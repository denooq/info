<?php
declare(strict_types=1);

use Customs\AI\CandidateVerifier;
use Customs\AI\SourceIndex;
use Customs\Domain\Normalizer;
use Customs\Domain\Registry;

function evidenceSources(): array
{
    return [['documentId'=>str_repeat('a',32),'name'=>'Specification.pdf','pages'=>[['page'=>1,'blocks'=>[['id'=>'p1-b0','text'=>'Масса нетто 24.48 tonnes; брутто 26792 kg. HS 29153100','bbox'=>null]]]],'sheets'=>[]]];
}

test('evidence rejects fabricated pages and unsupported values', function (): void {
    $sources=new SourceIndex(evidenceSources());
    $citation=sourceFixture('24.48 tonnes');
    $verified=$sources->verify($citation); same(1,$verified['page']);
    $citation['page']=2; errorCode('EVIDENCE_INVALID',fn()=>$sources->verify($citation));
    $candidate=['fieldId'=>'goods.netMass','entityKey'=>'g1','rawValue'=>'999','unit'=>'kg','source'=>sourceFixture('24.48 tonnes')];
    errorCode('VALUE_UNSUPPORTED',fn()=>(new CandidateVerifier(new Registry(),$sources))->verify($candidate));
});

test('units normalize deterministically and short HS codes are never padded', function (): void {
    $verifier=new CandidateVerifier(new Registry(),new SourceIndex(evidenceSources()));
    $mass=$verifier->verify(['fieldId'=>'goods.netMass','entityKey'=>'g1','rawValue'=>'24.48','unit'=>'tonnes','source'=>sourceFixture('24.48 tonnes')]);
    same('24480',$mass['value']); same('24.48',$mass['rawValue']);
    $hs=$verifier->verify(['fieldId'=>'goods.commodityCode','entityKey'=>'g1','rawValue'=>'29153100','unit'=>null,'source'=>sourceFixture('29153100')]);
    same('29153100',$hs['value']); same(false,$hs['valid']);
});

test('dates, decimal separators and spreadsheet provenance remain unambiguous', function (): void {
    $normalizer=new Normalizer();
    same('2026-10-09',$normalizer->date('09.10.2026'));
    same('2026-10-09',$normalizer->date('«09» октября 2026 г.'));
    same('2026-10-09',$normalizer->date('09.10.2026 г.'));
    errorCode('DATE_INVALID',fn()=>$normalizer->date('31 февраля 2026 года'));
    errorCode('DATE_AMBIGUOUS',fn()=>$normalizer->date('10/09/2026'));
    errorCode('DATE_INVALID',fn()=>$normalizer->date('31.02.2026'));
    same('37454.4',$normalizer->value(['type'=>'decimal','unit'=>'USD'],'37 454,40',null,''));
    $source=['documentId'=>str_repeat('b',32),'name'=>'data.xlsx','sheets'=>[['name'=>'Лист1','cells'=>['L4'=>['value'=>'1200']]]]];
    $verified=(new SourceIndex([$source]))->verify(['documentId'=>str_repeat('b',32),'page'=>null,'blockId'=>null,'sheet'=>'Лист1','cell'=>'L4','quote'=>'1200']);
    same('L4',$verified['cell']); same('Лист1',$verified['sheet']);
});

test('a numeric quantity without a gross/net label is never auto-filled as mass',function():void{
    $sources=[['documentId'=>str_repeat('a',32),'name'=>'invoice.pdf','pages'=>[['page'=>1,'blocks'=>[['id'=>'p1-b0','text'=>'Quantity 24480 KGS']]]]]];
    $candidate=(new CandidateVerifier(new Registry(),new SourceIndex($sources)))->verify(['fieldId'=>'goods.grossMass','entityKey'=>'g1','rawValue'=>'24480','unit'=>'kg','source'=>sourceFixture('24480 KGS')]);
    same(false,$candidate['valid']);truth(count($candidate['issues'])>0);
});

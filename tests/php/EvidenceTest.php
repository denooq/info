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

test('manifest source IDs are stable inside a session and isolated across sessions', function (): void {
    $raw = [['documentId'=>str_repeat('c',32),'contentHash'=>'same-bytes','name'=>'invoice.pdf','pages'=>[['page'=>1,'language'=>'en','blocks'=>[['id'=>'p1-t0','text'=>'Goods | Quantity','tableId'=>'t1']],'tables'=>[['id'=>'t1','blockId'=>'p1-t0','rows'=>[['row'=>1,'cells'=>[['column'=>1,'kind'=>'header','text'=>'Goods'],['column'=>2,'kind'=>'header','text'=>'Quantity']]],['row'=>2,'cells'=>[['column'=>1,'kind'=>'data','text'=>'ETHYL ACETATE'],['column'=>2,'kind'=>'data','text'=>'24480 KGS']]]]]]]],'sheets'=>[]]];
    $first = (new Customs\AI\SourceManifest())->build($raw, 'session-a');
    $again = (new Customs\AI\SourceManifest())->build($raw, 'session-a');
    $otherSession = (new Customs\AI\SourceManifest())->build($raw, 'session-b');
    same('source-manifest-v1', $first['version']);
    $cellId = $first['sources'][0]['pages'][0]['tables'][0]['rows'][1]['cells'][0]['sourceId'];
    same($cellId, $again['sources'][0]['pages'][0]['tables'][0]['rows'][1]['cells'][0]['sourceId']);
    truth($cellId !== $otherSession['sources'][0]['pages'][0]['tables'][0]['rows'][1]['cells'][0]['sourceId']);
    truth((new Customs\AI\SourceIndex($first['sources']))->verify(['sourceId'=>$cellId,'quote'=>'ETHYL ACETATE'])['page'] === 1);
});

test('manifest retains XLSX cells and citations by stable address', function (): void {
    $raw = [['documentId'=>str_repeat('d',32),'contentHash'=>'book-hash','name'=>'data.xlsx','sheets'=>[['name'=>'Invoice','cells'=>['B7'=>['value'=>'USD 37454.40','formula'=>null,'hasCachedValue'=>false,'source'=>['sheet'=>'Invoice','cell'=>'B7']]]]],'pages'=>[]]];
    $manifest = (new Customs\AI\SourceManifest())->build($raw, 'session-xlsx');
    $cell = $manifest['sources'][0]['sheets'][0]['cells']['B7'];
    $verified = (new Customs\AI\SourceIndex($manifest['sources']))->verify(['sourceId'=>$cell['sourceId'],'quote'=>'37454.40']);
    same('Invoice', $verified['sheet']); same('B7', $verified['cell']); same('USD 37454.40', $verified['context']);
});

test('mass uses a confirmed unit without requiring a gross/net keyword',function():void{
    $sources=[['documentId'=>str_repeat('a',32),'name'=>'invoice.pdf','pages'=>[['page'=>1,'blocks'=>[['id'=>'p1-b0','text'=>'Quantity 24480 KGS']]]]]];
    $candidate=(new CandidateVerifier(new Registry(),new SourceIndex($sources)))->verify(['fieldId'=>'goods.grossMass','entityKey'=>'g1','rawValue'=>'24480','unit'=>'kg','source'=>sourceFixture('24480 KGS')]);
    same(true,$candidate['valid']);same('24480',$candidate['value']);
    errorCode('UNIT_UNCONFIRMED',fn()=>(new CandidateVerifier(new Registry(),new SourceIndex($sources)))->verify(['fieldId'=>'goods.grossMass','entityKey'=>'g1','rawValue'=>'24480','unit'=>null,'source'=>sourceFixture('24480') ]));
});

test('country and date keep literal evidence while normalizing their field values',function():void{
    $id=str_repeat('a',32);$sources=[['documentId'=>$id,'name'=>'invoice.pdf','pages'=>[['page'=>1,'blocks'=>[['id'=>'p1-b0','text'=>'REPUBLIC OF KAZAKHSTAN; date 09.10.2026']]]]]];
    $verifier=new CandidateVerifier(new Registry(),new SourceIndex($sources));
    $country=$verifier->verify(['fieldId'=>'declaration.dispatchCountry','entityKey'=>null,'rawValue'=>'REPUBLIC OF KAZAKHSTAN','unit'=>null,'source'=>['documentId'=>$id,'page'=>1,'blockId'=>'p1-b0','sheet'=>null,'cell'=>null,'quote'=>'REPUBLIC OF KAZAKHSTAN']]);
    same('KZ',$country['value']);same(true,$country['valid']);
    $date=$verifier->verify(['fieldId'=>'document.date','entityKey'=>'doc1','rawValue'=>'09.10.2026','unit'=>null,'source'=>['documentId'=>$id,'page'=>1,'blockId'=>'p1-b0','sheet'=>null,'cell'=>null,'quote'=>'09.10.2026']]);
    same('2026-10-09',$date['value']);same(true,$date['valid']);
});

test('document number cannot reuse an explicitly measured mass or price',function():void{
    $id=str_repeat('a',32);$sources=[['documentId'=>$id,'name'=>'invoice.pdf','pages'=>[['page'=>1,'blocks'=>[['id'=>'p1-b0','text'=>'Gross 27860 kg; invoice total USD 37454.40']]]]]];
    $verifier=new CandidateVerifier(new Registry(),new SourceIndex($sources));
    foreach([['27860','Gross 27860 kg'],['37454.40','USD 37454.40']] as [$raw,$quote]) {
        $candidate=$verifier->verify(['fieldId'=>'document.number','entityKey'=>'doc1','rawValue'=>$raw,'unit'=>null,'source'=>['documentId'=>$id,'page'=>1,'blockId'=>'p1-b0','sheet'=>null,'cell'=>null,'quote'=>$quote]]);
        same(false,$candidate['valid']);truth(str_contains(implode(' ',$candidate['issues']),'массу или денежную сумму'));
    }
});

test('invoice amount requires its stated currency in source context',function():void{
    $id=str_repeat('a',32);$sources=[['documentId'=>$id,'name'=>'invoice.pdf','pages'=>[['page'=>1,'blocks'=>[['id'=>'p1-b0','text'=>'Total 37454.40']]]]]];
    $candidate=(new CandidateVerifier(new Registry(),new SourceIndex($sources)))->verify(['fieldId'=>'goods.invoiceValue','entityKey'=>'g1','rawValue'=>'37454.40','unit'=>'USD','source'=>['documentId'=>$id,'page'=>1,'blockId'=>'p1-b0','sheet'=>null,'cell'=>null,'quote'=>'37454.40']]);
    same(false,$candidate['valid']);truth(in_array('Валюта суммы не подтверждена тем же исходным фрагментом.',$candidate['issues'],true));
});

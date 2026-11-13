<?php
declare(strict_types=1);

use Customs\AI\CandidateMerger;
use Customs\Domain\Draft;
use Customs\Domain\Registry;

function mergerSource(string $id,string $weight): array
{
    return ['documentId'=>$id,'name'=>'source.pdf','pages'=>[['page'=>1,'blocks'=>[['id'=>'p1-b0','text'=>'ETHYL ACETATE gross '.$weight.' kg','bbox'=>null]]]]];
}
function mergerPayload(string $id,string $weight): array
{
    $entitySource=['documentId'=>$id,'page'=>1,'blockId'=>'p1-b0','sheet'=>null,'cell'=>null,'quote'=>'ETHYL ACETATE'];
    return ['entities'=>[['key'=>'ethyl','scope'=>'goods','role'=>null,'source'=>$entitySource]],'candidates'=>[['fieldId'=>'goods.grossMass','entityKey'=>'ethyl','rawValue'=>$weight,'unit'=>'kg','source'=>array_replace($entitySource,['quote'=>$weight.' kg'])]],'warnings'=>[]];
}

test('conflicting weight proposals remain buttons and manual input survives remapping', function (): void {
    $registry=new Registry(); $model=new Draft($registry); $merger=new CandidateMerger($registry);
    $state=sessionFixture()->create()['state']; $a=str_repeat('a',32); $b=str_repeat('b',32);
    $sources=[mergerSource($a,'26792'),mergerSource($b,'27860')];
    $state=$merger->merge($state,mergerPayload($a,'26792'),$sources);
    same('26792',$state['draft']['goods'][0]['grossMass']);
    $state=$merger->merge($state,mergerPayload($b,'27860'),$sources);
    same(2,count($state['candidates'])); same(null,$state['draft']['goods'][0]['grossMass']);
    $id=$state['draft']['goods'][0]['id'];
    $state=$model->edit($state,'goods.grossMass',$id,'28000');
    $state=$merger->merge($state,mergerPayload($b,'27860'),$sources);
    same('28000',$state['draft']['goods'][0]['grossMass']);
    same('manual',$state['draft']['fieldStates'][Draft::address('goods',$id,'grossMass')]['status']);
    $chosen=array_values(array_filter($state['candidates'],fn(array $c):bool=>$c['value']==='27860'))[0];
    $state=$model->edit($state,'goods.grossMass',$id,null,$chosen['id']);
    same('27860',$state['draft']['goods'][0]['grossMass']);
    same('selected',$state['draft']['fieldStates'][Draft::address('goods',$id,'grossMass')]['status']);
});

test('identical values combine provenance instead of inventing a conflict', function (): void {
    $merger=new CandidateMerger(new Registry()); $state=sessionFixture()->create()['state']; $a=str_repeat('a',32); $b=str_repeat('b',32);
    $sources=[mergerSource($a,'27860'),mergerSource($b,'27860')];
    $state=$merger->merge($state,mergerPayload($a,'27860'),$sources);
    $state=$merger->merge($state,mergerPayload($b,'27860'),$sources);
    same(1,count($state['candidates'])); same(2,count(array_values($state['candidates'])[0]['evidenceIds']));
    same('27860',$state['draft']['goods'][0]['grossMass']);
});

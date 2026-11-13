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

test('equivalent country names coalesce and a rejected delivery proposal does not prevent automatic fill', function (): void {
    $id = str_repeat('c', 32);
    $sources = [['documentId' => $id, 'name' => 'invoice.pdf', 'pages' => [['page' => 1, 'blocks' => [['id' => 'p1-b0', 'text' => 'Destination KZ; REPUBLIC OF KAZAKHSTAN. Delivery CPT. HS 29153100']]]]]];
    $citation = ['documentId' => $id, 'page' => 1, 'blockId' => 'p1-b0', 'sheet' => null, 'cell' => null];
    $candidates = [];
    foreach (['KZ', 'REPUBLIC OF KAZAKHSTAN'] as $value) $candidates[] = ['fieldId' => 'declaration.destinationCountry', 'entityKey' => null, 'rawValue' => $value, 'unit' => null, 'source' => [...$citation, 'quote' => $value]];
    foreach (['CPT', '29153100'] as $value) $candidates[] = ['fieldId' => 'declaration.deliveryCode', 'entityKey' => null, 'rawValue' => $value, 'unit' => null, 'source' => [...$citation, 'quote' => $value]];
    $state = (new CandidateMerger(new Registry()))->merge(sessionFixture()->create()['state'], ['entities' => [], 'candidates' => $candidates, 'warnings' => []], $sources);
    same('KZ', $state['draft']['declaration']['destinationCountry']);
    same('CPT', $state['draft']['declaration']['deliveryCode']);
    $country = array_values(array_filter($state['candidates'], fn($c) => $c['fieldId'] === 'declaration.destinationCountry'));
    same(1, count($country)); same(2, count($country[0]['evidenceIds']));
    same(false, $state['draft']['fieldStates']['declaration/_/deliveryCode']['conflict']);
    same(false, $state['draft']['fieldStates']['declaration/_/deliveryCode']['needsReview']);
    $state = (new Draft(new Registry()))->edit($state, 'declaration.deliveryCode', null, 'FCA');
    $state = (new CandidateMerger(new Registry()))->merge($state, ['entities' => [], 'candidates' => [$candidates[3]], 'warnings' => []], $sources);
    same('FCA', $state['draft']['declaration']['deliveryCode']);
    same(false, $state['draft']['fieldStates']['declaration/_/deliveryCode']['needsReview']);
});

test('a permit product list and entities without supported candidates do not create shipment goods', function (): void {
    $id = str_repeat('d', 32);
    $source = ['documentId' => $id, 'name' => 'invoice.pdf', 'pages' => [['page' => 1, 'blocks' => [['id' => 'p1-b0', 'text' => 'Санитарно-эпидемиологический контроль. Перечень продукции: ' . implode('; ', array_map(fn($i) => 'Продукт ' . $i, range(1, 15)))]]]]];
    $entities = []; $candidates = [];
    for ($i = 1; $i <= 15; $i++) {
        $citation = ['documentId' => $id, 'page' => 1, 'blockId' => 'p1-b0', 'sheet' => null, 'cell' => null, 'quote' => 'Продукт ' . $i];
        $entities[] = ['scope' => 'goods', 'key' => 'product-' . $i, 'role' => null, 'source' => $citation];
        $candidates[] = ['fieldId' => 'goods.description', 'entityKey' => 'product-' . $i, 'rawValue' => 'Продукт ' . $i, 'unit' => null, 'source' => $citation];
    }
    $merger = new CandidateMerger(new Registry());
    $state = $merger->merge(sessionFixture()->create()['state'], ['entities' => $entities, 'candidates' => $candidates, 'warnings' => []], [$source]);
    same(1, count($state['draft']['goods'])); same(null, $state['draft']['goods'][0]['description']); same([], $state['candidates']);
    truth(in_array('GOODS_SHIPMENT_UNCONFIRMED', array_column($state['mappingIssues'], 'code'), true));
    $entities[0]['key'] = 'goods-1'; $candidates[0]['entityKey'] = 'goods-1';
    $state = $merger->merge($state, ['entities' => [$entities[0]], 'candidates' => [$candidates[0]], 'warnings' => []], [$source]);
    same(null, $state['draft']['goods'][0]['description']); same([], $state['draft']['sourceLines']);
    $source['pages'][0]['blocks'][0]['text'] = 'Invoice. Продукт 1';
    $state = $merger->merge(sessionFixture()->create()['state'], ['entities' => $entities, 'candidates' => [], 'warnings' => []], [$source]);
    same(1, count($state['draft']['goods'])); same([], $state['draft']['sourceLines']);
    $candidates[0]['rawValue'] = 'Absent value';
    $state = $merger->merge($state, ['entities' => [$entities[0]], 'candidates' => [$candidates[0]], 'warnings' => []], [$source]);
    same(1, count($state['draft']['goods'])); same([], $state['draft']['sourceLines']);
});

test('supporting documents can reference an already established shipment item without creating a duplicate', function (): void {
    $primary = str_repeat('a', 32); $permit = str_repeat('b', 32);
    $merger = new CandidateMerger(new Registry());
    $state = $merger->merge(sessionFixture()->create()['state'], mergerPayload($primary, '27860'), [mergerSource($primary, '27860')]);
    $goodsId = $state['draft']['goods'][0]['id'];
    $source = ['documentId' => $permit, 'name' => 'permit.pdf', 'pages' => [['page' => 1, 'blocks' => [['id' => 'p1-b0', 'text' => 'Сертификат соответствия. ETHYL ACETATE 2915310000']]]]];
    $citation = ['documentId' => $permit, 'page' => 1, 'blockId' => 'p1-b0', 'sheet' => null, 'cell' => null, 'quote' => 'ETHYL ACETATE'];
    $response = ['entities' => [['scope' => 'goods', 'key' => $goodsId, 'role' => null, 'source' => $citation]], 'candidates' => [['fieldId' => 'goods.commodityCode', 'entityKey' => $goodsId, 'rawValue' => '2915310000', 'unit' => null, 'source' => [...$citation, 'quote' => '2915310000']]], 'warnings' => []];
    $state = $merger->merge($state, $response, [$source]);
    same(1, count($state['draft']['goods'])); same('2915310000', $state['draft']['goods'][0]['commodityCode']);
    same(1, count($state['draft']['goods'][0]['sourceLineIds']));
});

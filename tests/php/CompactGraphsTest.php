<?php
declare(strict_types=1);

test('compact projection is additive and deduplicates party names without inventing addresses', function(): void {
    $draft=(new Customs\Domain\Draft(new Customs\Domain\Registry()))->empty();
    $draft['parties'][2]['name']='ANHUI';$draft['parties'][2]['briefName']='ANHUI';
    $state=['draft'=>$draft,'dataRevision'=>8,'calculation'=>null,'candidates'=>[],'evidence'=>[]];
    $before=$state;$compact=new Customs\Domain\CompactGraphs();
    same('ANHUI',$compact->item($state,'2',null)['value']);same($before,$state);
    $state['draft']['graphTexts']['graph/2/_']=['value'=>'Ручной отправитель и адрес','status'=>'manual','evidenceIds'=>[]];
    same('Ручной отправитель и адрес',$compact->item($state,'2',null)['value']);
    $state['draft']['parties'][2]['name']='После remap';same('Ручной отправитель и адрес',$compact->item($state,'2',null)['value']);
    $state['draft']['goods'][0]['grossMass']='12.5';
    same('12.5',$compact->item($state,'35','goods-1')['inputs'][0]['value']);
    same(54,count($compact->project($state)));same('pending_calculation',$compact->item($state,'45','goods-1')['status']);
});

test('one new document conflict retains a selectable manual graph value without a fake citation', function(): void {
    $state=['draft'=>(new Customs\Domain\Draft(new Customs\Domain\Registry()))->empty(),'dataRevision'=>1,'calculation'=>null,'candidates'=>[],'evidence'=>[]];
    $state=(new Customs\Domain\GraphEdits())->edit($state,'2',null,'Ручной отправитель');
    $state['evidence']['new']=['documentId'=>'new-doc','documentName'=>'Новый инвойс','page'=>1,'quote'=>'OTHER'];
    $state['candidates']['new']=['id'=>'new','address'=>'party/party-consignor/name','fieldId'=>'party.name','entityId'=>'party-consignor','valid'=>true,'value'=>'OTHER','evidenceIds'=>['new']];
    $item=(new Customs\Domain\CompactGraphs())->item($state,'2',null);same('conflict',$item['status']);same('Ручной отправитель',$item['value']);same(2,count($item['choices']));
    $manual=array_values(array_filter($item['choices'],fn($c)=>($c['kind']??'')==='manual'))[0];same([],$manual['evidenceIds']);
    $state=(new Customs\Domain\GraphEdits())->edit($state,'2',null,'',$manual['id']);same('manual',(new Customs\Domain\CompactGraphs())->item($state,'2',null)['status']);
});

test('compact source proposals keep conflicting document facts together and merge equal sources', function(): void {
    $state=['draft'=>(new Customs\Domain\Draft(new Customs\Domain\Registry()))->empty(),'dataRevision'=>1,'calculation'=>null,'candidates'=>[],'evidence'=>[]];
    foreach (['invoice'=>['ALPHA','Street A'],'contract'=>['BETA','Street B']] as $document=>$values) foreach (['name','street'] as $index=>$key) {
        $e=$document.'-'.$key;$state['evidence'][$e]=['documentId'=>$document,'documentName'=>$document,'page'=>1,'quote'=>$values[$index]];
        $state['candidates'][$e]=['id'=>$e,'address'=>'party/party-consignor/'.$key,'fieldId'=>'party.'.$key,'entityId'=>'party-consignor','valid'=>true,'value'=>$values[$index],'evidenceIds'=>[$e]];
    }
    $compact=new Customs\Domain\CompactGraphs();$item=$compact->item($state,'2',null);
    same('conflict',$item['status']);same(null,$item['value']);same(2,count($item['choices']));
    same(['ALPHA; Street A','BETA; Street B'],array_column($item['choices'],'value'));
    $state['candidates']['contract-name']['value']='ALPHA';$state['candidates']['contract-street']['value']='Street A';
    $item=$compact->item($state,'2',null);same(1,count($item['choices']));same('ALPHA; Street A',$item['value']);same(4,count($item['evidenceIds']));
    $state['draft']['graphTexts']['graph/2/_']=['value'=>'Ручной адрес','status'=>'manual','evidenceIds'=>['invoice-name'],'reviewedCandidateIds'=>array_column($item['choices'],'id')];
    unset($state['evidence']['invoice-name']);same('Ручной адрес',$compact->item($state,'2',null)['value']);same([],$compact->item($state,'2',null)['evidenceIds']);
});

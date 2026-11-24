<?php
declare(strict_types=1);
test('graph projection preserves unknown zero false and derived stages without flattening goods',function():void {
    $draft=(new Customs\Domain\Draft(new Customs\Domain\Registry()))->empty(strtotime('2026-11-23T12:00:00Z'));
    $draft['goods'][0]['grossMass']='0';$draft['declaration']['container']=false;
    $state=['draft'=>$draft,'dataRevision'=>8,'calculation'=>null];
    $projection=(new Customs\Domain\GraphProjection())->project($state);$graphs=array_column($projection['graphs'],null,'id');
    same(57,count($graphs));same('0',$graphs['35']['entries'][0]['value']);same(false,$graphs['19']['entries'][0]['value']);
    same('pending_calculation',$graphs['45']['entries'][0]['status']);same('not_applicable',$graphs['40']['status']);
    $preference=array_values(array_filter($graphs['36']['entries'],fn($e)=>$e['fieldId']==='goods.hasPreference'))[0];same(false,$preference['value']);same('profile',$preference['status']);
    same('KZ',$graphs['17a']['entries'][0]['value']);same(['code'=>'KZ','name'=>'КАЗАХСТАН'],$graphs['17']['displayValue']);same(['code'=>'KZ','name'=>'КАЗАХСТАН'],$graphs['17a']['displayValue']);
    $draft['declaration']['dispatchCountry']='CN';$state['draft']=$draft;$graphs=array_column((new Customs\Domain\GraphProjection())->project($state)['graphs'],null,'id');$china=(new Customs\Domain\Classifiers())->country('CN')['name'];
    same(['code'=>'CN','name'=>$china],$graphs['15']['displayValue']);same(['code'=>'CN','name'=>$china],$graphs['15a']['displayValue']);
    $draft['declaration']['dispatchCountry']='US';$state['draft']=$draft;$graphs=array_column((new Customs\Domain\GraphProjection())->project($state)['graphs'],null,'id');$usa=(new Customs\Domain\Classifiers())->country('US')['name'];
    same(['code'=>'US','name'=>$usa],$graphs['15']['displayValue']);same(['code'=>'US','name'=>$usa],$graphs['15a']['displayValue']);
    $draft['goods'][]=['id'=>'goods-2',...(new Customs\Domain\Draft(new Customs\Domain\Registry()))->row('goods'),'grossMass'=>'12'];$state['draft']=$draft;
    $graphs=array_column((new Customs\Domain\GraphProjection())->project($state)['graphs'],null,'id');same(2,count($graphs['35']['entries']));same('12',$graphs['35']['entries'][1]['value']);
});
test('a cited shipment place count creates one shared group and manual package edits survive refresh',function():void {
    $registry=new Customs\Domain\Registry();$draft=(new Customs\Domain\Draft($registry))->empty();$draft['declaration']['sourcePackageCount']=34;
    $draft=Customs\Domain\GraphDefaults::apply($draft);same(1,count($draft['packages']));same(34,$draft['declaration']['packageCount']);same(['package-recognized-count'],$draft['goods'][0]['packageIds']);same(34,$draft['goods'][0]['packageQuantity']);
    $state=['draft'=>$draft,'candidates'=>[]];$state=(new Customs\Domain\Entities($registry))->change($state,['scope'=>'package','action'=>'update','id'=>'package-recognized-count','label'=>'Подтверждённые места','quantity'=>35]);
    $state['draft']=Customs\Domain\GraphDefaults::apply($state['draft']);same(35,$state['draft']['packages'][0]['quantity']);same('Подтверждённые места',$state['draft']['packages'][0]['label']);
});
test('graph 36 defaults only an untouched ordinary product to no preference and preserves confirmed preferences',function():void {
    $registry=new Customs\Domain\Registry();$draft=(new Customs\Domain\Draft($registry))->empty();$goodsId=$draft['goods'][0]['id'];$draft=Customs\Domain\GraphDefaults::apply($draft);
    same(false,$draft['goods'][0]['hasPreference']);foreach(['preferenceFee','preferenceDuty','preferenceExcise','preferenceVat'] as $key)same('ОО',$draft['goods'][0][$key]);same('profile',$draft['fieldStates']['goods/'.$goodsId.'/hasPreference']['status']);
    $draft['goods'][0]['hasPreference']=true;$draft['goods'][0]['preferenceDuty']='ОО';$draft['fieldStates']['goods/'.$goodsId.'/hasPreference']=['status'=>'manual','candidateId'=>null,'evidenceIds'=>[]];
    $next=Customs\Domain\GraphDefaults::apply($draft);same(true,$next['goods'][0]['hasPreference']);same('ОО',$next['goods'][0]['preferenceDuty']);
    $draft=(new Customs\Domain\Draft($registry))->empty();$draft['goods'][0]['commodityCode']='3004900000';$next=Customs\Domain\GraphDefaults::apply($draft);same(null,$next['goods'][0]['hasPreference']);
});

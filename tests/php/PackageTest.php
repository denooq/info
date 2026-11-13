<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/fixtures.php';

test('package creation resolves empty mandatory selectors atomically and shared places count once',function():void {
    $s=completeDraftState(2); $s['draft']['packages']=[]; $s['draft']['declaration']['shipmentPackageIds']=[];
    foreach($s['draft']['goods'] as &$goods)$goods['packageIds']=[]; unset($goods);
    $validator=new Customs\Domain\Validator(new Customs\Domain\Registry());
    truth(!$validator->validate($s)['valid']);
    $model=new Customs\Domain\Entities(new Customs\Domain\Registry());
    $s=$model->change($s,['scope'=>'package','action'=>'add','label'=>'Паллеты CMR','quantity'=>34,'goodsIds'=>['goods-1','goods-2']]);
    same(1,count($s['draft']['packages'])); $id=$s['draft']['packages'][0]['id'];
    same([$id],$s['draft']['declaration']['shipmentPackageIds']);
    foreach($s['draft']['goods'] as $goods) {
        same([$id],$goods['packageIds']);
        same('manual',$s['draft']['fieldStates']['goods/'.$goods['id'].'/packageIds']['status']);
        same(34,$goods['packageQuantity']);
    }
    truth($validator->validate($s)['valid'],json_encode($validator->validate($s)['errors'],JSON_UNESCAPED_UNICODE));
    same(34,(new Customs\Domain\Calculator())->calculate($s)['packageCount']);
    $s=$model->change($s,['scope'=>'package','action'=>'remove','id'=>$id]);
    same([],$s['draft']['declaration']['shipmentPackageIds']);
    foreach($s['draft']['goods'] as $goods)same([],$goods['packageIds']);
    truth(!$validator->validate($s)['valid']);
});

test('package creation rejects invalid data and foreign goods without leaving an orphan',function():void {
    $s=completeDraftState(); $model=new Customs\Domain\Entities(new Customs\Domain\Registry()); $before=$s;
    $request=['scope'=>'package','action'=>'add','label'=>'Новая группа','quantity'=>2,'goodsIds'=>['goods-1']];
    foreach([0,-1,1.5,'2',1000001,null] as $quantity)errorCode('PACKAGE_VALUE',fn()=>$model->change($s,[...$request,'quantity'=>$quantity]));
    errorCode('PACKAGE_VALUE',fn()=>$model->change($s,[...$request,'label'=>'   ']));
    foreach([['goods-from-other-session'],[null],'goods-1'] as $ids)errorCode('PACKAGE_GOODS',fn()=>$model->change($s,[...$request,'goodsIds'=>$ids]));
    same($before,$s);
    $s=$model->change($s,$request); same(2,count($s['draft']['packages'])); same(36,(new Customs\Domain\Calculator())->calculate($s)['packageCount']);
});

test('package creation links only selected goods and item groups must belong to the shipment',function():void {
    $s=completeDraftState(2); $model=new Customs\Domain\Entities(new Customs\Domain\Registry());
    $s=$model->change($s,['scope'=>'package','action'=>'add','label'=>'Дополнительные места','quantity'=>2,'goodsIds'=>['goods-1']]);
    same(2,count($s['draft']['goods'][0]['packageIds'])); same(['package-1'],$s['draft']['goods'][1]['packageIds']);
    $s['draft']['declaration']['shipmentPackageIds']=['package-1'];
    $errors=(new Customs\Domain\Validator(new Customs\Domain\Registry()))->validate($s)['errors'];
    truth(in_array('PACKAGE_SHIPMENT',array_column($errors,'code'),true));
});

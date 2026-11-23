<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/fixtures.php';
test('review gate requires all applicable input graphs while allowing manual records and future calculations',function():void {
    $s=completeDraftState();$review=new Customs\Domain\ReviewValidator();$s['draft']['declaration']['methodOneConfirmed']=null;$s['draft']['declaration']['feeBase']=null;$s['draft']['goods'][0]['dutyRate']=null;
    $r=$review->validate($s);truth($r['valid'],json_encode($r['errors'],JSON_UNESCAPED_UNICODE));
    truth(!(new Customs\Domain\Validator(new Customs\Domain\Registry()))->validate($s)['valid']);
    same([], $s['documents']);$s['draft']['parties'][3]['name']=null;
    $r=$review->validate($s);truth(!$r['valid']);truth((bool)array_filter($r['errors'],fn($e)=>$e['graphId']==='8'&&str_ends_with($e['address'],'/name')));
    errorCode('DECLARATION_INVALID',fn()=>$review->requireValid($s));
});
test('two and five goods preserve exact fee allocation, shared places and independent payment facts',function():void {
    foreach([2=>'25960.00',5=>'25970.00'] as $count=>$fee) {
        $s=completeDraftState($count);$s['draft']['declaration']['feeAdditional']='10';$s['draft']['keden']=[];
        $calc=(new Customs\Domain\Calculator())->calculate($s);same($count,$calc['goodsCount']);same(34,$calc['packageCount']);same($fee,$calc['totals']['fee']);same([],$calc['graphB']);
        $sum=Brick\Math\BigDecimal::zero();foreach($calc['items'] as $item)$sum=$sum->plus($item['fee']);truth($sum->isEqualTo($fee));
        $s['draft']['declaration']['paymentFactsAvailable']=true;$s['draft']['payments']=[['id'=>'paid','code'=>'2010','amount'=>'123.60','source'=>'Подтверждённая уплата','documentNumber'=>'P-1','date'=>'2026-10-28']];
        $paid=(new Customs\Domain\Calculator())->calculate($s);same('124',$paid['graphB'][2010]);truth($paid['totals']['duty']!==$paid['actualPaid'][2010]);
    }
});
test('unknown automatic text is not a value and alpha country model rejects numeric KZ',function():void {
    $s=completeDraftState();$review=new Customs\Domain\ReviewValidator();$s['draft']['declaration']['signatoryPlace']=Customs\Domain\GraphProjection::UNAVAILABLE;
    truth((bool)array_filter($review->validate($s)['errors'],fn($e)=>$e['address']==='declaration/_/signatoryPlace'));
    $s=completeDraftState();$s['draft']['goods'][0]['originCountry']='398';truth(!$review->validate($s)['valid']);
    $s=completeDraftState();$s['draft']['declaration']['container']=false;$s['draft']['goods'][0]['statisticalAddition']='0';truth($review->validate($s)['valid']);
});

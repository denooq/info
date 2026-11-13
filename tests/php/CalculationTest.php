<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/fixtures.php';

test('exact decimal arithmetic matches both manually confirmed reference calculations',function():void{
    $c=new Customs\Domain\Calculator();$s=completeDraftState();$result=$c->calculate($s);
    same('17065348.27',$result['totals']['customsValue']);same('853267.41',$result['totals']['duty']);same('2871130.51',$result['totals']['vat']);same('3750347.92',$result['totals']['accruedTotal']);same([],$result['graphB']);
    $s['draft']['declaration']['invoiceCurrency']='EUR';$s['draft']['goods'][0]['invoiceValue']='2844';
    $s['draft']['rates'][]=['id'=>'rate-eur','currency'=>'EUR','value'=>'508.33','nominal'=>'1','date'=>'2026-10-28','source'=>'Только для теста','confirmed'=>true];
    foreach(['freight'=>'2058736.50','insurance'=>'5222'] as $kind=>$amount){
        $s['draft']['declaration'][$kind.'Applicable']=true;
        $s['draft']['costs'][]=['id'=>'cost-'.$kind,'kind'=>$kind,'direction'=>'addition','amount'=>$amount,'currency'=>'KZT','includedInPrice'=>false,'basis'=>'Подтверждённый участок до места ввоза','evidenceIds'=>[],'allocationBasis'=>'value','allocationConfirmed'=>true,'goodsIds'=>['goods-1']];
    }
    $result=$c->calculate($s);same('3509649.02',$result['totals']['customsValue']);same('175482.45',$result['totals']['duty']);same('593773.04',$result['totals']['vat']);same('795205.49',$result['totals']['accruedTotal']);
    $s['draft']['costs'][0]['includedInPrice']=true;$r=$c->calculate($s);same('0.00',$r['costs'][0]['effective']);
});
test('allocation preserves exact totals, rejects zero bases and respects currency nominal',function():void{
    $c=new Customs\Domain\Calculator();
    foreach([2,5] as $count){$parts=$c->allocate('0.07',array_fill(0,$count,'1'));$sum=Brick\Math\BigDecimal::zero();foreach($parts as $p)$sum=$sum->plus($p);same('0.07',(string)$sum);}
    errorCode('ALLOCATION_BASE',fn()=>$c->allocate('10',['a'=>'0','b'=>'0']));
    same('123.46',$c->convert('100','USD',[['currency'=>'USD','value'=>'123.455','nominal'=>'100']]));
    same('0.01',Customs\Domain\Calculator::money('0.005'));
    $s=completeDraftState(5);$r=$c->calculate($s);same(3,$r['sheetCount']);same(34,$r['packageCount']);
    same('25950.00',$r['totals']['fee']);
});
test('derived proposals never override formula operands and inconsistent proposals block confirmation',function():void{
    $c=new Customs\Domain\Calculator();$s=completeDraftState();$r=$c->calculate($s);
    $edited=$c->propose($r,'goods-1','duty','999');same(false,$edited['consistent']);same('853267.41',$edited['items'][0]['duty']);
    $accepted=$c->propose($edited,'goods-1','duty',null);same(true,$accepted['consistent']);
    $s['draft']['rates'][0]['value']='460';$new=$c->calculate($s);truth($new['totals']['customsValue']!==$r['totals']['customsValue']);truth($new['totals']['vat']!==$r['totals']['vat']);
});

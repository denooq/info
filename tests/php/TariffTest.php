<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/fixtures.php';

use Customs\Domain\{Calculator,Draft,Registry,TariffCatalogue,TariffDefaults,TariffEngine,Validator};

function tariffState(string $code): array
{
    $state=completeDraftState(); $goods=&$state['draft']['goods'][0]; $goods['commodityCode']=$code;
    foreach([...TariffEngine::OPERANDS,'tariffFromCatalogue','tariffConfirmed','rateBasis','ratesDate'] as $key) {
        $goods[$key]=null; unset($state['draft']['fieldStates']['goods/goods-1/'.$key]);
    }
    unset($goods);
    return (new TariffDefaults())->apply($state);
}

test('all terminal tariff expressions round trip with exact rate and unit metadata in bounded runtime memory',function():void {
    $catalogue=new TariffCatalogue(); $index=json_decode(file_get_contents(dirname(__DIR__,2).'/resources/tnved/codes.v1.json'),true);
    $formulas=[]; $doubleUnits=0; $notes=0;
    foreach($index['codes'] as $code) {
        $entry=$catalogue->get($code); truth($entry!==null,$code);
        same($index['source_sha256'],$entry['version']);
        $params=TariffEngine::parameters($entry['duty']['base_rule']);
        truth($entry['duty']['base_rule']==TariffEngine::rule($params),$code);
        $formulas[$params['dutyFormula']]=true;
        same(null,$entry['duty']['effective_rule']);
        if(count($entry['units']['additional_unit_codes'])===2)$doubleUnits++;
        if($entry['notes']!==[])$notes++;
    }
    same(5,count($formulas)); same(8,$doubleUnits); same(1246,$notes);
    same('0',TariffEngine::parameters($catalogue->get('0101210000')['duty']['base_rule'])['dutyRate']);
    truth(memory_get_peak_usage(true)<192*1024*1024,'Runtime exceeds 192M');
});

test('tariff autofill requires context confirmation, owns rates and resets confirmation after changes',function():void {
    $service=new TariffDefaults(); $model=new Draft(new Registry()); $validator=new Validator(new Registry());
    $s=tariffState('2915310000'); $g=$s['draft']['goods'][0];
    same('5',$g['dutyRate']); same('advalorem',$g['dutyFormula']); same(false,$g['additionalUnitRequired']); same(false,$g['tariffConfirmed']); same('16',$g['vatRate']);
    truth(in_array('TARIFF_CONFIRM',array_column($validator->validate($s)['errors'],'code'),true));
    $s=$service->apply($model->edit($s,'goods.tariffConfirmed','goods-1',true));
    truth($validator->validate($s)['valid'],json_encode($validator->validate($s)['errors'],JSON_UNESCAPED_UNICODE));
    same($s,$service->apply($s));
    $changed=$service->apply($model->edit($s,'goods.originCountry','goods-1','DE')); same(false,$changed['draft']['goods'][0]['tariffConfirmed']);
    $changed=$service->apply($model->edit($s,'declaration.calculationDate',null,'2026-11-13')); same(false,$changed['draft']['goods'][0]['tariffConfirmed']); same('2026-11-13',$changed['draft']['goods'][0]['ratesDate']);
    errorCode('FIELD_READ_ONLY',fn()=>$model->edit($s,'goods.dutyRate','goods-1','7'));
    errorCode('FIELD_READ_ONLY',fn()=>$model->edit($s,'goods.rateBasis','goods-1','Произвольное основание'));
    $manual=completeDraftState(); $manual['draft']['goods'][0]['dutyRate']='7'; $manual=$service->apply($manual);
    same('5',$manual['draft']['goods'][0]['dutyRate']); same(true,$manual['draft']['goods'][0]['tariffFromCatalogue']);
    truth($validator->validate($manual)['valid'],json_encode($validator->validate($manual)['errors'],JSON_UNESCAPED_UNICODE));
    $zero=tariffState('0101210000'); same('0',$zero['draft']['goods'][0]['dutyRate']); same('796',$zero['draft']['goods'][0]['additionalUnit']); same(null,$zero['draft']['goods'][0]['additionalQuantity']);
    $none=$service->apply($model->edit($s,'goods.commodityCode','goods-1','9999999999')); same(null,$none['draft']['goods'][0]['dutyRate']); truth(!isset($none['draft']['goods'][0]['_tariff']));
});

test('specific duty links net mass while reporting units and two simultaneous quantities stay distinct',function():void {
    $service=new TariffDefaults(); $model=new Draft(new Registry());
    $s=tariffState('0808101000'); same('24480',$s['draft']['goods'][0]['dutyQuantity']); same('166',$s['draft']['goods'][0]['dutyUnitCode']);
    $s=$service->apply($model->edit($s,'goods.netMass','goods-1','1000')); same('1000',$s['draft']['goods'][0]['dutyQuantity']);
    errorCode('FIELD_READ_ONLY',fn()=>$model->edit($s,'goods.dutyQuantity','goods-1','2000'));
    $s=tariffState('3702319100'); $g=$s['draft']['goods'][0]; same(true,$g['additionalUnitRequired']); same(true,$g['secondaryAdditionalUnitRequired']); same('796',$g['additionalUnit']); same('006',$g['secondaryAdditionalUnit']); same(null,$g['additionalQuantity']); same(null,$g['secondaryAdditionalQuantity']);
    errorCode('FIELD_READ_ONLY',fn()=>$model->edit($s,'goods.additionalUnit','goods-1','166'));
    same('2500',TariffEngine::linkedQuantity(['dutyQuantityBasis'=>'goods_quantity','dutyUnitCode'=>'796','additionalUnit'=>'798','additionalQuantity'=>'2.5']));
    same('2000',TariffEngine::linkedQuantity(['dutyQuantityBasis'=>'goods_quantity','dutyUnitCode'=>'112','additionalUnit'=>'130','additionalQuantity'=>'2']));
    same('0.000000000000001',TariffEngine::linkedQuantity(['dutyQuantityBasis'=>'goods_quantity','dutyUnitCode'=>'130','additionalUnit'=>'112','additionalQuantity'=>'0.000000000001']));
    same(null,TariffEngine::linkedQuantity(['dutyQuantityBasis'=>'goods_quantity','dutyUnitCode'=>'796','additionalUnit'=>'715','additionalQuantity'=>'2']));
    same(null,TariffEngine::linkedQuantity(['dutyQuantityBasis'=>'goods_quantity','dutyUnitCode'=>'112','additionalUnit'=>'831','additionalQuantity'=>'2']));
    $qty=['dutyFormula'=>'specific','dutyQuantityBasis'=>'goods_quantity','dutyUnitCode'=>'796','additionalUnit'=>'796','additionalQuantity'=>'2','_tariff'=>['units'=>['qualifier'=>'колоды']]];
    same(null,TariffDefaults::linkedQuantity($qty));
});

test('five tariff formulas use exact comparisons, currency nominal and quantity denominator with final rounding',function():void {
    $engine=new TariffEngine(); $catalogue=new TariffCatalogue(); $rates=[['currency'=>'EUR','value'=>'500','nominal'=>'1']];
    foreach([['2915310000','1000','1','50.00'],['0808101000','1000','100','3000.00'],['0201100008','1000','100','50000.00'],['0201100008','1000000','100','500000.00'],['3401110001','1000','100','1045.00'],['8703221091','1000000','2000','150000.00'],['8703221091','10000000','2000','1250000.00']] as [$code,$cv,$qty,$expected]) {
        $rule=$catalogue->get($code)['duty']['base_rule'];
        same($expected,$engine->calculate($rule,$cv,['netMass'=>$qty,'dutyQuantity'=>$qty],$rates)['amount']);
    }
    $specific=['kind'=>'specific','amount'=>'1','currency'=>'EUR','per_quantity'=>'1000','unit_code'=>'796','unit_symbol'=>'шт','quantity_basis'=>'goods_quantity'];
    same('0.33',$engine->calculate($specific,'1',['dutyQuantity'=>'1'],[['currency'=>'EUR','value'=>'1000','nominal'=>'3']])['amount']);
    $sum=['operator'=>'sum','operands'=>[['kind'=>'ad_valorem','percent'=>'0.4','basis'=>'customs_value'],[...$specific,'per_quantity'=>'1','amount'=>'0.004','currency'=>'KZT']]];
    same('0.01',$engine->calculate($sum,'1',['dutyQuantity'=>'1'],[])['amount']); // do not round each 0.004 component to zero
    errorCode('RATE_REQUIRED',fn()=>$engine->calculate($specific,'1000',['dutyQuantity'=>'1'],[]));
    errorCode('TARIFF_OPERAND_REQUIRED',fn()=>$engine->calculate($specific,'1000',[], $rates));
});

test('specific tariff participates in VAT, requires its exchange rate, and cannot be misrepresented by sample XML',function():void {
    $s=tariffState('0808101000'); $model=new Draft(new Registry()); $service=new TariffDefaults();
    $s=$service->apply($model->edit($s,'goods.tariffConfirmed','goods-1',true));
    $v=new Validator(new Registry()); truth(in_array('RATE_REQUIRED',array_column($v->validate($s)['errors'],'code'),true));
    $s['draft']['rates'][]=['id'=>'eur','currency'=>'EUR','value'=>'500','nominal'=>'1','date'=>'2026-10-28','source'=>'Test only','confirmed'=>true];
    truth($v->validate($s)['valid'],json_encode($v->validate($s)['errors'],JSON_UNESCAPED_UNICODE));
    $result=(new Calculator())->calculate($s); same('734400.00',$result['items'][0]['duty']); same('17825698.27',$result['items'][0]['vatBase']);
    same(null,$result['items'][0]['payments'][1]['rate']); truth(count($result['items'][0]['dutyTrace'])>0);
    errorCode('XML_BRANCH_UNSUPPORTED',fn()=>(new Customs\Export\XmlProfiles())->requireSupported(['data'=>['draft'=>$s['draft'],'calculation'=>$result]]));
});

test('goods merge sums reporting and duty quantities and rejects different specific rate parameters',function():void {
    $s=tariffState('0101210000'); $s['draft']['goods'][0]['additionalQuantity']='2';
    $s['draft']['goods'][]=[...$s['draft']['goods'][0],'id'=>'goods-2','additionalQuantity'=>'3','sourceLineIds'=>[]];
    $model=new Customs\Domain\Entities(new Registry()); $request=['scope'=>'goods','action'=>'merge','ids'=>['goods-1','goods-2'],'mode'=>'sum','confirmed'=>true];
    $merged=(new TariffDefaults())->apply($model->change($s,$request)); same('5',$merged['draft']['goods'][0]['additionalQuantity']); same(false,$merged['draft']['goods'][0]['tariffConfirmed']);
    $same=$model->change($s,[...$request,'mode'=>'same-item']); same(null,$same['draft']['goods'][0]['additionalQuantity']);
    $s['draft']['goods'][0]['dutyFormula']='specific'; $s['draft']['goods'][1]['dutyFormula']='specific';
    $s['draft']['goods'][0]['dutySpecificRate']='1'; $s['draft']['goods'][1]['dutySpecificRate']='2';
    errorCode('GROUP_INCOMPATIBLE',fn()=>$model->change($s,$request));
});

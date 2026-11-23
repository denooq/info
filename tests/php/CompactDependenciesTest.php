<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/fixtures.php';

test('compact dependencies use the preparation date and retain exact nominal and shared totals', function(): void {
    $timestamp=strtotime('2026-11-23T19:10:00Z');$draft=(new Customs\Domain\Draft(new Customs\Domain\Registry()))->empty($timestamp);
    same('2026-11-24',$draft['declaration']['signatureDate']);same($draft,Customs\Domain\DeclarationDate::defaults($draft,$timestamp+86400));
    $s=completeDraftState(5);$s['draft']['rates'][0]['nominal']='100';$s['draft']['rates'][0]['value']='45563';
    $r=(new Customs\Domain\Calculator())->calculate($s);same('25950.00',$r['totals']['fee']);same(34,$r['packageCount']);same('17065348.27',$r['items'][0]['nationalInvoiceValue']);
    $s=(new Customs\Domain\Draft(new Customs\Domain\Registry()))->edit($s,'declaration.signatureDate',null,'2026-10-29');
    $s=(new Customs\Domain\StatutoryRules())->apply($s);same('2026-10-29',$s['draft']['declaration']['calculationDate']);same(false,$s['draft']['goods'][0]['tariffConfirmed']);
    $r=(new Customs\Domain\ReviewValidator())->validate($s);truth((bool)array_filter($r['errors'],fn($e)=>$e['code']==='RATE_DATE'));
});

test('switching to two reporting units clears incompatible quantities without replacing a manual code', function(): void {
    $s=completeDraftState();$model=new Customs\Domain\Draft(new Customs\Domain\Registry());
    $s=$model->edit($s,'goods.commodityCode','goods-1','3702319100');$s=(new Customs\Domain\StatutoryRules())->apply($s);
    same('3702319100',$s['draft']['goods'][0]['commodityCode']);same(true,$s['draft']['goods'][0]['secondaryAdditionalUnitRequired']);same('006',$s['draft']['goods'][0]['secondaryAdditionalUnit']);
    $s=$model->edit($s,'goods.additionalQuantity','goods-1','10');$s=$model->edit($s,'goods.secondaryAdditionalQuantity','goods-1','20');
    $s=$model->edit($s,'goods.commodityCode','goods-1','2915310000');$s=(new Customs\Domain\StatutoryRules())->apply($s);
    same(null,$s['draft']['goods'][0]['additionalQuantity']);same(null,$s['draft']['goods'][0]['secondaryAdditionalQuantity']);
});

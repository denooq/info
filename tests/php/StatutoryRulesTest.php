<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/fixtures.php';

test('dated statutory rules resolve standard 2026 values and reject unknown years and medical exceptions', function(): void {
    $s=completeDraftState();$s=(new Customs\Domain\TariffDefaults())->apply($s);$s['draft']['goods'][0]['tariffConfirmed']=true;
    $rules=new Customs\Domain\StatutoryRules();$r=$rules->resolve($s['draft']);
    same('resolved',$r['status']);same('4325',$r['mrp']);same('25950.00',$r['fee']);same('16',$r['goods']['goods-1']['vatRate']);
    foreach (['2025-12-31','2027-01-01','2026-02-30'] as $date) {$s['draft']['declaration']['calculationDate']=$date;$r=$rules->resolve($s['draft']);same('unavailable',$r['status']);same(null,$r['fee']);}
    $s['draft']['declaration']['calculationDate']='2026-12-31';same('resolved',$rules->resolve($s['draft'])['status']);
    $s['draft']['goods'][0]['description']='Медицинское изделие';$r=$rules->resolve($s['draft']);same('unsupported',$r['status']);same(null,$r['goods']['goods-1']['vatRate']);
    $s['draft']['goods'][0]['description']='Товар';$s['draft']['goods'][0]['hasPreference']=null;same('needs_confirmation',$rules->resolve($s['draft'])['status']);
});

test('statutory operands are server owned and changing tariff context resets confirmation', function(): void {
    $s=completeDraftState();unset($s['draft']['goods'][0]['_tariff']);$rules=new Customs\Domain\StatutoryRules();$s=$rules->apply($s);
    same('5',$s['draft']['goods'][0]['dutyRate']);same(false,$s['draft']['goods'][0]['tariffConfirmed']);
    $s['draft']['goods'][0]['tariffConfirmed']=true;$s=$rules->apply($s);same('16',$s['draft']['goods'][0]['vatRate']);
    errorCode('FIELD_READ_ONLY',fn()=>(new Customs\Domain\Draft(new Customs\Domain\Registry()))->edit($s,'goods.vatRate','goods-1','1'));
    $s['draft']['goods'][0]['originCountry']='KZ';$s=$rules->apply($s);same(false,$s['draft']['goods'][0]['tariffConfirmed']);same(null,$s['draft']['goods'][0]['vatRate']);
    $s['draft']['goods'][0]['dutyRate']='99';$s=$rules->apply($s);same('5',$s['draft']['goods'][0]['dutyRate']);
});

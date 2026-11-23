<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/fixtures.php';

test('statutory fee stays fixed across goods counts and exact VAT has an independent expectation', function(): void {
    $calculator=new Customs\Domain\Calculator();
    foreach([1,2,5] as $count) {
        $s=completeDraftState($count);$s['draft']['declaration']['feeBase']='1';$s['draft']['declaration']['feeAdditional']='9999';
        $r=$calculator->calculate($s);same('25950.00',$r['totals']['fee']);$sum=Brick\Math\BigDecimal::zero();
        foreach($r['items'] as $item)$sum=$sum->plus($item['fee']);same('25950.00',(string)$sum);same('6',$r['nodes']['declaration/fee']['operands']['feeMultiplier']);
        same($s['draft']['statutoryRules']['hash'],$r['ruleHash']);same([],$r['graphB']);
    }
    $s=completeDraftState();$s['draft']['declaration']['invoiceCurrency']='KZT';$s['draft']['goods'][0]['invoiceValue']='100000';
    $r=$calculator->calculate($s);same('5000.00',$r['items'][0]['duty']);same('130950.00',$r['items'][0]['vatBase']);same('20952.00',$r['items'][0]['vat']);
    truth(isset($r['nodes']['goods-1/vat']['sourceUrl']));
});

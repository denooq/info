<?php
declare(strict_types=1);
namespace Customs\Domain;

use Brick\Math\BigDecimal as D;
use Brick\Math\RoundingMode as R;
use Customs\Http\ApiError;

final class Calculator
{
    public static function money(D|string $number): string { return (string)D::of($number)->toScale(2,R::HalfUp); }

    public function convert(string $amount, string $currency, array $rates): string
    {
        if ($currency==='KZT') return self::money($amount);
        foreach ($rates as $r) if ($r['currency']===$currency) {
            if (!D::of($r['value'])->isPositive() || !D::of($r['nominal'])->isPositive()) throw new ApiError('RATE_POSITIVE','Курс и кратность должны быть положительными.');
            return (string)D::of($amount)->multipliedBy($r['value'])->dividedBy($r['nominal'],2,R::HalfUp);
        }
        throw new ApiError('RATE_REQUIRED','Не указан подтверждённый курс '.$currency,422);
    }

    /** Largest fractional remainders, ties resolved by item id. Never use binary floating point. */
    public function allocate(string $total, array $weights): array
    {
        $sum=D::zero();
        foreach ($weights as $id=>$weight) { $w=D::of($weight); if($w->isNegative())throw new ApiError('ALLOCATION_BASE','База распределения не может быть отрицательной.',422); $sum=$sum->plus($w); }
        if (!$sum->isPositive()) throw new ApiError('ALLOCATION_BASE','Сумма базы распределения должна быть больше нуля.',422);
        $remaining=D::of(self::money($total)); $parts=[]; $remainders=[];
        foreach ($weights as $id=>$weight) {
            $product=D::of(self::money($total))->multipliedBy($weight);
            $part=$product->dividedBy($sum,2,R::Down);
            $parts[$id]=self::money($part); $remaining=$remaining->minus($part);
            // Remainders have a common denominator, so compare exact numerators.
            $remainders[$id]=$product->minus($part->multipliedBy($sum));
        }
        $order=array_keys($remainders);
        usort($order,fn($a,$b)=>$remainders[$b]->compareTo($remainders[$a]) ?: strcmp((string)$a,(string)$b));
        $cents=$remaining->multipliedBy('100')->toInt();
        for($i=0;$i<$cents;$i++)$parts[$order[$i]]=self::money(D::of($parts[$order[$i]])->plus('0.01'));
        return $parts;
    }

    public function calculate(array $state, bool $validate = true): array
    {
        if($validate)(new Validator(new Registry()))->requireValid($state);
        $d=$state['draft']; $goods=$d['goods']; $count=count($goods);
        if($count<1)throw new ApiError('GOODS_REQUIRED','Добавьте товары.',422);
        $extra=intdiv(max(0,$count-1)+2,3);
        $feeTotal=self::money(D::of($d['declaration']['feeBase'])->plus(D::of($d['declaration']['feeAdditional'])->multipliedBy($extra)));
        $fees=$this->allocate($feeTotal,array_fill_keys(array_column($goods,'id'),'1'));
        $costs=[]; $parts=[];
        foreach ($goods as $g) $parts[$g['id']]=['indirect'=>D::zero(),'addition'=>D::zero(),'deduction'=>D::zero()];
        $deductions=['postImportWork','inlandTransport','importTaxes'];
        foreach ($d['costs'] as $cost) {
            $expected=$cost['kind']==='indirectPayments'?'indirect':(in_array($cost['kind'],$deductions,true)?'deduction':'addition');
            if($cost['direction']!==$expected)throw new ApiError('COST_DIRECTION','Вид компонента не соответствует начислению/вычету.',422,['address'=>'cost/'.$cost['id'].'/direction']);
            $converted=$this->convert($cost['amount'],$cost['currency'],$d['rates']);
            $effective=($cost['direction']!=='deduction' && $cost['includedInPrice']===true)?'0.00':$converted;
            $weights=[];
            foreach($goods as $g) if(in_array($g['id'],$cost['goodsIds'],true)) {
                $weights[$g['id']]=match($cost['allocationBasis']) {
                    'equal'=>'1', 'value','invoiceValue'=>$g['invoiceValue'], 'netMass'=>$g['netMass'], 'grossMass'=>$g['grossMass'],
                    'manual'=>$cost['allocations'][$g['id']]??'0',
                    default=>throw new ApiError('ALLOCATION_FORMULA','Этот способ распределения не поддерживается.',422),
                };
            }
            // A confirmed zero component has zero shares even with a zero manual base.
            $allocated=D::of($effective)->isZero()?array_fill_keys(array_keys($weights),'0.00'):$this->allocate($effective,$weights);
            foreach($allocated as $id=>$amount)$parts[$id][$expected]=$parts[$id][$expected]->plus($amount);
            $costs[]=['id'=>$cost['id'],'kind'=>$cost['kind'],'sourceAmount'=>$cost['amount'],'currency'=>$cost['currency'],'converted'=>$converted,'effective'=>$effective,'includedInPrice'=>$cost['includedInPrice'],'basis'=>$cost['allocationBasis'],'allocations'=>$allocated];
        }
        $items=[]; $totals=['invoiceTotal'=>D::zero(),'customsValue'=>D::zero(),'duty'=>D::zero(),'fee'=>D::zero(),'vat'=>D::zero(),'accruedTotal'=>D::zero()];
        foreach($goods as $i=>$g) {
            if(($g['valuationMethod']??'1')!=='1')throw new ApiError('METHOD_UNSUPPORTED','MVP поддерживает только метод 1.',422);
            if(($g['dutyFormula']??'advalorem')!=='advalorem'||($g['exciseApplicable']??false)!==false)throw new ApiError('PAYMENT_FORMULA_UNSUPPORTED','MVP поддерживает адвалорную пошлину без акциза.',422);
            $id=$g['id']; $invoice=$this->convert($g['invoiceValue'],$d['declaration']['invoiceCurrency'],$d['rates']);
            $indirect=self::money($parts[$id]['indirect']); $basis=self::money(D::of($invoice)->plus($indirect));
            $addition=self::money($parts[$id]['addition']); $deduction=self::money($parts[$id]['deduction']);
            $cv=self::money(D::of($basis)->plus($addition)->minus($deduction));
            if(D::of($cv)->isNegative())throw new ApiError('CUSTOMS_VALUE_NEGATIVE','Вычеты превышают стоимость товара.',422);
            $duty=(string)D::of($cv)->multipliedBy($g['dutyRate'])->dividedBy('100',2,R::HalfUp);
            $fee=$fees[$id]; $vatBase=self::money(D::of($cv)->plus($duty)->plus($fee));
            $vat=(string)D::of($vatBase)->multipliedBy($g['vatRate'])->dividedBy('100',2,R::HalfUp);
            $total=self::money(D::of($duty)->plus($fee)->plus($vat));
            $statKzt=D::of($cv)->plus($g['statisticalAddition'])->minus($g['statisticalDeduction']);
            if($statKzt->isNegative())throw new ApiError('STAT_NEGATIVE','Статистическая стоимость не может быть отрицательной.',422);
            $usdRate=null; foreach($d['rates'] as $r)if($r['currency']==='USD')$usdRate=$r;
            if($usdRate===null)throw new ApiError('RATE_REQUIRED','Нужен курс USD.',422);
            $stat=(string)$statKzt->multipliedBy($usdRate['nominal'])->dividedBy($usdRate['value'],2,R::HalfUp);
            $cvUsd=(string)D::of($cv)->multipliedBy($usdRate['nominal'])->dividedBy($usdRate['value'],2,R::HalfUp);
            $items[]=['id'=>$id,'ordinal'=>$i+1,'sheetOrdinal'=>$i===0?1:2+intdiv($i-1,3),'dtsPageOrdinal'=>2+intdiv($i,3),'nationalInvoiceValue'=>$invoice,'indirectValue'=>$indirect,'basisValue'=>$basis,'additionValue'=>$addition,'deductionValue'=>$deduction,'customsValue'=>$cv,'customsValueUsd'=>$cvUsd,'statisticalValue'=>$stat,'statisticalBaseKzt'=>self::money($statKzt),'fee'=>$fee,'duty'=>$duty,'vatBase'=>$vatBase,'vat'=>$vat,'accruedTotal'=>$total,'payments'=>[['code'=>'1010','base'=>$cv,'rate'=>null,'amount'=>$fee],['code'=>'2010','base'=>$cv,'rate'=>$g['dutyRate'],'amount'=>$duty],['code'=>'5060','base'=>$vatBase,'rate'=>$g['vatRate'],'amount'=>$vat]]];
            $totals['invoiceTotal']=$totals['invoiceTotal']->plus($g['invoiceValue']);
            foreach(['customsValue'=>$cv,'duty'=>$duty,'fee'=>$fee,'vat'=>$vat,'accruedTotal'=>$total] as $key=>$v)$totals[$key]=$totals[$key]->plus($v);
        }
        $packageCount=0;
        foreach($d['packages'] as $p)if(in_array($p['id'],$d['declaration']['shipmentPackageIds'],true))$packageCount+=$p['quantity'];
        $paid=[]; if($d['declaration']['paymentFactsAvailable']===true)foreach($d['payments'] as $p) {
            if(!in_array($p['code'],['1010','2010','5060'],true))throw new ApiError('PAYMENT_PROFILE','Код платежа не поддерживается профилем.',422);
            $paid[$p['code']]=self::money(D::of($paid[$p['code']]??'0')->plus($p['amount']));
        }
        $wholePaid=[];foreach($paid as $code=>$amount)$wholePaid[$code]=(string)D::of($amount)->toScale(0,R::HalfUp);
        $nodes=[];
        foreach($items as $item){$g=$goods[$item['ordinal']-1];$prefix=$item['id'].'/';
            $nodes[$prefix.'customsValue']=['formula'=>'basisValue + additionValue - deductionValue','operands'=>array_intersect_key($item,array_flip(['basisValue','additionValue','deductionValue'])),'result'=>$item['customsValue'],'scale'=>2];
            $nodes[$prefix.'duty']=['formula'=>'customsValue * dutyRate / 100','operands'=>['customsValue'=>$item['customsValue'],'dutyRate'=>$g['dutyRate']],'result'=>$item['duty'],'scale'=>2];
            $nodes[$prefix.'vat']=['formula'=>'(customsValue + duty + fee) * vatRate / 100','operands'=>['customsValue'=>$item['customsValue'],'duty'=>$item['duty'],'fee'=>$item['fee'],'vatRate'=>$g['vatRate']],'result'=>$item['vat'],'scale'=>2];
        }
        $result=['dataRevision'=>$state['dataRevision'],'registryVersion'=>$state['registryVersion'],'profile'=>Registry::resource('calculation-profile'),'nodes'=>$nodes,'goodsCount'=>$count,'sheetCount'=>1+$extra,'packageCount'=>$packageCount,'items'=>$items,'costs'=>$costs,'totals'=>array_map(self::money(...),$totals),'actualPaid'=>$paid,'graphB'=>$wholePaid,'proposals'=>[],'consistent'=>true];
        $result['hash']=hash('sha256',json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        return $result;
    }

    public function propose(array $calculation, string $id, string $key, ?string $value): array
    {
        if(!in_array($key,['customsValue','statisticalValue','duty','fee','vat','accruedTotal'],true))throw new ApiError('CALC_FIELD','Это поле не редактируется как результат.');
        $item=null;foreach($calculation['items'] as $row)if($row['id']===$id)$item=$row;
        if($item===null)throw new ApiError('ENTITY_UNKNOWN','Товар расчёта не найден.');
        $address=$id.'/'.$key;
        if($value===null)unset($calculation['proposals'][$address]);
        else {
            $value=(new Normalizer())->value(['type'=>'decimal'],$value,null,'');
            $calculation['proposals'][$address]=['value'=>$value,'calculated'=>$item[$key],'matches'=>D::of($value)->isEqualTo($item[$key])];
        }
        $calculation['consistent']=!in_array(false,array_column($calculation['proposals'],'matches'),true);
        return $calculation;
    }
}

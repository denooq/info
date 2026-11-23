<?php
declare(strict_types=1);
require_once __DIR__.'/fixtures.php';

/** Test-only scenarios: supplied documentary facts plus explicitly synthetic manual confirmations. */
function referenceState(string $case):array
{
    $s=completeDraftState();$d=&$s['draft'];
    foreach($d['parties'] as &$p){$p['name']='Ручное заполнение проверочного сценария';$p['briefName']=null;$p['region']=null;$p['city']='Проверочный адрес';$p['street']='Заполнено вручную для теста';$p['postalCode']=null;}unset($p);
    foreach(['manufacturer','tradeMark','brand','model','article'] as $key)$d['goods'][0][$key]=null;
    foreach($d['documents'] as &$doc){$doc['validFrom']=$doc['validUntil']=null;}unset($doc);
    $d['goods'][0]['description']=$case==='reference-cpt'?'Этилацетат, CAS 141-78-6':'Клей H.B. Fuller';
    if($case==='reference-cpt'){
        $d['documents'][0]=array_replace($d['documents'][0],['name'=>'Инвойс','number'=>'AHRBXS2026103311','date'=>'2026-10-09']);
        $d['documents'][1]=array_replace($d['documents'][1],['name'=>'Контракт','number'=>'777-ЕА','date'=>'2025-04-08']);
        $d['documents'][]=array_replace($d['documents'][0],['id'=>'letter','kind'=>'01999','name'=>'Письмо по СЭС','number'=>'10-18/332','date'=>'2025-02-21','presentationCode'=>'1']);
    }elseif($case==='reference-fca'){
        $d['declaration']['dispatchCountry']=$d['declaration']['tradeCountry']='DE';
        $d['declaration']['invoiceCurrency']='EUR';$d['declaration']['deliveryCode']='FCA';$d['declaration']['deliveryPlace']='NIENBURG';
        $d['goods'][0]=array_replace($d['goods'][0],['description'=>'Клей H.B. Fuller','commodityCode'=>'3506910000','originCountry'=>'DE','grossMass'=>'1248','netMass'=>'1200','invoiceValue'=>'2844','packageQuantity'=>2,'article'=>'1000054922']);
        $d['packages'][0]['quantity']=2;
        $d['documents'][0]=array_replace($d['documents'][0],['name'=>'Инвойс','number'=>'2601011274','date'=>'2026-10-26']);
        $d['documents'][1]['number']='Заполнено вручную для теста';
        $d['rates'][]=['id'=>'rate-eur','currency'=>'EUR','value'=>'508.33','nominal'=>'1','date'=>'2026-10-28','source'=>'Проверочный курс из сценария; не для новой декларации','confirmed'=>true];
        foreach(['freight'=>'2058736.50','insurance'=>'5222'] as $kind=>$amount){$d['declaration'][$kind.'Applicable']=true;$d['costs'][]=['id'=>'cost-'.$kind,'kind'=>$kind,'direction'=>'addition','amount'=>$amount,'currency'=>'KZT','includedInPrice'=>false,'basis'=>'Участок до места ввоза подтверждён только для теста','evidenceIds'=>[],'allocationBasis'=>'value','allocationConfirmed'=>true,'goodsIds'=>['goods-1']];}
    }else throw new InvalidArgumentException('Unknown reference case');
    unset($d);
    $rules=new Customs\Domain\StatutoryRules();$s=$rules->apply($s);
    foreach($s['draft']['goods'] as &$goods)$goods['tariffConfirmed']=true;unset($goods);
    return $rules->apply($s);
}

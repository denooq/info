<?php
declare(strict_types=1);

function completeDraftState(int $goodsCount=1): array
{
    $r=new Customs\Domain\Registry(); $draft=(new Customs\Domain\Draft($r))->empty();
    $draft['goods']=[];
    for($i=0;$i<$goodsCount;$i++)$draft['goods'][]=['id'=>'goods-'.($i+1),...(new Customs\Domain\Draft($r))->row('goods')];
    $draft['documents']=[['id'=>'doc-invoice',...(new Customs\Domain\Draft($r))->row('document')],['id'=>'doc-contract',...(new Customs\Domain\Draft($r))->row('document')]];
    $draft['rates']=[['id'=>'rate-usd',...(new Customs\Domain\Draft($r))->row('rate')]];
    foreach($r->fields() as $f){
        if(in_array('calculated',$f['acquisition'],true))continue;
        $collection=$r->data()['scopes'][$f['scope']]['collection'];
        $indices=$collection===null?[null]:array_keys($draft[$collection]);
        foreach($indices as $i){
            $row=&$draft[$collection??$f['scope']];if($i!==null)$row=&$draft[$collection][$i];
            if(in_array($f['key'],['role','stage'],true)||isset($f['fixedValue']))continue;
            $row[$f['key']]=match($f['type']) {
                'boolean'=>false, 'decimal'=>'0', 'integer'=>1, 'date'=>'2026-10-28',
                'enum'=>$f['values'][0]??'test', 'code'=>'00', 'identifier'=>'040340007232',
                'string-list','date-list','code-list'=>[],default=>'Проверочные данные',
            };
            if($f['key']==='sameAsRole')$row[$f['key']]=null;
            $id=$i===null?null:$row['id'];
            $draft['fieldStates'][Customs\Domain\Draft::address($f['scope'],$id,$f['key'])]=['status'=>'manual','candidateId'=>null,'evidenceIds'=>[]];
            unset($row);
        }
    }
    $draft['declaration']=array_replace($draft['declaration'],[
        'dispatchCountry'=>'CN','destinationCountry'=>'KZ','originCountry'=>'CN','sourcePackageCount'=>null,'tradeCountry'=>'CN','deliveryCode'=>'CPT','deliveryPlace'=>'ALMATY','invoiceCurrency'=>'USD','transactionCode'=>'010','transactionFeature'=>'00','borderOfficeCode'=>'55411','borderOfficeCountry'=>'KZ','locationCode'=>'11','locationRegistration'=>'001','locationCountry'=>'KZ','locationOfficeCode'=>'55411','shipmentPackageIds'=>['package-1'],'valuationDocumentIds'=>['doc-invoice','doc-contract'],'methodOneConfirmed'=>true,'feeBase'=>'25950','feeAdditional'=>'0',
    ]);
    foreach($draft['parties'] as &$p){$p['country']='KZ';$p['identifierKind']='1';}unset($p);
    foreach($draft['transports'] as &$p){$p['mode']='31';$p['country']='KZ';$p['transportType']='31';}unset($p);
    foreach($draft['goods'] as $i=>&$g){
        $line='manual-line-'.($i+1);$draft['sourceLines'][$line]=['id'=>$line,'origin'=>'manual','quote'=>'Проверочная строка '.($i+1)];
        $g=array_replace($g,['description'=>'Проверочный товар '.($i+1),'commodityCode'=>'2915310000','originCountry'=>'CN','grossMass'=>'27860','netMass'=>'24480','invoiceValue'=>'37454.40','sourceLineIds'=>[$line],'packageIds'=>['package-1'],'packageKind'=>'PX','packageQuantity'=>34,'preferenceFee'=>'ОО','preferenceDuty'=>'ОО','preferenceExcise'=>'ОО','preferenceVat'=>'ОО','previousProcedure'=>'00','movementFeature'=>'000','prohibitionCodes'=>['000'],'intellectualPropertyCode'=>'0','dutyRate'=>'5','vatRate'=>'16','ratesDate'=>'2026-10-28','rateBasis'=>'Проверочные ставки, только для теста','statisticalBasisConfirmed'=>true,'groupConfirmed'=>true]);
    }unset($g);
    $draft['packages']=[['id'=>'package-1','label'=>'Общая группа паллет','quantity'=>34,'kind'=>'PX']];
    foreach($draft['documents'] as $i=>&$doc)$doc=array_replace($doc,['kind'=>$i===0?'04021':'03011','presentationCode'=>'0','dateRequired'=>true,'goodsIds'=>array_column($draft['goods'],'id')]);unset($doc);
    $draft['rates'][0]=array_replace($draft['rates'][0],['currency'=>'USD','value'=>'455.63','nominal'=>'1','date'=>'2026-10-28','source'=>'Проверочный курс, только для теста','confirmed'=>true]);
    $draft['keden']=array_replace($draft['keden'],['officeCode'=>'55411','rateType'=>'1','fillerType'=>'0','filledBy'=>'1','personId'=>'test-person']);
    foreach($draft['goods'] as &$g)$g['paymentFeature']='ИУ';unset($g);
    return ['draft'=>$draft,'candidates'=>[],'evidence'=>[],'documents'=>[],'dataRevision'=>1,'registryVersion'=>'0.1.0','phase'=>'reviewing'];
}

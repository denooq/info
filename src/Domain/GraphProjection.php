<?php
declare(strict_types=1);
namespace Customs\Domain;

final class GraphProjection
{
    public const UNAVAILABLE='не удалось заполнить автоматически';
    public function project(array $state): array
    {
        $registry=new Registry();$graphs=new GraphRegistry();$draft=GraphDefaults::apply($state['draft']);$result=[];
        $calculation=($state['calculation']['dataRevision']??null)===($state['dataRevision']??0)?($state['calculation']??null):null;
        foreach($graphs->graphs() as $graph) {
            $entries=[];
            foreach($graph['fieldBindings'] as $id) {
                $f=$registry->field($id);
                foreach($graphs->rows($draft,$graph,$f) as $row) {
                    $entityId=$f['scope']==='declaration'?null:$row['id'];$value=$row[$f['key']]??null;$address=Draft::address($f['scope'],$entityId,$f['key']);
                    $meta=$draft['fieldStates'][$address]??[];$required=Conditions::evaluate($f['requiredWhen'],$row);
                    $status=($meta['conflict']??false)||($meta['needsReview']??false)?'conflict':(Conditions::missing($value)?($graph['emptyState']==='auto_unavailable'?'auto_unavailable':'missing'):($meta['status']??'manual'));
                    if(!$required && is_array($f['requiredWhen']))$status='not_applicable';
                    if($graph['calculationStage']==='result') {
                        if($calculation===null){$value=null;$status='pending_calculation';}
                        elseif($f['scope']==='declaration'){$value=$calculation['totals'][$f['key']]??null;$status='calculated';}
                        else {foreach($calculation['items'] as $item)if($item['id']===$entityId)$value=$item[$f['key']]??null;$status='calculated';}
                    }
                    $entries[]=['fieldId'=>$id,'entityId'=>$entityId,'address'=>$address,'value'=>$value,'status'=>$status,'required'=>$required,'placeholder'=>$status==='auto_unavailable'?self::UNAVAILABLE:null,'evidenceIds'=>$meta['evidenceIds']??[]];
                }
            }
            $status=$graph['fillMode']==='not_applicable'?'not_applicable':($graph['calculationStage']==='result'&&$calculation===null?'pending_calculation':'filled');
            if($status==='filled')foreach($entries as $entry)if($entry['required']&&in_array($entry['status'],['missing','conflict','auto_unavailable'],true)){$status=$entry['status'];break;}
            $displayValue=null;
            if(isset($graph['countryNameFrom'])) {
                $displayValue=(new Classifiers())->country($draft['declaration'][$graph['countryNameFrom']]??'')['name']??null;
                if($displayValue===null)$status='missing';
            }
            if($graph['id']==='47'&&$calculation!==null)$displayValue=array_map(fn($i)=>['goodsId'=>$i['id'],'fee'=>$i['fee'],'duty'=>$i['duty'],'vat'=>$i['vat']],$calculation['items']);
            if($graph['id']==='23'&&($draft['declaration']['invoiceCurrency']??null)==='KZT')$displayValue=['currency'=>'KZT','value'=>'1','nominal'=>'1','date'=>$draft['declaration']['signatureDate']];
            if($graph['id']==='B'){$status=($draft['declaration']['paymentFactsAvailable']??null)===false?'not_applicable':($calculation!==null?'calculated':'pending_calculation');$displayValue=$calculation['graphB']??null;}
            $result[]=['id'=>$graph['id'],'parentGraph'=>$graph['parentGraph'],'status'=>$status,'entries'=>$entries,'displayValue'=>$displayValue];
        }
        return ['version'=>$graphs->data()['version'],'profile'=>'im40-kz-graphs','dataRevision'=>$state['dataRevision']??0,'graphs'=>$result];
    }
}

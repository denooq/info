<?php
declare(strict_types=1);
namespace Customs\Domain;

final class GraphProjection
{
    public const UNAVAILABLE='не удалось заполнить автоматически';
    public function project(array $state): array
    {
        $registry=new Registry();$graphs=new GraphRegistry();$draft=GraphDefaults::apply($state['draft']);$result=[];
        $mappingResults=(new \Customs\AI\GraphMappingResultStore())->project($state);
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
                    $mappingField=$this->mappingField($mappingResults,$graph['id'],$id,$address);
                    $entries[]=['fieldId'=>$id,'entityId'=>$entityId,'address'=>$address,'value'=>$value,'status'=>$status,'required'=>$required,'placeholder'=>$status==='auto_unavailable'?self::UNAVAILABLE:null,'evidenceIds'=>$meta['evidenceIds']??[],'mappingStatus'=>$mappingField['status']??null,'mappingAlternatives'=>$mappingField['alternatives']??[]];
                }
            }
            $status=$graph['fillMode']==='not_applicable'?'not_applicable':($graph['calculationStage']==='result'&&$calculation===null?'pending_calculation':'filled');
            if($status==='filled')foreach($entries as $entry)if($entry['required']&&in_array($entry['status'],['missing','conflict','auto_unavailable'],true)){$status=$entry['status'];break;}
            $displayValue=null;
            if(isset($graph['countryNameFrom'])) {
                $countryCode=$draft['declaration'][$graph['countryNameFrom']]??null;
                $country=is_string($countryCode)?(new Classifiers())->country($countryCode):null;
                $displayValue=$country===null?null:['code'=>$countryCode,'name'=>$country['name']];
                if($displayValue===null)$status='missing';
            }
            if(in_array($graph['id'],['15a','17a'],true)) {
                $countryCode=$entries[0]['value']??null;$country=is_string($countryCode)?(new Classifiers())->country($countryCode):null;
                $displayValue=$country===null?null:['code'=>$countryCode,'name'=>$country['name']];
            }
            if($graph['id']==='47'&&$calculation!==null)$displayValue=array_map(fn($i)=>['goodsId'=>$i['id'],'fee'=>$i['fee'],'duty'=>$i['duty'],'vat'=>$i['vat']],$calculation['items']);
            if($graph['id']==='23'&&($draft['declaration']['invoiceCurrency']??null)==='KZT')$displayValue=['currency'=>'KZT','value'=>'1','nominal'=>'1','date'=>$draft['declaration']['signatureDate']];
            if($graph['id']==='B'){$status=($draft['declaration']['paymentFactsAvailable']??null)===false?'not_applicable':($calculation!==null?'calculated':'pending_calculation');$displayValue=$calculation['graphB']??null;}
            $mappingTarget=$this->mappingTarget($mappingResults,$graph['id'],$entries[0]['entityId']??null)
                ??$this->mappingTarget($mappingResults,$graph['id'],null)
                ??$this->firstMappingTarget($mappingResults,$graph['id']);
            $result[]=['id'=>$graph['id'],'parentGraph'=>$graph['parentGraph'],'status'=>$status,'mappingStatus'=>$mappingTarget['status']??null,'mappingReason'=>$mappingTarget['reason']??null,'entries'=>$entries,'displayValue'=>$displayValue];
        }
        return ['version'=>GraphEditors::VERSION,'profile'=>'im40-kz-compact','dataRevision'=>$state['dataRevision']??0,'graphs'=>$result,'mappingResults'=>$mappingResults,'editors'=>(new CompactGraphs())->project($state)];
    }

    private function mappingTarget(array $results,string $graphId,?string $entityId):?array
    {
        foreach($results['targets']??[] as $target)if($target['graphId']===$graphId&&($target['entityId']??null)===$entityId)return $target;
        return null;
    }

    private function firstMappingTarget(array $results,string $graphId):?array
    {
        foreach($results['targets']??[] as $target)if($target['graphId']===$graphId)return $target;
        return null;
    }

    private function mappingField(array $results,string $graphId,string $fieldId,string $address):?array
    {
        foreach($results['targets']??[] as $target)if($target['graphId']===$graphId)foreach($target['fields']??[] as $field)
            if($field['fieldId']===$fieldId&&$field['address']===$address)return $field;
        return null;
    }
}

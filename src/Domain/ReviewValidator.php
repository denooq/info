<?php
declare(strict_types=1);
namespace Customs\Domain;

use Brick\Math\BigDecimal;
use Customs\Http\ApiError;

final class ReviewValidator
{
    private array $errors=[];
    public function validate(array $state):array
    {
        $this->errors=[];$draft=GraphDefaults::apply($state['draft']);$registry=new Registry();$graphs=new GraphRegistry();$checked=[];
        foreach($graphs->graphs() as $graph) {
            if($graph['calculationStage']!=='review'||$graph['fillMode']==='not_applicable')continue;
            foreach($graph['fieldBindings'] as $fieldId) {
                $f=$registry->field($fieldId);if(in_array('calculated',$f['acquisition'],true))continue;
                foreach($graphs->rows($draft,$graph,$f) as $row) {
                    $address=Draft::address($f['scope'],$f['scope']==='declaration'?null:$row['id'],$f['key']);
                    if(isset($checked[$address]))continue;$checked[$address]=true;
                    $required=Conditions::evaluate($f['requiredWhen'],$row)||($graph['id']==='16'&&$f['id']==='declaration.originCountry');$value=$row[$f['key']]??null;$meta=$draft['fieldStates'][$address]??[];
                    if(!$required&&is_array($f['requiredWhen']))continue;
                    if(($meta['conflict']??false)||($meta['needsReview']??false))$this->error($address,$graph['id'],'CONFLICT','Выберите источник или подтвердите значение вручную.');
                    if(Conditions::missing($value)||$value===GraphProjection::UNAVAILABLE) {if($required)$this->error($address,$graph['id'],'REQUIRED','Заполните обязательный реквизит графы.');continue;}
                    foreach((new Normalizer())->issues($f,$value) as $message) {
                        $list=Registry::resource('catalogs')['fieldCatalogs'][$f['id']]??null;
                        $trustedRate=$f['id']==='rate.currency'&&($meta['status']??'')==='nbk'&&($row['_nbk']['currency']??null)===$value;
                        if(str_contains($message,'справочник')&&!in_array($list,['countries','documentKinds'],true)&&(in_array($meta['status']??'', ['manual','selected'],true)||$trustedRate))continue;
                        $this->error($address,$graph['id'],'VALUE',$message);
                    }
                    if(isset($f['fixedValue'])&&$value!==$f['fixedValue'])$this->error($address,$graph['id'],'PROFILE','Значение задано профилем ИМ 40.');
                }
            }
        }
        if($draft['goods']===[])$this->error('goods','31','GOODS_REQUIRED','Добавьте хотя бы один товар.');
        $usedLines=[];
        foreach($draft['goods'] as $g) {
            $prefix='goods/'.$g['id'].'/';
            if(($g['groupConfirmed']??null)!==true)$this->error($prefix.'groupConfirmed','31','GROUP_CONFIRM','Подтвердите состав товара.');
            foreach($g['sourceLineIds']??[] as $line) {
                if(!isset($draft['sourceLines'][$line])||isset($usedLines[$line]))$this->error($prefix.'groupConfirmed','31','SOURCE_LINE','Исходная строка отсутствует или повторяется в другом товаре. Проверьте группировку: объедините повторы либо создайте отдельный товар вручную.');
                $usedLines[$line]=true;
            }
            try{if($g['grossMass']!==null&&$g['netMass']!==null&&BigDecimal::of($g['netMass'])->isGreaterThan($g['grossMass']))$this->error($prefix.'netMass','38','MASS_ORDER','Нетто превышает брутто.');}catch(\Throwable){}
            $this->links($prefix.'packageIds','31',$g['packageIds']??[],array_column($draft['packages'],'id'));
            if(array_diff($g['packageIds']??[],$draft['declaration']['shipmentPackageIds']??[]))$this->error($prefix.'packageIds','31','PACKAGE_SHIPMENT','Места товара должны входить в места поставки.');
        }
        $this->links('declaration/_/shipmentPackageIds','6',$draft['declaration']['shipmentPackageIds']??[],array_column($draft['packages'],'id'));
        foreach($draft['packages'] as $p)if(!is_int($p['quantity']??null)||$p['quantity']<1||trim($p['label']??'')==='')$this->error('package/'.$p['id'].'/quantity','6','PACKAGE','Укажите группу мест и положительное количество.');
        $parties=array_column($draft['parties'],null,'role');
        foreach(['2'=>'consignor','8'=>'consignee','9'=>'financial','14'=>'declarant'] as $graphId=>$role) {
            $seen=[];$current=$role;
            while($current!==null) {
                if(!isset($parties[$current])||isset($seen[$current])){$this->error('party/'.($parties[$role]['id']??$role).'/sameAsRole',(string)$graphId,'PARTY_LINK','Связь участника отсутствует или образует цикл.');break;}
                $seen[$current]=true;$current=$parties[$current]['sameAsRole']??null;
            }
        }
        foreach($registry->data()['requiredDocuments'] as $required)if(!in_array($required['kind'],array_column($draft['documents'],'kind'),true))$this->error('documents','44','DOCUMENT_REQUIRED','Заполните реквизиты документа '.$required['kind'].' в графе 44; файл прикладывать повторно не нужно.');
        $catalog=new Classifiers();
        foreach($draft['documents'] as $document) {
            $kind=$catalog->document($document['kind']??'',$draft['declaration']['signatureDate']);
            if(!$kind||!$kind['selectable'])$this->error('document/'.$document['id'].'/kind','44','DOCUMENT_KIND',$kind['unavailableReason']??'Выберите официальный код вида документа.');
            $this->links('document/'.$document['id'].'/goodsIds','44',$document['goodsIds']??[],array_column($draft['goods'],'id'));
            $this->links('document/'.$document['id'].'/documentIds','44',$document['documentIds']??[],array_keys($state['documents']??[]));
        }
        $currency=$draft['declaration']['invoiceCurrency']??null;
        if($currency!==null&&$currency!=='KZT') {
            $rates=array_values(array_filter($draft['rates'],fn($r)=>$r['currency']===$currency));
            if(count($rates)!==1)$this->error('rates','23','RATE_REQUIRED','Укажите один курс '.$currency.' на дату составления.');
            foreach($rates as $r) {
                if(($r['date']??null)!==$draft['declaration']['signatureDate'])$this->error('rate/'.$r['id'].'/date','23','RATE_DATE','Курс должен соответствовать дате составления.');
                if(($r['confirmed']??null)!==true)$this->error('rate/'.$r['id'].'/confirmed','23','RATE_CONFIRM','Подтвердите курс и источник.');
            }
        }
        return ['valid'=>$this->errors===[],'errors'=>$this->errors,'count'=>count($this->errors),'registryVersion'=>'2.0.0','stage'=>'review'];
    }
    public function requireValid(array $state):void {$r=$this->validate($state);if(!$r['valid'])throw new ApiError('DECLARATION_INVALID','Заполните все применимые обязательные исходные графы.',422,$r);}
    private function links(string $address,string $graph,array $values,array $allowed):void {if(array_diff($values,$allowed))$this->error($address,$graph,'LINK_INVALID','Ссылка указывает на отсутствующую строку.');}
    private function error(string $address,string $graphId,string $code,string $message):void {$this->errors[]=compact('address','graphId','code','message');}
}

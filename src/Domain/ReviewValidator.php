<?php
declare(strict_types=1);
namespace Customs\Domain;

use Brick\Math\BigDecimal;
use Customs\Http\ApiError;

final class ReviewValidator
{
    private array $errors=[];

    public function validate(array $state): array
    {
        $this->errors=[]; $state['draft']=GraphDefaults::apply($state['draft']); $draft=$state['draft'];
        $registry=new Registry(); $graphs=new GraphRegistry(); $manifest=new GraphEditors();
        foreach((new CompactGraphs())->project($state) as $item) {
            $editor=$manifest->editor($item['graphId']);
            if($editor['readOnlyWhen'])continue;
            $graph=$graphs->graph($item['graphId']);
            if($item['error']!==null)$this->error($item['address'],$graph['id'],'GRAPH_FORMAT',$item['error']);
            if($item['status']==='conflict')$this->error($item['address'],$graph['id'],'CONFLICT','Выберите источник или подтвердите полный текст графы.');
            if(in_array($editor['editorKind'],['text','signature'],true) && $editor['requiredWhen'] && (trim((string)$item['value'])===''||$item['value']===GraphProjection::UNAVAILABLE))$this->error($item['address'],$graph['id'],'REQUIRED','Заполните обязательную графу.');
            foreach($item['inputs'] as $input) {
                $f=$registry->field($input['fieldId']);$rows=$graphs->rows($draft,$graph,$f);
                if($graph['id']==='15')$rows=$graphs->rows($draft,$graphs->graph('15a'),$f);
                foreach($rows as $row)if(($f['scope']==='declaration'?null:$row['id'])===$input['entityId']) {
                    if($f['scope']==='goods'&&$item['entityId']!==null&&$row['id']!==$item['entityId'])continue;
                    if($f['id']==='declaration.sourcePackageCount'&&($draft['packages']??[])!==[]&&($row['sourcePackageCount']??null)===null)continue;
                    $this->field($state,$f,$row,$input['address'],$graph['id'],$input['address'],$f['id']==='declaration.originCountry');
                }
            }
            if($editor['editorKind']==='code-text')foreach($editor['typedBindings'] as $fieldId) {
                $f=$registry->field($fieldId);
                foreach($graphs->rows($draft,$graph,$f) as $row) {
                    if($f['scope']==='goods'&&$row['id']!==$item['entityId'])continue;
                    $address=Draft::address($f['scope'],$f['scope']==='declaration'?null:$row['id'],$f['key']);
                    $this->field($state,$f,$row,$address,$graph['id'],$item['address']);
                }
            }
        }
        if($draft['goods']===[])$this->error('goods','31','GOODS_REQUIRED','Добавьте хотя бы один товар.');
        if(($draft['declaration']['packageCount']??null)===null)$this->error('declaration/_/sourcePackageCount','6','PACKAGE_REQUIRED','Укажите количество мест или добавьте и свяжите группы мест.');
        $packageIds=array_column($draft['packages'],'id'); $selected=$draft['declaration']['shipmentPackageIds']??[];$used=[];
        if(array_diff($selected,$packageIds))$this->error('declaration/_/shipmentPackageIds','6','LINK_INVALID','Выберите существующие места поставки.');
        foreach($draft['packages'] as $p)if(!is_int($p['quantity']??null)||$p['quantity']<1||trim($p['label']??'')==='')$this->error('package/'.$p['id'].'/quantity','6','PACKAGE','Укажите название группы и положительное целое количество мест.');
        foreach($draft['goods'] as $g) {
            $a=CompactGraphs::address('31',$g['id']);
            foreach($g['sourceLineIds']??[] as $line) {
                if(!isset($draft['sourceLines'][$line])||isset($used[$line]))$this->error($a,'31','SOURCE_LINE','Проверьте состав товара: исходная строка отсутствует или повторяется; объедините повторы или разделите товар.');
                $used[$line]=true;
            }
            if(array_diff($g['packageIds']??[],$selected))$this->error('goods/'.$g['id'].'/packageIds','6','PACKAGE_SHIPMENT','Свяжите товар с местами поставки в графе 6.');
            try{if($g['grossMass']!==null&&$g['netMass']!==null&&BigDecimal::of($g['netMass'])->isGreaterThan($g['grossMass']))$this->error('goods/'.$g['id'].'/netMass','38','MASS_ORDER','Нетто превышает брутто.');}catch(\Throwable){}
            $this->documents($state,$g['id']);
        }
        $currency=$draft['declaration']['invoiceCurrency']??null;
        if($currency!==null&&$currency!=='KZT') {
            $rates=array_values(array_filter($draft['rates'],fn($r)=>$r['currency']===$currency));
            if(count($rates)!==1)$this->error('graph/23/_','23','RATE_REQUIRED','Нужен один курс '.$currency.' на дату составления. Получите курсы НБК или заполните ручной резерв.');
            foreach($rates as $rate) {
                if(($rate['date']??null)!==$draft['declaration']['signatureDate'])$this->error('graph/23/_','23','RATE_DATE','Курс должен соответствовать дате составления.');
                if(($rate['confirmed']??null)!==true)$this->error('rate/'.$rate['id'].'/confirmed','23','RATE_CONFIRM','Подтвердите курс и источник.');
            }
        }
        return ['valid'=>$this->errors===[],'errors'=>$this->errors,'count'=>count($this->errors),'registryVersion'=>GraphEditors::VERSION,'stage'=>'review'];
    }

    private function field(array $state,array $field,array $row,string $address,string $graphId,string $target,bool $force=false):void
    {
        if(in_array('calculated',$field['acquisition'],true)||isset($field['fixedValue']))return;
        $required=$force||Conditions::evaluate($field['requiredWhen'],$row);
        if(!$required&&is_array($field['requiredWhen']))return;
        if(isset($state['draft']['invalidInputs'][$address])){$this->error($target,$graphId,'VALUE',$state['draft']['invalidInputs'][$address]['message']);return;}
        $meta=$state['draft']['fieldStates'][$address]??[];$value=$row[$field['key']]??null;
        if(($meta['conflict']??false)||($meta['needsReview']??false))$this->error($target,$graphId,'CONFLICT','Выберите источник или подтвердите значение в этой графе.');
        if(Conditions::missing($value)||$value===GraphProjection::UNAVAILABLE){if($required)$this->error($target,$graphId,'REQUIRED','Заполните обязательное значение графы.');return;}
        foreach((new Normalizer())->issues($field,$value) as $message) {
            $catalog=Registry::resource('catalogs')['fieldCatalogs'][$field['id']]??null;
            if(str_contains($message,'справочник')&&!in_array($catalog,['countries','documentKinds'],true)&&$field['id']!=='goods.commodityCode'&&(in_array($meta['status']??'',['manual','selected'],true)||($meta['status']??'')==='nbk'))continue;
            $this->error($target,$graphId,'VALUE',$message);
        }
    }

    private function documents(array $state,string $goodsId):void
    {
        $a=CompactGraphs::address('44',$goodsId);$draft=$state['draft'];$catalog=new Classifiers();
        $records=array_values(array_filter($draft['documents'],fn($r)=>($r['goodsIds']??[])===[]||in_array($goodsId,$r['goodsIds'],true)));
        foreach((new Registry())->data()['requiredDocuments'] as $required)if(!in_array($required['kind'],array_column($records,'kind'),true))$this->error($a,'44','DOCUMENT_REQUIRED','Укажите реквизиты '.$required['name'].' ('.$required['kind'].') в едином списке графы 44; файл повторно загружать не нужно.');
        foreach($records as $record) {
            foreach(['kind','number','date','presentationCode','validFrom','validUntil'] as $key){$meta=$draft['fieldStates']['document/'.$record['id'].'/'.$key]??[];if(($meta['conflict']??false)||($meta['needsReview']??false))$this->error($a,'44','CONFLICT','Разрешите противоречие реквизитов документа '.$record['kind'].' в графе 44.');}
            $kind=$catalog->document($record['kind']??'',$draft['declaration']['signatureDate']);
            if(!$kind||!$kind['selectable'])$this->error($a,'44','DOCUMENT_KIND',$kind['unavailableReason']??'Нужен действующий официальный код вида документа.');
            if(Conditions::missing($record['number']??null)||(($record['dateRequired']??true)&&Conditions::missing($record['date']??null))||!in_array($record['presentationCode']??null,['0','1','2','3'],true))$this->error($a,'44','DOCUMENT_VALUE','Проверьте номер, дату и код представления документа. Формат: '.GraphCodecs::HINTS['44']);
            foreach(['date','validFrom','validUntil'] as $key)if(!Conditions::missing($record[$key]??null))try{(new Normalizer())->date($record[$key]);}catch(ApiError){$this->error($a,'44','DOCUMENT_DATE','Проверьте календарную дату документа.');}
            if(($record['validFrom']??null)!==null&&($record['validUntil']??null)!==null&&$record['validUntil']<$record['validFrom'])$this->error($a,'44','DOCUMENT_DATE','Окончание действия документа раньше начала.');
            if(array_diff($record['goodsIds']??[],array_column($draft['goods'],'id'))||array_diff($record['documentIds']??[],array_keys($state['documents']??[])))$this->error($a,'44','LINK_INVALID','Документ связан с отсутствующим товаром или файлом.');
        }
    }

    public function requireValid(array $state):void {$r=$this->validate($state);if(!$r['valid'])throw new ApiError('DECLARATION_INVALID','Заполните все применимые обязательные исходные графы.',422,$r);}
    private function error(string $address,string $graphId,string $code,string $message):void { $entry=compact('address','graphId','code','message');if(!in_array($entry,$this->errors,true))$this->errors[]=$entry; }
}

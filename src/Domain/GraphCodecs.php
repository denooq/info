<?php
declare(strict_types=1);
namespace Customs\Domain;

use Customs\Http\ApiError;

final class GraphCodecs
{
    public const HINTS = [
        '19'=>'Да; номера контейнеров через запятую. Либо: Нет',
        '20'=>'CPT; ALMATY', '24'=>'010; 00',
        '36'=>'Нет; ОО; ОО; ОО; ОО — наличие льгот, сбор, пошлина, акциз, НДС. При льготе добавьте основание после точки с запятой.',
        '37'=>'40; 00; 000 — процедура, предыдущая процедура, особенность',
        '44'=>'Одна строка на документ: код | номер | дата YYYY-MM-DD | код представления 0–3 | название. Необязательно: | действует с | действует по',
    ];

    public function parse(array $state, string $id, ?string $goodsId, string $text): array
    {
        if ($id === '44') return $this->documents($state,$goodsId,$text);
        if (!isset(self::HINTS[$id])) return $state;
        $parts = array_map('trim',explode(';',$text));
        $fields = match($id) {
            '19'=>['declaration.container','declaration.containerNumbers'],
            '20'=>['declaration.deliveryCode','declaration.deliveryPlace'],
            '24'=>['declaration.transactionCode','declaration.transactionFeature'],
            '36'=>['goods.hasPreference','goods.preferenceFee','goods.preferenceDuty','goods.preferenceExcise','goods.preferenceVat','goods.preferenceBasis'],
            '37'=>['declaration.procedure','goods.previousProcedure','goods.movementFeature'],
        };
        $minimum = match($id) {'19'=>1,'36'=>5,default=>count($fields)};
        if (count($parts)<$minimum || count($parts)>count($fields) || in_array('',array_slice($parts,0,$minimum),true)) throw new ApiError('GRAPH_FORMAT','Формат: '.self::HINTS[$id],422);
        foreach ($fields as $index=>$fieldId) {
            $raw = $parts[$index] ?? '';
            if ($fieldId === 'declaration.procedure') {
                if ($raw !== '40') throw new ApiError('GRAPH_FORMAT','MVP поддерживает только процедуру 40.',422);
                continue;
            }
            $state = $this->set($state,$fieldId,str_starts_with($fieldId,'goods.')?$goodsId:null,$raw);
        }
        if ($id==='19' && $state['draft']['declaration']['container']===false && $state['draft']['declaration']['containerNumbers']!==[]) throw new ApiError('GRAPH_FORMAT','При отсутствии контейнеров номера не указываются.',422);
        if ($id==='36' && $state['draft']['goods']!==[]) {
            $goods=array_column($state['draft']['goods'],null,'id')[$goodsId];
            if ($goods['hasPreference']===true && Conditions::missing($goods['preferenceBasis'])) throw new ApiError('GRAPH_FORMAT','Добавьте основание льготы шестой частью графы 36.',422);
        }
        return $state;
    }

    private function set(array $state, string $id, ?string $entityId, string $raw): array
    {
        $registry = new Registry(); $field = $registry->field($id);
        $value = $raw === '' ? (str_ends_with($field['type'],'-list')?[]:null) : (new Normalizer())->value($field,$raw,null,'');
        foreach ((new Normalizer())->issues($field,$value) as $message) {
            if (str_contains($message,'справочник') && !in_array(Registry::resource('catalogs')['fieldCatalogs'][$id] ?? '',['countries','documentKinds'],true)) continue;
            throw new ApiError('GRAPH_FORMAT',$message,422);
        }
        $model = new Draft($registry); $row =& $model->entity($state['draft'],$field['scope'],$entityId);
        $row[$field['key']] = $value; unset($row);
        $address = Draft::address($field['scope'],$entityId,$field['key']);
        $state['draft']['fieldStates'][$address] = [...DeclarationDate::meta('manual'),'reviewedCandidateIds'=>array_keys(array_filter($state['candidates']??[],fn($c)=>$c['address']===$address))];
        unset($state['draft']['invalidInputs'][$address]);
        return $state;
    }

    public static function documentText(array $records, ?string $goodsId): string
    {
        return implode("\n",array_map(fn($r)=>implode(' | ',[$r['kind'] ?? '',$r['number'] ?? '',$r['date'] ?? '',$r['presentationCode'] ?? '',$r['name'] ?? '',$r['validFrom'] ?? '',$r['validUntil'] ?? '']),array_values(array_filter($records,fn($r)=>$goodsId===null||($r['goodsIds']??[])===[]||in_array($goodsId,$r['goodsIds'],true)))));
    }

    private function documents(array $state, ?string $goodsId, string $text): array
    {
        $catalog = new Classifiers(); $model = new Draft(new Registry()); $old = $state['draft']['documents'];
        $visible = array_values(array_filter($old,fn($r)=>($r['goodsIds']??[])===[]||in_array($goodsId,$r['goodsIds'],true)));
        $lines = trim($text)===''?[]:preg_split('/\r?\n/u',trim($text));
        if (count($lines)>200) throw new ApiError('GRAPH_FORMAT','В графе 44 допускается до 200 строк.',422);
        $parsed = []; $used = [];
        $incomingKeys = [];
        foreach ($lines as $line) {
            $p=array_map('trim',explode('|',$line));
            if(count($p)>=3)$incomingKeys[]=$p[0].'|'.$p[1].'|'.($p[2]===''?'':(new Normalizer())->date($p[2]));
        }
        foreach ($lines as $line) {
            $p = array_map('trim',explode('|',$line));
            if (count($p)<4 || count($p)>7 || !preg_match('/^\d{5}$/D',$p[0]) || $p[1]==='' || !in_array($p[3],['0','1','2','3'],true)) throw new ApiError('GRAPH_FORMAT','Формат: '.self::HINTS['44'],422);
            $official = $catalog->requireDocument($p[0],$catalog->version());
            $date = $p[2]===''?null:(new Normalizer())->date($p[2]); $dateRequired = true;
            foreach(Registry::resource('catalogs')['lists']['documentKinds'] as $kind) if($kind['code']===$p[0])$dateRequired=$kind['dateRequired'];
            if($dateRequired && $date===null)throw new ApiError('GRAPH_FORMAT','Укажите дату документа '.$p[0].'.',422);
            $key=$p[0].'|'.$p[1].'|'.$date;
            if(isset($parsed[$key]))continue;
            $matches = array_values(array_filter($visible,fn($r)=>!isset($used[$r['id']])&&$r['kind']===$p[0]&&$r['number']===$p[1]&&$r['date']===$date));
            // A legal type correction must retain the file links of the same document.
            if ($matches===[]) $matches = array_values(array_filter($visible,fn($r)=>!isset($used[$r['id']])&&$r['number']===$p[1]&&$r['date']===$date));
            if ($matches===[]) $matches = array_values(array_filter($visible,fn($r)=>!isset($used[$r['id']])&&$r['kind']===$p[0]&&!in_array($r['kind'].'|'.$r['number'].'|'.$r['date'],$incomingKeys,true)));
            if (count($matches)>1) throw new ApiError('GRAPH_FORMAT','Неоднозначное изменение нескольких документов одного вида. Сохраните изменение одной строки отдельно.',422);
            $record = $matches[0] ?? ['id'=>'document-'.bin2hex(random_bytes(8)),...$model->row('document'),'goodsIds'=>[$goodsId],'documentIds'=>[]];
            $used[$record['id']]=true;
            $record=array_replace($record,['kind'=>$p[0],'number'=>$p[1],'date'=>$date,'presentationCode'=>$p[3],'name'=>$p[4]??$official['name'],'dateRequired'=>$dateRequired,'classifierVersion'=>$catalog->version()]);
            if($record['name']==='')$record['name']=$official['name'];
            foreach(['validFrom'=>5,'validUntil'=>6] as $field=>$index) if(isset($p[$index]))$record[$field]=$p[$index]===''?null:(new Normalizer())->date($p[$index]);
            if(($record['validFrom']??null)!==null&&($record['validUntil']??null)!==null&&$record['validUntil']<$record['validFrom'])throw new ApiError('GRAPH_FORMAT','Окончание действия документа раньше начала.',422);
            $record['goodsIds']=array_values(array_unique([...($record['goodsIds']??[]),$goodsId]));
            foreach(['kind','number','date','name','presentationCode','validFrom','validUntil'] as $field) {
                $address=Draft::address('document',$record['id'],$field);$previous=$state['draft']['fieldStates'][$address]??[];
                $original=array_column($old,null,'id')[$record['id']]??[];
                $proof=($original[$field]??null)===($record[$field]??null)?($previous['evidenceIds']??[]):[];
                $state['draft']['fieldStates'][$address]=[...DeclarationDate::meta('manual'),'evidenceIds'=>$proof,'reviewedCandidateIds'=>array_keys(array_filter($state['candidates']??[],fn($c)=>$c['address']===$address))];
            }
            $parsed[$key]=$record;
        }
        $records=array_values($parsed);
        foreach($old as $record) {
            if(isset($used[$record['id']]))continue;
            if(in_array($record['id'],array_column($visible,'id'),true)) {
                $record['goodsIds']=array_values(array_diff($record['goodsIds']??[],[$goodsId]));
                if($record['goodsIds']===[])continue;
            }
            $records[]=$record;
        }
        $state['draft']['documents']=$records;
        $state['draft']['declaration']['valuationDocumentIds']=array_values(array_intersect($state['draft']['declaration']['valuationDocumentIds'],array_column($records,'id')));
        foreach(array_keys($state['draft']['graphTexts']??[]) as $address) if(str_starts_with($address,'graph/44/')&&($state['draft']['graphTexts'][$address]['error']??null)===null)$state['draft']['graphTexts'][$address]['value']=self::documentText($records,substr($address,9));
        return $state;
    }

    public function documentChoice(array $state, string $recordId, array $values, ?string $goodsId): array
    {
        foreach($values as $key=>$value) {
            if(!in_array($key,['kind','number','date','name','presentationCode','validFrom','validUntil'],true))continue;
            $state=$this->set($state,'document.'.$key,$recordId,(string)$value);
        }
        foreach(['kind','number','date','name','presentationCode','validFrom','validUntil'] as $key) {
            $address=Draft::address('document',$recordId,$key);
            $state['draft']['fieldStates'][$address]=[...DeclarationDate::meta('manual'),'reviewedCandidateIds'=>array_keys(array_filter($state['candidates']??[],fn($c)=>$c['address']===$address))];
        }
        $model=new Draft(new Registry());$record=&$model->entity($state['draft'],'document',$recordId);
        $official=(new Classifiers())->requireDocument((string)$record['kind'],(new Classifiers())->version());
        if(Conditions::missing($record['name']??null))$record['name']=$official['name'];
        unset($record);
        foreach(array_keys($state['draft']['graphTexts']??[]) as $address)if(str_starts_with($address,'graph/44/')&&($state['draft']['graphTexts'][$address]['error']??null)===null)$state['draft']['graphTexts'][$address]['value']=self::documentText($state['draft']['documents'],substr($address,9));
        return $state;
    }
}

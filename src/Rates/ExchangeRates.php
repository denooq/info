<?php
declare(strict_types=1);
namespace Customs\Rates;

use Customs\Domain\{DeclarationDate,Draft,Registry};
use Customs\Http\ApiError;
use Customs\Storage\SessionStore;

final class ExchangeRates
{
    public function __construct(private readonly SessionStore $store,private readonly NationalBankClient $client) {}

    public static function required(array $draft): array
    {
        $specific=array_map(fn($goods)=>($goods['dutyFormula']??'advalorem')==='advalorem'?null:($goods['dutyCurrency']??null),$draft['goods']);
        $currencies=['USD',$draft['declaration']['invoiceCurrency']??null,...array_column($draft['costs'],'currency'),...$specific,...array_column($draft['rates'],'currency')];
        $result=[];foreach($currencies as $currency)if(is_string($currency) && trim($currency)!=='' && strtoupper(trim($currency))!=='KZT')$result[]=strtoupper(trim($currency));
        $result=array_values(array_unique($result));sort($result,SORT_STRING);return $result;
    }

    public function refresh(string $id,int $revision,bool $force=false): array
    {
        $initial=$this->store->read($id);
        if($initial['dataRevision']!==$revision)throw new ApiError('REVISION_CONFLICT','Данные изменились. Обновите проверку перед загрузкой курсов.',409);
        $timestamp=$this->store->now();$draft=DeclarationDate::defaults($initial['draft'],$timestamp);$date=$draft['declaration']['signatureDate'];
        $url=NationalBankClient::url($date);$lock=fopen($this->store->directory($id).'/scratch/nbk.lock','c+b');
        if(!$lock || !flock($lock,LOCK_EX|LOCK_NB)){if(is_resource($lock))fclose($lock);throw new ApiError('NBK_BUSY','Курсы уже загружаются. Повторите проверку через несколько секунд.',409);}
        try {
            $table=null;$error=null;
            if(!$force && ($initial['_nbkCache']['date']??null)===$date)$table=$initial['_nbkCache'];
            else try{$table=$this->client->fetch($date);}catch(ApiError $failure){$error=['code'=>$failure->errorCode,'message'=>$failure->getMessage()];}
            return $this->store->mutate($id,$revision,function(array $state)use($table,$error,$timestamp,$url,$force):array {
                $state['draft']=DeclarationDate::defaults($state['draft'],$timestamp);
                return $this->apply($state,$table,$error,$url,$force);
            },true,true);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }

    public function apply(array $state,?array $table,?array $error,string $url,bool $replaceManual=false): array
    {
        $draft=&$state['draft'];$date=$draft['declaration']['signatureDate'];$model=new Draft(new Registry());$missing=[];$protected=[];
        if($table!==null && $table['date']!==$date)throw new ApiError('NBK_DATE_MISMATCH','Дата курса не соответствует дате составления.',502);
        foreach(self::required($draft) as $currency) {
            $index=null;
            foreach($draft['rates'] as $i=>$row)if(strtoupper(trim((string)($row['currency']??'')))===$currency){$index=$i;break;}
            if($index===null)foreach($draft['rates'] as $i=>$row)if(($row['currency']??null)===null && ($row['value']??null)===null && ($row['nominal']??null)===null && ($row['source']??null)===null){$index=$i;break;}
            if($index===null){$index=count($draft['rates']);$draft['rates'][]=['id'=>'rate-'.bin2hex(random_bytes(8)),...$model->row('rate')];}
            $row=&$draft['rates'][$index];$prefix='rate/'.$row['id'].'/';$manual=false;
            foreach(['value','nominal','source'] as $key)if(in_array($draft['fieldStates'][$prefix.$key]['status']??'', ['manual','selected'],true))$manual=true;
            if(!$replaceManual && $manual && ($row['date']??null)===$date && ($row['value']??null)!==null && ($row['nominal']??null)!==null){$protected[]=$currency;unset($row);continue;}
            if($table===null && ($row['date']??null)===$date && ($row['value']??null)!==null && ($row['nominal']??null)!==null){unset($row);continue;}
            $quote=$table['rates'][$currency]??null;
            $values=['currency'=>$currency,'value'=>$quote['value']??null,'nominal'=>$quote['nominal']??null,'date'=>$date,'source'=>$quote===null?null:$url,'confirmed'=>$quote!==null];
            $evidence=[];
            if($quote!==null) {
                $proof=['currency'=>$currency,'date'=>$date,'value'=>$quote['value'],'nominal'=>$quote['nominal'],'url'=>$url,'sha256'=>$table['sha256']];$row['_nbk']=$proof;
                $evidenceId='nbk-'.$date.'-'.$currency.'-'.substr($table['sha256'],0,12);
                $state['evidence'][$evidenceId]=['kind'=>'nbk','documentId'=>'nbk-'.$date,'documentName'=>'Национальный Банк Казахстана','quote'=>$date.': '.$quote['value'].' KZT за '.$quote['nominal'].' '.$currency,'url'=>$url,'sourceVersion'=>$table['sha256']];$evidence=[$evidenceId];
            } else {$missing[]=$currency;unset($row['_nbk']);}
            foreach($values as $key=>$value){$row[$key]=$value;$draft['fieldStates'][$prefix.$key]=[...DeclarationDate::meta($quote===null?'missing':'nbk'),'evidenceIds'=>$evidence];}
            unset($row);
        }
        $state['exchangeRates']=['date'=>$date,'url'=>$url,'status'=>$error!==null?'error':($missing===[]?'ready':'incomplete'),'error'=>$error,'missingCurrencies'=>$missing,'protectedManual'=>$protected,'retrievedAt'=>$table['retrievedAt']??null];
        if($table!==null)$state['_nbkCache']=$table;
        return $state;
    }
}

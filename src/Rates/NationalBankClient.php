<?php
declare(strict_types=1);
namespace Customs\Rates;

use Brick\Math\BigDecimal;
use Closure;
use Customs\Http\ApiError;

final class NationalBankClient
{
    public const ENDPOINT='https://nationalbank.kz/rss/get_rates.cfm';
    private const MAX_BYTES=262144;
    public function __construct(private readonly ?Closure $transport=null) {}

    public static function url(string $date): string
    {
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$parsed || $parsed->format('Y-m-d')!==$date) throw new ApiError('NBK_DATE_INVALID','Укажите корректную дату составления.',422);
        return self::ENDPOINT.'?fdate='.$parsed->format('d.m.Y');
    }

    public function fetch(string $date): array
    {
        $url=self::url($date);
        $response=$this->transport===null?$this->request($url):($this->transport)($url);
        if (($response['status']??0)!==200) throw new ApiError('NBK_UNAVAILABLE','НБК не вернул курсы на дату составления. Повторите запрос или укажите курсы вручную.',502);
        $body=$response['body']??'';
        $result=$this->parse($body,$date);
        return [...$result,'url'=>$url,'retrievedAt'=>time(),'sha256'=>hash('sha256',$body)];
    }

    private function request(string $url): array
    {
        $handle=curl_init($url);$body='';$oversized=false;
        curl_setopt_array($handle,[CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/xml, text/xml'],CURLOPT_USERAGENT=>'CustomsDeclarationMVP/1.0',CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$body,&$oversized):int {
            if(strlen($body)+strlen($chunk)>self::MAX_BYTES){$oversized=true;return 0;}
            $body.=$chunk;return strlen($chunk);
        }]);
        try {
            $ok=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);
            if($oversized)throw new ApiError('NBK_RESPONSE_LIMIT','Ответ НБК превышает допустимый размер.',502);
            if($ok===false)throw new ApiError('NBK_UNAVAILABLE','Не удалось получить курсы НБК по HTTPS. Повторите запрос или заполните курсы вручную.',502);
            return ['status'=>$status,'body'=>$body];
        }finally{curl_close($handle);}
    }

    public function parse(string $xml,string $date): array
    {
        $expected=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if(!$expected || $expected->format('Y-m-d')!==$date)throw new ApiError('NBK_DATE_INVALID','Некорректная дата курса.',422);
        if(strlen($xml)>self::MAX_BYTES)throw new ApiError('NBK_RESPONSE_LIMIT','Ответ НБК превышает допустимый размер.',502);
        if(preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i',$xml))throw new ApiError('NBK_XML_INVALID','Ответ НБК содержит запрещённые XML-конструкции.',502);
        $previous=libxml_use_internal_errors(true);
        try {
            $dom=new \DOMDocument();
            if(!$dom->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS|LIBXML_COMPACT) || $dom->doctype!==null || $dom->documentElement?->tagName!=='rates' || $dom->documentElement->namespaceURI)throw new ApiError('NBK_XML_INVALID','НБК вернул некорректный XML курсов.',502);
            $xpath=new \DOMXPath($dom);$dates=$xpath->query('/rates/date');
            if($dates->length!==1 || trim($dates->item(0)->textContent)!==$expected->format('d.m.Y'))throw new ApiError('NBK_DATE_MISMATCH','Дата ответа НБК не совпадает с датой составления. Курсы другого дня не подставлены.',502);
            $items=$xpath->query('/rates/item');
            if($items->length<1 || $items->length>200)throw new ApiError('NBK_XML_INVALID','В ответе НБК отсутствуют корректные записи курсов.',502);
            $rates=[];
            foreach($items as $item) {
                $values=[];
                foreach(['title','description','quant'] as $key) {
                    $nodes=$xpath->query('./'.$key,$item);
                    if($nodes->length!==1)throw new ApiError('NBK_XML_INVALID','В записи курса НБК отсутствует валюта, значение или кратность.',502);
                    $values[$key]=trim($nodes->item(0)->textContent);
                }
                $currency=$values['title'];$value=str_replace(',','.',$values['description']);$nominal=$values['quant'];
                if(!preg_match('/^[A-Z]{3}$/D',$currency) || isset($rates[$currency]) || strlen($value)>40 || !preg_match('/^[0-9]+(?:\.[0-9]+)?$/D',$value) || !preg_match('/^[1-9][0-9]{0,8}$/D',$nominal) || !BigDecimal::of($value)->isPositive())throw new ApiError('NBK_XML_INVALID','НБК вернул недопустимый или повторный курс.',502);
                $rates[$currency]=['value'=>(string)BigDecimal::of($value)->strippedOfTrailingZeros(),'nominal'=>$nominal];
            }
            return ['date'=>$date,'rates'=>$rates];
        }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
}

<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/fixtures.php';

use Customs\Domain\{Calculator,DeclarationDate,Draft,Registry,Validator};
use Customs\Rates\{ExchangeRates,NationalBankClient};

function bankFixture(): string {return file_get_contents(dirname(__DIR__).'/fixtures/nbk-2026-10-28.xml');}
function bankState(array $changes=[]): array {
    $store=sessionFixture();$created=$store->create();
    $state=$store->mutate($created['state']['id'],0,function($s)use($changes){$s=array_replace($s,completeDraftState());$s['draft']['rates']=[];foreach($changes as $key=>$value)$s['draft']['declaration'][$key]=$value;return $s;});
    return [$store,$state];
}

test('new declaration uses the Kazakhstan calendar day and date edits synchronize the calculation date',function():void {
    $old=date_default_timezone_get();date_default_timezone_set('Pacific/Honolulu');
    try {
        $timestamp=(new DateTimeImmutable('2026-11-12T20:05:00+00:00'))->getTimestamp();
        $store=sessionFixture(fn()=>$timestamp);$s=$store->create()['state'];
        same('2026-11-13',$s['draft']['declaration']['signatureDate']);same('2026-11-13',$s['draft']['declaration']['calculationDate']);same('2026-11-13',$s['draft']['goods'][0]['ratesDate']);
        $s=(new Draft(new Registry()))->edit($s,'declaration.signatureDate',null,'2026-10-28');same('2026-10-28',$s['draft']['declaration']['calculationDate']);
        same('2026-10-28',$s['draft']['goods'][0]['ratesDate']);
    }finally{date_default_timezone_set($old);}
});

test('real NBK XML preserves quote date, decimal strings and the published currency nominal',function():void {
    $client=new NationalBankClient();$data=$client->parse(bankFixture(),'2026-10-28');
    same(['value'=>'455.63','nominal'=>'1'],$data['rates']['USD']);same(['value'=>'507.89','nominal'=>'1'],$data['rates']['EUR']);same(['value'=>'34.32','nominal'=>'100'],$data['rates']['KRW']);
    same('3432.00',(new Calculator())->convert('10000','KRW',[['currency'=>'KRW',...$data['rates']['KRW']]]));
    same('https://nationalbank.kz/rss/get_rates.cfm?fdate=13.11.2026',NationalBankClient::url('2026-11-13'));
    errorCode('NBK_DATE_INVALID',fn()=>NationalBankClient::url('2026-02-31'));
});

test('NBK rejects other dates, invalid values, duplicate codes, unsafe XML and oversized responses',function():void {
    $client=new NationalBankClient();
    errorCode('NBK_DATE_MISMATCH',fn()=>$client->parse(bankFixture(),'2026-10-29'));
    $item='<item><title>USD</title><description>455.63</description><quant>1</quant></item>';
    foreach([str_replace('<quant>1</quant>','',$item),str_replace('<quant>1</quant>','<quant>0</quant>',$item),str_replace('455.63','-1',$item),$item.$item] as $items)errorCode('NBK_XML_INVALID',fn()=>$client->parse('<rates><date>28.10.2026</date>'.$items.'</rates>','2026-10-28'));
    errorCode('NBK_XML_INVALID',fn()=>$client->parse('<!DOCTYPE rates [<!ENTITY x SYSTEM "https://example.com/">]><rates>&x;</rates>','2026-10-28'));
    errorCode('NBK_XML_INVALID',fn()=>$client->parse('<html>Unavailable</html>','2026-10-28'));
    errorCode('NBK_RESPONSE_LIMIT',fn()=>$client->parse(str_repeat('x',262145),'2026-10-28'));
});

test('official rates auto-fill every required currency, use the session cache, and preserve manual corrections',function():void {
    [$store,$s]=bankState(['invoiceCurrency'=>'KRW']);$calls=0;
    $client=new NationalBankClient(function($url)use(&$calls){$calls++;truth(str_ends_with($url,'fdate=28.10.2026'));return ['status'=>200,'body'=>bankFixture()];});
    $service=new ExchangeRates($store,$client);$s=$service->refresh($s['id'],$s['dataRevision']);same(1,$calls);
    same(['KRW','USD'],ExchangeRates::required($s['draft']));same(2,count($s['draft']['rates']));
    foreach($s['draft']['rates'] as $r){same(true,$r['confirmed']);same('2026-10-28',$r['date']);}
    $valid=(new Validator(new Registry()))->validate($s);truth($valid['valid'],json_encode($valid['errors'],JSON_UNESCAPED_UNICODE));
    $s=$service->refresh($s['id'],$s['dataRevision']);same(1,$calls); // no repeated HTTPS for a cached date
    $usd=array_values(array_filter($s['draft']['rates'],fn($r)=>$r['currency']==='USD'))[0];$model=new Draft(new Registry());
    $s=$store->mutate($s['id'],$s['dataRevision'],fn($s)=>$model->edit($s,'rate.value',$usd['id'],'460'));
    same(false,array_values(array_filter($s['draft']['rates'],fn($r)=>$r['currency']==='USD'))[0]['confirmed']);
    truth(in_array('RATE_MANUAL_BASIS',array_column((new Validator(new Registry()))->validate($s)['errors'],'code'),true));
    $s=$store->mutate($s['id'],$s['dataRevision'],function($s)use($model,$usd){$s=$model->edit($s,'rate.source',$usd['id'],'Подтверждённое ручное основание');return $model->edit($s,'rate.confirmed',$usd['id'],true);});
    $s=$service->refresh($s['id'],$s['dataRevision']);same(1,$calls);same(['USD'],$s['exchangeRates']['protectedManual']);
    same('460',array_values(array_filter($s['draft']['rates'],fn($r)=>$r['currency']==='USD'))[0]['value']);
    $s=$service->refresh($s['id'],$s['dataRevision'],true);same(2,$calls);same('455.63',array_values(array_filter($s['draft']['rates'],fn($r)=>$r['currency']==='USD'))[0]['value']);
    truth(!isset($store->publicView($s)['_nbkCache']));
});

test('date changes do not use another days quotes and provider failure leaves editable missing values',function():void {
    [$store,$s]=bankState();$client=new NationalBankClient(fn()=>['status'=>200,'body'=>bankFixture()]);$service=new ExchangeRates($store,$client);
    $s=$service->refresh($s['id'],$s['dataRevision']);$model=new Draft(new Registry());
    $s=$store->mutate($s['id'],$s['dataRevision'],fn($s)=>$model->edit($s,'declaration.signatureDate',null,'2026-10-29'));
    $s=$service->refresh($s['id'],$s['dataRevision']);same('error',$s['exchangeRates']['status']);same('NBK_DATE_MISMATCH',$s['exchangeRates']['error']['code']);
    same('2026-10-29',$s['draft']['rates'][0]['date']);same(null,$s['draft']['rates'][0]['value']);same(null,$s['draft']['rates'][0]['nominal']);same(false,$s['draft']['rates'][0]['confirmed']);
    [$store,$s]=bankState(['invoiceCurrency'=>'QQQ']);$service=new ExchangeRates($store,$client);$s=$service->refresh($s['id'],$s['dataRevision']);same(['QQQ'],$s['exchangeRates']['missingCurrencies']);same('incomplete',$s['exchangeRates']['status']);
    [$store,$s]=bankState();$service=new ExchangeRates($store,new NationalBankClient(fn()=>['status'=>503,'body'=>'unavailable']));$s=$service->refresh($s['id'],$s['dataRevision']);
    same('error',$s['exchangeRates']['status']);same('USD',$s['draft']['rates'][0]['currency']);same(null,$s['draft']['rates'][0]['value']);
});

test('rate fetch cannot overwrite an edit made while the external request was running',function():void {
    [$store,$s]=bankState();$id=$s['id'];
    $client=new NationalBankClient(function()use($store,$id){$store->mutate($id,null,function($s){$s['draft']['parties'][0]['name']='Более новая правка';return $s;});return ['status'=>200,'body'=>bankFixture()];});
    errorCode('REVISION_CONFLICT',fn()=>(new ExchangeRates($store,$client))->refresh($id,$s['dataRevision']));
    $fresh=$store->read($id);same('Более новая правка',$fresh['draft']['parties'][0]['name']);same([],$fresh['draft']['rates']);
});

<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/fixtures.php';
$input=json_decode(stream_get_contents(STDIN)?:'{}',true,64,JSON_THROW_ON_ERROR);$state=$input['state']??completeDraftState();
if(!isset($input['state'])) {
    $state['draft']['rates']=[];$state['draft']['declaration']['invoiceCurrency']='KRW';$state['capabilities']=['nbkRates'=>true];
    $state['draft']['fieldStates']['goods/goods-1/ratesDate']['status']='linked';
    $state['createdAt']=time();$state['expiresAt']=time()+7200;$state['csrfToken']='test-only';$state['jobs']=[];$state['limits']=[];$state['error']=null;$state['calculation']=null;
}
foreach($input['changes']??[] as $change)$state=(new Customs\Domain\Draft(new Customs\Domain\Registry()))->edit($state,$change['fieldId'],$change['entityId']??null,$change['value']??null);
if(isset($input['bank'])) {
    $date=$state['draft']['declaration']['signatureDate'];$url=Customs\Rates\NationalBankClient::url($date);
    $config=new Customs\Config(['runtime_dir'=>sys_get_temp_dir().'/customs-bank-ui-'.bin2hex(random_bytes(6))]);
    $service=new Customs\Rates\ExchangeRates(new Customs\Storage\SessionStore($config),new Customs\Rates\NationalBankClient());
    $table=null;$error=null;
    if($input['bank']==='error')$error=['code'=>'NBK_UNAVAILABLE','message'=>'НБК временно недоступен. Курсы можно заполнить вручную.'];
    else {
        $xml=file_get_contents(dirname(__DIR__).'/fixtures/nbk-2026-10-28.xml');
        // Synthetic second-day response for testing automatic reload; not a real quote for 29 October.
        if($date==='2026-10-29')$xml=str_replace(['<date>28.10.2026</date>','455.63','34.32'],['<date>29.10.2026</date>','460','35'],$xml);
        $table=(new Customs\Rates\NationalBankClient(fn()=>['status'=>200,'body'=>$xml]))->fetch($date);
    }
    $state=$service->apply($state,$table,$error,$url,$input['force']??false);
}
if(isset($input['changes']) || isset($input['bank'])){$state['dataRevision']++;$state['calculation']=null;}
$state['validation']=(new Customs\Domain\Validator(new Customs\Domain\Registry()))->validate($state);
if(isset($input['calculate'])){$state['calculation']=(new Customs\Domain\Calculator())->calculate($state);$state['phase']='calculated';}
echo json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

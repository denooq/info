<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/fixtures.php';
$input=json_decode(stream_get_contents(STDIN)?:'{}',true,64,JSON_THROW_ON_ERROR);
$state=$input['state']??completeDraftState();
if(!isset($input['state'])) {
    $state['draft']['declaration']['paymentFactsAvailable']=true;
    $state['csrfToken']='test-only';$state['expiresAt']=time()+7200;$state['createdAt']=time();
    $state['jobs']=[];$state['limits']=[];$state['error']=null;$state['calculation']=null;
    $state['documents']=[['id'=>'test-document','name'=>'invoice.pdf','mime'=>'application/pdf','size'=>1000,'pages'=>1,'status'=>'recognized']];
}
foreach($input['changes']??[] as $change)$state=(new Customs\Domain\Draft(new Customs\Domain\Registry()))->edit($state,$change['fieldId'],$change['entityId']??null,$change['value']??null);
if(isset($input['entity']))$state=(new Customs\Domain\Entities(new Customs\Domain\Registry()))->change($state,$input['entity']);
$state=(new Customs\Domain\TariffDefaults())->apply($state);
if(isset($input['state'])){$state['dataRevision']++;$state['phase']='reviewing';$state['calculation']=$state['confirmed']=$state['artifactSet']=null;}
$state['validation']=(new Customs\Domain\Validator(new Customs\Domain\Registry()))->validate($state);
echo json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

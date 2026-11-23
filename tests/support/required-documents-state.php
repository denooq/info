<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/fixtures.php';
$input=json_decode(stream_get_contents(STDIN)?:'{}',true,64,JSON_THROW_ON_ERROR);
$state=$input['state']??completeDraftState();
if(!isset($input['state'])) {
    $state['draft']['documents']=[];$state['draft']['declaration']['valuationDocumentIds']=[];
    $state['csrfToken']='test-only';$state['createdAt']=time();$state['expiresAt']=time()+7200;
    $state['jobs']=[];$state['limits']=[];$state['error']=null;$state['calculation']=null;
}
foreach($input['changes']??[] as $change)$state=(new Customs\Domain\Draft(new Customs\Domain\Registry()))->edit($state,$change['fieldId'],$change['entityId']??null,$change['value']??null);
if(isset($input['entity']))$state=(new Customs\Domain\Entities(new Customs\Domain\Registry()))->change($state,$input['entity']);
if(isset($input['changes']) || isset($input['entity'])){$state['dataRevision']++;$state['calculation']=null;}
$state['validation']=(new Customs\Domain\Validator(new Customs\Domain\Registry()))->validate($state);
if(isset($input['calculate'])){$state['calculation']=(new Customs\Domain\Calculator())->calculate($state);$state['phase']='calculated';}
echo json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/src/bootstrap.php';
require __DIR__.'/fixtures.php';
$input=json_decode(stream_get_contents(STDIN)?:'{}',true,64,JSON_THROW_ON_ERROR);
$state=$input['state']??completeDraftState(2);
if(!isset($input['state'])) {
    $state['draft']['packages']=[]; $state['draft']['declaration']['shipmentPackageIds']=[];
    foreach($state['draft']['goods'] as &$goods)$goods['packageIds']=[];unset($goods);
    $state['csrfToken']='test-only';$state['expiresAt']=time()+3600;$state['createdAt']=time();
    $state['jobs']=[];$state['limits']=[];$state['error']=null;$state['calculation']=null;
}
if(isset($input['entity'])) {
    $state=(new Customs\Domain\Entities(new Customs\Domain\Registry()))->change($state,$input['entity']);
    $state['dataRevision']++;$state['phase']='reviewing';$state['calculation']=null;
}
$state['validation']=(new Customs\Domain\Validator(new Customs\Domain\Registry()))->validate($state);
if(isset($input['calculate'])) {$state['calculation']=(new Customs\Domain\Calculator())->calculate($state);$state['phase']='calculated';}
echo json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

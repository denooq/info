<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/src/bootstrap.php';
require __DIR__.'/fixtures.php';
$input=json_decode(stream_get_contents(STDIN)?:'{}',true,64,JSON_THROW_ON_ERROR);
$model=new Customs\Domain\Draft(new Customs\Domain\Registry());
$defaults=new Customs\Domain\TariffDefaults();
$state=$input['state']??completeDraftState();
if(!isset($input['state'])) {
    foreach([...Customs\Domain\TariffEngine::OPERANDS,'tariffFromCatalogue','tariffConfirmed','rateBasis','ratesDate'] as $key) {
        $state['draft']['goods'][0][$key]=null;
        unset($state['draft']['fieldStates']['goods/goods-1/'.$key]);
    }
    $state['draft']['goods'][0]['commodityCode']=$input['code']??'2915310000';
    $state['csrfToken']='test-only'; $state['expiresAt']=time()+3600; $state['createdAt']=time();
    $state['jobs']=[]; $state['limits']=[]; $state['error']=null; $state['calculation']=null;
    if(isset($input['specific']))$state['draft']['rates'][]=['id'=>'eur','currency'=>'EUR','value'=>'500','nominal'=>'1','date'=>'2026-10-28','source'=>'Test only','confirmed'=>true];
}
foreach($input['changes']??[] as $change)$state=$model->edit($state,$change['fieldId'],$change['entityId']??null,$change['value']??null);
$state=$defaults->apply($state);
$state['validation']=(new Customs\Domain\Validator(new Customs\Domain\Registry()))->validate($state);
if(isset($input['calculate'])) {$state['calculation']=(new Customs\Domain\Calculator())->calculate($state);$state['phase']='calculated';}
elseif(isset($input['state'])) {$state['dataRevision']++;$state['phase']='reviewing';}
echo json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/fixtures.php';
$store=new Customs\Storage\SessionStore(Customs\Config::load());$created=$store->create();
$state=$store->mutate($created['state']['id'],0,function($s){
    $s=array_replace($s,completeDraftState());
    $address='goods/goods-1/grossMass';$s['draft']['goods'][0]['grossMass']=null;
    foreach(['26792'=>'Экспортная декларация','27860'=>'CMR'] as $value=>$name){
        $id='candidate-'.$value;$ev='evidence-'.$value;
        $s['evidence'][$ev]=['documentId'=>str_repeat('a',32),'documentName'=>$name,'page'=>1,'quote'=>$value.' кг'];
        $s['candidates'][$id]=['id'=>$id,'address'=>$address,'fieldId'=>'goods.grossMass','entityId'=>'goods-1','value'=>(string)$value,'unit'=>'kg','valid'=>true,'issues'=>[],'evidenceIds'=>[$ev]];
    }
    $s['draft']['fieldStates'][$address]=['status'=>'unknown','candidateId'=>null,'evidenceIds'=>[],'conflict'=>true,'needsReview'=>true];
    return $s;
});
echo json_encode(['token'=>$created['token'],'state'=>$store->publicView($state)],JSON_UNESCAPED_UNICODE);

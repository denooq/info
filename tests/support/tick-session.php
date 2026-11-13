<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/src/bootstrap.php';
$token=$argv[1]??'';
if(!preg_match('/^[a-f0-9]{64}$/D',$token))exit(1);
$config=Customs\Config::load();$store=new Customs\Storage\SessionStore($config);
for($i=0;$i<3;$i++){
    (new Customs\AI\Pipeline($config,$store,new Customs\AI\MistralClient($config)))->tick(1,hash('sha256',$token));
    $s=$store->fromToken($token);if($s['phase']==='ready'||$s['error']!==null)break;
}
echo json_encode(['phase'=>$s['phase'],'error'=>$s['error']]);

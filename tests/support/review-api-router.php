<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli-server'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/fixtures.php';
$runtime=getenv('CUSTOMS_TEST_RUNTIME');
if(!$runtime){http_response_code(404);exit;}
$config=new Customs\Config(['runtime_dir'=>$runtime,'session_ttl'=>120,'secure_cookie'=>false]);
$store=new Customs\Storage\SessionStore($config,fn():int=>(int)file_get_contents($runtime.'/clock'));
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try {
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD'];
    if($path==='/fixture/seed' && $method==='POST') {
        $state=$store->fromToken($_COOKIE['customs_session']??null);
        if(!hash_equals($state['csrfToken'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))throw new Customs\Http\ApiError('CSRF_INVALID','Fixture CSRF',403);
        $state=$store->mutate($state['id'],$state['dataRevision'],function($state)use($store){
            $state=array_replace($state,completeDraftState());$id=str_repeat('a',32);$directory=$store->directory($state['id']);
            file_put_contents($directory.'/documents/'.$id.'.pdf','fixture document bytes');
            file_put_contents($directory.'/ocr/source.json','{"fixture":"OCR retained"}');
            $state['documents'][$id]=['id'=>$id,'name'=>'invoice-fixture.pdf','mime'=>'application/pdf','size'=>22,'pages'=>1,'status'=>'recognized','extension'=>'pdf','storageName'=>$id.'.pdf','hash'=>hash('sha256','fixture document bytes')];
            return $state;
        });
        $result=$store->publicView($state);
    } else $result=(new Customs\Http\Application($config,$store))->handle($method,$path);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Customs\Http\ApiError $error){http_response_code($error->status);echo json_encode(['error'=>['code'=>$error->errorCode,'message'=>$error->getMessage(),'details'=>$error->details]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
catch(Throwable $error){http_response_code(500);echo json_encode(['error'=>['code'=>'FIXTURE_FAILURE','message'=>$error->getMessage()]],JSON_THROW_ON_ERROR);}

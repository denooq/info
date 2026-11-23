<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli-server'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/src/bootstrap.php';require __DIR__.'/fixtures.php';
$runtime=getenv('CUSTOMS_TEST_RUNTIME');
if(!$runtime){http_response_code(404);exit;}
$config=new Customs\Config(['runtime_dir'=>$runtime,'session_ttl'=>120,'secure_cookie'=>false]);
$store=new Customs\Storage\SessionStore($config,fn():int=>(int)file_get_contents($runtime.'/clock'));
$assetPath=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($_SERVER['REQUEST_METHOD']==='GET'&&($assetPath==='/'||str_starts_with($assetPath,'/assets-app/'))) {
    $base=realpath(dirname(__DIR__,2).'/public/assets-app');$path=realpath($assetPath==='/'?$base.'/index.html':dirname(__DIR__,2).'/public'.$assetPath);
    if($path===false||!Customs\Config::inside($path,$base)||!is_file($path)){http_response_code(404);exit;}
    header('Content-Type: '.match(pathinfo($path,PATHINFO_EXTENSION)){'js'=>'application/javascript','css'=>'text/css',default=>'text/html'});readfile($path);return;
}
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try {
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD'];
    if($path==='/api/documents'&&$method==='POST')file_put_contents($runtime.'/last-upload.json',json_encode(['groupId'=>$_POST['groupId']??null,'revision'=>$_POST['revision']??null,'name'=>$_FILES['file']['name']??null,'uploaded'=>is_uploaded_file($_FILES['file']['tmp_name']??'')],JSON_THROW_ON_ERROR));
    if($path==='/fixture/seed' && $method==='POST') {
        $state=$store->fromToken($_COOKIE['customs_session']??null);
        if(!hash_equals($state['csrfToken'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))throw new Customs\Http\ApiError('CSRF_INVALID','Fixture CSRF',403);
        $body=json_decode(file_get_contents('php://input'),true)??[];$count=max(1,min(5,(int)($body['goodsCount']??1)));
        $state=$store->mutate($state['id'],$state['dataRevision'],function($state)use($store,$count,$body){
            $state=array_replace($state,completeDraftState($count));$id=str_repeat('a',32);$directory=$store->directory($state['id']);
            file_put_contents($directory.'/documents/'.$id.'.pdf','fixture document bytes');
            file_put_contents($directory.'/ocr/source.json','{"fixture":"OCR retained"}');
            $state['documents'][$id]=['id'=>$id,'name'=>'invoice-fixture.pdf','mime'=>'application/pdf','size'=>22,'pages'=>1,'status'=>'recognized','extension'=>'pdf','storageName'=>$id.'.pdf','hash'=>hash('sha256','fixture document bytes')];
            if(($body['emptyDocuments']??false)===true){$state['draft']['documents']=[];$state['draft']['declaration']['valuationDocumentIds']=[];}
            if(isset($body['recordType'])){$state=(new Customs\Documents\UploadGroups())->change($state,['code'=>$body['recordType'],'version'=>'378-2026-11-23']);$state=(new Customs\Documents\UploadGroups())->assign($state,$id,'kind-'.$body['recordType']);}
            return $state;
        });
        $result=$store->publicView($state);
    } elseif($path==='/fixture/recognition'&&$method==='POST') {
        $state=$store->fromToken($_COOKIE['customs_session']??null);
        if(!hash_equals($state['csrfToken'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))throw new Customs\Http\ApiError('CSRF_INVALID','Fixture CSRF',403);
        $body=json_decode(file_get_contents('php://input'),true)??[];$stage=$body['stage']??'partial';
        if(!in_array($stage,['partial','mapping','mapping-partial','success','partial-error','all-error','mapping-error'],true))throw new Customs\Http\ApiError('FIXTURE_STAGE','Unknown fixture stage',422);
        $terminal=in_array($stage,['success','partial-error','all-error','mapping-error'],true);
        $state=$store->mutate($state['id'],$state['dataRevision'],function($s)use($stage,$terminal,$config,$store){
            $ids=array_keys($s['documents']);$failure=['code'=>'MISTRAL_REQUEST','message'=>'Проверочная конечная ошибка обработки.'];
            foreach($ids as $i=>$id){$status=$stage==='all-error'||(in_array($stage,['partial-error','mapping-partial'],true)&&$i>0)?'error':($stage==='partial'&&$i>0?'recognizing':'recognized');$s['documents'][$id]['status']=$status;$s['documents'][$id]['error']=$status==='error'?$failure:null;}
            foreach($s['jobs'] as &$job)if($job['kind']==='ocr'){$status=$s['documents'][$job['documentId']]['status'];$job['status']=match($status){'error'=>'failed','recognized'=>'done',default=>'running'};$job['error']=$status==='error'?$failure:null;}unset($job);
            if(in_array($stage,['mapping','mapping-partial','success','partial-error','mapping-error'],true))$s['jobs']['fixture-mapping']=['id'=>'fixture-mapping','kind'=>'mapping','documentId'=>null,'fingerprint'=>(new Customs\Jobs\Queue($config,$store))->fingerprint($s,'mapping',null),'status'=>in_array($stage,['mapping','mapping-partial'],true)?'running':($stage==='mapping-error'?'failed':'done'),'error'=>$stage==='mapping-error'?$failure:null];
            $s['phase']=$terminal?'reviewing':'recognizing';$s['error']=in_array($stage,['partial-error','all-error','mapping-error'],true)?$failure:null;return $s;
        },$terminal);$result=$store->publicView($state);
    } elseif($path==='/fixture/candidates'&&$method==='POST') {
        $state=$store->fromToken($_COOKIE['customs_session']??null);if(!hash_equals($state['csrfToken'],$_SERVER['HTTP_X_CSRF_TOKEN']??''))throw new Customs\Http\ApiError('CSRF_INVALID','Fixture CSRF',403);
        $body=json_decode(file_get_contents('php://input'),true)??[];$count=($body['count']??1)===2?2:1;
        $state=$store->mutate($state['id'],$state['dataRevision'],function($s)use($count){
            $address='goods/goods-1/grossMass';foreach([['weight-1','27860','Транспортная накладная',1],['weight-2','26792','Отгрузочный (упаковочный) лист',2]] as $i=>[$id,$value,$name,$rank]) {
                if($i>=$count)continue;
                $s['evidence'][$id]=['documentId'=>str_repeat('a',32),'documentName'=>$name,'page'=>1,'quote'=>'Gross '.$value.' kg'];
                $s['candidates'][$id]=['id'=>$id,'address'=>$address,'fieldId'=>'goods.grossMass','entityId'=>'goods-1','value'=>$value,'unit'=>'kg','valid'=>true,'issues'=>[],'evidenceIds'=>[$id],'rank'=>$rank];
            }
            $s['draft']['goods'][0]['grossMass']=$count===1?'27860':null;
            $s['draft']['fieldStates'][$address]=['status'=>$count===1?'auto':'unknown','candidateId'=>$count===1?'weight-1':null,'evidenceIds'=>$count===1?['weight-1']:[],'conflict'=>$count===2,'needsReview'=>$count===2];return $s;
        });$result=$store->publicView($state);
    } else {
        $bank=new Customs\Rates\NationalBankClient(fn()=>['status'=>200,'body'=>file_get_contents(dirname(__DIR__).'/fixtures/nbk-2026-10-28.xml')]);
        $result=(new Customs\Http\Application($config,$store,$bank))->handle($method,$path);
    }
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(Customs\Http\ApiError $error){http_response_code($error->status);echo json_encode(['error'=>['code'=>$error->errorCode,'message'=>$error->getMessage(),'details'=>$error->details]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
catch(Throwable $error){http_response_code(500);echo json_encode(['error'=>['code'=>'FIXTURE_FAILURE','message'=>$error->getMessage()]],JSON_THROW_ON_ERROR);}

<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/support/attachments.php';
test('terminal OCR failures finish in manual review while successful sources alone enter mapping',function():void {
    foreach([false,true] as $allFailed) {
        $config=new Customs\Config(['runtime_dir'=>sys_get_temp_dir().'/customs-partial-'.bin2hex(random_bytes(6)),'mistral_api_key'=>'test-placeholder']);$store=new Customs\Storage\SessionStore($config);$s=$store->create()['state'];$dir=$store->directory($s['id']);
        $s=$store->mutate($s['id'],0,function($s)use($dir){foreach(['good','bad'] as $name){file_put_contents($dir.'/documents/'.$name.'.pdf',$name);$s['documents'][$name]=['id'=>$name,'name'=>$name.'.pdf','hash'=>hash('sha256',$name),'extension'=>'pdf','storageName'=>$name.'.pdf','mime'=>'application/pdf','pages'=>1,'status'=>'pending','documentKind'=>'04021','classifierVersion'=>'378-2026-11-23'];}return $s;});
        $ocr=0;$mapping=0;
        $client=new Customs\AI\MistralClient($config,function($url,$body)use($allFailed,&$ocr,&$mapping){if(str_ends_with($url,'/ocr')){$ocr++;$name=base64_decode(explode(',',$body['document']['document_url'],2)[1]);if($allFailed||$name==='bad')return ['status'=>400,'body'=>'{}'];return ['status'=>200,'body'=>json_encode(['pages'=>[['index'=>0,'markdown'=>'Invoice goods']]])];}$mapping++;$input=json_decode($body['messages'][1]['content'],true);foreach($input['sources'] as $source){same('good.pdf',$source['document']);same('04021',$source['documentKind']);same('invoice',$source['sourceRole']);}return ['status'=>200,'body'=>json_encode(['choices'=>[['finish_reason'=>'stop','message'=>['content'=>json_encode(['entities'=>[],'candidates'=>[],'classificationQueries'=>[],'warnings'=>[]])]]]])];});
        $pipeline=new Customs\AI\Pipeline($config,$store,$client);$pipeline->start($s['id']);$pipeline->tick();$after=$store->read($s['id']);same('reviewing',$after['phase']);same(2,$ocr);if($allFailed)same(0,$mapping);else truth($mapping>0);
        truth(!array_filter($after['jobs'],fn($j)=>in_array($j['status'],['queued','running'],true)));
    }
});
test('duplicate bytes of the same official type keep two files but provide one mapping source',function():void {
    $config=new Customs\Config(['runtime_dir'=>sys_get_temp_dir().'/customs-duplicate-'.bin2hex(random_bytes(6))]);$store=new Customs\Storage\SessionStore($config);$s=$store->create()['state'];$dir=$store->directory($s['id']);
    foreach(['first','duplicate'] as $id) {
        $s['documents'][$id]=['id'=>$id,'name'=>$id.'.pdf','hash'=>'same-hash','status'=>'recognized','documentKind'=>'04021','classifierVersion'=>'378-2026-11-23'];
        file_put_contents($dir.'/ocr/'.$id.'-0.json',json_encode(['sourceHash'=>'same-hash','ocrModel'=>$config->get('ocr_model'),'result'=>['pages'=>[['page'=>1,'blocks'=>[['id'=>'p1-b0','text'=>'Invoice goods']]]],'sheets'=>[]]],JSON_THROW_ON_ERROR));
    }
    $pipeline=new Customs\AI\Pipeline($config,$store,new Customs\AI\MistralClient($config));$sources=(new ReflectionMethod($pipeline,'sources'))->invoke($pipeline,$s,$dir);
    same(2,count($s['documents']));same(1,count($sources));same('first',$sources[0]['documentId']);
});

test('a repeated start cannot requeue a failed file while another part of the current batch is active',function():void {
    [$config,$store,$uploads,$s,$ids]=attachmentFixture();$pipeline=new Customs\AI\Pipeline($config,$store,new Customs\AI\MistralClient($config));
    $s=$pipeline->start($s['id']);$keys=array_keys($s['jobs']);
    $s=$store->mutate($s['id'],null,function($s)use($keys,$ids){$s['jobs'][$keys[0]]['status']='failed';$s['documents'][$ids[0]]['status']='error';return $s;},false);
    $again=$pipeline->start($s['id'],true);same($s['jobs'],$again['jobs']);same($s['dataRevision'],$again['dataRevision']);
});

test('exhausted interrupted OCR and mapping leases reach terminal review instead of an endless recognizing phase',function():void {
    foreach(['ocr','mapping'] as $kind) {
        [$config,$store,$uploads,$s,$ids]=attachmentFixture();$queue=new Customs\Jobs\Queue($config,$store);
        $s=$store->mutate($s['id'],null,function($s)use($kind){foreach($s['documents'] as &$d)$d['status']=$kind==='mapping'?'recognized':'recognizing';unset($d);return $s;},false);
        if($kind==='ocr')foreach($ids as $id)$queue->enqueue($s['id'],'ocr',$id);else $queue->enqueue($s['id'],'mapping');
        $store->mutate($s['id'],null,function($s)use($config){foreach($s['jobs'] as &$job){$job['status']='running';$job['leaseOwner']='interrupted';$job['attempts']=$config->get('job_attempts');$job['leaseUntil']=0;}unset($job);return $s;},false);
        $client=new Customs\AI\MistralClient($config,fn()=>throw new RuntimeException('Exhausted work must not call a provider'));
        (new Customs\AI\Pipeline($config,$store,$client))->tick(100,$s['id']);$after=$store->read($s['id']);same('reviewing',$after['phase']);
        truth(!array_filter($after['jobs'],fn($j)=>in_array($j['status'],['running','queued'],true)));
        if($kind==='ocr')foreach($after['documents'] as $document)same('error',$document['status']);
    }
});

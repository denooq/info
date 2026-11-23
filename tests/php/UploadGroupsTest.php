<?php
declare(strict_types=1);
test('typed upload groups preserve file identity and OCR while validating full official code and quotas',function():void {
    $config=new Customs\Config(['runtime_dir'=>sys_get_temp_dir().'/customs-groups-'.bin2hex(random_bytes(6)),'max_files'=>2]);$store=new Customs\Storage\SessionStore($config);$groups=new Customs\Documents\UploadGroups();$s=$store->create()['state'];$version=(new Customs\Domain\Classifiers())->version();
    $s=$store->mutate($s['id'],0,fn($s)=>$groups->change($s,['code'=>'02017','version'=>$version]));
    same([], $s['draft']['documents']);same(1,count($s['uploadGroups']));
    $uploads=new Customs\Documents\Uploads($config,$store);$file=dirname(__DIR__,2).'/reference/source-documents/example2/авиа.pdf';
    $s=$uploads->addFromPath($s['id'],$file,'авиа.pdf',$s['dataRevision'],false,'kind-02017');$s=$uploads->addFromPath($s['id'],$file,'авиа2.pdf',$s['dataRevision'],false,'kind-02017');same(2,count($s['documents']));
    $before=$s['documents'];$s=$groups->change($s,['code'=>'02017','version'=>$version]);same($before,$s['documents']);
    errorCode('FILE_COUNT_LIMIT',fn()=>$uploads->addFromPath($s['id'],$file,'third.pdf',$s['dataRevision'],false,'kind-02017'));
    errorCode('DOCUMENT_KIND_UNKNOWN',fn()=>$groups->change($s,['code'=>'CMR','version'=>$version]));
    errorCode('DOCUMENT_KIND_UNAVAILABLE',fn()=>$groups->change($s,['code'=>'07013','version'=>$version]));
    $first=array_key_first($s['documents']);$s['documents'][$first]['status']='recognized';$s['jobs']=['ocr'=>['id'=>'ocr','kind'=>'ocr','documentId'=>$first,'status'=>'done']];
    $s=$groups->change($s,['action'=>'type','id'=>'kind-02017','code'=>'02015','version'=>$version]);same('recognized',$s['documents'][$first]['status']);same($before[$first]['hash'],$s['documents'][$first]['hash']);same($before[$first]['storageName'],$s['documents'][$first]['storageName']);same('done',$s['jobs']['ocr']['status']);
    same('02015',$s['documents'][$first]['documentKind']);same(1,count($s['uploadGroups']));
});
test('legacy session migration preserves TTL, manual evidence and files but removes export navigation',function():void {
    $store=sessionFixture();$s=$store->create()['state'];$fileId=str_repeat('a',32);unset($s['schemaVersion'],$s['profile'],$s['uploadGroups']);$s['registryVersion']='0.1.0';$s['phase']='ready';$s['draft']['goods'][0]['description']='Ручное значение';$s['draft']['fieldStates']['goods/goods-1/description']=['status'=>'manual','evidenceIds'=>['e1']];$s['evidence']['e1']=['documentId'=>$fileId,'quote'=>'Ручное значение'];$s['documents'][$fileId]=['id'=>$fileId,'hash'=>'abc','status'=>'recognized'];$s['artifactSet']=['revision'=>0];$s['jobs']['old']=['kind'=>'generate','status'=>'queued'];
    $m=Customs\Storage\SessionMigration::upgrade($s);same($s['id'],$m['id']);same($s['expiresAt'],$m['expiresAt']);same($s['evidence'],$m['evidence']);same($s['draft'],$m['draft']);same('recognized',$m['documents'][$fileId]['status']);same('reviewing',$m['phase']);same(null,$m['artifactSet']);same('cancelled',$m['jobs']['old']['status']);
    $version=(new Customs\Domain\Classifiers())->version();$groups=new Customs\Documents\UploadGroups();$m=$groups->change($m,['code'=>'04021','version'=>$version]);$m=$groups->assign($m,$fileId,'kind-04021');same($fileId,$m['documents'][$fileId]['id']);same('recognized',$m['documents'][$fileId]['status']);same('Ручное значение',$m['draft']['goods'][0]['description']);
    $m['schemaVersion']=99;errorCode('SESSION_VERSION',fn()=>Customs\Storage\SessionMigration::upgrade($m));
});
test('session reader migrates a persisted legacy JSON and rejects unknown schema without changing TTL',function():void {
    $store=sessionFixture();$s=$store->create()['state'];$path=$store->directory($s['id']).'/state.json';unset($s['schemaVersion']);$s['phase']='generating';
    file_put_contents($path,json_encode($s,JSON_THROW_ON_ERROR));$read=$store->read($s['id']);same(2,$read['schemaVersion']);same('reviewing',$read['phase']);same($s['expiresAt'],$read['expiresAt']);
    $store->mutate($s['id'],0,fn($s)=>$s,false);same(2,json_decode(file_get_contents($path),true)['schemaVersion']);
    $read['schemaVersion']=42;file_put_contents($path,json_encode($read,JSON_THROW_ON_ERROR));errorCode('SESSION_VERSION',fn()=>$store->read($s['id']));
});

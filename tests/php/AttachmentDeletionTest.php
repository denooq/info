<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/support/attachments.php';

test('deleting a populated group reconciles surviving evidence and preserves manual and selected values',function():void {
    [$config,$store,$uploads,$s,$ids]=attachmentFixture();[$a,$b,$c]=$ids;$directory=$store->directory($s['id']);
    foreach($ids as $id)file_put_contents($directory.'/ocr/'.$id.'-1111111111111111.json','{}');
    file_put_contents($directory.'/ocr/'.$a.'-1111111111111111.json.2222222222222222.tmp','interrupted cache');
    file_put_contents($directory.'/ocr/mapping-1111111111111111-0.json','{}');
    $s=$store->mutate($s['id'],$s['dataRevision'],function($s)use($a,$b,$c){
        foreach(['ea'=>$a,'eb'=>$b,'ec'=>$c] as $key=>$id)$s['evidence'][$key]=['documentId'=>$id,'documentKind'=>$id===$c?'02015':'04021','quote'=>'fixture'];
        $s['draft']['goods'][0]=array_replace($s['draft']['goods'][0],['grossMass'=>'27','netMass'=>'25','description'=>'Automatically extracted','sourceLineIds'=>['line-a']]);
        foreach(['mass'=>['grossMass','27',['ea','ec'],[$a,$c]],'selected'=>['netMass','25',['eb'],[$b]],'description'=>['description','Automatically extracted',['ea'],[$a]]] as $key=>[$field,$value,$evidence,$documents]) {
            $address='goods/goods-1/'.$field;
            $s['candidates'][$key]=['id'=>$key,'fieldId'=>'goods.'.$field,'entityId'=>'goods-1','address'=>$address,'value'=>$value,'valid'=>true,'unit'=>null,'evidenceIds'=>$evidence,'documentIds'=>$documents,'rawValues'=>array_map(fn($e)=>['value'=>$value,'evidenceId'=>$e],$evidence),'rank'=>3];
            $s['draft']['fieldStates'][$address]=['status'=>$key==='selected'?'selected':'auto','candidateId'=>$key,'evidenceIds'=>$evidence,'conflict'=>false,'needsReview'=>false];
        }
        $s['draft']['parties'][0]['name']='Manual name';$s['draft']['fieldStates']['party/party-seller/name']=['status'=>'manual','candidateId'=>null,'evidenceIds'=>['ea']];
        $s['draft']['declaration']['sourcePackageCount']=47;$s['draft']['fieldStates']['declaration/_/sourcePackageCount']=['status'=>'auto','evidenceIds'=>['ea']];
        $s['draft']['goods'][0]['commodityCode']='2915310000';$s['draft']['fieldStates']['goods/goods-1/commodityCode']=['status'=>'auto','evidenceIds'=>['ea']];
        $s['draft']['goods'][0]['_tariff']=['code'=>'2915310000'];$s['draft']['goods'][0]['dutyRate']='3';$s['draft']['fieldStates']['goods/goods-1/dutyRate']=['status'=>'reference','evidenceIds'=>[]];
        $s['draft']['documents'][]=['id'=>'record-a','kind'=>'04021','number'=>'Manual invoice','documentIds'=>[$a,$b,$c]];
        $s['draft']['sourceLines']['line-a']=['id'=>'line-a','origin'=>'document','evidenceId'=>'ea'];
        $s['draft']['entityAliases']['document:'.$a.':invoice']='record-a';
        $s['jobs']=['ocr-c'=>['kind'=>'ocr','status'=>'done','documentId'=>$c],'mapping'=>['kind'=>'mapping','status'=>'done','documentId'=>null]];
        $s['mappingProgress']=['old'=>3];$s['calculation']=['fixture'=>'stale'];return $s;
    },false);
    $before=$s['dataRevision'];$s=$uploads->changeGroup($s['id'],['action'=>'remove','id'=>'kind-04021'],$before);
    same($before+1,$s['dataRevision']);same([$c],array_keys($s['documents']));same(1,count($s['uploadGroups']));
    same(['ec'],$s['candidates']['mass']['evidenceIds']);same([$c],$s['candidates']['mass']['documentIds']);same(1,$s['candidates']['mass']['rank']);same('27',$s['draft']['goods'][0]['grossMass']);
    same(null,$s['draft']['goods'][0]['description']);same('25',$s['draft']['goods'][0]['netMass']);same('manual',$s['draft']['fieldStates']['goods/goods-1/netMass']['status']);same([],$s['draft']['fieldStates']['goods/goods-1/netMass']['evidenceIds']);
    same('Manual name',$s['draft']['parties'][0]['name']);same('Manual invoice',$s['draft']['documents'][0]['number']);same([$c],$s['draft']['documents'][0]['documentIds']);
    same([],$s['draft']['sourceLines']);same([],$s['draft']['goods'][0]['sourceLineIds']);same([],$s['draft']['entityAliases']);same(['ocr-c'],array_keys($s['jobs']));truth(!isset($s['mappingProgress']));same(null,$s['calculation']);
    same(null,$s['draft']['packages'][0]['quantity']);same([],$s['draft']['packages'][0]['evidenceIds']);same(null,$s['draft']['goods'][0]['packageQuantity']);same(null,$s['draft']['goods'][0]['commodityCode']);same(null,$s['draft']['goods'][0]['dutyRate']);truth(!isset($s['draft']['goods'][0]['_tariff']));
    foreach([$a,$b] as $id){truth(!is_file($directory.'/documents/'.$id.'.pdf'));truth(!is_file($directory.'/ocr/'.$id.'-1111111111111111.json'));}
    truth(!is_file($directory.'/ocr/'.$a.'-1111111111111111.json.2222222222222222.tmp'));
    truth(!is_file($directory.'/ocr/mapping-1111111111111111-0.json'));truth(is_file($directory.'/documents/'.$c.'.pdf'));truth(is_file($directory.'/ocr/'.$c.'-1111111111111111.json'));truth(!isset($s['pendingFileCleanup']));
});

test('attachment mutations reject active work, stale revisions and foreign identifiers without losing files',function():void {
    [$config,$store,$uploads,$s,$ids]=attachmentFixture();$version=(new Customs\Domain\Classifiers())->version();
    errorCode('REVISION_CONFLICT',fn()=>$uploads->changeGroup($s['id'],['action'=>'remove','id'=>'kind-04021'],$s['dataRevision']-1));
    $foreign=$store->create()['state'];errorCode('DOCUMENT_NOT_FOUND',fn()=>$uploads->remove($foreign['id'],$ids[0],$foreign['dataRevision']));errorCode('UPLOAD_GROUP_UNKNOWN',fn()=>$uploads->changeGroup($foreign['id'],['action'=>'remove','id'=>'kind-04021'],$foreign['dataRevision']));
    foreach(['ocr','mapping'] as $kind) {
        $s=$store->mutate($s['id'],null,function($s)use($kind,$ids){$s['phase']='recognizing';$s['jobs']=['active'=>['kind'=>$kind,'status'=>'queued','documentId'=>$ids[0]]];return $s;},false);
        errorCode('EXTRACTION_ACTIVE',fn()=>$uploads->remove($s['id'],$ids[0],$s['dataRevision']));
        errorCode('EXTRACTION_ACTIVE',fn()=>$uploads->changeGroup($s['id'],['action'=>'remove','id'=>'kind-04021'],$s['dataRevision']));
        errorCode('EXTRACTION_ACTIVE',fn()=>$uploads->changeGroup($s['id'],['code'=>'04131','version'=>$version],$s['dataRevision']));
        errorCode('EXTRACTION_ACTIVE',fn()=>(new Customs\Documents\UploadGroups())->assign($s,$ids[0],'kind-02015'));
        errorCode('EXTRACTION_ACTIVE',fn()=>$uploads->addFromPath($s['id'],dirname(__DIR__,2).'/reference/source-documents/example2/авиа.pdf','new.pdf',$s['dataRevision'],false,'kind-04021'));
        same(3,count($store->read($s['id'])['documents']));
    }
});

test('pending attachment cleanup retries safely and cannot remove a path outside its session',function():void {
    [$config,$store,$uploads,$s,$ids]=attachmentFixture();$directory=$store->directory($s['id']);$relative='ocr/'.$ids[0].'-1111111111111111.json';mkdir($directory.'/'.$relative);
    $sentinel=$config->get('runtime_dir').'/sentinel';file_put_contents($sentinel,'keep');
    $store->mutate($s['id'],null,function($s)use($relative){$s['pendingFileCleanup']=[$relative,'../../sentinel'];return $s;},false);
    $result=$store->cleanupDeletedFiles($s['id']);same(2,$result['errors']);same('keep',file_get_contents($sentinel));same(2,count($store->read($s['id'])['pendingFileCleanup']));
    rmdir($directory.'/'.$relative);file_put_contents($directory.'/'.$relative,'retry');
    $store->mutate($s['id'],null,function($s)use($relative){$s['pendingFileCleanup']=[$relative];return $s;},false);
    same(['removed'=>1,'errors'=>0],$store->cleanupDeletedFiles($s['id']));truth(!is_file($directory.'/'.$relative));same('keep',file_get_contents($sentinel));
});

test('deleting every scan from a reviewed manual draft does not require another upload to reopen its graphs',function():void {
    [$config,$store,$uploads,$s,$ids]=attachmentFixture();
    $s=$store->mutate($s['id'],null,function($s){$s['phase']='reviewing';foreach($s['documents'] as &$d)$d['status']='recognized';unset($d);$s['draft']['goods'][0]['description']='Manual surviving description';$s['draft']['fieldStates']['goods/goods-1/description']=['status'=>'manual','evidenceIds'=>[]];return $s;},false);
    foreach(['kind-04021','kind-02015'] as $group)$s=$uploads->changeGroup($s['id'],['action'=>'remove','id'=>$group],$s['dataRevision']);
    same([],$s['documents']);same('reviewing',$s['phase']);same('Manual surviving description',$s['draft']['goods'][0]['description']);same(57,count($store->publicView($s)['graphProjection']['graphs']));
});

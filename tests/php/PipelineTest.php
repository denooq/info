<?php
declare(strict_types=1);

use Customs\AI\MistralClient;
use Customs\AI\Pipeline;
use Customs\Config;
use Customs\Documents\Uploads;
use Customs\Domain\Draft;
use Customs\Domain\Registry;
use Customs\Storage\SessionStore;

test('mapping plan sends the entire manifest as one logical bundle', function (): void {
    $config = new Config(); $store = new SessionStore($config);
    $pipeline = new Pipeline($config, $store, new MistralClient($config));
    $permit = ['documentId' => 'permit', 'name' => 'permit.pdf', 'allowsNewGoods' => false, 'pages' => [['page' => 2, 'blocks' => [['id' => 'p2-b0', 'text' => 'Продукт 1 2915310000']]]], 'sheets' => []];
    $invoice = ['documentId' => 'invoice', 'name' => 'invoice.pdf', 'allowsNewGoods' => true, 'pages' => [['page' => 1, 'blocks' => [['id' => 'p1-b0', 'text' => 'Invoice. ETHYL ACETATE. Gross 27860 kg']]]], 'sheets' => []];
    $manifest = (new Customs\AI\SourceManifest())->build([$permit, $invoice], 'session-bundle');
    $plan = (new ReflectionMethod($pipeline, 'plan'))->invoke($pipeline, $manifest);
    same(1, count($plan));
    truth($plan[0]['completeBundle']);
    same(2, count($manifest['sources']));
    same(false, (new Customs\AI\SourceIndex($manifest['sources']))->supportsNewGoods('permit'));
});

test('pipeline serves bounded AI context requests and accumulates token usage across windows', function (): void {
    $config = new Config(['runtime_dir' => sys_get_temp_dir() . '/customs-context-' . bin2hex(random_bytes(6)), 'mistral_api_key' => 'test-placeholder', 'mapping_max_repairs' => 0]);
    $store = new SessionStore($config); $id = $store->create()['state']['id'];
    $typed = $store->mutate($id, 0, fn($s) => (new Customs\Documents\UploadGroups())->change($s, ['code' => '04021', 'version' => '378-2026-11-23']));
    (new Uploads($config, $store))->addFromPath($id, dirname(__DIR__, 2) . '/reference/source-documents/example2/авиа.pdf', 'invoice.pdf', $typed['dataRevision'], false, 'kind-04021');
    $mappingCalls = 0;
    $client = new MistralClient($config, function (string $url, array $body) use (&$mappingCalls): array {
        if (str_ends_with($url, '/ocr')) return ['status' => 200, 'body' => json_encode(['pages' => [['index' => 0, 'markdown' => 'Invoice ETHYL ACETATE']]])];
        $mappingCalls++; $input = json_decode($body['messages'][1]['content'], true, 256, JSON_THROW_ON_ERROR);
        $mapping = ['schemaVersion' => 'graph-mapping-v2', 'items' => [], 'results' => [], 'documentRecords' => [], 'dtsFacts' => [], 'referenceSelections' => [], 'catalogRequests' => [], 'contextRequests' => [], 'warnings' => []];
        if ($mappingCalls === 1) {
            $mapping['contextRequests'][] = ['graphId' => '15', 'itemKey' => null, 'sourceIds' => [$input['sources'][0]['sourceId']], 'reason' => 'Проверьте источник повторно.'];
        } else {
            truth($input['contextRequests'] !== []);
            truth(in_array($input['sources'][0]['sourceId'], $input['contextRequests'][0]['sourceIds'], true));
        }
        return ['status' => 200, 'body' => json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($mapping)]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 4]])];
    });
    $pipeline = new Pipeline($config, $store, $client); $pipeline->start($id); $pipeline->tick(100);
    $after = $store->read($id);
    same('reviewing', $after['phase']); same(2, $mappingCalls); same(2, $after['graphMappingCalls']);
    same(['prompt_tokens' => 20, 'completion_tokens' => 8], $after['graphMappingUsage']);
    same(1, $after['mappingContextRequestsServed']); same([], $after['mappingContextRequests'] ?? []);
});
test('mapping bundle keeps manifest languages, table cells and stable coordinates', function (): void {
    $config = new Config(); $pipeline = new Pipeline($config, sessionFixture(), new MistralClient($config));
    $sourceId = 'src_' . str_repeat('e', 32);
    $source = ['documentId'=>'invoice','name'=>'invoice.pdf','contentHash'=>'invoice-hash','allowsNewGoods'=>true,'pages'=>[['page'=>1,'language'=>'en','blocks'=>[['id'=>'p1-t0','sourceId'=>$sourceId,'text'=>'Goods | Quantity','tableId'=>'t1']],'tables'=>[['id'=>'t1','blockId'=>'p1-t0','sourceId'=>'src_'.str_repeat('f',32),'rows'=>[['row'=>1,'sourceId'=>'src_'.str_repeat('1',32),'cells'=>[['column'=>1,'sourceId'=>'src_'.str_repeat('2',32),'text'=>'Goods'],['column'=>2,'sourceId'=>'src_'.str_repeat('3',32),'text'=>'Quantity']]]]]]]],'sheets'=>[]];
    $manifest = (new Customs\AI\SourceManifest())->build([$source], 'session-table');
    $plan = (new ReflectionMethod($pipeline, 'plan'))->invoke($pipeline, $manifest);
    same(1, count($plan));
    $bundle = $manifest['sources'][0];
    same('en', $bundle['pages'][0]['language']);
    same('Quantity', $bundle['pages'][0]['tables'][0]['rows'][0]['cells'][1]['text']);
    same('t1', $bundle['pages'][0]['tables'][0]['id']);
    truth(in_array($bundle['pages'][0]['tables'][0]['rows'][0]['cells'][1]['sourceId'], $plan[0]['sourceIds'], true));
});

test('the OCR pipeline sends all graph targets once and persists the structured mapping result', function (): void {
    $config = new Config(['runtime_dir' => sys_get_temp_dir() . '/customs-tnved-pipeline-' . bin2hex(random_bytes(6)), 'mistral_api_key' => 'test-placeholder', 'mapping_max_repairs' => 0]);
    $store = new SessionStore($config); $session = $store->create(); $id = $session['state']['id'];
    $typed=$store->mutate($id,0,fn($s)=>(new Customs\Documents\UploadGroups())->change($s,['code'=>'04021','version'=>'378-2026-11-23']));
    (new Uploads($config, $store))->addFromPath($id, dirname(__DIR__, 2) . '/reference/source-documents/example2/авиа.pdf', 'invoice.pdf', $typed['dataRevision'],false,'kind-04021');
    $ocrCalls = 0; $mappingCalls = 0;
    $client = new MistralClient($config, function (string $url, array $body) use (&$ocrCalls, &$mappingCalls): array {
        if (str_ends_with($url, '/ocr')) { $ocrCalls++; return ['status' => 200, 'body' => json_encode(['pages' => [['index' => 0, 'markdown' => 'Invoice ETHYL ACETATE']]])]; }
        $mappingCalls++;
        $input = json_decode($body['messages'][1]['content'], true);
        same(57, count($input['registry']['positions']));
        same(1, count($input['sources']));
        $source = ['sourceId' => $input['sources'][0]['sourceId'], 'quote' => 'ETHYL ACETATE'];
        $payload = ['schemaVersion' => 'graph-mapping-v2', 'items' => [['itemKey' => 'goods-1', 'description' => 'ETHYL ACETATE', 'evidence' => [$source]]], 'results' => [], 'documentRecords' => [], 'dtsFacts' => [], 'referenceSelections' => [], 'catalogRequests' => [], 'contextRequests' => [], 'warnings' => []];
        return ['status' => 200, 'body' => json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($payload)]]]])];
    });
    $pipeline = new Pipeline($config, $store, $client); $pipeline->start($id); $pipeline->tick();
    $state = $store->read($id);
    same('reviewing', $state['phase']);
    same('ETHYL ACETATE', $state['graphMapping']['items'][0]['description']);
    same(1, $ocrCalls); same(1, $mappingCalls);
    same('customs-graph-mapping-v2.1.0', $state['graphMappingPromptVersion']);
    same(true,$state['graphMappingRunComplete']);
    same(57,count(array_unique(array_column($state['graphMappingResults']['targets'],'graphId'))));
});

test('parallel OCR checkpoints survive a terminal failure and retry reuses successful OCR', function (): void {
    $config = new Config(['runtime_dir' => sys_get_temp_dir() . '/customs-test-' . bin2hex(random_bytes(8)), 'mistral_api_key' => 'test-placeholder', 'mapping_max_repairs' => 0]);
    $store = new SessionStore($config); $session = $store->create(); $id = $session['state']['id'];
    $directory = $store->directory($id);
    $documents = [];
    foreach (['first', 'second'] as $name) {
        file_put_contents($directory . '/documents/' . $name . '.pdf', $name);
        $documents[$name] = ['id' => $name, 'name' => $name . '.pdf', 'hash' => hash('sha256', $name), 'storageName' => $name . '.pdf', 'extension' => 'pdf', 'mime' => 'application/pdf', 'pages' => 1, 'status' => 'pending'];
    }
    $store->mutate($id, null, function (array $state) use ($documents): array { $state['documents'] = $documents; return $state; });
    $calls = ['first' => 0, 'second' => 0]; $mappingCalls = 0;
    $client = new MistralClient($config, function (string $url, array $body) use ($store, $id, &$calls, &$mappingCalls): array {
        if (str_ends_with($url, '/ocr')) {
            $name = base64_decode(explode(',', $body['document']['document_url'], 2)[1]);
            $calls[$name]++;
            if ($name === 'first') same(2, count(array_filter($store->read($id)['jobs'], fn($job) => $job['status'] === 'running')));
            if ($name === 'second' && $calls[$name] === 1) {
                same('recognized', $store->read($id)['documents']['first']['status']);
                same(0, $mappingCalls);
                return ['status' => 400, 'body' => '{}'];
            }
            return ['status' => 200, 'body' => json_encode(['pages' => [['index' => 0, 'markdown' => $name]]])];
        }
        $mappingCalls++;
        return ['status' => 200, 'body' => json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode(['schemaVersion' => 'graph-mapping-v2', 'items' => [], 'results' => [], 'documentRecords' => [], 'dtsFacts' => [], 'referenceSelections' => [], 'catalogRequests' => [], 'contextRequests' => [], 'warnings' => []])]]]])];
    });
    $pipeline = new Pipeline($config, $store, $client);
    $pipeline->start($id); $pipeline->tick();
    same(['first' => 1, 'second' => 1], $calls);
    truth($mappingCalls > 0);
    same('reviewing',$store->read($id)['phase']);
    same('error', $store->read($id)['documents']['second']['status']);
    $pipeline->start($id, true); $pipeline->tick();
    same(['first' => 1, 'second' => 2], $calls);
    truth($mappingCalls > 0);
    same('reviewing', $store->read($id)['phase']);
    same(2, count(glob($directory . '/ocr/first-*.json')) + count(glob($directory . '/ocr/second-*.json')));
});

test('graph remapping preserves manual draft changes and reuses cached OCR', function (): void {
    $config=new Config(['runtime_dir'=>sys_get_temp_dir().'/customs-test-'.bin2hex(random_bytes(8)),'mistral_api_key'=>'test-placeholder','mapping_max_repairs'=>0]);
    $store=new SessionStore($config); $session=$store->create(); $id=$session['state']['id'];
    $typed=$store->mutate($id,0,fn($s)=>(new Customs\Documents\UploadGroups())->change($s,['code'=>'04021','version'=>'378-2026-11-23']));
    $state=(new Uploads($config,$store))->addFromPath($id,dirname(__DIR__,2).'/reference/source-documents/example2/авиа.pdf','test.pdf',$typed['dataRevision'],false,'kind-04021');
    $ocrCalls=0; $mappingCalls=0;
    $client=new MistralClient($config,function(string $url,array $body)use(&$ocrCalls,&$mappingCalls):array{
        if(str_ends_with($url,'/ocr')) {
            $ocrCalls++; return ['status'=>200,'body'=>json_encode(['pages'=>[['index'=>0,'markdown'=>'ETHYL ACETATE gross 27860 kg']],'model'=>'mistral-ocr-4-1'])];
        }
        $mappingCalls++; $payload=['schemaVersion'=>'graph-mapping-v2','items'=>[],'results'=>[],'documentRecords'=>[],'dtsFacts'=>[],'referenceSelections'=>[],'catalogRequests'=>[],'contextRequests'=>[],'warnings'=>[]];
        return ['status'=>200,'body'=>json_encode(['choices'=>[['finish_reason'=>'stop','message'=>['content'=>json_encode($payload)]]]])];
    });
    $pipeline=new Pipeline($config,$store,$client); $pipeline->start($id); $pipeline->start($id);
    for($i=0;$i<12 && $store->read($id)['phase']!=='reviewing';$i++)$pipeline->tick();
    $done=$store->read($id); same('reviewing',$done['phase']); same('graph-mapping-v2',$done['graphMapping']['schemaVersion']); same(1,$ocrCalls); truth($mappingCalls>0);
    $entityId=$done['draft']['goods'][0]['id'];
    $store->mutate($id,$done['dataRevision'],fn(array $s):array=>(new Draft(new Registry()))->edit($s,'goods.grossMass',$entityId,'28000'));
    $pipeline->start($id); $pipeline->tick();
    same(1,$ocrCalls); same('28000',$store->read($id)['draft']['goods'][0]['grossMass']);
});

test('legacy graph mapping waits for explicit retry and then reuses the saved OCR', function (): void {
    $config=new Config(['runtime_dir'=>sys_get_temp_dir().'/customs-legacy-mapping-'.bin2hex(random_bytes(8)),'mistral_api_key'=>'test-placeholder','mapping_max_repairs'=>0]);
    $store=new SessionStore($config);$created=$store->create();$id=$created['state']['id'];
    $typed=$store->mutate($id,0,fn($s)=>(new Customs\Documents\UploadGroups())->change($s,['code'=>'04021','version'=>'378-2026-11-23']));
    (new Uploads($config,$store))->addFromPath($id,dirname(__DIR__,2).'/reference/source-documents/example2/авиа.pdf','invoice.pdf',$typed['dataRevision'],false,'kind-04021');
    $ocrCalls=0;$mappingCalls=0;
    $client=new MistralClient($config,function(string $url,array $body)use(&$ocrCalls,&$mappingCalls):array{
        if(str_ends_with($url,'/ocr')){$ocrCalls++;return ['status'=>200,'body'=>json_encode(['pages'=>[['index'=>0,'markdown'=>'Invoice ETHYL ACETATE']]])];}
        $mappingCalls++;$payload=['schemaVersion'=>'graph-mapping-v2','items'=>[],'results'=>[],'documentRecords'=>[],'dtsFacts'=>[],'referenceSelections'=>[],'catalogRequests'=>[],'contextRequests'=>[],'warnings'=>[]];
        return ['status'=>200,'body'=>json_encode(['choices'=>[['finish_reason'=>'stop','message'=>['content'=>json_encode($payload)]]]])];
    });
    $pipeline=new Pipeline($config,$store,$client);$pipeline->start($id);for($i=0;$i<12&&($ocrCalls===0||$mappingCalls===0);$i++)$pipeline->tick();truth($ocrCalls===1,'initial OCR count: '.$ocrCalls);truth($mappingCalls===1,'initial mapping count: '.$mappingCalls);
    $state=$store->read($id);$goodsId=$state['draft']['goods'][0]['id'];
    $manual=(new Draft(new Customs\Domain\Registry()))->edit($state,'goods.grossMass',$goodsId,'28000');
    $store->mutate($id,$state['dataRevision'],function(array $s)use($manual):array{$s['draft']=$manual['draft'];$s['mappingNeedsExplicitRetry']=true;$s['graphMappingFingerprint']='legacy-mapping-fingerprint';$s['graphMapping']=['schemaVersion'=>'old'];$s['graphMappingResults']=['schemaVersion'=>'old'];$s['mappingProgress']=[];foreach($s['jobs'] as $jobId=>$job)if($job['kind']==='mapping')unset($s['jobs'][$jobId]);return $s;});
    foreach(glob($store->directory($id).'/ocr/mapping-*.json')?:[] as $cachedMapping)unlink($cachedMapping);
    $ocrCalls=0;$mappingCalls=0;
    $pipeline->start($id);$pipeline->tick();same(0,$ocrCalls);same(0,$mappingCalls);truth($store->read($id)['mappingNeedsExplicitRetry']);
    $pipeline->start($id,true);same(0,$ocrCalls);same(0,$mappingCalls);$after=$store->read($id);
    truth(!isset($after['mappingNeedsExplicitRetry']));same('28000',$after['draft']['goods'][0]['grossMass']);$documentId=array_key_first($after['documents']);truth((bool)glob($store->directory($id).'/ocr/'.$documentId.'-*.json'));
    truth((bool)array_filter($after['jobs'],fn($job)=>$job['kind']==='mapping'&&$job['status']==='queued'),'explicit retry queues mapping from the saved OCR');
});

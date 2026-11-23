<?php
declare(strict_types=1);

use Customs\AI\MistralClient;
use Customs\AI\Pipeline;
use Customs\Config;
use Customs\Documents\Uploads;
use Customs\Domain\Draft;
use Customs\Domain\Registry;
use Customs\Storage\SessionStore;

test('mapping establishes shipment goods before permits and preserves document role across chunks', function (): void {
    $config = new Config(); $store = new SessionStore($config);
    $pipeline = new Pipeline($config, $store, new MistralClient($config));
    $permit = ['documentId' => 'permit', 'name' => 'permit.pdf', 'allowsNewGoods' => false, 'pages' => [['page' => 2, 'blocks' => [['id' => 'p2-b0', 'text' => 'Продукт 1 2915310000']]]], 'sheets' => []];
    $invoice = ['documentId' => 'invoice', 'name' => 'invoice.pdf', 'allowsNewGoods' => true, 'pages' => [['page' => 1, 'blocks' => [['id' => 'p1-b0', 'text' => 'Invoice. ETHYL ACETATE. Gross 27860 kg']]]], 'sheets' => []];
    $plan = (new ReflectionMethod($pipeline, 'plan'))->invoke($pipeline, [$permit, $invoice]);
    same('invoice', $plan[0]['sources'][0]['documentId']);
    same('permit', $plan[count($plan) - 1]['sources'][0]['documentId']);
    same(false, (new Customs\AI\SourceIndex($plan[count($plan) - 1]['sources']))->supportsNewGoods('permit'));
});

test('the existing OCR and mapping pipeline fills TN VED from a cited English name without an extra model request', function (): void {
    $config = new Config(['runtime_dir' => sys_get_temp_dir() . '/customs-tnved-pipeline-' . bin2hex(random_bytes(6)), 'mistral_api_key' => 'test-placeholder']);
    $store = new SessionStore($config); $session = $store->create(); $id = $session['state']['id'];
    $typed=$store->mutate($id,0,fn($s)=>(new Customs\Documents\UploadGroups())->change($s,['code'=>'04021','version'=>'378-2026-11-23']));
    (new Uploads($config, $store))->addFromPath($id, dirname(__DIR__, 2) . '/reference/source-documents/example2/авиа.pdf', 'invoice.pdf', $typed['dataRevision'],false,'kind-04021');
    $ocrCalls = 0; $mappingCalls = 0;
    $client = new MistralClient($config, function (string $url, array $body) use (&$ocrCalls, &$mappingCalls): array {
        if (str_ends_with($url, '/ocr')) { $ocrCalls++; return ['status' => 200, 'body' => json_encode(['pages' => [['index' => 0, 'markdown' => 'Invoice ETHYL ACETATE']]])]; }
        $mappingCalls++;
        $input = json_decode($body['messages'][1]['content'], true);
        $payload = ['entities' => [], 'candidates' => [], 'classificationQueries' => [], 'warnings' => []];
        if (in_array('goods.description', array_column($input['fields'], 'id'), true)) {
            $source = ['sourceId' => $input['sources'][0]['sourceId'], 'quote' => 'ETHYL ACETATE'];
            $payload['entities'][] = ['scope' => 'goods', 'key' => 'ethyl', 'role' => null, 'source' => $source];
            $payload['candidates'][] = ['fieldId' => 'goods.description', 'entityKey' => 'ethyl', 'rawValue' => 'ETHYL ACETATE', 'unit' => null, 'source' => $source];
            $payload['classificationQueries'][] = ['entityKey' => 'ethyl', 'name' => 'ETHYL ACETATE', 'queryRu' => 'этилацетат', 'source' => $source];
        }
        return ['status' => 200, 'body' => json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($payload)]]]])];
    });
    $pipeline = new Pipeline($config, $store, $client); $pipeline->start($id); $pipeline->tick();
    $state = $store->read($id);
    same('reviewing', $state['phase']); same('2915310000', $state['draft']['goods'][0]['commodityCode']);
    same('5', $state['draft']['goods'][0]['dutyRate']); same('advalorem', $state['draft']['goods'][0]['dutyFormula']);
    same(false, $state['draft']['goods'][0]['tariffConfirmed']); same(false, $state['draft']['goods'][0]['additionalUnitRequired']);
    same(1, $ocrCalls); same(3, $mappingCalls);
    same('reference', $state['draft']['fieldStates']['goods/goods-1/commodityCode']['status']);
});

test('parallel OCR checkpoints survive a terminal failure and retry reuses successful OCR', function (): void {
    $config = new Config(['runtime_dir' => sys_get_temp_dir() . '/customs-test-' . bin2hex(random_bytes(8)), 'mistral_api_key' => 'test-placeholder']);
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
        return ['status' => 200, 'body' => json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode(['entities' => [], 'candidates' => [], 'classificationQueries' => [], 'warnings' => []])]]]])];
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

test('complete extraction pipeline maps from OCR, preserves manual changes and never reruns cached OCR on remap', function (): void {
    $config=new Config(['runtime_dir'=>sys_get_temp_dir().'/customs-test-'.bin2hex(random_bytes(8)),'mistral_api_key'=>'test-placeholder']);
    $store=new SessionStore($config); $session=$store->create(); $id=$session['state']['id'];
    $typed=$store->mutate($id,0,fn($s)=>(new Customs\Documents\UploadGroups())->change($s,['code'=>'04021','version'=>'378-2026-11-23']));
    $state=(new Uploads($config,$store))->addFromPath($id,dirname(__DIR__,2).'/reference/source-documents/example2/авиа.pdf','test.pdf',$typed['dataRevision'],false,'kind-04021');
    $documentId=array_key_first($state['documents']); $ocrCalls=0; $mappingCalls=0;
    $client=new MistralClient($config,function(string $url,array $body)use(&$ocrCalls,&$mappingCalls,$documentId):array{
        if(str_ends_with($url,'/ocr')) {
            $ocrCalls++; return ['status'=>200,'body'=>json_encode(['pages'=>[['index'=>0,'markdown'=>'ETHYL ACETATE gross 27860 kg']],'model'=>'mistral-ocr-4-1'])];
        }
        $mappingCalls++; $input=json_decode($body['messages'][1]['content'],true); $fields=array_column($input['fields'],'id');
        $source=['sourceId'=>$input['sources'][0]['sourceId'],'quote'=>'ETHYL ACETATE'];
        $candidates=in_array('goods.grossMass',$fields,true)?[['fieldId'=>'goods.grossMass','entityKey'=>'ethyl','rawValue'=>'27860','unit'=>'kg','source'=>array_replace($source,['quote'=>'27860 kg'])]]:[];
        $payload=['entities'=>$candidates!==[]?[['scope'=>'goods','key'=>'ethyl','role'=>null,'source'=>$source]]:[],'candidates'=>$candidates,'classificationQueries'=>[],'warnings'=>[]];
        return ['status'=>200,'body'=>json_encode(['choices'=>[['finish_reason'=>'stop','message'=>['content'=>json_encode($payload)]]]])];
    });
    $pipeline=new Pipeline($config,$store,$client); $pipeline->start($id); $pipeline->start($id);
    for($i=0;$i<12 && $store->read($id)['phase']!=='reviewing';$i++)$pipeline->tick();
    $done=$store->read($id); same('reviewing',$done['phase']); same('27860',$done['draft']['goods'][0]['grossMass']); same(1,$ocrCalls); truth($mappingCalls>0);
    $entityId=$done['draft']['goods'][0]['id'];
    $store->mutate($id,$done['dataRevision'],fn(array $s):array=>(new Draft(new Registry()))->edit($s,'goods.grossMass',$entityId,'28000'));
    $pipeline->start($id); $pipeline->tick();
    same(1,$ocrCalls); same('28000',$store->read($id)['draft']['goods'][0]['grossMass']);
});

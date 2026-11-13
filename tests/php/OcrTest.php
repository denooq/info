<?php
declare(strict_types=1);

use Customs\AI\MistralClient;
use Customs\AI\Ocr;
use Customs\AI\OcrRequestBody;
use Customs\Config;
use Customs\Documents\Uploads;
use Customs\Jobs\Queue;
use Customs\Storage\SessionStore;

test('streamed OCR body preserves binary bytes, JSON and content length at arbitrary boundaries', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'ocr-body-');
    try {
        foreach ([0, 1, 2, 3, 65_539] as $size) {
            $bytes = $size === 0 ? '' : random_bytes($size);
            file_put_contents($path, $bytes);
            $stream = new OcrRequestBody($path, ['document' => ['type' => 'image_url', 'image_url' => 'data:image/png;base64,'], 'pages' => [0]]);
            $body = '';
            while (($chunk = $stream->read(101)) !== '') $body .= $chunk;
            same(strlen($body), $stream->length);
            same('data:image/png;base64,' . base64_encode($bytes), json_decode($body, true, 256, JSON_THROW_ON_ERROR)['document']['image_url']);
            unset($stream);
        }
    } finally { unlink($path); }
});

test('server OCR request pins model and preserves page structure without image copies', function (): void {
    $calls=0;
    $client=new MistralClient(new Config(['mistral_api_key'=>'test-placeholder']),function(string $url,array $body,array $headers)use(&$calls):array{
        $calls++; same('https://api.mistral.ai/v1/ocr',$url); same('mistral-ocr-4-1',$body['model']);
        same(true,$body['include_blocks']); same(false,$body['include_image_base64']);
        same([8,9],$body['pages']); truth(str_starts_with($body['document']['document_url'],'data:application/pdf;base64,'));
        return ['status'=>200,'body'=>json_encode(['pages'=>[['index'=>8,'markdown'=>'Товар 1200 кг'],['index'=>9,'markdown'=>'Цена 2844 EUR']]])];
    });
    $response=$client->ocr(dirname(__DIR__,2).'/reference/source-documents/example2/авиа.pdf','application/pdf',[8,9]);
    $result=(new Ocr(new Config(),$client))->normalize($response,[8,9]);
    same(9,$result['pages'][0]['page']); same(10,$result['pages'][1]['page']); same(1,$calls);
    truth(str_contains($result['pages'][0]['blocks'][0]['text'],'1200'));
});

test('provider errors never expose keys or raw document text', function (): void {
    foreach ([401=>'MISTRAL_AUTH',402=>'MISTRAL_BILLING',400=>'MISTRAL_REQUEST',429=>'MISTRAL_RATE_LIMIT',503=>'MISTRAL_TEMPORARY'] as $status=>$code) {
        $client=new MistralClient(new Config(['mistral_api_key'=>'test-placeholder']),fn()=>['status'=>$status,'body'=>'private document text and test-placeholder']);
        try { $client->structured('system','input',[]); throw new RuntimeException('Expected provider error'); }
        catch(Customs\Http\ApiError $error) { same($code,$error->errorCode); truth(!str_contains($error->getMessage(),'test-placeholder')); truth(!str_contains($error->getMessage(),'private document')); }
    }
    $called=false;
    $client=new MistralClient(new Config(),function()use(&$called){$called=true;});
    errorCode('MISTRAL_NOT_CONFIGURED',fn()=>$client->structured('s','i',[])); same(false,$called);
});

test('OCR rejects missing and incorrectly indexed pages and preserves table text', function (): void {
    $config=new Config(); $ocr=new Ocr($config,new MistralClient($config));
    errorCode('OCR_INCOMPLETE',fn()=>$ocr->normalize(['pages'=>[['index'=>0,'markdown'=>'one']]],[0,1]));
    errorCode('OCR_PAGE_INDEX',fn()=>$ocr->normalize(['pages'=>[['index'=>0,'markdown'=>'one']]],[8]));
    $result=$ocr->normalize(['pages'=>[['index'=>0,'markdown'=>'[tbl.html](tbl.html)','tables'=>[['id'=>'t1','content'=>'<table><tr><td>1200</td><td>кг</td></tr></table>']]]]],[0]);
    truth(str_contains($result['pages'][0]['blocks'][1]['text'],'1200'));
    truth(str_contains($result['pages'][0]['blocks'][1]['text'],'кг'));
});

test('twelve-page contract runs as two independent chunks and cached chunks are not billed twice', function (): void {
    $config=new Config(['runtime_dir'=>sys_get_temp_dir().'/customs-test-'.bin2hex(random_bytes(8)),'mistral_api_key'=>'test-placeholder']);
    $store=new SessionStore($config); $session=$store->create(); $id=$session['state']['id'];
    $state=(new Uploads($config,$store))->addFromPath($id,dirname(__DIR__,2).'/reference/source-documents/example2/контракт.pdf','contract.pdf',0);
    $documentId=array_key_first($state['documents']); $queue=new Queue($config,$store);
    $queue->enqueue($id,'ocr',$documentId,false,0); $queue->enqueue($id,'ocr',$documentId,false,8); $queue->enqueue($id,'ocr',$documentId,false,8);
    same(2,count($store->read($id)['jobs'])); $calls=0;
    $client=new MistralClient($config,function(string $url,array $body)use(&$calls):array{
        $calls++; return ['status'=>200,'body'=>json_encode(['pages'=>array_map(fn(int $index):array=>['index'=>$index,'markdown'=>'Contract page '.($index+1)],$body['pages']),'usage_info'=>['pages_processed'=>count($body['pages'])]])];
    });
    $ocr=new Ocr($config,$client); $handlers=['ocr'=>['run'=>$ocr->run(...),'apply'=>$ocr->apply(...)]];
    $queue->tick($handlers); same('recognizing',$store->read($id)['documents'][$documentId]['status']);
    $queue->tick($handlers); $done=$store->read($id);
    same('recognized',$done['documents'][$documentId]['status']); same(range(0,11),$done['documents'][$documentId]['recognizedPages']);
    foreach($done['jobs'] as $job) $ocr->run($job,$done,$store->directory($id));
    same(2,$calls);
});

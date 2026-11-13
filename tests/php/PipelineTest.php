<?php
declare(strict_types=1);

use Customs\AI\MistralClient;
use Customs\AI\Pipeline;
use Customs\Config;
use Customs\Documents\Uploads;
use Customs\Domain\Draft;
use Customs\Domain\Registry;
use Customs\Storage\SessionStore;

test('complete extraction pipeline maps from OCR, preserves manual changes and never reruns cached OCR on remap', function (): void {
    $config=new Config(['runtime_dir'=>sys_get_temp_dir().'/customs-test-'.bin2hex(random_bytes(8)),'mistral_api_key'=>'test-placeholder']);
    $store=new SessionStore($config); $session=$store->create(); $id=$session['state']['id'];
    $state=(new Uploads($config,$store))->addFromPath($id,dirname(__DIR__,2).'/reference/source-documents/example2/авиа.pdf','test.pdf',0);
    $documentId=array_key_first($state['documents']); $ocrCalls=0; $mappingCalls=0;
    $client=new MistralClient($config,function(string $url,array $body)use(&$ocrCalls,&$mappingCalls,$documentId):array{
        if(str_ends_with($url,'/ocr')) {
            $ocrCalls++; return ['status'=>200,'body'=>json_encode(['pages'=>[['index'=>0,'markdown'=>'ETHYL ACETATE gross 27860 kg']],'model'=>'mistral-ocr-4-1'])];
        }
        $mappingCalls++; $input=json_decode($body['messages'][1]['content'],true); $fields=array_column($input['fields'],'id');
        $source=['sourceId'=>$input['sources'][0]['sourceId'],'quote'=>'ETHYL ACETATE'];
        $candidates=in_array('goods.grossMass',$fields,true)?[['fieldId'=>'goods.grossMass','entityKey'=>'ethyl','rawValue'=>'27860','unit'=>'kg','source'=>array_replace($source,['quote'=>'27860 kg'])]]:[];
        $payload=['entities'=>$candidates!==[]?[['scope'=>'goods','key'=>'ethyl','role'=>null,'source'=>$source]]:[],'candidates'=>$candidates,'warnings'=>[]];
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

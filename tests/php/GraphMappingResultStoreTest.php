<?php
declare(strict_types=1);

function graphResultSources(): array
{
    return [
        ['documentId'=>str_repeat('a',32),'name'=>'cmr.pdf','documentKind'=>'02015','allowsNewGoods'=>true,'pages'=>[['page'=>1,'blocks'=>[['id'=>'p1-b0','sourceId'=>'src_'.str_repeat('1',32),'text'=>'ETHYL ACETATE Gross 27860 kg']]]],'sheets'=>[]],
        ['documentId'=>str_repeat('b',32),'name'=>'export.pdf','documentKind'=>'04021','allowsNewGoods'=>true,'pages'=>[['page'=>1,'blocks'=>[['id'=>'p1-b0','sourceId'=>'src_'.str_repeat('2',32),'text'=>'ETHYL ACETATE Gross 26792 kg']]]],'sheets'=>[]],
    ];
}

function graphMassMapping(string $sourceId, string $mass): array
{
    return [
        'schemaVersion'=>'graph-mapping-v2',
        'items'=>[['itemKey'=>'goods-1','description'=>'ETHYL ACETATE','evidence'=>[['sourceId'=>$sourceId,'quote'=>'ETHYL ACETATE']]]],
        'results'=>[['graphId'=>'35','itemKey'=>'goods-1','status'=>'found','values'=>[[
            'fieldId'=>'goods.grossMass','valueType'=>'decimal','rawValue'=>$mass,'normalizedValue'=>$mass,'unit'=>'kg','currency'=>null,
            'evidence'=>[['sourceId'=>$sourceId,'quote'=>$mass.' kg']],
        ]],'positionValue'=>null,'searchedSourceIds'=>[$sourceId],'reason'=>null]],
        'documentRecords'=>[],'dtsFacts'=>[],'referenceSelections'=>[],'catalogRequests'=>[],'contextRequests'=>[],'warnings'=>[],
    ];
}

function graphResultState(array $sources): array
{
    $draft=(new Customs\Domain\Draft(new Customs\Domain\Registry()))->empty();
    return [
        'id'=>'graph-result-test','draft'=>$draft,'dataRevision'=>3,'documents'=>[
            str_repeat('a',32)=>['id'=>str_repeat('a',32),'documentKind'=>'02015'],
            str_repeat('b',32)=>['id'=>str_repeat('b',32),'documentKind'=>'04021'],
        ],'candidates'=>[],'evidence'=>[],'mappingIssues'=>[],'graphMappingParts'=>[],
        'graphMappingExpectedSourceIds'=>['src_'.str_repeat('1',32),'src_'.str_repeat('2',32)],'graphMappingRunComplete'=>true,
    ];
}

test('mapping result application fills a supported fact and exposes its source in the API projection',function():void {
    $sources=graphResultSources();$state=graphResultState($sources);$mapping=graphMassMapping('src_'.str_repeat('1',32),'27860');
    $state=(new Customs\AI\GraphMappingResultStore())->apply($state,$mapping,$sources);$state['graphMappingParts'][]=$mapping;
    $state['graphMappingResults']=(new Customs\AI\GraphMappingResultStore())->project($state);
    same('27860',$state['draft']['goods'][0]['grossMass']);
    $projection=(new Customs\Domain\GraphProjection())->project($state);
    $graph=array_values(array_filter($projection['graphs'],fn(array $row):bool=>$row['id']==='35'))[0];
    same('found',$graph['mappingStatus']);
    $entry=array_values(array_filter($graph['entries'],fn(array $row):bool=>$row['fieldId']==='goods.grossMass'))[0];
    same('found',$entry['mappingStatus']);same(1,count($entry['mappingAlternatives']));
    $proof=$entry['mappingAlternatives'][0]['evidenceIds'][0];same('27860 kg',$state['evidence'][$proof]['quote']);
});

test('equal facts merge citations while distinct values remain a conflict regardless of source rank',function():void {
    $sources=graphResultSources();$equalSources=$sources;$equalSources[1]['pages'][0]['blocks'][0]['text']='ETHYL ACETATE Gross 27860 kg';$store=new Customs\AI\GraphMappingResultStore();
    $state=graphResultState($sources);$first=graphMassMapping('src_'.str_repeat('1',32),'27860');$same=graphMassMapping('src_'.str_repeat('2',32),'27860');
    $state=$store->apply($state,$first,$equalSources);$state=$store->apply($state,$same,$equalSources);$state['graphMappingParts']=[$first,$same];
    $address='goods/goods-1/grossMass';$candidate=array_values(array_filter($state['candidates'],fn(array $row):bool=>$row['address']===$address))[0];
    same(2,count($candidate['evidenceIds']));same('27860',$state['draft']['goods'][0]['grossMass']);
    $state=graphResultState($sources);$first=graphMassMapping('src_'.str_repeat('1',32),'27860');$different=graphMassMapping('src_'.str_repeat('2',32),'26792');
    $state=$store->apply($state,$first,$sources);$state=$store->apply($state,$different,$sources);$state['graphMappingParts']=[$first,$different];
    same(null,$state['draft']['goods'][0]['grossMass']);
    $state['graphMappingResults']=$store->project($state);
    $target=array_values(array_filter($state['graphMappingResults']['targets'],fn(array $row):bool=>$row['graphId']==='35'&&$row['entityId']==='goods-1'))[0];
    same('conflict',$target['status']);same(2,count($target['fields'][0]['alternatives']));
});

test('manual values survive remap and new conflicting evidence is reported',function():void {
    $sources=graphResultSources();$state=graphResultState($sources);$goodsId=$state['draft']['goods'][0]['id'];
    $state=(new Customs\Domain\Draft(new Customs\Domain\Registry()))->edit($state,'goods.grossMass',$goodsId,'28000');
    $mapping=graphMassMapping('src_'.str_repeat('1',32),'27860');$store=new Customs\AI\GraphMappingResultStore();
    $state=$store->apply($state,$mapping,$sources);$state['graphMappingParts']=[$mapping];
    same('28000',$state['draft']['goods'][0]['grossMass']);same(true,$state['draft']['fieldStates']['goods/goods-1/grossMass']['needsReview']);
    $state=$store->resetForRun($state,'next-run',['src_'.str_repeat('1',32)]);
    same('28000',$state['draft']['goods'][0]['grossMass']);same('manual',$state['draft']['fieldStates']['goods/goods-1/grossMass']['status']);
});

test('selected candidate and its identity survive a new mapping revision',function():void {
    $sources=graphResultSources();$state=graphResultState($sources);$mapping=graphMassMapping('src_'.str_repeat('1',32),'27860');$store=new Customs\AI\GraphMappingResultStore();
    $state=$store->apply($state,$mapping,$sources);$candidate=array_values(array_filter($state['candidates'],fn(array $row):bool=>$row['fieldId']==='goods.grossMass'))[0];
    $state=(new Customs\Domain\Draft(new Customs\Domain\Registry()))->edit($state,'goods.grossMass','goods-1','', $candidate['id']);
    $state=$store->resetForRun($state,'next-run',['src_'.str_repeat('1',32)]);
    truth(isset($state['candidates'][$candidate['id']]));same('selected-snapshot',$state['candidates'][$candidate['id']]['origin']);
    same('27860',$state['draft']['goods'][0]['grossMass']);same('selected',$state['draft']['fieldStates']['goods/goods-1/grossMass']['status']);
});

test('not found requires complete source coverage and all 57 positions are present in session projection',function():void {
    $sources=graphResultSources();$state=graphResultState($sources);$id='src_'.str_repeat('1',32);
    $mapping=['schemaVersion'=>'graph-mapping-v2','items'=>[],'results'=>[['graphId'=>'35','itemKey'=>'goods-1','status'=>'not_found','values'=>[],'positionValue'=>null,'searchedSourceIds'=>[$id,'src_'.str_repeat('2',32)],'reason'=>null]],'documentRecords'=>[],'dtsFacts'=>[],'referenceSelections'=>[],'catalogRequests'=>[],'contextRequests'=>[],'warnings'=>[]];
    $state['graphMappingParts']=[$mapping];$store=new Customs\AI\GraphMappingResultStore();$report=$store->project($state);
    $target=array_values(array_filter($report['targets'],fn(array $row):bool=>$row['graphId']==='35'&&$row['entityId']==='goods-1'))[0];same('not_found',$target['status']);
    $state['graphMappingRunComplete']=false;$state['graphMappingExpectedSourceIds']=['src_'.str_repeat('1',32),'src_'.str_repeat('2',32)];
    $target=array_values(array_filter($store->project($state)['targets'],fn(array $row):bool=>$row['graphId']==='35'&&$row['entityId']==='goods-1'))[0];same('incomplete',$target['status']);

    $config=new Customs\Config(['runtime_dir'=>sys_get_temp_dir().'/customs-result-api-'.bin2hex(random_bytes(5))]);
    $previousReporting=error_reporting(0);
    try {$reply=(new Customs\Http\Application($config))->handle('POST','/api/session');}
    finally {error_reporting($previousReporting);}
    $results=$reply['graphProjection']['mappingResults'];same(57,count(array_unique(array_column($results['targets'],'graphId'))));
    truth(!array_key_exists('graphMappingParts',$reply),'Raw mapping responses must not be included in the public session response.');
});

test('DTS values without a stable cost row remain visible and are not auto-assigned',function():void {
    $sources=graphResultSources();$state=graphResultState($sources);$store=new Customs\AI\GraphMappingResultStore();
    $fact=['matrixId'=>'DTS-13','itemKey'=>null,'participantRole'=>null,'value'=>[
        'fieldId'=>'cost.amount','valueType'=>'decimal','rawValue'=>'27860','normalizedValue'=>'27860','unit'=>'USD','currency'=>'USD',
        'evidence'=>[['sourceId'=>'src_'.str_repeat('1',32),'quote'=>'27860 kg']],
    ]];
    $mapping=['schemaVersion'=>'graph-mapping-v2','items'=>[],'results'=>[],'documentRecords'=>[],'dtsFacts'=>[$fact],'referenceSelections'=>[],'catalogRequests'=>[],'contextRequests'=>[],'warnings'=>[]];
    $state=$store->apply($state,$mapping,$sources);$state['graphMappingParts']=[$mapping];$report=$store->project($state);
    same([],$state['draft']['costs']);same('incomplete',$report['dtsFacts'][0]['status']);
    same('27860',$report['dtsFacts'][0]['alternatives'][0]['value']);truth($report['dtsFacts'][0]['alternatives'][0]['evidenceIds']!==[]);
});

test('AI descriptive TN VED query resolves only through the local catalogue and cites both origins',function():void {
    $source=['documentId'=>str_repeat('c',32),'name'=>'invoice.pdf','documentKind'=>'04021','allowsNewGoods'=>true,'pages'=>[['page'=>1,'blocks'=>[['id'=>'p1-b0','sourceId'=>'src_'.str_repeat('3',32),'text'=>'Invoice ETHYL ACETATE']]]],'sheets'=>[]];
    $store=new Customs\AI\GraphMappingResultStore();$state=graphResultState([$source]);
    $mapping=['schemaVersion'=>'graph-mapping-v2','items'=>[['itemKey'=>'goods-1','description'=>'ETHYL ACETATE','evidence'=>[['sourceId'=>'src_'.str_repeat('3',32),'quote'=>'ETHYL ACETATE']]]],
        'results'=>[],'documentRecords'=>[],'dtsFacts'=>[],'referenceSelections'=>[],
        'catalogRequests'=>[['graphId'=>'33','itemKey'=>'goods-1','fieldId'=>'goods.commodityCode','query'=>'этилацетат','reason'=>'Смысловое соответствие наименования товара']],
        'contextRequests'=>[],'warnings'=>[]];
    $state=$store->apply($state,$mapping,[$source]);
    same('2915310000',$state['draft']['goods'][0]['commodityCode']);
    same('reference',$state['draft']['fieldStates']['goods/goods-1/commodityCode']['status']);
    $candidate=array_values(array_filter($state['candidates'],fn(array $row):bool=>($row['fieldId']??null)==='goods.commodityCode'))[0];
    same('tnved',$candidate['origin']);same([$source['documentId']],$candidate['documentIds']);
    truth(count($candidate['evidenceIds'])>=2,'The product description and selected catalog record must both remain visible.');
});

test('a broad AI translated product label produces review candidates rather than an automatic code',function():void {
    $source=['documentId'=>str_repeat('d',32),'name'=>'invoice.pdf','documentKind'=>'04021','allowsNewGoods'=>true,'pages'=>[['page'=>1,'blocks'=>[['id'=>'p1-b0','sourceId'=>'src_'.str_repeat('4',32),'text'=>'Invoice GLUE']]]],'sheets'=>[]];
    $store=new Customs\AI\GraphMappingResultStore();$state=graphResultState([$source]);
    $mapping=['schemaVersion'=>'graph-mapping-v2','items'=>[['itemKey'=>'goods-1','description'=>'GLUE','evidence'=>[['sourceId'=>'src_'.str_repeat('4',32),'quote'=>'GLUE']]]],
        'results'=>[],'documentRecords'=>[],'dtsFacts'=>[],'referenceSelections'=>[],
        'catalogRequests'=>[['graphId'=>'33','itemKey'=>'goods-1','fieldId'=>'goods.commodityCode','query'=>'клей','reason'=>'Перевод подтверждённого наименования товара']],
        'contextRequests'=>[],'warnings'=>[]];
    $state=$store->apply($state,$mapping,[$source]);
    same(null,$state['draft']['goods'][0]['commodityCode']);
    same('ambiguous',$state['draft']['fieldStates']['goods/goods-1/commodityCode']['tnved']['status']);
    truth(count(array_filter($state['candidates'],fn(array $row):bool=>($row['fieldId']??null)==='goods.commodityCode'))>1);
});

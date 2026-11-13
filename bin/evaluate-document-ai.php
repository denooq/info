<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

$options = getopt('', ['fixture:','probe','max-ticks:','reset-mapping','report-only','replay']);
$fixtureName = $options['fixture'] ?? 'reference-cpt';
if (!in_array($fixtureName, ['reference-cpt','reference-fca'], true)) { fwrite(STDERR, "Unknown fixture.\n"); exit(1); }
$probe = array_key_exists('probe', $options);
$maxTicks = min(60, max(1, (int) ($options['max-ticks'] ?? 30)));
$project = dirname(__DIR__);
$fixture = json_decode(file_get_contents($project . '/tests/fixtures/' . $fixtureName . '.json'), true, 128, JSON_THROW_ON_ERROR);
$inputs = $probe ? array_values(array_filter($fixture['inputs'], fn(string $file): bool => basename($file) === 'смр.pdf')) : $fixture['inputs'];
if ($inputs === []) { fwrite(STDERR, "Probe source not found.\n"); exit(1); }

try {
    $config = Customs\Config::load();
    if ($config->get('mistral_api_key') === '') throw new Customs\Http\ApiError('MISTRAL_NOT_CONFIGURED', 'Нужен ключ в закрытой конфигурации.', 503);
    $store = new Customs\Storage\SessionStore($config);
    $caseId = $fixtureName . ($probe ? '-probe' : '');
    $state = null;
    foreach (glob($config->get('runtime_dir') . '/sessions/*', GLOB_ONLYDIR) as $directory) {
        $id = basename($directory);
        if (!preg_match('/^[a-f0-9]{64}$/D', $id)) continue;
        try { $candidate = $store->read($id); }
        catch (Customs\Http\ApiError) { continue; }
        if (($candidate['evaluationCase'] ?? null) === $caseId) { $state = $candidate; break; }
    }
    if ($state === null) {
        $state = $store->create()['state'];
        $state = $store->mutate($state['id'], null, function (array $s) use ($caseId): array { $s['evaluationCase'] = $caseId; return $s; }, false);
    }
    $uploads = new Customs\Documents\Uploads($config, $store);
    foreach ($inputs as $relative) {
        $exists=false;
        foreach($state['documents'] as $document)if($document['name']===basename($relative) && $document['hash']===hash_file('sha256',$project.'/'.$relative))$exists=true;
        if(!$exists)$state=$uploads->addFromPath($state['id'],$project.'/'.$relative,basename($relative),$state['dataRevision']);
    }
    $id = $state['id'];
    if (array_key_exists('reset-mapping', $options)) {
        $state = $store->mutate($id, null, function(array $s): array {
            $s['draft'] = (new Customs\Domain\Draft(new Customs\Domain\Registry()))->empty();
            $s['candidates'] = $s['evidence'] = $s['mappingIssues'] = $s['mappingProgress'] = [];
            $s['jobs'] = array_filter($s['jobs'], fn(array $j): bool => $j['kind'] === 'ocr');
            $s['error'] = null;
            return $s;
        });
    }
    $pipeline = new Customs\AI\Pipeline($config, $store, new Customs\AI\MistralClient($config));
    if(array_key_exists('replay',$options)) {
        $completed=null;foreach($state['jobs'] as $job)if($job['kind']==='mapping'&&$job['status']==='done')$completed=$job;
        if($completed===null)throw new Customs\Http\ApiError('EVALUATION_INCOMPLETE','Нет завершённого сопоставления для повтора проверки.');
        // Test harness only: re-verify saved canonical responses, without any provider request.
        $sources=(new ReflectionMethod($pipeline,'sources'))->invoke($pipeline,$state,$store->directory($id));
        $paths=glob($store->directory($id).'/ocr/mapping-'.substr($completed['fingerprint'],0,16).'-*.json');natsort($paths);
        $state=$store->mutate($id,null,function(array $s)use($paths,$sources):array{
            $registry=new Customs\Domain\Registry();$s['draft']=(new Customs\Domain\Draft($registry))->empty();$s['candidates']=$s['evidence']=$s['mappingIssues']=[];
            foreach($paths as $path)$s=(new Customs\AI\CandidateMerger($registry))->merge($s,json_decode(file_get_contents($path),true,128,JSON_THROW_ON_ERROR),$sources);
            $s['phase']='reviewing';return $s;
        });
        $options['report-only']=false;
    }
    if (!array_key_exists('report-only', $options)) $pipeline->start($id, true);
    $started = time();
    for ($tick = 0; !array_key_exists('report-only', $options) && $tick < $maxTicks && time() - $started < 600; $tick++) {
        $pipeline->tick(1, $id);
        $state = $store->read($id);
        $done = count(array_filter($state['jobs'], fn(array $job): bool => $job['status'] === 'done'));
        echo json_encode(['fixture' => $caseId,'phase' => $state['phase'],'jobsDone' => $done,'jobsTotal' => count($state['jobs'])], JSON_THROW_ON_ERROR) . PHP_EOL;
        foreach ($state['jobs'] as $job) if ($job['error'] !== null && in_array($job['status'], ['queued','failed'], true)) throw new Customs\Http\ApiError($job['error']['code'], $job['error']['message'], 502);
        if ($state['phase'] === 'reviewing') break;
        if ($probe && count(array_filter($state['documents'], fn(array $doc): bool => $doc['status'] === 'recognized')) === count($state['documents'])) break;
    }
    $coverage = [];
    $registry = new Customs\Domain\Registry();
    foreach ($fixture['facts'] as $fact) {
        $matches = [];
        $expected = $registry->field($fact['fieldId'])['type'] === 'decimal' ? (string) Brick\Math\BigDecimal::of($fact['value'])->strippedOfTrailingZeros() : $fact['value'];
        foreach ($state['candidates'] as $candidate) if ($candidate['fieldId'] === $fact['fieldId'] && (string) $candidate['value'] === $expected) $matches[] = $candidate;
        $evidence=[];foreach($matches as $candidate)foreach($candidate['evidenceIds'] as $evidenceId) {
            $source=$state['evidence'][$evidenceId];$evidence[]=['file'=>$source['documentName'],'page'=>$source['page']??null,'sheet'=>$source['sheet']??null,'cell'=>$source['cell']??null];
        }
        $coverage[] = ['field' => $fact['fieldId'],'expected' => $fact['value'],'found' => $matches !== [],'sources'=>$evidence];
    }
    $usage = ['ocrPages'=>0,'mappingPromptTokens'=>0,'mappingCompletionTokens'=>0,'note'=>'Includes all local evaluation attempts and cached prompt versions in this session.'];
    foreach (glob($store->directory($id) . '/ocr/*.json') as $path) {
        $cache = json_decode(file_get_contents($path), true, 256, JSON_THROW_ON_ERROR);
        $part=$cache['result']['usage'] ?? $cache['usage'] ?? [];
        $usage['ocrPages']+=(int)($part['pages_processed']??0);
        $usage['mappingPromptTokens']+=(int)($part['prompt_tokens']??0);
        $usage['mappingCompletionTokens']+=(int)($part['completion_tokens']??0);
    }
    $allRecognized = count(array_filter($state['documents'], fn(array $doc): bool => $doc['status'] === 'recognized')) === count($state['documents']);
    $report = ['fixture' => $caseId,'complete' => $probe ? $allRecognized : $state['phase'] === 'reviewing','inputFiles' => count($state['documents']),'candidates' => count($state['candidates']),'mappingIssues' => array_count_values(array_column($state['mappingIssues'] ?? [], 'code')),'coverage' => $probe ? [] : $coverage,'usage' => $usage,'peakBytes' => memory_get_peak_usage(true),'elapsedSeconds' => time() - $started];
    echo json_encode(['report' => $report], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($report['complete'] ? 0 : 1);
} catch (Customs\Http\ApiError $error) {
    echo json_encode(['error' => ['code' => $error->errorCode,'message' => $error->getMessage()]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "Evaluation failed; no provider payload or key was logged.\n");
    exit(1);
}

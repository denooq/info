<?php
declare(strict_types=1);

namespace Customs\AI;

use Customs\Config;
use Customs\Http\ApiError;
use Customs\Jobs\Queue;
use Customs\Storage\SessionStore;

final class Pipeline
{
    public const MAPPING_PLAN_VERSION='exact-serialized-graph-windows-v4';
    private Queue $queue;
    private Ocr $ocr;

    public function __construct(private readonly Config $config, private readonly SessionStore $store, private readonly MistralClient $client)
    {
        $this->queue = new Queue($config, $store);
        $this->ocr = new Ocr($config, $client);
    }

    public function start(string $sessionId, bool $retry = false, bool $mappingOnly = false): array
    {
        $state = $this->store->read($sessionId);
        if($retry&&($state['mappingNeedsExplicitRetry']??false))$state=$this->store->mutate($sessionId,null,function(array $current):array{unset($current['mappingNeedsExplicitRetry']);return $current;},false);
        if ($state['documents'] === []) throw new ApiError('DOCUMENTS_REQUIRED', 'Сначала приложите документы.');
        foreach($state['jobs'] as $job)if(in_array($job['kind'],['ocr','mapping'],true)&&in_array($job['status'],['queued','running'],true))return $state;
        if ($mappingOnly) {
            if (!$retry) throw new ApiError('MAPPING_RETRY_REQUIRED', 'Повтор сопоставления должен быть явно запрошен.', 422);
            if (!array_filter($state['documents'], static fn(array $document): bool => $document['status'] === 'recognized')) {
                throw new ApiError('MAPPING_OCR_REQUIRED', 'Нет распознанных документов для повторного сопоставления.', 409);
            }
            foreach ($state['documents'] as $document) if (!in_array($document['status'], ['recognized','error'], true)) {
                throw new ApiError('MAPPING_OCR_INCOMPLETE', 'Сначала завершите распознавание документов.', 409);
            }
            $this->scheduleMapping($sessionId, true, true);
            return $this->store->read($sessionId);
        }
        foreach ($state['documents'] as $document) {
            $step = $document['extension'] === 'xlsx' ? $document['pages'] : Ocr::CHUNK_PAGES;
            for ($start = 0; $start < $document['pages']; $start += $step) $this->queue->enqueue($sessionId, 'ocr', $document['id'], $retry, $start);
        }
        $this->scheduleMapping($sessionId, $retry);
        return $this->store->read($sessionId);
    }

    public function tick(int $maxJobs = 100, ?string $onlySessionId = null): array
    {
        $handlers = [
            'ocr' => ['run' => $this->ocr->run(...), 'runBatch' => $this->ocr->runBatch(...), 'apply' => $this->ocr->apply(...)],
            'mapping' => [
                'run' => $this->mapPart(...), 'apply' => $this->applyPart(...),
                'continue' => fn(array $payload): bool => $payload['more'], 'changesData' => true,
            ],
        ];
        $result = $this->queue->tick($handlers, $maxJobs, 150, $onlySessionId, fn(string $id) => $this->scheduleMapping($id, false));
        foreach (glob($this->config->get('runtime_dir') . '/sessions/*', GLOB_ONLYDIR) as $directory) {
            $id = basename($directory);
            if (!preg_match('/^[a-f0-9]{64}$/D', $id)) continue;
            if ($onlySessionId !== null && $id !== $onlySessionId) continue;
            try { if($onlySessionId===null && isset($this->store->read($id)['evaluationCase']))continue; $this->scheduleMapping($id, false); }
            catch (ApiError $error) { if ($error->errorCode !== 'SESSION_EXPIRED') throw $error; }
        }
        return $result;
    }

    private function scheduleMapping(string $sessionId, bool $retry, bool $mappingOnly = false): void
    {
        $state = $this->store->read($sessionId);
        if(($state['mappingNeedsExplicitRetry']??false)&&!$retry)return;
        if ($state['documents'] === []) return;
        foreach($state['jobs'] as $job)if($job['kind']==='ocr'&&in_array($job['status'],['queued','running'],true))return;
        foreach ($state['documents'] as $document) if (!in_array($document['status'],['recognized','error'],true)) return;
        if(!array_filter($state['documents'],fn($d)=>$d['status']==='recognized')) {
            if($state['phase']==='recognizing')$this->store->mutate($sessionId,null,function($s){$s['phase']='reviewing';$s['error']??=['code'=>'OCR_ALL_FAILED','message'=>'Документы не распознаны. Заполните графы вручную или повторите неуспешные файлы.'];return $s;},false);
            return;
        }
        $fingerprint = $this->queue->fingerprint($state, 'mapping', null);
        if (($state['graphMappingFingerprint'] ?? null) !== $fingerprint || $retry) {
            try { $manifest = $this->sources($state, $this->store->directory($sessionId)); }
            catch (ApiError $error) {
                if ($error->errorCode !== 'OCR_REQUIRED') throw $error;
                if (!$mappingOnly) return;
                $safe = ['code' => 'MAPPING_OCR_CACHE_REQUIRED', 'message' => 'Не найден сохранённый текст распознавания. Вернитесь к документам и повторите распознавание.'];
                $this->store->mutate($sessionId, null, function (array $current) use ($safe): array {
                    $current['phase'] = 'reviewing'; $current['error'] = $safe; $current['graphMappingRunComplete'] = false;
                    return $current;
                }, false);
                return;
            }
            [$knownGoods, $knownDocumentRecords] = $this->knownMappingContext($state);
            try { $plan = $this->plan($manifest, $knownGoods, $knownDocumentRecords); }
            catch (ApiError $error) {
                if (!str_starts_with($error->errorCode, 'MAPPING_')) throw $error;
                $safe = $this->safeMappingError($error);
                $this->store->mutate($sessionId, null, function (array $current) use ($safe): array {
                    $current['phase'] = 'reviewing'; $current['error'] = $safe;
                    $current['graphMappingRunComplete'] = false;
                    $current['graphMappingFailures'][] = ['code' => $safe['code'], 'details' => $safe['details'] ?? null];
                    return $current;
                }, false);
                return;
            }
            $expected = $plan[0]['allSourceIds'] ?? [];
            $state = $this->store->mutate($sessionId, null, function (array $current) use ($fingerprint, $expected): array {
                $current = (new GraphMappingResultStore())->resetForRun($current, $fingerprint, $expected);
                $current['mappingProgress'][$fingerprint] = 0;
                $current['mappingCompletedSourceIds'][$fingerprint] = [];
                $current['mappingContextRequests'] = [];
                $current['mappingContextRequestsServed'] = 0;
                $current['mappingWindowProgress'] = ['completed' => 0, 'calls' => 0, 'repairs' => 0, 'inputBytes' => 0];
                return $current;
            }, false);
        }
        $this->queue->enqueue($sessionId, 'mapping', null, $retry);
    }

    private function sources(array $state, string $directory): array
    {
        $sources = [];
        foreach ($state['documents'] as $document) {
            if($document['status']!=='recognized')continue;
            $source = ['documentId' => $document['id'],'name' => $document['name'],'contentHash'=>$document['hash'],'documentKind'=>$document['documentKind']??null,'classifierVersion'=>$document['classifierVersion']??null,'sourceRole'=>\Customs\Domain\SourceRoles::forCode($document['documentKind']??null),'pages' => [],'sheets' => []];
            foreach (glob($directory . '/ocr/' . $document['id'] . '-*.json') as $path) {
                $raw = @file_get_contents($path);
                if ($raw === false) throw new ApiError('SESSION_EXPIRED', 'Данные сессии недоступны.', 410);
                $cache = json_decode($raw, true, 256, JSON_THROW_ON_ERROR);
                if ($cache['sourceHash'] !== $document['hash']) continue;
                if (($cache['result']['kind'] ?? null) !== 'spreadsheet' && ($cache['ocrModel'] ?? null) !== $this->config->get('ocr_model')) continue;
                foreach ($cache['result']['pages'] as $page) $source['pages'][$page['page']] = $page;
                foreach ($cache['result']['sheets'] ?? [] as $sheet) $source['sheets'][$sheet['name']] = $sheet;
            }
            ksort($source['pages']);
            $source['pages'] = array_values($source['pages']); $source['sheets'] = array_values($source['sheets']);
            $source['allowsNewGoods'] = (new SourceIndex([$source]))->supportsNewGoods($source['documentId']);
            if ($source['pages'] === [] && $source['sheets'] === []) throw new ApiError('OCR_REQUIRED', 'Не все документы имеют сохранённый OCR.');
            $sources[] = $source;
        }
        return (new SourceManifest())->build($sources, $state['id']);
    }

    private function plan(array $sources, array $knownGoods = [], array $knownDocumentRecords = [], array $options = []): array
    {
        if (($sources['version'] ?? null) !== SourceManifest::VERSION || ($sources['sources'] ?? []) === []) return [];
        return (new Mapper($this->client))->plan($sources, $this->config->get('mapping_context_bytes'), $this->config->get('mapping_max_windows'), $knownGoods, [
            'knownDocumentRecords' => $knownDocumentRecords,
            ...$options,
        ]);
    }

    private function mapPart(array $job, array $state, string $directory): array
    {
        $manifest = $this->sources($state, $directory);
        [$knownGoods, $knownDocumentRecords] = $this->knownMappingContext($state);
        $cursor = (int)($state['mappingProgress'][$job['fingerprint']] ?? 0);
        $contextRequests = $state['mappingContextRequests'] ?? [];
        $contextLimitReached = (int)($state['mappingContextRequestsServed'] ?? 0) >= $this->config->get('mapping_max_context_requests');
        $isContextWindow = $contextRequests !== [] && !$contextLimitReached;
        $servedContextRequests = [];

        if ($isContextWindow) {
            $contextIds = [];
            foreach ($contextRequests as $request) foreach ($request['sourceIds'] ?? [] as $sourceId) if (is_string($sourceId)) $contextIds[] = $sourceId;
            $contextPlan = $this->plan($manifest, $knownGoods, $knownDocumentRecords, [
                'sourceIds' => array_values(array_unique($contextIds)),
                'contextRequests' => $contextRequests,
            ]);
            $part = $contextPlan[0] ?? null;
            if ($part === null) throw new ApiError('MAPPING_CONTEXT_INVALID', 'Дополнительный контекст не содержит доступных источников.', 422);
            $served = array_fill_keys($part['sourceIds'], true);
            foreach ($contextRequests as $request) {
                $ids = array_values(array_filter($request['sourceIds'] ?? [], static fn(string $id): bool => isset($served[$id])));
                if ($ids !== []) $servedContextRequests[] = [...$request, 'sourceIds' => $ids];
            }
        } else {
            $plan = $this->plan($manifest, $knownGoods, $knownDocumentRecords);
            $completed = array_fill_keys($state['mappingCompletedSourceIds'][$job['fingerprint']] ?? [], true);
            $part = null;
            foreach ($plan as $plannedWindow) {
                if (array_diff($plannedWindow['sourceIds'], array_keys($completed)) !== []) { $part = $plannedWindow; break; }
            }
            if ($part === null) return [
                'response' => ['mapping' => ['schemaVersion' => 'graph-mapping-v2']],
                'sources' => $manifest['sources'], 'cursor' => $cursor, 'more' => false,
                'window' => null, 'servedContextRequests' => [], 'isContextWindow' => false,
            ];
        }

        $requestContextRequests = $isContextWindow ? $servedContextRequests : [];
        $cacheKey = hash('sha256', json_encode([$part, $knownGoods, $knownDocumentRecords, $requestContextRequests], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $cache = $directory . '/ocr/mapping-' . substr($job['fingerprint'], 0, 16) . '-' . $cursor . '-' . substr($cacheKey, 0, 16) . '.json';
        if (is_file($cache)) $response = json_decode(file_get_contents($cache), true, 128, JSON_THROW_ON_ERROR);
        else {
            $response = (new Mapper($this->client))->request($manifest, $knownGoods, [
                'window' => $part, 'maxContextBytes' => $this->config->get('mapping_context_bytes'),
                'maxRepairs' => $this->config->get('mapping_max_repairs'), 'contextRequests' => $requestContextRequests,
                'knownDocumentRecords' => $knownDocumentRecords,
            ]);
            $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $used=0;
            foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->config->get('runtime_dir'),\FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile()&&!$file->isLink())$used+=$file->getSize();
            if($used+strlen($json)>$this->config->get('max_runtime_bytes'))throw new ApiError('STORAGE_LIMIT','Недостаточно места для сопоставления.',503);
            if (@file_put_contents($cache . '.tmp', $json, LOCK_EX) !== strlen($json) || !@rename($cache . '.tmp', $cache)) throw new ApiError('STORAGE_ERROR', 'Не удалось сохранить сопоставление.', 503);
        }
        $more = $isContextWindow;
        if (!$isContextWindow) {
            $covered = array_values(array_unique([...(array)($state['mappingCompletedSourceIds'][$job['fingerprint']] ?? []), ...$part['sourceIds']]));
            $more = array_diff($part['allSourceIds'], $covered) !== []
                || (($response['mapping']['contextRequests'] ?? []) !== [] && !$contextLimitReached);
        }
        return [
            'response' => $response, 'sources' => $manifest['sources'], 'cursor' => $cursor + 1,
            'more' => $more, 'window' => $part, 'servedContextRequests' => $servedContextRequests,
            'isContextWindow' => $isContextWindow,
        ];
    }

    private function applyPart(array $state, array $payload, array $job): array
    {
        if (($payload['window'] ?? null) === null) {
            $state['mappingProgress'][$job['fingerprint']] = $payload['cursor'];
            $state['graphMappingRunComplete'] = true;
            $state['graphMappingResults'] = (new GraphMappingResultStore())->project($state);
            $state['phase'] = 'reviewing';
            return $state;
        }
        $state['graphMapping'] = $payload['response']['mapping'] ?? ['schemaVersion' => 'graph-mapping-v2'];
        $state['graphMappingParts'] ??= [];
        if (($payload['response']['mapping']['schemaVersion'] ?? null) === 'graph-mapping-v2') {
            $knownGoods = [];
            foreach ($state['draft']['goods'] ?? [] as $goods) if (($goods['description'] ?? null) !== null) $knownGoods[] = ['key' => $goods['id'], 'description' => $goods['description']];
            $knownDocumentKeys = array_column($state['draft']['documents'] ?? [], 'id');
            $linked = (new GraphMappingIdentityLinker())->link($payload['response']['mapping'], $state['id'], $payload['sources'] ?? [], $state['graphMappingIdentity'] ?? [], $knownGoods, $knownDocumentKeys);
            $state['graphMapping'] = $linked['mapping'];
            $state['graphMappingIdentity'] = $linked['registry'];
            $state['graphMappingParts'][] = $linked['mapping'];
            $state = (new GraphMappingResultStore())->apply($state, $linked['mapping'], $payload['sources'] ?? []);
        }
        $state['graphMappingUsage'] = $this->addUsage($state['graphMappingUsage'] ?? [], $payload['response']['usage'] ?? []);
        $state['graphMappingCalls'] = ($state['graphMappingCalls'] ?? 0) + (int)($payload['response']['calls'] ?? 0);
        $state['graphMappingRepairs'] = ($state['graphMappingRepairs'] ?? 0) + (int)($payload['response']['repairs'] ?? 0);
        $state['graphMappingInputBytes'] = ($state['graphMappingInputBytes'] ?? 0) + (int)($payload['response']['inputBytes'] ?? 0);
        if (($payload['response']['failureCode'] ?? null) !== null) $state['graphMappingFailures'][] = ['code' => $payload['response']['failureCode'], 'window' => $payload['window']['index'] ?? null];
        $state['graphMappingPromptVersion'] = $payload['response']['promptVersion'] ?? Mapper::PROMPT_VERSION;
        $state['draft'] = \Customs\Domain\DeclarationDate::defaults($state['draft'], $this->store->now());
        $state = (new \Customs\Domain\TariffDefaults())->apply($state);
        $state['mappingProgress'][$job['fingerprint']] = $payload['cursor'];
        if (!($payload['isContextWindow'] ?? false)) {
            $covered = $state['mappingCompletedSourceIds'][$job['fingerprint']] ?? [];
            $state['mappingCompletedSourceIds'][$job['fingerprint']] = array_values(array_unique([...$covered, ...($payload['window']['sourceIds'] ?? [])]));
        }
        $state['phase'] = $payload['more'] ? 'recognizing' : 'reviewing';
        $state['graphMappingRunComplete'] = !$payload['more'];
        if (($payload['servedContextRequests'] ?? []) !== []) {
            $served = [];
            foreach ($payload['servedContextRequests'] as $request) foreach ($request['sourceIds'] ?? [] as $id) $served[$id] = true;
            $state['mappingContextRequests'] = array_values(array_filter($state['mappingContextRequests'] ?? [], static fn(array $request): bool => array_diff($request['sourceIds'] ?? [], array_keys($served)) !== []));
            $state['mappingContextRequestsServed'] = ($state['mappingContextRequestsServed'] ?? 0) + 1;
        }
        $requestedIds = [];
        foreach ($state['mappingContextRequests'] ?? [] as $request) foreach ($request['sourceIds'] ?? [] as $id) $requestedIds[$id] = true;
        foreach ($state['graphMapping']['contextRequests'] ?? [] as $request) {
            if (($state['mappingContextRequestsServed'] ?? 0) >= $this->config->get('mapping_max_context_requests')) break;
            $ids = array_values(array_filter($request['sourceIds'] ?? [], static fn(string $id): bool => !isset($requestedIds[$id])));
            if ($ids === []) continue;
            $state['mappingContextRequests'][] = ['graphId' => $request['graphId'], 'itemKey' => $request['itemKey'], 'sourceIds' => $ids, 'reason' => $request['reason']];
            foreach ($ids as $id) $requestedIds[$id] = true;
        }
        if (($state['mappingContextRequestsServed'] ?? 0) >= $this->config->get('mapping_max_context_requests') && ($state['graphMapping']['contextRequests'] ?? []) !== []) {
            $state['graphMapping']['warnings'][] = 'Запрос дополнительного контекста не выполнен: достигнут предел запросов.';
        }
        $state['mappingWindowProgress'] = ['completed' => $payload['cursor'], 'lastWindow' => $payload['window'], 'contextRequestsServed' => $state['mappingContextRequestsServed'] ?? 0, 'calls' => $state['graphMappingCalls'], 'repairs' => $state['graphMappingRepairs'], 'inputBytes' => $state['graphMappingInputBytes']];
        if (($state['mappingContextRequests'] ?? []) !== [] && !$payload['more']) $state['graphMapping']['warnings'][] = 'Запрос дополнительного контекста не обработан: достигнут лимит запросов.';
        $state['graphMappingResults'] = (new GraphMappingResultStore())->project($state);
        return $state;
    }

    private function addUsage(array $current, array $additional): array
    {
        foreach ($additional as $key => $value) if (is_numeric($value)) $current[$key] = ($current[$key] ?? 0) + $value;
        return $current;
    }

    private function knownMappingContext(array $state): array
    {
        $knownGoods = [];
        foreach ($state['draft']['goods'] ?? [] as $goods) if (is_string($goods['id'] ?? null) && is_string($goods['description'] ?? null) && $goods['description'] !== '') {
            $knownGoods[$goods['id']] = ['key' => $goods['id'], 'description' => $goods['description'], 'commodityCode' => $goods['commodityCode'] ?? null];
        }
        $knownDocumentRecords = [];
        foreach ($state['draft']['documents'] ?? [] as $document) if (is_string($document['id'] ?? null)) {
            $knownDocumentRecords[$document['id']] = ['key' => $document['id'], 'values' => []];
            foreach (['kind' => 'document.kind', 'name' => 'document.name', 'number' => 'document.number', 'date' => 'document.date'] as $key => $fieldId) {
                if (($document[$key] ?? null) !== null && $document[$key] !== '') $knownDocumentRecords[$document['id']]['values'][] = ['fieldId' => $fieldId, 'value' => $document[$key]];
            }
        }
        foreach ($state['graphMappingParts'] ?? [] as $previous) {
            foreach ($previous['items'] ?? [] as $item) if (is_string($item['itemKey'] ?? null) && is_string($item['description'] ?? null)) {
                $knownGoods[$item['itemKey']] = ['key' => $item['itemKey'], 'description' => $item['description'], 'commodityCode' => null];
            }
            foreach ($previous['documentRecords'] ?? [] as $record) if (is_string($record['recordKey'] ?? null)) {
                $knownDocumentRecords[$record['recordKey']] ??= ['key' => $record['recordKey'], 'values' => []];
                foreach ($record['values'] ?? [] as $value) if (isset($value['fieldId'], $value['normalizedValue'])) {
                    $identity = $value['fieldId'] . "\0" . (string)$value['normalizedValue'];
                    $knownDocumentRecords[$record['recordKey']]['values'][$identity] = ['fieldId' => $value['fieldId'], 'value' => $value['normalizedValue']];
                }
            }
        }
        foreach ($knownDocumentRecords as &$record) {
            $values = $record['values'];
            if (!array_is_list($values)) $values = array_values($values);
            $record['values'] = $values;
        }
        unset($record);
        return [array_values($knownGoods), array_values($knownDocumentRecords)];
    }

    private function safeMappingError(ApiError $error): array
    {
        $safeDetails = array_intersect_key($error->details, array_flip(['measuredBytes','limitBytes','requiredWindows','limitWindows']));
        return ['code' => $error->errorCode, 'message' => $error->getMessage(), 'details' => $safeDetails];
    }
}

<?php
declare(strict_types=1);

namespace Customs\AI;

use Customs\Config;
use Customs\Domain\Registry;
use Customs\Http\ApiError;
use Customs\Jobs\Queue;
use Customs\Storage\SessionStore;

final class Pipeline
{
    public const MAPPING_PLAN_VERSION='per-document-scopes-v3';
    private Queue $queue;
    private Ocr $ocr;
    private Registry $registry;

    public function __construct(private readonly Config $config, private readonly SessionStore $store, private readonly MistralClient $client)
    {
        $this->queue = new Queue($config, $store);
        $this->ocr = new Ocr($config, $client);
        $this->registry = new Registry();
    }

    public function start(string $sessionId, bool $retry = false): array
    {
        $state = $this->store->read($sessionId);
        if ($state['documents'] === []) throw new ApiError('DOCUMENTS_REQUIRED', 'Сначала приложите документы.');
        foreach ($state['documents'] as $document) {
            $step = $document['extension'] === 'xlsx' ? $document['pages'] : Ocr::CHUNK_PAGES;
            for ($start = 0; $start < $document['pages']; $start += $step) $this->queue->enqueue($sessionId, 'ocr', $document['id'], $retry, $start);
        }
        $this->scheduleMapping($sessionId, $retry);
        return $this->store->read($sessionId);
    }

    public function tick(int $maxJobs = 3, ?string $onlySessionId = null): array
    {
        $handlers = [
            'ocr' => ['run' => $this->ocr->run(...),'apply' => $this->ocr->apply(...)],
            'mapping' => [
                'run' => $this->mapPart(...), 'apply' => $this->applyPart(...),
                'continue' => fn(array $payload): bool => $payload['more'], 'changesData' => true,
            ],
        ];
        $bundle=new \Customs\Export\Bundle((new \Customs\Export\XmlProfiles())->serialize(...),$this->config);
        $handlers['generate']=['run'=>$bundle->run(...),'apply'=>$bundle->apply(...)];
        $result = $this->queue->tick($handlers, $maxJobs, 150, $onlySessionId);
        foreach (glob($this->config->get('runtime_dir') . '/sessions/*', GLOB_ONLYDIR) as $directory) {
            $id = basename($directory);
            if (!preg_match('/^[a-f0-9]{64}$/D', $id)) continue;
            if ($onlySessionId !== null && $id !== $onlySessionId) continue;
            try { if($onlySessionId===null && isset($this->store->read($id)['evaluationCase']))continue; $this->scheduleMapping($id, false); }
            catch (ApiError $error) { if ($error->errorCode !== 'SESSION_EXPIRED') throw $error; }
        }
        return $result;
    }

    private function scheduleMapping(string $sessionId, bool $retry): void
    {
        $state = $this->store->read($sessionId);
        if ($state['documents'] === []) return;
        foreach ($state['documents'] as $document) if ($document['status'] !== 'recognized') return;
        $this->queue->enqueue($sessionId, 'mapping', null, $retry);
    }

    private function sources(array $state, string $directory): array
    {
        $sources = [];
        foreach ($state['documents'] as $document) {
            $source = ['documentId' => $document['id'],'name' => $document['name'],'pages' => [],'sheets' => []];
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
            if ($source['pages'] === [] && $source['sheets'] === []) throw new ApiError('OCR_REQUIRED', 'Не все документы имеют сохранённый OCR.');
            $sources[] = $source;
        }
        return $sources;
    }

    private function plan(array $sources): array
    {
        $chunks = []; $current = []; $bytes = 0;
        foreach ($sources as $source) {
            $parts = [];
            foreach ($source['pages'] as $page) foreach ($page['blocks'] as $block) $parts[] = ['documentId' => $source['documentId'],'name' => $source['name'],'pages' => [['page' => $page['page'],'blocks' => [$block]]], 'sheets' => []];
            foreach ($source['sheets'] as $sheet) {
                $cells = [];
                foreach ($sheet['cells'] as $address => $cell) if ($cell['value'] !== null && $cell['value'] !== '') $cells[$address] = $cell;
                foreach (array_chunk($cells, 600, true) as $cellChunk) $parts[] = ['documentId' => $source['documentId'],'name' => $source['name'],'pages' => [],'sheets' => [['name' => $sheet['name'],'cells' => $cellChunk]]];
            }
            foreach ($parts as $part) {
                $length = strlen(json_encode($part, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                if ($length > 400_000) throw new ApiError('MAPPING_BLOCK_LIMIT', 'Слишком большой блок документа для сопоставления.');
                if ($bytes + $length > 400_000 && $current !== []) { $chunks[] = $this->combineSources($current); $current = []; $bytes = 0; }
                $current[] = $part; $bytes += $length;
            }
            // Map each document with all factual fields together. Mixing unrelated contracts,
            // invoices and payment orders in a long context caused unreliable role/citation choices.
            if ($current !== []) { $chunks[] = $this->combineSources($current); $current = []; $bytes = 0; }
        }
        if ($current !== []) $chunks[] = $this->combineSources($current);
        if ($chunks === []) return [];
        $plan = [];
        foreach ($chunks as $chunk) foreach ([['declaration','party','transport'],['goods','cost'],['document']] as $scopes) {
            $fields=array_filter($this->registry->extractable(),fn(array $f):bool=>in_array($f['scope'],$scopes,true));
            if($fields!==[])$plan[]=['sources'=>$chunk,'fieldIds'=>array_keys($fields)];
        }
        return $plan;
    }

    private function combineSources(array $parts): array
    {
        $result = [];
        foreach ($parts as $part) {
            $id = $part['documentId']; $result[$id] ??= ['documentId' => $id,'name' => $part['name'],'pages' => [],'sheets' => []];
            foreach ($part['pages'] as $page) {
                $number = $page['page'];
                $result[$id]['pages'][$number] ??= ['page' => $number,'blocks' => []];
                array_push($result[$id]['pages'][$number]['blocks'], ...$page['blocks']);
            }
            foreach ($part['sheets'] as $sheet) {
                $name = $sheet['name'];
                $result[$id]['sheets'][$name] ??= ['name' => $name,'cells' => []];
                $result[$id]['sheets'][$name]['cells'] += $sheet['cells'];
            }
        }
        foreach ($result as &$source) { $source['pages'] = array_values($source['pages']); $source['sheets'] = array_values($source['sheets']); }
        unset($source);
        return array_values($result);
    }

    private function mapPart(array $job, array $state, string $directory): array
    {
        $sources = $this->sources($state, $directory);
        $plan = $this->plan($sources);
        $cursor = $state['mappingProgress'][$job['fingerprint']] ?? 0;
        if ($cursor >= count($plan)) return ['response' => ['entities' => [],'candidates' => [],'warnings' => []], 'sources' => $sources, 'cursor' => $cursor, 'more' => false];
        $part = $plan[$cursor];
        $cache = $directory . '/ocr/mapping-' . substr($job['fingerprint'], 0, 16) . '-' . $cursor . '.json';
        if (is_file($cache)) $response = json_decode(file_get_contents($cache), true, 128, JSON_THROW_ON_ERROR);
        else {
            $response = (new Mapper($this->client, $this->registry))->request($part['sources'], $part['fieldIds']);
            $json = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $used=0;
            foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->config->get('runtime_dir'),\FilesystemIterator::SKIP_DOTS)) as $file)if($file->isFile()&&!$file->isLink())$used+=$file->getSize();
            if($used+strlen($json)>$this->config->get('max_runtime_bytes'))throw new ApiError('STORAGE_LIMIT','Недостаточно места для сопоставления.',503);
            if (@file_put_contents($cache . '.tmp', $json, LOCK_EX) !== strlen($json) || !@rename($cache . '.tmp', $cache)) throw new ApiError('STORAGE_ERROR', 'Не удалось сохранить сопоставление.', 503);
        }
        return ['response' => $response,'sources' => $sources,'cursor' => $cursor + 1,'more' => $cursor + 1 < count($plan)];
    }

    private function applyPart(array $state, array $payload, array $job): array
    {
        $state = (new CandidateMerger($this->registry))->merge($state, $payload['response'], $payload['sources']);
        $state['mappingProgress'][$job['fingerprint']] = $payload['cursor'];
        $state['phase'] = $payload['more'] ? 'recognizing' : 'reviewing';
        return $state;
    }
}

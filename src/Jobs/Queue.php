<?php
declare(strict_types=1);

namespace Customs\Jobs;

use Customs\Config;
use Customs\Http\ApiError;
use Customs\Storage\SessionStore;

final class Queue
{
    public function __construct(private readonly Config $config, private readonly SessionStore $store) {}

    public function enqueue(string $sessionId, string $kind, ?string $documentId = null, bool $retry = false, int $chunkStart = 0): array
    {
        if (!in_array($kind, ['ocr','mapping','generate'], true)) throw new ApiError('JOB_KIND', 'Неизвестный этап обработки.');
        if($kind==='generate')throw new ApiError('EXPORT_DISABLED','Формирование PDF и XML отключено в профиле подготовки 54 граф.',409);
        return $this->store->mutate($sessionId, null, function (array $state) use ($kind, $documentId, $retry, $chunkStart): array {
            $fingerprint = $this->fingerprint($state, $kind, $documentId, $chunkStart);
            foreach ($state['jobs'] as $job) {
                if ($job['kind'] === $kind && $job['fingerprint'] === $fingerprint && (!in_array($job['status'], ['failed','cancelled'], true) || !$retry)) return $state;
            }
            $id = bin2hex(random_bytes(16));
            $state['jobs'][$id] = [
                'id' => $id, 'kind' => $kind, 'documentId' => $documentId, 'fingerprint' => $fingerprint, 'chunkStart' => $chunkStart,
                'dataRevision' => $state['dataRevision'], 'status' => 'queued', 'attempts' => 0,
                'createdAt' => $this->store->now(), 'nextAttemptAt' => $this->store->now(), 'leaseUntil' => null,
                'leaseOwner' => null, 'error' => null,
            ];
            $state['error'] = null;
            $state['phase'] = $kind === 'generate' ? 'generating' : 'recognizing';
            return $state;
        }, false);
    }

    public function fingerprint(array $state, string $kind, ?string $documentId, int $chunkStart = 0): string
    {
        $parts = [$kind, $this->config->get('ocr_model')];
        if ($kind !== 'ocr') {
            $parts[] = $state['registryVersion'];
            $parts[] = $this->config->get('mapping_model');
            $parts[] = hash('sha256',json_encode((new \Customs\Domain\GraphMappingContract())->data(),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
            $parts[] = hash_file('sha256', dirname(__DIR__, 2) . '/resources/prompts/graph-mapping.v2.txt');
            $parts[] = hash_file('sha256', dirname(__DIR__, 2) . '/resources/schemas/graph-mapping.v2.json');
            $parts[] = \Customs\AI\Pipeline::MAPPING_PLAN_VERSION;
            $parts[] = \Customs\AI\Mapper::PROMPT_VERSION;
            $parts[] = (new \Customs\Domain\TnvedCatalogue())->version();
            $parts[] = \Customs\Domain\TariffDefaults::VERSION;
            $parts[] = hash_file('sha256',dirname(__DIR__,2).'/resources/registry/graphs.im40.v2.json');
            $parts[] = hash_file('sha256',dirname(__DIR__,2).'/resources/registry/source-roles.v2.json');
            $parts[] = (new \Customs\Domain\Classifiers())->version();
        }
        if ($kind === 'ocr') {
            $document = $state['documents'][$documentId] ?? null;
            if ($document === null) throw new ApiError('DOCUMENT_NOT_FOUND', 'Документ не найден.', 404);
            $parts[] = $documentId; $parts[] = $document['hash']; $parts[] = $chunkStart;
        } else {
            $documents = $state['documents']; ksort($documents);
            foreach ($documents as $document) { $parts[] = [$document['id'],$document['hash'],$document['documentKind']??null,$document['classifierVersion']??null,$document['status']]; }
        }
        if ($kind === 'generate') { $parts[] = $state['dataRevision']; $parts[] = $state['confirmed']['hash'] ?? null; }
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /** OCR may supply runBatch(jobs,state,directory,complete); other handlers remain serial. */
    public function tick(array $handlers, int $maxJobs = 1, int $maxSeconds = 180, ?string $onlySessionId = null, ?callable $afterBatch = null): array
    {
        $lock = fopen($this->config->get('runtime_dir') . '/heavy.lock', 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            return ['status' => 'busy', 'processed' => 0];
        }
        $processed = 0; $started = hrtime(true);
        try {
            do {
                $progress = false;
                foreach ($onlySessionId === null ? $this->sessionIds() : [$onlySessionId] as $sessionId) {
                    if ($processed >= $maxJobs || (hrtime(true) - $started) / 1e9 >= $maxSeconds) break;
                    $claimed = [];
                    $limit = min($maxJobs - $processed, $this->config->get('max_files'));
                    try {
                        $state = $this->store->mutate($sessionId, null, function (array $state) use (&$claimed, $handlers, $onlySessionId, $limit): array {
                            if($onlySessionId===null && isset($state['evaluationCase']))return $state;
                            // The first chunk of every document starts before any document's next chunk.
                            $ids = array_keys($state['jobs']);
                            usort($ids, fn($a, $b): int => ($state['jobs'][$a]['chunkStart'] ?? 0) <=> ($state['jobs'][$b]['chunkStart'] ?? 0));
                            foreach ($ids as $id) {
                                $job = &$state['jobs'][$id];
                                if (!isset($handlers[$job['kind']])) continue;
                                if ($job['status'] === 'running' && $job['leaseUntil'] <= $this->store->now()) {
                                    $job['status'] = $job['attempts'] >= $this->config->get('job_attempts') ? 'failed' : 'queued';
                                    $job['error'] = ['code' => 'JOB_INTERRUPTED','message' => 'Предыдущая обработка прервана.'];
                                    if ($job['status'] === 'failed') {
                                        $state['error'] = $job['error'];
                                        if($job['kind']==='ocr'&&isset($state['documents'][$job['documentId']])){$state['documents'][$job['documentId']]['status']='error';$state['documents'][$job['documentId']]['error']=$job['error'];}
                                        if($job['kind']==='mapping')$state['phase']='reviewing';
                                    }
                                }
                                if ($job['status'] !== 'queued' || $job['nextAttemptAt'] > $this->store->now()) continue;
                                try { $currentFingerprint = $this->fingerprint($state, $job['kind'], $job['documentId'], $job['chunkStart'] ?? 0); }
                                catch (ApiError) { $job['status'] = 'cancelled'; continue; }
                                if ($currentFingerprint !== $job['fingerprint']) { $job['status'] = 'cancelled'; continue; }
                                if ($claimed !== [] && ($job['kind'] !== 'ocr' || $claimed[0]['kind'] !== 'ocr')) continue;
                                $job['status'] = 'running'; $job['attempts']++;
                                $job['leaseOwner'] = bin2hex(random_bytes(16));
                                $job['leaseUntil'] = $this->store->now() + $this->config->get('job_lease_seconds');
                                if ($job['kind'] === 'ocr' && isset($state['documents'][$job['documentId']])) $state['documents'][$job['documentId']]['status'] = 'recognizing';
                                $claimed[] = $job;
                                if (!isset($handlers[$job['kind']]['runBatch']) || count($claimed) >= $limit) break;
                            }
                            unset($job);
                            return $state;
                        }, false);
                    } catch (ApiError $error) {
                        if (in_array($error->errorCode, ['SESSION_EXPIRED','SESSION_REQUIRED'], true)) continue;
                        throw $error;
                    }
                    if ($claimed === []) continue;
                    $processed += count($claimed); $progress = true;
                    $handler = $handlers[$claimed[0]['kind']];
                    $pending = array_column($claimed, null, 'id');
                    $complete = function (array $job, ?array $payload, ?\Throwable $error = null) use ($sessionId, $handler, &$pending): void {
                        if (!isset($pending[$job['id']])) return;
                        unset($pending[$job['id']]);
                        $this->finish($sessionId, $job, $handler, $payload, $error);
                    };
                    try {
                        if (isset($handler['runBatch'])) ($handler['runBatch'])($claimed, $state, $this->store->directory($sessionId), $complete);
                        else $complete($claimed[0], ($handler['run'])($claimed[0], $state, $this->store->directory($sessionId)));
                    } catch (\Throwable $error) {
                        foreach ($pending as $job) $complete($job, null, $error);
                    }
                    if ($afterBatch !== null) $afterBatch($sessionId);
                }
            } while ($progress && $processed < $maxJobs && (hrtime(true) - $started) / 1e9 < $maxSeconds);
            return ['status' => 'ok', 'processed' => $processed];
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function finish(string $sessionId, array $claimed, array $handler, ?array $payload, ?\Throwable $failure): void
    {
        try {
            if ($failure !== null) throw $failure;
            $this->store->mutate($sessionId, null, function (array $current) use ($claimed, $payload, $handler): array {
                $job = $current['jobs'][$claimed['id']] ?? null;
                if ($job === null || $job['status'] !== 'running' || $job['leaseOwner'] !== $claimed['leaseOwner']) return $current;
                if ($this->fingerprint($current, $job['kind'], $job['documentId'], $job['chunkStart'] ?? 0) !== $job['fingerprint']) {
                    $current['jobs'][$job['id']]['status'] = 'cancelled';
                    return $current;
                }
                $current = ($handler['apply'])($current, $payload, $job);
                $continued = isset($handler['continue']) && ($handler['continue'])($payload);
                $current['jobs'][$job['id']]['status'] = $continued ? 'queued' : 'done';
                if ($continued) {
                    $current['jobs'][$job['id']]['completedParts'] = ($job['completedParts'] ?? 0) + 1;
                    $current['jobs'][$job['id']]['attempts'] = 0;
                }
                $current['jobs'][$job['id']]['error'] = null;
                $current['jobs'][$job['id']]['leaseOwner'] = null;
                $current['jobs'][$job['id']]['leaseUntil'] = null;
                return $current;
            }, (bool) ($handler['changesData'] ?? false));
        } catch (\Throwable $error) {
            if ($error instanceof ApiError && in_array($error->errorCode, ['SESSION_EXPIRED', 'SESSION_REQUIRED'], true)) return;
            $safe = $error instanceof ApiError ? ['code' => $error->errorCode, 'message' => $error->getMessage()] : ['code' => 'JOB_FAILED','message' => 'Не удалось завершить этап обработки.'];
            if ($error instanceof ApiError && in_array($error->errorCode, ['MAPPING_CONTEXT_LIMIT','MAPPING_WINDOW_LIMIT'], true)) {
                $safe['details'] = array_intersect_key($error->details, array_flip(['measuredBytes','limitBytes','requiredWindows','limitWindows']));
            }
            try {
                $this->store->mutate($sessionId, null, function (array $current) use ($claimed, $safe, $error): array {
                    $job = $current['jobs'][$claimed['id']] ?? null;
                    if ($job === null || $job['leaseOwner'] !== $claimed['leaseOwner']) return $current;
                    $retryable = in_array($safe['code'], ['MISTRAL_RATE_LIMIT','MISTRAL_TEMPORARY','NETWORK_ERROR'], true);
                    $retry = $retryable && $job['attempts'] < $this->config->get('job_attempts');
                    $delay = min(300, 10 * (2 ** min($job['attempts'], 5))) + random_int(0, 5);
                    if ($error instanceof ApiError && isset($error->details['retryAfter'])) $delay = max($delay, min(300, (int) $error->details['retryAfter']));
                    $job['status'] = $retry ? 'queued' : 'failed';
                    $job['nextAttemptAt'] = $this->store->now() + $delay;
                    $job['leaseOwner'] = null; $job['leaseUntil'] = null; $job['error'] = $safe;
                    $current['jobs'][$job['id']] = $job;
                    if ($job['kind'] === 'ocr' && isset($current['documents'][$job['documentId']])) {
                        $current['documents'][$job['documentId']]['status'] = $retry ? 'recognizing' : 'error';
                        $current['documents'][$job['documentId']]['error'] = $safe;
                    }
                    if (!$retry) $current['error'] = $safe;
                    if(!$retry&&$job['kind']==='mapping')$current['phase']='reviewing';
                    return $current;
                }, false);
            } catch (ApiError $storageError) {
                if ($storageError->errorCode !== 'SESSION_EXPIRED') throw $storageError;
            }
        }
    }

    private function sessionIds(): array
    {
        $ids = [];
        foreach (glob($this->config->get('runtime_dir') . '/sessions/*', GLOB_ONLYDIR) as $directory) {
            $id = basename($directory);
            if (preg_match('/^[a-f0-9]{64}$/D', $id) && !is_link($directory)) $ids[] = $id;
        }
        return $ids;
    }
}

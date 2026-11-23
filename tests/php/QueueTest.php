<?php
declare(strict_types=1);

use Customs\Config;
use Customs\Jobs\Queue;
use Customs\Storage\SessionStore;

function queueFixture(?Closure $clock = null): array
{
    $config = new Config(['runtime_dir' => sys_get_temp_dir() . '/customs-test-' . bin2hex(random_bytes(8))]);
    $store = new SessionStore($config, $clock); $session = $store->create();
    return [$config, $store, $session, new Queue($config, $store)];
}

test('one worker claims all documents, saves each completion, and retries only the failed request', function (): void {
    $now = 1000;
    [$config, $store, $session, $queue] = queueFixture(function () use (&$now): int { return $now; });
    $id = $session['state']['id'];
    $store->mutate($id, null, function (array $state): array {
        for ($i = 0; $i < 20; $i++) $state['documents']['doc-' . $i] = ['id' => 'doc-' . $i, 'hash' => hash('sha256', (string) $i), 'status' => 'pending'];
        return $state;
    });
    foreach ($store->read($id)['documents'] as $document) $queue->enqueue($id, 'ocr', $document['id']);
    $calls = []; $batches = [];
    $handlers = ['ocr' => [
        'runBatch' => function (array $jobs, array $state, string $directory, callable $complete) use ($store, $queue, $id, &$calls, &$batches): void {
            $batches[] = count($jobs);
            same(count($jobs), count(array_filter($store->read($id)['jobs'], fn($job) => $job['status'] === 'running')));
            same(['status' => 'busy', 'processed' => 0], $queue->tick([]));
            foreach (array_reverse($jobs) as $job) {
                $documentId = $job['documentId'];
                $calls[$documentId] = ($calls[$documentId] ?? 0) + 1;
                if ($documentId === 'doc-7' && $calls[$documentId] === 1) {
                    $complete($job, null, new Customs\Http\ApiError('MISTRAL_RATE_LIMIT', 'Подождите.', 429, ['retryAfter' => 60]));
                } else {
                    $complete($job, ['documentId' => $documentId]);
                    same('done', $store->read($id)['jobs'][$job['id']]['status']);
                }
                if ($documentId === 'doc-19') same('running', array_values($store->read($id)['jobs'])[0]['status']);
            }
        },
        'apply' => function (array $state, array $payload): array {
            $state['documents'][$payload['documentId']]['status'] = 'recognized';
            return $state;
        },
    ]];
    same(20, $queue->tick($handlers, 100)['processed']);
    $state = $store->read($id);
    same(19, count(array_filter($state['jobs'], fn($job) => $job['status'] === 'done')));
    same(null, $state['error']);
    same(0, $queue->tick($handlers, 100)['processed']);
    $now = max(array_column($state['jobs'], 'nextAttemptAt'));
    same(1, $queue->tick($handlers, 100)['processed']);
    same([20, 1], $batches);
    same(2, $calls['doc-7']);
    foreach ($calls as $documentId => $count) if ($documentId !== 'doc-7') same(1, $count);
    same(20, count(array_filter($store->read($id)['jobs'], fn($job) => $job['status'] === 'done')));
});

test('a long document does not crowd out the first chunks of other documents', function (): void {
    [$config, $store, $session, $queue] = queueFixture(); $id = $session['state']['id'];
    $store->mutate($id, null, function (array $state): array {
        for ($i = 0; $i < 20; $i++) $state['documents']['doc-' . $i] = ['id' => 'doc-' . $i, 'hash' => hash('sha256', (string) $i), 'status' => 'pending'];
        return $state;
    });
    $queue->enqueue($id, 'ocr', 'doc-0', false, 0);
    $queue->enqueue($id, 'ocr', 'doc-0', false, 8);
    for ($i = 1; $i < 20; $i++) $queue->enqueue($id, 'ocr', 'doc-' . $i);
    $batches = [];
    $handlers = ['ocr' => ['runBatch' => function (array $jobs, array $state, string $directory, callable $complete) use (&$batches): void {
        $batches[] = array_column($jobs, 'chunkStart');
        foreach ($jobs as $job) $complete($job, []);
    }, 'apply' => fn(array $state): array => $state]];
    same(21, $queue->tick($handlers, 100)['processed']);
    same([array_fill(0, 20, 0), [8]], $batches);
});

test('duplicate starts and polling do not duplicate paid work', function (): void {
    [$config,$store,$session,$queue] = queueFixture(); $id = $session['state']['id'];
    $queue->enqueue($id, 'mapping'); $queue->enqueue($id, 'mapping');
    same(1, count($store->read($id)['jobs']));
    $calls = 0;
    $handler = ['mapping' => ['run' => function () use (&$calls): array { $calls++; return ['ok'=>true]; }, 'apply' => fn(array $s, array $p): array => $s]];
    $queue->tick($handler); $queue->tick($handler);
    same(1, $calls);
    same('done', array_values($store->read($id)['jobs'])[0]['status']);
});

test('interrupted lease recovers and bounded retries do not loop on access errors', function (): void {
    $now = 1000;
    [$config,$store,$session,$queue] = queueFixture(function () use (&$now): int { return $now; }); $id=$session['state']['id'];
    $queue->enqueue($id,'mapping');
    $store->mutate($id,null,function(array $s): array { $key=array_key_first($s['jobs']); $s['jobs'][$key]['status']='running'; $s['jobs'][$key]['leaseUntil']=999; $s['jobs'][$key]['attempts']=1; return $s; },false);
    $calls=0;
    $handler=['mapping'=>['run'=>function() use (&$calls): never { $calls++; throw new Customs\Http\ApiError('MISTRAL_RATE_LIMIT','Подождите.',429); },'apply'=>fn(array $s):array=>$s]];
    $queue->tick($handler);
    $job=array_values($store->read($id)['jobs'])[0]; same(2,$job['attempts']); same('queued',$job['status']);
    $now=$job['nextAttemptAt']; $queue->tick($handler);
    same('failed',array_values($store->read($id)['jobs'])[0]['status']); same(2,$calls);
    $queue->enqueue($id,'mapping',null,true);
    $queue->tick(['mapping'=>['run'=>fn()=>throw new Customs\Http\ApiError('MISTRAL_AUTH','Нужен ключ.',401),'apply'=>fn(array $s):array=>$s]]);
    same('failed',array_values($store->read($id)['jobs'])[1]['status']);
});

test('the graph profile rejects export jobs without scheduling work',function():void {
    [$config,$store,$session,$queue]=queueFixture();$id=$session['state']['id'];
    errorCode('EXPORT_DISABLED',fn()=>$queue->enqueue($id,'generate'));same([], $store->read($id)['jobs']);
});

test('worker releases the session lock while an external stage runs', function (): void {
    [$config,$store,$session,$queue]=queueFixture(); $id=$session['state']['id'];
    $queue->enqueue($id,'mapping');
    $queue->tick(['mapping'=>['run'=>function() use($store,$id):array { return ['revision'=>$store->read($id)['dataRevision']]; },'apply'=>fn(array $s):array=>$s]]);
    same('done',array_values($store->read($id)['jobs'])[0]['status']);
});

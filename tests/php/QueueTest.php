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

test('late generation never overwrites a newer revision', function (): void {
    [$config,$store,$session,$queue] = queueFixture(); $id=$session['state']['id'];
    $queue->enqueue($id,'generate');
    $queue->tick(['generate'=>['run'=>function() use($store,$id):array { $store->mutate($id,0,fn(array $s):array=>$s); return ['old'=>true]; },'apply'=>function(array $s):array { $s['artifactSet']=['revision'=>0]; return $s; }]]);
    same(null,$store->read($id)['artifactSet']);
    same('cancelled',array_values($store->read($id)['jobs'])[0]['status']);
});

test('worker releases the session lock while an external stage runs', function (): void {
    [$config,$store,$session,$queue]=queueFixture(); $id=$session['state']['id'];
    $queue->enqueue($id,'mapping');
    $queue->tick(['mapping'=>['run'=>function() use($store,$id):array { return ['revision'=>$store->read($id)['dataRevision']]; },'apply'=>fn(array $s):array=>$s]]);
    same('done',array_values($store->read($id)['jobs'])[0]['status']);
});

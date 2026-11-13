<?php
declare(strict_types=1);

use Customs\Config;
use Customs\Storage\SessionStore;

test('independent session tokens isolate state and reject guessed ids', function (): void {
    $store = sessionFixture();
    $a = $store->create(); $b = $store->create();
    truth($a['token'] !== $b['token']);
    truth($a['token'] !== $a['state']['id']);
    $store->mutate($a['state']['id'], 0, function (array $state): array { $state['draft']['declaration']['test'] = 'A'; return $state; });
    same('A', $store->fromToken($a['token'])['draft']['declaration']['test']);
    truth(!isset($store->fromToken($b['token'])['draft']['declaration']['test']));
    errorCode('SESSION_EXPIRED', fn() => $store->fromToken($a['state']['id']));
    errorCode('SESSION_REQUIRED', fn() => $store->fromToken('../../state.json'));
});

test('stale revision cannot overwrite newer edits and changes invalidate outputs', function (): void {
    $store = sessionFixture(); $session = $store->create(); $id = $session['state']['id'];
    $store->mutate($id, 0, function (array $s): array { $s['calculation']=['revision'=>0]; $s['confirmed']=['revision'=>0]; $s['artifactSet']=['revision'=>0]; return $s; }, false);
    $updated = $store->mutate($id, 0, function (array $s): array { $s['draft']['declaration']['deliveryPlace']='A'; return $s; });
    same(1, $updated['dataRevision']);
    same(null, $updated['calculation']); same(null, $updated['confirmed']); same(null, $updated['artifactSet']);
    errorCode('REVISION_CONFLICT', fn() => $store->mutate($id, 0, fn(array $s): array => $s));
    same('A', $store->read($id)['draft']['declaration']['deliveryPlace']);
    same([], glob($store->directory($id) . '/*.tmp'));
});

test('polling does not extend TTL and late worker mutations are rejected', function (): void {
    $now = 1000; $store = sessionFixture(function () use (&$now): int { return $now; });
    $session = $store->create(); $id = $session['state']['id'];
    $now = 8199; same(8200, $store->read($id)['expiresAt']);
    $now = 8200;
    errorCode('SESSION_EXPIRED', fn() => $store->fromToken($session['token']));
    errorCode('SESSION_EXPIRED', fn() => $store->mutate($id, 0, fn(array $s): array => $s));
});

test('public session view excludes storage paths, access secret and worker internals', function (): void {
    $store = sessionFixture(); $session = $store->create(); $state = $session['state'];
    $state['documents'][] = ['id'=>'d1','name'=>'invoice.pdf','storagePath'=>'private/path','hash'=>'internal'];
    $state['jobs'][] = ['id'=>'j1','kind'=>'ocr','status'=>'queued','leaseOwner'=>'secret-internal'];
    $view = $store->publicView($state);
    truth(!isset($view['id']) && !isset($view['token']));
    truth(!isset($view['documents'][0]['storagePath']));
    truth(!isset($view['jobs'][0]['leaseOwner']));
});

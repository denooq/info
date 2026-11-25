<?php
declare(strict_types=1);

use Customs\AI\MistralClient;
use Customs\AI\Pipeline;
use Customs\Config;
use Customs\Storage\SessionStore;

function mappingRetryFixture(array $texts): array
{
    $config = new Config(['runtime_dir' => sys_get_temp_dir() . '/customs-mapping-retry-' . bin2hex(random_bytes(8))]);
    $store = new SessionStore($config);
    $created = $store->create();
    $id = $created['state']['id'];
    $directory = $store->directory($id);
    $documents = [];
    foreach ($texts as $index => $text) {
        $documentId = str_pad(dechex($index + 1), 32, '0', STR_PAD_LEFT);
        $bytes = 'fixture pdf ' . $index;
        $hash = hash('sha256', $bytes);
        $storageName = $documentId . '.pdf';
        file_put_contents($directory . '/documents/' . $storageName, $bytes);
        $documents[$documentId] = [
            'id' => $documentId, 'name' => 'document-' . $index . '.pdf', 'mime' => 'application/pdf', 'size' => strlen($bytes),
            'pages' => 1, 'status' => 'recognized', 'extension' => 'pdf', 'storageName' => $storageName,
            'hash' => $hash, 'documentKind' => '04021', 'classifierVersion' => 'fixture-v1', 'recognizedPages' => [0],
        ];
        $blockId = 'p1-b0';
        $cache = [
            'sourceHash' => $hash, 'ocrModel' => $config->get('ocr_model'),
            'result' => ['kind' => 'document', 'pages' => [['page' => 1, 'language' => 'en', 'blocks' => [['id' => $blockId, 'text' => $text, 'bbox' => null]], 'tables' => []]], 'sheets' => []],
        ];
        file_put_contents($directory . '/ocr/' . $documentId . '-fixture.json', json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    $state = $store->mutate($id, 0, static function (array $state) use ($documents): array { $state['documents'] = $documents; return $state; }, false);
    $client = new MistralClient($config, static fn() => throw new RuntimeException('Mapping preflight must not call Mistral.'));
    return [$config, $store, $state, new Pipeline($config, $store, $client)];
}

test('mapping retry queues only mapping when successful OCR is already cached', function (): void {
    [$config, $store, $state, $pipeline] = mappingRetryFixture(['Invoice number INV-1; USD 10']);
    $id = $state['id'];
    $cacheFiles = glob($store->directory($id) . '/ocr/*.json');
    same(1, count($cacheFiles));
    $before = hash_file('sha256', $cacheFiles[0]);

    $pipeline->start($id, true, true);

    $after = $store->read($id);
    same(['mapping'], array_values(array_unique(array_column($after['jobs'], 'kind'))));
    same('queued', array_values($after['jobs'])[0]['status']);
    same('recognizing', $after['phase']);
    same($before, hash_file('sha256', $cacheFiles[0]));
});

test('four-document mapping context failure is reported with safe serialized-size diagnostics', function (): void {
    [$config, $store, $state, $pipeline] = mappingRetryFixture([
        'Invoice number INV-1', 'Contract number CON-1', 'CMR gross mass 27860 kg', str_repeat('large source text ', 15000),
    ]);

    $pipeline->start($state['id'], true, true);

    $after = $store->read($state['id']);
    same('reviewing', $after['phase']);
    same('MAPPING_CONTEXT_LIMIT', $after['error']['code']);
    truth($after['error']['details']['measuredBytes'] > $after['error']['details']['limitBytes']);
    same(180000, $after['error']['details']['limitBytes']);
    same([], $after['jobs']);
});

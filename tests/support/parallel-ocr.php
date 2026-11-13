<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$config = new Customs\Config(['runtime_dir' => $argv[2], 'http_timeout' => 8, 'max_runtime_bytes' => isset($argv[4]) ? (int) $argv[4] : 256 * 1024 * 1024]);
$requests = [];
for ($index = 0; $index < 20; $index++) {
    $requests['document-' . $index] = [
        'path' => $argv[3],
        'payload' => ['model' => 'offline-test', 'document' => ['type' => 'document_url', 'document_url' => 'data:application/pdf;base64,'], 'pages' => [$index]],
    ];
}
$transport = new Customs\AI\ParallelOcrTransport($config);
$transport->send($argv[1], $requests, ['Content-Type: application/json'], $argv[2], static function ($id, $response, $error): void {
    echo json_encode(['id' => $id, 'status' => $response['status'] ?? null, 'error' => $error?->getMessage(), 'retryAfter' => $response['headers']['retry-after'] ?? null], JSON_THROW_ON_ERROR) . PHP_EOL;
    flush();
});
echo json_encode(['peakMemory' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR) . PHP_EOL;

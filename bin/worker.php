<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $config = Customs\Config::load();
    $store = new Customs\Storage\SessionStore($config);
    $result = (new Customs\AI\Pipeline($config, $store, new Customs\AI\MistralClient($config)))->tick(3);
    echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Worker failed; verify private configuration.\n");
    exit(1);
}

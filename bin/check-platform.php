<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $result = Customs\Platform::check(Customs\Config::load());
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($result['ok'] ? 0 : 1);
} catch (Customs\Http\ApiError $error) {
    echo json_encode(['ok' => false, 'error' => $error->errorCode, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

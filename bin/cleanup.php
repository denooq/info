<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $store = new Customs\Storage\SessionStore(Customs\Config::load());
    $result = $store->cleanupExpired();
    if (!in_array('--quiet', $argv, true)) echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
    if ($result['errors']) fwrite(STDERR, "Cleanup completed with errors; verify private storage permissions.\n");
    exit($result['errors'] ? 1 : 0);
} catch (Throwable) {
    fwrite(STDERR, "Cleanup failed; verify private storage configuration.\n");
    exit(1);
}

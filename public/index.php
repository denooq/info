<?php
declare(strict_types=1);

ini_set('display_errors', '0');
set_error_handler(static function (int $severity): bool {
    if ((error_reporting() & $severity) === 0) return false;
    throw new RuntimeException('Internal operation failed.');
});
require dirname(__DIR__) . '/src/bootstrap.php';

use Customs\Http\ApiError;

try {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' blob:; frame-ancestors 'none'; base-uri 'self'");
    if (preg_match('#^/(?:\.(?!well-known)|config|src|vendor|resources|reference|openspec|tests|bin|runtime)(?:/|$)#i', rawurldecode($path ?? '/'))) {
        throw new ApiError('NOT_FOUND', 'Ресурс не найден.', 404);
    }
    if ($path === '/api/health') {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => true, 'version' => '0.1.0'], JSON_THROW_ON_ERROR);
        return;
    }
    if (str_starts_with($path ?? '', '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $app = new Customs\Http\Application(Customs\Config::load());
        $result=$app->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
        if($result instanceof \Customs\Http\Download){$result->send();return;}
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return;
    }
    $index = __DIR__ . '/assets-app/index.html';
    if (!is_file($index)) {
        http_response_code(503);
        echo 'Frontend ещё не собран. Выполните npm run build.';
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    readfile($index);
} catch (ApiError $e) {
    http_response_code($e->status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'details' => $e->details]], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Не удалось выполнить операцию.']], JSON_UNESCAPED_UNICODE);
}

<?php
declare(strict_types=1);

namespace Customs;

use Customs\Http\ApiError;

final class Config
{
    private array $values;

    public function __construct(array $overrides = [])
    {
        $this->values = array_replace([
            'mistral_api_key' => '',
            'runtime_dir' => sys_get_temp_dir() . '/customs-mvp',
            'session_ttl' => 7200,
            'max_files' => 20,
            'max_file_bytes' => 10 * 1024 * 1024,
            'max_session_bytes' => 50 * 1024 * 1024,
            'max_pages' => 100,
            'max_unpacked_bytes' => 64 * 1024 * 1024,
            'max_runtime_bytes' => 256 * 1024 * 1024,
            'ocr_model' => 'mistral-ocr-4-1',
            'mapping_model' => 'mistral-small-2603',
            'mapping_context_bytes' => 180000,
            'mapping_max_windows' => 12,
            'mapping_max_repairs' => 2,
            'mapping_max_context_requests' => 2,
            'http_timeout' => 120,
            'job_lease_seconds' => 180,
            'job_attempts' => 3,
            'secure_cookie' => true,
        ], $overrides);
        foreach (['session_ttl','max_files','max_file_bytes','max_session_bytes','max_pages','max_unpacked_bytes','max_runtime_bytes','http_timeout','job_lease_seconds','job_attempts','mapping_context_bytes','mapping_max_windows','mapping_max_context_requests'] as $key) {
            if (!is_int($this->values[$key]) || $this->values[$key] < 1) {
                throw new ApiError('CONFIG_INVALID', 'Неверный положительный параметр конфигурации: ' . $key, 503);
            }
        }
        if (!is_int($this->values['mapping_max_repairs']) || $this->values['mapping_max_repairs'] < 0 || $this->values['mapping_max_repairs'] > 2) {
            throw new ApiError('CONFIG_INVALID', 'Лимит исправлений сопоставления должен быть от 0 до 2.', 503);
        }
        if ($this->values['job_lease_seconds'] <= $this->values['http_timeout']) {
            throw new ApiError('CONFIG_INVALID', 'Срок аренды задания должен превышать тайм-аут HTTP.', 503);
        }
        $root = (string) $this->values['runtime_dir'];
        if ($root === '' || str_contains($root, "\0")) throw new ApiError('CONFIG_INVALID', 'Неверный временный каталог.', 503);
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
            throw new ApiError('RUNTIME_UNWRITABLE', 'Не удалось создать закрытый временный каталог.', 503);
        }
        $resolved = realpath($root);
        $public = realpath(dirname(__DIR__) . '/public');
        if ($resolved === false || $public === false || self::inside($resolved, $public)) {
            throw new ApiError('RUNTIME_PUBLIC', 'Временный каталог должен находиться вне публичного каталога.', 503);
        }
        if (!is_writable($resolved)) throw new ApiError('RUNTIME_UNWRITABLE', 'Временный каталог недоступен для записи.', 503);
        $this->values['runtime_dir'] = $resolved;
    }

    public static function load(): self
    {
        $path = getenv('CUSTOMS_CONFIG_FILE') ?: dirname(__DIR__) . '/config/local.php';
        $values = [];
        if (is_file($path)) {
            $resolved = realpath($path);
            if ($resolved === false || self::inside($resolved, dirname(__DIR__) . '/public')) {
                throw new ApiError('CONFIG_PUBLIC', 'Файл конфигурации не должен находиться в публичном каталоге.', 503);
            }
            $values = require $resolved;
            if (!is_array($values)) throw new ApiError('CONFIG_INVALID', 'Конфигурация должна возвращать массив.', 503);
        }
        foreach (['MISTRAL_API_KEY' => 'mistral_api_key','CUSTOMS_RUNTIME_DIR' => 'runtime_dir'] as $env => $key) {
            $value = getenv($env);
            if ($value !== false && $value !== '') $values[$key] = $value;
        }
        return new self($values);
    }

    public function get(string $key): mixed { return $this->values[$key] ?? null; }

    public function clientLimits(): array
    {
        return array_intersect_key($this->values, array_flip(['session_ttl','max_files','max_file_bytes','max_session_bytes','max_pages']));
    }

    public static function inside(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (PHP_OS_FAMILY === 'Windows') { $path = strtolower($path); $root = strtolower($root); }
        return $path === $root || str_starts_with($path, $root . '/');
    }
}

<?php
declare(strict_types=1);

namespace Customs;

final class Platform
{
    public const EXTENSIONS = ['curl','fileinfo','gd','mbstring','openssl','dom','libxml','xmlreader','xmlwriter','zip','zlib'];

    public static function check(Config $config, ?array $extensions = null, ?int $phpVersion = null): array
    {
        $extensions ??= get_loaded_extensions();
        $phpVersion ??= PHP_VERSION_ID;
        $errors = [];
        if ($phpVersion < 80300) $errors[] = ['code' => 'PHP_VERSION', 'message' => 'Требуется PHP 8.3 или новее.'];
        foreach (self::EXTENSIONS as $extension) {
            if (!in_array($extension, $extensions, true)) $errors[] = ['code' => 'EXTENSION_MISSING', 'extension' => $extension];
        }
        $root = $config->get('runtime_dir');
        $probe = $root . '/.probe-' . bin2hex(random_bytes(8));
        $renamed = $probe . '.renamed';
        $handle = @fopen($probe, 'x+b');
        if ($handle === false) $errors[] = ['code' => 'RUNTIME_WRITE'];
        else {
            if (!flock($handle, LOCK_EX | LOCK_NB)) $errors[] = ['code' => 'RUNTIME_LOCK'];
            if (fwrite($handle, '{}') !== 2) $errors[] = ['code' => 'RUNTIME_WRITE'];
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);
            if (!@rename($probe, $renamed)) $errors[] = ['code' => 'RUNTIME_ATOMIC_RENAME'];
            @unlink($probe);
            @unlink($renamed);
        }
        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'php' => PHP_VERSION,
            'limits' => [
                'memory_limit' => ini_get('memory_limit'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'max_execution_time' => ini_get('max_execution_time'),
            ],
            'mistral_key_configured' => $config->get('mistral_api_key') !== '',
            'outbound_https' => 'not-tested',
            'scheduler' => 'verify-in-Plesk',
        ];
    }
}

<?php
declare(strict_types=1);

// Copy to local.php outside the public directory. Never commit the real key.
return [
    'mistral_api_key' => '', // Or set MISTRAL_API_KEY on the backend.
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
    'http_timeout' => 120,
    'job_lease_seconds' => 180,
    'job_attempts' => 3,
    'secure_cookie' => true, // false only for local HTTP development.
];

<?php
declare(strict_types=1);

use Customs\Config;
use Customs\Platform;

test('private configuration never exposes API key through client limits', function (): void {
    $config = new Config(['mistral_api_key' => 'test-placeholder']);
    truth(!array_key_exists('mistral_api_key', $config->clientLimits()));
    truth(!array_key_exists('runtime_dir', $config->clientLimits()));
    same(7200, $config->clientLimits()['session_ttl']);
});

test('runtime under web root and invalid timing are rejected', function (): void {
    errorCode('RUNTIME_PUBLIC', fn() => new Config(['runtime_dir' => dirname(__DIR__, 2) . '/public']));
    errorCode('CONFIG_INVALID', fn() => new Config(['session_ttl' => 0]));
    errorCode('CONFIG_INVALID', fn() => new Config(['job_lease_seconds' => 10]));
    truth(Config::inside('C:/test/public/a', 'C:/test/public'));
    truth(!Config::inside('C:/test/publicity/a', 'C:/test/public'));
});

test('platform check diagnoses each absent extension and verifies real file operations', function (): void {
    $config = new Config();
    $valid = Platform::check($config);
    truth($valid['ok'], json_encode($valid));
    foreach (Platform::EXTENSIONS as $missing) {
        $result = Platform::check($config, array_values(array_diff(Platform::EXTENSIONS, [$missing])));
        truth(!$result['ok']);
        truth(in_array(['code' => 'EXTENSION_MISSING','extension' => $missing], $result['errors'], true));
    }
    truth(!Platform::check($config, null, 80200)['ok']);
});

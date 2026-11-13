<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

$testFailures = 0;
$testCount = 0;
function test(string $name, callable $test): void
{
    global $testFailures, $testCount;
    $testCount++;
    try {
        $test();
        echo "PASS $name\n";
    } catch (Throwable $error) {
        $testFailures++;
        echo "FAIL $name: {$error->getMessage()}\n";
    }
}
function same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . ', got ' . json_encode($actual, JSON_UNESCAPED_UNICODE));
    }
}
function truth(bool $value, string $message = 'Condition failed'): void
{
    if (!$value) throw new RuntimeException($message);
}
function errorCode(string $expected, callable $action): void
{
    try { $action(); } catch (Customs\Http\ApiError $error) { same($expected, $error->errorCode); return; }
    throw new RuntimeException('Expected error: ' . $expected);
}
function sessionFixture(?Closure $clock = null, array $overrides = []): Customs\Storage\SessionStore
{
    return new Customs\Storage\SessionStore(new Customs\Config(array_replace(['runtime_dir' => sys_get_temp_dir() . '/customs-test-' . bin2hex(random_bytes(8))], $overrides)), $clock);
}
function sourceFixture(string $quote = '27860'): array
{
    return ['documentId' => str_repeat('a',32), 'page' => 1, 'blockId' => 'p1-b0', 'sheet' => null, 'cell' => null, 'quote' => $quote];
}
foreach (glob(__DIR__ . '/*Test.php') as $testFile) require $testFile;
echo "$testCount tests; $testFailures failures\n";
exit($testFailures ? 1 : 0);

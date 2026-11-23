<?php
declare(strict_types=1);

// Match request/worker lifetimes: large document and catalogue cases use separate processes.
if (($argv[1] ?? '') === '--isolated') {
    $total=0;$failures=0;
    foreach (glob(__DIR__.'/*Test.php') as $file) {
        $process=proc_open([PHP_BINARY,__FILE__,basename($file)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if (!is_resource($process)) throw new RuntimeException('Cannot start test process');
        fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
        echo basename($file)."\n".$output;fwrite(STDERR,$errors);
        if (preg_match('/(\d+) tests; (\d+) failures\s*$/',$output,$counts)) {$total+=(int)$counts[1];$failures+=(int)$counts[2];if($code!==0 && (int)$counts[2]===0)$failures++;}
        else $failures++;
    }
    echo "$total tests; $failures failures (isolated files)\n";exit($failures ? 1 : 0);
}

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
$pattern = isset($argv[1]) ? basename($argv[1]) : '*Test.php';
foreach (glob(__DIR__ . '/' . $pattern) as $testFile) require $testFile;
echo "$testCount tests; $testFailures failures\n";
exit($testFailures ? 1 : 0);

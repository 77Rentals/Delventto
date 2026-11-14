<?php
// Tiny dependency-free test runner. Usage: php tests/run.php
declare(strict_types=1);

$GLOBALS['__tests'] = [];
$GLOBALS['__failures'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$name, $fn];
}

function ok(bool $cond, $msg = ''): void
{
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? (string) $msg : 'assertion failed');
    }
}

function eq($actual, $expected, $msg = ''): void
{
    if ($actual !== $expected) {
        $detail = 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
        throw new RuntimeException(($msg !== '' ? $msg . ': ' : '') . $detail);
    }
}

foreach (glob(__DIR__ . '/*_test.php') ?: [] as $file) {
    require $file;
}

$pass = 0;
foreach ($GLOBALS['__tests'] as [$name, $fn]) {
    try {
        $fn();
        $pass++;
    } catch (Throwable $e) {
        $GLOBALS['__failures'][] = $name . ': ' . $e->getMessage();
    }
}

foreach ($GLOBALS['__failures'] as $f) {
    echo "FAIL  $f\n";
}
$fail = count($GLOBALS['__failures']);
echo "$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);

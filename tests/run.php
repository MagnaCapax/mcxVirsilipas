<?php

declare(strict_types=1);

// Plain test runner: every function named test* in tests/*Test.php is run; no PHPUnit needed.
// Usage: php tests/run.php [filter]

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/lib.php';

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    require $file;
}

$filter = $argv[1] ?? '';
$tests = array_filter(get_defined_functions()['user'], static fn (string $f): bool => str_starts_with($f, 'test') && ($filter === '' || str_contains($f, strtolower($filter))));

$failed = 0;
foreach ($tests as $test) {
    try {
        $test();
        echo "ok   $test\n";
    } catch (\Throwable $e) {
        $failed++;
        echo "FAIL $test: " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    }
}
Harness::stop();
printf("%d tests, %d failed\n", count($tests), $failed);
exit($failed === 0 ? 0 : 1);

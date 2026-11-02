<?php
declare(strict_types=1);

/*
 * Run all tests: php tests/run.php
 * Exit code 0 when everything passes.
 */

$failed = 0;
foreach (['unit.php', 'http.php'] as $suite) {
    echo "\n#### {$suite}\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $suite), $rc);
    if ($rc !== 0) {
        $failed++;
    }
}
echo "\n" . ($failed === 0 ? 'ALL SUITES PASSED' : "{$failed} SUITE(S) FAILED") . "\n";
exit($failed === 0 ? 0 : 1);

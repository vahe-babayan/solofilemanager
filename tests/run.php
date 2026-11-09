<?php
declare(strict_types=1);

/*
 * Run all tests: php tests/run.php
 * Exit code 0 when everything passes. The suites work in tests/.tmp; after a run where every suite passed the
 * folder is removed, after a failure it is kept for a look (the next run starts from an empty folder anyway).
 */

$failed = 0;
foreach (['unit.php', 'http.php'] as $suite) {
    echo "\n#### {$suite}\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $suite), $rc);
    if ($rc !== 0) {
        $failed++;
    }
}

/** Delete a folder with everything in it; links are removed, not followed. */
function removeTree(string $path): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path) || @rmdir($path);
        return;
    }
    foreach (@scandir($path) ?: [] as $e) {
        if ($e !== '.' && $e !== '..') {
            removeTree($path . '/' . $e);
        }
    }
    @rmdir($path);
}

if ($failed === 0 && is_dir(__DIR__ . '/.tmp')) {
    removeTree(__DIR__ . '/.tmp');
}
echo "\n" . ($failed === 0 ? 'ALL SUITES PASSED' : "{$failed} SUITE(S) FAILED (files of the run kept in tests/.tmp)") . "\n";
exit($failed === 0 ? 0 : 1);

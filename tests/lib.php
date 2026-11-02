<?php
declare(strict_types=1);

/*
 * Tiny test helpers shared by tests/unit.php and tests/http.php (no framework, PHP CLI only).
 */

const FM_TEST_ROOT = __DIR__;

$GLOBALS['fm_test_fail'] = 0;
$GLOBALS['fm_test_pass'] = 0;

function section(string $title): void {
    echo "\n== {$title}\n";
}

function check(string $label, bool $cond, string $detail = ''): void {
    if ($cond) {
        $GLOBALS['fm_test_pass']++;
        echo "  ok   {$label}\n";
        return;
    }
    $GLOBALS['fm_test_fail']++;
    $detail = trim(preg_replace('/\s+/', ' ', $detail) ?? '');
    if (strlen($detail) > 200) {
        $detail = substr($detail, 0, 200) . '...';
    }
    echo "  FAIL {$label}" . ($detail !== '' ? " :: {$detail}" : '') . "\n";
}

function summary(string $suite): int {
    $p = $GLOBALS['fm_test_pass'];
    $f = $GLOBALS['fm_test_fail'];
    echo "\n{$suite}: {$p} passed, {$f} failed\n";
    return $f === 0 ? 0 : 1;
}

/** Fresh scratch folder under tests/.tmp (on the same drive as the repo). */
function scratch(string $name): string {
    $dir = FM_TEST_ROOT . '/.tmp/' . $name;
    if (is_dir($dir) || is_link($dir)) {
        rmTree($dir);
    }
    mkdir($dir, 0775, true);
    return str_replace('\\', '/', realpath($dir));
}

/** Folder link: junction on Windows, symlink elsewhere. */
function makeDirLink(string $link, string $target): bool {
    if (PHP_OS_FAMILY === 'Windows') {
        exec('cmd /c mklink /J ' . escapeshellarg(str_replace('/', '\\', $link)) . ' ' . escapeshellarg(str_replace('/', '\\', $target)) . ' 2>nul', $o, $rc);
        clearstatcache(true, $link);
        return $rc === 0 && is_dir($link);
    }
    return @symlink($target, $link);
}

/** Delete a tree without following links into their targets. */
function rmTree(string $path): void {
    // Windows: rmdir() removes a junction (even one whose target is gone) or an empty folder, never the
    // target's contents; a non-empty real folder fails here and is emptied below.
    if (PHP_OS_FAMILY === 'Windows' && @rmdir($path)) {
        return;
    }
    if (is_link($path)) {
        @unlink($path);
        return;
    }
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $e) {
            if ($e !== '.' && $e !== '..') {
                rmTree($path . '/' . $e);
            }
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
}

function isJunction(string $path): bool {
    $real = realpath($path);
    $parent = realpath(dirname($path));
    if ($real === false || $parent === false) {
        return false;
    }
    $own = rtrim(str_replace('\\', '/', $parent), '/') . '/' . basename($path);
    return strcasecmp(str_replace('\\', '/', $real), $own) !== 0;
}

/** One TAR header block (+ data) in ustar layout. */
function tarBlock(string $name, string $data, string $type = '0', string $linkName = ''): string {
    $h = str_pad(substr($name, 0, 100), 100, "\0")
        . ($type === '5' ? '0000755' : '0000644') . "\0"
        . str_pad('0', 7, '0', STR_PAD_LEFT) . "\0"
        . str_pad('0', 7, '0', STR_PAD_LEFT) . "\0"
        . str_pad(decoct(strlen($data)), 11, '0', STR_PAD_LEFT) . "\0"
        . str_pad(decoct(time()), 11, '0', STR_PAD_LEFT) . "\0"
        . '        '
        . $type
        . str_pad($linkName, 100, "\0")
        . "ustar\0" . '00'
        . str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);
    $sum = 0;
    for ($i = 0; $i < 512; $i++) {
        $sum += ord($h[$i]);
    }
    $h = substr($h, 0, 148) . str_pad(decoct($sum), 6, '0', STR_PAD_LEFT) . "\0 " . substr($h, 156);
    $pad = (512 - strlen($data) % 512) % 512;
    return $h . $data . str_repeat("\0", $pad);
}

/** TAR archive; entries: [name, data, type, linkName]. Long names become GNU 'L' records. */
function writeTar(string $path, array $entries): void {
    $out = '';
    foreach ($entries as $e) {
        [$name, $data, $type, $link] = $e + ['', '', '0', ''];
        if (strlen($name) > 100) {
            $out .= tarBlock('././@LongLink', $name . "\0", 'L');
        }
        $out .= tarBlock($name, $data, $type, $link);
    }
    file_put_contents($path, $out . str_repeat("\0", 1024));
}

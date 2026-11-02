<?php
declare(strict_types=1);

/*
 * Build a ready-to-open copy of solofilemanager.php for manual / browser testing.
 *
 *   php tests/make-instance.php --out-dir=ai-test [--name=x.php] [--password=...]
 *        [--allowed-ips=1.2.3.4,10.0.0.0/8] [--ops-mode=auto|php|os] [--source=path]
 *   php tests/make-instance.php --out-dir=ai-test --keep-name
 *
 * New copy: random non-default file name (no first-run rename), hash of the given or a generated
 * password (no forced "admin" change), optional IP allowlist and file ops mode.
 * --keep-name: replace the code of the copy recorded in <out-dir>/.instance.json (or the given
 * --name / the only SoloFileManager file in out-dir) and keep its config lines (password, modes,
 * allowlist, trash name, terminal flags...).
 * Name and password are written to <out-dir>/.instance.json and printed as JSON.
 */

$opts = getopt('', ['out-dir:', 'name:', 'password:', 'allowed-ips:', 'ops-mode:', 'source:', 'keep-name']);
$outDir = isset($opts['out-dir']) ? rtrim(str_replace('\\', '/', (string)$opts['out-dir']), '/') : '';
if ($outDir === '') {
    fwrite(STDERR, "Usage: php tests/make-instance.php --out-dir=DIR [--name=] [--password=] [--allowed-ips=] [--ops-mode=] [--keep-name]\n");
    exit(2);
}
$source = (string)($opts['source'] ?? dirname(__DIR__) . '/solofilemanager.php');
$code = (string)@file_get_contents($source);
if ($code === '' || strpos($code, 'SOLOFILEMANAGER_VERSION') === false) {
    fwrite(STDERR, "Cannot read SoloFileManager from {$source}\n");
    exit(2);
}
if (!is_dir($outDir) && !mkdir($outDir, 0775, true)) {
    fwrite(STDERR, "Cannot create {$outDir}\n");
    exit(2);
}
$infoFile = $outDir . '/.instance.json';
$info = is_file($infoFile) ? (json_decode((string)file_get_contents($infoFile), true) ?: []) : [];

/** Config lines kept by --keep-name (one line each in the script). */
const KEEP_VARS = [
    'ROOT_DIR', 'ENABLE_AUTH', 'PASSWORD_HASH', 'FM_FILE_OPS_MODE', 'FM_VERBOSE_PROGRESS_MIN_ITEMS',
    'FM_TRASH_BASENAME', 'FM_IDLE_TIMEOUT_MINUTES', 'FM_ALLOWED_IPS', 'FM_TRUSTED_PROXIES',
    'FM_ENABLE_TERMINAL_HERE', 'FM_ENABLE_TERMINAL_MANUAL', 'FM_ENABLE_TERMINAL_ADVANCED',
];

function configLinePattern(string $var): string {
    return '/^\$' . $var . '\s*=[^\n]*;[ \t]*$/m';
}

/** Replace the whole "$VAR = ...;" line; callback so "$2y$..." is not read as back-references. */
function setConfigLine(string $code, string $var, string $line): string {
    return preg_replace_callback(configLinePattern($var), static fn() => $line, $code, 1) ?? $code;
}

function phpString(string $s): string {
    return '\'' . str_replace(['\\', '\''], ['\\\\', '\\\''], $s) . '\'';
}

if (isset($opts['keep-name'])) {
    $name = (string)($opts['name'] ?? ($info['name'] ?? ''));
    if ($name === '') {
        $found = [];
        foreach (glob($outDir . '/*.php') ?: [] as $f) {
            if (strpos((string)file_get_contents($f, false, null, 0, 2048), 'SOLOFILEMANAGER_VERSION') !== false) {
                $found[] = basename($f);
            }
        }
        if (count($found) !== 1) {
            fwrite(STDERR, 'Cannot tell which copy to update in ' . $outDir . ' (found ' . count($found) . "); pass --name\n");
            exit(2);
        }
        $name = $found[0];
    }
    $target = $outDir . '/' . $name;
    $old = (string)@file_get_contents($target);
    if ($old === '') {
        fwrite(STDERR, "Cannot read {$target}\n");
        exit(2);
    }
    foreach (KEEP_VARS as $var) {
        if (preg_match(configLinePattern($var), $old, $m)) {
            $code = setConfigLine($code, $var, $m[0]);
        }
    }
    $password = (string)($info['password'] ?? '');
} else {
    $name = (string)($opts['name'] ?? ('ai-' . bin2hex(random_bytes(10)) . '.php'));
    if (basename($name) !== $name || !preg_match('/\.php$/i', $name) || strcasecmp($name, 'solofilemanager.php') === 0) {
        fwrite(STDERR, "--name must be a plain *.php file name other than solofilemanager.php\n");
        exit(2);
    }
    $password = (string)($opts['password'] ?? bin2hex(random_bytes(8)));
    $code = setConfigLine($code, 'PASSWORD_HASH', '$PASSWORD_HASH = ' . phpString(password_hash($password, PASSWORD_DEFAULT)) . ';');
    if (isset($opts['allowed-ips'])) {
        $ips = array_values(array_filter(array_map('trim', explode(',', (string)$opts['allowed-ips'])), 'strlen'));
        $code = setConfigLine($code, 'FM_ALLOWED_IPS', '$FM_ALLOWED_IPS = [' . implode(', ', array_map('phpString', $ips)) . '];');
    }
    if (isset($opts['ops-mode'])) {
        $mode = (string)$opts['ops-mode'];
        if (!in_array($mode, ['auto', 'php', 'os'], true)) {
            fwrite(STDERR, "--ops-mode must be auto, php or os\n");
            exit(2);
        }
        $code = setConfigLine($code, 'FM_FILE_OPS_MODE', '$FM_FILE_OPS_MODE = ' . phpString($mode) . ';');
    }
}

$target = $outDir . '/' . $name;
if (file_put_contents($target, $code) === false) {
    fwrite(STDERR, "Cannot write {$target}\n");
    exit(2);
}
preg_match("/const SOLOFILEMANAGER_VERSION = '([^']+)'/", $code, $vm);
$info = ['name' => $name, 'password' => $password, 'version' => $vm[1] ?? '', 'built' => date('c')];
file_put_contents($infoFile, json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo json_encode($info + ['file' => $target], JSON_UNESCAPED_SLASHES), "\n";

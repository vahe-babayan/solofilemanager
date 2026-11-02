<?php
declare(strict_types=1);

/*
 * HTTP smoke tests against temporary copies served by PHP's built-in server.
 * Run: php tests/http.php [--source=path/to/solofilemanager.php] [--port=8765]
 */

require __DIR__ . '/lib.php';

$opts = getopt('', ['source:', 'port:']);
$source = $opts['source'] ?? dirname(__DIR__) . '/solofilemanager.php';
$port = (int)($opts['port'] ?? 8765);
$code = (string)file_get_contents($source);
if ($code === '') {
    fwrite(STDERR, "Cannot read {$source}\n");
    exit(2);
}

$base = scratch('http');
$www = $base . '/www';
$sessions = $base . '/sessions';
$outside = $base . '/outside';
mkdir($www);
mkdir($sessions);
mkdir($outside);
$script = 'solofilemanager_HttpSmokeTestAbcdef12345678.php';

/** Install a copy at $www/$folder with its own password and config tweaks. */
function installCopy(string $folder, string $password, array $replace = []): string {
    global $www, $code, $script;
    $dir = $www . '/' . $folder;
    mkdir($dir, 0775, true);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    // Callback, not a replacement string: "$2y$10$..." would be read as back-references.
    $c = preg_replace_callback('/\$PASSWORD_HASH = \'[^\']*\';/', static fn() => '$PASSWORD_HASH = \'' . $hash . '\';', $code, 1);
    foreach ($replace as $from => $to) {
        $c = str_replace($from, $to, $c);
    }
    file_put_contents($dir . '/' . $script, $c);
    return $dir;
}

$dirA = installCopy('a', 'pass-A-12345', ["\$FM_FILE_OPS_MODE = 'auto';" => "\$FM_FILE_OPS_MODE = 'php';"]);
installCopy('b', 'pass-B-12345');
installCopy('c', 'pass-C-12345', ['$FM_ALLOWED_IPS = [];' => "\$FM_ALLOWED_IPS = ['10.0.0.1'];"]);

$server = proc_open(
    [PHP_BINARY, '-d', 'session.save_path=' . $sessions, '-S', '127.0.0.1:' . $port, '-t', $www],
    [0 => ['pipe', 'r'], 1 => ['file', $base . '/server.log', 'w'], 2 => ['file', $base . '/server.log', 'a']],
    $pipes
);
if (!is_resource($server)) {
    fwrite(STDERR, "Cannot start the PHP built-in server\n");
    exit(2);
}
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
    usleep(100000);
}

/** Minimal browser: cookie jar + requests. */
final class Browser {
    /** @var array<string, string> */
    public array $cookies = [];

    /** @return array{0: int, 1: string} [status, body] */
    public function req(string $url, array $fields = [], array $files = [], array $headers = []): array {
        $hdr = $headers;
        if ($this->cookies) {
            $hdr[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => $k . '=' . $v, array_keys($this->cookies), $this->cookies));
        }
        $opts = ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 60];
        if ($fields || $files) {
            $opts['method'] = 'POST';
            $b = '----fmtest' . bin2hex(random_bytes(6));
            $body = '';
            foreach ($fields as $k => $v) {
                foreach ((array)$v as $one) {
                    $body .= "--{$b}\r\nContent-Disposition: form-data; name=\"{$k}\"\r\n\r\n{$one}\r\n";
                }
            }
            foreach ($files as $k => [$name, $data]) {
                $body .= "--{$b}\r\nContent-Disposition: form-data; name=\"{$k}\"; filename=\"{$name}\"\r\nContent-Type: application/octet-stream\r\n\r\n{$data}\r\n";
            }
            $body .= "--{$b}--\r\n";
            $hdr[] = 'Content-Type: multipart/form-data; boundary=' . $b;
            $opts['content'] = $body;
        }
        $opts['header'] = implode("\r\n", $hdr);
        $resp = @file_get_contents($url, false, stream_context_create(['http' => $opts]));
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) {
                $status = (int)$m[1];
            } elseif (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $line, $m)) {
                if ($m[2] === 'deleted' || $m[2] === '') {
                    unset($this->cookies[$m[1]]);
                } else {
                    $this->cookies[$m[1]] = $m[2];
                }
            }
        }
        return [$status, (string)$resp];
    }

    public function token(string $url): string {
        [, $page] = $this->req($url);
        return preg_match('/const fm_csrf_token = "([0-9a-f]+)"/', $page, $m) ? $m[1] : '';
    }

    public function state(string $url): string {
        [, $page] = $this->req($url);
        if (strpos($page, 'fm-login-input') !== false) return 'login';
        if (strpos($page, 'fm-table-config-btn') !== false) return 'app';
        return 'other';
    }

    public function post(string $url, array $fields, array $files = []): array {
        return $this->req($url, $fields, $files, ['X-CSRF-Token: ' . $this->token($url)]);
    }

    public function login(string $url, string $password): bool {
        [, $body] = $this->post($url, ['action' => 'login', 'password' => $password]);
        return strpos($body, '"success"') !== false;
    }
}

$u = fn(string $copy) => "http://127.0.0.1:{$port}/{$copy}/{$script}";

try {
    section('Login and CSRF');
    $br = new Browser();
    check('copy A shows the login form', $br->state($u('a')) === 'login');
    check('wrong password refused', !$br->login($u('a'), 'nope'));
    check('login to A', $br->login($u('a'), 'pass-A-12345'));
    check('A opens after login', $br->state($u('a')) === 'app');
    [$st] = $br->req($u('a'), ['action' => 'create-new-folder', 'in' => '', 'name' => 'no-csrf']);
    check('POST without CSRF token refused (403)', $st === 403 && !is_dir($dirA . '/no-csrf'), "status {$st}");
    $br->post($u('a'), ['action' => 'create-new-folder', 'in' => '', 'name' => 'with-csrf']);
    check('POST with CSRF token works', is_dir($dirA . '/with-csrf'));
    [$st] = (new Browser())->req($u('a') . '?action=get-server-info');
    check('API without login refused (401)', $st === 401, "status {$st}");

    section('Separate login per installation');
    check('logged in to A, B still asks for its password', $br->state($u('b')) === 'login');
    check('A password does not open B', !$br->login($u('b'), 'pass-A-12345'));
    check('login to B with its own password', $br->login($u('b'), 'pass-B-12345'));
    $br->post($u('a'), ['action' => 'logout']);
    check('logout of A keeps B logged in', $br->state($u('a')) === 'login' && $br->state($u('b')) === 'app');

    section('Password change logs out other sessions');
    $s1 = new Browser();
    $s2 = new Browser();
    $s1->login($u('b'), 'pass-B-12345');
    $s2->login($u('b'), 'pass-B-12345');
    [, $body] = $s1->post($u('b'), ['action' => 'change-password', 'current_password' => 'pass-B-12345', 'new_password' => 'pass-B-new-67890', 'confirm_password' => 'pass-B-new-67890']);
    check('password changed', strpos($body, '"saved":true') !== false, $body);
    check('changing session stays logged in', $s1->state($u('b')) === 'app');
    check('other session logged out', $s2->state($u('b')) === 'login');
    check('old password refused', !$s2->login($u('b'), 'pass-B-12345'));

    section('IP allowlist');
    [$st, $body] = (new Browser())->req($u('c'));
    check('address not on the list gets 403 before the login page', $st === 403 && trim($body) === 'Forbidden', "status {$st}");

    section('Upload and name conflicts');
    $br->login($u('a'), 'pass-A-12345');
    mkdir($dirA . '/up');
    file_put_contents($dirA . '/up/report.txt', 'ORIGINAL');
    [, $body] = $br->post($u('a'), ['action' => 'upload-check', 'in' => 'up', 'paths[]' => ['report.txt', 'new.txt']]);
    check('upload-check reports the existing file', strpos($body, '"existing":["report.txt"]') !== false, $body);
    $br->post($u('a'), ['action' => 'upload', 'in' => 'up', 'paths[]' => 'report.txt', 'on_conflict' => 'skip'], ['files[]' => ['report.txt', 'SKIP']]);
    check('skip keeps the existing file', file_get_contents($dirA . '/up/report.txt') === 'ORIGINAL');
    $br->post($u('a'), ['action' => 'upload', 'in' => 'up', 'paths[]' => 'report.txt', 'on_conflict' => 'rename'], ['files[]' => ['report.txt', 'BOTH']]);
    check('keep both saves "report (1).txt"', @file_get_contents($dirA . '/up/report (1).txt') === 'BOTH');
    $br->post($u('a'), ['action' => 'upload', 'in' => 'up', 'paths[]' => 'report.txt', 'on_conflict' => 'replace'], ['files[]' => ['report.txt', 'NEW']]);
    check('replace overwrites', file_get_contents($dirA . '/up/report.txt') === 'NEW');

    section('Chunked upload');
    [, $body] = $br->post($u('a'), ['action' => 'upload-init', 'in' => 'up', 'path' => 'big/data.bin', 'size' => '10']);
    $id = preg_match('/"id":"([0-9a-f]{32})"/', $body, $m) ? $m[1] : '';
    [, $c1] = $br->post($u('a'), ['action' => 'upload-chunk', 'id' => $id, 'offset' => '0'], ['chunk' => ['chunk', 'abcdef']]);
    [, $again] = $br->post($u('a'), ['action' => 'upload-chunk', 'id' => $id, 'offset' => '0'], ['chunk' => ['chunk', 'abcdef']]);
    [, $c2] = $br->post($u('a'), ['action' => 'upload-chunk', 'id' => $id, 'offset' => '6'], ['chunk' => ['chunk', 'ghij']]);
    [, $fin] = $br->post($u('a'), ['action' => 'upload-finish', 'id' => $id]);
    check('parts accepted, a repeated part is refused with the server position', strpos($c1, '"received":6') !== false && strpos($again, '"code":"offset"') !== false && strpos($c2, '"received":10') !== false);
    check('file assembled', strpos($fin, '"success"') !== false && @file_get_contents($dirA . '/up/big/data.bin') === 'abcdefghij', $fin);
    check('temp folder removed afterwards', !is_dir($dirA . '/.fm-upload-tmp'));

    section('Extract: hostile archive');
    mkdir($dirA . '/ex');
    $haveLink = makeDirLink($dirA . '/ex/jx', $outside);
    $zip = new ZipArchive();
    $zip->open($dirA . '/ex/hostile.zip', ZipArchive::CREATE);
    $zip->addFromString('ok.txt', 'ok');
    $zip->addFromString('../escaped.txt', 'x');
    $zip->addFromString('jx/through-existing-link.txt', 'x');
    $zip->addFromString('lnk', $outside);
    $zip->setExternalAttributesName('lnk', ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->close();
    [, $body] = $br->post($u('a'), ['action' => 'extract', 'in' => 'ex', 'names[]' => 'hostile.zip', 'separate_folders' => '0']);
    $outsideFiles = array_values(array_diff(scandir($outside) ?: [], ['.', '..']));
    check('nothing written outside the root', $outsideFiles === [], implode(',', $outsideFiles));
    check('nothing escaped next to the folder', !file_exists($dirA . '/escaped.txt'));
    check('safe entry extracted', @file_get_contents($dirA . '/ex/ok.txt') === 'ok');
    check('skipped entries reported', (bool)preg_match('/"skipped_count":(\d+)/', $body, $m) && (int)$m[1] >= ($haveLink ? 3 : 2), $body);
    if ($haveLink) {
        rmTree($dirA . '/ex/jx');
    }

    section('Random trash folder name');
    file_put_contents($dirA . '/up/to-trash.txt', 'T');
    $br->post($u('a'), ['action' => 'trash-move', 'in' => 'up', 'names[]' => 'to-trash.txt']);
    check('deleted file goes to .trash', is_dir($dirA . '/.trash') && !file_exists($dirA . '/up/to-trash.txt'));
    [, $body] = $br->post($u('a'), ['action' => 'set-trash-name', 'password' => 'wrong', 'mode' => 'random']);
    check('wrong password refused', strpos($body, '"error"') !== false && is_dir($dirA . '/.trash'), $body);
    [, $body] = $br->post($u('a'), ['action' => 'set-trash-name', 'password' => 'pass-A-12345', 'mode' => 'random']);
    $rnd = preg_match('/"name":"(\.trash-[0-9a-f]{12})"/', $body, $m) ? $m[1] : '';
    $php = (string)file_get_contents($dirA . '/' . $script);
    check('switch to a random name renames the folder and the setting', $rnd !== '' && is_dir($dirA . '/' . $rnd) && !file_exists($dirA . '/.trash')
        && strpos($php, "\$FM_TRASH_BASENAME = '{$rnd}';") !== false, $body);
    [, $body] = $br->req($u('a') . '?action=open&folder=' . rawurlencode($dirA . '/' . $rnd));
    $tid = preg_match('/"trash_items":\[\{"id":"([^"]+)"/', $body, $m) ? $m[1] : '';
    check('deleted file still listed in the renamed trash', $tid !== '' && strpos($body, 'to-trash.txt') !== false, $body);
    $br->post($u('a'), ['action' => 'trash-restore', 'ids[]' => $tid]);
    check('restore from the renamed trash works', @file_get_contents($dirA . '/up/to-trash.txt') === 'T');
    $br->post($u('a'), ['action' => 'trash-move', 'in' => 'up', 'names[]' => 'to-trash.txt']);
    [, $body] = $br->post($u('a'), ['action' => 'set-trash-name', 'password' => 'pass-A-12345', 'mode' => 'default']);
    check('switch back to .trash keeps the content', is_dir($dirA . '/.trash') && !file_exists($dirA . '/' . $rnd)
        && strpos((string)file_get_contents($dirA . '/' . $script), "\$FM_TRASH_BASENAME = '.trash';") !== false, $body);
} finally {
    proc_terminate($server);
    proc_close($server);
}

rmTree($base);
exit(summary('http'));

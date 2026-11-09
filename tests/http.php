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
    // max_input_vars as on most hosts: long selections must still arrive in full (fm_lists).
    [PHP_BINARY, '-d', 'session.save_path=' . $sessions, '-d', 'max_input_vars=1000', '-S', '127.0.0.1:' . $port, '-t', $www],
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
    $br->req($u('a'));
    [, $page] = $br->req($u('a'));
    [, $body] = $br->req($u('a') . '?action=get-server-info');
    $capsFresh = (json_decode($body, true) ?: [])['capabilities'] ?? [];
    check('server capabilities kept in the session match a fresh probe',
        isset($capsFresh['exec_available']) && strpos($page, 'const fm_exec_available = ' . ($capsFresh['exec_available'] ? 'true' : 'false') . ';') !== false, $body);
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

    section('First run: rename, then set the password (no "admin" login)');
    $dirD = installCopy('d', 'admin');
    rename($dirD . '/' . $script, $dirD . '/solofilemanager.php');
    file_put_contents($dirD . '/secret.txt', 'S');
    $hashIn = static function () use ($dirD): string {
        foreach (glob($dirD . '/*.php') ?: [] as $f) {
            if (preg_match("/^\\\$PASSWORD_HASH = '([^']+)';/m", (string)file_get_contents($f), $m)) return $m[1];
        }
        return '';
    };
    $fresh = "http://127.0.0.1:{$port}/d/solofilemanager.php";
    $f = new Browser();
    [, $page] = $f->req($fresh);
    check('not renamed yet: the setup page', strpos($page, 'Setup required') !== false && strpos($page, 'fm-login-input') === false);
    [$st, $body] = $f->req($fresh . '?action=open&folder=');
    check('not renamed yet: file list refused (403)', $st === 403 && strpos($body, 'secret.txt') === false, "status {$st} {$body}");
    [$st] = $f->post($fresh, ['action' => 'create-new-folder', 'in' => '', 'name' => 'early']);
    check('not renamed yet: file actions refused (403)', $st === 403 && !is_dir($dirD . '/early'), "status {$st}");
    [, $body] = $f->post($fresh, ['action' => 'change-password', 'new_password' => 'first-pass-123', 'confirm_password' => 'first-pass-123']);
    check('not renamed yet: the password cannot be set', strpos($body, '"error"') !== false && password_verify('admin', $hashIn()), $body);
    $newName = 'solofilemanager_' . str_repeat('Ab1', 9) . '.php';
    [, $body] = $f->post($fresh, ['action' => 'do-rename', 'new_name' => $newName]);
    check('rename works', strpos($body, '"success"') !== false && is_file($dirD . '/' . $newName), $body);
    $named = "http://127.0.0.1:{$port}/d/{$newName}";
    [, $page] = $f->req($named);
    check('after the rename: set-password screen, no login form', strpos($page, 'fm-pwd-new') !== false && strpos($page, 'fm-login-input') === false);
    [$st] = $f->req($named . '?action=open&folder=');
    check('before the password is set, file actions still need a login (401)', $st === 401, "status {$st}");
    [, $body] = $f->post($named, ['action' => 'change-password', 'new_password' => 'first-pass-123', 'confirm_password' => 'other-pass-123']);
    check('confirmation must match', strpos($body, 'do not match') !== false, $body);
    [, $body] = $f->post($named, ['action' => 'change-password', 'new_password' => 'admin', 'confirm_password' => 'admin']);
    check('"admin" is refused', strpos($body, '"error"') !== false, $body);
    [, $body] = $f->post($named, ['action' => 'change-password', 'new_password' => 'first-pass-123', 'confirm_password' => 'first-pass-123']);
    check('password set without logging in first', strpos($body, '"saved":true') !== false && password_verify('first-pass-123', $hashIn()), $body);
    check('the browser that set it is logged in', $f->state($named) === 'app');
    $g = new Browser();
    check('another browser gets the login form', $g->state($named) === 'login');
    [$st] = $g->post($named, ['action' => 'change-password', 'new_password' => 'evil-pass-123', 'confirm_password' => 'evil-pass-123']);
    check('another browser cannot set it again (401)', $st === 401 && password_verify('first-pass-123', $hashIn()), "status {$st}");
    check('the new password logs in', $g->login($named, 'first-pass-123') && $g->state($named) === 'app');

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

    section('Search and actions on results');
    mkdir($dirA . '/s/docs', 0775, true);
    mkdir($dirA . '/s/dest', 0775, true);
    file_put_contents($dirA . '/s/docs/report-a.txt', 'A');
    file_put_contents($dirA . '/s/report-b.txt', 'B');
    file_put_contents($dirA . '/s/dest/report-a.txt', 'other');
    file_put_contents($outside . '/report-outside.txt', 'O');
    $sLink = makeDirLink($dirA . '/s/out', $outside);
    $ndjson = static function (string $body): array {
        return array_values(array_filter(array_map(static fn($l) => json_decode($l, true), explode("\n", $body))));
    };
    $found = static function (array $lines): array {
        $paths = [];
        foreach ($lines as $l) {
            foreach ($l['items'] ?? [] as $it) {
                $paths[] = $it['path'];
            }
        }
        sort($paths);
        return $paths;
    };
    [, $body] = $br->post($u('a'), ['action' => 'search', 'in' => 's', 'name' => 'report']);
    $lines = $ndjson($body);
    $last = end($lines) ?: [];
    check('search streams results below the folder, not through links', $found($lines) === ['dest/report-a.txt', 'docs/report-a.txt', 'report-b.txt'] && !empty($last['done']) && $last['count'] === 3, $body);
    [, $body] = $br->post($u('a'), ['action' => 'search', 'in' => '', 'name' => '*.TXT', 'match' => 'wildcard', 'icase' => '0']);
    check('case-sensitive wildcard finds nothing for *.TXT', $found($ndjson($body)) === [], $body);
    [, $body] = $br->post($u('a'), ['action' => 'search', 'in' => '', 'name' => '(', 'match' => 'regex']);
    check('invalid regex is a plain JSON error', strpos($body, '"status":"error"') !== false && strpos($body, 'Invalid regular expression') !== false, $body);
    file_put_contents($dirA . '/s/docs/notes.txt', "intro\n  Budget for 2026\n");
    [, $body] = $br->post($u('a'), ['action' => 'search', 'in' => 's', 'content' => 'budget']);
    $lines = $ndjson($body);
    $last = end($lines) ?: [];
    $items = array_merge(...array_map(static fn($l) => $l['items'] ?? [], $lines));
    check('text inside files: result carries the matching line', $found($lines) === ['docs/notes.txt'] && ($items[0]['match'] ?? null) === ['line' => 2, 'text' => 'Budget for 2026', 'at' => 0, 'len' => 6]
        && isset($last['bytes_read']) && $last['bytes_read'] > 0, $body);
    [, $body] = $br->post($u('a'), ['action' => 'search', 'in' => '', 'content' => '(', 'content_match' => 'regex']);
    check('invalid regex for the text is a plain JSON error', strpos($body, '"status":"error"') !== false && strpos($body, 'Contains text') !== false, $body);
    unlink($dirA . '/s/docs/notes.txt');
    mkdir($dirA . '/.trash');
    [, $body] = $br->post($u('a'), ['action' => 'search', 'in' => '.trash', 'name' => 'x']);
    check('search inside Trash refused', strpos($body, 'not available in Trash') !== false, $body);
    rmTree($dirA . '/.trash');
    [, $body] = $br->post($u('a'), ['action' => 'search', 'in' => '../..', 'name' => 'x']);
    check('search outside the root refused', strpos($body, '"status":"error"') !== false, $body);

    [, $body] = $br->post($u('a'), ['action' => 'copy-move-stream', 'operation' => 'copy', 'in' => 's', 'dest' => 's/dest', 'names[]' => ['report-b.txt', 'docs/report-a.txt']]);
    check('copy of results stops at a name that exists in the destination', strpos($body, 'Already exists in destination: docs/report-a.txt') !== false && file_get_contents($dirA . '/s/dest/report-a.txt') === 'other', $body);
    [, $body] = $br->post($u('a'), ['action' => 'copy-move-stream', 'operation' => 'copy', 'in' => 's', 'dest' => 's', 'names[]' => ['docs/report-a.txt', 'dest/report-a.txt']]);
    check('two results with the same name cannot go into one folder', strpos($body, 'same name') !== false, $body);
    [, $body] = $br->post($u('a'), ['action' => 'copy-move-stream', 'operation' => 'move', 'in' => 's', 'dest' => 's', 'names[]' => 'docs/report-a.txt']);
    check('move of a result from a subfolder', strpos($body, '"done":true') !== false && !file_exists($dirA . '/s/docs/report-a.txt') && @file_get_contents($dirA . '/s/report-a.txt') === 'A', $body);
    $br->post($u('a'), ['action' => 'trash-move', 'in' => 's', 'names[]' => 'dest/report-a.txt']);
    [, $body] = $br->req($u('a') . '?action=open&folder=' . rawurlencode($dirA . '/.trash'));
    check('result moved to Trash remembers its folder', strpos($body, '"original_path":"s/dest/report-a.txt"') !== false && !file_exists($dirA . '/s/dest/report-a.txt'), $body);
    $br->post($u('a'), ['action' => 'trash-empty']);
    [, $body] = $br->post($u('a'), ['action' => 'delete-stream', 'in' => 's', 'names[]' => ['docs/../../outside-x', '../s']]);
    check('names with .. are refused', strpos($body, 'Nothing to delete') !== false, $body);

    file_put_contents($dirA . '/s/docs/img1.txt', 'D');
    file_put_contents($dirA . '/s/dest/img1.txt', 'E');
    [, $body] = $br->post($u('a'), ['action' => 'bulk-rename', 'in' => 's', 'from[]' => ['docs/img1.txt', 'dest/img1.txt'], 'to[]' => ['photo.txt', 'photo.txt']]);
    check('bulk rename of results: the same new name in different folders', strpos($body, '"success"') !== false
        && @file_get_contents($dirA . '/s/docs/photo.txt') === 'D' && @file_get_contents($dirA . '/s/dest/photo.txt') === 'E', $body);
    [, $body] = $br->post($u('a'), ['action' => 'bulk-rename', 'in' => 's', 'from[]' => ['docs', 'docs/photo.txt'], 'to[]' => ['papers', 'pic.txt']]);
    check('bulk rename of a folder and a file inside it', strpos($body, '"success"') !== false && @file_get_contents($dirA . '/s/papers/pic.txt') === 'D', $body);
    [, $body] = $br->post($u('a'), ['action' => 'bulk-rename', 'in' => 's', 'from[]' => ['papers/pic.txt', 'report-b.txt'], 'to[]' => ['x.txt', 'report-a.txt']]);
    check('a conflict in one folder renames nothing in the others', strpos($body, 'Target already exists: report-a.txt') !== false
        && file_exists($dirA . '/s/papers/pic.txt') && !file_exists($dirA . '/s/papers/x.txt'), $body);
    [, $body] = $br->post($u('a'), ['action' => 'bulk-rename', 'in' => 's', 'from[]' => '../s/report-b.txt', 'to[]' => 'z.txt']);
    check('bulk rename with .. refused', strpos($body, 'Invalid name in batch') !== false && file_exists($dirA . '/s/report-b.txt'), $body);
    [, $body] = $br->post($u('a'), ['action' => 'bulk-rename', 'in' => 's', 'from[]' => 'report-b.txt', 'to[]' => 'dest/z.txt']);
    check('new name with a folder in it refused', strpos($body, 'Invalid name in batch') !== false && !file_exists($dirA . '/s/dest/z.txt'), $body);
    [, $body] = $br->post($u('a'), ['action' => 'chmod', 'in' => 's', 'file_names[]' => 'dest/photo.txt', 'file_mode' => '444']);
    clearstatcache();
    check('chmod of a result in a subfolder', strpos($body, '"ok":["dest\/photo.txt"]') !== false || strpos($body, '"ok":["dest/photo.txt"]') !== false, $body);
    check('chmod made the file read-only', !is_writable($dirA . '/s/dest/photo.txt'));
    $br->post($u('a'), ['action' => 'chmod', 'in' => 's', 'file_names[]' => 'dest/photo.txt', 'file_mode' => '644']);
    [, $body] = $br->post($u('a'), ['action' => 'chmod', 'in' => 's', 'file_names[]' => '../s/report-b.txt', 'file_mode' => '444']);
    check('chmod with .. refused', strpos($body, 'invalid name') !== false, $body);

    if ($sLink) {
        [, $body] = $br->post($u('a'), ['action' => 'delete-stream', 'in' => 's', 'names[]' => 'out/report-outside.txt']);
        check('a path through a link to outside the root is refused', strpos($body, 'Nothing to delete') !== false && file_exists($outside . '/report-outside.txt'), $body);
        [, $body] = $br->post($u('a'), ['action' => 'bulk-rename', 'in' => 's', 'from[]' => 'out/report-outside.txt', 'to[]' => 'renamed.txt']);
        check('bulk rename through a link to outside refused', strpos($body, '"error"') !== false && file_exists($outside . '/report-outside.txt'), $body);
        [, $body] = $br->post($u('a'), ['action' => 'chmod', 'in' => 's', 'file_names[]' => 'out/report-outside.txt', 'file_mode' => '444']);
        clearstatcache();
        check('chmod through a link to outside refused', strpos($body, 'not found') !== false && is_writable($outside . '/report-outside.txt'), $body);
        rmTree($dirA . '/s/out');
    }
    @unlink($outside . '/report-outside.txt');

    section('Archive jobs');
    mkdir($dirA . '/arc/inner', 0775, true);
    file_put_contents($dirA . '/arc/inner/a.txt', str_repeat('a', 5000));
    file_put_contents($dirA . '/arc/b.txt', 'b');
    $tok = bin2hex(random_bytes(16));
    [, $body] = $br->post($u('a'), ['action' => 'compress', 'in' => 'arc', 'names[]' => ['inner', 'b.txt'], 'archive_name' => 'arc', 'archive_type' => 'zip', 'total' => '2', 'dl_token' => $tok]);
    $lines = $ndjson($body);
    $last = end($lines) ?: [];
    check('compress streams progress and ends with done', !empty($last['done']) && !array_filter($lines, static fn($l) => isset($l['error'])) && is_file($dirA . '/arc/arc.zip'), $body);
    [, $body] = $br->req($u('a') . '?action=download-status&token=' . $tok);
    check('compress job status is done (read after a dropped connection)', strpos($body, '"state":"done"') !== false && strpos($body, '"items":') !== false, $body);
    $tok = bin2hex(random_bytes(16));
    [, $body] = $br->post($u('a'), ['action' => 'compress', 'in' => 'arc', 'names[]' => 'b.txt', 'archive_name' => 'arc', 'archive_type' => 'zip', 'dl_token' => $tok]);
    [, $st] = $br->req($u('a') . '?action=download-status&token=' . $tok);
    check('a failed job keeps its reason in the status', strpos($body, 'already exists') !== false && strpos($st, '"state":"error"') !== false && strpos($st, 'already exists') !== false, $st);
    [, $body] = $br->req($u('a') . '?action=download-status&token=' . bin2hex(random_bytes(16)));
    check('unknown job token is pending', strpos($body, '"state":"pending"') !== false, $body);
    [, $body] = $br->post($u('a'), ['action' => 'compress', 'in' => 'arc', 'names[]' => 'b.txt', 'archive_name' => 'other.zip', 'archive_type' => 'tar']);
    check('archive name with another archive extension gets the right one', is_file($dirA . '/arc/other.tar') && !file_exists($dirA . '/arc/other.zip.tar'), $body);
    $tok = bin2hex(random_bytes(16));
    [, $body] = $br->post($u('a'), ['action' => 'compress', 'in' => 'arc', 'names[]' => ['inner', 'b.txt'], 'archive_name' => 'gz', 'archive_type' => 'gzip', 'total' => '2', 'dl_token' => $tok]);
    $lines = $ndjson($body);
    $last = end($lines) ?: [];
    $counts = array_values(array_filter(array_map(static fn($l) => $l['n'] ?? null, $lines), static fn($n) => $n !== null));
    check('PHP tar.gz is written in one pass and counts files', !empty($last['done']) && end($counts) === 2 && strpos($body, 'Writing and compressing') !== false, $body);
    $gzEntries = [];
    try {
        foreach (new RecursiveIteratorIterator(new PharData($dirA . '/arc/gz.tar.gz')) as $f) {
            $gzEntries[] = str_replace('\\', '/', substr($f->getPathname(), strpos($f->getPathname(), 'gz.tar.gz') + 10));
        }
    } catch (Throwable $e) {
        $gzEntries = [$e->getMessage()];
    }
    sort($gzEntries);
    check('PHP tar.gz keeps folder paths and leaves no .tar', $gzEntries === ['b.txt', 'inner/a.txt'] && !file_exists($dirA . '/arc/gz.tar'), implode(', ', $gzEntries));
    [, $body] = $br->post($u('a'), ['action' => 'compress', 'in' => 'arc', 'names[]' => 'inner', 'archive_name' => 'plain', 'archive_type' => 'tar']);
    $tarEntries = [];
    try {
        foreach (new RecursiveIteratorIterator(new PharData($dirA . '/arc/plain.tar')) as $f) {
            $tarEntries[] = str_replace('\\', '/', substr($f->getPathname(), strpos($f->getPathname(), 'plain.tar') + 10));
        }
    } catch (Throwable $e) {
        $tarEntries = [$e->getMessage()];
    }
    check('PHP tar keeps the folder name in paths', $tarEntries === ['inner/a.txt'], implode(', ', $tarEntries) . ' ' . $body);
    @mkdir($dirA . '/.fm-state');
    [, $body] = $br->req($u('a') . '?action=open&folder=' . rawurlencode($dirA));
    check('.fm-state is not listed at the root', strpos($body, '.fm-state') === false, substr($body, 0, 300));
    @rmdir($dirA . '/.fm-state');

    section('Archive filter (include / exclude)');
    $zipNames = static function (string $file): array {
        $z = new ZipArchive();
        if ($z->open($file) !== true) return ['(not a zip)'];
        $out = [];
        for ($i = 0; $i < $z->numFiles; $i++) $out[] = $z->getNameIndex($i);
        $z->close();
        sort($out);
        return $out;
    };
    $tarFiles = static function (string $file): array {
        $out = [];
        try {
            foreach (new RecursiveIteratorIterator(new PharData($file)) as $f) {
                $out[] = str_replace('\\', '/', substr($f->getPathname(), strpos($f->getPathname(), basename($file)) + strlen(basename($file)) + 1));
            }
        } catch (Throwable $e) {
            $out = [$e->getMessage()];
        }
        sort($out);
        return $out;
    };
    $makeTree = static function (string $root): void {
        mkdir($root . '/flt/site/node_modules/x', 0775, true);
        mkdir($root . '/flt/site/docs');
        mkdir($root . '/flt/site/empty');
        file_put_contents($root . '/flt/site/index.php', '<?php');
        file_put_contents($root . '/flt/site/app.log', 'log');
        file_put_contents($root . '/flt/site/node_modules/x/y.js', 'js');
        file_put_contents($root . '/flt/site/docs/readme.txt', 'readme');
        file_put_contents($root . '/flt/notes.txt', 'notes');
    };
    $makeTree($dirA);
    $excludeZip = ['site/', 'site/docs/', 'site/docs/readme.txt', 'site/empty/', 'site/index.php', 'notes.txt'];
    sort($excludeZip);
    [, $body] = $br->post($u('a'), ['action' => 'compress', 'in' => 'flt', 'names[]' => ['site', 'notes.txt'], 'archive_name' => 'ex', 'archive_type' => 'zip',
        'rules_exclude' => "*.log, node_modules/", 'rules_regex' => '0']);
    $flLines = $ndjson($body); $last = end($flLines) ?: [];
    check('Archive ZIP: excluded files and folders are left out, the rest is kept (also empty folders)',
        $zipNames($dirA . '/flt/ex.zip') === $excludeZip && ($last['filtered'] ?? 0) === 2, json_encode($zipNames($dirA . '/flt/ex.zip')) . ' ' . $body);
    [, $body] = $br->post($u('a'), ['action' => 'get-files-and-folders-count', 'in' => 'flt', 'names[]' => ['site', 'notes.txt'], 'rules_include' => '*.txt']);
    check('count with a filter: only the files that will be added', strpos($body, '"files":2') !== false && strpos($body, '"total":2') !== false, $body);
    [, $body] = $br->post($u('a'), ['action' => 'compress', 'in' => 'flt', 'names[]' => ['site', 'notes.txt'], 'archive_name' => 'inc', 'archive_type' => 'gzip',
        'rules_include' => '*.txt']);
    check('Archive TAR.GZ: Include keeps only matching files', $tarFiles($dirA . '/flt/inc.tar.gz') === ['notes.txt', 'site/docs/readme.txt'], json_encode($tarFiles($dirA . '/flt/inc.tar.gz')) . ' ' . $body);
    [, $body] = $br->post($u('a'), ['action' => 'compress', 'in' => 'flt', 'names[]' => 'site', 'archive_name' => 'none', 'archive_type' => 'zip', 'rules_include' => '*.exe']);
    check('Archive: nothing matches = error, no archive', strpos($body, 'Nothing matches the filter') !== false && !file_exists($dirA . '/flt/none.zip'), $body);
    [, $body] = $br->post($u('a'), ['action' => 'compress', 'in' => 'flt', 'names[]' => 'site', 'archive_name' => 'bad', 'archive_type' => 'zip',
        'rules_exclude' => '(unclosed', 'rules_regex' => '1']);
    check('Archive: a bad regular expression is refused before anything is written', strpos($body, 'Filter: Exclude, line 1') !== false && !file_exists($dirA . '/flt/bad.zip'), $body);
    [, $body] = $br->post($u('a'), ['action' => 'archive-filter-preview', 'mode' => 'selection', 'in' => 'flt', 'names[]' => 'site', 'rules_exclude' => '[', 'rules_regex' => '1', 'validate_only' => '1']);
    check('rules check before the job names the bad line', strpos($body, '"error"') !== false && strpos($body, 'line 1') !== false, $body);
    [, $body] = $br->post($u('a'), ['action' => 'archive-filter-preview', 'mode' => 'selection', 'in' => 'flt', 'names[]' => ['site', 'notes.txt'], 'rules_exclude' => "*.log\nnode_modules/"]);
    $pv = json_decode($body, true) ?: [];
    check('Check (selection): kept and skipped counts, size and names', ($pv['kept'] ?? 0) === 6 && ($pv['skipped'] ?? 0) === 2 && ($pv['kept_size'] ?? 0) === 16
        && in_array('site/node_modules/', $pv['skipped_names'] ?? [], true), $body);
    [, $body] = $br->post($u('a'), ['action' => 'download-archive', 'in' => 'flt', 'names[]' => ['site', 'notes.txt'], 'archive_name' => 'dl', 'archive_type' => 'zip',
        'rules_exclude' => 'docs/, *.log, node_modules/']);
    file_put_contents($dirA . '/flt/dl-got.zip', $body);
    check('Download as archive: the filter applies to the streamed ZIP',
        $zipNames($dirA . '/flt/dl-got.zip') === ['notes.txt', 'site/', 'site/empty/', 'site/index.php'], json_encode($zipNames($dirA . '/flt/dl-got.zip')));
    $zip = new ZipArchive();
    $zip->open($dirA . '/flt/pack.zip', ZipArchive::CREATE);
    foreach (['p/a.txt', 'p/b.log', 'p/sub/c.txt', 'p/sub/d.jpg'] as $e) {
        $zip->addFromString($e, $e);
    }
    $zip->close();
    [, $body] = $br->post($u('a'), ['action' => 'archive-filter-preview', 'mode' => 'archive', 'in' => 'flt', 'names[]' => 'pack.zip', 'rules_include' => '*.txt']);
    $pv = json_decode($body, true) ?: [];
    check('Check (archive): entries kept and skipped', ($pv['kept'] ?? 0) === 2 && ($pv['skipped'] ?? 0) === 2, $body);
    [, $body] = $br->post($u('a'), ['action' => 'extract', 'in' => 'flt', 'names[]' => 'pack.zip', 'separate_folders' => '1', 'rules_include' => '*.txt']);
    $flLines = $ndjson($body); $last = end($flLines) ?: [];
    check('Extract: only the matching entries are written', is_file($dirA . '/flt/pack/p/a.txt') && is_file($dirA . '/flt/pack/p/sub/c.txt')
        && !file_exists($dirA . '/flt/pack/p/b.log') && !file_exists($dirA . '/flt/pack/p/sub/d.jpg') && ($last['filtered'] ?? 0) === 2, $body);
    rmTree($dirA . '/flt/pack');
    [, $body] = $br->post($u('a'), ['action' => 'extract', 'in' => 'flt', 'names[]' => 'pack.zip', 'separate_folders' => '1', 'rules_include' => '*.exe']);
    check('Extract: nothing matches = error, nothing written', strpos($body, 'matches the filter') !== false && !file_exists($dirA . '/flt/pack'), $body);
    $makeTree($dirB = $www . '/b');
    $brAuto = new Browser();
    $brAuto->login($u('b'), 'pass-B-new-67890');
    [, $body] = $brAuto->post($u('b'), ['action' => 'compress', 'in' => 'flt', 'names[]' => ['site', 'notes.txt'], 'archive_name' => 'ex', 'archive_type' => 'zip',
        'rules_exclude' => "*.log, node_modules/"]);
    check('auto mode: a filtered archive is the same as in PHP mode (OS tools not used)', $zipNames($dirB . '/flt/ex.zip') === $excludeZip, json_encode($zipNames($dirB . '/flt/ex.zip')) . ' ' . $body);
    rmTree($dirB . '/flt');
    $dirE = installCopy('e', 'pass-E-12345', ["\$FM_FILE_OPS_MODE = 'auto';" => "\$FM_FILE_OPS_MODE = 'os';"]);
    $makeTree($dirE);
    $brE = new Browser();
    $brE->login($u('e'), 'pass-E-12345');
    [, $body] = $brE->post($u('e'), ['action' => 'compress', 'in' => 'flt', 'names[]' => 'site', 'archive_name' => 'os', 'archive_type' => 'zip', 'rules_exclude' => '*.log']);
    check('OS-only mode: a filter is refused with the reason', strpos($body, 'file ops mode is OS only') !== false && !file_exists($dirE . '/flt/os.zip'), $body);
    $zip = new ZipArchive();
    $zip->open($dirE . '/flt/pack.zip', ZipArchive::CREATE);
    $zip->addFromString('a.txt', 'a');
    $zip->close();
    [, $body] = $brE->post($u('e'), ['action' => 'extract', 'in' => 'flt', 'names[]' => 'pack.zip', 'separate_folders' => '1', 'rules_exclude' => '*.log']);
    check('OS-only mode: a filtered extract is refused', strpos($body, 'file ops mode is OS only') !== false && !file_exists($dirE . '/flt/pack'), $body);
    rmTree($dirA . '/flt');

    section('Progress of file jobs');
    // Copy A runs in PHP; copy B in auto mode, i.e. OS commands followed line by line where exec() works.
    $brB = new Browser();
    $brB->login($u('b'), 'pass-B-new-67890');
    $dirB = $www . '/b';
    $jobLines = static function (string $body) use ($ndjson): array {
        $lines = $ndjson($body);
        $ns = array_values(array_filter($lines, static fn($l) => isset($l['n'])));
        return [$lines, $ns, end($ns) ?: []];
    };
    foreach (['a' => [$br, $dirA, 'PHP'], 'b' => [$brB, $dirB, 'auto']] as $copy => [$who, $root, $mode]) {
        mkdir($root . '/job/src/sub', 0775, true);
        for ($i = 1; $i <= 6; $i++) {
            file_put_contents($root . '/job/src/sub/f' . $i . '.txt', str_repeat('x', 100));
        }
        file_put_contents($root . '/job/src/top.txt', 'top');
        mkdir($root . '/job/dest');
        [, $body] = $who->post($u($copy), ['action' => 'copy-move-stream', 'operation' => 'copy', 'in' => 'job', 'dest' => 'job/dest', 'names[]' => 'src']);
        [$lines, $ns, $lastN] = $jobLines($body);
        $total = $lines[0]['total'] ?? 0;
        check("$mode copy: total first, counts up to it, names, done", $total >= 8 && ($lastN['n'] ?? 0) === $total
            && array_filter($ns, static fn($l) => ($l['name'] ?? '') !== '') && !empty(end($lines)['done'])
            && is_file($root . '/job/dest/src/sub/f6.txt') && strpos($body, 'verbose') === false, $body);
        [, $body] = $who->post($u($copy), ['action' => 'delete-stream', 'in' => 'job', 'names[]' => 'dest']);
        [$lines, , $lastN] = $jobLines($body);
        clearstatcache();
        $total = $lines[0]['total'] ?? 0;
        check("$mode delete forever: total first, counts up to it, done", $total >= 9 && ($lastN['n'] ?? 0) === $total
            && !empty(end($lines)['done']) && !file_exists($root . '/job/dest'), $body);
        $zip = new ZipArchive();
        $zip->open($root . '/job/pack.zip', ZipArchive::CREATE);
        foreach (['p/a.txt', 'p/b.txt', 'p/c.txt'] as $e) {
            $zip->addFromString($e, $e);
        }
        $zip->close();
        [, $body] = $who->post($u($copy), ['action' => 'extract', 'in' => 'job', 'names[]' => 'pack.zip', 'separate_folders' => '1']);
        [$lines, , $lastN] = $jobLines($body);
        check("$mode extract: entries counted up to the total, done", ($lines[0]['total'] ?? 0) === 3 && ($lastN['n'] ?? 0) === 3
            && !empty(end($lines)['done']) && is_file($root . '/job/pack/p/c.txt'), $body);
        rmTree($root . '/job');
    }

    section('Long selections (max_input_vars)');
    mkdir($dirA . '/many', 0775, true);
    $manyNames = [];
    for ($i = 1; $i <= 1500; $i++) {
        $manyNames[] = sprintf('f%04d.txt', $i);
        file_put_contents($dirA . '/many/' . end($manyNames), 'x');
    }
    [, $body] = $br->post($u('a'), ['action' => 'get-files-and-folders-count', 'in' => 'many', 'names[]' => $manyNames]);
    $plain = preg_match('/\{"status".*\}/s', $body, $m) ? (json_decode($m[0], true) ?: []) : [];
    check('PHP itself drops fields past max_input_vars (1,500 names[] fields)',
        strpos($body, 'Input variables exceeded') !== false || (($plain['total'] ?? 0) > 0 && ($plain['total'] ?? 0) < 1500), substr($body, 0, 200));
    [, $body] = $br->post($u('a'), ['action' => 'get-files-and-folders-count', 'in' => 'many', 'fm_lists' => json_encode(['names' => $manyNames])]);
    $packed = json_decode($body, true) ?: [];
    check('the same list in fm_lists arrives in full', ($packed['total'] ?? 0) === 1500, $body);
    [, $body] = $br->post($u('a'), ['action' => 'get-files-and-folders-count', 'in' => 'many', 'fm_lists' => '{"names": "not a list"}']);
    check('a broken fm_lists is refused', strpos($body, 'Invalid list') !== false, $body);
    [, $body] = $br->post($u('a'), ['action' => 'delete-stream', 'in' => 'many', 'fm_lists' => json_encode(['names' => $manyNames])]);
    clearstatcache();
    check('delete forever of 1,500 selected files removes all of them', count(glob($dirA . '/many/*') ?: []) === 0 && strpos($body, '"done":true') !== false, substr($body, -200));
    rmTree($dirA . '/many');
    mkdir($dirA . '/keep/dest', 0775, true);
    file_put_contents($dirA . '/keep/old.txt', 'old');
    touch($dirA . '/keep/old.txt', strtotime('2020-05-06 07:08:09'));
    foreach (['a' => $br, 'b' => $brB] as $copy => $who) {
        $root = $copy === 'a' ? $dirA : $dirB;
        if ($copy === 'b') {
            mkdir($root . '/keep/dest', 0775, true);
            file_put_contents($root . '/keep/old.txt', 'old');
            touch($root . '/keep/old.txt', strtotime('2020-05-06 07:08:09'));
        }
        $who->post($u($copy), ['action' => 'copy-move-stream', 'operation' => 'copy', 'in' => 'keep', 'dest' => 'keep/dest', 'names[]' => 'old.txt']);
        clearstatcache();
        check("copy of one file keeps its modified time ($copy)", @filemtime($root . '/keep/dest/old.txt') === strtotime('2020-05-06 07:08:09'));
        rmTree($root . '/keep');
    }

    section('Get info for several items');
    mkdir($dirA . '/info/sub/inner', 0775, true);
    file_put_contents($dirA . '/info/a.txt', str_repeat('a', 100));
    file_put_contents($dirA . '/info/b.jpg', str_repeat('b', 200));
    file_put_contents($dirA . '/info/sub/c.txt', str_repeat('c', 50));
    file_put_contents($dirA . '/info/sub/d.png', str_repeat('d', 30));
    file_put_contents($outside . '/secret.txt', 'x');
    $infoOf = static function (string $body) use ($ndjson): array {
        $lines = $ndjson($body);
        $last = end($lines) ?: [];
        return !empty($last['done']) ? (array)$last['info'] : [];
    };
    [, $body] = $br->post($u('a'), ['action' => 'get-info-multi', 'paths[]' => [$dirA . '/info/a.txt', $dirA . '/info/b.jpg', $dirA . '/info/sub', $outside . '/secret.txt']]);
    $inf = $infoOf($body);
    check('summary: counts, size with folder contents, inside counts, location, outside path not counted',
        ($inf['count'] ?? 0) === 3 && $inf['files'] === 2 && $inf['folders'] === 1 && $inf['size'] === 380
        && $inf['inside_files'] === 2 && $inf['inside_folders'] === 1 && $inf['location'] === 'info' && $inf['missing'] === 1, $body);
    check('summary: types most common first, with their size', ($inf['types'][0] ?? null) === ['txt', 2, 150] && count($inf['types']) === 3, json_encode($inf['types'] ?? null));
    [, $body] = $br->req($u('a') . '?action=get-info&path=' . rawurlencode($dirA . '/info/sub'));
    $one = json_decode($body, true) ?: [];
    check('Get info of a folder: size and what it holds in one answer (the folder itself not counted)',
        ($one['size'] ?? null) === 80 && ($one['files_count'] ?? null) === 2 && ($one['folders_count'] ?? null) === 1, $body);
    check('Get info of a folder: file types with count and size', ($one['types'] ?? null) === [['png', 1, 30], ['txt', 1, 50]], json_encode($one['types'] ?? null));
    [, $body] = $br->post($u('a'), ['action' => 'get-info-multi', 'paths[]' => [$dirA . '/info/a.txt', $dirA . '/info/sub/c.txt']]);
    $inf = $infoOf($body);
    check('summary of items from different folders has no single location', ($inf['count'] ?? 0) === 2 && $inf['location'] === null && !empty($inf['modified_min']), $body);
    rmTree($dirA . '/info');
    @unlink($outside . '/secret.txt');

    section('Convert encoding');
    $ruText = "Бюджет на 2026 год утверждён.\nОтчёт готов, счёт оплачен.\n";
    $ruCp = (string)iconv('UTF-8', 'Windows-1251', $ruText);
    mkdir($dirA . '/enc/deep', 0775, true);
    file_put_contents($dirA . '/enc/ru.txt', $ruCp);
    file_put_contents($dirA . '/enc/deep/old.txt', $ruCp);
    file_put_contents($dirA . '/enc/u.txt', $ruText);
    file_put_contents($dirA . '/enc/plain.txt', "plain\n");
    file_put_contents($dirA . '/enc/bin.dat', str_repeat("\x00\x01\x02\xFF", 64));
    file_put_contents($outside . '/o.txt', $ruCp);
    $eLink = makeDirLink($dirA . '/enc/out', $outside);
    [, $body] = $br->post($u('a'), ['action' => 'encoding-info', 'in' => 'enc', 'names[]' => ['ru.txt', 'u.txt', 'bin.dat', '../x.txt']]);
    $info = json_decode($body, true) ?: [];
    $byName = array_column($info['files'] ?? [], null, 'name');
    check('info: detected encodings, binary and invalid names skipped', ($byName['ru.txt']['encoding'] ?? '') === 'Windows-1251' && ($byName['u.txt']['encoding'] ?? '') === 'UTF-8'
        && ($byName['bin.dat']['skip'] ?? '') === 'binary' && ($byName['x.txt']['skip'] ?? '') === 'invalid' && in_array('ARMSCII-8', $info['encodings'] ?? [], true), $body);
    [, $body] = $br->post($u('a'), ['action' => 'encoding-scan', 'in' => 'enc']);
    $lines = $ndjson($body);
    $last = end($lines) ?: [];
    check('scan: files not in UTF-8 in all subfolders, links not followed', $found($lines) === ['deep/old.txt', 'ru.txt'] && !empty($last['done']), $body);
    [, $body] = $br->post($u('a'), ['action' => 'encoding-scan', 'in' => '', 'names[]' => 'enc', 'recursive' => '0', 'not_utf8' => '0', 'mask' => '*.txt']);
    check('scan: a selected folder, no subfolders, all text files', $found($ndjson($body)) === ['enc/plain.txt', 'enc/ru.txt', 'enc/u.txt'], $body);
    [, $body] = $br->post($u('a'), ['action' => 'encoding-scan', 'in' => '', 'names[]' => '../..']);
    check('scan: .. refused', strpos($body, 'Invalid name') !== false, $body);
    [, $body] = $br->post($u('a'), ['action' => 'encoding-preview', 'in' => 'enc', 'name' => 'ru.txt', 'from' => 'auto', 'to' => 'UTF-8']);
    $prev = json_decode($body, true) ?: [];
    check('preview: text after decoding', ($prev['after'][0] ?? '') === 'Бюджет на 2026 год утверждён.' && ($prev['from'] ?? '') === 'Windows-1251' && strpos($prev['now'][0] ?? '', '?') !== false, $body);
    [, $body] = $br->post($u('a'), ['action' => 'encoding-convert', 'in' => 'enc', 'names[]' => 'ru.txt', 'from' => 'auto', 'to' => 'UTF-8', 'mode' => 'copy']);
    check('convert: copy next to the file, original untouched', strpos($body, '"new_name":"ru.utf8.txt"') !== false && @file_get_contents($dirA . '/enc/ru.utf8.txt') === $ruText
        && file_get_contents($dirA . '/enc/ru.txt') === $ruCp, $body);
    [, $body] = $br->post($u('a'), ['action' => 'encoding-convert', 'in' => 'enc', 'names[]' => ['ru.txt', 'deep/old.txt', 'plain.txt', 'bin.dat', '../zz.txt'], 'from' => 'auto', 'to' => 'UTF-8', 'eol' => 'crlf']);
    $res = array_column((json_decode($body, true) ?: [])['files'] ?? [], 'status', 'name');
    check('convert: replace in place (also below the folder), CRLF', file_get_contents($dirA . '/enc/ru.txt') === str_replace("\n", "\r\n", $ruText)
        && file_get_contents($dirA . '/enc/deep/old.txt') === str_replace("\n", "\r\n", $ruText) && ($res['ru.txt'] ?? '') === 'converted', $body);
    check('convert: binary and .. skipped, the rest reported', ($res['bin.dat'] ?? '') === 'skipped' && ($res['zz.txt'] ?? '') === 'skipped' && ($res['plain.txt'] ?? '') === 'converted' && file_get_contents($dirA . '/enc/plain.txt') === "plain\r\n" && count($res) === 5, $body);
    [, $body] = $br->post($u('a'), ['action' => 'encoding-convert', 'in' => 'enc', 'names[]' => 'u.txt', 'from' => 'auto', 'to' => 'Windows-1252']);
    check('convert: characters missing in the target fail, file untouched', strpos($body, '"status":"failed"') !== false && file_get_contents($dirA . '/enc/u.txt') === $ruText, $body);
    [, $body] = $br->post($u('a'), ['action' => 'encoding-convert', 'in' => 'enc', 'names[]' => 'u.txt', 'from' => 'auto', 'to' => 'UTF-8']);
    check('convert: already in the target encoding', strpos($body, '"status":"already"') !== false, $body);
    file_put_contents($dirA . '/enc/legacy.txt', $ruCp);
    [, $body] = $br->req($u('a') . '?action=get-info&path=' . rawurlencode($dirA . '/enc/legacy.txt'));
    $gi = json_decode($body, true) ?: [];
    check('Get info: a file reports its encoding', ($gi['encoding']['encoding'] ?? '') === 'Windows-1251' && ($gi['encoding']['how'] ?? '') === 'guess', $body);
    [, $body] = $br->post($u('a'), ['action' => 'search', 'in' => 'enc', 'content' => 'счёт']);
    $hit = array_values(array_filter(array_merge(...array_map(static fn($l) => $l['items'] ?? [], $ndjson($body))), static fn($it) => $it['path'] === 'legacy.txt'))[0] ?? [];
    check('Find: text found in a Windows-1251 file, line decoded', ($hit['match']['encoding']['name'] ?? '') === 'Windows-1251' && strpos($hit['match']['text'] ?? '', 'счёт оплачен') !== false, $body);
    unlink($dirA . '/enc/legacy.txt');
    [, $body] = $br->post($u('a'), ['action' => 'encoding-convert', 'in' => 'enc', 'names[]' => 'u.txt', 'from' => 'auto', 'to' => 'EBCDIC-XYZ']);
    check('convert: unknown encoding refused', strpos($body, 'cannot convert') !== false, $body);
    if ($eLink) {
        [, $body] = $br->post($u('a'), ['action' => 'encoding-convert', 'in' => 'enc', 'names[]' => 'out/o.txt', 'from' => 'auto', 'to' => 'UTF-8']);
        check('convert: a file through a link to outside the root is not touched', strpos($body, '"status":"skipped"') !== false && file_get_contents($outside . '/o.txt') === $ruCp, $body);
        rmTree($dirA . '/enc/out');
    }
    @unlink($outside . '/o.txt');
    check('convert: no temp files left', glob($dirA . '/enc/.fm-conv-*') === [] && glob($dirA . '/enc/deep/.fm-conv-*') === []);
    [, $body] = $br->post($u('a'), ['action' => 'search', 'in' => 'enc', 'encoding' => 'not_utf8']);
    check('Find: encoding filter after the conversion finds nothing', $found($ndjson($body)) === [], $body);

    section('Undo of Move to Trash');
    file_put_contents($dirA . '/up/undo-me.txt', 'U');
    [, $body] = $br->post($u('a'), ['action' => 'trash-move', 'in' => 'up', 'names[]' => 'undo-me.txt']);
    $moved = json_decode($body, true) ?: [];
    check('Move to Trash answers the trash id of each moved item', ($moved['ok'] ?? null) === ['undo-me.txt'] && count($moved['ids'] ?? []) === 1
        && !file_exists($dirA . '/up/undo-me.txt'), $body);
    [, $body] = $br->post($u('a'), ['action' => 'trash-restore', 'ids[]' => $moved['ids'] ?? []]);
    check('Undo (restore by those ids) puts the item back where it was', @file_get_contents($dirA . '/up/undo-me.txt') === 'U', $body);
    @unlink($dirA . '/up/undo-me.txt');

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

<?php
declare(strict_types=1);

/*
 * Unit tests: call SoloFileManager helpers directly (path boundary, IP allowlist, archives, uploads, sessions).
 * Run: php tests/unit.php
 */

require __DIR__ . '/lib.php';

putenv('SOLOFM_TEST_INCLUDE=1');
ob_start();
require dirname(__DIR__) . '/solofilemanager.php';
ob_end_clean();

$base = scratch('unit');
$root = $base . '/root';
$outside = $base . '/outside';
mkdir($root . '/sub', 0775, true);
mkdir($base . '/root-private', 0775, true);
mkdir($outside, 0775, true);
file_put_contents($root . '/sub/file.txt', 'x');
$haveLink = makeDirLink($root . '/out-link', $outside);

section('Root boundary (pathInsideRoot)');
check('root itself', pathInsideRoot($root, $root));
check('existing child', pathInsideRoot($root . '/sub/file.txt', $root));
check('new child that does not exist yet', pathInsideRoot($root . '/sub/new/deeper.txt', $root));
check('neighbour with the same prefix is outside', !pathInsideRoot($base . '/root-private', $root));
check('.. leads outside', !pathInsideRoot($root . '/../outside', $root));
check('.. in a new path is refused', !pathInsideRoot($root . '/sub/../../outside/x', $root));
if ($haveLink) {
    check('folder link to outside is outside', !pathInsideRoot($root . '/out-link', $root));
    check('new file through that link is outside', !pathInsideRoot($root . '/out-link/new.txt', $root));
} else {
    echo "  (skipped link checks: could not create a folder link)\n";
}

section('Upload relative paths (fmSanitizeUploadRelativePath)');
check('nested path kept', fmSanitizeUploadRelativePath('a/b/c.txt') === 'a/b/c.txt');
check('backslashes normalised', fmSanitizeUploadRelativePath('a\\b.txt') === 'a/b.txt');
check('.. refused', fmSanitizeUploadRelativePath('../x.txt') === null);
check('.. in the middle refused', fmSanitizeUploadRelativePath('a/../../x.txt') === null);
check('colon refused', fmSanitizeUploadRelativePath('c:x.txt') === null);
check('empty refused', fmSanitizeUploadRelativePath('/') === null);

section('IP allowlist');
check('IPv4 exact', fmIpMatchesEntry('203.0.113.5', '203.0.113.5'));
check('IPv4 /24 inside', fmIpMatchesEntry('192.168.1.77', '192.168.1.0/24'));
check('IPv4 /24 outside', !fmIpMatchesEntry('192.168.2.1', '192.168.1.0/24'));
check('IPv6 /32', fmIpMatchesEntry('2001:db8:abcd::1', '2001:db8::/32'));
check('IPv4-mapped IPv6 client vs IPv4 range', fmIpMatchesEntry('::ffff:192.168.1.5', '192.168.1.0/24'));
check('invalid prefix', !fmIpEntryIsValid('1.2.3.4/33'));
check('garbage entry never matches', !fmIpMatchesList('1.2.3.4', ['not-an-ip']));
$parsed = fmParseIpListInput("203.0.113.5\n10.0.0.0/8, 10.0.0.0/8 ; bad");
check('list parsing dedupes and reports invalid', $parsed['list'] === ['203.0.113.5', '10.0.0.0/8'] && $parsed['invalid'] === ['bad']);
$GLOBALS['FM_TRUSTED_PROXIES'] = [];
$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1';
check('X-Forwarded-For ignored without trusted proxies', fmClientIp() === '198.51.100.9');
$GLOBALS['FM_TRUSTED_PROXIES'] = ['198.51.100.0/24'];
$_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 203.0.113.5, 198.51.100.20';
check('trusted proxy: right-most untrusted hop', fmClientIp() === '203.0.113.5');
$_SERVER['REMOTE_ADDR'] = '9.9.9.9';
check('spoofed header from untrusted address ignored', fmClientIp() === '9.9.9.9');
$GLOBALS['FM_TRUSTED_PROXIES'] = [];

section('Archives: listing and safety split');
$arch = $base . '/archives';
mkdir($arch);
$zipPath = $arch . '/hostile.zip';
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('ok.txt', 'ok');
$zip->addFromString('../escaped.txt', 'x');
$zip->addFromString('link', $outside);
$zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
$zip->addFromString('link/through.txt', 'x');
$zip->close();
$entries = fmArchiveListEntries($zipPath, 'zip');
$kinds = [];
foreach ($entries ?? [] as $e) {
    $kinds[$e['name']] = $e['kind'];
}
check('zip listed', is_array($entries) && count($entries) === 4, json_encode($entries));
check('zip symlink entry detected', ($kinds['link'] ?? '') === 'link');
$dest = $root . '/extract-here';
$split = fmArchiveSplitEntries($entries ?? [], $dest, $root);
$safeNames = array_column($split['safe'], 'name');
check('zip: only ok.txt and link/through.txt survive the name/kind check', in_array('ok.txt', $safeNames, true) && !in_array('link', $safeNames, true));
check('zip: ../ and the link are unsafe', in_array('../escaped.txt', $split['unsafe'], true) && in_array('link', $split['unsafe'], true));

$tarPath = $arch . '/hostile.tar';
$long = str_repeat('long-folder-name/', 8) . 'file.txt';
writeTar($tarPath, [
    ['ok.txt', 'ok'],
    ['../escaped.txt', 'x'],
    ['tlink', '', '2', $outside],
    ['dir/', '', '5'],
    ['dir/nested.txt', 'nested'],
    [$long, 'long name ok'],
]);
$tEntries = fmArchiveListEntries($tarPath, 'tar');
$tKinds = [];
foreach ($tEntries ?? [] as $e) {
    $tKinds[$e['name']] = $e['kind'];
}
check('tar listed with GNU long name', is_array($tEntries) && isset($tKinds[$long]), json_encode(array_keys($tKinds)));
check('tar symlink detected', ($tKinds['tlink'] ?? '') === 'link');
$tSplit = fmArchiveSplitEntries($tEntries ?? [], $dest, $root);
check('tar: ../ and symlink unsafe', in_array('../escaped.txt', $tSplit['unsafe'], true) && in_array('tlink', $tSplit['unsafe'], true));
if ($haveLink) {
    $s2 = fmArchiveSplitEntries([['name' => 'out-link/evil.txt', 'raw' => 'out-link/evil.txt', 'kind' => 'file']], $root, $root);
    check('entry through an existing link in the destination is unsafe', $s2['unsafe'] === ['out-link/evil.txt']);
}

section('Archives: PHP TAR extraction');
@mkdir($dest, 0775, true);
$only = [];
foreach ($tSplit['safe'] as $e) {
    $only[$e['name']] = true;
}
$err = fmTarExtract($tarPath, false, $dest, $root, $only);
check('extract without error', $err === '', $err);
check('ok.txt written', @file_get_contents($dest . '/ok.txt') === 'ok');
check('nested file written', @file_get_contents($dest . '/dir/nested.txt') === 'nested');
check('long name written', @file_get_contents($dest . '/' . $long) === 'long name ok');
check('nothing escaped next to root', !file_exists($root . '/escaped.txt') && !file_exists($base . '/escaped.txt'));
check('no link created', !file_exists($dest . '/tlink'));
check('nothing written outside', count(array_diff(scandir($outside) ?: [], ['.', '..'])) === 0);
$gzPath = $arch . '/plain.tar.gz';
writeTar($arch . '/plain.tar', [['a.txt', 'AAA'], ['b/c.txt', 'CCC']]);
file_put_contents($gzPath, gzencode((string)file_get_contents($arch . '/plain.tar')));
$gzDest = $root . '/gz-out';
mkdir($gzDest);
check('tar.gz extract', fmTarExtract($gzPath, true, $gzDest, $root, null) === '' && @file_get_contents($gzDest . '/b/c.txt') === 'CCC');
check('entry count via own reader', fmCountArchiveEntries($gzPath, 'tgz') === 2);

section('Upload name conflicts');
$up = $root . '/up';
mkdir($up);
file_put_contents($up . '/report.txt', 'orig');
[$f1] = fmUploadApplyConflict($up . '/report.txt', 'replace');
[$f2] = fmUploadApplyConflict($up . '/report.txt', 'skip');
[$f3, $n3] = fmUploadApplyConflict($up . '/report.txt', 'rename');
[$f4] = fmUploadApplyConflict($up . '/new.txt', 'skip');
check('replace keeps the path', $f1 === $up . '/report.txt');
check('skip returns null', $f2 === null);
check('rename picks "report (1).txt"', $n3 === 'report (1).txt' && $f3 === $up . '/report (1).txt');
check('no conflict: any mode writes', $f4 === $up . '/new.txt');

section('Sessions');
check('session name is SOLOFM_ + 16 hex', (bool)preg_match('/^SOLOFM_[0-9a-f]{16}$/', fmSessionName()));
check('password fingerprint differs per hash', fmPasswordFingerprint('a') !== fmPasswordFingerprint('b'));
check('password fingerprint is stable', fmPasswordFingerprint('a') === fmPasswordFingerprint('a'));

section('Folder listing');
file_put_contents($root . '/sub/big.bin', str_repeat('x', 1234));
file_put_contents($root . '/sub/README.md', 'hidden');
mkdir($root . '/sub/inner');
mkdir($root . '/.trash');
$haveInLink = makeDirLink($root . '/sub/in-link', $root . '/sub/inner');
$ls = getFilesAndFoldersList($root . '/sub', $root, ['.', '..', 'README.md'], true);
$fileMeta = $ls['files']['file.txt'] ?? [];
check('file size and extension', ($ls['files']['big.bin']['size'] ?? null) === 1234 && ($ls['files']['big.bin']['ext'] ?? '') === 'bin');
check('modified time and permissions', ($fileMeta['mtime'] ?? null) === filemtime($root . '/sub/file.txt')
    && ($fileMeta['permissions'] ?? '') === substr(sprintf('%o', fileperms($root . '/sub/file.txt')), -4));
check('plain folder and file are not links', isset($ls['folders']['inner']) && empty($ls['folders']['inner']['link']) && empty($fileMeta['link']));
check('excluded names are not listed', !isset($ls['files']['README.md']));
if ($haveInLink) {
    check('folder link inside the root has its target', !empty($ls['folders']['in-link']['link'])
        && ($ls['folders']['in-link']['link_target'] ?? '') === normPath((string)realpath($root . '/sub/inner')));
}
$lsRoot = getFilesAndFoldersList($root, $root, ['.', '..'], true);
check('Trash folder hidden at the root', !isset($lsRoot['folders']['.trash']) && isset($lsRoot['folders']['sub']));
if ($haveLink) {
    check('folder link outside the root is marked', !empty($lsRoot['folders']['out-link']['link_outside']));
}
if ($haveInLink) {
    rmTree($root . '/sub/in-link');
}

section('Search');
$sr = $base . '/search';
mkdir($sr . '/docs/deep', 0775, true);
mkdir($sr . '/photos', 0775, true);
mkdir($sr . '/.trash/files', 0775, true);
mkdir($sr . '/.fm-upload-tmp', 0775, true);
mkdir($base . '/search-outside', 0775, true);
file_put_contents($sr . '/Report-2024.TXT', str_repeat('x', 10));
file_put_contents($sr . '/docs/report-old.txt', str_repeat('x', 2000));
file_put_contents($sr . '/docs/notes.md', 'n');
touch($sr . '/docs/notes.md', time() - 86400 * 30);
file_put_contents($sr . '/docs/deep/Отчёт.txt', 'r');
file_put_contents($sr . '/README.md', 'excluded');
file_put_contents($sr . '/.trash/files/report-trashed.txt', 't');
file_put_contents($sr . '/.fm-upload-tmp/report.part', 'p');
file_put_contents($base . '/search-outside/report-outside.txt', 'o');
$haveSearchLink = makeDirLink($sr . '/link-out', $base . '/search-outside');
/** Paths found by the PHP engine (sorted), or the criteria error. */
$phpSearch = static function (array $post, int $stopAfter = 0) use ($sr) {
    $c = fmSearchCriteria($post);
    if (isset($c['error'])) {
        return $c['error'];
    }
    $rows = [];
    $scanned = 0;
    $emit = static function (array $r) use (&$rows, $stopAfter): bool {
        $rows[] = $r;
        return $stopAfter === 0 || count($rows) < $stopAfter;
    };
    fmSearchWalkPhp($sr, true, $sr, $c, ['README.md'], $emit, static fn() => true, $scanned);
    $paths = array_column($rows, 'path');
    sort($paths);
    return $paths;
};
check('contains, ignore case', $phpSearch(['name' => 'report']) === ['Report-2024.TXT', 'docs/report-old.txt']);
check('contains, case-sensitive', $phpSearch(['name' => 'report', 'icase' => '0']) === ['docs/report-old.txt']);
check('wildcard *.txt, ignore case', $phpSearch(['name' => '*.txt', 'match' => 'wildcard']) === ['Report-2024.TXT', 'docs/deep/Отчёт.txt', 'docs/report-old.txt']);
check('wildcard is anchored (*.tx finds nothing)', $phpSearch(['name' => '*.tx', 'match' => 'wildcard']) === []);
check('regex with groups', $phpSearch(['name' => '^report-\d{4}', 'match' => 'regex']) === ['Report-2024.TXT']);
check('invalid regex reported', is_string($phpSearch(['name' => '(', 'match' => 'regex'])));
check('Cyrillic, ignore case', $phpSearch(['name' => 'отчёт']) === ['docs/deep/Отчёт.txt']);
check('folders only (links listed, not followed)', $phpSearch(['type' => 'folder']) === ($haveSearchLink ? ['docs', 'docs/deep', 'link-out', 'photos'] : ['docs', 'docs/deep', 'photos']));
check('size from 1000 bytes (folders left out)', $phpSearch(['size_min' => '1000']) === ['docs/report-old.txt']);
check('modified before 10 days ago', $phpSearch(['type' => 'file', 'mtime_to' => (string)(time() - 86400 * 10)]) === ['docs/notes.md']);
check('Trash, unfinished uploads and excluded names are skipped', $phpSearch(['name' => 'report']) === $phpSearch(['name' => 'report', 'type' => 'file']) && !in_array('README.md', $phpSearch([]), true));
check('nothing found through a folder link', $phpSearch(['name' => 'outside']) === []);
check('stops when emit returns false', count($phpSearch([], 2)) === 2);
check('control characters refused', is_string($phpSearch(['name' => "a\x01b"])));

$argv = fmSearchFindArgv($sr, true, fmSearchCriteria(['name' => 'rep*rt']));
check('find: prunes Trash and upload parts at the root', in_array('-prune', $argv, true) && in_array($sr . '/.trash', $argv, true));
check('find: contains text is escaped for -iname', array_slice($argv, -3) === ['-iname', '*rep\\*rt*', '-print0']);
check('find: no name pre-filter for regex', !in_array('-iname', fmSearchFindArgv($sr, false, fmSearchCriteria(['name' => 'a', 'match' => 'regex'])), true));
check('find: no ignore-case pre-filter for non-ASCII', !in_array('-iname', fmSearchFindArgv($sr, false, fmSearchCriteria(['name' => 'отчёт'])), true));
check('find: case-sensitive wildcard uses -name', in_array('-name', fmSearchFindArgv($sr, false, fmSearchCriteria(['name' => '*.txt', 'match' => 'wildcard', 'icase' => '0'])), true));
if (PHP_OS_FAMILY !== 'Windows' && fmIsProcOpenAvailable()) {
    $findSearch = static function (array $post) use ($sr): array {
        $c = fmSearchCriteria($post);
        $paths = [];
        $prefix = $sr . '/';
        fmSearchRunFind(fmSearchFindArgv($sr, true, $c), static function (string $p) use (&$paths, $prefix, $c, $sr): bool {
            $rel = substr($p, strlen($prefix));
            $segs = explode('/', $rel);
            foreach ($segs as $i => $seg) {
                if (fmSearchSkipName($seg, $i === 0, ['README.md'], '.trash')) {
                    return true;
                }
            }
            if (fmSearchEntry($c, $p, $rel, (string)end($segs), is_dir($p), $sr) !== null) {
                $paths[] = $rel;
            }
            return true;
        }, static fn() => true);
        sort($paths);
        return $paths;
    };
    foreach ([['name' => 'report'], ['name' => '*.txt', 'match' => 'wildcard'], ['name' => 'отчёт'], ['type' => 'folder'], ['name' => '^report-\d{4}', 'match' => 'regex'], ['content' => 'x']] as $q) {
        check('find and PHP return the same: ' . json_encode($q, JSON_UNESCAPED_UNICODE), $findSearch($q) === $phpSearch($q));
    }
} else {
    echo "  (skipped find(1) comparison: Windows or proc_open unavailable)\n";
}

section('Search: text inside files');
$cs = $base . '/content';
mkdir($cs . '/sub', 0775, true);
file_put_contents($cs . '/a.txt', "first line\n    Hello World here\nthird\n");
file_put_contents($cs . '/b.txt', "no match\n");
file_put_contents($cs . '/sub/ru.txt', "Строка один\nПРИВЕТ мир\n");
file_put_contents($cs . '/bin.dat', "abc\0hello");
file_put_contents($cs . '/cp1251.txt', "\xcf\xf0\xe8 hello\n");
file_put_contents($cs . '/nonl.txt', 'x hello');
file_put_contents($cs . '/long.txt', str_repeat('a', 70000) . "hello\nnext hello");
/** Content search below $cs: [path => match]. */
$contentSearch = static function (array $post, ?callable $tune = null) use ($cs) {
    $c = fmSearchCriteria($post);
    if (isset($c['error'])) {
        return $c['error'];
    }
    if ($tune) {
        $c = $tune($c);
    }
    $rows = [];
    $scanned = 0;
    fmSearchWalkPhp($cs, false, $cs, $c, [], static function (array $r) use (&$rows): bool {
        $rows[$r['path']] = $r['match'] ?? null;
        return true;
    }, static fn() => true, $scanned);
    ksort($rows);
    return $rows;
};
$r = $contentSearch(['content' => 'hello']);
check('text, ignore case: text files only, binary skipped, folders left out', is_array($r) && array_keys($r) === ['a.txt', 'cp1251.txt', 'long.txt', 'nonl.txt'], json_encode($r));
check('line number and snippet without indentation', ($r['a.txt'] ?? null) === ['line' => 2, 'text' => 'Hello World here', 'at' => 0, 'len' => 5], json_encode($r['a.txt'] ?? null));
check('line without a final newline', ($r['nonl.txt']['line'] ?? 0) === 1 && ($r['nonl.txt']['text'] ?? '') === 'x hello');
check('long line checked in parts, still line 1, snippet cut at the start', ($r['long.txt']['line'] ?? 0) === 1 && strpos($r['long.txt']['text'] ?? '', '…') === 0
    && mb_substr($r['long.txt']['text'], $r['long.txt']['at'], 5) === 'hello');
check('non-UTF-8 line matched byte by byte, its bytes shown as ?', ($r['cp1251.txt']['text'] ?? '') === '??? hello');
check('case-sensitive text', array_keys((array)$contentSearch(['content' => 'Hello', 'content_icase' => '0'])) === ['a.txt']);
$r = $contentSearch(['content' => 'привет']);
check('Cyrillic, ignore case', array_keys((array)$r) === ['sub/ru.txt'] && ($r['sub/ru.txt']['line'] ?? 0) === 2 && ($r['sub/ru.txt']['len'] ?? 0) === 6, json_encode($r, JSON_UNESCAPED_UNICODE));
check('regex', array_keys((array)$contentSearch(['content' => '^(first|third)$', 'content_match' => 'regex'])) === ['a.txt']);
check('invalid regex reported', is_string($contentSearch(['content' => '(', 'content_match' => 'regex'])));
check('name and text together', array_keys((array)$contentSearch(['name' => 'nonl', 'content' => 'hello'])) === ['nonl.txt']);
check('file bigger than the limit is skipped', !isset($contentSearch(['content' => 'hello'], static function (array $c): array {
    $c['content_max_file'] = 100;
    return $c;
})['long.txt']));
$budgetCrit = fmSearchCriteria(['content' => 'zzz']);
$budgetCrit['content_state']->left = 20;
$contentSearch(['content' => 'zzz'], static fn(array $c): array => $budgetCrit);
check('read budget is used up and stays shared', $budgetCrit['content_state']->left <= 0);
$zero = 0;
check('nothing is read once the budget is used up', fmSearchContentMatch(fmSearchCriteria(['content' => 'hello']), $cs . '/a.txt', $cs, $zero) === null);
if ($haveSearchLink) {
    $left = 1000;
    check('file through a link to outside the root is not read', fmSearchContentMatch(fmSearchCriteria(['content' => 'o']), $sr . '/link-out/report-outside.txt', $sr, $left) === null && $left === 1000);
}
check('find: text search keeps files and links only', in_array('-type', fmSearchFindArgv($cs, false, fmSearchCriteria(['content' => 'x'])), true)
    && !in_array('-type', fmSearchFindArgv($cs, false, fmSearchCriteria(['name' => 'x'])), true));

section('Item names from names[]');
check('plain name', fmRequestItemName('a.txt') === 'a.txt');
check('relative path from search results', fmRequestItemName('docs/a.txt') === 'docs/a.txt');
check('.. refused', fmRequestItemName('docs/../x') === null);
check('empty segment refused', fmRequestItemName('docs//a') === null && fmRequestItemName('/a') === null);
check('backslash and colon refused', fmRequestItemName('a\\b') === null && fmRequestItemName('c:x') === null);

if ($haveSearchLink) {
    rmTree($sr . '/link-out');
}
rmTree($base);
exit(summary('unit'));

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
$legacyText = "Первая строка отчёта за квартал.\nБюджет на 2026 год утверждён советом.\n";
file_put_contents($cs . '/koi8.txt', iconv('UTF-8', 'KOI8-R', $legacyText));
file_put_contents($cs . '/u16.txt', "\xFF\xFE" . iconv('UTF-8', 'UTF-16LE', $legacyText));
file_put_contents($cs . '/u16-nobom.txt', iconv('UTF-8', 'UTF-16BE', $legacyText));
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
check('legacy line decoded before matching, encoding reported', ($r['cp1251.txt']['text'] ?? '') === 'При hello'
    && ($r['cp1251.txt']['encoding']['name'] ?? '') === 'Windows-1251' && !isset($r['a.txt']['encoding']), json_encode($r['cp1251.txt'] ?? null, JSON_UNESCAPED_UNICODE));
$r = $contentSearch(['content' => 'бюджет']);
check('Cyrillic found in KOI8-R and UTF-16 files (with and without BOM)', array_keys((array)$r) === ['koi8.txt', 'u16-nobom.txt', 'u16.txt'], json_encode($r, JSON_UNESCAPED_UNICODE));
check('decoded match: line number, text and encoding', ($r['koi8.txt']['line'] ?? 0) === 2 && strpos($r['koi8.txt']['text'] ?? '', 'Бюджет на 2026') === 0
    && ($r['koi8.txt']['encoding']['name'] ?? '') === 'KOI8-R' && ($r['u16.txt']['line'] ?? 0) === 2 && ($r['u16.txt']['encoding']['how'] ?? '') === 'bom'
    && ($r['u16-nobom.txt']['encoding']['name'] ?? '') === 'UTF-16BE', json_encode($r, JSON_UNESCAPED_UNICODE));
check('short binary data with NUL bytes is not taken for UTF-16', fmDetectEncoding("abc\0hello")['how'] === 'binary');
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

section('Encodings');
$encList = fmEncodingList();
check('the server converts the main encodings', !array_diff(['UTF-8', 'UTF-16LE', 'UTF-16BE', 'Windows-1251', 'KOI8-R', 'CP866', 'Windows-1252', 'ARMSCII-8'], $encList), implode(',', $encList));
$ru = "Привет! Это тестовый текст для проверки кодировки. Бюджет на 2026 год утверждён, отчёт готов.\nВторая строка: съешь же ещё этих мягких французских булок, да выпей чаю.\n";
$texts = [
    'fr' => "Le café est très bon. Où est la bibliothèque? Ça va très bien, merci. Garçon, l'addition s'il vous plaît.\n",
    'de' => "Die Größe der Straße ist schön. Über den Fluss führt eine Brücke. Mädchen und Jungen spielen im Garten.\n",
    'hy' => "Բարեւ ձեզ։ Սա փորձնական տեքստ է կոդավորման ստուգման համար։ Հայաստանը գեղեցիկ երկիր է, Երեւանը մայրաքաղաքն է։\n",
    'ru' => $ru,
];
$detect = static fn(string $text, string $enc): array => fmDetectEncoding((string)fmEncodingConvert($text, 'UTF-8', $enc));
check('BOM: UTF-8, UTF-16LE, UTF-16BE', fmDetectEncoding("\xEF\xBB\xBFabc") === ['encoding' => 'UTF-8', 'how' => 'bom', 'bom' => 3]
    && fmDetectEncoding("\xFF\xFEa\0")['encoding'] === 'UTF-16LE' && fmDetectEncoding("\xFE\xFF\0a")['encoding'] === 'UTF-16BE');
check('ASCII and valid UTF-8 are certain', fmDetectEncoding("plain text\n")['how'] === 'ascii' && fmDetectEncoding($ru) === ['encoding' => 'UTF-8', 'how' => 'utf8', 'bom' => 0]);
check('a character cut at the end of a sample is still UTF-8', fmDetectEncoding(substr('ёж', 0, 3), true)['encoding'] === 'UTF-8');
check('UTF-16 without BOM (LE and BE)', $detect($ru, 'UTF-16LE')['encoding'] === 'UTF-16LE' && $detect($ru, 'UTF-16BE')['encoding'] === 'UTF-16BE');
check('binary data', fmDetectEncoding(str_repeat("\x00\x01\x02\x03\xFF\xFE\x10\x00", 50))['how'] === 'binary');
foreach ([['ru', 'Windows-1251'], ['ru', 'KOI8-R'], ['ru', 'CP866'], ['fr', 'Windows-1252'], ['de', 'Windows-1252'], ['hy', 'ARMSCII-8']] as [$lang, $enc]) {
    $d = $detect($texts[$lang], $enc);
    check("guess: $lang in $enc", $d['encoding'] === $enc && $d['how'] === 'guess', json_encode($d));
}
check('guess: one short Russian word', $detect("Счёт\n", 'Windows-1251')['encoding'] === 'Windows-1251');
check('strict: Cyrillic does not exist in Windows-1252', fmEncodingConvert('Привет', 'UTF-8', 'Windows-1252') === null);
check('strict: invalid UTF-8 refused', fmEncodingConvert("ab\xFF", 'UTF-8', 'Windows-1251') === null);
check('round trip through KOI8-R and UTF-16LE', fmEncodingConvert((string)fmEncodingConvert($ru, 'UTF-8', 'KOI8-R'), 'KOI8-R', 'UTF-8') === $ru
    && fmEncodingConvert((string)fmEncodingConvert($ru, 'UTF-8', 'UTF-16LE'), 'UTF-16LE', 'UTF-8') === $ru);
check('line endings detected', [fmDetectEol("a\r\nb\r\n"), fmDetectEol("a\nb"), fmDetectEol("a\rb"), fmDetectEol("a\r\nb\n"), fmDetectEol('x')] === ['crlf', 'lf', 'cr', 'mixed', 'none']);
check('line endings changed', fmEolConvert("a\r\nb\nc\rd", 'lf') === "a\nb\nc\nd" && fmEolConvert("a\nb\r\nc\r", 'crlf') === "a\r\nb\r\nc\r\n" && fmEolConvert("a\r\n", 'keep') === "a\r\n");
$ed = $root . '/enc';
mkdir($ed . '/sub', 0775, true);
file_put_contents($ed . '/report.utf8.txt', 'x');
check('copy name: free name, extension kept, Windows-125x as cp125x', fmEncodingCopyName($ed, 'report.txt', 'UTF-8') === 'report.utf8 (1).txt'
    && fmEncodingCopyName($ed, 'README', 'Windows-1251') === 'README.cp1251' && fmEncodingCopyName($ed, 'a.b.txt', 'UTF-16LE') === 'a.b.utf16le.txt');
unlink($ed . '/report.utf8.txt');
$cp = (string)fmEncodingConvert(str_replace("\n", "\r\n", $ru), 'UTF-8', 'Windows-1251');
file_put_contents($ed . '/a.txt', $cp);
file_put_contents($ed . '/b.php', $cp);
file_put_contents($ed . '/c.txt', $ru);
file_put_contents($ed . '/d.txt', "plain\n");
file_put_contents($ed . '/sub/e.txt', $cp);
file_put_contents($ed . '/f.bin', str_repeat("\x00\x01\x02\xFF", 100));
$info = fmEncodingFileInfo($ed . '/a.txt', $root);
check('file info: encoding, how, line endings, size', $info === ['size' => strlen($cp), 'encoding' => 'Windows-1251', 'how' => 'guess', 'eol' => 'crlf'], json_encode($info));
check('file info: binary skipped', fmEncodingFileInfo($ed . '/f.bin', $root)['skip'] === 'binary');
if ($haveLink) {
    check('file info: link skipped', fmEncodingFileInfo($root . '/out-link', $root)['skip'] === 'link');
}
/** Paths found by the encoding scan below $ed. */
$scanEnc = static function (string $mask, bool $notUtf8, bool $recursive = true) use ($ed, $root): array {
    $c = fmEncodingScanCriteria($mask, $notUtf8);
    $paths = [];
    $scanned = 0;
    fmSearchWalkPhp($ed, false, $root, $c, [], static function (array $r) use (&$paths): bool {
        $paths[] = $r['path'];
        return isset($r['encoding']['name']);
    }, static fn() => true, $scanned, $recursive);
    sort($paths);
    return $paths;
};
check('scan: not UTF-8 in all subfolders, binary left out', $scanEnc('', true) === ['a.txt', 'b.php', 'sub/e.txt']);
check('scan: name mask, letter case ignored', $scanEnc('*.TXT', true) === ['a.txt', 'sub/e.txt']);
check('scan: two masks without subfolders', $scanEnc('*.txt; *.php', true, false) === ['a.txt', 'b.php']);
check('scan: all text files', $scanEnc('*', false) === ['a.txt', 'b.php', 'c.txt', 'd.txt', 'sub/e.txt']);
$findEnc = static function (string $enc) use ($ed, $root) {
    $c = fmSearchCriteria(['encoding' => $enc]);
    if (isset($c['error'])) {
        return $c['error'];
    }
    $paths = [];
    $scanned = 0;
    fmSearchWalkPhp($ed, false, $root, $c, [], static function (array $r) use (&$paths): bool {
        $paths[] = $r['path'] . ':' . $r['encoding']['name'];
        return true;
    }, static fn() => true, $scanned);
    sort($paths);
    return $paths;
};
check('Find: encoding not UTF-8 (folders left out)', $findEnc('not_utf8') === ['a.txt:Windows-1251', 'b.php:Windows-1251', 'sub/e.txt:Windows-1251']);
check('Find: UTF-8 includes ASCII', $findEnc('utf-8') === ['c.txt:UTF-8', 'd.txt:ASCII']);
check('Find: unknown encoding reported', is_string($findEnc('EBCDIC-XYZ')));
check('find(1): encoding filter keeps files and links only', in_array('-type', fmSearchFindArgv($ed, false, fmSearchCriteria(['encoding' => 'not_utf8'])), true));
$r = fmEncodingConvertFile($ed . '/a.txt', $root, 'auto', 'UTF-8', false, 'lf', 'replace');
check('convert: replace, detected source, line endings to LF', ($r['status'] ?? '') === 'converted' && $r['from'] === 'Windows-1251' && file_get_contents($ed . '/a.txt') === $ru, json_encode($r));
check('convert: the same again is "already"', fmEncodingConvertFile($ed . '/a.txt', $root, 'auto', 'UTF-8', false, 'lf', 'replace')['status'] === 'already');
$r = fmEncodingConvertFile($ed . '/b.php', $root, 'Windows-1251', 'UTF-8', true, 'keep', 'copy');
check('convert: copy with BOM, original untouched', ($r['new_name'] ?? '') === 'b.utf8.php' && file_get_contents($ed . '/b.utf8.php') === "\xEF\xBB\xBF" . str_replace("\n", "\r\n", $ru)
    && file_get_contents($ed . '/b.php') === $cp, json_encode($r));
$r = fmEncodingConvertFile($ed . '/c.txt', $root, 'auto', 'Windows-1252', false, 'keep', 'replace');
check('convert: characters missing in the target fail and leave the file', $r['status'] === 'failed' && file_get_contents($ed . '/c.txt') === $ru, json_encode($r));
$r = fmEncodingConvertFile($ed . '/b.php', $root, 'UTF-8', 'Windows-1251', false, 'keep', 'replace');
check('convert: a chosen source the file is not valid in fails and leaves the file', $r['status'] === 'failed' && file_get_contents($ed . '/b.php') === $cp, json_encode($r));
check('convert: binary skipped', fmEncodingConvertFile($ed . '/f.bin', $root, 'auto', 'UTF-8', false, 'keep', 'replace')['status'] === 'skipped');
check('convert: no temp files left', glob($ed . '/.fm-conv-*') === []);

section('Archive jobs and status files');
check('zip output: files counted, folders and messages not', [
    fmArchiverOutputFile('  adding: docs/report.txt (deflated 61%)'), fmArchiverOutputFile('  adding: docs/ (stored 0%)'),
    fmArchiverOutputFile('updating: a b.txt (stored 0%)'), fmArchiverOutputFile('zip warning: name not matched: x'), fmArchiverOutputFile(''),
] === ['docs/report.txt', null, 'a b.txt', null, null]);
check('tar -v output (GNU and bsdtar)', [fmArchiverOutputFile('docs/'), fmArchiverOutputFile('docs/a.txt'), fmArchiverOutputFile('a docs/b.txt'), fmArchiverOutputFile('tar: Removing leading `/\'')]
    === [null, 'docs/a.txt', 'docs/b.txt', null]);
$stateDir = fmStateDir();
check('status folder found and writable', is_string($stateDir) && fmDirAcceptsFiles($stateDir), (string)$stateDir);
$lines = [];
$ticks = 0;
$php = escapeshellarg(PHP_BINARY);
file_put_contents($base . '/fake-zip.php', '<?php echo "  adding: one.txt (stored 0%)\n"; usleep(600000); echo "  adding: two.txt (deflated 10%)\n"; fwrite(STDERR, "warn\n");');
[$code, $tail] = fmRunArchiver($php . ' ' . escapeshellarg($base . '/fake-zip.php'),
    static function (string $l) use (&$lines): void { $lines[] = $l; }, static function () use (&$ticks): void { $ticks++; });
check('archiver runner: output lines while running, stderr too, exit code, ticks', $code === 0 && $lines === ['  adding: one.txt (stored 0%)', '  adding: two.txt (deflated 10%)', 'warn']
    && $ticks >= 1 && strpos($tail, 'warn') !== false, json_encode([$code, $lines, $ticks]));
[$code] = fmRunArchiver($php . ' -r ' . escapeshellarg('exit(3);'), static function (): void {}, static function (): void {});
check('archiver runner: failing command reports its exit code', $code === 3);
check('archiver runner leaves no output files', glob($stateDir . '/fm-arcout-*') === []);
check('service folders at the root are known', fmIsServiceName('.fm-state') && fmIsServiceName('.fm-upload-tmp') && !fmIsServiceName('.trash'));
$gzSrc = random_bytes(1500000) . str_repeat('text ', 200000);
file_put_contents($base . '/big.tar', $gzSrc);
$calls = [];
fmGzipFileWithProgress($base . '/big.tar', $base . '/big.tar.gz', static function (int $done, int $all) use (&$calls): void { $calls[] = [$done, $all]; });
check('gzip in PHP: the same data back, progress per MB up to the full size',
    gzdecode((string)file_get_contents($base . '/big.tar.gz')) === $gzSrc && count($calls) === 3 && end($calls) === [strlen($gzSrc), strlen($gzSrc)], json_encode($calls));
$gzErr = '';
try {
    fmGzipFileWithProgress($base . '/missing.tar', $base . '/missing.tar.gz', static function (): void {});
} catch (RuntimeException $e) {
    $gzErr = $e->getMessage();
}
check('gzip in PHP: a missing source is an error and writes nothing', $gzErr !== '' && !file_exists($base . '/missing.tar.gz'), $gzErr);

section('Progress of OS commands');
$osLines = [
    "removed '/srv/site/a b.txt'" => '/srv/site/a b.txt',
    "removed directory '/srv/site/docs'" => '/srv/site/docs',
    "removed \u{2018}/srv/x\u{2019}" => '/srv/x',
    "'/srv/a/f.txt' -> '/srv/b/f.txt'" => '/srv/a/f.txt',
    '  inflating: docs/report.txt  ' => 'docs/report.txt',
    '   creating: docs/' => 'docs/',
    ' extracting: img/logo.png' => 'img/logo.png',
    'Archive:  backup.zip' => null,
    'x docs/a.txt' => 'docs/a.txt',
    'docs/b.txt' => 'docs/b.txt',
    'I:\\site\\src\\f1.txt' => 'I:\\site\\src\\f1.txt',
    '2 File(s) copied' => null,
    '        1 file(s) copied.' => null,
    "rm: cannot remove 'x': Permission denied" => null,
    'tar: Removing leading `/\' from member names' => null,
    '' => null,
];
$osBad = [];
foreach ($osLines as $line => $want) {
    if (fmOsProgressLineName((string)$line) !== $want) {
        $osBad[] = $line . ' => ' . var_export(fmOsProgressLineName((string)$line), true);
    }
}
check('verbose output of rm / cp / unzip / tar / xcopy: paths counted, headers and errors not', $osBad === [], implode(' | ', $osBad));
$sent = [];
[$jp, $jt, $jl] = fmJobProgress(static function (array $p) use (&$sent): void { $sent[] = $p; }, $base, 10);
$jp(3, $base . '/docs/a.txt', true);
$jp(4, $base . '/docs/b.txt');
check('job progress: name below the root, at most 4 lines a second', $sent === [['n' => 3, 'name' => 'docs/a.txt', 'total' => 10]], json_encode($sent));
$cnt = 2;
$sent = [];
$onLine = $jl($cnt, 3);
usleep(300000);
foreach (["removed '$base/x1'", 'rm: cannot remove', "removed '$base/x2'", "removed '$base/x3'", "removed '$base/x4'"] as $l) {
    $onLine($l);
}
check('OS lines count up to the item total only', $cnt === 5 && ($sent[0]['n'] ?? 0) === 3, json_encode([$cnt, $sent]));

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

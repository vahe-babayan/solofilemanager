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

rmTree($base);
exit(summary('unit'));

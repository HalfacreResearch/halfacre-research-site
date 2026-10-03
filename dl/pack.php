<?php
// Builds a pack zip on request from the files in dl/<token>/.
// Pure PHP (no ZipArchive needed). Fails closed if any file is missing
// or does not match the SHA-256 recorded in MANIFEST.json.
declare(strict_types=1);
date_default_timezone_set('America/Chicago');

$PACKS = [
    '376d2ddcf2b69986b5583f0b7e2d1aad' => 'halfacre-research-macro-pack',
    '929d58db29ff852b574eff9659e53513' => 'halfacre-research-etf-flow-pack',
];

function fail(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $msg . "\n";
    exit;
}

$t = isset($_GET['t']) ? (string)$_GET['t'] : '';
if (!preg_match('/^[0-9a-f]{32}$/', $t) || !isset($PACKS[$t])) {
    fail(404, 'Not found.');
}
$dir = __DIR__ . '/' . $t;
$help = 'Please email matt@halfacreresearch.tech with your PayPal receipt.';
$manifestRaw = @file_get_contents($dir . '/MANIFEST.json');
$manifest = $manifestRaw === false ? null : json_decode($manifestRaw, true);
if (!is_array($manifest) || !isset($manifest['files']) || !is_array($manifest['files'])) {
    fail(503, 'This pack is temporarily unavailable. ' . $help);
}

$entries = [];
foreach (['README.md', 'DISCLOSURE.md'] as $doc) {
    $data = @file_get_contents($dir . '/' . $doc);
    if ($data === false) { fail(503, 'This pack is temporarily unavailable. ' . $help); }
    $entries[$doc] = $data;
}
$entries['MANIFEST.json'] = $manifestRaw;
foreach ($manifest['files'] as $f) {
    $p = isset($f['path']) ? (string)$f['path'] : '';
    if (!preg_match('#^(csv|json)/[A-Za-z0-9._-]+$#', $p)) {
        fail(503, 'This pack is temporarily unavailable. ' . $help);
    }
    $data = @file_get_contents($dir . '/' . $p);
    if ($data === false
        || strlen($data) !== (int)($f['bytes'] ?? -1)
        || !hash_equals((string)($f['sha256'] ?? ''), hash('sha256', $data))) {
        fail(503, 'This pack is temporarily unavailable. ' . $help);
    }
    $entries[$p] = $data;
}

// Fixed timestamp from the build date so the same files give the same zip.
$ts = strtotime((string)($manifest['built_utc'] ?? '2026-10-03T00:00:00Z')) ?: time();
$g = getdate($ts);
$dosTime = ($g['hours'] << 11) | ($g['minutes'] << 5) | intdiv($g['seconds'], 2);
$dosDate = (($g['year'] - 1980) << 9) | ($g['mon'] << 5) | $g['mday'];
$canDeflate = function_exists('gzdeflate');
$root = $PACKS[$t] . '-' . date('Y-m-d', $ts) . '/';

$body = '';
$central = '';
$n = 0;
foreach ($entries as $name => $data) {
    $name = $root . $name;
    $crc = crc32($data);
    $usize = strlen($data);
    $method = 0;
    $payload = $data;
    if ($canDeflate) {
        $z = gzdeflate($data, 6);
        if ($z !== false && strlen($z) < $usize) { $method = 8; $payload = $z; }
    }
    $csize = strlen($payload);
    $offset = strlen($body);
    $body .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $dosTime, $dosDate,
                  $crc, $csize, $usize, strlen($name), 0) . $name . $payload;
    $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $method, $dosTime, $dosDate,
                     $crc, $csize, $usize, strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
    $n++;
}
$zip = $body . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), strlen($body), 0);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . rtrim($root, '/') . '.zip"');
header('Content-Length: ' . strlen($zip));
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
echo $zip;

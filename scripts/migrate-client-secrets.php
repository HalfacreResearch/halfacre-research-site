<?php
/**
 * One-time: plaintext client-secrets.store.json → encrypted halfacre-private store.
 * CLI only. Prints counts, never key values. Ops runs this after merge.
 */
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
  http_response_code(403);
  header("Content-Type: text/plain; charset=utf-8");
  echo "CLI only\n";
  exit(1);
}

require_once dirname(__DIR__) . "/client-secrets-lib.php";

if (!halfacre_secrets_configured()) {
  fwrite(STDERR, "not configured: missing or invalid halfacre-private/secrets.key.php\n");
  exit(1);
}

$src = halfacre_secrets_plaintext_path();
$dest = halfacre_secrets_store_path();

if (!is_file($src)) {
  fwrite(STDERR, "no plaintext store\n");
  exit(1);
}

$raw = file_get_contents($src);
$data = json_decode(is_string($raw) ? $raw : "", true);
if (!is_array($data)) {
  fwrite(STDERR, "plaintext store is not JSON object/array\n");
  exit(1);
}

$clients = 0;
$keys = 0;
$out = [];
foreach ($data as $client => $row) {
  if (!is_string($client) || $client === "" || !is_array($row)) {
    continue;
  }
  $id = halfacre_secrets_client_id($client);
  if ($id === "") {
    continue;
  }
  $kept = [];
  foreach ($row as $service => $svc) {
    if (!is_string($service) || !is_array($svc)) {
      continue;
    }
    $key = isset($svc["key"]) ? trim((string) $svc["key"]) : "";
    if ($key === "") {
      continue;
    }
    $kept[$service] = [
      "hint" => isset($svc["hint"]) ? (string) $svc["hint"] : halfacre_secrets_hint($key),
      "savedAt" => (int) ($svc["savedAt"] ?? 0),
      "key" => $key
    ];
    $keys++;
  }
  if ($kept === []) {
    continue;
  }
  $out[$id] = $kept;
  $clients++;
}

halfacre_secrets_write($out);

echo "migrated clients: {$clients}\n";
echo "migrated keys: {$keys}\n";
echo "source: {$src}\n";
echo "dest: {$dest}\n";
echo "delete the plaintext source after confirming the dest file exists.\n";

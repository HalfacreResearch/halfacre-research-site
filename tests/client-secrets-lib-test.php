<?php
/**
 * Encrypt/decrypt round-trip for the client-secrets store.
 * CLI only. Never prints key values.
 */
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
  http_response_code(403);
  echo "CLI only\n";
  exit(1);
}

$pass = 0;
$fail = 0;

function check(string $name, bool $ok): void
{
  global $pass, $fail;
  if ($ok) {
    $pass++;
    echo "PASS  {$name}\n";
  } else {
    $fail++;
    echo "FAIL  {$name}\n";
  }
}

$work = getenv("HALFACRE_SECRETS_TESTDIR");
if (!is_string($work) || $work === "") {
  $work = sys_get_temp_dir() . "/halfacre-secrets-lib-" . bin2hex(random_bytes(4));
}
if (!is_dir($work)) {
  mkdir($work, 0700, true);
}

$keyFile = $work . "/secrets.key.php";
$store = $work . "/client-secrets.store.json";
$b64 = base64_encode(random_bytes(32));
file_put_contents($keyFile, "<?php\nconst HALFACRE_SECRETS_KEY = \"" . $b64 . "\";\n");
putenv("HALFACRE_SECRETS_KEY_FILE=" . $keyFile);
putenv("HALFACRE_SECRETS_STORE=" . $store);

require dirname(__DIR__) . "/client-secrets-lib.php";

check("master key configured", halfacre_secrets_configured());
check("empty c is not a client id", halfacre_secrets_client_id("") === "");
check("unknown c is not a client id", halfacre_secrets_client_id("not-a-client") === "");
check("van alias is Charley id", halfacre_secrets_client_id("van") === client_van_id());
check("hex client id ok", halfacre_secrets_client_id("abcdef1234567890") === "abcdef1234567890");

$plain = "sfox-test-secret-key-ABCDEF123456";
$box = halfacre_secrets_encrypt($plain);
check("secretbox is not plaintext", strpos($box, $plain) === false);
check("secretbox round-trip", halfacre_secrets_decrypt($box) === $plain);

halfacre_secrets_write([
  "abcdef1234567890" => [
    "sfox" => [
      "hint" => halfacre_secrets_hint($plain),
      "savedAt" => 1,
      "key" => $plain
    ]
  ]
]);
$onDisk = is_file($store) ? (string) file_get_contents($store) : "";
check("store file exists", $onDisk !== "");
check("plaintext key absent from store file", strpos($onDisk, $plain) === false);
check("store is secretbox envelope", str_contains($onDisk, "sodium-secretbox") && str_contains($onDisk, "\"box\""));

$read = halfacre_secrets_read();
$got = (string) ($read["abcdef1234567890"]["sfox"]["key"] ?? "");
check("read store round-trips key", $got === $plain);
$status = halfacre_secrets_status("abcdef1234567890");
check("status does not include key", !isset($status["key"]) && ($status["saved"] ?? false) === true);
check("status hint is last-4 only", ($status["hint"] ?? "") === "••••3456");

echo "Results: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

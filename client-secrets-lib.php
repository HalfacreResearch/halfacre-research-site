<?php
/**
 * Encrypted client exchange-key store. Include-only.
 * Live files live outside the web root under halfacre-private/.
 */
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
  $script = basename((string) ($_SERVER["SCRIPT_FILENAME"] ?? ""));
  if ($script === "client-secrets-lib.php") {
    http_response_code(403);
    exit;
  }
}

require_once __DIR__ . "/client-token.php";

const HALFACRE_SECRETS_SERVICE = "sfox";

function halfacre_private_dir(): string
{
  $override = getenv("HALFACRE_PRIVATE_DIR");
  if (is_string($override) && $override !== "") {
    if (!is_dir($override)) {
      @mkdir($override, 0700, true);
    }
    return $override;
  }
  return dirname(__DIR__) . "/halfacre-private";
}

function halfacre_secrets_key_path(): string
{
  $override = getenv("HALFACRE_SECRETS_KEY_FILE");
  if (is_string($override) && $override !== "") {
    return $override;
  }
  return halfacre_private_dir() . "/secrets.key.php";
}

function halfacre_secrets_store_path(): string
{
  $override = getenv("HALFACRE_SECRETS_STORE");
  if (is_string($override) && $override !== "") {
    return $override;
  }
  return halfacre_private_dir() . "/client-secrets.store.json";
}

function halfacre_secrets_plaintext_path(): string
{
  $override = getenv("HALFACRE_SECRETS_PLAINTEXT_STORE");
  if (is_string($override) && $override !== "") {
    return $override;
  }
  return __DIR__ . "/client-secrets.store.json";
}

function halfacre_secrets_load_key_b64(): string
{
  $path = halfacre_secrets_key_path();
  if (!is_file($path)) {
    return "";
  }
  if (defined("HALFACRE_SECRETS_KEY")) {
    return trim((string) HALFACRE_SECRETS_KEY);
  }
  include $path;
  if (!defined("HALFACRE_SECRETS_KEY")) {
    return "";
  }
  return trim((string) HALFACRE_SECRETS_KEY);
}

function halfacre_secrets_key_bytes(): string
{
  if (!function_exists("sodium_crypto_secretbox")) {
    return "";
  }
  $b64 = halfacre_secrets_load_key_b64();
  if ($b64 === "") {
    return "";
  }
  $raw = base64_decode($b64, true);
  if (!is_string($raw) || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
    return "";
  }
  return $raw;
}

function halfacre_secrets_configured(): bool
{
  return halfacre_secrets_key_bytes() !== "";
}

function halfacre_secrets_refuse_unconfigured(): void
{
  http_response_code(503);
  echo json_encode(["ok" => false, "error" => "not configured"]);
  exit;
}

function halfacre_secrets_encrypt(string $plaintext): string
{
  $key = halfacre_secrets_key_bytes();
  if ($key === "") {
    throw new RuntimeException("not configured");
  }
  $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
  $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
  return base64_encode($nonce . $cipher);
}

function halfacre_secrets_decrypt(string $blob): string
{
  $key = halfacre_secrets_key_bytes();
  if ($key === "") {
    throw new RuntimeException("not configured");
  }
  $raw = base64_decode($blob, true);
  if (!is_string($raw) || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
    throw new RuntimeException("bad box");
  }
  $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
  $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
  $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
  if (!is_string($plain)) {
    throw new RuntimeException("decrypt failed");
  }
  return $plain;
}

function halfacre_secrets_read(): array
{
  if (!halfacre_secrets_configured()) {
    throw new RuntimeException("not configured");
  }
  $path = halfacre_secrets_store_path();
  if (!is_file($path)) {
    return [];
  }
  $raw = file_get_contents($path);
  $data = json_decode(is_string($raw) ? $raw : "", true);
  if (!is_array($data)) {
    return [];
  }
  $box = (string) ($data["box"] ?? "");
  if ($box === "") {
    return [];
  }
  $plain = halfacre_secrets_decrypt($box);
  $inner = json_decode($plain, true);
  return is_array($inner) ? $inner : [];
}

function halfacre_secrets_write(array $data): void
{
  if (!halfacre_secrets_configured()) {
    throw new RuntimeException("not configured");
  }
  $path = halfacre_secrets_store_path();
  $dir = dirname($path);
  if (!is_dir($dir)) {
    if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
      throw new RuntimeException("store dir missing");
    }
  }
  $json = json_encode($data, JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    $json = "{}";
  }
  $envelope = [
    "v" => 1,
    "alg" => "sodium-secretbox",
    "box" => halfacre_secrets_encrypt($json)
  ];
  $out = json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  file_put_contents($path, ($out === false ? "{}\n" : $out . "\n"), LOCK_EX);
  @chmod($path, 0600);
}

function halfacre_secrets_client_id(string $raw): string
{
  $id = client_token_id($raw);
  return client_token_id_ok($id) ? $id : "";
}

function halfacre_secrets_hint(string $key): string
{
  $clean = preg_replace("/\\s+/", "", $key) ?? "";
  if (strlen($clean) < 4) {
    return "••••";
  }
  return "••••" . substr($clean, -4);
}

function halfacre_secrets_row(array $all, string $client, string $service = HALFACRE_SECRETS_SERVICE): array
{
  $row = isset($all[$client]) && is_array($all[$client]) ? $all[$client] : [];
  $svc = isset($row[$service]) && is_array($row[$service]) ? $row[$service] : [];
  return $svc;
}

function halfacre_secrets_status(string $client, string $service = HALFACRE_SECRETS_SERVICE): array
{
  $all = halfacre_secrets_read();
  $svc = halfacre_secrets_row($all, $client, $service);
  $has = !empty($svc["key"]) && is_string($svc["key"]);
  return [
    "ok" => true,
    "client" => $client,
    "service" => $service,
    "saved" => $has,
    "hint" => $has ? (string) ($svc["hint"] ?? halfacre_secrets_hint((string) $svc["key"])) : "",
    "savedAt" => $has ? (int) ($svc["savedAt"] ?? 0) : 0
  ];
}

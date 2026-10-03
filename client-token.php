<?php
/**
 * Per-client page tokens. Hashes live outside public_html when possible.
 * Direct HTTP hits do nothing.
 */
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
  $script = basename((string) ($_SERVER["SCRIPT_FILENAME"] ?? ""));
  if ($script === "client-token.php") {
    http_response_code(403);
    exit;
  }
}

function client_token_private_dir(): string
{
  $override = getenv("HALFACRE_TOKEN_ROOT");
  if (is_string($override) && $override !== "") {
    if (!is_dir($override)) {
      @mkdir($override, 0700, true);
    }
    return is_dir($override) ? $override : "";
  }
  $outside = dirname(__DIR__) . "/private";
  if (!is_dir($outside)) {
    @mkdir($outside, 0700, true);
  }
  if (is_dir($outside) && is_writable($outside)) {
    return $outside;
  }
  return "";
}

function client_token_hash_path(): string
{
  $dir = client_token_private_dir();
  if ($dir !== "") {
    return $dir . "/client-tokens.store.json";
  }
  return __DIR__ . "/client-tokens.store.json";
}

function client_token_links_path(): string
{
  $dir = client_token_private_dir();
  if ($dir !== "") {
    return $dir . "/client-private-links.store.json";
  }
  return "";
}

function client_token_id(string $raw): string
{
  $id = strtolower(trim($raw));
  return preg_replace("/[^a-f0-9]/", "", $id) ?? "";
}

function client_token_clean(string $raw): string
{
  $token = strtolower(trim($raw));
  return preg_replace("/[^a-f0-9]/", "", $token) ?? "";
}

function client_token_read_hashes(): array
{
  $path = client_token_hash_path();
  if (!is_file($path)) {
    return [];
  }
  $raw = file_get_contents($path);
  $data = json_decode(is_string($raw) ? $raw : "", true);
  return is_array($data) ? $data : [];
}

function client_token_write_hashes(array $rows): void
{
  $path = client_token_hash_path();
  $json = json_encode($rows, JSON_PRETTY_PRINT);
  file_put_contents($path, $json === false ? "{}\n" : $json . "\n", LOCK_EX);
  @chmod($path, 0600);
}

function client_token_hash(string $token): string
{
  return hash("sha256", $token);
}

function client_token_page(string $id, string $token): string
{
  return "/page.html?c=" . $id . "&t=" . $token;
}

function client_token_remember_link(string $id, string $token): void
{
  $path = client_token_links_path();
  if ($path === "") {
    return;
  }
  $rows = [];
  if (is_file($path)) {
    $raw = file_get_contents($path);
    $data = json_decode(is_string($raw) ? $raw : "", true);
    $rows = is_array($data) ? $data : [];
  }
  $rows[$id] = [
    "page" => "https://www.halfacreresearch.tech" . client_token_page($id, $token)
  ];
  $json = json_encode($rows, JSON_PRETTY_PRINT);
  file_put_contents($path, $json === false ? "{}\n" : $json . "\n", LOCK_EX);
  @chmod($path, 0600);
}

function client_token_issue(string $id): string
{
  $id = client_token_id($id);
  if (strlen($id) < 8 || strlen($id) > 64) {
    throw new InvalidArgumentException("Missing page id");
  }
  $token = bin2hex(random_bytes(32));
  $rows = client_token_read_hashes();
  $rows[$id] = [
    "hash" => client_token_hash($token),
    "set" => gmdate("c")
  ];
  client_token_write_hashes($rows);
  client_token_remember_link($id, $token);
  return $token;
}

function client_token_verify(string $id, string $token): bool
{
  $id = client_token_id($id);
  $token = client_token_clean($token);
  if (strlen($id) < 8 || strlen($token) < 64) {
    return false;
  }
  $rows = client_token_read_hashes();
  $row = isset($rows[$id]) && is_array($rows[$id]) ? $rows[$id] : [];
  $hash = (string) ($row["hash"] ?? "");
  if ($hash === "") {
    return false;
  }
  return hash_equals($hash, client_token_hash($token));
}

function client_token_from_request(?array $payload = null): string
{
  $token = client_token_clean((string) ($_GET["t"] ?? ""));
  if ($token === "" && is_array($payload)) {
    $token = client_token_clean((string) ($payload["t"] ?? ""));
  }
  return $token;
}

function client_token_provision_existing(array $clients): int
{
  // Do not mint tokens without proving inbox ownership. Signup emails the link.
  unset($clients);
  return 0;
}

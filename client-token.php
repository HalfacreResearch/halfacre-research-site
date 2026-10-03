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

function client_van_id(): string
{
  return "charley-van-halfacre";
}

function client_is_van_alias(string $raw): bool
{
  $id = strtolower(trim($raw));
  return $id === "van"
    || $id === "charley-van-halfacre"
    || $id === "charlie-van-halfacre"
    || $id === "1";
}

function client_token_id(string $raw): string
{
  $id = strtolower(trim($raw));
  if (client_is_van_alias($id)) {
    return client_van_id();
  }
  return preg_replace("/[^a-f0-9]/", "", $id) ?? "";
}

function client_token_id_ok(string $id): bool
{
  if ($id === client_van_id()) {
    return true;
  }
  $n = strlen($id);
  return $n >= 8 && $n <= 64 && ctype_xdigit($id);
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
  if (!client_token_id_ok($id)) {
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
  if (!client_token_id_ok($id) || strlen($token) < 64) {
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

function client_clients_store_path(): string
{
  $override = getenv("HALFACRE_CLIENTS_STORE");
  if (is_string($override) && $override !== "") {
    return $override;
  }
  return __DIR__ . "/clients.store.json";
}

function client_read_clients(): array
{
  $path = client_clients_store_path();
  if (!is_file($path)) {
    return [];
  }
  $raw = file_get_contents($path);
  $data = json_decode(is_string($raw) ? $raw : "", true);
  return is_array($data) ? $data : [];
}

function client_record_for(string $id): ?array
{
  $id = client_token_id($id);
  foreach (client_read_clients() as $row) {
    if (!is_array($row)) {
      continue;
    }
    if (client_token_id((string) ($row["id"] ?? "")) === $id) {
      return $row;
    }
  }
  if ($id === client_van_id()) {
    return [
      "id" => client_van_id(),
      "name" => "Charley Van Halfacre",
      "number" => 1
    ];
  }
  return null;
}

function client_refuse(int $code, string $error = "link not valid"): void
{
  http_response_code($code);
  echo json_encode(["ok" => false, "error" => $error]);
  exit;
}

function client_require_page_token(?array $payload = null): array
{
  $raw = (string) ($_GET["c"] ?? "");
  if ($raw === "" && is_array($payload)) {
    $raw = (string) ($payload["c"] ?? $payload["userId"] ?? "");
  }
  $id = client_token_id($raw);
  $token = client_token_from_request($payload);
  if ($id === "" || !client_token_id_ok($id)) {
    client_refuse(401);
  }
  if ($token === "") {
    client_refuse(401);
  }
  if (!client_token_verify($id, $token)) {
    client_refuse(403);
  }
  return ["id" => $id, "token" => $token];
}

function client_client_ip(): string
{
  $ip = trim((string) ($_SERVER["REMOTE_ADDR"] ?? ""));
  return $ip !== "" ? $ip : "unknown";
}

function client_rate_store_path(string $filename): string
{
  $safe = preg_replace("/[^a-z0-9._\\-]/", "", strtolower($filename)) ?? "";
  if ($safe === "") {
    $safe = "rate.store.json";
  }
  $dir = client_token_private_dir();
  if ($dir !== "") {
    return $dir . "/" . $safe;
  }
  return __DIR__ . "/" . $safe;
}

function client_rate_allow(string $bucket, string $filename, int $max, int $window): bool
{
  if ($max < 1 || $window < 1) {
    return false;
  }
  $path = client_rate_store_path($filename);
  $now = time();
  $cut = $now - $window;
  $fp = fopen($path, "c+");
  if ($fp === false) {
    return false;
  }
  if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    return false;
  }
  $raw = stream_get_contents($fp);
  $data = json_decode(is_string($raw) ? $raw : "", true);
  $all = is_array($data) ? $data : [];
  $hits = [];
  if (isset($all[$bucket]) && is_array($all[$bucket])) {
    foreach ($all[$bucket] as $ts) {
      $n = (int) $ts;
      if ($n >= $cut) {
        $hits[] = $n;
      }
    }
  }
  if (count($hits) >= $max) {
    flock($fp, LOCK_UN);
    fclose($fp);
    return false;
  }
  $hits[] = $now;
  $all[$bucket] = $hits;
  foreach ($all as $key => $rows) {
    if (!is_array($rows)) {
      unset($all[$key]);
      continue;
    }
    $keep = [];
    foreach ($rows as $ts) {
      if ((int) $ts >= $cut) {
        $keep[] = (int) $ts;
      }
    }
    if ($keep === []) {
      unset($all[$key]);
    } else {
      $all[$key] = $keep;
    }
  }
  $json = json_encode($all);
  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, ($json === false ? "{}" : $json) . "\n");
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
  @chmod($path, 0600);
  return true;
}

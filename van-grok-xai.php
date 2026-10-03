<?php
/**
 * xAI transport + fail-closed Zero Data Retention guard.
 * ZDR header: https://docs.x.ai/developers/faq/security
 * safety_identifier: same page — accepted on Chat Completions; hash an
 * internal id, never an email, phone, or display name.
 */
declare(strict_types=1);

function grok_stub(): bool
{
  $raw = getenv("HALFACRE_GROK_STUB");
  return is_string($raw) && ($raw === "1" || strtolower($raw) === "true");
}

function grok_endpoint(): string
{
  $raw = getenv("HALFACRE_XAI_URL");
  if (is_string($raw) && trim($raw) !== "") {
    return trim($raw);
  }
  return "https://api.x.ai/v1/chat/completions";
}

function grok_coming_soon_message(): string
{
  return "Private AI chat and uploads are coming soon. We're finishing a privacy upgrade first.";
}

function grok_zdr_ttl(): int
{
  $raw = getenv("HALFACRE_ZDR_TTL");
  $n = 900;
  if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
    $n = (int) $raw;
  }
  return min($n, 900);
}

function grok_zdr_operator_confirmed(): bool
{
  $raw = getenv("HALFACRE_XAI_ZDR_CONFIRMED");
  if (is_string($raw) && trim($raw) !== "") {
    $v = strtolower(trim($raw));
    return $v === "1" || $v === "true" || $v === "on" || $v === "yes";
  }
  if (grok_stub() || grok_zdr_stub_header() !== null) {
    return true;
  }
  return false;
}

function grok_zdr_cache_path(): string
{
  return client_rate_store_path("grok-zdr.store.json");
}

function grok_zdr_header_is_true(?string $header): bool
{
  return strtolower(trim((string) $header)) === "true";
}

function grok_zdr_read_cache(): ?array
{
  $path = grok_zdr_cache_path();
  if (!is_file($path)) {
    return null;
  }
  $data = json_decode((string) file_get_contents($path), true);
  if (!is_array($data) || !isset($data["at"], $data["ok"])) {
    return null;
  }
  $at = (int) $data["at"];
  if ($at <= 0 || (time() - $at) > grok_zdr_ttl()) {
    return null;
  }
  return ["ok" => !empty($data["ok"]), "at" => $at];
}

function grok_zdr_write_cache(bool $ok): void
{
  $path = grok_zdr_cache_path();
  $json = json_encode(["ok" => $ok, "at" => time()]);
  $fp = fopen($path, "c+");
  if ($fp === false) {
    return;
  }
  if (flock($fp, LOCK_EX)) {
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, ($json === false ? "{}" : $json) . "\n");
    fflush($fp);
    flock($fp, LOCK_UN);
  }
  fclose($fp);
  @chmod($path, 0600);
}

function grok_safety_salt(): string
{
  $env = getenv("HALFACRE_SAFETY_SALT");
  if (is_string($env) && trim($env) !== "") {
    return trim($env);
  }
  $path = client_rate_store_path("grok-safety.store.json");
  if (is_file($path)) {
    $data = json_decode((string) file_get_contents($path), true);
    if (is_array($data) && isset($data["salt"]) && is_string($data["salt"]) && $data["salt"] !== "") {
      return $data["salt"];
    }
  }
  $salt = bin2hex(random_bytes(16));
  $json = json_encode(["salt" => $salt]);
  file_put_contents($path, ($json === false ? "{}" : $json) . "\n");
  @chmod($path, 0600);
  return $salt;
}

function grok_safety_identifier(string $clientId): string
{
  $id = trim($clientId);
  if ($id === "") {
    return "";
  }
  return hash_hmac("sha256", $id, grok_safety_salt());
}

function grok_probe_payload(): array
{
  return [
    "model" => "grok-4.6",
    "messages" => [["role" => "user", "content" => "zdr-probe"]],
    "stream" => false
  ];
}

function grok_zdr_stub_header(): ?string
{
  $raw = getenv("HALFACRE_XAI_ZDR");
  if (is_string($raw) && trim($raw) !== "") {
    $v = strtolower(trim($raw));
    if ($v === "true" || $v === "1") {
      return "true";
    }
    if ($v === "false" || $v === "0") {
      return "false";
    }
    if ($v === "missing") {
      return "";
    }
  }
  if (grok_stub()) {
    return "true";
  }
  return null;
}

function grok_http_post(string $key, string $body, string $convId): array
{
  $headers = [];
  $res = false;
  $code = 0;
  $err = "";
  if (function_exists("curl_init")) {
    $ch = curl_init(grok_endpoint());
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => [
        "Content-Type: application/json",
        "Authorization: Bearer " . $key,
        "x-grok-conv-id: " . preg_replace("/[^A-Za-z0-9_\\-]/", "", $convId)
      ],
      CURLOPT_POSTFIELDS => $body,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 45,
      CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
        $parts = explode(":", $line, 2);
        if (count($parts) === 2) {
          $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return strlen($line);
      }
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
  } else {
    $ctx = stream_context_create([
      "http" => [
        "method" => "POST",
        "header" => "Content-Type: application/json\r\nAuthorization: Bearer " . $key . "\r\nx-grok-conv-id: " . $convId . "\r\n",
        "content" => $body,
        "timeout" => 45,
        "ignore_errors" => true
      ]
    ]);
    $res = file_get_contents(grok_endpoint(), false, $ctx);
    if (isset($http_response_header) && is_array($http_response_header)) {
      foreach ($http_response_header as $line) {
        $parts = explode(":", $line, 2);
        if (count($parts) === 2) {
          $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        if (preg_match("/\\s(\\d{3})\\s/", $line, $m)) {
          $code = (int) $m[1];
        }
      }
    }
  }
  $zdr = isset($headers["x-zero-data-retention"]) ? (string) $headers["x-zero-data-retention"] : null;
  return [
    "body" => is_string($res) ? $res : "",
    "code" => $code,
    "err" => $err,
    "zdr" => $zdr
  ];
}

function grok_zdr_probe(string $key): bool
{
  $override = grok_zdr_stub_header();
  if ($override !== null) {
    $ok = grok_zdr_header_is_true($override);
    grok_zdr_write_cache($ok);
    return $ok;
  }
  $body = json_encode(grok_probe_payload());
  if (!is_string($body) || $body === "") {
    grok_zdr_write_cache(false);
    return false;
  }
  $out = grok_http_post($key, $body, "halfacre-zdr-probe");
  $ok = grok_zdr_header_is_true($out["zdr"]);
  grok_zdr_write_cache($ok);
  return $ok;
}

function grok_zdr_confirmed(string $key): bool
{
  if (!grok_zdr_operator_confirmed()) {
    return false;
  }
  $cached = grok_zdr_read_cache();
  if ($cached !== null) {
    return $cached["ok"];
  }
  return grok_zdr_probe($key);
}

function grok_note_real_zdr_header(?string $header): bool
{
  $ok = grok_zdr_header_is_true($header);
  grok_zdr_write_cache($ok);
  return $ok;
}

function grok_coming_soon_payload(): array
{
  return [
    "ok" => true,
    "reply" => grok_coming_soon_message(),
    "engine" => "grok",
    "zdr" => false,
    "comingSoon" => true
  ];
}

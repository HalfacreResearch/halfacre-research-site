<?php
/**
 * Hostinger same-origin proxy for on-page Grok (xAI Chat Completions).
 * Put the key in XAI_API_KEY or van-grok.secret.php (not in git).
 * Access: same emailed client token as page.html / client-memory.php.
 */
declare(strict_types=1);

require_once __DIR__ . "/client-token.php";
require_once __DIR__ . "/client-memory.php";
require_once __DIR__ . "/van-grok-prompts.php";

@set_time_limit(90);
@ignore_user_abort(true);

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  header("Access-Control-Allow-Methods: POST, OPTIONS");
  header("Access-Control-Allow-Headers: Content-Type");
  http_response_code(204);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "POST only"]);
  exit;
}

function grok_key(): string
{
  $env = getenv("XAI_API_KEY");
  if (is_string($env) && trim($env) !== "") {
    return trim($env);
  }
  $secret = __DIR__ . "/van-grok.secret.php";
  if (is_file($secret)) {
    $value = include $secret;
    if (is_string($value) && trim($value) !== "") {
      return trim($value);
    }
  }
  return "";
}

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

function grok_rate_max(): int
{
  $raw = getenv("HALFACRE_GROK_RATE_MAX");
  if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
    return (int) $raw;
  }
  return 20;
}

function grok_server_catalog(): array
{
  $data = read_json(client_catalog_path());
  $rows = isset($data["modules"]) && is_array($data["modules"]) ? $data["modules"] : [];
  $live = [];
  $coming = [];
  foreach ($rows as $row) {
    if (!is_array($row) || empty($row["name"])) {
      continue;
    }
    $item = [
      "id" => (string) ($row["id"] ?? ""),
      "name" => (string) $row["name"],
      "price" => $row["price_usd"] ?? null,
      "tier" => (string) ($row["tier"] ?? ""),
      "buyable" => !empty($row["live"])
    ];
    if (!empty($row["live"])) {
      $live[] = $item;
    } else {
      $coming[] = $item;
    }
  }
  return ["live" => $live, "coming_soon" => $coming];
}

function grok_server_avatar(string $userId, array $purchases): array
{
  $names = catalog_names();
  $powerups = [];
  foreach ($purchases as $item) {
    if (!is_array($item)) {
      continue;
    }
    $id = (string) ($item["id"] ?? "");
    if ($id === "") {
      continue;
    }
    $powerups[] = [
      "id" => $id,
      "name" => (string) ($item["name"] ?? $names[$id] ?? $id),
      "avatar_knowledge" => ""
    ];
  }
  return ["userId" => $userId, "powerups" => $powerups];
}

$raw = file_get_contents("php://input");
$payload = json_decode(is_string($raw) ? $raw : "", true);
if (!is_array($payload)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad JSON"]);
  exit;
}

$auth = client_require_page_token($payload);
$pageId = $auth["id"];
$token = $auth["token"];

$rateMax = grok_rate_max();
$ipBucket = "ip:" . client_client_ip();
$tokenBucket = "token:" . hash("sha256", $token);
if (!client_rate_allow($ipBucket, "grok-rate.store.json", $rateMax, 3600)
  || !client_rate_allow($tokenBucket, "grok-rate.store.json", $rateMax, 3600)) {
  http_response_code(429);
  echo json_encode(["ok" => false, "error" => "Try again later."]);
  exit;
}

$incoming = isset($payload["messages"]) && is_array($payload["messages"]) ? $payload["messages"] : [];
$sfox = !empty($payload["sfox"]);
$open = !empty($payload["open"]);
$sfoxHoldings = trim((string) ($payload["sfoxHoldings"] ?? ""));
$sfoxHoldings = preg_replace("/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]+/", "", $sfoxHoldings) ?? "";
if (strlen($sfoxHoldings) > 2000) {
  $sfoxHoldings = substr($sfoxHoldings, 0, 2000);
}
if ($sfoxHoldings === "") {
  $sfoxHoldings = $sfox ? "sFOX is connected." : "sFOX is not connected.";
}

$record = client_record_for($pageId);
$clientName = trim((string) (($record["name"] ?? "")));
if ($clientName === "") {
  $clientName = $pageId === client_van_id() ? "Charley Van Halfacre" : "Client";
}
$userId = $pageId;

$stored = pack_for($pageId);
$catalog = grok_server_catalog();
$owned = [];
foreach ($stored["purchases"] as $item) {
  if (is_array($item) && !empty($item["id"])) {
    $owned[] = (string) $item["id"];
  }
}
$avatar = grok_server_avatar($userId, $stored["purchases"]);

$cleanUploads = [];
foreach ($stored["uploads"] as $row) {
  if (!is_array($row)) {
    continue;
  }
  $name = trim((string) ($row["name"] ?? ""));
  $kind = strtolower(trim((string) ($row["kind"] ?? "file")));
  if ($name === "") {
    continue;
  }
  if (strlen($name) > 180) {
    $name = substr($name, 0, 180);
  }
  $kind = preg_replace("/[^a-z]/", "", $kind) ?? "file";
  $cleanUploads[] = ["name" => $name, "kind" => $kind !== "" ? $kind : "file"];
  if (count($cleanUploads) >= 80) {
    break;
  }
}

$clean = [];
foreach ($incoming as $item) {
  if (!is_array($item)) {
    continue;
  }
  $role = isset($item["role"]) ? (string) $item["role"] : "";
  $content = isset($item["content"]) ? trim((string) $item["content"]) : "";
  if ($role !== "user" && $role !== "assistant") {
    continue;
  }
  if ($content === "" || strlen($content) > 4000) {
    continue;
  }
  $clean[] = ["role" => $role, "content" => $content];
  if (count($clean) >= 24) {
    break;
  }
}

$messages = array_merge(
  [["role" => "system", "content" => system_prompt($catalog, $owned, $sfox, $avatar, $clientName, $userId, $cleanUploads, $open, $sfoxHoldings)]],
  $clean
);

$body = json_encode([
  "model" => "grok-4.6",
  "messages" => $messages,
  "stream" => false
]);

if (grok_stub()) {
  echo json_encode([
    "ok" => true,
    "reply" => "stub: reached xAI-call step",
    "engine" => "grok",
    "model" => "grok-4.6",
    "stub" => true,
    "reached" => "xai-call"
  ]);
  exit;
}

$key = grok_key();
if ($key === "") {
  http_response_code(503);
  echo json_encode([
    "ok" => false,
    "error" => "Grok key missing on Hostinger. Set XAI_API_KEY or van-grok.secret.php.",
    "engine" => "grok"
  ]);
  exit;
}

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
      "x-grok-conv-id: halfacre-page-" . preg_replace("/[^A-Za-z0-9_\\-]/", "", $userId)
    ],
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 45
  ]);
  $res = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
} else {
  $ctx = stream_context_create([
    "http" => [
      "method" => "POST",
      "header" => "Content-Type: application/json\r\nAuthorization: Bearer " . $key . "\r\nx-grok-conv-id: halfacre-page\r\n",
      "content" => $body,
      "timeout" => 45,
      "ignore_errors" => true
    ]
  ]);
  $res = file_get_contents(grok_endpoint(), false, $ctx);
  if (isset($http_response_header[0]) && preg_match("/\\s(\\d{3})\\s/", $http_response_header[0], $m)) {
    $code = (int) $m[1];
  }
}

if (!is_string($res) || $res === "") {
  http_response_code(502);
  echo json_encode(["ok" => false, "error" => $err !== "" ? $err : "Empty xAI response", "engine" => "grok"]);
  exit;
}

$data = json_decode($res, true);
$reply = "";
if (is_array($data) && isset($data["choices"][0]["message"]["content"])) {
  $reply = trim((string) $data["choices"][0]["message"]["content"]);
}

if ($code >= 400 || $reply === "") {
  http_response_code($code >= 400 ? $code : 502);
  echo json_encode(["ok" => false, "error" => "Grok did not return text", "engine" => "grok"]);
  exit;
}

echo json_encode(["ok" => true, "reply" => $reply, "engine" => "grok", "model" => "grok-4.6"]);

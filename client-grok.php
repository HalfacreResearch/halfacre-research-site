<?php
/**
 * Hostinger same-origin proxy for on-page Grok (xAI Chat Completions).
 * Generic: any client page with a valid emailed token.
 * Put the key in XAI_API_KEY or van-grok.secret.php (not in git).
 * Access: same emailed client token as page.html / client-memory.php.
 */
declare(strict_types=1);

require_once __DIR__ . "/client-token.php";
require_once __DIR__ . "/client-memory.php";
require_once __DIR__ . "/van-grok-prompts.php";
require_once __DIR__ . "/van-grok-xai.php";
require_once __DIR__ . "/grok-redact.php";

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

function grok_finish_turn(string $userId, array $computed, string $reply, string $redactNotice, bool $stub, bool $open = false): array
{
  $sellable = grok_sellable_products();
  $flags = grok_nudge_state_from_compute($computed);
  $filtered = grok_filter_reply($reply, $flags, $sellable);
  if (!$filtered["ok"]) {
    grok_filter_log($filtered["reason"]);
    $filtered = grok_filter_reply($reply, $flags, $sellable);
    if (!$filtered["ok"]) {
      grok_filter_log("strip:" . $filtered["reason"]);
      $reply = "I can keep helping with general research. I won't add a product or upload nudge here.";
    } else {
      $reply = $filtered["text"];
    }
  } else {
    $reply = $filtered["text"];
  }

  $now = time();
  $patch = [
    "last_message_at" => $now,
    "session_started_at" => (int) ($computed["session_started_at"] ?? $now)
  ];
  if (!empty($computed["ai_disclosure_due"])) {
    $patch["ai_disclosure_last_at"] = $now;
    $patch["assistant_replies_since_disclosure"] = 0;
  } else {
    $cur = client_nudge_fields_from(client_memory_row($userId));
    $patch["assistant_replies_since_disclosure"] = ((int) ($cur["assistant_replies_since_disclosure"] ?? 0)) + 1;
  }
  if ($open || !empty($flags["upload_reminder_allowed_this_turn"])) {
    $patch["welcome_done"] = true;
  }
  if (!empty($computed["upload_reminder_allowed_this_turn"])) {
    $cur = client_nudge_fields_from(client_memory_row($userId));
    $log = isset($cur["upload_reminders_log"]) && is_array($cur["upload_reminders_log"])
      ? $cur["upload_reminders_log"] : [];
    $log[] = $now;
    $patch["upload_reminders_log"] = $log;
    $patch["last_upload_reminder_session"] = (int) ($computed["session_started_at"] ?? $now);
    $patch["last_upload_reminder_ignored"] = true;
  }
  if (!empty($computed["product_suggestion_allowed_this_turn"])) {
    $cur = isset($cur) ? $cur : client_nudge_fields_from(client_memory_row($userId));
    $plog = isset($cur["product_suggestions_log"]) && is_array($cur["product_suggestions_log"])
      ? $cur["product_suggestions_log"] : [];
    $plog[] = $now;
    $patch["product_suggestions_log"] = $plog;
  }
  client_memory_merge($userId, $patch);

  $out = [
    "ok" => true,
    "reply" => $reply,
    "engine" => "grok",
    "model" => "grok-4.6",
    "zdr" => true,
    "comingSoon" => false,
    "disclosure" => grok_ai_disclosure_label()
  ];
  if ($redactNotice !== "") {
    $out["redacted"] = $redactNotice;
    $out["reply"] = $redactNotice . "\n\n" . $reply;
  }
  if ($stub) {
    $out["stub"] = true;
    $out["reached"] = "xai-call";
  }
  return $out;
}

function grok_page_identity(string $pageId): array
{
  $record = client_record_for($pageId);
  $id = $pageId;
  $name = "";
  if (is_array($record)) {
    $fromRecord = client_token_id((string) ($record["id"] ?? ""));
    if (client_token_id_ok($fromRecord)) {
      $id = $fromRecord;
    }
    $name = trim((string) ($record["name"] ?? ""));
  }
  return [
    "id" => $id,
    "name" => $name
  ];
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
$identity = grok_page_identity($pageId);
$userId = $identity["id"];

$rateMax = grok_rate_max();
$ipBucket = "ip:" . client_client_ip();
$tokenBucket = "token:" . hash("sha256", $token);
if (!client_rate_allow($ipBucket, "grok-rate.store.json", $rateMax, 3600)
  || !client_rate_allow($tokenBucket, "grok-rate.store.json", $rateMax, 3600)) {
  http_response_code(429);
  echo json_encode(["ok" => false, "error" => "Try again later."]);
  exit;
}

$key = grok_key();
if (!grok_zdr_confirmed($key)) {
  echo json_encode(grok_coming_soon_payload());
  exit;
}

$incoming = isset($payload["messages"]) && is_array($payload["messages"]) ? $payload["messages"] : [];
$open = !empty($payload["open"]);

$stored = pack_for($userId);
$nudgeMem = isset($stored["nudges"]) && is_array($stored["nudges"]) ? $stored["nudges"] : client_nudge_fields_from([]);
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

$redactNotice = "";
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
  $red = grok_redact_text($content);
  if ($red["changed"] && $red["notice"] !== "") {
    $redactNotice = $red["notice"];
  }
  $clean[] = ["role" => $role, "content" => $red["text"]];
  if (count($clean) >= 24) {
    break;
  }
}

$first = grok_first_name((string) ($identity["name"] ?? ""), true);
$computed = grok_nudge_compute([
  "zdr_on" => true,
  "open" => $open,
  "messages" => $clean,
  "memory" => $nudgeMem,
  "checkout_open" => grok_checkout_open(),
  "paper_mode_available" => grok_paper_mode_available()
]);
client_memory_merge($userId, $computed["memory_patch"]);

$nudgeCtx = [
  "zdr" => true,
  "zdr_on" => true,
  "messages" => $clean,
  "open" => $open,
  "first" => $first,
  "computed" => $computed,
  "memory" => array_merge($nudgeMem, $computed["memory_patch"]),
  "sellable_line" => $computed["sellable_line"]
];

$messages = array_merge(
  [["role" => "system", "content" => system_prompt($catalog, $owned, false, $avatar, $first, $userId, $cleanUploads, $open, "", $nudgeCtx)]],
  $clean
);

$payloadOut = [
  "model" => "grok-4.6",
  "messages" => $messages,
  "stream" => false
];
$safety = grok_safety_identifier($userId);
if ($safety !== "") {
  $payloadOut["safety_identifier"] = $safety;
}
$body = json_encode($payloadOut);

if (grok_stub()) {
  echo json_encode(grok_finish_turn(
    $userId,
    $computed,
    "stub: reached xAI-call step",
    $redactNotice,
    true,
    $open
  ));
  exit;
}

if ($key === "") {
  http_response_code(503);
  echo json_encode([
    "ok" => false,
    "error" => "Grok key missing on Hostinger. Set XAI_API_KEY or van-grok.secret.php.",
    "engine" => "grok"
  ]);
  exit;
}

$out = grok_http_post($key, is_string($body) ? $body : "{}", "halfacre-page");
if (!grok_note_real_zdr_header($out["zdr"])) {
  echo json_encode(grok_coming_soon_payload());
  exit;
}

$res = $out["body"];
$code = $out["code"];
$err = $out["err"];

if ($res === "") {
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

$flags = grok_nudge_state_from_compute($computed);
$firstFilter = grok_filter_reply($reply, $flags, grok_sellable_products());
if (!$firstFilter["ok"]) {
  grok_filter_log($firstFilter["reason"]);
  $retry = grok_http_post($key, is_string($body) ? $body : "{}", "halfacre-page-retry");
  if (grok_note_real_zdr_header($retry["zdr"])) {
    $retryData = json_decode((string) $retry["body"], true);
    if (is_array($retryData) && isset($retryData["choices"][0]["message"]["content"])) {
      $retryText = trim((string) $retryData["choices"][0]["message"]["content"]);
      if ($retryText !== "") {
        $reply = $retryText;
      }
    }
  } else {
    echo json_encode(grok_coming_soon_payload());
    exit;
  }
}

echo json_encode(grok_finish_turn($userId, $computed, $reply, $redactNotice, false, $open));

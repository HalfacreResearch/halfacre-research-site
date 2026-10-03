<?php
/**
 * Persist upload names and purchases for one client page.
 * Does not store file bytes. Hostinger-only store file.
 * Functions may be included by other PHP (van-grok.php). Direct HTTP hits run the API.
 */
declare(strict_types=1);

require_once __DIR__ . "/client-token.php";

const VAN_ID = "charley-van-halfacre";

function client_memory_is_endpoint(): bool
{
  if (PHP_SAPI === "cli") {
    return false;
  }
  $script = basename((string) ($_SERVER["SCRIPT_FILENAME"] ?? ""));
  return $script === "client-memory.php";
}

function client_memory_store_path(): string
{
  $override = getenv("HALFACRE_MEMORY_STORE");
  if (is_string($override) && $override !== "") {
    return $override;
  }
  return __DIR__ . "/client-memory.store.json";
}

function client_unlocks_store_path(): string
{
  $override = getenv("HALFACRE_UNLOCKS_STORE");
  if (is_string($override) && $override !== "") {
    return $override;
  }
  return __DIR__ . "/van-unlocks.store.json";
}

function client_catalog_path(): string
{
  return __DIR__ . "/van-products.json";
}

function client_key(string $raw): string
{
  $id = client_token_id($raw);
  if (client_token_id_ok($id)) {
    return $id;
  }
  $safe = preg_replace("/[^a-z0-9_\\-]/", "", strtolower(trim($raw))) ?? "";
  return $safe;
}

function read_json(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $raw = file_get_contents($path);
  $data = json_decode(is_string($raw) ? $raw : "", true);
  return is_array($data) ? $data : [];
}

function write_store(array $data): void
{
  $json = json_encode($data, JSON_PRETTY_PRINT);
  file_put_contents(client_memory_store_path(), $json === false ? "{}\n" : $json . "\n", LOCK_EX);
}

function catalog_names(): array
{
  $data = read_json(client_catalog_path());
  $rows = isset($data["modules"]) && is_array($data["modules"]) ? $data["modules"] : [];
  $map = [];
  foreach ($rows as $row) {
    if (!is_array($row) || empty($row["id"])) {
      continue;
    }
    $id = (string) $row["id"];
    $map[$id] = (string) ($row["avatar_upgrade_label"] ?? $row["name"] ?? $id);
  }
  return $map;
}

function van_purchases(): array
{
  $store = read_json(client_unlocks_store_path());
  $modules = isset($store["modules"]) && is_array($store["modules"]) ? $store["modules"] : [];
  $names = catalog_names();
  $out = [];
  foreach ($modules as $id => $meta) {
    $id = (string) $id;
    $out[] = [
      "id" => $id,
      "name" => $names[$id] ?? $id,
      "kind" => "module"
    ];
  }
  return $out;
}

function client_nudge_defaults(): array
{
  return [
    "upload_reminders_off" => false,
    "product_suggestions_off" => false,
    "upload_snooze_until" => 0,
    "upload_reminders_log" => [],
    "upload_ignored_streak" => 0,
    "product_suggestions_log" => [],
    "declined_items" => [],
    "adult_confirmed" => false,
    "ai_disclosure_last_at" => 0,
    "assistant_replies_since_disclosure" => 0,
    "last_message_at" => 0,
    "session_started_at" => 0,
    "welcome_done" => false,
    "last_upload_reminder_ignored" => false,
    "last_upload_reminder_session" => 0,
    "product_declined_this_session" => false
  ];
}

function client_nudge_fields_from(array $row): array
{
  $out = client_nudge_defaults();
  foreach ($out as $k => $default) {
    if (!array_key_exists($k, $row)) {
      continue;
    }
    if (is_array($default)) {
      $out[$k] = is_array($row[$k]) ? $row[$k] : $default;
    } elseif (is_bool($default)) {
      $out[$k] = !empty($row[$k]);
    } else {
      $out[$k] = is_numeric($row[$k]) ? (int) $row[$k] : $default;
    }
  }
  return $out;
}

function client_memory_row(string $key): array
{
  $all = read_json(client_memory_store_path());
  return isset($all[$key]) && is_array($all[$key]) ? $all[$key] : [];
}

function client_memory_merge(string $key, array $patch): array
{
  $all = read_json(client_memory_store_path());
  if (!isset($all[$key]) || !is_array($all[$key])) {
    $all[$key] = ["uploads" => [], "purchases" => []];
  }
  foreach ($patch as $k => $v) {
    $all[$key][$k] = $v;
  }
  write_store($all);
  return $all[$key];
}

function client_nudge_public(array $fields): array
{
  return [
    "upload_reminders_off" => !empty($fields["upload_reminders_off"]),
    "product_suggestions_off" => !empty($fields["product_suggestions_off"]),
    "adult_confirmed" => !empty($fields["adult_confirmed"]),
    "ai_disclosure_label" => function_exists("grok_ai_disclosure_label")
      ? grok_ai_disclosure_label()
      : "You're chatting with Grok, an AI from xAI. Not a person. It can be wrong. Not advice."
  ];
}

function pack_for(string $key): array
{
  $all = read_json(client_memory_store_path());
  $row = isset($all[$key]) && is_array($all[$key]) ? $all[$key] : [];
  $uploads = isset($row["uploads"]) && is_array($row["uploads"]) ? $row["uploads"] : [];
  $purchases = isset($row["purchases"]) && is_array($row["purchases"]) ? $row["purchases"] : [];
  if ($key === VAN_ID) {
    $seen = [];
    foreach ($purchases as $item) {
      if (is_array($item) && !empty($item["id"])) {
        $seen[(string) $item["id"]] = true;
      }
    }
    foreach (van_purchases() as $item) {
      if (!isset($seen[$item["id"]])) {
        $purchases[] = $item;
      }
    }
  }
  $nudges = client_nudge_fields_from($row);
  return [
    "uploads" => array_values($uploads),
    "purchases" => array_values($purchases),
    "nudges" => $nudges
  ];
}

function clean_name(string $name): string
{
  $name = trim($name);
  $name = preg_replace("/[\\r\\n\\t]+/", " ", $name) ?? "";
  if (strlen($name) > 180) {
    $name = substr($name, 0, 180);
  }
  return $name;
}

if (!client_memory_is_endpoint()) {
  return;
}

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
  header("Access-Control-Allow-Headers: Content-Type");
  http_response_code(204);
  exit;
}

$payload = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $raw = file_get_contents("php://input");
  $payload = json_decode(is_string($raw) ? $raw : "", true);
  if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Bad JSON"]);
    exit;
  }
}

$auth = client_require_page_token($payload);
$key = $auth["id"];

if ($_SERVER["REQUEST_METHOD"] === "GET") {
  $pack = pack_for($key);
  echo json_encode([
    "ok" => true,
    "client" => $key,
    "uploads" => $pack["uploads"],
    "purchases" => $pack["purchases"],
    "nudges" => client_nudge_public($pack["nudges"])
  ]);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "GET or POST only"]);
  exit;
}

$action = (string) ($payload["action"] ?? "upload");
$all = read_json(client_memory_store_path());
if (!isset($all[$key]) || !is_array($all[$key])) {
  $all[$key] = ["uploads" => [], "purchases" => []];
}
if (!isset($all[$key]["uploads"]) || !is_array($all[$key]["uploads"])) {
  $all[$key]["uploads"] = [];
}
if (!isset($all[$key]["purchases"]) || !is_array($all[$key]["purchases"])) {
  $all[$key]["purchases"] = [];
}

if ($action === "prefs") {
  if (array_key_exists("upload_reminders_off", $payload)) {
    $all[$key]["upload_reminders_off"] = !empty($payload["upload_reminders_off"]);
  }
  if (array_key_exists("product_suggestions_off", $payload)) {
    $all[$key]["product_suggestions_off"] = !empty($payload["product_suggestions_off"]);
  }
  write_store($all);
}

if ($action === "adult_confirm") {
  if (empty($payload["adult_confirmed"])) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Adult confirmation is required."]);
    exit;
  }
  $all[$key]["adult_confirmed"] = true;
  write_store($all);
}

if ($action === "upload") {
  $name = clean_name((string) ($payload["name"] ?? ""));
  if ($name === "") {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Missing file name"]);
    exit;
  }
  require_once __DIR__ . "/grok-redact.php";
  if (grok_sensitive_filename($name) && empty($payload["confirm_sensitive"])) {
    echo json_encode([
      "ok" => false,
      "hold" => true,
      "error" => grok_sensitive_hold_message()
    ]);
    exit;
  }
  $kind = strtolower(trim((string) ($payload["kind"] ?? "file")));
  $kind = preg_replace("/[^a-z]/", "", $kind) ?? "file";
  if ($kind === "") {
    $kind = "file";
  }
  $all[$key]["uploads"][] = [
    "name" => $name,
    "kind" => $kind,
    "at" => (int) round(microtime(true) * 1000)
  ];
  if (count($all[$key]["uploads"]) > 200) {
    $all[$key]["uploads"] = array_slice($all[$key]["uploads"], -200);
  }
  write_store($all);
}

if ($action === "purchase") {
  $id = clean_name((string) ($payload["id"] ?? $payload["moduleId"] ?? ""));
  $name = clean_name((string) ($payload["name"] ?? $id));
  if ($id === "") {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Missing purchase id"]);
    exit;
  }
  $exists = false;
  foreach ($all[$key]["purchases"] as $item) {
    if (is_array($item) && (string) ($item["id"] ?? "") === $id) {
      $exists = true;
      break;
    }
  }
  if (!$exists) {
    $all[$key]["purchases"][] = [
      "id" => $id,
      "name" => $name !== "" ? $name : $id,
      "kind" => "module",
      "at" => (int) round(microtime(true) * 1000)
    ];
    write_store($all);
  }
}

$pack = pack_for($key);
echo json_encode([
  "ok" => true,
  "client" => $key,
  "uploads" => $pack["uploads"],
  "purchases" => $pack["purchases"],
  "nudges" => client_nudge_public($pack["nudges"])
]);

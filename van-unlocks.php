<?php
/**
 * Durable user-scoped unlock store for Charlie Van Halfacre.
 *
 * userId: charlie-van-halfacre (same hivemind client slot).
 * Pay identity: cvhalfacre@msn.com.
 * Unlock list is source of truth for what the avatar knows.
 * Downloads are a re-fetch; this file is the entitlement.
 */
declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
  header("Access-Control-Allow-Headers: Content-Type");
  http_response_code(204);
  exit;
}

const VAN_USER_ID = "charlie-van-halfacre";
const VAN_EMAIL = "cvhalfacre@msn.com";
const VAN_NAME = "Charlie Van Halfacre";
const VAN_PHONE = "601-408-8342";
const STORE = __DIR__ . "/van-unlocks.store.json";
const CATALOG = __DIR__ . "/van-products.json";

function van_identity_ok(string $userId, string $email): bool
{
  $id = strtolower(trim($userId));
  $mail = strtolower(trim($email));
  if ($id === VAN_USER_ID || $id === "van") {
    return true;
  }
  if ($mail === VAN_EMAIL) {
    return true;
  }
  return false;
}

function read_store(): array
{
  if (!is_file(STORE)) {
    return [
      "userId" => VAN_USER_ID,
      "identity" => [
        "display_name" => VAN_NAME,
        "email" => VAN_EMAIL,
        "phone" => VAN_PHONE,
        "hivemind_client_id" => VAN_USER_ID,
        "pay_identity" => VAN_EMAIL
      ],
      "modules" => new stdClass()
    ];
  }
  $raw = file_get_contents(STORE);
  $data = json_decode(is_string($raw) ? $raw : "", true);
  if (!is_array($data)) {
    $data = [];
  }
  if (!isset($data["modules"]) || !is_array($data["modules"])) {
    $data["modules"] = [];
  }
  $data["userId"] = VAN_USER_ID;
  return $data;
}

function write_store(array $data): void
{
  $data["userId"] = VAN_USER_ID;
  $data["updatedAt"] = (int) round(microtime(true) * 1000);
  $json = json_encode($data, JSON_PRETTY_PRINT);
  file_put_contents(STORE, $json === false ? "{}\n" : $json . "\n", LOCK_EX);
}

function live_skus(): array
{
  if (!is_file(CATALOG)) {
    return [];
  }
  $raw = file_get_contents(CATALOG);
  $data = json_decode(is_string($raw) ? $raw : "", true);
  if (!is_array($data) || !isset($data["modules"]) || !is_array($data["modules"])) {
    return [];
  }
  $ids = [];
  foreach ($data["modules"] as $row) {
    if (!is_array($row)) {
      continue;
    }
    $id = isset($row["id"]) ? (string) $row["id"] : "";
    $live = !empty($row["live"]) || (isset($row["status"]) && $row["status"] === "live");
    if ($id !== "" && $live) {
      $ids[$id] = $row;
    }
  }
  return $ids;
}

function module_ids(array $store): array
{
  $modules = isset($store["modules"]) && is_array($store["modules"]) ? $store["modules"] : [];
  return array_values(array_filter(array_map("strval", array_keys($modules))));
}

$method = $_SERVER["REQUEST_METHOD"] ?? "GET";

if ($method === "GET") {
  $userId = isset($_GET["userId"]) ? (string) $_GET["userId"] : VAN_USER_ID;
  $email = isset($_GET["email"]) ? (string) $_GET["email"] : VAN_EMAIL;
  if (!van_identity_ok($userId, $email)) {
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "Unknown unlock identity"]);
    exit;
  }
  $store = read_store();
  $ids = module_ids($store);
  echo json_encode([
    "ok" => true,
    "userId" => VAN_USER_ID,
    "identity" => [
      "display_name" => VAN_NAME,
      "email" => VAN_EMAIL,
      "phone" => VAN_PHONE,
      "hivemind_client_id" => VAN_USER_ID,
      "pay_identity" => VAN_EMAIL
    ],
    "moduleIds" => $ids,
    "modules" => isset($store["modules"]) ? $store["modules"] : new stdClass()
  ]);
  exit;
}

if ($method !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "GET or POST only"]);
  exit;
}

$raw = file_get_contents("php://input");
$payload = json_decode(is_string($raw) ? $raw : "", true);
if (!is_array($payload)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad JSON"]);
  exit;
}

$userId = isset($payload["userId"]) ? (string) $payload["userId"] : "";
$email = isset($payload["email"]) ? (string) $payload["email"] : VAN_EMAIL;
if (!van_identity_ok($userId, $email)) {
  http_response_code(403);
  echo json_encode(["ok" => false, "error" => "Unknown unlock identity"]);
  exit;
}

$sku = isset($payload["moduleId"]) ? trim((string) $payload["moduleId"]) : "";
if ($sku === "" && isset($payload["sku"])) {
  $sku = trim((string) $payload["sku"]);
}
$live = live_skus();
if ($sku === "" || !isset($live[$sku])) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Not a live module."]);
  exit;
}

$store = read_store();
if (!isset($store["modules"]) || !is_array($store["modules"])) {
  $store["modules"] = [];
}
if (!isset($store["modules"][$sku])) {
  $store["modules"][$sku] = [
    "amount" => isset($payload["amount"]) ? (string) $payload["amount"] : "",
    "tx" => isset($payload["tx"]) ? (string) $payload["tx"] : "",
    "paidAt" => isset($payload["paidAt"]) ? (int) $payload["paidAt"] : (int) round(microtime(true) * 1000)
  ];
  write_store($store);
  $already = false;
} else {
  $already = true;
}

echo json_encode([
  "ok" => true,
  "already" => $already,
  "userId" => VAN_USER_ID,
  "moduleId" => $sku,
  "moduleIds" => module_ids($store),
  "modules" => $store["modules"]
]);

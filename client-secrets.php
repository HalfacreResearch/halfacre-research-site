<?php
/**
 * Forever vault for one client’s API keys (sFOX first).
 * Hostinger-only store. GET returns saved + last-4 hint. Never returns the key.
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

const STORE = __DIR__ . "/client-secrets.store.json";
const VAN_ID = "charley-van-halfacre";
const SERVICE = "sfox";

function client_key(string $raw): string
{
  $raw = strtolower(trim($raw));
  if ($raw === "" || $raw === "van" || $raw === VAN_ID) {
    return VAN_ID;
  }
  $hex = preg_replace("/[^a-f0-9]/", "", $raw) ?? "";
  if (strlen($hex) >= 8 && strlen($hex) <= 64) {
    return $hex;
  }
  $safe = preg_replace("/[^a-z0-9_\\-]/", "", $raw) ?? "";
  return $safe !== "" ? $safe : VAN_ID;
}

function read_store(): array
{
  if (!is_file(STORE)) {
    return [];
  }
  $raw = file_get_contents(STORE);
  $data = json_decode(is_string($raw) ? $raw : "", true);
  return is_array($data) ? $data : [];
}

function write_store(array $data): void
{
  $json = json_encode($data, JSON_PRETTY_PRINT);
  file_put_contents(STORE, $json === false ? "{}\n" : $json . "\n", LOCK_EX);
  @chmod(STORE, 0600);
}

function hint_from(string $key): string
{
  $clean = preg_replace("/\\s+/", "", $key) ?? "";
  if (strlen($clean) < 4) {
    return "••••";
  }
  return "••••" . substr($clean, -4);
}

function status_for(string $client): array
{
  $all = read_store();
  $row = isset($all[$client]) && is_array($all[$client]) ? $all[$client] : [];
  $svc = isset($row[SERVICE]) && is_array($row[SERVICE]) ? $row[SERVICE] : [];
  $has = !empty($svc["key"]) && is_string($svc["key"]);
  return [
    "ok" => true,
    "client" => $client,
    "service" => SERVICE,
    "saved" => $has,
    "hint" => $has ? (string) ($svc["hint"] ?? hint_from((string) $svc["key"])) : "",
    "savedAt" => $has ? (int) ($svc["savedAt"] ?? 0) : 0
  ];
}

$keyIn = (string) ($_GET["c"] ?? "");
$payload = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $raw = file_get_contents("php://input");
  $payload = json_decode(is_string($raw) ? $raw : "", true);
  if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Bad JSON"]);
    exit;
  }
  $keyIn = (string) ($payload["c"] ?? $payload["userId"] ?? "");
}

$client = client_key($keyIn);

if ($_SERVER["REQUEST_METHOD"] === "GET") {
  echo json_encode(status_for($client));
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "GET or POST only"]);
  exit;
}

$apiKey = preg_replace("/\\s+/", "", (string) ($payload["key"] ?? $payload["apiKey"] ?? "")) ?? "";
if (strlen($apiKey) < 12) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "That key looks too short."]);
  exit;
}

$all = read_store();
if (!isset($all[$client]) || !is_array($all[$client])) {
  $all[$client] = [];
}
$all[$client][SERVICE] = [
  "hint" => hint_from($apiKey),
  "savedAt" => (int) round(microtime(true) * 1000),
  "key" => $apiKey
];
write_store($all);

echo json_encode(status_for($client));

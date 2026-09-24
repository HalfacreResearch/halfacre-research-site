<?php
/**
 * Persist upload names and purchases for one client page.
 * Does not store file bytes. Hostinger-only store file.
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

const STORE = __DIR__ . "/client-memory.store.json";
const VAN_UNLOCKS = __DIR__ . "/van-unlocks.store.json";
const CATALOG = __DIR__ . "/van-products.json";
const VAN_ID = "charley-van-halfacre";

function client_key(string $raw): string
{
  $raw = strtolower(trim($raw));
  if ($raw === "van" || $raw === VAN_ID) {
    return VAN_ID;
  }
  $hex = preg_replace("/[^a-f0-9]/", "", $raw) ?? "";
  if (strlen($hex) >= 8 && strlen($hex) <= 64) {
    return $hex;
  }
  $safe = preg_replace("/[^a-z0-9_\\-]/", "", $raw) ?? "";
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
  file_put_contents(STORE, $json === false ? "{}\n" : $json . "\n", LOCK_EX);
}

function catalog_names(): array
{
  $data = read_json(CATALOG);
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
  $store = read_json(VAN_UNLOCKS);
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

function pack_for(string $key): array
{
  $all = read_json(STORE);
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
  return [
    "uploads" => array_values($uploads),
    "purchases" => array_values($purchases)
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

$key = client_key($keyIn);
if ($key === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Missing page id"]);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
  $pack = pack_for($key);
  echo json_encode([
    "ok" => true,
    "client" => $key,
    "uploads" => $pack["uploads"],
    "purchases" => $pack["purchases"]
  ]);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "GET or POST only"]);
  exit;
}

$action = (string) ($payload["action"] ?? "upload");
$all = read_json(STORE);
if (!isset($all[$key]) || !is_array($all[$key])) {
  $all[$key] = ["uploads" => [], "purchases" => []];
}
if (!isset($all[$key]["uploads"]) || !is_array($all[$key]["uploads"])) {
  $all[$key]["uploads"] = [];
}
if (!isset($all[$key]["purchases"]) || !is_array($all[$key]["purchases"])) {
  $all[$key]["purchases"] = [];
}

if ($action === "upload") {
  $name = clean_name((string) ($payload["name"] ?? ""));
  if ($name === "") {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Missing file name"]);
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
  "purchases" => $pack["purchases"]
]);

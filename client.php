<?php
/**
 * Look up one client by id for their own page.
 * Does not list other clients.
 */
declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "GET only"]);
  exit;
}

const STORE = __DIR__ . "/clients.store.json";

$id = strtolower(trim((string) ($_GET["c"] ?? "")));
$id = preg_replace("/[^a-f0-9]/", "", $id) ?? "";
if (strlen($id) < 8 || strlen($id) > 64) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Missing page id"]);
  exit;
}

$rows = [];
if (is_file(STORE)) {
  $raw = file_get_contents(STORE);
  $data = json_decode(is_string($raw) ? $raw : "", true);
  $rows = is_array($data) ? $data : [];
}

$found = null;
foreach ($rows as $row) {
  if (!is_array($row)) {
    continue;
  }
  if (strtolower((string) ($row["id"] ?? "")) === $id) {
    $found = $row;
    break;
  }
}

if (!is_array($found)) {
  http_response_code(404);
  echo json_encode(["ok" => false, "error" => "No page for that account"]);
  exit;
}

$number = isset($found["number"]) ? (int) $found["number"] : 0;
echo json_encode([
  "ok" => true,
  "client" => [
    "id" => (string) $found["id"],
    "name" => (string) ($found["name"] ?? ""),
    "number" => $number > 0 ? $number : null,
    "page" => "/page.html?c=" . $found["id"]
  ]
]);

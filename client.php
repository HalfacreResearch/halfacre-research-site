<?php
/**
 * Look up one client by id for their own page.
 * Serves data only after a per-client token check.
 */
declare(strict_types=1);

require_once __DIR__ . "/client-token.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "GET only"]);
  exit;
}

const STORE = __DIR__ . "/clients.store.json";

function read_clients(): array
{
  if (!is_file(STORE)) {
    return [];
  }
  $raw = file_get_contents(STORE);
  $data = json_decode(is_string($raw) ? $raw : "", true);
  return is_array($data) ? $data : [];
}

client_token_provision_existing(read_clients());

$id = client_token_id((string) ($_GET["c"] ?? ""));
if (strlen($id) < 8 || strlen($id) > 64) {
  http_response_code(401);
  echo json_encode(["ok" => false, "error" => "link not valid"]);
  exit;
}

$token = client_token_from_request();
if ($token === "") {
  http_response_code(401);
  echo json_encode(["ok" => false, "error" => "link not valid"]);
  exit;
}

if (!client_token_verify($id, $token)) {
  http_response_code(403);
  echo json_encode(["ok" => false, "error" => "link not valid"]);
  exit;
}

$found = null;
foreach (read_clients() as $row) {
  if (!is_array($row)) {
    continue;
  }
  if (client_token_id((string) ($row["id"] ?? "")) === $id) {
    $found = $row;
    break;
  }
}

if (!is_array($found)) {
  http_response_code(403);
  echo json_encode(["ok" => false, "error" => "link not valid"]);
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

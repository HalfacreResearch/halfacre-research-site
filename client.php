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

$auth = client_require_page_token();
$found = client_record_for($auth["id"]);

if (!is_array($found)) {
  client_refuse(403);
}

$number = isset($found["number"]) ? (int) $found["number"] : 0;
echo json_encode([
  "ok" => true,
  "client" => [
    "id" => (string) $found["id"],
    "name" => (string) ($found["name"] ?? ""),
    "number" => $number > 0 ? $number : null
  ]
]);

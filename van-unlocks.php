<?php
/**
 * Compatibility endpoint. GET returns verified PayPal purchases.
 * POST cannot mark items bought.
 */
declare(strict_types=1);

require_once __DIR__ . "/client-token.php";
require_once __DIR__ . "/paypal-lib.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
  header("Access-Control-Allow-Headers: Content-Type");
  http_response_code(204);
  exit;
}

$raw = file_get_contents("php://input");
$payload = json_decode(is_string($raw) ? $raw : "", true);
if (!is_array($payload)) {
  $payload = [];
}

$auth = client_require_page_token($payload);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  http_response_code(403);
  echo json_encode([
    "ok" => false,
    "error" => "Purchases are recorded after PayPal capture only."
  ]);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "GET or POST only"]);
  exit;
}

$purchases = paypal_client_purchases($auth["id"]);
$modules = [];
foreach ($purchases as $row) {
  $modules[$row["id"]] = [
    "amount" => $row["amount"],
    "capture_id" => $row["capture_id"],
    "paidAt" => $row["at"]
  ];
}

echo json_encode([
  "ok" => true,
  "client" => $auth["id"],
  "modules" => $modules,
  "purchases" => $purchases
]);

<?php
/**
 * Capture a PayPal order and record the purchase only after COMPLETED + amount/sku/client checks.
 */
declare(strict_types=1);

require_once __DIR__ . "/client-token.php";
require_once __DIR__ . "/paypal-lib.php";

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

$raw = file_get_contents("php://input");
$payload = json_decode(is_string($raw) ? $raw : "", true);
if (!is_array($payload)) {
  $payload = [];
}

$auth = client_require_page_token($payload);
$orderId = trim((string) ($payload["orderID"] ?? $payload["order_id"] ?? $payload["id"] ?? ""));
$result = paypal_capture_order($auth["id"], $orderId);
if (empty($result["ok"])) {
  http_response_code((int) ($result["http"] ?? 400));
  echo json_encode(["ok" => false, "error" => $result["error"] ?? "Capture failed"]);
  exit;
}

echo json_encode([
  "ok" => true,
  "already" => !empty($result["already"]),
  "sku" => $result["sku"],
  "amount" => $result["amount"],
  "capture_id" => $result["capture_id"],
  "purchases" => $result["purchases"]
]);

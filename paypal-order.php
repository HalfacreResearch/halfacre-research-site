<?php
/**
 * Create a PayPal order. Price is taken from van-products.json, never the client.
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
$sku = trim((string) ($payload["sku"] ?? $payload["id"] ?? $_GET["sku"] ?? $_GET["id"] ?? ""));
$result = paypal_create_order($auth["id"], $sku);
if (empty($result["ok"])) {
  http_response_code((int) ($result["http"] ?? 400));
  echo json_encode(["ok" => false, "error" => $result["error"] ?? "Could not create order"]);
  exit;
}

echo json_encode([
  "ok" => true,
  "id" => $result["order_id"],
  "order_id" => $result["order_id"],
  "sku" => $result["sku"],
  "amount" => $result["amount"],
  "currency" => $result["currency"]
]);

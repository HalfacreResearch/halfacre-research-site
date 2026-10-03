<?php
/**
 * Public checkout availability. Returns the JS SDK client id only when config is present.
 * Never returns the secret or webhook id.
 */
declare(strict_types=1);

require_once __DIR__ . "/paypal-lib.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "GET only"]);
  exit;
}

$cfg = paypal_config();
if ($cfg === null) {
  echo json_encode([
    "ok" => true,
    "available" => false,
    "message" => "checkout not available yet"
  ]);
  exit;
}

$sku = trim((string) ($_GET["sku"] ?? $_GET["id"] ?? ""));
$item = null;
if ($sku !== "") {
  $row = paypal_sku_row($sku);
  $price = paypal_sku_price($sku);
  if ($row !== null && $price !== null && paypal_sku_live($sku)) {
    $item = [
      "sku" => $sku,
      "name" => paypal_sku_name($sku),
      "amount" => $price,
      "currency" => PAYPAL_CURRENCY
    ];
  }
}

echo json_encode([
  "ok" => true,
  "available" => true,
  "env" => $cfg["env"],
  "client_id" => $cfg["client_id"],
  "item" => $item
]);

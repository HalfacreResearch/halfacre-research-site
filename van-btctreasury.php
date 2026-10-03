<?php
/**
 * Unlock status for BTCTreasuryBot. Purchase record only — no trading.
 * Practice-mode UI never calls this for balances. Do not add sFOX or keys here.
 */
declare(strict_types=1);

require_once __DIR__ . "/client-token.php";
require_once __DIR__ . "/paypal-lib.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "GET only"]);
  exit;
}

$auth = client_require_page_token();
$owned = paypal_has_verified_purchase($auth["id"], "btc-treasury-bot");

echo json_encode([
  "ok" => true,
  "sku" => "btc-treasury-bot",
  "unlocked" => $owned,
  "pay_path" => "pay.html?sku=btc-treasury-bot"
]);

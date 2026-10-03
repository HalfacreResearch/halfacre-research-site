<?php
/**
 * CLI checks for PayPal helpers. No live PayPal calls.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$work = getenv("HALFACRE_PAYPAL_TESTDIR");
if (!is_string($work) || $work === "") {
  fwrite(STDERR, "HALFACRE_PAYPAL_TESTDIR is required\n");
  exit(1);
}

putenv("HALFACRE_PAYPAL_HTTP=mock");
putenv("HALFACRE_PAYPAL_SECRET=" . $work . "/paypal.secret.php");
putenv("HALFACRE_PAYPAL_CAPTURES=" . $work . "/paypal-captures.store.json");
putenv("HALFACRE_PAYPAL_LOG=" . $work . "/paypal-events.store.json");
putenv("HALFACRE_PAYPAL_MOCK_STORE=" . $work . "/paypal-mock.json");
putenv("HALFACRE_MAIL_LOG=" . $work . "/mail.log");
putenv("HALFACRE_TOKEN_ROOT=" . $work . "/private");

require_once $root . "/paypal-lib.php";

$pass = 0;
$fail = 0;

function expect_true(string $name, bool $ok, string $detail = ""): void
{
  global $pass, $fail;
  if ($ok) {
    $pass++;
    echo "PASS  {$name}\n";
    return;
  }
  $fail++;
  echo "FAIL  {$name}" . ($detail !== "" ? " ({$detail})" : "") . "\n";
}

$cfg = paypal_config();
expect_true("config loads from test secret", is_array($cfg) && $cfg["env"] === "sandbox");
expect_true("pack delivery defaults false", is_array($cfg) && $cfg["pack_delivery"] === false);
expect_true("catalog module is $4.99", paypal_sku_price("macro-indicators-research") === "4.99");
expect_true("catalog pack is $29.99", paypal_sku_price("pack-etf-mf") === "29.99");
expect_true("catalog bot is $149.00", paypal_sku_price("btc-treasury-bot") === "149.00");

$order = paypal_create_order("abcdef1234567890", "macro-indicators-research");
expect_true("create order uses catalog", !empty($order["ok"]) && ($order["amount"] ?? "") === "4.99");

$bad = [
  "id" => "ORDER-WRONGAMT",
  "status" => "COMPLETED",
  "purchase_units" => [[
    "custom_id" => "abcdef1234567890|macro-indicators-research",
    "reference_id" => "macro-indicators-research",
    "items" => [["sku" => "macro-indicators-research"]],
    "payments" => ["captures" => [[
      "id" => "CAP-TAMPER",
      "status" => "COMPLETED",
      "amount" => ["value" => "0.01", "currency_code" => "USD"],
      "custom_id" => "abcdef1234567890|macro-indicators-research"
    ]]]
  ]]
];
$check = paypal_verify_captured_order($bad, "abcdef1234567890", "macro-indicators-research");
expect_true("tampered price rejected", empty($check["ok"]));

$good = [
  "id" => "ORDER-OK",
  "status" => "COMPLETED",
  "purchase_units" => [[
    "custom_id" => "abcdef1234567890|macro-indicators-research",
    "reference_id" => "macro-indicators-research",
    "items" => [["sku" => "macro-indicators-research"]],
    "payments" => ["captures" => [[
      "id" => "CAP-OK",
      "status" => "COMPLETED",
      "amount" => ["value" => "4.99", "currency_code" => "USD"],
      "custom_id" => "abcdef1234567890|macro-indicators-research"
    ]]]
  ]]
];
$check = paypal_verify_captured_order($good, "abcdef1234567890", "macro-indicators-research");
expect_true("matching capture accepted", !empty($check["ok"]) && ($check["capture_id"] ?? "") === "CAP-OK");

$first = paypal_record_capture([
  "capture_id" => "CAP-OK",
  "order_id" => "ORDER-OK",
  "sku" => "macro-indicators-research",
  "client_id" => "abcdef1234567890",
  "amount" => "4.99",
  "currency" => "USD",
  "source" => "test"
]);
$second = paypal_record_capture([
  "capture_id" => "CAP-OK",
  "order_id" => "ORDER-OK",
  "sku" => "macro-indicators-research",
  "client_id" => "abcdef1234567890",
  "amount" => "4.99",
  "currency" => "USD",
  "source" => "test"
]);
expect_true("first record writes", !empty($first["ok"]) && empty($first["already"]));
expect_true("second record is idempotent", !empty($second["ok"]) && !empty($second["already"]));
expect_true("client has verified purchase", paypal_has_verified_purchase("abcdef1234567890", "macro-indicators-research"));
expect_true("other sku not purchased", !paypal_has_verified_purchase("abcdef1234567890", "pack-etf-mf"));

$legacy = paypal_handle_webhook_event([
  "event_type" => "PAYMENT.CAPTURE.COMPLETED",
  "resource" => [
    "id" => "CAP-LEGACY-ETF",
    "amount" => ["value" => "149.00", "currency_code" => "USD"],
    "status" => "COMPLETED",
    "payer" => ["email_address" => "legacy@example.invalid"],
    "invoice_id" => "PLB-DN2KVZRLCUML",
    "custom_id" => "abcdef1234567890|pack-etf-mf"
  ]
]);
expect_true("legacy NCP recorded for review", !empty($legacy["ok"]) && ($legacy["review"] ?? "") === "legacy NCP, needs manual review");
expect_true("legacy NCP does not unlock pack-etf-mf", !paypal_has_verified_purchase("abcdef1234567890", "pack-etf-mf", "legacy@example.invalid"));
$legacyRows = paypal_json_read(paypal_captures_path());
$legacyRec = $legacyRows["captures"]["CAP-LEGACY-ETF"] ?? [];
expect_true(
  "legacy NCP store text",
  is_array($legacyRec) && ($legacyRec["review"] ?? "") === "legacy NCP, needs manual review" && ($legacyRec["sku"] ?? "x") === ""
);

$legacyMacro = paypal_handle_webhook_event([
  "event_type" => "PAYMENT.CAPTURE.COMPLETED",
  "resource" => [
    "id" => "CAP-LEGACY-MACRO",
    "amount" => ["value" => "99.00", "currency_code" => "USD"],
    "status" => "COMPLETED",
    "payer" => ["email_address" => "legacy-macro@example.invalid"],
    "invoice_id" => "PLB-NGZRXTQA93RE",
    "custom_id" => "abcdef1234567890|pack-bitcoin-macro"
  ]
]);
expect_true("legacy Macro NCP recorded for review", !empty($legacyMacro["ok"]) && ($legacyMacro["review"] ?? "") === "legacy NCP, needs manual review");
expect_true("legacy Macro NCP does not unlock pack-bitcoin-macro", !paypal_has_verified_purchase("abcdef1234567890", "pack-bitcoin-macro", "legacy-macro@example.invalid"));
$legacyMacroRec = (paypal_json_read(paypal_captures_path())["captures"]["CAP-LEGACY-MACRO"] ?? []);
expect_true(
  "legacy Macro NCP store text",
  is_array($legacyMacroRec) && ($legacyMacroRec["review"] ?? "") === "legacy NCP, needs manual review" && ($legacyMacroRec["sku"] ?? "x") === ""
);

$catalogPack = paypal_handle_webhook_event([
  "event_type" => "PAYMENT.CAPTURE.COMPLETED",
  "resource" => [
    "id" => "CAP-CATALOG-ETF-PACK",
    "amount" => ["value" => "29.99", "currency_code" => "USD"],
    "status" => "COMPLETED",
    "payer" => ["email_address" => "catalog-pack@example.invalid"],
    "custom_id" => "abcdef1234567890|pack-etf-mf"
  ]
]);
expect_true("catalog $29.99 pack capture unlocks pack-etf-mf", !empty($catalogPack["ok"]) && empty($catalogPack["review"]) && paypal_has_verified_purchase("abcdef1234567890", "pack-etf-mf", "catalog-pack@example.invalid"));

$mail = is_file($work . "/mail.log") ? (string) file_get_contents($work . "/mail.log") : "";
expect_true(
  "legacy NCP did not email a download",
  strpos($mail, "legacy@example.invalid") === false && strpos($mail, "legacy-macro@example.invalid") === false
);

$exp = time() + 60;
$sig = paypal_sign_download("pack-etf-mf", "buyer@example.invalid", $exp);
expect_true("signed link verifies", paypal_verify_download_sig("pack-etf-mf", "buyer@example.invalid", $exp, $sig));
expect_true("expired link fails", !paypal_verify_download_sig("pack-etf-mf", "buyer@example.invalid", time() - 10, $sig));

echo "Results: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

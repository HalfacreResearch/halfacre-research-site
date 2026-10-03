<?php
/**
 * PayPal Orders v2 helpers, verified purchase store, and signed downloads.
 * Include-only. Prices always come from van-products.json, never the client.
 */
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
  $script = basename((string) ($_SERVER["SCRIPT_FILENAME"] ?? ""));
  if ($script === "paypal-lib.php") {
    http_response_code(403);
    exit;
  }
}

require_once __DIR__ . "/paypal-config.php";
require_once __DIR__ . "/client-token.php";

const PAYPAL_CURRENCY = "USD";
const PAYPAL_NOCODE_ETF = "PLB-DN2KVZRLCUML";
const PAYPAL_NOCODE_MACRO = "PLB-NGZRXTQA93RE";
const PAYPAL_PACK_MACRO_TOKEN = "376d2ddcf2b69986b5583f0b7e2d1aad";
const PAYPAL_PACK_ETF_TOKEN = "929d58db29ff852b574eff9659e53513";
const PAYPAL_DOWNLOAD_TTL = 86400;
const PAYPAL_MATTHEW = "matt@halfacreresearch.tech";

function paypal_captures_path(): string
{
  $override = getenv("HALFACRE_PAYPAL_CAPTURES");
  if (is_string($override) && $override !== "") {
    return $override;
  }
  return __DIR__ . "/paypal-captures.store.json";
}

function paypal_log_path(): string
{
  $override = getenv("HALFACRE_PAYPAL_LOG");
  if (is_string($override) && $override !== "") {
    return $override;
  }
  return __DIR__ . "/paypal-events.store.json";
}

function paypal_json_read(string $path): array
{
  if (!is_file($path)) {
    return [];
  }
  $raw = file_get_contents($path);
  $data = json_decode(is_string($raw) ? $raw : "", true);
  return is_array($data) ? $data : [];
}

function paypal_json_write(string $path, array $data): void
{
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  file_put_contents($path, ($json === false ? "{}\n" : $json . "\n"), LOCK_EX);
  @chmod($path, 0600);
}

function paypal_now_ms(): int
{
  return (int) round(microtime(true) * 1000);
}

function paypal_log(string $event, array $meta = []): void
{
  $path = paypal_log_path();
  $all = paypal_json_read($path);
  $rows = isset($all["events"]) && is_array($all["events"]) ? $all["events"] : [];
  $rows[] = [
    "event" => $event,
    "at" => paypal_now_ms(),
    "meta" => $meta
  ];
  if (count($rows) > 500) {
    $rows = array_slice($rows, -500);
  }
  paypal_json_write($path, ["events" => $rows]);
}

function paypal_money(string $value): string
{
  $n = (float) $value;
  return number_format($n, 2, ".", "");
}

function paypal_money_eq(string $a, string $b): bool
{
  return paypal_money($a) === paypal_money($b);
}

function paypal_catalog_data(): array
{
  $path = __DIR__ . "/van-products.json";
  return paypal_json_read($path);
}

function paypal_sku_row(string $sku): ?array
{
  $want = strtolower(trim($sku));
  if ($want === "codex-buy" || $want === "codex-sell") {
    $want = "btc-treasury-bot";
  }
  if ($want === "") {
    return null;
  }
  $data = paypal_catalog_data();
  $rows = isset($data["modules"]) && is_array($data["modules"]) ? $data["modules"] : [];
  foreach ($rows as $row) {
    if (!is_array($row)) {
      continue;
    }
    if (strtolower(trim((string) ($row["id"] ?? ""))) === $want) {
      return $row;
    }
  }
  return null;
}

function paypal_sku_price(string $sku): ?string
{
  $row = paypal_sku_row($sku);
  if ($row === null) {
    return null;
  }
  if (!empty($row["free"])) {
    return null;
  }
  if (!isset($row["price_usd"])) {
    return null;
  }
  return paypal_money((string) $row["price_usd"]);
}

function paypal_sku_live(string $sku): bool
{
  $row = paypal_sku_row($sku);
  if ($row === null) {
    return false;
  }
  if (($row["live"] ?? false) === true) {
    return true;
  }
  return strtolower(trim((string) ($row["status"] ?? ""))) === "live";
}

function paypal_sku_name(string $sku): string
{
  $row = paypal_sku_row($sku);
  if ($row === null) {
    return $sku;
  }
  $label = trim((string) ($row["avatar_upgrade_label"] ?? ""));
  if ($label !== "") {
    return $label;
  }
  $name = trim((string) ($row["name"] ?? ""));
  return $name !== "" ? $name : $sku;
}

function paypal_nocode_skus(): array
{
  return [
    PAYPAL_NOCODE_ETF => [
      "sku" => "pack-etf-mf",
      "amount" => "149.00",
      "label" => "BTC spot ETF flow pack (no-code $149)"
    ],
    PAYPAL_NOCODE_MACRO => [
      "sku" => "pack-bitcoin-macro",
      "amount" => "99.00",
      "label" => "Bitcoin / macro stack pack (no-code $99)"
    ]
  ];
}

function paypal_pack_file(string $sku): string
{
  $map = [
    "pack-bitcoin-macro" => __DIR__ . "/dl/" . PAYPAL_PACK_MACRO_TOKEN . "/PACK.zip",
    "pack-etf-mf" => __DIR__ . "/dl/" . PAYPAL_PACK_ETF_TOKEN . "/PACK.zip"
  ];
  $want = strtolower(trim($sku));
  if (!isset($map[$want])) {
    return "";
  }
  $path = $map[$want];
  $real = realpath($path);
  $root = realpath(__DIR__ . "/dl");
  if ($real === false || $root === false) {
    return "";
  }
  if (strpos($real, $root) !== 0) {
    return "";
  }
  return is_file($real) ? $real : "";
}

function paypal_custom_id(string $clientId, string $sku): string
{
  $id = trim($clientId);
  $sku = trim($sku);
  $custom = $id . "|" . $sku;
  if (strlen($custom) > 127) {
    $custom = substr($custom, 0, 127);
  }
  return $custom;
}

function paypal_parse_custom_id(string $custom): array
{
  $custom = trim($custom);
  if ($custom === "") {
    return ["client_id" => "", "sku" => ""];
  }
  $parts = explode("|", $custom, 2);
  if (count($parts) === 2) {
    return ["client_id" => trim($parts[0]), "sku" => trim($parts[1])];
  }
  return ["client_id" => "", "sku" => $custom];
}

function paypal_http(string $method, string $url, array $headers = [], $body = null): array
{
  if (getenv("HALFACRE_PAYPAL_HTTP") === "mock") {
    return paypal_http_mock($method, $url, $headers, $body);
  }
  $ch = curl_init($url);
  if ($ch === false) {
    return ["ok" => false, "status" => 0, "json" => [], "raw" => "", "error" => "curl_init failed"];
  }
  $payload = null;
  if ($body !== null) {
    $payload = is_string($body) ? $body : json_encode($body);
  }
  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => strtoupper($method),
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 30
  ];
  if ($payload !== null) {
    $opts[CURLOPT_POSTFIELDS] = $payload;
  }
  curl_setopt_array($ch, $opts);
  $raw = curl_exec($ch);
  $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if (!is_string($raw)) {
    $raw = "";
  }
  $json = json_decode($raw, true);
  return [
    "ok" => $status >= 200 && $status < 300,
    "status" => $status,
    "json" => is_array($json) ? $json : [],
    "raw" => $raw,
    "error" => $err
  ];
}

function paypal_mock_store_path(): string
{
  $override = getenv("HALFACRE_PAYPAL_MOCK_STORE");
  if (is_string($override) && $override !== "") {
    return $override;
  }
  return sys_get_temp_dir() . "/halfacre-paypal-mock.json";
}

function paypal_http_mock(string $method, string $url, array $headers, $body): array
{
  unset($headers);
  $method = strtoupper($method);
  $data = is_array($body) ? $body : (is_string($body) ? (json_decode($body, true) ?: []) : []);
  if (!is_array($data)) {
    $data = [];
  }
  if (str_contains($url, "/v1/oauth2/token")) {
    return [
      "ok" => true,
      "status" => 200,
      "json" => ["access_token" => "mock-access-token", "token_type" => "Bearer", "expires_in" => 300],
      "raw" => "",
      "error" => ""
    ];
  }
  if ($method === "POST" && preg_match("#/v2/checkout/orders$#", $url)) {
    $unit = $data["purchase_units"][0] ?? [];
    $amount = paypal_money((string) (($unit["amount"]["value"] ?? "0")));
    $currency = (string) ($unit["amount"]["currency_code"] ?? PAYPAL_CURRENCY);
    $custom = (string) ($unit["custom_id"] ?? "");
    $sku = (string) (($unit["items"][0]["sku"] ?? ""));
    $orderId = "ORDER-" . substr(hash("sha256", $custom . $amount . microtime(true)), 0, 12);
    $store = paypal_json_read(paypal_mock_store_path());
    $store[$orderId] = [
      "id" => $orderId,
      "status" => "CREATED",
      "amount" => $amount,
      "currency" => $currency,
      "custom_id" => $custom,
      "sku" => $sku
    ];
    paypal_json_write(paypal_mock_store_path(), $store);
    return [
      "ok" => true,
      "status" => 201,
      "json" => ["id" => $orderId, "status" => "CREATED"],
      "raw" => "",
      "error" => ""
    ];
  }
  if (preg_match("#/v2/checkout/orders/([^/]+)/capture#", $url, $m)) {
    return paypal_mock_capture($m[1]);
  }
  if (preg_match("#/v2/checkout/orders/([^/]+)$#", $url, $m)) {
    $store = paypal_json_read(paypal_mock_store_path());
    $row = $store[$m[1]] ?? null;
    if (!is_array($row)) {
      return ["ok" => false, "status" => 404, "json" => ["error" => "not found"], "raw" => "", "error" => ""];
    }
    return ["ok" => true, "status" => 200, "json" => paypal_mock_order_payload($row, "CREATED"), "raw" => "", "error" => ""];
  }
  if (str_contains($url, "/v1/notifications/verify-webhook-signature")) {
    $ok = (($data["webhook_id"] ?? "") !== "") && (($data["transmission_id"] ?? "") !== "bad-sig");
    return [
      "ok" => true,
      "status" => 200,
      "json" => ["verification_status" => $ok ? "SUCCESS" : "FAILURE"],
      "raw" => "",
      "error" => ""
    ];
  }
  return ["ok" => false, "status" => 404, "json" => ["error" => "mock miss"], "raw" => "", "error" => "unknown mock url"];
}

function paypal_mock_order_payload(array $row, string $status): array
{
  $captureId = "CAP-" . substr(hash("sha256", (string) $row["id"]), 0, 12);
  $amount = (string) $row["amount"];
  $currency = (string) $row["currency"];
  $custom = (string) $row["custom_id"];
  $sku = (string) $row["sku"];
  $id = (string) $row["id"];
  if (str_contains($id, "WRONGAMT")) {
    $amount = "0.01";
  }
  if (str_contains($id, "WRONGCUR")) {
    $currency = "EUR";
  }
  if (str_contains($id, "WRONGSKU")) {
    $custom = "other-client|other-sku";
    $sku = "other-sku";
  }
  if (str_contains($id, "INCOMPLETE")) {
    $status = "APPROVED";
  }
  $capture = [
    "id" => $captureId,
    "status" => $status === "COMPLETED" ? "COMPLETED" : "PENDING",
    "amount" => ["value" => $amount, "currency_code" => $currency],
    "custom_id" => $custom
  ];
  return [
    "id" => $id,
    "status" => $status,
    "purchase_units" => [[
      "custom_id" => $custom,
      "reference_id" => $sku,
      "amount" => ["value" => $amount, "currency_code" => $currency],
      "items" => [["sku" => $sku, "name" => $sku]],
      "payments" => ["captures" => [$capture]]
    ]]
  ];
}

function paypal_mock_capture(string $orderId): array
{
  $store = paypal_json_read(paypal_mock_store_path());
  $row = $store[$orderId] ?? null;
  if (!is_array($row)) {
    if (str_starts_with($orderId, "ORDER-")) {
      $row = [
        "id" => $orderId,
        "amount" => "4.99",
        "currency" => PAYPAL_CURRENCY,
        "custom_id" => "abcdef1234567890|macro-indicators-research",
        "sku" => "macro-indicators-research"
      ];
    } else {
      return ["ok" => false, "status" => 404, "json" => ["error" => "order not found"], "raw" => "", "error" => ""];
    }
  }
  $status = str_contains($orderId, "INCOMPLETE") ? "APPROVED" : "COMPLETED";
  return [
    "ok" => true,
    "status" => 201,
    "json" => paypal_mock_order_payload($row, $status),
    "raw" => "",
    "error" => ""
  ];
}

function paypal_access_token(array $cfg): string
{
  $res = paypal_http(
    "POST",
    paypal_api_base($cfg) . "/v1/oauth2/token",
    [
      "Accept: application/json",
      "Authorization: Basic " . base64_encode($cfg["client_id"] . ":" . $cfg["client_secret"]),
      "Content-Type: application/x-www-form-urlencoded"
    ],
    "grant_type=client_credentials"
  );
  $token = (string) ($res["json"]["access_token"] ?? "");
  if (!$res["ok"] || $token === "") {
    paypal_log("oauth_failed", ["status" => $res["status"]]);
    return "";
  }
  return $token;
}

function paypal_auth_headers(array $cfg): array
{
  $token = paypal_access_token($cfg);
  if ($token === "") {
    return [];
  }
  return [
    "Authorization: Bearer " . $token,
    "Content-Type: application/json",
    "Accept: application/json"
  ];
}

function paypal_create_order(string $clientId, string $sku): array
{
  $cfg = paypal_config();
  if ($cfg === null) {
    return ["ok" => false, "error" => "checkout not available yet", "http" => 503];
  }
  $sku = strtolower(trim($sku));
  if ($sku === "codex-buy" || $sku === "codex-sell") {
    $sku = "btc-treasury-bot";
  }
  if (!paypal_sku_live($sku)) {
    return ["ok" => false, "error" => "That item is not for sale.", "http" => 400];
  }
  $price = paypal_sku_price($sku);
  if ($price === null) {
    return ["ok" => false, "error" => "That item has no catalog price.", "http" => 400];
  }
  $name = paypal_sku_name($sku);
  $headers = paypal_auth_headers($cfg);
  if ($headers === []) {
    return ["ok" => false, "error" => "checkout not available yet", "http" => 503];
  }
  $payload = [
    "intent" => "CAPTURE",
    "purchase_units" => [[
      "custom_id" => paypal_custom_id($clientId, $sku),
      "reference_id" => $sku,
      "description" => $name,
      "amount" => [
        "currency_code" => PAYPAL_CURRENCY,
        "value" => $price,
        "breakdown" => [
          "item_total" => ["currency_code" => PAYPAL_CURRENCY, "value" => $price]
        ]
      ],
      "items" => [[
        "name" => $name,
        "quantity" => "1",
        "sku" => $sku,
        "unit_amount" => ["currency_code" => PAYPAL_CURRENCY, "value" => $price]
      ]]
    ]]
  ];
  $res = paypal_http("POST", paypal_api_base($cfg) . "/v2/checkout/orders", $headers, $payload);
  $orderId = (string) ($res["json"]["id"] ?? "");
  paypal_log("order_create", [
    "ok" => $res["ok"],
    "status" => $res["status"],
    "order_id" => $orderId,
    "sku" => $sku,
    "client_id" => $clientId,
    "amount" => $price
  ]);
  if (!$res["ok"] || $orderId === "") {
    return ["ok" => false, "error" => "PayPal did not create the order.", "http" => 502];
  }
  return [
    "ok" => true,
    "order_id" => $orderId,
    "sku" => $sku,
    "amount" => $price,
    "currency" => PAYPAL_CURRENCY
  ];
}

function paypal_unit_amount(array $order): array
{
  $units = isset($order["purchase_units"]) && is_array($order["purchase_units"]) ? $order["purchase_units"] : [];
  $unit = isset($units[0]) && is_array($units[0]) ? $units[0] : [];
  $captures = $unit["payments"]["captures"] ?? [];
  $capture = isset($captures[0]) && is_array($captures[0]) ? $captures[0] : [];
  $amount = $capture["amount"] ?? $unit["amount"] ?? [];
  if (!is_array($amount)) {
    $amount = [];
  }
  $items = isset($unit["items"]) && is_array($unit["items"]) ? $unit["items"] : [];
  $sku = (string) (($items[0]["sku"] ?? "") ?: ($unit["reference_id"] ?? ""));
  $custom = (string) ($capture["custom_id"] ?? $unit["custom_id"] ?? "");
  $parsed = paypal_parse_custom_id($custom);
  if ($sku === "" && $parsed["sku"] !== "") {
    $sku = $parsed["sku"];
  }
  return [
    "capture_id" => (string) ($capture["id"] ?? ""),
    "capture_status" => (string) ($capture["status"] ?? ""),
    "amount" => paypal_money((string) ($amount["value"] ?? "")),
    "currency" => strtoupper((string) ($amount["currency_code"] ?? "")),
    "custom_id" => $custom,
    "client_id" => $parsed["client_id"],
    "sku" => $sku,
    "order_status" => (string) ($order["status"] ?? "")
  ];
}

function paypal_verify_captured_order(array $order, string $clientId, string $sku): array
{
  $got = paypal_unit_amount($order);
  $price = paypal_sku_price($sku);
  if ($price === null) {
    return ["ok" => false, "error" => "Unknown catalog SKU."];
  }
  if (strtoupper($got["order_status"]) !== "COMPLETED" && strtoupper($got["capture_status"]) !== "COMPLETED") {
    return ["ok" => false, "error" => "PayPal capture is not COMPLETED."];
  }
  if ($got["capture_id"] === "") {
    return ["ok" => false, "error" => "Missing capture id."];
  }
  if ($got["currency"] !== PAYPAL_CURRENCY) {
    return ["ok" => false, "error" => "Currency mismatch."];
  }
  if (!paypal_money_eq($got["amount"], $price)) {
    return ["ok" => false, "error" => "Amount mismatch."];
  }
  $parsed = paypal_parse_custom_id($got["custom_id"]);
  $gotSku = strtolower($got["sku"] !== "" ? $got["sku"] : $parsed["sku"]);
  $gotClient = $parsed["client_id"] !== "" ? $parsed["client_id"] : $got["client_id"];
  if ($gotSku !== strtolower($sku)) {
    return ["ok" => false, "error" => "SKU mismatch."];
  }
  if ($clientId !== "" && $gotClient !== "" && $gotClient !== $clientId) {
    return ["ok" => false, "error" => "Client mismatch."];
  }
  return [
    "ok" => true,
    "capture_id" => $got["capture_id"],
    "amount" => $price,
    "currency" => PAYPAL_CURRENCY,
    "sku" => $sku,
    "client_id" => $gotClient !== "" ? $gotClient : $clientId
  ];
}

function paypal_store_all(): array
{
  $data = paypal_json_read(paypal_captures_path());
  if (!isset($data["captures"]) || !is_array($data["captures"])) {
    $data["captures"] = [];
  }
  return $data;
}

function paypal_record_capture(array $row): array
{
  $captureId = trim((string) ($row["capture_id"] ?? ""));
  if ($captureId === "") {
    return ["ok" => false, "error" => "Missing capture id.", "already" => false];
  }
  $path = paypal_captures_path();
  $all = paypal_store_all();
  $existing = $all["captures"][$captureId] ?? null;
  if (is_array($existing) && empty($existing["refunded"])) {
    paypal_log("capture_idempotent", ["capture_id" => $captureId]);
    return ["ok" => true, "already" => true, "record" => $existing];
  }
  $record = [
    "capture_id" => $captureId,
    "order_id" => (string) ($row["order_id"] ?? ""),
    "sku" => (string) ($row["sku"] ?? ""),
    "client_id" => (string) ($row["client_id"] ?? ""),
    "payer_email" => strtolower(trim((string) ($row["payer_email"] ?? ""))),
    "amount" => paypal_money((string) ($row["amount"] ?? "")),
    "currency" => strtoupper((string) ($row["currency"] ?? PAYPAL_CURRENCY)),
    "status" => "COMPLETED",
    "source" => (string) ($row["source"] ?? "capture"),
    "button_id" => (string) ($row["button_id"] ?? ""),
    "refunded" => false,
    "at" => paypal_now_ms()
  ];
  if (is_array($existing) && !empty($existing["refunded"])) {
    $record["refunded"] = false;
    $record["reinstated_at"] = paypal_now_ms();
  }
  $all["captures"][$captureId] = $record;
  paypal_json_write($path, $all);
  paypal_log("capture_recorded", [
    "capture_id" => $captureId,
    "order_id" => $record["order_id"],
    "sku" => $record["sku"],
    "client_id" => $record["client_id"],
    "payer_email" => $record["payer_email"],
    "amount" => $record["amount"],
    "source" => $record["source"]
  ]);
  return ["ok" => true, "already" => is_array($existing), "record" => $record];
}

function paypal_mark_refunded(string $captureId, string $reason): bool
{
  $captureId = trim($captureId);
  if ($captureId === "") {
    return false;
  }
  $all = paypal_store_all();
  if (!isset($all["captures"][$captureId]) || !is_array($all["captures"][$captureId])) {
    paypal_log("refund_unknown_capture", ["capture_id" => $captureId, "reason" => $reason]);
    return false;
  }
  $all["captures"][$captureId]["refunded"] = true;
  $all["captures"][$captureId]["refund_reason"] = $reason;
  $all["captures"][$captureId]["refunded_at"] = paypal_now_ms();
  paypal_json_write(paypal_captures_path(), $all);
  paypal_log("capture_refunded", ["capture_id" => $captureId, "reason" => $reason]);
  return true;
}

function paypal_active_record(array $row): bool
{
  return empty($row["refunded"]) && strtoupper((string) ($row["status"] ?? "")) === "COMPLETED";
}

function paypal_client_purchases(string $clientId): array
{
  $clientId = trim($clientId);
  $email = "";
  $record = client_record_for($clientId);
  if (is_array($record)) {
    $email = strtolower(trim((string) ($record["email"] ?? "")));
  }
  $all = paypal_store_all();
  $out = [];
  $seen = [];
  foreach ($all["captures"] as $row) {
    if (!is_array($row) || !paypal_active_record($row)) {
      continue;
    }
    $sku = (string) ($row["sku"] ?? "");
    if ($sku === "") {
      continue;
    }
    $matchClient = $clientId !== "" && (string) ($row["client_id"] ?? "") === $clientId;
    $matchEmail = $email !== "" && (string) ($row["payer_email"] ?? "") === $email;
    if (!$matchClient && !$matchEmail) {
      continue;
    }
    if (isset($seen[$sku])) {
      continue;
    }
    $seen[$sku] = true;
    $out[] = [
      "id" => $sku,
      "name" => paypal_sku_name($sku),
      "kind" => (string) ((paypal_sku_row($sku)["kind"] ?? "module")),
      "capture_id" => (string) ($row["capture_id"] ?? ""),
      "amount" => (string) ($row["amount"] ?? ""),
      "at" => (int) ($row["at"] ?? 0),
      "verified" => true
    ];
  }
  return $out;
}

function paypal_has_verified_purchase(string $clientId, string $sku, string $email = ""): bool
{
  $sku = strtolower(trim($sku));
  $clientId = trim($clientId);
  $email = strtolower(trim($email));
  if ($sku === "") {
    return false;
  }
  foreach (paypal_store_all()["captures"] as $row) {
    if (!is_array($row) || !paypal_active_record($row)) {
      continue;
    }
    if (strtolower((string) ($row["sku"] ?? "")) !== $sku) {
      continue;
    }
    if ($clientId !== "" && (string) ($row["client_id"] ?? "") === $clientId) {
      return true;
    }
    if ($email !== "" && (string) ($row["payer_email"] ?? "") === $email) {
      return true;
    }
  }
  return false;
}

function paypal_capture_order(string $clientId, string $orderId): array
{
  $cfg = paypal_config();
  if ($cfg === null) {
    return ["ok" => false, "error" => "checkout not available yet", "http" => 503];
  }
  $orderId = trim($orderId);
  if ($orderId === "") {
    return ["ok" => false, "error" => "Missing order id.", "http" => 400];
  }
  $headers = paypal_auth_headers($cfg);
  if ($headers === []) {
    return ["ok" => false, "error" => "checkout not available yet", "http" => 503];
  }
  $res = paypal_http("POST", paypal_api_base($cfg) . "/v2/checkout/orders/" . rawurlencode($orderId) . "/capture", $headers, "{}");
  paypal_log("order_capture", [
    "ok" => $res["ok"],
    "status" => $res["status"],
    "order_id" => $orderId,
    "client_id" => $clientId
  ]);
  if (!$res["ok"]) {
    return ["ok" => false, "error" => "PayPal capture failed.", "http" => 502];
  }
  $got = paypal_unit_amount($res["json"]);
  $sku = $got["sku"];
  if ($sku === "") {
    return ["ok" => false, "error" => "Capture did not include a SKU.", "http" => 400];
  }
  $check = paypal_verify_captured_order($res["json"], $clientId, $sku);
  if (!$check["ok"]) {
    paypal_log("capture_rejected", [
      "order_id" => $orderId,
      "reason" => $check["error"],
      "client_id" => $clientId,
      "sku" => $sku
    ]);
    return ["ok" => false, "error" => $check["error"], "http" => 400];
  }
  $saved = paypal_record_capture([
    "capture_id" => $check["capture_id"],
    "order_id" => $orderId,
    "sku" => $check["sku"],
    "client_id" => $check["client_id"],
    "amount" => $check["amount"],
    "currency" => $check["currency"],
    "source" => "sdk-capture"
  ]);
  return [
    "ok" => true,
    "already" => $saved["already"],
    "sku" => $check["sku"],
    "amount" => $check["amount"],
    "capture_id" => $check["capture_id"],
    "purchases" => paypal_client_purchases($check["client_id"])
  ];
}

function paypal_find_button_id(array $event): string
{
  $blob = json_encode($event);
  if (!is_string($blob)) {
    return "";
  }
  if (preg_match("/PLB-[A-Z0-9]+/", $blob, $m)) {
    return $m[0];
  }
  return "";
}

function paypal_webhook_resource(array $event): array
{
  $resource = isset($event["resource"]) && is_array($event["resource"]) ? $event["resource"] : [];
  $amount = $resource["amount"] ?? ($resource["seller_receivable_breakdown"]["gross_amount"] ?? []);
  if (!is_array($amount)) {
    $amount = [];
  }
  $supp = $resource["supplementary_data"]["related_ids"] ?? [];
  if (!is_array($supp)) {
    $supp = [];
  }
  $custom = (string) ($resource["custom_id"] ?? $resource["custom"] ?? "");
  $parsed = paypal_parse_custom_id($custom);
  $sku = $parsed["sku"];
  $button = paypal_find_button_id($event);
  $nocode = paypal_nocode_skus();
  $paid = paypal_money((string) ($amount["value"] ?? "0"));
  if ($sku === "" && $button !== "" && isset($nocode[$button])) {
    $sku = $nocode[$button]["sku"];
  }
  $payer = "";
  if (isset($resource["payer"]["email_address"])) {
    $payer = strtolower(trim((string) $resource["payer"]["email_address"]));
  }
  if ($payer === "" && isset($event["resource"]["payer"]["email_address"])) {
    $payer = strtolower(trim((string) $event["resource"]["payer"]["email_address"]));
  }
  return [
    "capture_id" => (string) ($resource["id"] ?? ""),
    "order_id" => (string) ($supp["order_id"] ?? $resource["supplementary_data"]["related_ids"]["order_id"] ?? ""),
    "amount" => $paid,
    "currency" => strtoupper((string) ($amount["currency_code"] ?? "")),
    "custom_id" => $custom,
    "client_id" => $parsed["client_id"],
    "sku" => $sku,
    "button_id" => $button,
    "payer_email" => $payer,
    "status" => (string) ($resource["status"] ?? "")
  ];
}

function paypal_webhook_verify(array $cfg, array $headers, array $event, string $raw): bool
{
  unset($raw);
  $cert = trim((string) ($headers["paypal-cert-url"] ?? $headers["PAYPAL-CERT-URL"] ?? ""));
  $host = strtolower((string) (parse_url($cert, PHP_URL_HOST) ?? ""));
  $allowed = ["api.paypal.com", "api.sandbox.paypal.com"];
  if ($cert === "" || !in_array($host, $allowed, true)) {
    paypal_log("webhook_bad_cert", ["host" => $host]);
    return false;
  }
  if ($cfg["webhook_id"] === "") {
    paypal_log("webhook_missing_id", []);
    return false;
  }
  $auth = paypal_auth_headers($cfg);
  if ($auth === []) {
    return false;
  }
  $payload = [
    "auth_algo" => (string) ($headers["paypal-auth-algo"] ?? $headers["PAYPAL-AUTH-ALGO"] ?? ""),
    "cert_url" => $cert,
    "transmission_id" => (string) ($headers["paypal-transmission-id"] ?? $headers["PAYPAL-TRANSMISSION-ID"] ?? ""),
    "transmission_sig" => (string) ($headers["paypal-transmission-sig"] ?? $headers["PAYPAL-TRANSMISSION-SIG"] ?? ""),
    "transmission_time" => (string) ($headers["paypal-transmission-time"] ?? $headers["PAYPAL-TRANSMISSION-TIME"] ?? ""),
    "webhook_id" => $cfg["webhook_id"],
    "webhook_event" => $event
  ];
  $res = paypal_http("POST", paypal_api_base($cfg) . "/v1/notifications/verify-webhook-signature", $auth, $payload);
  $status = (string) ($res["json"]["verification_status"] ?? "");
  paypal_log("webhook_verify", ["ok" => $status === "SUCCESS", "status" => $res["status"], "verification" => $status]);
  return $res["ok"] && $status === "SUCCESS";
}

function paypal_request_headers(): array
{
  $out = [];
  foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, "HTTP_")) {
      $name = strtolower(str_replace("_", "-", substr($key, 5)));
      $out[$name] = (string) $value;
    }
  }
  return $out;
}

function paypal_amount_allowed_for_sku(string $sku, string $amount, string $buttonId): bool
{
  $catalog = paypal_sku_price($sku);
  if ($catalog !== null && paypal_money_eq($amount, $catalog)) {
    return true;
  }
  $nocode = paypal_nocode_skus();
  if ($buttonId !== "" && isset($nocode[$buttonId])) {
    $row = $nocode[$buttonId];
    return $row["sku"] === $sku && paypal_money_eq($amount, $row["amount"]);
  }
  return false;
}

function paypal_mail_headers(): string
{
  return "From: Halfacre Research <noreply@halfacreresearch.tech>\r\n"
    . "Reply-To: " . PAYPAL_MATTHEW . "\r\n"
    . "Content-Type: text/plain; charset=utf-8\r\n";
}

function paypal_send_mail(string $to, string $subject, string $body): bool
{
  $log = getenv("HALFACRE_MAIL_LOG");
  if (is_string($log) && $log !== "") {
    $line = json_encode([
      "to" => $to,
      "subject" => $subject,
      "body" => $body
    ], JSON_UNESCAPED_SLASHES);
    file_put_contents($log, ($line === false ? "{}" : $line) . "\n", FILE_APPEND | LOCK_EX);
    return true;
  }
  return @mail($to, $subject, $body, paypal_mail_headers());
}

function paypal_sign_download(string $sku, string $email, int $exp): string
{
  $cfg = paypal_config();
  $secret = $cfg["client_secret"] ?? "";
  return hash_hmac("sha256", strtolower($sku) . "\n" . strtolower($email) . "\n" . $exp, $secret);
}

function paypal_verify_download_sig(string $sku, string $email, int $exp, string $sig): bool
{
  if ($exp < time() || $sku === "" || $email === "" || $sig === "") {
    return false;
  }
  $want = paypal_sign_download($sku, $email, $exp);
  return hash_equals($want, strtolower($sig)) || hash_equals($want, $sig);
}

function paypal_public_origin(): string
{
  return "https://www.halfacreresearch.tech";
}

function paypal_signed_download_url(string $sku, string $email): string
{
  $exp = time() + PAYPAL_DOWNLOAD_TTL;
  $sig = paypal_sign_download($sku, $email, $exp);
  return paypal_public_origin() . "/van-download.php?" . http_build_query([
    "sku" => $sku,
    "email" => $email,
    "exp" => $exp,
    "sig" => $sig
  ]);
}

function paypal_email_download_link(string $email, string $sku, string $orderId): void
{
  $email = strtolower(trim($email));
  if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    paypal_log("download_mail_skipped", ["reason" => "no email", "sku" => $sku, "order_id" => $orderId]);
    return;
  }
  $link = paypal_signed_download_url($sku, $email);
  $name = paypal_sku_name($sku);
  $cfg = paypal_config();
  $enabled = $cfg !== null && $cfg["pack_delivery"] === true && paypal_pack_file($sku) !== "";
  $body = "We recorded a PayPal payment for " . $name . ".\n\n";
  if ($enabled) {
    $body .= "Your download link (expires in 24 hours). Do not share it.\n\n" . $link . "\n";
  } else {
    $body .= "The file is not released yet (sales hold). We recorded order "
      . $orderId . " against this email. When delivery is enabled you can use:\n\n"
      . $link . "\n\nIf that link has expired, reply to this email.\n";
  }
  $body .= "\nHalfacre Research\nKnow more. Bank less.\n";
  $ok = paypal_send_mail($email, "Your Halfacre Research download", $body);
  paypal_log("download_mail", ["ok" => $ok, "email" => $email, "sku" => $sku, "order_id" => $orderId]);
}

function paypal_handle_webhook_event(array $event): array
{
  $type = (string) ($event["event_type"] ?? "");
  $allowed = [
    "CHECKOUT.ORDER.APPROVED",
    "PAYMENT.CAPTURE.COMPLETED",
    "PAYMENT.CAPTURE.REFUNDED",
    "PAYMENT.CAPTURE.REVERSED"
  ];
  if (!in_array($type, $allowed, true)) {
    paypal_log("webhook_ignored_type", ["type" => $type]);
    return ["ok" => true, "ignored" => true, "type" => $type];
  }
  $res = paypal_webhook_resource($event);
  if ($type === "CHECKOUT.ORDER.APPROVED") {
    paypal_log("webhook_approved", [
      "order_id" => $res["order_id"],
      "sku" => $res["sku"],
      "amount" => $res["amount"]
    ]);
    return ["ok" => true, "type" => $type, "recorded" => false];
  }
  if ($type === "PAYMENT.CAPTURE.REFUNDED" || $type === "PAYMENT.CAPTURE.REVERSED") {
    $id = $res["capture_id"];
    if ($id === "" && isset($event["resource"]["id"])) {
      $id = (string) $event["resource"]["id"];
    }
    paypal_mark_refunded($id, $type);
    return ["ok" => true, "type" => $type, "refunded" => true];
  }
  if ($res["sku"] === "") {
    paypal_log("webhook_unmapped_sku", [
      "capture_id" => $res["capture_id"],
      "order_id" => $res["order_id"],
      "amount" => $res["amount"],
      "button_id" => $res["button_id"],
      "payer_email" => $res["payer_email"]
    ]);
    return ["ok" => true, "type" => $type, "recorded" => false, "error" => "SKU unknown — capture logged only."];
  }
  if ($res["currency"] !== "" && $res["currency"] !== PAYPAL_CURRENCY) {
    paypal_log("webhook_bad_currency", $res);
    return ["ok" => false, "error" => "Currency mismatch."];
  }
  if (!paypal_amount_allowed_for_sku($res["sku"], $res["amount"], $res["button_id"])) {
    paypal_log("webhook_bad_amount", $res);
    return ["ok" => false, "error" => "Amount mismatch."];
  }
  $saved = paypal_record_capture([
    "capture_id" => $res["capture_id"],
    "order_id" => $res["order_id"],
    "sku" => $res["sku"],
    "client_id" => $res["client_id"],
    "payer_email" => $res["payer_email"],
    "amount" => $res["amount"],
    "currency" => $res["currency"] !== "" ? $res["currency"] : PAYPAL_CURRENCY,
    "source" => $res["button_id"] !== "" ? "webhook-nocode" : "webhook",
    "button_id" => $res["button_id"]
  ]);
  if ($saved["ok"] && !$saved["already"] && $res["client_id"] === "" && $res["payer_email"] !== "") {
    paypal_email_download_link($res["payer_email"], $res["sku"], $res["order_id"]);
  }
  return ["ok" => true, "type" => $type, "recorded" => $saved["ok"], "already" => $saved["already"]];
}

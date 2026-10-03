<?php
/**
 * Live sFOX balances for a client page.
 * Decrypts the vault key on the server. Never returns the key.
 * Same emailed page token as client.php / client-memory.php.
 */
declare(strict_types=1);

require_once __DIR__ . "/client-token.php";
require_once __DIR__ . "/client-secrets-lib.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  header("Access-Control-Allow-Methods: GET, OPTIONS");
  header("Access-Control-Allow-Headers: Content-Type");
  http_response_code(204);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "GET only"]);
  exit;
}

const SERVICE = HALFACRE_SECRETS_SERVICE;
const SFOX = "https://api.sfox.com";

$rawC = (string) ($_GET["c"] ?? "");
if (halfacre_secrets_client_id($rawC) === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Missing page id"]);
  exit;
}

$auth = client_require_page_token();
$client = $auth["id"];

if (!halfacre_secrets_configured()) {
  halfacre_secrets_refuse_unconfigured();
}

try {
  $all = halfacre_secrets_read();
} catch (Throwable $e) {
  halfacre_secrets_refuse_unconfigured();
}

$svc = halfacre_secrets_row($all, $client, SERVICE);
$key = isset($svc["key"]) ? trim((string) $svc["key"]) : "";
$hint = isset($svc["hint"]) ? (string) $svc["hint"] : "";

function sfox_get(string $path, string $key): array
{
  $stub = getenv("HALFACRE_SFOX_STUB");
  if (is_string($stub) && $stub !== "" && $stub !== "0") {
    if (str_starts_with($path, "/v1/user/balance")) {
      return [
        "ok" => true,
        "code" => 200,
        "data" => [
          [
            "currency" => "USD",
            "balance" => 10,
            "available" => 10,
            "held" => 0,
            "trading_wallet" => 0
          ]
        ],
        "error" => ""
      ];
    }
    return ["ok" => false, "code" => 404, "data" => null, "error" => "stub miss"];
  }
  $url = SFOX . $path;
  if (!function_exists("curl_init")) {
    return ["ok" => false, "code" => 0, "data" => null, "error" => "curl missing"];
  }
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_HTTPGET => true,
    CURLOPT_HTTPHEADER => [
      "Authorization: Bearer " . $key,
      "Accept: application/json"
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20
  ]);
  $res = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if (!is_string($res) || $res === "") {
    return ["ok" => false, "code" => $code, "data" => null, "error" => $err !== "" ? "empty" : "empty"];
  }
  $data = json_decode($res, true);
  return [
    "ok" => $code >= 200 && $code < 300 && $data !== null,
    "code" => $code,
    "data" => $data,
    "error" => ""
  ];
}

function is_usdish(string $currency): bool
{
  $c = strtolower($currency);
  return in_array($c, ["usd", "usdc", "usdt", "dai", "busd", "pyusd"], true);
}

function num($value): float
{
  if (is_int($value) || is_float($value)) {
    return (float) $value;
  }
  if (is_string($value) && is_numeric($value)) {
    return (float) $value;
  }
  return 0.0;
}

function estimate_usd(string $currency, float $qty, string $key): ?float
{
  if ($qty <= 0) {
    return 0.0;
  }
  if (is_usdish($currency)) {
    return $qty;
  }
  $pair = strtolower($currency) . "usd";
  $path = "/v1/offer/sell?" . http_build_query([
    "pair" => $pair,
    "quantity" => $qty
  ]);
  $pack = sfox_get($path, $key);
  if (!$pack["ok"] || !is_array($pack["data"])) {
    return null;
  }
  $data = $pack["data"];
  if (isset($data["total"]) && is_numeric($data["total"])) {
    return (float) $data["total"];
  }
  if (isset($data["subtotal"]) && is_numeric($data["subtotal"])) {
    return (float) $data["subtotal"];
  }
  if (isset($data["vwap"]) && is_numeric($data["vwap"])) {
    return (float) $data["vwap"] * $qty;
  }
  return null;
}

if ($key === "") {
  echo json_encode([
    "ok" => true,
    "client" => $client,
    "connected" => false,
    "hint" => "",
    "holdings" => [],
    "totalUsd" => null,
    "asOf" => 0
  ]);
  exit;
}

$pack = sfox_get("/v1/user/balance", $key);
if (!$pack["ok"] || !is_array($pack["data"])) {
  $msg = "sFOX did not return balances.";
  if ((int) $pack["code"] === 401 || (int) $pack["code"] === 403) {
    $msg = "sFOX did not accept the saved key.";
  }
  http_response_code(502);
  echo json_encode([
    "ok" => false,
    "client" => $client,
    "connected" => true,
    "hint" => $hint,
    "error" => $msg,
    "holdings" => [],
    "totalUsd" => null,
    "asOf" => 0
  ]);
  exit;
}

$raw = $pack["data"];
if (isset($raw["data"]) && is_array($raw["data"])) {
  $raw = $raw["data"];
}
if (!is_array($raw)) {
  $raw = [];
}

$holdings = [];
foreach ($raw as $row) {
  if (!is_array($row)) {
    continue;
  }
  $currency = strtoupper(trim((string) ($row["currency"] ?? "")));
  if ($currency === "") {
    continue;
  }
  $balance = num($row["balance"] ?? 0);
  if (abs($balance) < 0.00000001) {
    continue;
  }
  $holdings[] = [
    "currency" => $currency,
    "balance" => $balance,
    "available" => num($row["available"] ?? 0),
    "held" => num($row["held"] ?? 0),
    "trading" => num($row["trading_wallet"] ?? 0),
    "usd" => null
  ];
}

usort($holdings, function ($a, $b) {
  return $b["balance"] <=> $a["balance"];
});

$wantPrices = !empty($_GET["prices"]);
$priced = 0;
$totalUsd = 0.0;
$haveTotal = $wantPrices;
foreach ($holdings as $i => $row) {
  if (!$wantPrices) {
    continue;
  }
  if (is_usdish($row["currency"])) {
    $holdings[$i]["usd"] = $row["balance"];
    $totalUsd += $row["balance"];
    continue;
  }
  if ($priced >= 5) {
    $haveTotal = false;
    continue;
  }
  $usd = estimate_usd($row["currency"], $row["balance"], $key);
  $priced++;
  if ($usd === null) {
    $haveTotal = false;
    continue;
  }
  $holdings[$i]["usd"] = $usd;
  $totalUsd += $usd;
}

echo json_encode([
  "ok" => true,
  "client" => $client,
  "connected" => true,
  "hint" => $hint,
  "holdings" => $holdings,
  "totalUsd" => $haveTotal && $holdings ? round($totalUsd, 2) : null,
  "asOf" => (int) round(microtime(true) * 1000)
]);

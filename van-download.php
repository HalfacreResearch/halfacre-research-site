<?php
/**
 * Serve a purchased file only after a verified PayPal capture for that client+sku
 * (or a short-lived signed email link). Does not change packs/ or dl/.
 */
declare(strict_types=1);

require_once __DIR__ . "/client-token.php";
require_once __DIR__ . "/paypal-lib.php";

function van_download_refuse(int $code, string $error): void
{
  http_response_code($code);
  header("Content-Type: text/plain; charset=utf-8");
  header("Cache-Control: no-store");
  header("X-Content-Type-Options: nosniff");
  echo $error;
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  van_download_refuse(405, "GET only");
}

$sku = strtolower(trim((string) ($_GET["sku"] ?? $_GET["id"] ?? "")));
if ($sku === "") {
  van_download_refuse(400, "Missing sku.");
}

$clientId = "";
$email = strtolower(trim((string) ($_GET["email"] ?? "")));
$signed = false;

$token = client_token_from_request();
$rawClient = trim((string) ($_GET["c"] ?? ""));
if ($rawClient !== "" && $token !== "") {
  $auth = client_require_page_token();
  $clientId = $auth["id"];
  $record = client_record_for($clientId);
  if (is_array($record)) {
    $email = strtolower(trim((string) ($record["email"] ?? $email)));
  }
} else {
  $exp = (int) ($_GET["exp"] ?? 0);
  $sig = trim((string) ($_GET["sig"] ?? ""));
  if (!paypal_verify_download_sig($sku, $email, $exp, $sig)) {
    van_download_refuse(403, "Download denied.");
  }
  $signed = true;
}

if (!paypal_has_verified_purchase($clientId, $sku, $email)) {
  van_download_refuse(403, "Download denied.");
}

$cfg = paypal_config();
$path = paypal_pack_file($sku);
$packSku = $path !== "";
if ($packSku) {
  if ($cfg === null || $cfg["pack_delivery"] !== true) {
    van_download_refuse(403, "This download is not ready. Pack delivery is on hold.");
  }
  $fh = fopen($path, "rb");
  if ($fh === false) {
    van_download_refuse(503, "This download is not ready.");
  }
  header("Content-Type: application/zip");
  header("Content-Disposition: attachment; filename=\"" . $sku . ".zip\"");
  header("Content-Length: " . (string) filesize($path));
  header("Cache-Control: no-store");
  header("X-Content-Type-Options: nosniff");
  header("X-Robots-Tag: noindex");
  fpassthru($fh);
  fclose($fh);
  paypal_log("download_served", [
    "sku" => $sku,
    "client_id" => $clientId,
    "signed" => $signed,
    "bytes" => filesize($path)
  ]);
  exit;
}

van_download_refuse(403, "This download is not ready. No file is on the server for that SKU.");

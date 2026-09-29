<?php
/**
 * Re-fetch a live Van module after unlock.
 * Unlock list (van-unlocks.store.json) is source of truth.
 * Serves van-fulfillment/{sku}.json, then the pack zip when present.
 */
declare(strict_types=1);

header("X-Content-Type-Options: nosniff");
header("Cache-Control: no-store");

const VAN_USER_ID = "charlie-van-halfacre";
const VAN_EMAIL = "cvhalfacre@msn.com";
const STORE = __DIR__ . "/van-unlocks.store.json";
const CATALOG = __DIR__ . "/van-products.json";
const FULFILL = __DIR__ . "/van-fulfillment";

function fail(int $code, string $message): void
{
  http_response_code($code);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode(["ok" => false, "error" => $message]);
  exit;
}

function unlocked(string $sku): bool
{
  if ($sku === "" || !is_file(STORE)) {
    return false;
  }
  $raw = file_get_contents(STORE);
  $data = json_decode(is_string($raw) ? $raw : "", true);
  if (!is_array($data) || !isset($data["modules"]) || !is_array($data["modules"])) {
    return false;
  }
  return isset($data["modules"][$sku]);
}

function find_row(string $sku): ?array
{
  if (!is_file(CATALOG)) {
    return null;
  }
  $raw = file_get_contents(CATALOG);
  $data = json_decode(is_string($raw) ? $raw : "", true);
  if (!is_array($data) || !isset($data["modules"]) || !is_array($data["modules"])) {
    return null;
  }
  foreach ($data["modules"] as $row) {
    if (is_array($row) && isset($row["id"]) && (string) $row["id"] === $sku) {
      return $row;
    }
  }
  return null;
}

function send_file(string $path, string $downloadName, string $mime): void
{
  header("Content-Type: " . $mime);
  header("Content-Disposition: attachment; filename=\"" . $downloadName . "\"");
  header("Content-Length: " . (string) filesize($path));
  readfile($path);
  exit;
}

$sku = isset($_GET["sku"]) ? trim((string) $_GET["sku"]) : "";
$userId = isset($_GET["userId"]) ? trim((string) $_GET["userId"]) : VAN_USER_ID;
$email = isset($_GET["email"]) ? trim((string) $_GET["email"]) : VAN_EMAIL;
$want = isset($_GET["file"]) ? trim((string) $_GET["file"]) : "receipt";

if ($sku === "") {
  fail(400, "Missing sku");
}
if ($userId !== VAN_USER_ID && $userId !== "van" && strtolower($email) !== VAN_EMAIL) {
  fail(403, "Unknown unlock identity");
}

$row = find_row($sku);
if ($row === null || empty($row["live"])) {
  fail(404, "Not a live module.");
}
if (!unlocked($sku)) {
  fail(403, "Not unlocked. Pay for this module first. The unlock list is source of truth.");
}

$fulfill = isset($row["fulfillment"]) && is_array($row["fulfillment"]) ? $row["fulfillment"] : [];
$packDir = isset($fulfill["pack_dir"]) ? (string) $fulfill["pack_dir"] : "";
$zip = $packDir !== "" ? __DIR__ . "/" . $packDir . "/PACK.zip" : "";

if ($want === "zip" && $zip !== "" && is_file($zip) && filesize($zip) > 100) {
  send_file($zip, $sku . "-PACK.zip", "application/zip");
}

$receipt = FULFILL . "/" . $sku . ".json";
if (is_file($receipt)) {
  send_file($receipt, $sku . ".json", "application/json");
}

fail(404, "Fulfillment file missing for this unlock.");

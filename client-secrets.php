<?php
/**
 * Forever vault for one client’s API keys (sFOX first).
 * Desk route: Basic Auth. Encrypts at rest outside the web root.
 * GET returns saved + last-4 hint. Never returns the key.
 */
declare(strict_types=1);

require_once __DIR__ . "/client-secrets-lib.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
  header("Access-Control-Allow-Headers: Content-Type");
  http_response_code(204);
  exit;
}

$keyIn = (string) ($_GET["c"] ?? "");
$payload = [];
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $raw = file_get_contents("php://input");
  $payload = json_decode(is_string($raw) ? $raw : "", true);
  if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Bad JSON"]);
    exit;
  }
  $keyIn = (string) ($payload["c"] ?? $payload["userId"] ?? "");
}

$client = halfacre_secrets_client_id($keyIn);
if ($client === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Missing page id"]);
  exit;
}

if (!halfacre_secrets_configured()) {
  halfacre_secrets_refuse_unconfigured();
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
  try {
    echo json_encode(halfacre_secrets_status($client));
  } catch (Throwable $e) {
    halfacre_secrets_refuse_unconfigured();
  }
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "GET or POST only"]);
  exit;
}

$apiKey = preg_replace("/\\s+/", "", (string) ($payload["key"] ?? $payload["apiKey"] ?? "")) ?? "";
if (strlen($apiKey) < 12) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "That key looks too short."]);
  exit;
}

try {
  $all = halfacre_secrets_read();
} catch (Throwable $e) {
  halfacre_secrets_refuse_unconfigured();
}

if (!isset($all[$client]) || !is_array($all[$client])) {
  $all[$client] = [];
}
$all[$client][HALFACRE_SECRETS_SERVICE] = [
  "hint" => halfacre_secrets_hint($apiKey),
  "savedAt" => (int) round(microtime(true) * 1000),
  "key" => $apiKey
];

try {
  halfacre_secrets_write($all);
  echo json_encode(halfacre_secrets_status($client));
} catch (Throwable $e) {
  halfacre_secrets_refuse_unconfigured();
}

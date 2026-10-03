<?php
/**
 * PayPal webhook at /paypal-webhook.php
 * Verifies every event with verify-webhook-signature and PAYPAL_WEBHOOK_ID.
 * Acts only on APPROVED / CAPTURE.COMPLETED / REFUNDED / REVERSED.
 */
declare(strict_types=1);

require_once __DIR__ . "/paypal-lib.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "POST only"]);
  exit;
}

$cfg = paypal_config();
if ($cfg === null) {
  http_response_code(503);
  echo json_encode(["ok" => false, "error" => "checkout not available yet"]);
  exit;
}

$raw = file_get_contents("php://input");
$event = json_decode(is_string($raw) ? $raw : "", true);
if (!is_array($event)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad JSON"]);
  exit;
}

$headers = paypal_request_headers();
if (!paypal_webhook_verify($cfg, $headers, $event, is_string($raw) ? $raw : "")) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Webhook signature failed"]);
  exit;
}

$result = paypal_handle_webhook_event($event);
if (empty($result["ok"])) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => $result["error"] ?? "Webhook rejected"]);
  exit;
}

echo json_encode(["ok" => true, "type" => $result["type"] ?? "", "recorded" => $result["recorded"] ?? false]);

<?php
/**
 * Tiny xAI stand-in for ZDR header tests. Records bodies. Never used in production.
 */
declare(strict_types=1);

$log = getenv("HALFACRE_XAI_MOCK_LOG");
if (!is_string($log) || $log === "") {
  $log = sys_get_temp_dir() . "/halfacre-xai-mock.log";
}
$countPath = $log . ".count";
$raw = file_get_contents("php://input");
file_put_contents($log, (is_string($raw) ? $raw : "") . "\n---\n", FILE_APPEND);
$n = 1;
if (is_file($countPath)) {
  $n = ((int) trim((string) file_get_contents($countPath))) + 1;
}
file_put_contents($countPath, (string) $n);

$mode = strtolower(trim((string) getenv("HALFACRE_XAI_MOCK_ZDR")));
$header = $mode;
if ($mode === "true-then-false") {
  $header = $n === 1 ? "true" : "false";
}

header("Content-Type: application/json; charset=utf-8");
if ($header !== "missing" && $header !== "") {
  header("x-zero-data-retention: " . ($header === "true" ? "true" : "false"));
}
http_response_code(200);
echo json_encode([
  "choices" => [
    ["message" => ["content" => "mock-reply"]]
  ]
]);

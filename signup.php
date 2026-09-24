<?php
/**
 * Create a free client account and email Matthew.
 * Google ID tokens are checked with Google's tokeninfo endpoint.
 * Client records stay in clients.store.json on this host (not in git).
 */
declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  header("Access-Control-Allow-Methods: POST, OPTIONS");
  header("Access-Control-Allow-Headers: Content-Type");
  http_response_code(204);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "POST only"]);
  exit;
}

const GOOGLE_CLIENT_ID = "3260604131-hsdpbu9kmn06e449e193kqkuo9e8o9b8.apps.googleusercontent.com";
const STORE = __DIR__ . "/clients.store.json";
const MATTHEW = "matt@halfacreresearch.tech";

function clean_text(string $value, int $max): string
{
  $value = trim($value);
  $value = preg_replace("/[\\r\\n\\t]+/", " ", $value) ?? "";
  if (strlen($value) > $max) {
    $value = substr($value, 0, $max);
  }
  return trim($value);
}

function read_clients(): array
{
  if (!is_file(STORE)) {
    return [];
  }
  $raw = file_get_contents(STORE);
  $data = json_decode(is_string($raw) ? $raw : "", true);
  return is_array($data) ? $data : [];
}

function write_clients(array $rows): void
{
  $json = json_encode(array_values($rows), JSON_PRETTY_PRINT);
  file_put_contents(STORE, $json === false ? "[]\n" : $json . "\n", LOCK_EX);
}

function verify_google_token(string $token): ?array
{
  $token = trim($token);
  if ($token === "" || strlen($token) > 4096) {
    return null;
  }
  $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . rawurlencode($token);
  $res = false;
  $code = 0;
  if (function_exists("curl_init")) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 12
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
  } else {
    $ctx = stream_context_create(["http" => ["timeout" => 12, "ignore_errors" => true]]);
    $res = file_get_contents($url, false, $ctx);
    if (isset($http_response_header[0]) && preg_match("/\\s(\\d{3})\\s/", $http_response_header[0], $m)) {
      $code = (int) $m[1];
    }
  }
  if (!is_string($res) || $res === "" || $code >= 400) {
    return null;
  }
  $data = json_decode($res, true);
  if (!is_array($data)) {
    return null;
  }
  $aud = isset($data["aud"]) ? (string) $data["aud"] : "";
  $email = isset($data["email"]) ? strtolower(trim((string) $data["email"])) : "";
  if ($aud !== GOOGLE_CLIENT_ID || $email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    return null;
  }
  $name = isset($data["name"]) ? clean_text((string) $data["name"], 120) : "";
  if ($name === "" && isset($data["given_name"])) {
    $name = clean_text(trim((string) $data["given_name"] . " " . (string) ($data["family_name"] ?? "")), 120);
  }
  $phone = "";
  if (isset($data["phone_number"])) {
    $phone = clean_text((string) $data["phone_number"], 40);
  } elseif (isset($data["phone"])) {
    $phone = clean_text((string) $data["phone"], 40);
  }
  return [
    "name" => $name,
    "email" => $email,
    "phone" => $phone
  ];
}

function notify_matthew(array $client): void
{
  $name = $client["name"];
  $email = $client["email"];
  $phone = $client["phone"] !== "" ? $client["phone"] : "(none from Google)";
  $via = $client["via"];
  $subject = "New Halfacre account: " . $name;
  $body = "A free client account was created.\n\n"
    . "Name: {$name}\n"
    . "Email: {$email}\n"
    . "Phone: {$phone}\n"
    . "Via: {$via}\n"
    . "Created: {$client["created"]}\n\n"
    . "Their page: https://halfacreresearch.tech/van.html\n";
  $headers = "From: Halfacre Research <noreply@halfacreresearch.tech>\r\n"
    . "Reply-To: " . MATTHEW . "\r\n"
    . "Content-Type: text/plain; charset=utf-8\r\n";
  @mail(MATTHEW, $subject, $body, $headers);
}

$raw = file_get_contents("php://input");
$payload = json_decode(is_string($raw) ? $raw : "", true);
if (!is_array($payload)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad JSON"]);
  exit;
}

$via = "form";
$name = clean_text((string) ($payload["name"] ?? ""), 120);
$email = strtolower(clean_text((string) ($payload["email"] ?? ""), 160));
$phone = clean_text((string) ($payload["phone"] ?? ""), 40);
$token = isset($payload["google_id_token"]) ? (string) $payload["google_id_token"] : "";

if ($token !== "") {
  $google = verify_google_token($token);
  if ($google === null) {
    http_response_code(401);
    echo json_encode(["ok" => false, "error" => "Google sign-in could not be verified."]);
    exit;
  }
  $via = "google";
  $name = $google["name"] !== "" ? $google["name"] : $name;
  $email = $google["email"];
  if ($google["phone"] !== "") {
    $phone = $google["phone"];
  }
}

if ($name === "" || $email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Name and a valid email are required."]);
  exit;
}

if ($via === "form" && $phone === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Name, email, and phone are required."]);
  exit;
}

$rows = read_clients();
$existing = null;
foreach ($rows as $row) {
  if (!is_array($row)) {
    continue;
  }
  if (strtolower((string) ($row["email"] ?? "")) === $email) {
    $existing = $row;
    break;
  }
}

if (is_array($existing)) {
  if ($phone !== "" && (!isset($existing["phone"]) || $existing["phone"] === "")) {
    $existing["phone"] = $phone;
  }
  if ($name !== "") {
    $existing["name"] = $name;
  }
  $existing["via"] = $via;
  $existing["seen"] = gmdate("c");
  foreach ($rows as $i => $row) {
    if (is_array($row) && strtolower((string) ($row["email"] ?? "")) === $email) {
      $rows[$i] = $existing;
      break;
    }
  }
  write_clients($rows);
  echo json_encode(["ok" => true, "created" => false, "client" => $existing]);
  exit;
}

$client = [
  "id" => bin2hex(random_bytes(8)),
  "name" => $name,
  "email" => $email,
  "phone" => $phone,
  "via" => $via,
  "created" => gmdate("c")
];
$rows[] = $client;
write_clients($rows);
notify_matthew($client);

echo json_encode(["ok" => true, "created" => true, "client" => $client]);

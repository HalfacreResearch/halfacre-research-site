<?php
/**
 * Create a free client account and email the private page link.
 * Existing emails never get a link or client record in the HTTP response.
 */
declare(strict_types=1);

require_once __DIR__ . "/client-token.php";

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

const STORE = __DIR__ . "/clients.store.json";
const MATTHEW = "matt@halfacreresearch.tech";
const SIGNUP_ACK = "If this email already has an account, we just sent its private link there.";
const RATE_MAX = 5;
const RATE_WINDOW = 3600;

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

function signup_mail_headers(): string
{
  return "From: Halfacre Research <noreply@halfacreresearch.tech>\r\n"
    . "Reply-To: " . MATTHEW . "\r\n"
    . "Content-Type: text/plain; charset=utf-8\r\n";
}

function signup_send_mail(string $to, string $subject, string $body): bool
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
  return @mail($to, $subject, $body, signup_mail_headers());
}

function mail_private_link(string $to, string $id, string $token): void
{
  $page = "https://www.halfacreresearch.tech" . client_token_page($id, $token);
  $body = "Open your private Halfacre Research page. Do not share this link.\n\n"
    . $page . "\n";
  signup_send_mail($to, "Your Halfacre Research page", $body);
}

function notify_matthew(array $client): void
{
  $name = (string) ($client["name"] ?? "");
  $email = (string) ($client["email"] ?? "");
  $phone = (string) ($client["phone"] ?? "");
  $created = (string) ($client["created"] ?? "");
  $page = (string) ($client["page"] ?? "");
  $subject = "New Halfacre account: " . $name;
  $body = "A free client account was created.\n\n"
    . "Name: {$name}\n"
    . "Email: {$email}\n"
    . "Phone: {$phone}\n"
    . "Created: {$created}\n\n"
    . "Their page: https://www.halfacreresearch.tech{$page}\n";
  signup_send_mail(MATTHEW, $subject, $body);
}

function signup_ack(): void
{
  echo json_encode([
    "ok" => true,
    "created" => false,
    "message" => SIGNUP_ACK
  ]);
}

function signup_rate_path(): string
{
  $dir = client_token_private_dir();
  if ($dir !== "") {
    return $dir . "/signup-rate.store.json";
  }
  return __DIR__ . "/signup-rate.store.json";
}

function signup_rate_max(): int
{
  $raw = getenv("HALFACRE_SIGNUP_RATE_MAX");
  if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
    return (int) $raw;
  }
  return RATE_MAX;
}

function signup_client_ip(): string
{
  $ip = trim((string) ($_SERVER["REMOTE_ADDR"] ?? ""));
  return $ip !== "" ? $ip : "unknown";
}

function signup_rate_allow(string $bucket): bool
{
  $path = signup_rate_path();
  $now = time();
  $cut = $now - RATE_WINDOW;
  $max = signup_rate_max();
  $all = [];
  $fp = fopen($path, "c+");
  if ($fp === false) {
    return true;
  }
  if (!flock($fp, LOCK_EX)) {
    fclose($fp);
    return true;
  }
  $raw = stream_get_contents($fp);
  $data = json_decode(is_string($raw) ? $raw : "", true);
  $all = is_array($data) ? $data : [];
  $hits = [];
  if (isset($all[$bucket]) && is_array($all[$bucket])) {
    foreach ($all[$bucket] as $ts) {
      $n = (int) $ts;
      if ($n >= $cut) {
        $hits[] = $n;
      }
    }
  }
  if (count($hits) >= $max) {
    flock($fp, LOCK_UN);
    fclose($fp);
    return false;
  }
  $hits[] = $now;
  $all[$bucket] = $hits;
  foreach ($all as $key => $rows) {
    if (!is_array($rows)) {
      unset($all[$key]);
      continue;
    }
    $keep = [];
    foreach ($rows as $ts) {
      if ((int) $ts >= $cut) {
        $keep[] = (int) $ts;
      }
    }
    if ($keep === []) {
      unset($all[$key]);
    } else {
      $all[$key] = $keep;
    }
  }
  $json = json_encode($all);
  ftruncate($fp, 0);
  rewind($fp);
  fwrite($fp, ($json === false ? "{}" : $json) . "\n");
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
  @chmod($path, 0600);
  return true;
}

function next_number(array $rows): int
{
  $next = 2;
  foreach ($rows as $row) {
    if (!is_array($row)) {
      continue;
    }
    $n = isset($row["number"]) ? (int) $row["number"] : 0;
    if ($n >= $next) {
      $next = $n + 1;
    }
  }
  return $next;
}

$raw = file_get_contents("php://input");
$payload = json_decode(is_string($raw) ? $raw : "", true);
if (!is_array($payload)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad JSON"]);
  exit;
}

if (!signup_rate_allow("ip:" . signup_client_ip())) {
  http_response_code(429);
  echo json_encode(["ok" => false, "error" => "Try again later."]);
  exit;
}

$name = clean_text((string) ($payload["name"] ?? ""), 120);
$email = strtolower(clean_text((string) ($payload["email"] ?? ""), 160));
$phone = clean_text((string) ($payload["phone"] ?? ""), 40);
$adult = !empty($payload["adult_confirmed"]);

if ($name === "" || $email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Name, email, and phone are required."]);
  exit;
}

if (!$adult) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "You must confirm you are 18 or older."]);
  exit;
}

if (!signup_rate_allow("email:" . $email)) {
  http_response_code(429);
  echo json_encode(["ok" => false, "error" => "Try again later."]);
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
  $id = client_token_id((string) ($existing["id"] ?? ""));
  $storedEmail = strtolower(trim((string) ($existing["email"] ?? "")));
  if (strlen($id) >= 8 && $storedEmail !== "" && filter_var($storedEmail, FILTER_VALIDATE_EMAIL)) {
    $token = client_token_issue($id);
    mail_private_link($storedEmail, $id, $token);
  }
  signup_ack();
  exit;
}

$id = bin2hex(random_bytes(8));
$client = [
  "id" => $id,
  "number" => next_number($rows),
  "name" => $name,
  "email" => $email,
  "phone" => $phone,
  "adult_confirmed" => true,
  "created" => gmdate("c")
];
$rows[] = $client;
write_clients($rows);
require_once __DIR__ . "/client-memory.php";
client_memory_merge($id, ["adult_confirmed" => true]);
$token = client_token_issue($id);
$handed = $client;
$handed["page"] = client_token_page($id, $token);
mail_private_link($email, $id, $token);
notify_matthew($handed);
signup_ack();

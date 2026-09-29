<?php
/**
 * Create a free client account and email Matthew.
 * Name, email, and phone only.
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

function notify_matthew(array $client): void
{
  $name = $client["name"];
  $email = $client["email"];
  $phone = $client["phone"];
  $subject = "New Halfacre account: " . $name;
  $body = "A free client account was created.\n\n"
    . "Name: {$name}\n"
    . "Email: {$email}\n"
    . "Phone: {$phone}\n"
    . "Created: {$client["created"]}\n\n"
    . "Their page: https://www.halfacreresearch.tech/page.html?c=" . $client["id"] . "\n";
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

$name = clean_text((string) ($payload["name"] ?? ""), 120);
$email = strtolower(clean_text((string) ($payload["email"] ?? ""), 160));
$phone = clean_text((string) ($payload["phone"] ?? ""), 40);

if ($name === "" || $email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === "") {
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

function with_page(array $client): array
{
  $id = (string) ($client["id"] ?? "");
  $client["page"] = $id !== "" ? "/page.html?c=" . $id : "/signup.html";
  return $client;
}

if (is_array($existing)) {
  $existing["name"] = $name;
  $existing["phone"] = $phone;
  $existing["seen"] = gmdate("c");
  if (empty($existing["id"])) {
    $existing["id"] = bin2hex(random_bytes(8));
  }
  if (empty($existing["number"])) {
    $existing["number"] = next_number($rows);
  }
  $existing = with_page($existing);
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

$client = with_page([
  "id" => bin2hex(random_bytes(8)),
  "number" => next_number($rows),
  "name" => $name,
  "email" => $email,
  "phone" => $phone,
  "created" => gmdate("c")
]);
$rows[] = $client;
write_clients($rows);
notify_matthew($client);

echo json_encode(["ok" => true, "created" => true, "client" => $client]);

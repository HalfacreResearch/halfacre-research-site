<?php
/**
 * Hostinger same-origin proxy for on-page Grok (xAI Chat Completions).
 * This is Grok itself — not a Grok Bot / fleet agent / VanCoachBot.
 * Put the key in XAI_API_KEY or van-grok.secret.php (not in git).
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

function grok_key(): string
{
  $env = getenv("XAI_API_KEY");
  if (is_string($env) && trim($env) !== "") {
    return trim($env);
  }
  $secret = __DIR__ . "/van-grok.secret.php";
  if (is_file($secret)) {
    $value = include $secret;
    if (is_string($value) && trim($value) !== "") {
      return trim($value);
    }
  }
  return "";
}

function system_prompt(array $catalog, array $owned, bool $sfox): string
{
  $lines = [];
  foreach ($catalog as $row) {
    if (!is_array($row)) {
      continue;
    }
    $id = isset($row["id"]) ? (string) $row["id"] : "";
    $name = isset($row["name"]) ? (string) $row["name"] : $id;
    $price = isset($row["price"]) ? (string) $row["price"] : "1.99";
    $state = in_array($id, $owned, true) ? "unlocked" : "locked";
    $lines[] = "- {$name} ({$id}) \${$price} {$state} pay.html?id={$id}";
  }
  $shop = $lines ? implode("\n", $lines) : "- (catalog loading)";
  $sfoxLine = $sfox ? "sFOX shows connected on this device." : "sFOX is not connected. Keys go in the page box, never chat.";
  return <<<TXT
You are Grok (xAI), embedded full-time on Charlie Van Halfacre's Halfacre Research page (/van.html).
You are Grok itself. You are not a Grok Bot, not a fleet agent, and not VanCoachBot.

Client: Charlie Van Halfacre. Phone (601) 408-8342. Email cvhalfacre@msn.com.
Speak warm and plain. No Soft HOLD jargon. No DataBazaar or Hermes. No Stripe.

Job:
1) Maximize this client's net worth in ordinary words.
2) Flow him through Halfacre products and the PayPal buy path.
3) Extract useful client info (goals, accounts, risk, family) without logins or secrets.
4) Show the value of research/data modules and drive a $1.99 buy when it fits.
5) Keep sFOX connect in the dedicated box so Codex Buy / Codex Sell can autotrade later. Do not execute trades.

Catalog is OPEN-ENDED. Matthew will add more modules. Do not say the shop is only seven.
Starter billable set (each \$1.99, locked until that PayPal payment; paying one unlocks only that SKU):
{$shop}

{$sfoxLine}

Rules:
- Never ask for or accept passwords, API keys, seed phrases, or PINs in chat.
- Never invent a Stripe checkout. PayPal only. Square is coming next.
- Never bundle Codex Buy and Codex Sell.
- Never pre-unlock or gift a module. Matthew cashes Van back privately off-app.
- Not investment advice. No live bank connect. No live trade from this page.
- When you name a buy, include the pay.html?id=SKU link.
TXT;
}

$raw = file_get_contents("php://input");
$payload = json_decode(is_string($raw) ? $raw : "", true);
if (!is_array($payload)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad JSON"]);
  exit;
}

$incoming = isset($payload["messages"]) && is_array($payload["messages"]) ? $payload["messages"] : [];
$catalog = isset($payload["catalog"]) && is_array($payload["catalog"]) ? $payload["catalog"] : [];
$owned = isset($payload["owned"]) && is_array($payload["owned"]) ? array_values(array_map("strval", $payload["owned"])) : [];
$sfox = !empty($payload["sfox"]);

$clean = [];
foreach ($incoming as $item) {
  if (!is_array($item)) {
    continue;
  }
  $role = isset($item["role"]) ? (string) $item["role"] : "";
  $content = isset($item["content"]) ? trim((string) $item["content"]) : "";
  if ($role !== "user" && $role !== "assistant") {
    continue;
  }
  if ($content === "" || strlen($content) > 4000) {
    continue;
  }
  $clean[] = ["role" => $role, "content" => $content];
  if (count($clean) >= 24) {
    break;
  }
}

$key = grok_key();
if ($key === "") {
  http_response_code(503);
  echo json_encode([
    "ok" => false,
    "error" => "Grok key missing on Hostinger. Set XAI_API_KEY or van-grok.secret.php.",
    "engine" => "grok"
  ]);
  exit;
}

$messages = array_merge(
  [["role" => "system", "content" => system_prompt($catalog, $owned, $sfox)]],
  $clean
);

$body = json_encode([
  "model" => "grok-4.6",
  "messages" => $messages,
  "stream" => false
]);

$res = false;
$code = 0;
$err = "";
if (function_exists("curl_init")) {
  $ch = curl_init("https://api.x.ai/v1/chat/completions");
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      "Content-Type: application/json",
      "Authorization: Bearer " . $key,
      "x-grok-conv-id: halfacre-van-page"
    ],
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 45
  ]);
  $res = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
} else {
  $ctx = stream_context_create([
    "http" => [
      "method" => "POST",
      "header" => "Content-Type: application/json\r\nAuthorization: Bearer " . $key . "\r\nx-grok-conv-id: halfacre-van-page\r\n",
      "content" => $body,
      "timeout" => 45,
      "ignore_errors" => true
    ]
  ]);
  $res = file_get_contents("https://api.x.ai/v1/chat/completions", false, $ctx);
  if (isset($http_response_header[0]) && preg_match("/\\s(\\d{3})\\s/", $http_response_header[0], $m)) {
    $code = (int) $m[1];
  }
}

if (!is_string($res) || $res === "") {
  http_response_code(502);
  echo json_encode(["ok" => false, "error" => $err !== "" ? $err : "Empty xAI response", "engine" => "grok"]);
  exit;
}

$data = json_decode($res, true);
$reply = "";
if (is_array($data) && isset($data["choices"][0]["message"]["content"])) {
  $reply = trim((string) $data["choices"][0]["message"]["content"]);
}

if ($code >= 400 || $reply === "") {
  http_response_code($code >= 400 ? $code : 502);
  echo json_encode(["ok" => false, "error" => "Grok did not return text", "engine" => "grok"]);
  exit;
}

echo json_encode(["ok" => true, "reply" => $reply, "engine" => "grok", "model" => "grok-4.6"]);

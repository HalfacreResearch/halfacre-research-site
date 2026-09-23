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

function catalog_rows($catalog): array
{
  if (isset($catalog["live"]) && is_array($catalog["live"])) {
    return [
      "live" => $catalog["live"],
      "coming" => isset($catalog["coming_soon"]) && is_array($catalog["coming_soon"]) ? $catalog["coming_soon"] : []
    ];
  }
  return ["live" => $catalog, "coming" => []];
}

function format_live_lines(array $rows, array $owned): string
{
  $lines = [];
  foreach ($rows as $row) {
    if (!is_array($row)) {
      continue;
    }
    $id = isset($row["id"]) ? (string) $row["id"] : "";
    $name = isset($row["name"]) ? (string) $row["name"] : $id;
    $price = isset($row["price"]) ? (string) $row["price"] : "4.99";
    $state = in_array($id, $owned, true) ? "unlocked" : "locked";
    $lines[] = "- {$name} ({$id}) \${$price} {$state} pay.html?id={$id}";
  }
  return $lines ? implode("\n", $lines) : "- (no live SKUs)";
}

function format_coming_names(array $rows): string
{
  $names = [];
  foreach ($rows as $row) {
    if (!is_array($row)) {
      continue;
    }
    $name = isset($row["name"]) ? (string) $row["name"] : "";
    if ($name !== "") {
      $names[] = $name;
    }
    if (count($names) >= 80) {
      break;
    }
  }
  $extra = max(0, count($rows) - count($names));
  $list = $names ? implode(", ", $names) : "(coming-soon names loading)";
  if ($extra > 0) {
    $list .= ", plus {$extra} more on van.html";
  }
  return $list;
}

function format_powerups(array $owned, $avatar): string
{
  $names = [];
  if (is_array($avatar) && isset($avatar["powerups"]) && is_array($avatar["powerups"])) {
    foreach ($avatar["powerups"] as $row) {
      if (!is_array($row)) {
        continue;
      }
      $id = isset($row["id"]) ? (string) $row["id"] : "";
      $name = isset($row["name"]) ? (string) $row["name"] : $id;
      $know = isset($row["avatar_knowledge"]) ? (string) $row["avatar_knowledge"] : "";
      if ($name !== "") {
        $names[] = $know !== "" ? "{$name} ({$id}) — {$know}" : "{$name} ({$id})";
      }
    }
  }
  if (!$names) {
    foreach ($owned as $id) {
      $id = trim((string) $id);
      if ($id !== "") {
        $names[] = $id;
      }
    }
  }
  if (!$names) {
    return "None yet. The unlock list for charlie-van-halfacre is empty. Do not pretend he already owns paid modules.";
  }
  return implode("\n", array_map(function ($line) {
    return "- " . $line;
  }, $names));
}

function system_prompt($catalog, array $owned, bool $sfox, $avatar): string
{
  $pack = is_array($catalog) ? catalog_rows($catalog) : ["live" => [], "coming" => []];
  $shop = format_live_lines($pack["live"], $owned);
  $coming = format_coming_names($pack["coming"]);
  $comingCount = count($pack["coming"]);
  $power = format_powerups($owned, $avatar);
  $sfoxLine = $sfox ? "sFOX shows connected on this device." : "sFOX is not connected. Keys go in the page box, never chat.";
  return <<<TXT
You are Grok (xAI), embedded full-time on Charlie Van Halfacre's Halfacre Research page (/van.html).
You are Grok itself. You are not a Grok Bot, not a fleet agent, and not VanCoachBot.

Client: Charlie Van Halfacre. Phone (601) 408-8342. Email cvhalfacre@msn.com.
Account userId: charlie-van-halfacre (hivemind client + PayPal identity cvhalfacre@msn.com).
Speak warm and plain. No Soft HOLD jargon. No DataBazaar or Hermes. No Stripe.

AVATAR POWER-UPS (load these BEFORE you talk — unlock list is source of truth for what he knows):
{$power}

Job:
1) Maximize this client's net worth in ordinary words.
2) Flow him through Halfacre products and the PayPal buy path for LIVE SKUs only.
3) Extract useful client info (goals, accounts, risk, family) without logins or secrets.
4) Show the value of live research/data modules (\$4.99): he can PAY, DOWNLOAD, and POWER UP this avatar. Theme packs \$29.99. Assembled bots \$149 (including TaxAttorneyBot).
5) Keep sFOX connect on the FREE Codex founder surface. sFOX API connect is the unlock. Execution SoT is autotrades-engine server/sfoxEngine.ts (dcaEngine, rotationEngine). Live operator path is admin tRPC on autotrades.codexyield.com. Do not invent a fake trading engine. Do not claim live autotrade is firing until keys + that scheduler path are wired. BTCTreasuryBot is the $149 assembled bot on that same path.

TWO LAYERS. Do not say the shop is only seven SKUs.
1) FULL LIST — everything we intend to sell is visible on van.html. Coming soon / Not ready means data or fulfillment is not built yet. That is not teaser fluff.
2) CLICKABLE LIVE — research modules that already exist in Halfacre SoT are buyable now (pay + download + lasting avatar unlock). Codex itself is FREE on van.html#codex. Only BTCTreasuryBot is live among the 18 assembled bots (\$149). Do not send pay.html links for Coming soon / Not ready items.

LIVE (buyable — pay, download, power up):
{$shop}

COMING SOON / NOT READY ({$comingCount} names, visible, not clickable): {$coming}

Codex is free (price 0). Founder surface on van.html#codex. sFOX API connect is the unlock. SoT files: autotrades-engine server/sfoxEngine.ts, dcaEngine, rotationEngine. Operator: admin tRPC on autotrades.codexyield.com. Do not claim live autotrade is already firing. BTCTreasuryBot is the only live assembled bot (\$149). The other 17 names are Coming soon until built. Do not invent extra bot names. A research unlock is a lasting account entitlement plus a downloadable series — not candles-only.

{$sfoxLine}

Rules:
- Treat the unlock list as what this avatar already knows. Use those modules in answers.
- Never ask for or accept passwords, API keys, seed phrases, or PINs in chat.
- Never invent a Stripe checkout. PayPal only. Square is coming next.
- Never invent extra bot names beyond the 18 on the page. Never sell a Coming soon / Not ready item.
- Never pre-unlock or gift a module. Matthew cashes Van back privately off-app.
- Never send a pay.html link for a Coming soon / Not ready name.
- Not investment advice. No live bank connect. No live trade from this page.
- When you name a LIVE buy, include the pay.html?id=SKU link.
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
$avatar = isset($payload["avatar"]) && is_array($payload["avatar"]) ? $payload["avatar"] : [];
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
  [["role" => "system", "content" => system_prompt($catalog, $owned, $sfox, $avatar)]],
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

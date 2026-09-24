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

function format_powerups(array $owned, $avatar, string $userId): string
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
    return "None yet. The unlock list for {$userId} is empty. Do not pretend this client already owns paid modules.";
  }
  return implode("\n", array_map(function ($line) {
    return "- " . $line;
  }, $names));
}

function first_name(string $full): string
{
  $parts = preg_split("/\s+/", trim($full)) ?: [];
  $first = isset($parts[0]) ? (string) $parts[0] : "there";
  if ($first === "") {
    return "there";
  }
  if (strcasecmp($first, "Charley") === 0) {
    return "Van";
  }
  return $first;
}

function system_prompt($catalog, array $owned, bool $sfox, $avatar, string $clientName, string $userId): string
{
  $pack = is_array($catalog) ? catalog_rows($catalog) : ["live" => [], "coming" => []];
  $shop = format_live_lines($pack["live"], $owned);
  $coming = format_coming_names($pack["coming"]);
  $comingCount = count($pack["coming"]);
  $power = format_powerups($owned, $avatar, $userId);
  $sfoxLine = $sfox ? "sFOX shows connected on this device." : "sFOX is not connected. Keys go in the page box, never chat.";
  $first = first_name($clientName);
  $isVan = ($userId === "charley-van-halfacre" || strcasecmp($clientName, "Charley Van Halfacre") === 0);
  $pageLine = $isVan
    ? "You are on Charley Van Halfacre's page (/van.html). You may call him Van."
    : "You are on {$clientName}'s own page (/page.html). This is not Dad's page. Call them {$first}.";
  $identity = $isVan
    ? "Client: Charley Van Halfacre. Phone (601) 408-8342. Email cvhalfacre@msn.com.\nAccount userId: charley-van-halfacre."
    : "Client: {$clientName}. Account userId: {$userId}. Do not use Charley's phone, email, or identity on this page.";
  return <<<TXT
You are Grok (xAI), embedded full-time on this client's Halfacre Research page.
You are Grok itself — the coach on this page. You are not Dad. You are not a Grok Bot, not a fleet agent, and not VanCoachBot.

{$pageLine}
{$identity}
Speak warm and plain. You are Grok, the coach. Do not call yourself Dad. No Soft HOLD jargon. No DataBazaar or Hermes. No Stripe.

AVATAR POWER-UPS (load these BEFORE you talk — unlock list is source of truth for what he knows):
{$power}

Job (approved Q1–Q15, 2026-09-23):
1) Dual job every turn: gather every financial document for a real net worth and retirement plan, AND point to live modules / mid packs / top algos as natural next steps. Never hard close.
2) Voice: warm plain-English Grok coach. Client is {$clientName}. You are not Dad.
3) First message and every return visit: two poles (zero NW vs Elon-level / trillionaire best-retirement structure) + invite the next upload. Do not open as a shop clerk.
4) After each upload: deep plan read → update position on the poles → name only LIVE clickable catalog items for the next moves → give as much value as possible → then one next doc or one live item page.
5) Macro frame: bold on the trillionaire vision; never guarantee returns. Long-term BTC, tech, S&P 500, gold/silver/metals, oil/commodities, Mag-10 outperform USD by design (money printing), not by accident. Sitting in USD is the risk in that frame. Still research/data only; client decides every move.
6) Top products are automated algorithmic trading systems. After purchase: platform → five pairs if exchange → existing account? → API into Vault / page box, never chat. Then back to docs and value. You are not a manual first-trade coach. Do not claim live autotrade is firing until keys + the existing autotrades-engine scheduler path are wired.
7) Broke / thin picture → lean smallest live research modules the catalog actually shows. Funded picture → lean live arranged bots / Codex connect. Always: most complete retirement portfolio on earth.
8) If they stall or say no: stay warm, ask what blocked them, never shame, offer another doc path or a smaller live product. Always more uploads + more value. Memory persists. Optimization never stops.

TWO LAYERS. Do not say the shop is only seven SKUs.
1) FULL LIST — everything we intend to sell is visible on van.html. Coming soon / Not ready means data or fulfillment is not built yet. That is not teaser fluff.
2) CLICKABLE LIVE — research modules that already exist in Halfacre SoT are buyable now (pay + download + lasting avatar unlock). Codex itself is FREE on van.html#codex. Only BTCTreasuryBot is live among the 18 assembled bots (\$149). Do not send pay.html links for Coming soon / Not ready items.

LIVE (buyable — pay, download, power up):
{$shop}

COMING SOON / NOT READY ({$comingCount} names, visible, not clickable): {$coming}

Codex is free (price 0). Founder surface on van.html#codex. sFOX API connect is the unlock. SoT files: autotrades-engine server/sfoxEngine.ts, dcaEngine, rotationEngine. Operator: admin tRPC on autotrades.codexyield.com. Do not claim live autotrade is already firing. BTCTreasuryBot is the only live assembled bot (\$149). TaxAttorneyBot, if/when live, is also \$149 — no \$199 tax tier. The other named bots are Coming soon until built. Do not invent extra bot names. A research unlock is a lasting account entitlement plus a downloadable series — not candles-only.

{$sfoxLine}

Rules:
- Treat the unlock list as what this avatar already knows. Use those modules in answers.
- Never ask for or accept passwords, API keys, seed phrases, or PINs in chat.
- Never invent a Stripe checkout. PayPal only. Square is coming next.
- Never invent extra bot names beyond the 18 on the page. Never sell a Coming soon / Not ready item.
- Never pre-unlock or gift a module. Matthew cashes Van back privately off-app.
- Never send a pay.html link for a Coming soon / Not ready name.
- Never pose as licensed attorney, CPA, or financial advisor. Research and data only. Client is 100% in charge.
- Never SpeakToUser Matthew from this page. Staff/ops issues stay off this chat.
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

$clientName = trim(preg_replace("/[\\r\\n\\t]+/", " ", (string) ($payload["client"] ?? "")) ?? "");
if (strlen($clientName) > 120) {
  $clientName = substr($clientName, 0, 120);
}
if ($clientName === "") {
  $clientName = "Charley Van Halfacre";
}
$userId = trim(preg_replace("/[^A-Za-z0-9_\\- ]/", "", (string) ($payload["userId"] ?? "")) ?? "");
if (strlen($userId) > 80) {
  $userId = substr($userId, 0, 80);
}
if ($userId === "") {
  $userId = "charley-van-halfacre";
}

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
  [["role" => "system", "content" => system_prompt($catalog, $owned, $sfox, $avatar, $clientName, $userId)]],
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
      "x-grok-conv-id: halfacre-page-" . preg_replace("/[^A-Za-z0-9_\\-]/", "", $userId)
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

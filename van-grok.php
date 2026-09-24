<?php
/**
 * Hostinger same-origin proxy for on-page Grok (xAI Chat Completions).
 * Put the key in XAI_API_KEY or van-grok.secret.php (not in git).
 */
declare(strict_types=1);

@set_time_limit(90);
@ignore_user_abort(true);

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
  return ["live" => is_array($catalog) ? $catalog : [], "coming" => []];
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
    return "None yet for {$userId}.";
  }
  return implode("\n", array_map(function ($line) {
    return "- " . $line;
  }, $names));
}

function format_uploads(array $uploads): string
{
  if (!$uploads) {
    return "None yet.";
  }
  $lines = [];
  foreach ($uploads as $row) {
    if (!is_array($row)) {
      continue;
    }
    $name = isset($row["name"]) ? (string) $row["name"] : "";
    $kind = isset($row["kind"]) ? (string) $row["kind"] : "file";
    if ($name !== "") {
      $lines[] = "- {$name} ({$kind})";
    }
  }
  return $lines ? implode("\n", $lines) : "None yet.";
}

function upload_kinds(array $uploads): array
{
  $counts = [];
  foreach ($uploads as $row) {
    if (!is_array($row)) {
      continue;
    }
    $kind = strtolower(trim((string) ($row["kind"] ?? "file")));
    if ($kind === "") {
      $kind = "file";
    }
    $counts[$kind] = ($counts[$kind] ?? 0) + 1;
  }
  return $counts;
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

function welcome_prompt(string $clientName, string $userId, array $uploads, array $owned, $avatar, string $sfoxHoldings): string
{
  $first = first_name($clientName);
  $files = format_uploads($uploads);
  $power = format_powerups($owned, $avatar, $userId);
  return <<<TXT
You are Grok, the coach on {$clientName}'s Halfacre Research page. Call them {$first}.
There is no script. Listen. You decide the next sentence.

Welcome them. Your goal is to use portfolio balancing to guide them toward a very diversified portfolio over time.
The live mix is eight equal sleeves of 12.5% each: BTC, crypto, tech stocks, dividend stocks, precious metals, commodities, mutual funds/ETFs, and real estate.
You may name those eight so they can pick the first sector they care about.

If they ask what they can buy, or how to power up the avatar, send them to the three shop sections in the sidebar: Research Modules ($4.99), Research Packs ($29.99), and Trading Bots ($149). Do not paste the catalog into chat. Name one next item if one fits. The lists and product pages are on this site.

First job: a capital account attached. Second job: a monthly retirement contribution. Without those, long-term help does not work.
Coach them toward uploading documents. Every file must be saved, then tell them what that file just unlocked. Simple talk. No preaching. No internals. No comparisons.

Already uploaded:
{$files}

sFOX holdings (live account data, never the key):
{$sfoxHoldings}

Avatar upgrades they already bought:
{$power}

Do not speak as if the picture is complete if files are missing. Ask what they can share first.
TXT;
}

function second_training(string $clientName): string
{
  return <<<TXT
SECOND TRAINING SET (approved). Load this only because tax returns for the last 2 years, banks, and exchanges are in. Merge with the first set. Do not replace it.

Tell them how the new tax papers change their overall position. Then use the decision matrix plus the tax loopholes bot, tax advantages bot, and tax attorney bot to see whether moves like offshore accounts, charitable structures, or other sophisticated steps can cut what they owe. A cut means more surplus, more into the eight-sleeve mix, more return over time.

Taxes: read what they filed. Say the cuts you see. Less tax is more money to invest. Speak like a coach. Do not hide behind “not advice.”

Retirement: describe Halfacre’s current retirement path. Explain self-directed 401k and self-directed IRA, and how those can help their tax structure. Walk slowly. The next ask is a tax module, a tax data pack, or a tax bot — not a dump. The 401k bot and IRA bot come later.

M&A: only if they own a business or might sell one.
Venture capital: only if they already invest in or run startups.
Trusts: most people should understand whether they need one. Help them see if they do. Do not scare.
Wills: ask once you can see they have assets to pass on. Do not draft. Do not scare.

This unlock is a chance to move them into these areas. Sell by walking, one next thing that fits. Never list all five. Never list a catalog of products.
Never scare (audit, death, “you’re behind”).
Client: {$clientName}.
TXT;
}

function system_prompt($catalog, array $owned, bool $sfox, $avatar, string $clientName, string $userId, array $uploads, bool $open, string $sfoxHoldings): string
{
  if ($open) {
    return welcome_prompt($clientName, $userId, $uploads, $owned, $avatar, $sfoxHoldings);
  }

  $first = first_name($clientName);
  $power = format_powerups($owned, $avatar, $userId);
  $files = format_uploads($uploads);
  $kinds = upload_kinds($uploads);
  $taxReady = ($kinds["tax"] ?? 0) >= 2;
  $bankReady = ($kinds["bank"] ?? 0) >= 1;
  $exReady = ($kinds["exchange"] ?? 0) >= 1;
  $second = ($taxReady && $bankReady && $exReady) ? second_training($clientName) : "Second training set is closed. Do not open taxes, retirement planning, M&A, venture capital, trusts, or wills as live sectors until the last 2 years of tax returns, banks, and exchanges are saved.";

  $pack = is_array($catalog) ? catalog_rows($catalog) : ["live" => [], "coming" => []];
  $liveNames = [];
  foreach ($pack["live"] as $row) {
    if (is_array($row) && !empty($row["name"])) {
      $liveNames[] = (string) $row["name"];
    }
  }
  $liveLine = $liveNames ? implode(", ", array_slice($liveNames, 0, 12)) : "(none live)";

  return <<<TXT
You are Grok, the coach on {$clientName}'s Halfacre Research page. Call them {$first}.
There is no script. Listen. You decide the next sentence. Simple talk. No preaching. No internals.

Do not speak without this pack. If something is missing, do not guess.

Uploaded:
{$files}

sFOX holdings (live account data, never the key):
{$sfoxHoldings}

Avatar upgrades they bought:
{$power}

Perfect portfolio (now): eight sleeves at 12.5% each — BTC, crypto, tech stocks, dividend stocks, precious metals, commodities, mutual funds/ETFs, real estate.
Sectors 9–14 stay closed until the last 2 years of tax returns, banks, and exchanges are in: taxes, retirement planning, M&A, venture capital, trusts, last will.

Jobs in order:
1) Attach a capital account.
2) Get monthly retirement contributions coming in.
3) Each month’s new money fills the gap toward the eight-sleeve mix, using the first sector they picked.

Every upload: make sure it is saved, then tell them what value that just unlocked. Each file makes the picture clearer and lets you offer the next fitting step — one thing, not a list.
If they confirm they have uploaded everything, you should already have been walking them toward real next steps.

sFOX connected: {$sfox}. Keys never go in chat.
Live items you may name when one fits (do not dump): {$liveLine}
The shop is on this page: Research Modules, Research Packs, Trading Bots in the sidebar. If they ask for the list, send them there. Do not paste the catalog into chat.

{$second}

Rules:
- Never ask for passwords, API keys, seed phrases, or PINs.
- Never invent checkout. PayPal only when they are ready for one item.
- Never dump sectors or products. Never scare.
- You are not a lawyer or CPA. Still speak plainly like a coach.
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
$uploads = isset($payload["uploads"]) && is_array($payload["uploads"]) ? $payload["uploads"] : [];
$sfox = !empty($payload["sfox"]);
$open = !empty($payload["open"]);
$sfoxHoldings = trim((string) ($payload["sfoxHoldings"] ?? ""));
$sfoxHoldings = preg_replace("/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]+/", "", $sfoxHoldings) ?? "";
if (strlen($sfoxHoldings) > 2000) {
  $sfoxHoldings = substr($sfoxHoldings, 0, 2000);
}
if ($sfoxHoldings === "") {
  $sfoxHoldings = $sfox ? "sFOX is connected." : "sFOX is not connected.";
}

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

$cleanUploads = [];
foreach ($uploads as $row) {
  if (!is_array($row)) {
    continue;
  }
  $name = trim((string) ($row["name"] ?? ""));
  $kind = strtolower(trim((string) ($row["kind"] ?? "file")));
  if ($name === "") {
    continue;
  }
  if (strlen($name) > 180) {
    $name = substr($name, 0, 180);
  }
  $kind = preg_replace("/[^a-z]/", "", $kind) ?? "file";
  $cleanUploads[] = ["name" => $name, "kind" => $kind !== "" ? $kind : "file"];
  if (count($cleanUploads) >= 80) {
    break;
  }
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
  [["role" => "system", "content" => system_prompt($catalog, $owned, $sfox, $avatar, $clientName, $userId, $cleanUploads, $open, $sfoxHoldings)]],
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
      "header" => "Content-Type: application/json\r\nAuthorization: Bearer " . $key . "\r\nx-grok-conv-id: halfacre-page\r\n",
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

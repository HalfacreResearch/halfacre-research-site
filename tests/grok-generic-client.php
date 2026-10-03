<?php
/**
 * Generic client prompts + swappable Grok nudges.
 * Does not boot client-grok.php / van-grok.php (those are HTTP handlers).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . "/van-grok-prompts.php";
require_once $root . "/client-token.php";

$pass = 0;
$fail = 0;

function note(string $line): void
{
  echo $line . "\n";
}

function check(string $name, bool $ok, string $detail = ""): void
{
  global $pass, $fail;
  if ($ok) {
    $pass++;
    note("PASS  " . $name);
    return;
  }
  $fail++;
  note("FAIL  " . $name);
  if ($detail !== "") {
    note("      " . $detail);
  }
}

$work = getenv("HALFACRE_GENERIC_WORKDIR");
if (is_string($work) && $work !== "") {
  putenv("HALFACRE_CLIENTS_STORE=" . $work . "/clients.store.json");
  putenv("HALFACRE_TOKEN_ROOT=" . $work . "/private");
}

$genericId = "abcdef1234567890";
$genericName = "Jordan Lee";
$genericEmail = "jordan-lee@example.invalid";

$genericPrompt = system_prompt(
  ["live" => [["name" => "Sample Research Module"]], "coming_soon" => []],
  [],
  false,
  ["powerups" => []],
  $genericName,
  $genericId,
  [["name" => "w-2.pdf", "kind" => "tax"]],
  false,
  "sFOX is not connected.",
  [
    "zdr" => true,
    "open" => false,
    "messages" => [["role" => "user", "content" => "Hello."]],
    "upload_enabled" => false,
    "powerup_enabled" => false
  ]
);

$vanNeedles = [
  "Charley",
  "charley",
  "Van Halfacre",
  "charley-van-halfacre",
  "charlie-van-halfacre",
  "Charley Van",
];
$hitVan = [];
foreach ($vanNeedles as $needle) {
  if (stripos($genericPrompt, $needle) !== false) {
    $hitVan[] = $needle;
  }
}
check("generic client prompt has no Van/Charley strings", $hitVan === [], implode(", ", $hitVan));
check("generic client prompt has no display name", strpos($genericPrompt, $genericName) === false);
check("generic client prompt has no email", strpos($genericPrompt, $genericEmail) === false);
check("generic client prompt has no @", preg_match("/@/", $genericPrompt) !== 1);
check("generic client prompt is non-empty", trim($genericPrompt) !== "");

$uploadOn = grok_nudge_block([
  "zdr" => true,
  "open" => true,
  "force" => "upload",
  "upload_enabled" => true,
  "powerup_enabled" => false
]);
$uploadOffFlag = grok_nudge_block([
  "zdr" => true,
  "open" => true,
  "force" => "upload",
  "upload_enabled" => false,
  "powerup_enabled" => false
]);
$uploadOffZdr = grok_nudge_block([
  "zdr" => false,
  "open" => true,
  "force" => "upload",
  "upload_enabled" => true,
  "powerup_enabled" => false
]);
$powerOn = grok_nudge_block([
  "zdr" => true,
  "open" => false,
  "force" => "powerup",
  "upload_enabled" => false,
  "powerup_enabled" => true,
  "messages" => [["role" => "user", "content" => "What can I add?"]]
]);
$powerOff = grok_nudge_block([
  "zdr" => true,
  "open" => false,
  "force" => "powerup",
  "upload_enabled" => false,
  "powerup_enabled" => false
]);
$distress = grok_nudge_block([
  "zdr" => true,
  "open" => false,
  "upload_enabled" => true,
  "powerup_enabled" => true,
  "messages" => [["role" => "user", "content" => "I lost everything this week and I am in distress."]]
]);

$uploadCopy = grok_nudge_upload_copy();
$powerCopy = grok_nudge_powerup_copy();

check("upload copy is marked PENDING ATTORNEYBOT WORDING", strpos($uploadCopy, "PENDING ATTORNEYBOT WORDING") === 0);
check("powerup copy is marked PENDING ATTORNEYBOT WORDING", strpos($powerCopy, "PENDING ATTORNEYBOT WORDING") === 0);
check("upload nudge appears when enabled and ZDR true", strpos($uploadOn, $uploadCopy) !== false);
check("upload nudge omitted when disabled", $uploadOffFlag === "" || strpos($uploadOffFlag, $uploadCopy) === false);
check("upload nudge suppressed when ZDR is false", $uploadOffZdr === "" || strpos($uploadOffZdr, $uploadCopy) === false);
check("powerup nudge appears when enabled", strpos($powerOn, $powerCopy) !== false);
check("powerup nudge omitted when disabled", $powerOff === "" || strpos($powerOff, $powerCopy) === false);
check("no nudge when last user text is loss/distress", $distress === "");

$both = grok_nudge_block([
  "zdr" => true,
  "open" => true,
  "upload_enabled" => true,
  "powerup_enabled" => true
]);
$hasUpload = strpos($both, $uploadCopy) !== false;
$hasPower = strpos($both, $powerCopy) !== false;
check("open turn includes at most one nudge kind", !($hasUpload && $hasPower));

$catalog = grok_nudge_live_products();
check("live purchasable catalog is non-empty", $catalog !== []);
$catalogJson = json_decode((string) file_get_contents($root . "/van-products.json"), true);
$modules = (is_array($catalogJson) && isset($catalogJson["modules"]) && is_array($catalogJson["modules"]))
  ? $catalogJson["modules"]
  : [];
$byId = [];
foreach ($modules as $row) {
  if (is_array($row) && !empty($row["id"])) {
    $byId[(string) $row["id"]] = $row;
  }
}

foreach ($catalog as $item) {
  $id = $item["id"];
  $src = $byId[$id] ?? null;
  check("catalog item {$id} exists in van-products.json", is_array($src));
  if (!is_array($src)) {
    continue;
  }
  check("catalog item {$id} is live", !empty($src["live"]));
  check("catalog item {$id} is not free", empty($src["free"]));
  check("catalog name {$id} matches van-products.json", $item["name"] === (string) $src["name"]);
  $wantPrice = grok_nudge_price_label($src["price_usd"] ?? null);
  check("catalog price {$id} matches van-products.json", $item["price_label"] === $wantPrice);
  check("powerup prompt names {$id}", strpos($powerOn, $item["name"]) !== false);
  check("powerup prompt prices {$id}", strpos($powerOn, $item["price_label"]) !== false);
  check("powerup prompt links {$id}", strpos($powerOn, $item["page"]) !== false);
}

$nudgeSources = $uploadCopy . "\n" . $powerCopy . "\n" . $uploadOn . "\n" . $powerOn;
foreach (grok_nudge_banned_patterns() as $banName => $pattern) {
  $hit = preg_match($pattern, $nudgeSources) === 1;
  check("nudge copy has no {$banName}", !$hit, $hit ? "matched banned phrase in nudge copy" : "");
}

$welcomeGeneric = welcome_prompt(
  $genericName,
  $genericId,
  [],
  [],
  ["powerups" => []],
  "sFOX is not connected.",
  ["zdr" => true, "open" => true, "upload_enabled" => true, "powerup_enabled" => false]
);
check("welcome for generic client has no display name", strpos($welcomeGeneric, $genericName) === false);
check("welcome for generic client has no Van/Charley", stripos($welcomeGeneric, "Charley") === false && stripos($welcomeGeneric, "Van Halfacre") === false);
check("welcome includes upload nudge when ZDR true", strpos($welcomeGeneric, $uploadCopy) !== false);

$welcomeZdrOff = welcome_prompt(
  $genericName,
  $genericId,
  [],
  [],
  ["powerups" => []],
  "sFOX is not connected.",
  ["zdr" => false, "open" => true, "upload_enabled" => true, "powerup_enabled" => false]
);
check("welcome omits upload nudge when ZDR false", strpos($welcomeZdrOff, $uploadCopy) === false);

if (function_exists("client_record_for") && is_string($work) && $work !== "") {
  $row = client_record_for($genericId);
  check("client record id comes from store", is_array($row) && (string) ($row["id"] ?? "") === $genericId);
  check("client record name comes from store", is_array($row) && (string) ($row["name"] ?? "") === $genericName);
}

note("");
note("Results: {$pass} passed, {$fail} failed");
if ($fail > 0) {
  exit(1);
}

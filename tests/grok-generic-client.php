<?php
/**
 * Generic client prompts + AttorneyBot Grok nudges.
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
    "powerup_enabled" => false,
    "memory" => ["adult_confirmed" => true]
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
check("generic client prompt has no full display name", strpos($genericPrompt, $genericName) === false);
check("generic client prompt has no email", strpos($genericPrompt, $genericEmail) === false);
check("generic client prompt has no @", preg_match("/@/", $genericPrompt) !== 1);
check("generic client prompt is non-empty", trim($genericPrompt) !== "");
check("generic prompt uses first name when ZDR on", strpos($genericPrompt, "Jordan") !== false);
check("generic prompt has no sfoxHoldings", strpos($genericPrompt, "sFOX is not connected.") === false);

$uploadOn = grok_nudge_block([
  "zdr" => true,
  "open" => true,
  "force" => "upload",
  "upload_enabled" => true,
  "powerup_enabled" => false,
  "memory" => ["adult_confirmed" => true]
]);
$uploadOffFlag = grok_nudge_block([
  "zdr" => true,
  "open" => true,
  "force" => "upload",
  "upload_enabled" => false,
  "powerup_enabled" => false,
  "memory" => ["adult_confirmed" => true]
]);
$uploadOffZdr = grok_nudge_block([
  "zdr" => false,
  "open" => true,
  "force" => "upload",
  "upload_enabled" => true,
  "powerup_enabled" => false,
  "memory" => ["adult_confirmed" => true]
]);
$powerOn = grok_nudge_block([
  "zdr" => true,
  "open" => false,
  "force" => "powerup",
  "upload_enabled" => false,
  "powerup_enabled" => true,
  "messages" => [["role" => "user", "content" => "What can I add?"]],
  "memory" => ["adult_confirmed" => true]
]);
$powerOff = grok_nudge_block([
  "zdr" => true,
  "open" => false,
  "force" => "powerup",
  "upload_enabled" => false,
  "powerup_enabled" => false,
  "messages" => [["role" => "user", "content" => "What can I add?"]],
  "memory" => ["adult_confirmed" => true]
]);
$distress = grok_nudge_compute([
  "zdr_on" => true,
  "open" => false,
  "messages" => [["role" => "user", "content" => "I lost everything this week and I am in distress."]],
  "memory" => ["adult_confirmed" => true, "welcome_done" => true, "last_message_at" => time() - 60],
  "now" => time()
]);

check("nudge state names upload flag when forced", strpos($uploadOn, "upload_reminder_allowed_this_turn: yes") !== false);
check("upload flag omitted when disabled", strpos($uploadOffFlag, "upload_reminder_allowed_this_turn: no") !== false);
check("upload flag no when ZDR is false", strpos($uploadOffZdr, "upload_reminder_allowed_this_turn: no") !== false);
check("product flag can be forced on", strpos($powerOn, "product_suggestion_allowed_this_turn: yes") !== false);
check("product flag omitted when disabled", strpos($powerOff, "product_suggestion_allowed_this_turn: no") !== false);
check("no upload nudge when last user text is loss/distress", empty($distress["upload_reminder_allowed_this_turn"]));
check("no product nudge when last user text is loss/distress", empty($distress["product_suggestion_allowed_this_turn"]));

$both = grok_nudge_compute([
  "zdr_on" => true,
  "open" => true,
  "messages" => [["role" => "user", "content" => "Hello."]],
  "memory" => ["adult_confirmed" => true],
  "checkout_open" => true,
  "sellable" => [["id" => "x", "name" => "Hello Research", "description" => "hello topic"]],
  "now" => time()
]);
check(
  "open turn includes at most one nudge kind",
  !(
    !empty($both["upload_reminder_allowed_this_turn"])
    && !empty($both["product_suggestion_allowed_this_turn"])
  )
);

$catalog = grok_nudge_live_products();
check("sellable catalog is empty until sources_cleared", $catalog === []);
check("sellable line is nothing for sale yet", grok_sellable_line() === "(nothing is for sale yet)");

$welcomeGeneric = welcome_prompt(
  $genericName,
  $genericId,
  [],
  [],
  ["powerups" => []],
  "sFOX is not connected.",
  ["zdr" => true, "open" => true, "upload_enabled" => true, "powerup_enabled" => false, "memory" => ["adult_confirmed" => true]]
);
check("welcome for generic client has no full display name", strpos($welcomeGeneric, $genericName) === false);
check("welcome for generic client has no Van/Charley", stripos($welcomeGeneric, "Charley") === false && stripos($welcomeGeneric, "Van Halfacre") === false);
check("welcome includes upload block when ZDR true", strpos($welcomeGeneric, "UPLOADS (friendly, optional, never pushy)") !== false);
check("welcome includes AttorneyBot hard stops last", preg_match("/HARD STOPS \\(these override everything above\\):\\s*\\n1\\. No personal advice/s", $welcomeGeneric) === 1);
check("welcome has no PENDING ATTORNEYBOT WORDING", strpos($welcomeGeneric, "PENDING ATTORNEYBOT WORDING") === false);

$welcomeZdrOff = welcome_prompt(
  $genericName,
  $genericId,
  [],
  [],
  ["powerups" => []],
  "sFOX is not connected.",
  ["zdr" => false, "open" => true, "upload_enabled" => true, "powerup_enabled" => false]
);
check("welcome omits upload block when ZDR false", strpos($welcomeZdrOff, "UPLOADS (friendly, optional, never pushy)") === false);
check("welcome uses the client when ZDR false", strpos($welcomeZdrOff, "the client") !== false);
check("welcome ZDR off has no first name", strpos($welcomeZdrOff, "Jordan") === false);

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

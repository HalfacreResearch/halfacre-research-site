<?php
/**
 * AttorneyBot flag computer, redaction, and prompt-assembly checks.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . "/van-grok-prompts.php";
require_once $root . "/grok-redact.php";
require_once $root . "/client-memory.php";

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

$now = 1_700_000_000;

$adult = [
  "adult_confirmed" => true,
  "welcome_done" => true,
  "last_message_at" => $now - 60,
  "session_started_at" => $now - 120,
  "upload_reminders_log" => [],
  "product_suggestions_log" => []
];

$third = grok_nudge_compute([
  "zdr_on" => true,
  "open" => false,
  "now" => $now,
  "messages" => [
    ["role" => "user", "content" => "How do dividends work in general?"],
    ["role" => "assistant", "content" => "Generally speaking..."],
    ["role" => "user", "content" => "Thanks, tell me more."]
  ],
  "memory" => array_merge($adult, [
    "upload_reminders_log" => [$now - 86400, $now - 2 * 86400]
  ])
]);
check("third upload reminder in 7 days is impossible", empty($third["upload_reminder_allowed_this_turn"]));

$opted = grok_nudge_compute([
  "zdr_on" => true,
  "open" => false,
  "now" => $now,
  "messages" => [["role" => "user", "content" => "Please stop reminding me"]],
  "memory" => $adult
]);
check("opt-out sets upload_reminders_off", !empty($opted["upload_reminders_off"]));
check("opt-out forces this-turn upload flag no", empty($opted["upload_reminder_allowed_this_turn"]));

$afterOff = grok_nudge_compute([
  "zdr_on" => true,
  "open" => false,
  "now" => $now,
  "messages" => [["role" => "user", "content" => "How do ETFs work?"]],
  "memory" => array_merge($adult, ["upload_reminders_off" => true])
]);
check("any reminder after opt-out is impossible", empty($afterOff["upload_reminder_allowed_this_turn"]));

$zdrOff = grok_nudge_compute([
  "zdr_on" => false,
  "open" => true,
  "now" => $now,
  "messages" => [["role" => "user", "content" => "Hello"]],
  "memory" => ["adult_confirmed" => true]
]);
check("no upload nudge when zdr_on=no", empty($zdrOff["upload_reminder_allowed_this_turn"]));
check("no product nudge when zdr_on=no", empty($zdrOff["product_suggestion_allowed_this_turn"]));

$both = grok_nudge_compute([
  "zdr_on" => true,
  "open" => false,
  "now" => $now,
  "checkout_open" => true,
  "sellable" => [["id" => "div-research", "name" => "Dividend Research", "description" => "dividends and etf rules"]],
  "messages" => [
    ["role" => "user", "content" => "Tell me about dividends"],
    ["role" => "assistant", "content" => "ok"],
    ["role" => "user", "content" => "More on dividends please"]
  ],
  "memory" => $adult
]);
check(
  "both nudges in one turn is impossible",
  !(
    !empty($both["upload_reminder_allowed_this_turn"])
    && !empty($both["product_suggestion_allowed_this_turn"])
  )
);

$noAdult = grok_nudge_compute([
  "zdr_on" => true,
  "open" => true,
  "now" => $now,
  "messages" => [["role" => "user", "content" => "Hello"]],
  "memory" => ["adult_confirmed" => false]
]);
check("no upload nudge without adult_confirmed", empty($noAdult["upload_reminder_allowed_this_turn"]));
check("no product nudge without adult_confirmed", empty($noAdult["product_suggestion_allowed_this_turn"]));

$ssn = grok_redact_text("My number is 123-45-6789 thanks");
check("redacts SSN", strpos($ssn["text"], "123-45-6789") === false);
check("SSN notice is number", strpos($ssn["notice"], "number") !== false);

$tin = grok_redact_text("EIN 12-3456789");
check("redacts TIN", strpos($tin["text"], "12-3456789") === false);

$card = grok_redact_text("card 4111111111111111");
check("redacts Luhn card", strpos($card["text"], "4111111111111111") === false);

$digits = grok_redact_text("acct 123456789012");
check("redacts 8+ digits keeping last 4", strpos($digits["text"], "9012") !== false && strpos($digits["text"], "123456789012") === false);

$pw = grok_redact_text("password: hunter2");
check("redacts password value", strpos($pw["text"], "hunter2") === false);

$pem = grok_redact_text("-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----");
check("redacts PEM block", strpos($pem["text"], "BEGIN") === false);

$ent = grok_redact_text("token " . str_repeat("Ab1+", 10));
check("redacts high-entropy string", strpos($ent["text"], str_repeat("Ab1+", 10)) === false);

$seed = "abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon abandon about";
$bip = grok_redact_text("seed " . $seed);
check("redacts BIP-39 run", strpos($bip["text"], "abandon abandon") === false);

check("sensitive 1040 name", grok_sensitive_filename("1040-2024.pdf"));
check("sensitive passport name", grok_sensitive_filename("passport-scan.jpg"));
check("plain statement is not held", !grok_sensitive_filename("checking-summary.txt"));

$prompt = welcome_prompt("Pat Rivera", "abcdef1234567890", [], [], ["powerups" => []], "HOLDINGS", [
  "zdr" => true,
  "open" => true,
  "memory" => ["adult_confirmed" => true]
]);
$posState = strpos($prompt, "NUDGE STATE");
$posWho = strpos($prompt, "WHO YOU ARE");
$posRole = strpos($prompt, "You are Grok on this Halfacre Research page");
$posUp = strpos($prompt, "UPLOADS (friendly, optional, never pushy)");
$posProd = strpos($prompt, "PRODUCTS (helpful, factual, never pushy)");
$posHard = strpos($prompt, "HARD STOPS (these override everything above)");
check("assembly starts with nudge state", $posState === 0 || ($posState !== false && $posState < $posWho));
check("disclosure follows state", $posWho !== false && $posWho < $posRole);
check("role follows disclosure", $posRole !== false && $posRole < $posUp);
check("upload follows role when zdr on", $posUp !== false && $posUp < $posProd);
check("product follows upload", $posProd !== false && $posProd < $posHard);
check("hard stops are last", $posHard !== false && $posHard > $posProd);
check("prompt has no HOLDINGS", strpos($prompt, "HOLDINGS") === false);
check("prompt has no Bank less", stripos($prompt, "Bank less") === false);
check("prompt has no PENDING placeholder", strpos($prompt, "PENDING ATTORNEYBOT") === false);

$off = welcome_prompt("Pat Rivera", "id", [], [], ["powerups" => []], "", ["zdr" => false, "open" => true]);
check("zdr off omits upload block", strpos($off, "UPLOADS (friendly, optional, never pushy)") === false);
check("zdr off still has product and hard stops", strpos($off, "PRODUCTS (helpful") !== false && strpos($off, "HARD STOPS") !== false);

$flags = ["upload_reminder_allowed_this_turn" => false, "product_suggestion_allowed_this_turn" => false];
$bad = grok_filter_reply("This is risk-free and guaranteed", $flags, []);
check("post-filter catches risk-free/guarantee", $bad["ok"] === false);

$invite = grok_filter_reply(
  "Here is the answer. Whenever you feel like it, you can add another document and I'll help you organize it. Totally optional.",
  $flags,
  []
);
check("post-filter blocks upload invite when not allowed", $invite["ok"] === false);

note("");
note("Results: {$pass} passed, {$fail} failed");
if ($fail > 0) {
  exit(1);
}

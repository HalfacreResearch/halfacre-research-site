<?php
/**
 * Scan client-page Grok prompt strings for language that contradicts /disclaimer.html.
 * Load the prompt builders only — do not boot van-grok.php (that file is an HTTP handler).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . "/van-grok-prompts.php";

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

$banned = [
  "Do not hide behind" => "/do not hide behind/i",
  "hide behind" => "/hide behind/i",
  "fixed 12.5% sleeve" => "/12\\.5\\s*%/",
  "percent-each allocation" => "/\\d+(?:\\.\\d+)?%\\s*each/i",
  "offshore" => "/\\boffshore\\b/i",
  "Where you run your own retirement" => "/where you run your own retirement/i",
  "Perfect portfolio" => "/perfect portfolio/i",
  "cut what they owe" => "/cut what they owe/i",
  "tax loopholes" => "/tax loopholes/i",
  "portfolio balancing" => "/portfolio balancing/i",
  "eight-sleeve target" => "/eight-sleeve/i",
  "eight equal sleeves" => "/eight equal sleeves/i",
  "You are an adviser" => "/you are (?:an |a )(?:investment )?advis[oe]r/i",
  "Bank less" => "/Bank less/i",
  "just unlocked" => "/just unlocked|what that file just unlocked|value that just unlocked/i",
  "second training" => "/second training/i",
  "when their files make them relevant" => "/when their files make them relevant/i",
  "next fitting step" => "/next fitting step/i",
  "turns on, configures" => "/turns on, configures/i",
  "hard-coded dollar price" => "/\\$\\d/",
];

$welcome = welcome_prompt(
  "Charley Van Halfacre",
  "charley-van-halfacre",
  [["name" => "sample-bank.pdf", "kind" => "bank"]],
  ["mod-sample"],
  ["powerups" => [["id" => "mod-sample", "name" => "Sample Module", "avatar_knowledge" => ""]]],
  "sFOX is not connected."
);

$second = second_training("Test Client");
$closed = grok_closed_second_training();

$openPrompt = system_prompt(
  ["live" => [], "coming_soon" => []],
  [],
  false,
  ["powerups" => []],
  "Test Client",
  "abcdef1234567890",
  [],
  true,
  "sFOX is not connected."
);

$mainPrompt = system_prompt(
  ["live" => [["name" => "Sample Research Module"]], "coming_soon" => []],
  [],
  false,
  ["powerups" => []],
  "Test Client",
  "abcdef1234567890",
  [["name" => "w-2.pdf", "kind" => "tax"]],
  false,
  "sFOX is connected."
);

$taxReadyPrompt = system_prompt(
  ["live" => [["name" => "Sample Research Module"]], "coming_soon" => []],
  [],
  true,
  ["powerups" => []],
  "Test Client",
  "abcdef1234567890",
  [
    ["name" => "1040-2024.pdf", "kind" => "tax"],
    ["name" => "1040-2025.pdf", "kind" => "tax"],
    ["name" => "checking.csv", "kind" => "bank"],
    ["name" => "exchange.csv", "kind" => "exchange"]
  ],
  false,
  "sFOX is connected."
);

$generated = [
  "welcome_prompt" => $welcome,
  "second_training" => $second,
  "closed_second_training" => $closed,
  "system_prompt open" => $openPrompt,
  "system_prompt main" => $mainPrompt,
  "system_prompt tax-ready" => $taxReadyPrompt,
];

foreach ($generated as $label => $text) {
  if ($label === "closed_second_training") {
    check($label . " is empty after AttorneyBot (gate removed)", is_string($text) && trim($text) === "");
  } else {
    check($label . " is non-empty", is_string($text) && trim($text) !== "");
  }
  foreach ($banned as $banName => $pattern) {
    $hit = preg_match($pattern, $text) === 1;
    check($label . " has no " . $banName, !$hit, $hit ? "matched in generated prompt" : "");
  }
}

$openExpected = welcome_prompt(
  "Test Client",
  "abcdef1234567890",
  [],
  [],
  ["powerups" => []],
  "sFOX is not connected."
);
check("welcome has no stored legal name", strpos($welcome, "Charley Van Halfacre") === false);
check("welcome has no email", preg_match("/@/", $welcome) !== 1);
check("second_training has no Test Client label", strpos($second, "Client: Test Client") === false);
check("main prompt has no Test Client name", strpos($mainPrompt, "Test Client") === false);

check("open prompt uses welcome_prompt", $openPrompt === $openExpected);
check("tax-ready prompt has no closed second-training gate", $closed === "" || strpos($taxReadyPrompt, $closed) === false);
check("main prompt has no closed second-training gate", $closed === "" || strpos($mainPrompt, $closed) === false);
check("welcome has no sfoxHoldings payload", strpos($welcome, "sFOX is not connected.") === false);
check("main prompt has no sfoxHoldings payload", strpos($mainPrompt, "sFOX is connected.") === false);
check("tax-ready prompt has no sfoxHoldings payload", strpos($taxReadyPrompt, "sFOX is connected.") === false);
check("sellable line is nothing for sale yet", strpos($welcome, "(nothing is for sale yet)") !== false);

$required = [
  "not an investment adviser" => "/not an investment adviser/i",
  "verify with a licensed professional" => "/verify with a licensed professional/i",
  "Research Modules" => "/Research Modules/",
  "Never invent checkout" => "/Never invent checkout/i",
  "Do not paste the catalog" => "/Do not paste the catalog/i",
  "Never dump sectors or products" => "/Never dump sectors or products/i",
];

foreach (["welcome_prompt" => $welcome, "system_prompt main" => $mainPrompt] as $label => $text) {
  foreach ($required as $needName => $pattern) {
    check($label . " keeps " . $needName, preg_match($pattern, $text) === 1);
  }
}

$scanFiles = [
  $root . "/van-grok-prompts.php",
  $root . "/van-grok.php",
  $root . "/van-grok.js",
  $root . "/client-grok.js",
];

$sourceBanned = $banned;
unset($sourceBanned["You are an adviser"]);
unset($sourceBanned["Bank less"]);
unset($sourceBanned["just unlocked"]);
unset($sourceBanned["second training"]);
unset($sourceBanned["when their files make them relevant"]);
unset($sourceBanned["next fitting step"]);
unset($sourceBanned["turns on, configures"]);
unset($sourceBanned["hard-coded dollar price"]);

foreach ($scanFiles as $path) {
  check("readable " . basename($path), is_file($path) && is_readable($path), $path);
  $src = is_file($path) ? (string) file_get_contents($path) : "";
  foreach ($sourceBanned as $banName => $pattern) {
    $hit = preg_match($pattern, $src) === 1;
    check(basename($path) . " source has no " . $banName, !$hit, $hit ? $path : "");
  }
}

$personaNeedles = [
  "Do not hide behind",
  "Your goal is to use portfolio balancing",
  "eight equal sleeves of 12.5%",
];
$personaRoots = [
  $root . "/hivemind",
  $root . "/becky-welcome.html",
  $root . "/becky-avatar.html",
  $root . "/matthew-welcome.html",
  $root . "/matthew-funnel.html",
  $root . "/matthew-who-is-van.html",
];

$personaHits = [];
foreach ($personaRoots as $target) {
  if (is_file($target)) {
    $files = [$target];
  } elseif (is_dir($target)) {
    $files = glob($target . "/*.{html,js}", GLOB_BRACE) ?: [];
  } else {
    $files = [];
  }
  foreach ($files as $file) {
    $src = (string) file_get_contents($file);
    foreach ($personaNeedles as $needle) {
      if (stripos($src, $needle) !== false) {
        $personaHits[] = $file . " :: " . $needle;
      }
    }
  }
}
check("Becky/Van/Matthew/hivemind pages have no Grok advice directives", $personaHits === [], implode("; ", $personaHits));

note("");
note("Results: {$pass} passed, {$fail} failed");

if ($fail > 0) {
  exit(1);
}

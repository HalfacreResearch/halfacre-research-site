<?php
/**
 * System prompts for the client-page Grok proxy.
 * Kept out of the HTTP handler so tests can load them without sending headers.
 * Assembly order (AttorneyBot): nudge state, disclosure, role/education,
 * upload block (zdr only), product block, hard stops last.
 */
declare(strict_types=1);

require_once __DIR__ . "/grok-nudges.php";

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
  unset($userId);
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
    return "None yet.";
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

function grok_closed_second_training(): string
{
  return "";
}

function grok_nudge_ctx_defaults(array $nudgeCtx): array
{
  $zdr = array_key_exists("zdr", $nudgeCtx)
    ? (bool) $nudgeCtx["zdr"]
    : (array_key_exists("zdr_on", $nudgeCtx) ? (bool) $nudgeCtx["zdr_on"] : true);
  $first = isset($nudgeCtx["first"]) ? (string) $nudgeCtx["first"] : "";
  if ($first === "") {
    $first = grok_first_name((string) ($nudgeCtx["display_name"] ?? ""), $zdr);
  }
  $computed = isset($nudgeCtx["computed"]) && is_array($nudgeCtx["computed"])
    ? $nudgeCtx["computed"]
    : grok_nudge_compute([
      "zdr_on" => $zdr,
      "open" => !empty($nudgeCtx["open"]),
      "messages" => isset($nudgeCtx["messages"]) && is_array($nudgeCtx["messages"]) ? $nudgeCtx["messages"] : [],
      "memory" => isset($nudgeCtx["memory"]) && is_array($nudgeCtx["memory"]) ? $nudgeCtx["memory"] : [
        "adult_confirmed" => !empty($nudgeCtx["adult_confirmed"])
      ],
      "checkout_open" => $nudgeCtx["checkout_open"] ?? grok_checkout_open(),
      "paper_mode_available" => $nudgeCtx["paper_mode_available"] ?? grok_paper_mode_available(),
      "sellable_line" => $nudgeCtx["sellable_line"] ?? grok_sellable_line(),
      "now" => $nudgeCtx["now"] ?? time()
    ]);
  if (array_key_exists("upload_enabled", $nudgeCtx) && empty($nudgeCtx["upload_enabled"])) {
    $computed["upload_reminder_allowed_this_turn"] = false;
  }
  if (array_key_exists("powerup_enabled", $nudgeCtx) && empty($nudgeCtx["powerup_enabled"])) {
    $computed["product_suggestion_allowed_this_turn"] = false;
  }
  if (!$zdr) {
    $computed["zdr_on"] = false;
    $computed["upload_reminder_allowed_this_turn"] = false;
    $first = "the client";
  }
  $sellableLine = (string) ($computed["sellable_line"] ?? grok_sellable_line());
  return [
    "zdr" => $zdr,
    "first" => $first,
    "computed" => $computed,
    "sellable_line" => $sellableLine,
    "state" => grok_nudge_state_from_compute($computed)
  ];
}

function grok_role_education_text(array $uploads, array $owned, $avatar, string $userId, bool $open): string
{
  $files = format_uploads($uploads);
  $power = format_powerups($owned, $avatar, $userId);
  $tone = $open
    ? "Welcome them. There is no script. Listen. You decide the next sentence. Warm and helpful. Simple talk. No preaching. No internals."
    : "There is no script. Listen. You decide the next sentence. Simple talk. No preaching. No internals. Warm and helpful.";
  return <<<TXT
You are Grok on this Halfacre Research page. Do not use a stored legal name or email. If they give a first name, you may use that.
{$tone}

Educate and explain general concepts. Help them organize and understand their own files and account data. Do not tell this person what to buy, sell, or allocate. Do not give personalized tax or legal directions.

Already uploaded:
{$files}

Avatar upgrades they already bought:
{$power}

Research areas you may discuss as general topics when the client asks about them: stocks, dividends, ETFs, mutual funds, metals, commodities, crypto, macro, and IRA rules. Do not assign this person a target mix or sleeve weights.

Explain M&A, venture capital, trusts and wills generally, only when the client asks. Do not raise them based on what their files show.

You are not an investment adviser, broker-dealer, commodity trading advisor, lawyer, CPA, or tax preparer. You are not registered as one. Never say you are. Never tell them to ignore /disclaimer.html. If a personal money, tax, or legal choice comes up, suggest they verify with a licensed professional — briefly, not preachily.

No performance claims. No promises about results. Describe products factually.
Never invent checkout. PayPal only when they are ready for one item.
Never dump sectors or products. Never scare.
Do not paste the catalog into chat.
TXT;
}

function grok_assemble_prompt(array $pack): string
{
  $parts = [
    grok_nudge_state($pack["state"]),
    grok_disclosure_block($pack["first"]),
    grok_role_education_text(
      $pack["uploads"],
      $pack["owned"],
      $pack["avatar"],
      $pack["userId"],
      $pack["open"]
    )
  ];
  if (!empty($pack["zdr"])) {
    $parts[] = grok_upload_block($pack["first"]);
  }
  $parts[] = grok_product_block($pack["first"], $pack["sellable_line"]);
  $parts[] = grok_hard_stop_block($pack["first"]);
  return implode("\n\n", $parts);
}

function welcome_prompt(string $clientName, string $userId, array $uploads, array $owned, $avatar, string $sfoxHoldings, array $nudgeCtx = []): string
{
  unset($sfoxHoldings);
  if (!array_key_exists("open", $nudgeCtx)) {
    $nudgeCtx["open"] = true;
  }
  if (!isset($nudgeCtx["display_name"]) || $nudgeCtx["display_name"] === "") {
    $nudgeCtx["display_name"] = $clientName;
  }
  $ctx = grok_nudge_ctx_defaults($nudgeCtx);
  return grok_assemble_prompt([
    "state" => $ctx["state"],
    "first" => $ctx["first"],
    "zdr" => $ctx["zdr"],
    "sellable_line" => $ctx["sellable_line"],
    "uploads" => $uploads,
    "owned" => $owned,
    "avatar" => $avatar,
    "userId" => $userId,
    "open" => true
  ]);
}

function second_training(string $clientName): string
{
  unset($clientName);
  return <<<TXT
Help them organize tax papers they share. Explain general tax and retirement concepts from public rules. Do not tell them which tax move to make, and do not steer them into any structure or strategy as if it were theirs to execute.

Taxes: help them read what they filed and understand the documents. Research modules, data packs, and tax-related bots are products that explain and organize — they are not a CPA and they are not tax advice. If they need a personal answer, they should verify with a licensed professional.

Retirement: describe Halfacre’s retirement research in general terms. Explain self-directed 401k and self-directed IRA as public account types people study. Walk slowly.

Explain M&A, venture capital, trusts and wills generally, only when the client asks. Do not raise them based on what their files show.
Never scare (audit, death, “you’re behind”).
TXT;
}

function system_prompt($catalog, array $owned, bool $sfox, $avatar, string $clientName, string $userId, array $uploads, bool $open, string $sfoxHoldings, array $nudgeCtx = []): string
{
  unset($catalog, $sfox, $sfoxHoldings);
  if (!array_key_exists("open", $nudgeCtx)) {
    $nudgeCtx["open"] = $open;
  }
  if (!isset($nudgeCtx["display_name"]) || $nudgeCtx["display_name"] === "") {
    $nudgeCtx["display_name"] = $clientName;
  }
  if ($open) {
    return welcome_prompt($clientName, $userId, $uploads, $owned, $avatar, "", $nudgeCtx);
  }
  $ctx = grok_nudge_ctx_defaults($nudgeCtx);
  return grok_assemble_prompt([
    "state" => $ctx["state"],
    "first" => $ctx["first"],
    "zdr" => $ctx["zdr"],
    "sellable_line" => $ctx["sellable_line"],
    "uploads" => $uploads,
    "owned" => $owned,
    "avatar" => $avatar,
    "userId" => $userId,
    "open" => false
  ]);
}

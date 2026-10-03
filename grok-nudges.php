<?php
/**
 * Client-page Grok nudge copy and flags.
 *
 * Drop replacement wording into the marked block below. Flags stay on/off
 * here so the prompt builder can include or omit a nudge without other edits.
 */
declare(strict_types=1);

// =============================================================================
// SWAP THIS BLOCK — PENDING ATTORNEYBOT WORDING
// Cleared copy will be dropped in verbatim. Do not edit around this banner
// when replacing strings. Prices and names are NOT in this block; they come
// from van-products.json at runtime.
// =============================================================================

function grok_nudge_config(): array
{
  return [
    "upload_reminder" => [
      "enabled" => true,
      // PENDING ATTORNEYBOT WORDING
      "copy" => "PENDING ATTORNEYBOT WORDING: Cheerful reminder — keep adding your own financial documents and account data when you can, so this page stays current."
    ],
    "powerup_invite" => [
      "enabled" => true,
      // PENDING ATTORNEYBOT WORDING
      "copy" => "PENDING ATTORNEYBOT WORDING: Invitation — you can power up this avatar with more research modules, data packs, and trading bots. General product information only. Not personalized advice. Do not claim a product improves results, performance, or retirement outcomes. Do not pick items from this person's holdings or finances. Trading bots are practice / simulated tools only. Name only live, purchasable items, with the catalog price, and link each one to its product page."
    ]
  ];
}

// =============================================================================
// End of swappable wording block
// =============================================================================

function grok_nudge_flag_override(string $envName, bool $default): bool
{
  $raw = getenv($envName);
  if (!is_string($raw) || trim($raw) === "") {
    return $default;
  }
  $v = strtolower(trim($raw));
  if ($v === "0" || $v === "false" || $v === "off") {
    return false;
  }
  if ($v === "1" || $v === "true" || $v === "on") {
    return true;
  }
  return $default;
}

function grok_nudge_flags(): array
{
  $cfg = grok_nudge_config();
  $upload = !empty($cfg["upload_reminder"]["enabled"]);
  $power = !empty($cfg["powerup_invite"]["enabled"]);
  return [
    "upload" => grok_nudge_flag_override("HALFACRE_GROK_NUDGE_UPLOAD", $upload),
    "powerup" => grok_nudge_flag_override("HALFACRE_GROK_NUDGE_POWERUP", $power)
  ];
}

function grok_nudge_upload_copy(): string
{
  $cfg = grok_nudge_config();
  return (string) ($cfg["upload_reminder"]["copy"] ?? "");
}

function grok_nudge_powerup_copy(): string
{
  $cfg = grok_nudge_config();
  return (string) ($cfg["powerup_invite"]["copy"] ?? "");
}

function grok_nudge_catalog_path(): string
{
  return __DIR__ . "/van-products.json";
}

function grok_nudge_price_label($price): string
{
  if (!is_numeric($price)) {
    return "";
  }
  $n = (float) $price;
  if (abs($n - 149.0) < 0.001) {
    return "$149";
  }
  if (abs($n - 29.99) < 0.001) {
    return "$29.99";
  }
  if (abs($n - 4.99) < 0.001) {
    return "$4.99";
  }
  if (abs($n - round($n)) < 0.001) {
    return "$" . (string) (int) round($n);
  }
  return "$" . number_format($n, 2, ".", "");
}

function grok_nudge_product_page(string $id): string
{
  return "product.html?id=" . rawurlencode($id);
}

function grok_nudge_live_products(): array
{
  $path = grok_nudge_catalog_path();
  if (!is_file($path)) {
    return [];
  }
  $data = json_decode((string) file_get_contents($path), true);
  $rows = (is_array($data) && isset($data["modules"]) && is_array($data["modules"]))
    ? $data["modules"]
    : [];
  $out = [];
  foreach ($rows as $row) {
    if (!is_array($row)) {
      continue;
    }
    $id = trim((string) ($row["id"] ?? ""));
    $name = trim((string) ($row["name"] ?? ""));
    if ($id === "" || $name === "") {
      continue;
    }
    if (empty($row["live"])) {
      continue;
    }
    if (!empty($row["free"])) {
      continue;
    }
    $price = $row["price_usd"] ?? null;
    if (!is_numeric($price) || (float) $price <= 0) {
      continue;
    }
    $kind = strtolower(trim((string) ($row["kind"] ?? "")));
    if ($kind !== "research" && $kind !== "pack" && $kind !== "bot") {
      continue;
    }
    $out[] = [
      "id" => $id,
      "name" => $name,
      "price" => (float) $price,
      "price_label" => grok_nudge_price_label($price),
      "kind" => $kind,
      "page" => grok_nudge_product_page($id)
    ];
  }
  return $out;
}

function grok_nudge_kind_label(string $kind): string
{
  if ($kind === "pack") {
    return "data pack";
  }
  if ($kind === "bot") {
    return "trading bot; practice / simulated only";
  }
  return "research module";
}

function grok_nudge_catalog_text(): string
{
  $lines = [];
  foreach (grok_nudge_live_products() as $row) {
    $lines[] = "- {$row["name"]} — {$row["price_label"]} — {$row["page"]} ({$row["kind"]})";
  }
  if (!$lines) {
    return "Live purchasable catalog (van-products.json): (none live)";
  }
  return "Live purchasable catalog (van-products.json):\n" . implode("\n", $lines);
}

function grok_nudge_looks_distressed(string $text): bool
{
  $raw = strtolower(trim($text));
  if ($raw === "") {
    return false;
  }
  return preg_match(
    "/\\b(lost|loss|losses|losing|wiped out|ruined|distress|devastat|suicide|i'm broke|im broke|going under)\\b/i",
    $raw
  ) === 1;
}

function grok_nudge_last_user_text(array $messages): string
{
  for ($i = count($messages) - 1; $i >= 0; $i--) {
    $row = $messages[$i];
    if (!is_array($row)) {
      continue;
    }
    if (($row["role"] ?? "") !== "user") {
      continue;
    }
    return trim((string) ($row["content"] ?? ""));
  }
  return "";
}

function grok_nudge_due(array $messages, bool $open): bool
{
  if ($open) {
    return true;
  }
  $users = 0;
  foreach ($messages as $row) {
    if (is_array($row) && ($row["role"] ?? "") === "user") {
      $users++;
    }
  }
  if ($users <= 0) {
    return false;
  }
  return ($users % 3) === 1;
}

/**
 * Build the optional nudge instruction block for a system prompt.
 *
 * @param array{
 *   zdr?: bool,
 *   messages?: array,
 *   open?: bool,
 *   upload_enabled?: bool,
 *   powerup_enabled?: bool,
 *   force?: string
 * } $ctx
 */
function grok_nudge_block(array $ctx = []): string
{
  $flags = grok_nudge_flags();
  if (array_key_exists("upload_enabled", $ctx)) {
    $flags["upload"] = (bool) $ctx["upload_enabled"];
  }
  if (array_key_exists("powerup_enabled", $ctx)) {
    $flags["powerup"] = (bool) $ctx["powerup_enabled"];
  }

  $zdr = array_key_exists("zdr", $ctx) ? (bool) $ctx["zdr"] : true;
  $messages = isset($ctx["messages"]) && is_array($ctx["messages"]) ? $ctx["messages"] : [];
  $open = !empty($ctx["open"]);
  $last = grok_nudge_last_user_text($messages);
  $force = isset($ctx["force"]) ? (string) $ctx["force"] : "";

  if ($force !== "upload" && $force !== "powerup" && $force !== "both") {
    if (grok_nudge_looks_distressed($last)) {
      return "";
    }
    if (!grok_nudge_due($messages, $open)) {
      return "";
    }
  }

  $wantUpload = $flags["upload"] && $zdr;
  $wantPower = $flags["powerup"];
  if ($force === "upload") {
    $wantPower = false;
  } elseif ($force === "powerup") {
    $wantUpload = false;
  } elseif ($force !== "both") {
    // At most one kind in the prompt for a given turn.
    if ($wantUpload && $wantPower) {
      if ($open) {
        $wantPower = false;
      } else {
        $users = 0;
        foreach ($messages as $row) {
          if (is_array($row) && ($row["role"] ?? "") === "user") {
            $users++;
          }
        }
        if ($users % 2 === 1) {
          $wantPower = false;
        } else {
          $wantUpload = false;
        }
      }
    }
  }

  $parts = [];
  if ($wantUpload) {
    $copy = grok_nudge_upload_copy();
    if ($copy !== "") {
      $parts[] = $copy;
    }
  }
  if ($wantPower) {
    $copy = grok_nudge_powerup_copy();
    if ($copy !== "") {
      $parts[] = $copy;
    }
    $parts[] = grok_nudge_catalog_text();
  }
  if (!$parts) {
    return "";
  }

  $rules = "Nudge rules: at most one nudge per reply. Do not add a nudge in every reply. Never add a nudge when they are talking about losses or distress.";
  return $rules . "\n" . implode("\n", $parts);
}

function grok_nudge_banned_patterns(): array
{
  return [
    "guaranteed" => "/\\bguaranteed\\b/i",
    "returns" => "/\\breturns\\b/i",
    "beat the market" => "/beat the market/i",
    "risk-free" => "/risk-free|risk free/i",
    "passive income" => "/passive income/i",
    "improve returns" => "/improve[s]? returns/i",
    "better returns" => "/better returns/i"
  ];
}

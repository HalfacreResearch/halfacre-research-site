<?php
/**
 * Pre-send redaction and Grok reply post-filter.
 * Logs hits without storing the redacted content.
 */
declare(strict_types=1);

function grok_luhn_valid(string $digits): bool
{
  $len = strlen($digits);
  if ($len < 13 || $len > 19) {
    return false;
  }
  $sum = 0;
  $alt = false;
  for ($i = $len - 1; $i >= 0; $i--) {
    $n = (int) $digits[$i];
    if ($alt) {
      $n *= 2;
      if ($n > 9) {
        $n -= 9;
      }
    }
    $sum += $n;
    $alt = !$alt;
  }
  return $sum % 10 === 0;
}

function grok_high_entropy(string $token): bool
{
  if (strlen($token) < 32) {
    return false;
  }
  if (!preg_match("/^[A-Za-z0-9\\/+_=\\-]{32,}$/", $token)) {
    return false;
  }
  $classes = 0;
  if (preg_match("/[a-z]/", $token)) {
    $classes++;
  }
  if (preg_match("/[A-Z]/", $token)) {
    $classes++;
  }
  if (preg_match("/[0-9]/", $token)) {
    $classes++;
  }
  if (preg_match("/[\\/+_=\\-]/", $token)) {
    $classes++;
  }
  return $classes >= 3;
}

function grok_bip39_set(): array
{
  static $set = null;
  if (is_array($set)) {
    return $set;
  }
  $path = __DIR__ . "/grok-bip39-words.txt";
  $set = [];
  if (is_file($path)) {
    foreach (preg_split("/\\s+/", strtolower((string) file_get_contents($path))) ?: [] as $w) {
      if ($w !== "") {
        $set[$w] = true;
      }
    }
  }
  foreach ([
    "abandon", "ability", "able", "about", "above", "absent", "absorb", "abstract",
    "absurd", "abuse", "access", "accident", "account", "accuse", "achieve", "acid",
    "acoustic", "acquire", "across", "act", "action", "actor", "actress", "actual",
    "adapt", "add", "addict", "address", "adjust", "admit", "adult", "advance",
    "advice", "aerobic", "affair", "afford", "afraid", "again", "age", "agent",
    "agree", "ahead", "aim", "air", "airport", "aisle", "alarm", "album",
    "alcohol", "alert", "alien", "all", "alley", "allow", "almost", "alone",
    "alpha", "already", "also", "alter", "always", "amateur", "amazing", "among",
    "amount", "amused", "analyst", "anchor", "ancient", "anger", "angle", "angry",
    "animal", "ankle", "announce", "annual", "another", "answer", "antenna", "antique",
    "anxiety", "any", "apart", "apology", "appear", "apple", "approve", "april",
    "arch", "arctic", "area", "arena", "argue", "arm", "armed", "armor",
    "army", "around", "arrange", "arrest", "arrive", "arrow", "art", "artefact",
    "artist", "artwork", "ask", "aspect", "assault", "asset", "assist", "assume",
    "asthma", "athlete", "atom", "attack", "attend", "attitude", "attract", "auction"
  ] as $w) {
    $set[$w] = true;
  }
  return $set;
}

function grok_redact_bip39(string $text, array &$kinds): string
{
  $set = grok_bip39_set();
  if (!$set) {
    return $text;
  }
  if (!preg_match_all("/[A-Za-z]+/", $text, $m, PREG_OFFSET_CAPTURE)) {
    return $text;
  }
  $words = $m[0];
  $n = count($words);
  $kill = [];
  foreach ([24, 12] as $need) {
    for ($i = 0; $i <= $n - $need; $i++) {
      $ok = true;
      for ($j = 0; $j < $need; $j++) {
        $w = strtolower($words[$i + $j][0]);
        if (!isset($set[$w])) {
          $ok = false;
          break;
        }
      }
      if ($ok) {
        $start = $words[$i][1];
        $last = $words[$i + $need - 1];
        $end = $last[1] + strlen($last[0]);
        $kill[] = [$start, $end];
        $kinds["key"] = true;
      }
    }
  }
  if (!$kill) {
    return $text;
  }
  usort($kill, function ($a, $b) {
    return $b[0] <=> $a[0];
  });
  foreach ($kill as $span) {
    $text = substr($text, 0, $span[0]) . "[redacted-key]" . substr($text, $span[1]);
  }
  return $text;
}

function grok_redact_text(string $text): array
{
  $kinds = [];
  $out = $text;

  $out = preg_replace_callback("/\\b\\d{3}-?\\d{2}-?\\d{4}\\b/", function () use (&$kinds) {
    $kinds["number"] = true;
    return "[redacted-number]";
  }, $out) ?? $out;

  $out = preg_replace_callback("/\\b\\d{2}-?\\d{7}\\b/", function () use (&$kinds) {
    $kinds["number"] = true;
    return "[redacted-number]";
  }, $out) ?? $out;

  $out = preg_replace_callback("/(?<![0-9])(\\d[\\s-]?){13,19}(?![0-9])/", function ($m) use (&$kinds) {
    $digits = preg_replace("/\\D/", "", $m[0]) ?? "";
    if (grok_luhn_valid($digits)) {
      $kinds["number"] = true;
      return "[redacted-number]";
    }
    return $m[0];
  }, $out) ?? $out;

  $out = preg_replace_callback("/\\d{8,}/", function ($m) use (&$kinds) {
    $kinds["number"] = true;
    $keep = substr($m[0], -4);
    return "[redacted-number]" . $keep;
  }, $out) ?? $out;

  $out = preg_replace_callback(
    "/\\b(?:password|passcode|pin|security answer)\\b\\s*[:#=-]?\\s*\\S+/i",
    function () use (&$kinds) {
      $kinds["key"] = true;
      return "[redacted-key]";
    },
    $out
  ) ?? $out;

  if (stripos($out, "-----BEGIN") !== false) {
    $kinds["key"] = true;
    $out = preg_replace("/-----BEGIN[\\s\\S]+?-----END[^-]+-----/", "[redacted-key]", $out) ?? $out;
  }

  $out = preg_replace_callback("/\\b[A-Za-z0-9\\/+_=\\-]{32,}\\b/", function ($m) use (&$kinds) {
    if (grok_high_entropy($m[0])) {
      $kinds["key"] = true;
      return "[redacted-key]";
    }
    return $m[0];
  }, $out) ?? $out;

  $out = grok_redact_bip39($out, $kinds);

  $notice = "";
  if ($kinds) {
    $label = isset($kinds["key"]) && isset($kinds["number"])
      ? "number/key"
      : (isset($kinds["key"]) ? "key" : "number");
    $notice = "We removed something that looked like a {$label} before sending.";
  }

  return [
    "text" => $out,
    "kinds" => array_keys($kinds),
    "notice" => $notice,
    "changed" => $out !== $text
  ];
}

function grok_filter_log(string $reason): void
{
  $path = function_exists("client_rate_store_path")
    ? client_rate_store_path("grok-filter.store.json")
    : sys_get_temp_dir() . "/grok-filter.store.json";
  $row = json_encode(["at" => time(), "reason" => $reason]);
  file_put_contents($path, ($row === false ? "{}" : $row) . "\n", FILE_APPEND | LOCK_EX);
}

function grok_reply_banned_match(string $text): ?string
{
  $rules = [
    "guarantee" => "/\\bguarantee/i",
    "risk-free" => "/risk[- ]free/i",
    "no risk" => "/\\bno risk\\b/i",
    "insured" => "/\\binsured\\b/i",
    "percent return" => "/\\d+(?:\\.\\d+)?%\\s*(?:a year|cagr|return|apy)/i",
    "beat the market" => "/beat(?:s)? the (?:market|s&p)/i",
    "passive income" => "/passive income/i",
    "only n left" => "/only \\d+ left/i",
    "ends soon" => "/ends (?:tonight|soon|at midnight)/i",
    "you should buy/sell" => "/you should (?:buy|sell)/i",
    "i would buy/sell" => "/i(?:'d| would) (?:buy|sell)/i",
    "allocate" => "/\\ballocate\\b/i",
    "rebalance" => "/\\brebalance\\b/i",
    "connect exchange" => "/connect (?:your )?(?:exchange|sfox|account)/i",
    "api key" => "/api key/i",
    "go live" => "/go live/i",
    "live trading" => "/live trading/i",
    "real money" => "/real money/i",
    "trade account" => "/trade (?:your|the) (?:account|book)/i"
  ];
  foreach ($rules as $name => $pat) {
    if (preg_match($pat, $text) === 1) {
      return $name;
    }
  }
  return null;
}

function grok_reply_looks_like_upload_invite(string $text): bool
{
  return preg_match(
    "/\\b(upload|add another document|stop reminding me)\\b/i",
    $text
  ) === 1 && preg_match(
    "/\\b(document|summary|optional|no pressure|black out)\\b/i",
    $text
  ) === 1;
}

function grok_reply_named_products(string $text, array $sellable): array
{
  $hits = [];
  foreach ($sellable as $row) {
    $name = trim((string) ($row["name"] ?? ""));
    if ($name !== "" && stripos($text, $name) !== false) {
      $hits[] = $name;
    }
  }
  return $hits;
}

function grok_catalog_names_not_sellable(string $text, array $sellable): array
{
  $allowed = [];
  foreach ($sellable as $row) {
    $n = strtolower(trim((string) ($row["name"] ?? "")));
    if ($n !== "") {
      $allowed[$n] = true;
    }
  }
  $bad = [];
  foreach (grok_nudge_catalog_modules() as $row) {
    if (!is_array($row)) {
      continue;
    }
    $name = trim((string) ($row["name"] ?? ""));
    if ($name === "" || isset($allowed[strtolower($name)])) {
      continue;
    }
    if (strlen($name) < 5) {
      continue;
    }
    if (stripos($text, $name) !== false) {
      $bad[] = $name;
    }
  }
  return $bad;
}

/**
 * Strip a banned reply. Returns [ok, text, reason].
 */
function grok_filter_reply(string $text, array $flags, array $sellable): array
{
  $reason = grok_reply_banned_match($text);
  if ($reason !== null) {
    return ["ok" => false, "text" => $text, "reason" => $reason];
  }
  $uploadInvite = grok_reply_looks_like_upload_invite($text);
  $productHits = grok_catalog_names_not_sellable($text, $sellable);
  $namedSellable = grok_reply_named_products($text, $sellable);
  $productInvite = $namedSellable !== [] && preg_match("/since you asked about/i", $text) === 1;

  if ($uploadInvite && empty($flags["upload_reminder_allowed_this_turn"])) {
    return ["ok" => false, "text" => $text, "reason" => "upload-invite-not-allowed"];
  }
  if ($productHits) {
    return ["ok" => false, "text" => $text, "reason" => "product-not-sellable"];
  }
  if ($uploadInvite && $productInvite) {
    return ["ok" => false, "text" => $text, "reason" => "both-nudges"];
  }
  return ["ok" => true, "text" => $text, "reason" => ""];
}

function grok_sensitive_filename(string $name): bool
{
  return preg_match("/1040|w-?2|1099|tax.?return|passport|license|ssn|will|trust/i", $name) === 1;
}

function grok_sensitive_hold_message(): string
{
  return "Your page link has no password. Please hold off on very sensitive documents, or remove the numbers first";
}

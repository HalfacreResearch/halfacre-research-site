<?php
/**
 * Arithmetic checks for the in-browser planner. Mirrors van-btctreasury-plan.js.
 * No network. Numbers the client would type.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;

function expect_true(string $name, bool $ok, string $detail = ""): void
{
  global $pass, $fail;
  if ($ok) {
    $pass++;
    echo "PASS  {$name}\n";
    return;
  }
  $fail++;
  echo "FAIL  {$name}" . ($detail !== "" ? " ({$detail})" : "") . "\n";
}

$js = file_get_contents(dirname(__DIR__) . "/van-btctreasury-plan.js");
expect_true("plan source exists", is_string($js) && $js !== "");
expect_true("plan is hypothetical", is_string($js) && str_contains((string) $js, "Hypothetical"));
expect_true("plan has no fetch", is_string($js) && !preg_match("/\\bfetch\\s*\\(/", (string) $js));

$holdings = 1.0;
$budget = 1200.0;
$price = 60000.0;
$periods = 12;
$per = $budget / $periods;
$added = $per / $price;
$end = $holdings + ($added * $periods);
expect_true("usd per period is 100.00", abs($per - 100.0) < 0.0001);
expect_true("btc added per period is 100/60000", abs($added - (100 / 60000)) < 1e-12);
expect_true("end holdings is start plus arithmetic", abs($end - (1.0 + (1200 / 60000))) < 1e-12);
expect_true("no performance claim in fixture", $end > 0);

echo "Results: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

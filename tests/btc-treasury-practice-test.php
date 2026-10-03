<?php
/**
 * Arithmetic checks for the practice-mode ledger. Mirrors van-btctreasury-practice.js.
 * No network. Numbers a client would type.
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

$js = file_get_contents(dirname(__DIR__) . "/van-btctreasury-practice.js");
expect_true("practice source exists", is_string($js) && $js !== "");
expect_true("round trip is 1.6%", is_string($js) && str_contains((string) $js, "var ROUND_TRIP_COST = 0.016"));
expect_true("data source is client-typed", is_string($js) && str_contains((string) $js, "prices entered by you"));
expect_true("signal TODO is marked", is_string($js) && str_contains((string) $js, "TODO(signal-module)"));
expect_true("practice has no fetch", is_string($js) && !preg_match("/\\bfetch\\s*\\(/", (string) $js));
expect_true("practice has no van-sfox", is_string($js) && !str_contains((string) $js, "van-sfox"));
expect_true("8.1 banner constant", is_string($js) && str_contains((string) $js, "PRACTICE MODE: SIMULATED. No real money. No exchange connection. No real trades."));
expect_true("8.2 legend constant", is_string($js) && str_contains((string) $js, "These results are based on simulated or hypothetical performance results"));
expect_true("8.3 disclaimer constant", is_string($js) && str_contains((string) $js, "It is general and impersonal: every user gets the same signals"));

$oneWay = 0.008;
$start = 10000.0;
$buyUsd = 5000.0;
$buyPrice = 100000.0;
$cost = $buyUsd * $oneWay;
$qty = ($buyUsd * (1 - $oneWay)) / $buyPrice;
$cashAfterBuy = $start - $buyUsd;
$equityAfterBuy = $cashAfterBuy + ($qty * $buyPrice);
$ddAfterBuy = (($start - $equityAfterBuy) / $start) * 100;

expect_true("simulated buy cost is 40.00", abs($cost - 40.0) < 0.0001, (string) $cost);
expect_true("simulated BTC filled is 0.0496", abs($qty - 0.0496) < 1e-12, (string) $qty);
expect_true("simulated equity after buy is 9960", abs($equityAfterBuy - 9960.0) < 0.0001, (string) $equityAfterBuy);
expect_true("simulated drawdown after buy is 0.40%", abs($ddAfterBuy - 0.4) < 0.0001, (string) $ddAfterBuy);

$mark = 80000.0;
$equityMark = $cashAfterBuy + ($qty * $mark);
$ddMark = (($start - $equityMark) / $start) * 100;
expect_true("simulated equity at lower mark is 8968", abs($equityMark - 8968.0) < 0.0001, (string) $equityMark);
expect_true("largest simulated drawdown after mark is 10.32%", abs($ddMark - 10.32) < 0.0001, (string) $ddMark);

$sellQty = $qty;
$proceeds = $sellQty * $mark * (1 - $oneWay);
$cashAfterSell = $cashAfterBuy + $proceeds;
$ddSell = (($start - $cashAfterSell) / $start) * 100;
expect_true("simulated sell applies one-way cost", abs($proceeds - 3936.256) < 0.001, (string) $proceeds);
expect_true("simulated cash after sell includes loss", abs($cashAfterSell - 8936.256) < 0.001, (string) $cashAfterSell);
expect_true("simulated drawdown after sell is larger", $ddSell > $ddMark);

echo "Results: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);

/**
 * BTC treasury / DCA planning arithmetic. Client-supplied numbers only.
 * No market data, no network. Hypothetical results, not advice.
 */
(function (root) {
  "use strict";

  var MAX_PERIODS = 120;
  var SKU = "btc-treasury-bot";

  function num(value) {
    var n = Number(value);
    return isFinite(n) ? n : 0;
  }

  function clampPeriods(value) {
    var n = parseInt(value, 10);
    if (!isFinite(n) || n < 1) return 1;
    if (n > MAX_PERIODS) return MAX_PERIODS;
    return n;
  }

  function cadenceOf(value) {
    return String(value || "").toLowerCase() === "weekly" ? "weekly" : "monthly";
  }

  function addPeriod(startIso, index, cadence) {
    var start = String(startIso || "").trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(start)) {
      return "period-" + (index + 1);
    }
    var parts = start.split("-");
    var y = parseInt(parts[0], 10);
    var m = parseInt(parts[1], 10) - 1;
    var d = parseInt(parts[2], 10);
    var dt = new Date(Date.UTC(y, m, d));
    if (cadence === "weekly") {
      dt.setUTCDate(dt.getUTCDate() + (7 * index));
    } else {
      dt.setUTCMonth(dt.getUTCMonth() + index);
    }
    var yy = dt.getUTCFullYear();
    var mm = String(dt.getUTCMonth() + 1);
    var dd = String(dt.getUTCDate());
    if (mm.length < 2) mm = "0" + mm;
    if (dd.length < 2) dd = "0" + dd;
    return yy + "-" + mm + "-" + dd;
  }

  function money(n) {
    return (Math.round(n * 100) / 100).toFixed(2);
  }

  function btc(n) {
    return (Math.round(n * 1e8) / 1e8).toFixed(8);
  }

  function build(input) {
    input = input || {};
    var holdings = Math.max(0, num(input.holdings_btc));
    var budget = Math.max(0, num(input.budget_usd));
    var price = Math.max(0, num(input.btc_price_usd));
    var periods = clampPeriods(input.periods);
    var cadence = cadenceOf(input.cadence);
    var start = String(input.start_date || "").trim();
    var perUsd = periods > 0 ? budget / periods : 0;
    var rows = [];
    var spent = 0;
    var coins = holdings;
    var i;
    for (i = 0; i < periods; i += 1) {
      var added = price > 0 ? perUsd / price : 0;
      spent += perUsd;
      coins += added;
      rows.push({
        period: i + 1,
        date: addPeriod(start, i, cadence),
        usd_allocated: money(perUsd),
        btc_price_used: price > 0 ? money(price) : "",
        btc_added: price > 0 ? btc(added) : "",
        holdings_btc: price > 0 ? btc(coins) : btc(holdings),
        spent_usd_cumulative: money(spent)
      });
    }
    return {
      sku: SKU,
      hypothetical: true,
      cadence: cadence,
      periods: periods,
      holdings_start_btc: btc(holdings),
      budget_usd: money(budget),
      btc_price_usd: price > 0 ? money(price) : "",
      usd_per_period: money(perUsd),
      holdings_end_btc: price > 0 ? btc(coins) : btc(holdings),
      spent_usd: money(spent),
      rows: rows,
      note: "Hypothetical arithmetic from numbers you typed. Not a forecast, backtest, or recommendation."
    };
  }

  function toCsv(plan) {
    var lines = [
      "period,date,usd_allocated,btc_price_used,btc_added,holdings_btc,spent_usd_cumulative"
    ];
    (plan.rows || []).forEach(function (row) {
      lines.push([
        row.period,
        row.date,
        row.usd_allocated,
        row.btc_price_used,
        row.btc_added,
        row.holdings_btc,
        row.spent_usd_cumulative
      ].join(","));
    });
    lines.push("");
    lines.push("note," + JSON.stringify(plan.note || ""));
    return lines.join("\n") + "\n";
  }

  root.HalfacreTreasuryPlan = {
    sku: SKU,
    maxPeriods: MAX_PERIODS,
    build: build,
    toCsv: toCsv
  };
})(typeof window !== "undefined" ? window : globalThis);

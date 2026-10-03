/**
 * BTCTreasuryBot PRACTICE MODE ledger.
 * Virtual USD + manual simulated spot-BTC buy/sell. No network. No keys.
 * No exchange. No keys. Prices entered by the client.
 *
 * TODO(signal-module): Do not invent signals. Documented bot rules are not
 * in this site repo or the public catalog. HalfacreResearch/Halfacre-Bitcoin-Trading-Bot
 * main is README-only; import/* branches hold sfoxEngine/dcaEngine/rotationEngine
 * tied to sFOX and TradingHQ feeds that DATA_SOURCES.md marks as needing
 * re-sourcing before commercial use. Port that module here only after
 * AttorneyBot clears an impersonal, documented rule set. Until then this
 * file is the practice shell (virtual balance, cost model, ledger, drawdown).
 */
(function (root) {
  "use strict";

  var SKU = "btc-treasury-bot";
  var ASSET = "BTC";
  var DEFAULT_CASH_USD = 10000;
  var ROUND_TRIP_COST = 0.016;
  var ONE_WAY_COST = ROUND_TRIP_COST / 2;
  var COST_LABEL = "1.6% round trip covering fees, spread and slippage";
  var DATA_SOURCE = "prices entered by you";
  var BANNER_81 =
    "PRACTICE MODE: SIMULATED. No real money. No exchange connection. No real trades.\n" +
    "Every balance, trade, and gain or loss on this screen is hypothetical.";
  var DISCLAIMER_83 =
    "BTCTreasuryBot is software and research published by Halfacre Research (a trade name of Halfacre Research Institute LLC, an Alabama LLC). It is general and impersonal: every user gets the same signals, and nothing is tailored to your finances, goals, or holdings. It is not investment, financial, tax, or legal advice, and not a recommendation to buy or sell any asset. Halfacre Research is not a registered investment adviser, broker-dealer, commodity trading advisor, or money transmitter. Bitcoin and other crypto assets are highly volatile, and you can lose some or all of your money. Automated trading adds risks such as software bugs, bad data, exchange outages, and fast losses. Past and simulated performance do not guarantee future results. There is no guarantee of profit and no \"risk-free\" trading.";

  function num(value) {
    var n = Number(value);
    return isFinite(n) ? n : NaN;
  }

  function money(n) {
    return (Math.round(n * 100) / 100).toFixed(2);
  }

  function btc(n) {
    return (Math.round(n * 1e8) / 1e8).toFixed(8);
  }

  function pct(n) {
    return (Math.round(n * 100) / 100).toFixed(2);
  }

  function isoDate(value) {
    var raw = String(value == null ? "" : value).trim();
    if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) return raw;
    return "";
  }

  function todayIso() {
    var d = new Date();
    var y = d.getFullYear();
    var m = String(d.getMonth() + 1);
    var day = String(d.getDate());
    if (m.length < 2) m = "0" + m;
    if (day.length < 2) day = "0" + day;
    return y + "-" + m + "-" + day;
  }

  function dateOrToday(value) {
    return isoDate(value) || todayIso();
  }

  function emptyBook(startCash) {
    var cash = Math.max(0, num(startCash));
    if (!isFinite(cash)) cash = DEFAULT_CASH_USD;
    return {
      sku: SKU,
      asset: ASSET,
      simulated: true,
      cash_usd: cash,
      btc: 0,
      start_cash_usd: cash,
      mark_price_usd: 0,
      peak_equity_usd: cash,
      largest_drawdown_pct: 0,
      start_date: "",
      end_date: "",
      rows: []
    };
  }

  function equityOf(book, mark) {
    var price = mark > 0 ? mark : book.mark_price_usd;
    if (!(price > 0) || !(book.btc > 0)) return book.cash_usd;
    return book.cash_usd + (book.btc * price);
  }

  function applyDrawdown(book, equity) {
    if (equity > book.peak_equity_usd) book.peak_equity_usd = equity;
    var peak = book.peak_equity_usd;
    var dd = peak > 0 ? ((peak - equity) / peak) * 100 : 0;
    if (dd < 0) dd = 0;
    if (dd > book.largest_drawdown_pct) book.largest_drawdown_pct = dd;
    return dd;
  }

  function touchDates(book, date) {
    if (!book.start_date) book.start_date = date;
    book.end_date = date;
  }

  function snapshot(book, markPrice) {
    var mark = num(markPrice);
    if (mark > 0) book.mark_price_usd = mark;
    var equity = equityOf(book, book.mark_price_usd);
    var dd = applyDrawdown(book, equity);
    var gain = equity - book.start_cash_usd;
    return {
      simulated: true,
      cash_usd: money(book.cash_usd),
      btc: btc(book.btc),
      mark_price_usd: book.mark_price_usd > 0 ? money(book.mark_price_usd) : "",
      equity_usd: money(equity),
      gain_or_loss_usd: money(gain),
      drawdown_pct: pct(dd),
      largest_drawdown_pct: pct(book.largest_drawdown_pct),
      start_cash_usd: money(book.start_cash_usd),
      start_date: book.start_date || "no simulated trades yet",
      end_date: book.end_date || "no simulated trades yet"
    };
  }

  function dateRange(book) {
    if (!book.start_date && !book.end_date) return "no simulated trades yet";
    var start = book.start_date || "undated";
    var end = book.end_date || start;
    if (start === end) return start;
    return start + " to " + end;
  }

  function legend82(book) {
    var snap = snapshot(book, book.mark_price_usd);
    return (
      "These results are based on simulated or hypothetical performance results that have certain inherent limitations. " +
      "Unlike the results shown in an actual performance record, these results do not represent actual trading. " +
      "Also, because these trades have not actually been executed, these results may have under- or over-compensated for the impact, if any, of certain market factors, such as lack of liquidity. " +
      "Simulated or hypothetical trading programs in general are also subject to the fact that they are designed with the benefit of hindsight. " +
      "No representation is being made that any account will or is likely to achieve profits or losses similar to those being shown. " +
      "Assumptions: " + COST_LABEL + ", " + DATA_SOURCE + ", " + dateRange(book) +
      ". Simulated results include periods of loss; the largest simulated drawdown was " +
      snap.largest_drawdown_pct + "%."
    );
  }

  function reset(startCash, date) {
    var book = emptyBook(startCash);
    var when = dateOrToday(date);
    book.start_date = when;
    book.end_date = when;
    book.rows.push({
      id: 1,
      side: "start",
      date: when,
      price_usd: "",
      usd: money(book.cash_usd),
      btc_qty: btc(0),
      cost_usd: money(0),
      cash_usd: money(book.cash_usd),
      holdings_btc: btc(0),
      equity_usd: money(book.cash_usd),
      drawdown_pct: pct(0),
      simulated: true
    });
    return book;
  }

  function fail(message) {
    return { ok: false, error: message };
  }

  function buy(book, input) {
    input = input || {};
    var usd = num(input.usd);
    var price = num(input.price);
    if (!(usd > 0)) return fail("Type a simulated USD amount greater than zero.");
    if (!(price > 0)) return fail("Type the BTC price you want to use.");
    if (usd > book.cash_usd + 1e-9) return fail("Simulated cash is not enough for that simulated buy.");
    var cost = usd * ONE_WAY_COST;
    var filled = usd * (1 - ONE_WAY_COST);
    var qty = filled / price;
    var when = dateOrToday(input.date);
    book.cash_usd -= usd;
    book.btc += qty;
    book.mark_price_usd = price;
    touchDates(book, when);
    var equity = equityOf(book, price);
    var dd = applyDrawdown(book, equity);
    book.rows.push({
      id: book.rows.length + 1,
      side: "buy",
      date: when,
      price_usd: money(price),
      usd: money(usd),
      btc_qty: btc(qty),
      cost_usd: money(cost),
      cash_usd: money(book.cash_usd),
      holdings_btc: btc(book.btc),
      equity_usd: money(equity),
      drawdown_pct: pct(dd),
      simulated: true
    });
    return { ok: true, book: book, snapshot: snapshot(book, price) };
  }

  function sell(book, input) {
    input = input || {};
    var qty = num(input.btc);
    var price = num(input.price);
    if (!(qty > 0)) return fail("Type a simulated BTC amount greater than zero.");
    if (!(price > 0)) return fail("Type the BTC price you want to use.");
    if (qty > book.btc + 1e-12) return fail("Simulated BTC is not enough for that simulated sell.");
    var notional = qty * price;
    var cost = notional * ONE_WAY_COST;
    var proceeds = notional * (1 - ONE_WAY_COST);
    var when = dateOrToday(input.date);
    book.btc -= qty;
    book.cash_usd += proceeds;
    book.mark_price_usd = price;
    touchDates(book, when);
    var equity = equityOf(book, price);
    var dd = applyDrawdown(book, equity);
    book.rows.push({
      id: book.rows.length + 1,
      side: "sell",
      date: when,
      price_usd: money(price),
      usd: money(proceeds),
      btc_qty: btc(qty),
      cost_usd: money(cost),
      cash_usd: money(book.cash_usd),
      holdings_btc: btc(book.btc),
      equity_usd: money(equity),
      drawdown_pct: pct(dd),
      simulated: true
    });
    return { ok: true, book: book, snapshot: snapshot(book, price) };
  }

  function mark(book, input) {
    input = input || {};
    var price = num(input.price);
    if (!(price > 0)) return fail("Type the BTC price you want to use.");
    var when = dateOrToday(input.date);
    book.mark_price_usd = price;
    touchDates(book, when);
    var equity = equityOf(book, price);
    var dd = applyDrawdown(book, equity);
    book.rows.push({
      id: book.rows.length + 1,
      side: "mark",
      date: when,
      price_usd: money(price),
      usd: money(0),
      btc_qty: btc(0),
      cost_usd: money(0),
      cash_usd: money(book.cash_usd),
      holdings_btc: btc(book.btc),
      equity_usd: money(equity),
      drawdown_pct: pct(dd),
      simulated: true
    });
    return { ok: true, book: book, snapshot: snapshot(book, price) };
  }

  function toCsv(book) {
    var lines = [
      "id,side,date,price_usd,usd,btc_qty,cost_usd,cash_usd,holdings_btc,equity_usd,drawdown_pct,label"
    ];
    (book.rows || []).forEach(function (row) {
      lines.push([
        row.id,
        row.side,
        row.date,
        row.price_usd,
        row.usd,
        row.btc_qty,
        row.cost_usd,
        row.cash_usd,
        row.holdings_btc,
        row.equity_usd,
        row.drawdown_pct,
        "simulated"
      ].join(","));
    });
    lines.push("");
    lines.push("legend," + JSON.stringify(legend82(book)));
    return lines.join("\n") + "\n";
  }

  root.HalfacreTreasuryPractice = {
    sku: SKU,
    asset: ASSET,
    defaultCashUsd: DEFAULT_CASH_USD,
    roundTripCost: ROUND_TRIP_COST,
    oneWayCost: ONE_WAY_COST,
    costLabel: COST_LABEL,
    dataSource: DATA_SOURCE,
    banner81: BANNER_81,
    disclaimer83: DISCLAIMER_83,
    reset: reset,
    buy: buy,
    sell: sell,
    mark: mark,
    snapshot: snapshot,
    legend82: legend82,
    toCsv: toCsv
  };
})(typeof window !== "undefined" ? window : globalThis);

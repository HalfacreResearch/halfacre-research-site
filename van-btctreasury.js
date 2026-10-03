/**
 * BTCTreasuryBot practice-mode UI on the client page.
 * Unlocks only from van-btctreasury.php (verified PayPal capture).
 * Virtual book only. Does not call the exchange widget. Does not read keys.
 * Does not hook Grok. Does not invent signals.
 *
 * TODO(signal-module): leave empty until documented impersonal rules are ported.
 */
(function (global) {
  "use strict";

  var SKU = "btc-treasury-bot";
  var STATUS = "van-btctreasury.php";
  var lastUnlocked = false;
  var book = null;

  function trim(value) {
    return String(value == null ? "" : value).trim();
  }

  function token() {
    return (global.HalfacreSession && global.HalfacreSession.token)
      ? trim(global.HalfacreSession.token())
      : "";
  }

  function clientId() {
    return (global.HalfacreClient && global.HalfacreClient.id) || "";
  }

  function payHref() {
    var row = { id: SKU, sku: SKU };
    if (global.HalfacreSession && global.HalfacreSession.payUrl) {
      return global.HalfacreSession.payUrl(row);
    }
    var url = "pay.html?sku=" + encodeURIComponent(SKU);
    var id = clientId();
    var t = token();
    if (id) url += "&c=" + encodeURIComponent(id);
    if (t) url += "&t=" + encodeURIComponent(t);
    return url;
  }

  function statusUrl() {
    var id = clientId();
    var t = token();
    var url = STATUS;
    var join = "?";
    if (id) {
      url += join + "c=" + encodeURIComponent(id);
      join = "&";
    }
    if (t) url += join + "t=" + encodeURIComponent(t);
    return url;
  }

  function el(id) {
    return document.getElementById(id);
  }

  function show(node, on) {
    if (!node) return;
    node.hidden = !on;
  }

  function fieldValue(id) {
    var node = el(id);
    return node ? node.value : "";
  }

  function setText(id, text) {
    var node = el(id);
    if (node) node.textContent = text;
  }

  function engine() {
    return global.HalfacreTreasuryPractice || null;
  }

  function paintLegal() {
    var lib = engine();
    if (!lib) return;
    var banner = el("btctreasuryBanner");
    if (banner) banner.textContent = lib.banner81;
    var disc = el("btctreasuryDisclaimer83");
    if (disc) disc.textContent = lib.disclaimer83;
  }

  function paintLocked() {
    lastUnlocked = false;
    var pay = el("btctreasuryPay");
    if (pay) pay.href = payHref();
    show(el("btctreasuryLocked"), true);
    show(el("btctreasuryOpen"), false);
    paintLegal();
  }

  function paintOpen() {
    lastUnlocked = true;
    show(el("btctreasuryLocked"), false);
    show(el("btctreasuryOpen"), true);
    paintLegal();
    if (!book) {
      var lib = engine();
      var start = fieldValue("treasuryStartCash") || (lib ? lib.defaultCashUsd : 10000);
      book = lib ? lib.reset(start, fieldValue("treasuryTradeDate")) : null;
    }
    renderBook("");
  }

  function simNumberHtml(value) {
    return '<span class="sim-value">' + value + '</span> <span class="sim-tag">simulated</span>';
  }

  function fillFigure(valueId, legendId, value, legend) {
    var valueNode = el(valueId);
    if (valueNode) valueNode.innerHTML = simNumberHtml(value);
    var legendNode = el(legendId);
    if (legendNode) legendNode.textContent = legend;
  }

  function renderBook(note) {
    var lib = engine();
    var box = el("btctreasuryLedger");
    var noteNode = el("btctreasuryPracticeNote");
    if (noteNode) noteNode.textContent = note || "";
    if (!lib || !book) {
      if (box) box.textContent = "Practice engine is not loaded.";
      return;
    }
    var snap = lib.snapshot(book, book.mark_price_usd);
    var legend = lib.legend82(book);
    fillFigure("simCash", "simCashLegend", snap.cash_usd, legend);
    fillFigure("simBtc", "simBtcLegend", snap.btc, legend);
    fillFigure("simEquity", "simEquityLegend", snap.equity_usd, legend);
    fillFigure("simGain", "simGainLegend", snap.gain_or_loss_usd, legend);
    fillFigure("simDrawdown", "simDrawdownLegend", snap.largest_drawdown_pct + "%", legend);
    var ledgerLegend = el("simLedgerLegend");
    if (ledgerLegend) ledgerLegend.textContent = legend;

    if (!box) return;
    box.innerHTML = "";
    if (!book.rows.length) {
      box.textContent = "No simulated trades yet. Type a price and record a simulated buy or sell.";
      return;
    }
    var table = document.createElement("table");
    table.className = "treasury-table";
    table.innerHTML = "<thead><tr>" +
      "<th>Side</th><th>Date</th><th>Price you typed</th><th>USD</th>" +
      "<th>BTC</th><th>Simulated cost</th><th>Simulated cash</th>" +
      "<th>Simulated BTC</th><th>Simulated equity</th><th>Simulated drawdown</th></tr></thead>";
    var tb = document.createElement("tbody");
    book.rows.forEach(function (row) {
      var tr = document.createElement("tr");
      tr.innerHTML =
        "<td>" + row.side + "</td>" +
        "<td>" + row.date + "</td>" +
        "<td>" + simNumberHtml(row.price_usd || "—") + "</td>" +
        "<td>" + simNumberHtml(row.usd) + "</td>" +
        "<td>" + simNumberHtml(row.btc_qty) + "</td>" +
        "<td>" + simNumberHtml(row.cost_usd) + "</td>" +
        "<td>" + simNumberHtml(row.cash_usd) + "</td>" +
        "<td>" + simNumberHtml(row.holdings_btc) + "</td>" +
        "<td>" + simNumberHtml(row.equity_usd) + "</td>" +
        "<td>" + simNumberHtml(row.drawdown_pct + "%") + "</td>";
      tb.appendChild(tr);
    });
    table.appendChild(tb);
    box.appendChild(table);
  }

  function startClicked(e) {
    if (e) e.preventDefault();
    if (!lastUnlocked) return;
    var lib = engine();
    if (!lib) return;
    book = lib.reset(fieldValue("treasuryStartCash"), fieldValue("treasuryTradeDate"));
    renderBook("Starting simulated balance applied. Every figure below is simulated.");
  }

  function tradeInput() {
    return {
      usd: fieldValue("treasuryBuyUsd"),
      btc: fieldValue("treasurySellBtc"),
      price: fieldValue("treasuryPrice"),
      date: fieldValue("treasuryTradeDate")
    };
  }

  function buyClicked(e) {
    if (e) e.preventDefault();
    if (!lastUnlocked || !engine() || !book) return;
    var result = engine().buy(book, tradeInput());
    renderBook(result.ok ? "Simulated buy recorded." : result.error);
  }

  function sellClicked(e) {
    if (e) e.preventDefault();
    if (!lastUnlocked || !engine() || !book) return;
    var result = engine().sell(book, tradeInput());
    renderBook(result.ok ? "Simulated sell recorded." : result.error);
  }

  function markClicked(e) {
    if (e) e.preventDefault();
    if (!lastUnlocked || !engine() || !book) return;
    var result = engine().mark(book, tradeInput());
    renderBook(result.ok ? "Simulated mark updated." : result.error);
  }

  function downloadClicked(e) {
    if (e) e.preventDefault();
    if (!lastUnlocked || !engine() || !book) return;
    var csv = engine().toCsv(book);
    var blob = new Blob([csv], { type: "text/csv;charset=utf-8" });
    var url = URL.createObjectURL(blob);
    var a = document.createElement("a");
    a.href = url;
    a.download = "btc-treasury-simulated-ledger.csv";
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  function bindOne(id, handler) {
    var node = el(id);
    if (!node || node.getAttribute("data-bound")) return;
    node.setAttribute("data-bound", "1");
    node.addEventListener("click", handler);
  }

  function bind() {
    var form = el("btctreasuryForm");
    if (form && !form.getAttribute("data-bound")) {
      form.setAttribute("data-bound", "1");
      form.addEventListener("submit", function (e) {
        e.preventDefault();
      });
    }
    bindOne("treasuryApplyStart", startClicked);
    bindOne("treasuryBuy", buyClicked);
    bindOne("treasurySell", sellClicked);
    bindOne("treasuryMark", markClicked);
    bindOne("btctreasuryCsv", downloadClicked);
    var pay = el("btctreasuryPay");
    if (pay) pay.href = payHref();
    paintLegal();
  }

  function applyStatus(data) {
    if (data && data.ok && data.unlocked === true) {
      paintOpen();
      return;
    }
    paintLocked();
  }

  function refresh() {
    bind();
    var t = token();
    var id = clientId();
    if (!t || !id) {
      paintLocked();
      return Promise.resolve({ ok: true, unlocked: false });
    }
    return fetch(statusUrl(), { cache: "no-store" }).then(function (res) {
      return res.json();
    }).then(function (data) {
      applyStatus(data);
      return data;
    }).catch(function () {
      paintLocked();
      return { ok: false, unlocked: false };
    });
  }

  global.HalfacreTreasury = {
    sku: SKU,
    refresh: refresh,
    unlocked: function () { return lastUnlocked; }
  };
})(window);

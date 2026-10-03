/**
 * BTCTreasuryBot planner on the client page.
 * Unlocks only from van-btctreasury.php (verified PayPal capture).
 * Client types every number. No market-data fetches.
 */
(function (global) {
  "use strict";

  var SKU = "btc-treasury-bot";
  var STATUS = "van-btctreasury.php";
  var lastUnlocked = false;

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

  function paintLocked() {
    lastUnlocked = false;
    var pay = el("btctreasuryPay");
    if (pay) pay.href = payHref();
    show(el("btctreasuryLocked"), true);
    show(el("btctreasuryOpen"), false);
  }

  function paintOpen() {
    lastUnlocked = true;
    show(el("btctreasuryLocked"), false);
    show(el("btctreasuryOpen"), true);
  }

  function renderPlan(plan) {
    var box = el("btctreasurySchedule");
    var note = el("btctreasuryResultNote");
    if (note) note.textContent = plan.note;
    if (!box) return;
    box.innerHTML = "";
    if (!plan.rows || !plan.rows.length) {
      box.textContent = "Enter your own numbers and build a plan.";
      return;
    }
    var table = document.createElement("table");
    table.className = "sfox-table treasury-table";
    table.innerHTML = "<thead><tr>" +
      "<th>Period</th><th>Date</th><th>USD</th><th>Price you typed</th>" +
      "<th>BTC added</th><th>Holdings</th><th>Spent</th></tr></thead>";
    var tb = document.createElement("tbody");
    plan.rows.forEach(function (row) {
      var tr = document.createElement("tr");
      tr.innerHTML =
        "<td>" + row.period + "</td>" +
        "<td>" + row.date + "</td>" +
        "<td>" + row.usd_allocated + "</td>" +
        "<td>" + (row.btc_price_used || "—") + "</td>" +
        "<td>" + (row.btc_added || "—") + "</td>" +
        "<td>" + row.holdings_btc + "</td>" +
        "<td>" + row.spent_usd_cumulative + "</td>";
      tb.appendChild(tr);
    });
    table.appendChild(tb);
    box.appendChild(table);
    var sum = document.createElement("p");
    sum.className = "pay-note";
    sum.textContent = "Hypothetical end holdings: " + plan.holdings_end_btc +
      " BTC after " + plan.spent_usd + " USD allocated at the price you typed.";
    box.appendChild(sum);
  }

  function currentPlan() {
    if (!global.HalfacreTreasuryPlan) return null;
    return global.HalfacreTreasuryPlan.build({
      holdings_btc: fieldValue("treasuryHoldings"),
      budget_usd: fieldValue("treasuryBudget"),
      btc_price_usd: fieldValue("treasuryPrice"),
      periods: fieldValue("treasuryPeriods"),
      cadence: fieldValue("treasuryCadence"),
      start_date: fieldValue("treasuryStart")
    });
  }

  function buildClicked(e) {
    if (e) e.preventDefault();
    if (!lastUnlocked) return;
    var plan = currentPlan();
    if (plan) renderPlan(plan);
  }

  function downloadClicked(e) {
    if (e) e.preventDefault();
    if (!lastUnlocked || !global.HalfacreTreasuryPlan) return;
    var plan = currentPlan();
    var csv = global.HalfacreTreasuryPlan.toCsv(plan);
    var blob = new Blob([csv], { type: "text/csv;charset=utf-8" });
    var url = URL.createObjectURL(blob);
    var a = document.createElement("a");
    a.href = url;
    a.download = "btc-treasury-plan.csv";
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  function bind() {
    var form = el("btctreasuryForm");
    var csv = el("btctreasuryCsv");
    if (form && !form.getAttribute("data-bound")) {
      form.setAttribute("data-bound", "1");
      form.addEventListener("submit", buildClicked);
    }
    if (csv && !csv.getAttribute("data-bound")) {
      csv.setAttribute("data-bound", "1");
      csv.addEventListener("click", downloadClicked);
    }
    var pay = el("btctreasuryPay");
    if (pay) pay.href = payHref();
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

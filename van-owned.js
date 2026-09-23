/**
 * Per-SKU unlock for live Van modules.
 *
 * Starting state: none owned. Never gift or pre-unlock.
 * Unlock only after a $1.99 or $49.99 PayPal return for that live SKU.
 * Coming-soon intentions cannot unlock. Matthew cashes Van back privately.
 */
(function (global) {
  "use strict";

  var OWNED_KEY = "halfacre.van.owned.v1";
  var PENDING_KEY = "halfacre.van.pending.v1";
  var PENDING_MS = 6 * 60 * 60 * 1000;
  var ALLOWED = [1.99, 49.99];

  function trim(value) {
    return String(value == null ? "" : value).trim();
  }

  function readJson(key, fallback) {
    try {
      var raw = global.localStorage.getItem(key);
      if (!raw) {
        return fallback;
      }
      var data = JSON.parse(raw);
      return data && typeof data === "object" ? data : fallback;
    } catch (err) {
      return fallback;
    }
  }

  function writeJson(key, data) {
    global.localStorage.setItem(key, JSON.stringify(data));
  }

  function catalogRows() {
    return (global.HalfacrePay && global.HalfacrePay.products) || [];
  }

  function findRow(id) {
    var want = trim(id).toLowerCase();
    var rows = catalogRows();
    var i;
    for (i = 0; i < rows.length; i += 1) {
      if (trim(rows[i].id).toLowerCase() === want) {
        return rows[i];
      }
    }
    return null;
  }

  function isLiveSku(id) {
    var row = findRow(id);
    return Boolean(row && row.live && trim(id).toLowerCase() !== "codex");
  }

  function expectedAmount(id) {
    var row = findRow(id);
    if (!row) {
      return NaN;
    }
    return Number(row.price_usd);
  }

  function readOwned() {
    var data = readJson(OWNED_KEY, { skus: {} });
    if (!data.skus || typeof data.skus !== "object") {
      data.skus = {};
    }
    return data;
  }

  function owns(id) {
    var sku = trim(id);
    return Boolean(sku && readOwned().skus[sku]);
  }

  function ownedIds() {
    return Object.keys(readOwned().skus);
  }

  function ownedCount() {
    return ownedIds().length;
  }

  function receipt(id) {
    return readOwned().skus[trim(id)] || null;
  }

  function markPending(id) {
    var sku = trim(id);
    if (!isLiveSku(sku)) {
      return { ok: false, error: "Not a live module." };
    }
    writeJson(PENDING_KEY, { sku: sku, at: Date.now() });
    return { ok: true, sku: sku };
  }

  function takePending(id) {
    var sku = trim(id);
    var pending = readJson(PENDING_KEY, null);
    if (!pending || trim(pending.sku) !== sku) {
      return false;
    }
    if (!pending.at || Date.now() - Number(pending.at) > PENDING_MS) {
      global.localStorage.removeItem(PENDING_KEY);
      return false;
    }
    global.localStorage.removeItem(PENDING_KEY);
    return true;
  }

  function amountOk(value, sku) {
    if (value == null || value === "") {
      return true;
    }
    var n = Number(value);
    var expected = expectedAmount(sku);
    if (Number.isFinite(expected)) {
      return n === expected;
    }
    return ALLOWED.indexOf(n) !== -1;
  }

  function paypalEvidence(query, sku) {
    var tx = trim(query.get("tx") || query.get("txn_id"));
    var st = trim(query.get("st") || query.get("payment_status"));
    var amt = query.get("amt") || query.get("mc_gross") || query.get("amount");
    var txOk = tx.length >= 8;
    var stOk = /^completed$/i.test(st);
    var listed = expectedAmount(sku);
    return {
      ok: (txOk || stOk) && amountOk(amt, sku),
      tx: tx,
      amount: amountOk(amt, sku)
        ? String(Number.isFinite(listed) ? listed : 1.99)
        : ""
    };
  }

  function unlock(id, meta) {
    var sku = trim(id);
    if (!isLiveSku(sku)) {
      return { ok: false, error: "Not a live module." };
    }
    if (owns(sku)) {
      return { ok: true, already: true, sku: sku, receipt: receipt(sku) };
    }
    var listed = expectedAmount(sku);
    var store = readOwned();
    store.skus[sku] = {
      amount: (meta && meta.amount) || String(Number.isFinite(listed) ? listed : 1.99),
      tx: (meta && meta.tx) || "",
      paidAt: Date.now()
    };
    writeJson(OWNED_KEY, store);
    return { ok: true, already: false, sku: sku, receipt: store.skus[sku] };
  }

  function claimFromSearch(search) {
    var query = new URLSearchParams(typeof search === "string" ? search : global.location.search);
    var sku = trim(
      query.get("paid") ||
      query.get("item_number") ||
      query.get("cm") ||
      query.get("custom")
    );
    if (!sku) {
      return { ok: false, skipped: true };
    }
    if (!isLiveSku(sku)) {
      return { ok: false, error: "That return is not a live paid module." };
    }
    if (!amountOk(query.get("amt") || query.get("mc_gross") || query.get("amount"), sku)) {
      return { ok: false, error: "Return amount did not match that module’s $1.99 or $49.99 price." };
    }
    var evidence = paypalEvidence(query, sku);
    var pending = takePending(sku);
    if (!evidence.ok && !pending) {
      return { ok: false, error: "No PayPal return for that module." };
    }
    return unlock(sku, {
      tx: evidence.tx,
      amount: evidence.amount || String(expectedAmount(sku))
    });
  }

  function returnUrl(sku) {
    var page = new URL("van.html", global.location.href);
    page.search = "";
    page.hash = "";
    page.searchParams.set("paid", sku);
    return page.href;
  }

  function cancelUrl(sku) {
    var page = new URL("pay.html", global.location.href);
    page.search = "";
    page.hash = "";
    page.searchParams.set("sku", sku);
    page.searchParams.set("id", sku);
    return page.href;
  }

  global.VanOwned = {
    owns: owns,
    ownedIds: ownedIds,
    ownedCount: ownedCount,
    receipt: receipt,
    markPending: markPending,
    unlock: unlock,
    claimFromSearch: claimFromSearch,
    returnUrl: returnUrl,
    cancelUrl: cancelUrl,
    catalogCount: function () {
      return catalogRows().length;
    },
    liveCount: function () {
      return catalogRows().filter(function (row) {
        return row.live;
      }).length;
    }
  };
})(window);

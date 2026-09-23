/**
 * Per-SKU unlock for Van’s seven modules.
 *
 * Starting state: none owned. Never gift or pre-unlock.
 * Unlock only after a $1.99 PayPal return for that SKU.
 * Matthew cashes Van back privately off-app.
 */
(function (global) {
  "use strict";

  var OWNED_KEY = "halfacre.van.owned.v1";
  var PENDING_KEY = "halfacre.van.pending.v1";
  var PENDING_MS = 6 * 60 * 60 * 1000;
  var PAID_USD = 1.99;

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

  function catalogIds() {
    var rows = (global.HalfacrePay && global.HalfacrePay.products) || [];
    return rows.map(function (row) {
      return trim(row.id);
    }).filter(Boolean);
  }

  function isCatalogSku(id) {
    var want = trim(id).toLowerCase();
    if (!want || want === "codex") {
      return false;
    }
    return catalogIds().some(function (item) {
      return item.toLowerCase() === want;
    });
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
    if (!isCatalogSku(sku)) {
      return { ok: false, error: "Unknown module." };
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

  function amountOk(value) {
    if (value == null || value === "") {
      return true;
    }
    return Number(value) === PAID_USD;
  }

  function paypalEvidence(query) {
    var tx = trim(query.get("tx") || query.get("txn_id"));
    var st = trim(query.get("st") || query.get("payment_status"));
    var amt = query.get("amt") || query.get("mc_gross") || query.get("amount");
    var txOk = tx.length >= 8;
    var stOk = /^completed$/i.test(st);
    return {
      ok: (txOk || stOk) && amountOk(amt),
      tx: tx,
      amount: amountOk(amt) ? String(PAID_USD) : ""
    };
  }

  function unlock(id, meta) {
    var sku = trim(id);
    if (!isCatalogSku(sku)) {
      return { ok: false, error: "Unknown module." };
    }
    if (owns(sku)) {
      return { ok: true, already: true, sku: sku, receipt: receipt(sku) };
    }
    var store = readOwned();
    store.skus[sku] = {
      amount: (meta && meta.amount) || String(PAID_USD),
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
    if (!isCatalogSku(sku)) {
      return { ok: false, error: "That return is not a single paid module." };
    }
    if (!amountOk(query.get("amt") || query.get("mc_gross") || query.get("amount"))) {
      return { ok: false, error: "Return amount was not $1.99." };
    }
    var evidence = paypalEvidence(query);
    var pending = takePending(sku);
    if (!evidence.ok && !pending) {
      return { ok: false, error: "No PayPal return for that module." };
    }
    return unlock(sku, { tx: evidence.tx, amount: String(PAID_USD) });
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
      return catalogIds().length;
    }
  };
})(window);

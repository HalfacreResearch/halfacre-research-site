/**
 * User-scoped unlock list for Charlie Van Halfacre.
 *
 * userId: charlie-van-halfacre (hivemind client slot + pay identity
 * cvhalfacre@msn.com). Unlock list is source of truth for what the
 * avatar knows. Downloads are a re-fetch.
 *
 * Storage:
 *  1) van-unlocks.php → van-unlocks.store.json on Hostinger (durable)
 *  2) localStorage halfacre.entitlements.v1.charlie-van-halfacre
 *  3) mirrors moduleUnlocks onto hivemind.client.charlie-van-halfacre
 *
 * Starting owned is empty. PayPal success appends moduleId.
 * Coming-soon / not-ready SKUs cannot unlock.
 */
(function (global) {
  "use strict";

  var USER_ID = "charlie-van-halfacre";
  var HIVEMIND_KEY = "hivemind.client.charlie-van-halfacre";
  var OWNED_KEY = "halfacre.entitlements.v1." + USER_ID;
  var LEGACY_KEY = "halfacre.van.owned.v1";
  var PENDING_KEY = "halfacre.van.pending.v1";
  var PENDING_MS = 6 * 60 * 60 * 1000;
  var UNLOCK_API = "van-unlocks.php";
  var ALLOWED = [4.99, 29.99, 149];
  var IDENTITY = {
    userId: USER_ID,
    display_name: "Charlie Van Halfacre",
    email: "cvhalfacre@msn.com",
    phone: "601-408-8342",
    hivemind_client_id: USER_ID,
    pay_identity: "cvhalfacre@msn.com"
  };

  var cache = { skus: {} };
  var loaded = false;
  var listeners = [];

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
    if (want === "codex" || want === "codex-buy" || want === "codex-sell") {
      want = "btc-treasury-bot";
    }
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
    return Boolean(row && row.live);
  }

  function expectedAmount(id) {
    var row = findRow(id);
    if (!row) {
      return NaN;
    }
    return Number(row.price_usd);
  }

  function migrateLegacy() {
    var current = readJson(OWNED_KEY, null);
    if (current && current.skus && typeof current.skus === "object") {
      return current;
    }
    var legacy = readJson(LEGACY_KEY, null);
    if (legacy && legacy.skus && typeof legacy.skus === "object") {
      var moved = {
        userId: USER_ID,
        identity: IDENTITY,
        skus: legacy.skus
      };
      writeJson(OWNED_KEY, moved);
      return moved;
    }
    return { userId: USER_ID, identity: IDENTITY, skus: {} };
  }

  function persist() {
    cache.userId = USER_ID;
    cache.identity = IDENTITY;
    writeJson(OWNED_KEY, cache);
    try {
      var rec = readJson(HIVEMIND_KEY, {});
      if (!rec || typeof rec !== "object") {
        rec = {};
      }
      rec.name = rec.name || IDENTITY.display_name;
      rec.email = rec.email || IDENTITY.email;
      rec.phone = rec.phone || IDENTITY.phone;
      rec.moduleUnlocks = ownedIds();
      writeJson(HIVEMIND_KEY, rec);
    } catch (err) {
      /* hivemind mirror is best-effort */
    }
    listeners.forEach(function (fn) {
      try {
        fn(ownedModules());
      } catch (ignored) {
        /* listener errors must not break unlock */
      }
    });
  }

  function mergeSkus(incoming) {
    if (!incoming || typeof incoming !== "object") {
      return;
    }
    Object.keys(incoming).forEach(function (sku) {
      if (!cache.skus[sku] && incoming[sku]) {
        cache.skus[sku] = incoming[sku];
      }
    });
  }

  function owns(id) {
    var sku = trim(id);
    return Boolean(sku && cache.skus[sku]);
  }

  function ownedIds() {
    return Object.keys(cache.skus);
  }

  function ownedCount() {
    return ownedIds().length;
  }

  function receipt(id) {
    return cache.skus[trim(id)] || null;
  }

  function ownedModules() {
    return ownedIds().map(function (id) {
      var row = findRow(id) || {};
      var rec = cache.skus[id] || {};
      var fulfill = row.fulfillment || {};
      return {
        id: id,
        name: row.avatar_upgrade_label || row.product || row.name || id,
        price: row.price_usd,
        kind: row.kind || "",
        tier: row.tier || "",
        avatar_knowledge: fulfill.avatar_knowledge || row.description || "",
        download_href: downloadUrl(id),
        receipt: rec
      };
    });
  }

  function avatarContext() {
    var rows = ownedModules();
    if (!rows.length) {
      return {
        userId: USER_ID,
        powerups: [],
        text: "Avatar power-ups: none yet. Van starts with an empty unlock list. Live modules he pays for stay on this account and are injected here before chat."
      };
    }
    return {
      userId: USER_ID,
      powerups: rows,
      text: "Avatar power-ups (persisted unlock list for " + IDENTITY.display_name + "): " +
        rows.map(function (row) {
          return row.name + " [" + row.id + "]" + (row.avatar_knowledge ? " — " + row.avatar_knowledge : "");
        }).join(" | ")
    };
  }

  function downloadUrl(id) {
    var row = findRow(id);
    var fulfill = (row && row.fulfillment) || {};
    if (fulfill.download_href) {
      return fulfill.download_href + (fulfill.download_href.indexOf("?") >= 0 ? "&" : "?") +
        "userId=" + encodeURIComponent(USER_ID);
    }
    if (fulfill.receipt_href) {
      return fulfill.receipt_href;
    }
    return "van-download.php?sku=" + encodeURIComponent(id) + "&userId=" + encodeURIComponent(USER_ID);
  }

  function markPending(id) {
    var sku = trim(id);
    if (!isLiveSku(sku)) {
      return { ok: false, error: "Not a live module." };
    }
    writeJson(PENDING_KEY, { sku: sku, at: Date.now(), userId: USER_ID });
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
        ? String(Number.isFinite(listed) ? listed : 4.99)
        : ""
    };
  }

  function postUnlock(sku, meta) {
    var body = {
      userId: USER_ID,
      email: IDENTITY.email,
      moduleId: sku,
      amount: (meta && meta.amount) || String(expectedAmount(sku)),
      tx: (meta && meta.tx) || "",
      paidAt: (meta && meta.paidAt) || Date.now()
    };
    return fetch(UNLOCK_API, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
      cache: "no-store"
    }).then(function (res) {
      return res.json().then(function (data) {
        return { ok: res.ok && data && data.ok, data: data };
      });
    }).catch(function () {
      return { ok: false, data: null };
    });
  }

  function fetchRemote() {
    var url = UNLOCK_API + "?userId=" + encodeURIComponent(USER_ID) +
      "&email=" + encodeURIComponent(IDENTITY.email);
    return fetch(url, { cache: "no-store" }).then(function (res) {
      if (!res.ok) {
        throw new Error("unlock store unavailable");
      }
      return res.json();
    }).then(function (data) {
      if (data && data.ok && data.modules) {
        mergeSkus(data.modules);
        persist();
      }
      return cache;
    }).catch(function () {
      return cache;
    });
  }

  function unlock(id, meta) {
    var sku = trim(id);
    if (!isLiveSku(sku)) {
      return { ok: false, error: "Not a live module." };
    }
    if (owns(sku)) {
      return { ok: true, already: true, sku: sku, receipt: receipt(sku), userId: USER_ID };
    }
    var listed = expectedAmount(sku);
    cache.skus[sku] = {
      amount: (meta && meta.amount) || String(Number.isFinite(listed) ? listed : 4.99),
      tx: (meta && meta.tx) || "",
      paidAt: Date.now(),
      userId: USER_ID
    };
    persist();
    postUnlock(sku, cache.skus[sku]);
    return { ok: true, already: false, sku: sku, receipt: cache.skus[sku], userId: USER_ID };
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
      return { ok: false, error: "Return amount did not match that module’s $4.99 / $29.99 / $149 price." };
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

  function load() {
    cache = migrateLegacy();
    if (!cache.skus || typeof cache.skus !== "object") {
      cache.skus = {};
    }
    persist();
    loaded = true;
    return fetchRemote().then(function () {
      return {
        userId: USER_ID,
        moduleIds: ownedIds(),
        modules: ownedModules()
      };
    });
  }

  function onChange(fn) {
    if (typeof fn === "function") {
      listeners.push(fn);
    }
  }

  cache = migrateLegacy();

  global.VanOwned = {
    userId: USER_ID,
    identity: IDENTITY,
    load: load,
    owns: owns,
    ownedIds: ownedIds,
    ownedCount: ownedCount,
    ownedModules: ownedModules,
    avatarContext: avatarContext,
    receipt: receipt,
    downloadUrl: downloadUrl,
    markPending: markPending,
    unlock: unlock,
    claimFromSearch: claimFromSearch,
    returnUrl: returnUrl,
    cancelUrl: cancelUrl,
    onChange: onChange,
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

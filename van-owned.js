/**
 * Per-client unlock list. Van is charlie-van-halfacre.
 * Other clients use their page id so purchases do not mix.
 */
(function (global) {
  "use strict";

  var VAN_ID = "charlie-van-halfacre";
  var VAN_PAGE = "charley-van-halfacre";
  var LEGACY_KEY = "halfacre.van.owned.v1";
  var PENDING_MS = 6 * 60 * 60 * 1000;
  var MEMORY_API = "client-memory.php";
  var ALLOWED = [4.99, 29.99, 149];

  var cache = { skus: {} };
  var listeners = [];

  function trim(value) {
    return String(value == null ? "" : value).trim();
  }

  function identity() {
    var c = global.HalfacreClient;
    if (c && c.id && c.id !== VAN_PAGE && c.id !== VAN_ID) {
      var id = trim(c.userId || c.id);
      return {
        userId: id,
        display_name: trim(c.name) || "Client",
        email: trim(c.email),
        phone: "",
        hivemind_client_id: id,
        pay_identity: trim(c.email) || id
      };
    }
    return {
      userId: VAN_ID,
      display_name: "Charlie Van Halfacre",
      email: "",
      phone: "",
      hivemind_client_id: VAN_ID,
      pay_identity: ""
    };
  }

  function userId() {
    return identity().userId;
  }

  function ownedKey() {
    return "halfacre.entitlements.v1." + userId();
  }

  function pendingKey() {
    return "halfacre.pending.v1." + userId();
  }

  function hivemindKey() {
    return "hivemind.client." + userId();
  }

  function readJson(key, fallback) {
    try {
      var raw = global.localStorage.getItem(key);
      if (!raw) return fallback;
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
    if (want === "codex-buy" || want === "codex-sell") want = "btc-treasury-bot";
    var rows = catalogRows();
    var i;
    for (i = 0; i < rows.length; i += 1) {
      if (trim(rows[i].id).toLowerCase() === want) return rows[i];
    }
    return null;
  }

  function isLiveSku(id) {
    var row = findRow(id);
    return Boolean(row && row.live);
  }

  function expectedAmount(id) {
    var row = findRow(id);
    return row ? Number(row.price_usd) : NaN;
  }

  function migrateLegacy() {
    var current = readJson(ownedKey(), null);
    if (current && current.skus && typeof current.skus === "object") return current;
    if (userId() === VAN_ID) {
      var legacy = readJson(LEGACY_KEY, null);
      if (legacy && legacy.skus && typeof legacy.skus === "object") {
        var moved = { userId: VAN_ID, identity: identity(), skus: legacy.skus };
        writeJson(ownedKey(), moved);
        return moved;
      }
    }
    return { userId: userId(), identity: identity(), skus: {} };
  }

  function persist() {
    var who = identity();
    cache.userId = who.userId;
    cache.identity = who;
    writeJson(ownedKey(), cache);
    try {
      var rec = readJson(hivemindKey(), {});
      if (!rec || typeof rec !== "object") rec = {};
      rec.name = rec.name || who.display_name;
      rec.email = rec.email || who.email;
      rec.moduleUnlocks = ownedIds();
      writeJson(hivemindKey(), rec);
    } catch (err) {}
    listeners.forEach(function (fn) {
      try { fn(ownedModules()); } catch (ignored) {}
    });
  }

  function mergeSkus(incoming) {
    if (!incoming || typeof incoming !== "object") return;
    Object.keys(incoming).forEach(function (sku) {
      if (!cache.skus[sku] && incoming[sku]) cache.skus[sku] = incoming[sku];
    });
  }

  function owns(id) {
    var sku = trim(id);
    return Boolean(sku && cache.skus[sku]);
  }

  function ownedIds() {
    return Object.keys(cache.skus);
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
    var who = identity();
    var rows = ownedModules();
    if (!rows.length) {
      return {
        userId: who.userId,
        powerups: [],
        text: "Avatar power-ups: none yet. " + who.display_name + " starts with an empty unlock list."
      };
    }
    return {
      userId: who.userId,
      powerups: rows,
      text: "Avatar power-ups for " + who.display_name + ": " +
        rows.map(function (row) {
          return row.name + " [" + row.id + "]" + (row.avatar_knowledge ? " — " + row.avatar_knowledge : "");
        }).join(" | ")
    };
  }

  function pageToken() {
    return (global.HalfacreSession && global.HalfacreSession.token)
      ? trim(global.HalfacreSession.token())
      : "";
  }

  function downloadUrl(id) {
    var sku = trim(id);
    var who = identity();
    var url = "van-download.php?sku=" + encodeURIComponent(sku);
    if (who.userId) url += "&c=" + encodeURIComponent(who.hivemind_client_id || who.userId);
    var t = pageToken();
    if (t) url += "&t=" + encodeURIComponent(t);
    return url;
  }

  function markPending(id) {
    var sku = trim(id);
    if (!isLiveSku(sku)) return { ok: false, error: "Not a live module." };
    writeJson(pendingKey(), { sku: sku, at: Date.now(), userId: userId() });
    return { ok: true, sku: sku };
  }

  function takePending(id) {
    var sku = trim(id);
    var pending = readJson(pendingKey(), null);
    if (!pending || trim(pending.sku) !== sku) return false;
    if (!pending.at || Date.now() - Number(pending.at) > PENDING_MS) {
      global.localStorage.removeItem(pendingKey());
      return false;
    }
    global.localStorage.removeItem(pendingKey());
    return true;
  }

  function amountOk(value, sku) {
    if (value == null || value === "") return true;
    var n = Number(value);
    var expected = expectedAmount(sku);
    if (Number.isFinite(expected)) return n === expected;
    return ALLOWED.indexOf(n) !== -1;
  }

  function paypalEvidence(query, sku) {
    var tx = trim(query.get("tx") || query.get("txn_id"));
    var st = trim(query.get("st") || query.get("payment_status"));
    var amt = query.get("amt") || query.get("mc_gross") || query.get("amount");
    var listed = expectedAmount(sku);
    return {
      ok: (tx.length >= 8 || /^completed$/i.test(st)) && amountOk(amt, sku),
      tx: tx,
      amount: amountOk(amt, sku) ? String(Number.isFinite(listed) ? listed : 4.99) : ""
    };
  }

  function fetchRemote() {
    var who = identity();
    var id = (global.HalfacreClient && global.HalfacreClient.id) || who.hivemind_client_id || "";
    var t = pageToken();
    if (!id || !t) {
      cache.skus = {};
      persist();
      return Promise.resolve(cache);
    }
    var url = MEMORY_API + "?c=" + encodeURIComponent(id) + "&t=" + encodeURIComponent(t);
    return fetch(url, { cache: "no-store" }).then(function (res) {
      if (!res.ok) throw new Error("purchase store unavailable");
      return res.json();
    }).then(function (data) {
      cache.skus = {};
      if (data && data.ok && Array.isArray(data.purchases)) {
        data.purchases.forEach(function (row) {
          if (!row || !row.id) return;
          cache.skus[row.id] = {
            amount: row.amount || "",
            tx: row.capture_id || "",
            paidAt: row.at || 0,
            verified: true
          };
        });
      }
      persist();
      return cache;
    }).catch(function () {
      cache.skus = {};
      persist();
      return cache;
    });
  }

  function receipt(id) {
    return cache.skus[trim(id)] || null;
  }

  function unlock() {
    return { ok: false, error: "Purchases are recorded after PayPal capture only." };
  }

  function claimFromSearch() {
    return { ok: false, skipped: true };
  }

  function returnUrl(sku) {
    var talk = (global.HalfacreSession && global.HalfacreSession.talkUrl)
      ? global.HalfacreSession.talkUrl()
      : "page.html";
    var page = new URL(talk, global.location.href);
    page.searchParams.set("paid", sku);
    return page.href;
  }

  function cancelUrl(sku) {
    var page = new URL("pay.html", global.location.href);
    page.search = "";
    page.searchParams.set("sku", sku);
    page.searchParams.set("id", sku);
    if (global.HalfacreClient && global.HalfacreClient.id) {
      page.searchParams.set("c", global.HalfacreClient.id);
    }
    return page.href;
  }

  function load() {
    cache = { userId: userId(), identity: identity(), skus: {} };
    persist();
    return fetchRemote().then(function () {
      return { userId: userId(), moduleIds: ownedIds(), modules: ownedModules() };
    });
  }

  function onChange(fn) {
    if (typeof fn === "function") listeners.push(fn);
  }

  cache = { skus: {} };

  global.VanOwned = {
    get userId() { return userId(); },
    get identity() { return identity(); },
    load: load,
    owns: owns,
    ownedIds: ownedIds,
    ownedCount: function () { return ownedIds().length; },
    ownedModules: ownedModules,
    avatarContext: avatarContext,
    receipt: receipt,
    downloadUrl: downloadUrl,
    markPending: markPending,
    unlock: unlock,
    claimFromSearch: claimFromSearch,
    returnUrl: returnUrl,
    cancelUrl: cancelUrl,
    onChange: onChange
  };
})(window);

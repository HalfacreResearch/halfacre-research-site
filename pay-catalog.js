/**
 * Loads van-products.json — the one file Matthew/staff edit.
 *
 * TWO LAYERS: full intentions (everything we intend to sell) + live
 * (already selling). Only live SKUs are clickable to PayPal.
 *
 * PRICE LOCK: research/data $4.99; theme packs $29.99; assembled
 * trading systems $149 (including TaxAttorneyBot).
 * Live research = pay + download + lasting avatar unlock.
 * Codex Buy and Codex Sell are separate $149 systems. Never merge.
 * Research unlock = full Bitcoin/macro stack Codex considers.
 * Do not shrink the visible catalog to seven SKUs.
 *
 * Do not pre-unlock or gift modules. Matthew reimburses Van off-app.
 * Historical Macro $99 / ETF pack NCP links are forbidden. $149 is a
 * valid assembled-system price — never charge it with the old ETF NCP.
 *
 * PayPal is Day-1. Card/guest is native PayPal Checkout.
 * Square (Block) is an approved second rail — config stub only until SDK.
 * Live path: NCP at the listed $4.99 / $29.99 / $149 + matching
 * paypal_confirms_usd, or dynamic _xclick when paypal_business is set.
 * No Stripe.
 */
(function (global) {
  "use strict";

  var PAGE = "pay.html";
  var SRC = "van-products.json";
  var RESEARCH_USD = 4.99;
  var PACK_USD = 29.99;
  var SYSTEM_USD = 149;
  var TAX_USD = 149;
  var BASIC_USD = RESEARCH_USD;
  var ADVANCED_USD = SYSTEM_USD;
  var ALLOWED = [RESEARCH_USD, PACK_USD, SYSTEM_USD, TAX_USD];
  var FORBIDDEN = [99];
  var cache = null;

  function trim(value) {
    return String(value == null ? "" : value).trim();
  }

  function asNumber(value) {
    var n = Number(value);
    return Number.isFinite(n) ? n : NaN;
  }

  function asStringList(value) {
    if (!value) {
      return [];
    }
    if (Array.isArray(value)) {
      return value.map(trim).filter(Boolean);
    }
    return [trim(value)].filter(Boolean);
  }

  function isForbiddenAmount(value) {
    var n = asNumber(value);
    return FORBIDDEN.indexOf(n) !== -1;
  }

  function isPaidAmount(value) {
    return ALLOWED.indexOf(asNumber(value)) !== -1;
  }

  function isLiveRow(row) {
    if (!row) {
      return false;
    }
    if (row.live === true) {
      return true;
    }
    return trim(row.status) === "live";
  }

  function defaultPrice(row) {
    var kind = trim(row.kind);
    var tier = trim(row.tier);
    if (kind === "tax" || tier === "tax") {
      return TAX_USD;
    }
    if (kind === "pack" || tier === "pack") {
      return PACK_USD;
    }
    if (tier === "advanced" || kind === "protocol" || kind === "system") {
      return SYSTEM_USD;
    }
    return RESEARCH_USD;
  }

  function normalizePaid(row) {
    var price = row.price_usd;
    if (price === null || price === undefined || price === "") {
      price = defaultPrice(row);
    }
    var live = isLiveRow(row);
    var departments = asStringList(row.departments);
    var department = trim(row.department) || departments[0] || "";
    if (!departments.length && department) {
      departments = [department];
    }
    return {
      id: trim(row.id),
      sku: trim(row.id),
      product: trim(row.name),
      name: trim(row.name),
      description: trim(row.description),
      amount: String(price),
      price_usd: price,
      currency: "USD",
      tier: trim(row.tier) || (asNumber(price) === SYSTEM_USD ? "advanced" : (asNumber(price) === TAX_USD ? "tax" : (asNumber(price) === PACK_USD ? "pack" : "basic"))),
      kind: trim(row.kind) || "research",
      pair: trim(row.pair),
      department: department,
      departments: departments,
      alias: trim(row.alias),
      paypal_link_or_button_id: trim(row.paypal_link_or_button_id),
      paypal_confirms_usd: row.paypal_confirms_usd == null ? null : asNumber(row.paypal_confirms_usd),
      avatar_upgrade_label: trim(row.avatar_upgrade_label) || trim(row.name),
      status: live ? "live" : (trim(row.status) || "coming_soon"),
      live: live,
      fulfillment: row.fulfillment && typeof row.fulfillment === "object" ? row.fulfillment : null,
      avatar_knowledge: row.fulfillment && row.fulfillment.avatar_knowledge
        ? trim(row.fulfillment.avatar_knowledge)
        : "",
      download_href: row.fulfillment && row.fulfillment.download_href
        ? trim(row.fulfillment.download_href)
        : "",
      ui_href: trim(row.ui_href) || (row.fulfillment && row.fulfillment.ui_href) || "",
      van_unlocked: false,
      free: Boolean(row.free) || asNumber(price) === 0
    };
  }

  function paypalKind(value) {
    var v = trim(value);
    var ncp;
    if (!v) {
      return { kind: "empty", value: "" };
    }
    if (/paypal\.com\/ncp\/payment\/PLB-/i.test(v) || /^PLB-[A-Z0-9]+$/i.test(v)) {
      ncp = v.indexOf("http") === 0 ? v : "https://www.paypal.com/ncp/payment/" + v;
      if (/PLB-NGZRXTQA93RE|PLB-DN2KVZRLCUML/i.test(ncp)) {
        return { kind: "pack-ncp", value: ncp };
      }
      return { kind: "ncp", value: ncp };
    }
    if (/^https?:\/\//i.test(v)) {
      return { kind: "url", value: v };
    }
    if (/^[A-Za-z0-9_-]{8,24}$/.test(v)) {
      return { kind: "button", value: v };
    }
    return { kind: "unknown", value: v };
  }

  function findPaid(id) {
    var want = trim(id).toLowerCase();
    var list = (cache && cache.paid) || [];
    var i;
    if (!want) {
      return null;
    }
    for (i = 0; i < list.length; i += 1) {
      if (list[i].id.toLowerCase() === want) {
        return list[i];
      }
    }
    return null;
  }

  function fromQuery(search) {
    var q = new URLSearchParams(typeof search === "string" ? search : global.location.search);
    return {
      id: trim(q.get("id") || q.get("sku")),
      product: trim(q.get("product") || q.get("name")),
      name: trim(q.get("name") || q.get("product")),
      sku: trim(q.get("sku") || q.get("id")),
      amount: trim(q.get("amount")),
      currency: trim(q.get("currency")) || "USD",
      description: trim(q.get("description"))
    };
  }

  function resolve(partial) {
    var id = trim(partial && (partial.id || partial.sku));
    if (id && /^(codex-buy|codex-sell)$/i.test(id)) {
      id = "btc-treasury-bot";
      if (partial) {
        partial = {
          id: "btc-treasury-bot",
          sku: "btc-treasury-bot",
          product: partial.product || "BTCTreasuryBot",
          name: partial.name || "BTCTreasuryBot",
          amount: partial.amount || String(SYSTEM_USD),
          description: partial.description || ""
        };
      }
    }
    var known = findPaid(id);
    var amount = trim(partial && partial.amount);
    if (!amount && known) {
      amount = known.amount;
    }
    if (!amount) {
      amount = known ? String(known.price_usd) : String(RESEARCH_USD);
    }
    if (known && known.free) {
      amount = "0";
    }
    var forbidden = isForbiddenAmount(amount);
    var live = known ? known.live : false;
    return {
      id: id || (known && known.id) || "",
      sku: trim(partial && partial.sku) || (known && known.sku) || id,
      product: trim(partial && (partial.product || partial.name)) || (known && known.product) || "",
      amount: forbidden ? "" : amount,
      currency: "USD",
      description: trim(partial && partial.description) || (known && known.description) || "",
      tier: (known && known.tier) || (asNumber(amount) === ADVANCED_USD ? "advanced" : "basic"),
      kind: (known && known.kind) || "",
      pair: (known && known.pair) || "",
      department: (known && known.department) || "",
      departments: (known && known.departments) || [],
      paypal_link_or_button_id: (known && known.paypal_link_or_button_id) || "",
      paypal_confirms_usd: known ? known.paypal_confirms_usd : null,
      avatar_upgrade_label: (known && known.avatar_upgrade_label) || "",
      fulfillment: (known && known.fulfillment) || null,
      avatar_knowledge: (known && known.avatar_knowledge) || "",
      download_href: (known && known.download_href) || "",
      ui_href: (known && known.ui_href) || "",
      status: (known && known.status) || "",
      live: live,
      van_unlocked: false,
      free: Boolean(known && known.free),
      split: false,
      forbidden: forbidden,
      known: Boolean(known)
    };
  }

  function buildUrl(partial) {
    var line = resolve(partial);
    var q = new URLSearchParams();
    if (line.id) {
      q.set("id", line.id);
    }
    if (line.product) {
      q.set("product", line.product);
      q.set("name", line.product);
    }
    if (line.sku) {
      q.set("sku", line.sku);
    }
    if (line.amount && !line.forbidden && !line.free && line.live) {
      q.set("amount", line.amount);
    }
    q.set("currency", "USD");
    if (line.description) {
      q.set("description", line.description);
    }
    return PAGE + "?" + q.toString();
  }

  function formatMoney(amount) {
    if (amount === "" || amount == null) {
      return "Price blocked";
    }
    var n = asNumber(amount);
    if (!Number.isFinite(n)) {
      return trim(amount);
    }
    if (n === 0) {
      return "Free";
    }
    return "$" + n.toFixed(2);
  }

  function listedPrice(line) {
    return asNumber(line && line.amount);
  }

  function checkoutPlan(line) {
    var pay = (cache && cache.pay) || {};
    var business = trim(pay.paypal_business);
    var price = listedPrice(line);
    var priceText = Number.isFinite(price) ? price.toFixed(2) : "";
    if (!line || line.free) {
      return { ok: false, reason: "No PayPal on a free line." };
    }
    if (line.split) {
      return { ok: false, reason: "Codex is now BTCTreasuryBot — one $149 assembled bot." };
    }
    if (!line.live) {
      return { ok: false, reason: "Coming soon / Not ready — data or fulfillment is not built yet. This SKU is not for sale." };
    }
    if (line.forbidden || isForbiddenAmount(line.amount)) {
      return { ok: false, reason: "Blocked: $99 is the historical Macro pack amount and is not a Van price. Allowed: $4.99 / $29.99 / $149." };
    }
    if (!isPaidAmount(line.amount)) {
      return { ok: false, reason: "Van paid amounts are $4.99 (research), $29.99 (theme packs), or $149 (assembled bots, including TaxAttorneyBot). This amount cannot be charged here." };
    }
    if (business) {
      return {
        ok: true,
        kind: "dynamic",
        business: business,
        amount: priceText,
        item_name: line.product || line.sku || "Halfacre module",
        item_number: line.sku || line.id,
        custom: line.sku || line.id
      };
    }
    var kind = paypalKind(line.paypal_link_or_button_id);
    if (kind.kind === "pack-ncp") {
      return {
        ok: false,
        reason: "Blocked: that PayPal NCP link is the historical Macro $99 or ETF pack SoT. Mint a new NCP at $4.99, $29.99, or $149. Do not reuse those pack IDs."
      };
    }
    if (kind.kind !== "empty" && line.paypal_confirms_usd === price) {
      return { ok: true, kind: kind.kind, value: kind.value, amount: priceText };
    }
    if (kind.kind !== "empty") {
      return {
        ok: false,
        reason: "A PayPal link is set, but paypal_confirms_usd does not match the listed $4.99 / $29.99 / $149 price. Mint a matching NCP link. Do not reuse the historical Macro/ETF pack NCP IDs."
      };
    }
    return {
      ok: false,
      reason: "PayPal not live yet. Mint a PayPal NCP link per live SKU at $4.99, $29.99, or $149, set paypal_confirms_usd to that price, and point its success URL at van.html?paid={SKU}. Or set pay.paypal_business for a dynamic _xclick at the listed price."
    };
  }

  function liveProducts() {
    return ((cache && cache.paid) || []).filter(function (row) {
      return row.live;
    });
  }

  function comingSoonProducts() {
    return ((cache && cache.paid) || []).filter(function (row) {
      return !row.live;
    });
  }

  function anyPaypalLive() {
    return liveProducts().some(function (row) {
      return checkoutPlan(row).ok;
    }) || checkoutPlan({
      free: false,
      live: true,
      forbidden: false,
      amount: "4.99",
      product: "draft",
      sku: "draft",
      paypal_link_or_button_id: "",
      paypal_confirms_usd: null
    }).ok;
  }

  function squareStatus() {
    var pay = (cache && cache.pay) || {};
    var sq = pay.square || {};
    var enabled = sq.enabled === true;
    var appId = trim(sq.application_id);
    var locationId = trim(sq.location_id);
    var live = enabled && Boolean(appId) && Boolean(locationId);
    return {
      enabled: enabled,
      live: live,
      status: live ? "ready" : (trim(sq.status) || "coming_next"),
      application_id: appId,
      location_id: locationId,
      note: trim(sq.note) || "Square (Block) is the approved second rail. Coming next. Day-1 checkout is PayPal."
    };
  }

  function load() {
    if (cache) {
      return Promise.resolve(cache);
    }
    return fetch(SRC, { cache: "no-store" })
      .then(function (res) {
        if (!res.ok) {
          throw new Error("catalog missing");
        }
        return res.json();
      })
      .then(function (json) {
        var founder = json.founder_product || json.founder || null;
        cache = {
          client: json.client || {},
          account: json.account || {},
          catalog: json.catalog || { open_ended: true },
          founder: founder,
          paid: (json.modules || json.paid_modules || []).map(normalizePaid),
          pay: json.pay || {},
          raw: json
        };
        global.HalfacrePay.products = cache.paid;
        global.HalfacrePay.liveProducts = liveProducts();
        global.HalfacrePay.comingSoonProducts = comingSoonProducts();
        global.HalfacrePay.account = cache.account;
        global.HalfacrePay.catalog = cache.catalog;
        global.HalfacrePay.founder = cache.founder;
        global.HalfacrePay.client = cache.client;
        global.HalfacrePay.pay = cache.pay;
        global.HalfacrePay.square = squareStatus();
        return cache;
      });
  }

  global.HalfacrePay = {
    page: PAGE,
    src: SRC,
    rail: "paypal-day1",
    paidUsd: RESEARCH_USD,
    researchUsd: RESEARCH_USD,
    packUsd: PACK_USD,
    systemUsd: SYSTEM_USD,
    taxUsd: TAX_USD,
    basicUsd: RESEARCH_USD,
    advancedUsd: SYSTEM_USD,
    stripe: false,
    square: { enabled: false, live: false, status: "coming_next" },
    products: [],
    liveProducts: [],
    comingSoonProducts: [],
    account: {},
    catalog: { open_ended: true },
    founder: null,
    client: null,
    pay: {},
    load: load,
    findPaid: findPaid,
    fromQuery: fromQuery,
    resolve: resolve,
    buildUrl: buildUrl,
    formatMoney: formatMoney,
    paypalKind: paypalKind,
    checkoutPlan: checkoutPlan,
    isForbiddenAmount: isForbiddenAmount,
    isPaidAmount: isPaidAmount,
    isLive: isLiveRow,
    anyPaypalLive: anyPaypalLive,
    squareStatus: squareStatus
  };
})(window);

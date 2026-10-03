/**
 * Loads the public shop catalog (van-products.json).
 * Client names, engine/admin addresses, and internal notes live in
 * van-products.private.json (HTTP denied). Do not put them here.
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
 * Historical Macro $99 / ETF pack hosted-checkout links stay out of
 * this public file. $149 is a valid assembled-system price — never
 * charge it with those old pack links.
 *
 * PayPal is Day-1. Card/guest is native PayPal Checkout.
 * Square (Block) is an approved second rail — config stub only until SDK.
 * Live path: listed $4.99 / $29.99 / $149 + matching paypal_confirms_usd,
 * or dynamic _xclick when paypal_business is set.
 * No Stripe. Do not put hosted checkout ids or payment URLs in this file.
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

  function publicFulfillment(raw) {
    if (!raw || typeof raw !== "object") {
      return null;
    }
    var out = {};
    if (trim(raw.kind)) {
      out.kind = trim(raw.kind);
    }
    if (trim(raw.ui_href)) {
      out.ui_href = trim(raw.ui_href);
    }
    if (trim(raw.download_href)) {
      out.download_href = trim(raw.download_href);
    }
    return Object.keys(out).length ? out : null;
  }

  function publicPay(raw) {
    var pay = raw && typeof raw === "object" ? raw : {};
    var sq = pay.square && typeof pay.square === "object" ? pay.square : {};
    return {
      rail: "paypal",
      rails: ["paypal"],
      stripe: false,
      basic_usd: asNumber(pay.basic_usd) || RESEARCH_USD,
      advanced_usd: asNumber(pay.advanced_usd) || SYSTEM_USD,
      research_usd: asNumber(pay.research_usd) || RESEARCH_USD,
      pack_usd: asNumber(pay.pack_usd) || PACK_USD,
      system_usd: asNumber(pay.system_usd) || SYSTEM_USD,
      tax_usd: asNumber(pay.tax_usd) || TAX_USD,
      allowed_paid_usd: ALLOWED.slice(),
      forbidden_pack_usd: FORBIDDEN.slice(),
      paypal_business: trim(pay.paypal_business),
      square: {
        enabled: false,
        status: trim(sq.status) || "coming_next",
        application_id: "",
        location_id: ""
      }
    };
  }

  function publicCatalogMeta(raw) {
    var catalog = raw && typeof raw === "object" ? raw : {};
    return {
      open_ended: catalog.open_ended !== false,
      layers: catalog.layers,
      tiers: catalog.tiers,
      research_usd: catalog.research_usd,
      pack_usd: catalog.pack_usd,
      system_usd: catalog.system_usd,
      tax_usd: catalog.tax_usd,
      basic_usd: catalog.basic_usd,
      advanced_usd: catalog.advanced_usd,
      more_coming: catalog.more_coming,
      unique_research_skus: catalog.unique_research_skus,
      theme_packs: catalog.theme_packs,
      advanced_systems: catalog.advanced_systems,
      tax_upgrades: catalog.tax_upgrades,
      live_count: catalog.live_count,
      coming_soon_count: catalog.coming_soon_count,
      assembled_bots: catalog.assembled_bots,
      live_research_from_sot: catalog.live_research_from_sot
    };
  }

  function publicPaypalRef(value) {
    var v = trim(value);
    if (!v) {
      return "";
    }
    var lower = v.toLowerCase();
    if (lower.indexOf("paypal.com") !== -1 && lower.indexOf("/ncp/") !== -1) {
      return "";
    }
    if (/^[A-Z]{3}-[A-Z0-9]{8,}$/i.test(v)) {
      return "";
    }
    return v;
  }

  function publicModule(row) {
    if (!row || typeof row !== "object") {
      return {
        id: "",
        name: "",
        description: "",
        price_usd: RESEARCH_USD,
        live: false,
        paypal_link_or_button_id: ""
      };
    }
    return {
      id: trim(row.id),
      name: trim(row.name),
      description: trim(row.description),
      price_usd: row.price_usd,
      free: Boolean(row.free),
      live: isLiveRow(row),
      status: trim(row.status),
      kind: trim(row.kind),
      tier: trim(row.tier),
      pair: trim(row.pair),
      department: trim(row.department),
      departments: asStringList(row.departments),
      alias: trim(row.alias),
      paypal_link_or_button_id: publicPaypalRef(row.paypal_link_or_button_id),
      paypal_confirms_usd: row.paypal_confirms_usd == null ? null : asNumber(row.paypal_confirms_usd),
      avatar_upgrade_label: trim(row.avatar_upgrade_label),
      ui_href: trim(row.ui_href),
      fulfillment: publicFulfillment(row.fulfillment)
    };
  }

  function publicOnly(json) {
    var data = json && typeof json === "object" ? json : {};
    var founder = data.founder_product || data.founder || null;
    if (founder && typeof founder === "object") {
      founder = {
        id: trim(founder.id),
        name: trim(founder.name),
        tier: trim(founder.tier),
        kind: trim(founder.kind),
        description: trim(founder.description),
        price_usd: founder.price_usd,
        free: Boolean(founder.free),
        live: isLiveRow(founder),
        paypal_link_or_button_id: publicPaypalRef(founder.paypal_link_or_button_id),
        paypal_confirms_usd: founder.paypal_confirms_usd == null ? null : asNumber(founder.paypal_confirms_usd),
        avatar_upgrade_label: trim(founder.avatar_upgrade_label),
        ui_href: trim(founder.ui_href)
      };
    }
    return {
      catalog: publicCatalogMeta(data.catalog),
      pay: publicPay(data.pay),
      founder: founder,
      modules: (data.modules || data.paid_modules || []).map(publicModule)
    };
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
      paypal_link_or_button_id: publicPaypalRef(row.paypal_link_or_button_id),
      paypal_confirms_usd: row.paypal_confirms_usd == null ? null : asNumber(row.paypal_confirms_usd),
      avatar_upgrade_label: trim(row.avatar_upgrade_label) || trim(row.name),
      status: live ? "live" : (trim(row.status) || "coming_soon"),
      live: live,
      fulfillment: publicFulfillment(row.fulfillment),
      avatar_knowledge: "",
      download_href: row.fulfillment && row.fulfillment.download_href
        ? trim(row.fulfillment.download_href)
        : "",
      ui_href: trim(row.ui_href) || (row.fulfillment && row.fulfillment.ui_href) || "",
      van_unlocked: false,
      free: Boolean(row.free) || asNumber(price) === 0
    };
  }

  function paypalKind(value) {
    var v = publicPaypalRef(value);
    if (!v) {
      return { kind: "empty", value: "" };
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
    if (kind.kind !== "empty" && line.paypal_confirms_usd === price) {
      return { ok: true, kind: kind.kind, value: kind.value, amount: priceText };
    }
    if (kind.kind !== "empty") {
      return {
        ok: false,
        reason: "A PayPal link is set, but paypal_confirms_usd does not match the listed $4.99 / $29.99 / $149 price. Use a matching hosted checkout link or pay.paypal_business."
      };
    }
    return {
      ok: false,
      reason: "PayPal not live yet. Set pay.paypal_business for a dynamic _xclick at the listed $4.99 / $29.99 / $149 price, or add a matching hosted checkout link with paypal_confirms_usd and a success URL of van.html?paid={SKU}."
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
        var safe = publicOnly(json);
        cache = {
          client: {},
          account: {},
          catalog: safe.catalog,
          founder: safe.founder,
          paid: safe.modules.map(normalizePaid),
          pay: safe.pay,
          raw: safe
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

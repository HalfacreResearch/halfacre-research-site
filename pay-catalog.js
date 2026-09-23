/**
 * Loads van-products.json — the one file Matthew/staff edit.
 *
 * Paid Van modules are $1.99. Codex is free and is never charged.
 * Macro $99 / ETF $149 pack prices are forbidden on this pay path.
 *
 * PayPal is Day-1. Card/guest is native PayPal Checkout.
 * Square (Block) is an approved second rail — config stub only until SDK.
 * SoT pack NCP pattern: https://www.paypal.com/ncp/payment/PLB-…
 * Those pack links are $99/$149 — never used as Van checkout.
 * Van live path: new $1.99 NCP link + paypal_confirms_usd 1.99,
 * or dynamic _xclick when paypal_business is set. No Stripe.
 */
(function (global) {
  "use strict";

  var PAGE = "pay.html";
  var SRC = "van-products.json";
  var PAID_USD = 1.99;
  var FORBIDDEN = [99, 149];
  var cache = null;

  function trim(value) {
    return String(value == null ? "" : value).trim();
  }

  function asNumber(value) {
    var n = Number(value);
    return Number.isFinite(n) ? n : NaN;
  }

  function isForbiddenAmount(value) {
    var n = asNumber(value);
    return FORBIDDEN.indexOf(n) !== -1;
  }

  function isPaidAmount(value) {
    return asNumber(value) === PAID_USD;
  }

  function normalizePaid(row) {
    var price = row.price_usd;
    if (price === null || price === undefined || price === "") {
      price = PAID_USD;
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
      paypal_link_or_button_id: trim(row.paypal_link_or_button_id),
      paypal_confirms_usd: row.paypal_confirms_usd == null ? null : asNumber(row.paypal_confirms_usd),
      avatar_upgrade_label: trim(row.avatar_upgrade_label) || trim(row.name),
      status: trim(row.status) || "draft",
      free: false
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
    var founder = cache && cache.founder;
    if (founder && id && id.toLowerCase() === String(founder.id || "codex").toLowerCase()) {
      return {
        id: founder.id,
        sku: founder.id,
        product: founder.name,
        amount: "0",
        currency: "USD",
        description: founder.description || "",
        paypal_link_or_button_id: "",
        paypal_confirms_usd: null,
        avatar_upgrade_label: founder.avatar_upgrade_label || "",
        status: "free",
        free: true,
        forbidden: false,
        known: true
      };
    }
    var known = findPaid(id);
    var amount = trim(partial && partial.amount);
    if (!amount && known) {
      amount = known.amount;
    }
    if (!amount) {
      amount = String(PAID_USD);
    }
    var forbidden = isForbiddenAmount(amount);
    return {
      id: id || (known && known.id) || "",
      sku: trim(partial && partial.sku) || (known && known.sku) || id,
      product: trim(partial && (partial.product || partial.name)) || (known && known.product) || "",
      amount: forbidden ? "" : amount,
      currency: "USD",
      description: trim(partial && partial.description) || (known && known.description) || "",
      paypal_link_or_button_id: (known && known.paypal_link_or_button_id) || "",
      paypal_confirms_usd: known ? known.paypal_confirms_usd : null,
      avatar_upgrade_label: (known && known.avatar_upgrade_label) || "",
      status: (known && known.status) || "",
      free: false,
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
    if (line.amount && !line.forbidden && !line.free) {
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

  function checkoutPlan(line) {
    var pay = (cache && cache.pay) || {};
    var business = trim(pay.paypal_business);
    if (!line || line.free) {
      return { ok: false, reason: "Codex is free. No PayPal on this page." };
    }
    if (line.forbidden || isForbiddenAmount(line.amount)) {
      return { ok: false, reason: "Blocked: Macro $99 / ETF $149 pack prices are not allowed on Van’s pay page." };
    }
    if (!isPaidAmount(line.amount)) {
      return { ok: false, reason: "Van paid modules are $1.99. This amount cannot be charged here." };
    }
    if (business) {
      return {
        ok: true,
        kind: "dynamic",
        business: business,
        amount: "1.99",
        item_name: line.product || line.sku || "Halfacre research module",
        item_number: line.sku || line.id
      };
    }
    var kind = paypalKind(line.paypal_link_or_button_id);
    if (kind.kind === "pack-ncp") {
      return {
        ok: false,
        reason: "Blocked: that PayPal NCP link is a Macro $99 or ETF $149 pack. Mint a new $1.99 NCP link. Do not reuse pack SoT."
      };
    }
    if (kind.kind !== "empty" && line.paypal_confirms_usd === PAID_USD) {
      return { ok: true, kind: kind.kind, value: kind.value, amount: "1.99" };
    }
    if (kind.kind !== "empty") {
      return {
        ok: false,
        reason: "A PayPal link is set, but paypal_confirms_usd is not 1.99. Mint a $1.99 NCP link (same paypal.com/ncp/payment/PLB- pattern as the packs). Do not reuse the $99/$149 pack links."
      };
    }
    return {
      ok: false,
      reason: "PayPal not live yet. Mint a $1.99 PayPal NCP link and set paypal_confirms_usd to 1.99, or set pay.paypal_business for a dynamic $1.99 _xclick. Catalog names still wait on Matthew."
    };
  }

  function anyPaypalLive() {
    var list = (cache && cache.paid) || [];
    return list.some(function (row) {
      return checkoutPlan(row).ok;
    }) || checkoutPlan({
      free: false,
      forbidden: false,
      amount: "1.99",
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
        cache = {
          client: json.client || {},
          founder: json.founder_product || null,
          paid: (json.paid_modules || []).map(normalizePaid),
          pay: json.pay || {},
          raw: json
        };
        global.HalfacrePay.products = cache.paid;
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
    paidUsd: PAID_USD,
    stripe: false,
    square: false,
    products: [],
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
    anyPaypalLive: anyPaypalLive,
    squareStatus: squareStatus
  };
})(window);

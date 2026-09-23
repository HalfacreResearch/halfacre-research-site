/**
 * Universal pay catalog for the Van product path.
 *
 * Every research / data / avatar-upgrade purchase from the Van UX must
 * land on the same page: pay.html?product=&sku=&amount=&description=
 *
 * Query params are the source of truth for the line item. This catalog
 * only pre-fills known modules. Unknown SKUs still render.
 *
 * Checkout rail: PENDING CoS / Matthew payment-rail answers.
 * Do not invent Stripe. No live PayPal button IDs exist in this repo.
 */
(function (global) {
  "use strict";

  var PAGE = "pay.html";

  var PRODUCTS = [
    {
      sku: "MACRO-BTC",
      product: "Macro×BTC research",
      amount: "99.00",
      currency: "USD",
      description: "Avatar power-up: bitcoin next to rates, gold, fear and greed."
    },
    {
      sku: "ETF-FLOW",
      product: "ETF flow research",
      amount: "149.00",
      currency: "USD",
      description: "Avatar power-up: money moving in and out of spot bitcoin funds."
    },
    {
      sku: "BTC-TREASURY-CODEX",
      product: "Bitcoin Treasury Codex",
      amount: "",
      currency: "USD",
      description: "Future trading product. Not live. No trade and no amount until Matthew sets one."
    },
    {
      sku: "HR-MOD-BTC",
      product: "Bitcoin module",
      amount: "1.99",
      currency: "USD",
      description: "Named $1.99 research module: BTC."
    },
    {
      sku: "HR-MOD-ETH-USD",
      product: "ETH versus the dollar",
      amount: "1.99",
      currency: "USD",
      description: "Named $1.99 research module: ETH priced in dollars."
    },
    {
      sku: "HR-MOD-IRA-TRAD",
      product: "Traditional IRA research",
      amount: "1.99",
      currency: "USD",
      description: "Named $1.99 wrapper module: Traditional IRA research."
    }
  ];

  function trim(value) {
    return String(value == null ? "" : value).trim();
  }

  function findBySku(sku) {
    var want = trim(sku).toUpperCase();
    var i;
    if (!want) {
      return null;
    }
    for (i = 0; i < PRODUCTS.length; i += 1) {
      if (PRODUCTS[i].sku.toUpperCase() === want) {
        return PRODUCTS[i];
      }
    }
    return null;
  }

  function fromQuery(search) {
    var q = new URLSearchParams(typeof search === "string" ? search : global.location.search);
    return {
      product: trim(q.get("product")),
      sku: trim(q.get("sku")),
      amount: trim(q.get("amount")),
      currency: trim(q.get("currency")) || "USD",
      description: trim(q.get("description"))
    };
  }

  function resolve(partial) {
    var known = findBySku(partial && partial.sku);
    var sku = trim(partial && partial.sku) || (known && known.sku) || "";
    var product = trim(partial && partial.product) || (known && known.product) || "";
    var amount = trim(partial && partial.amount);
    if (!amount && known) {
      amount = known.amount;
    }
    var currency = trim(partial && partial.currency) || (known && known.currency) || "USD";
    var description = trim(partial && partial.description) || (known && known.description) || "";
    return {
      product: product,
      sku: sku,
      amount: amount,
      currency: currency,
      description: description,
      known: Boolean(known)
    };
  }

  function buildUrl(partial) {
    var line = resolve(partial);
    var q = new URLSearchParams();
    if (line.product) {
      q.set("product", line.product);
    }
    if (line.sku) {
      q.set("sku", line.sku);
    }
    if (line.amount) {
      q.set("amount", line.amount);
    }
    if (line.currency) {
      q.set("currency", line.currency);
    }
    if (line.description) {
      q.set("description", line.description);
    }
    return PAGE + "?" + q.toString();
  }

  function formatMoney(amount, currency) {
    var n = Number(amount);
    var cur = trim(currency) || "USD";
    if (amount === "" || amount == null) {
      return "Price pending";
    }
    if (!Number.isFinite(n)) {
      return trim(amount) + " " + cur;
    }
    return "$" + n.toFixed(2) + " " + cur;
  }

  global.HalfacrePay = {
    page: PAGE,
    rail: "pending-cos",
    paypalLive: null,
    stripe: false,
    products: PRODUCTS,
    findBySku: findBySku,
    fromQuery: fromQuery,
    resolve: resolve,
    buildUrl: buildUrl,
    formatMoney: formatMoney
  };
})(window);

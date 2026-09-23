/**
 * On-page Grok (xAI) for /van.html.
 *
 * This is Grok itself, embedded full-time. Not a Grok Bot, not a fleet
 * agent, not VanCoachBot. Browser talks to van-grok.php on Hostinger;
 * the xAI key never ships in this file.
 */
(function (global) {
  "use strict";

  var FULL_NAME = "Charlie Van Halfacre";
  var FIRST_NAME = "Van";
  var PROXY = "van-grok.php";
  var MODEL = "grok-4.6";

  var PASTED_KEY_RE = /(?:^|\s)[A-Za-z0-9_\-]{24,}(?:\s|$)/;
  var SECRET_WORDS_RE =
    /\b(password|passwd|passcode|pin\b|secret[_\s-]?key|private[_\s-]?key|seed[_\s-]?phrase|recovery[_\s-]?phrase|mnemonic|ssn|social security)\b/i;

  var GREETING = [
    "Hi Van. I’m Grok — the model on this page, not a fleet bot.",
    "",
    "I’m here the whole sitting. My job is to grow your net worth in plain English, learn what accounts and goals matter, and walk you through Halfacre’s research and Codex products when they help.",
    "",
    "The shop has two layers. The full intentions list shows everything we intend to sell. Only live SKUs are clickable to PayPal. Research/data is $4.99 — a research unlock is the full Bitcoin/macro stack Codex considers, not candles-only. Theme packs are $29.99 (DIY à la carte of ~89 series at $4.99 is about $444, so the pack is the deal). Assembled trading systems are $149. Tax immediate-outcome upgrades are $199. Codex Buy and Codex Sell are separate $149 systems. The Codex explainer on this page is free. Paying one live SKU unlocks only that one. Matthew cashes you back off this page.",
    "",
    "sFOX keys go in the box above, never here. After sFOX is linked, Codex Buy and Codex Sell can autotrade later — not today.",
    "",
    "What do you want to work on first?"
  ].join("\n");

  var STARTERS = [
    { label: "Connect sFOX", send: "How do I connect sFOX?" },
    { label: "What can I buy?", send: "What is live to buy on PayPal versus coming soon on the intentions list?" },
    { label: "Grow net worth", send: "Help me grow my net worth. What should you know about my situation?" },
    { label: "Codex Buy vs Sell", send: "What is the difference between Codex Buy and Codex Sell?" }
  ];

  function looksLikePastedSecret(text) {
    var raw = String(text || "");
    return PASTED_KEY_RE.test(raw) || SECRET_WORDS_RE.test(raw);
  }

  function sfoxConnected() {
    return global.VanSfox && global.VanSfox.state().connected;
  }

  function catalogSnapshot() {
    var rows = (global.HalfacrePay && global.HalfacrePay.products) || [];
    var live = [];
    var coming = [];
    rows.forEach(function (row) {
      var name = row.product || row.name;
      if (row.live) {
        live.push({
          id: row.id,
          name: name,
          price: row.amount || row.price_usd,
          tier: row.tier || "",
          buyable: true
        });
      } else {
        coming.push({
          id: row.id,
          name: name,
          price: row.amount || row.price_usd,
          tier: row.tier || "",
          buyable: false
        });
      }
    });
    return {
      live: live,
      coming_soon: coming,
      note: "Only live SKUs are for sale. Coming soon is visible on van.html and not clickable. Do not shrink the catalog to seven SKUs."
    };
  }

  function ownedIds() {
    if (global.VanOwned && typeof global.VanOwned.ownedIds === "function") {
      return global.VanOwned.ownedIds();
    }
    return [];
  }

  function secretBlock() {
    return [
      "Don’t type a key, password, or PIN in this chat.",
      "",
      "sFOX has its own box on this page. Paste the API key there. I never need to see it."
    ].join("\n");
  }

  function offlineNote() {
    return [
      "I’m Grok on this page. The live xAI path is waiting on Hostinger: set XAI_API_KEY or van-grok.secret.php for van-grok.php.",
      "",
      "I still won’t take keys in chat. Use the sFOX box. Live prices on pay.html: $4.99 research, $29.99 packs, $149 systems, $199 tax — locked until that PayPal payment. Coming soon is visible and not for sale.",
      "",
      "Tell me a goal or pick a module and I’ll keep going."
    ].join("\n");
  }

  function endpoint() {
    if (typeof global.HALFACRE_GROK_ENDPOINT === "string" && global.HALFACRE_GROK_ENDPOINT.trim()) {
      return global.HALFACRE_GROK_ENDPOINT.trim();
    }
    return PROXY;
  }

  function nowMode() {
    return "grok";
  }

  function fetchWithTimeout(url, options, ms) {
    var ctrl = new AbortController();
    var timer = setTimeout(function () {
      ctrl.abort();
    }, ms);
    options = options || {};
    options.signal = ctrl.signal;
    return fetch(url, options).finally(function () {
      clearTimeout(timer);
    });
  }

  function scrub(messages) {
    return (messages || []).map(function (item) {
      if (looksLikePastedSecret(item && item.content)) {
        return { role: item.role, content: "[redacted — key or secret was not sent]" };
      }
      return { role: item.role, content: item.content };
    }).filter(function (item) {
      return item && (item.role === "user" || item.role === "assistant") && item.content;
    });
  }

  function askGrok(messages) {
    return fetchWithTimeout(
      endpoint(),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          messages: scrub(messages),
          catalog: catalogSnapshot(),
          owned: ownedIds(),
          sfox: sfoxConnected(),
          client: FULL_NAME
        })
      },
      45000
    )
      .then(function (res) {
        return res.json().then(function (data) {
          return { ok: res.ok, data: data };
        });
      })
      .then(function (pack) {
        if (pack.data && typeof pack.data.reply === "string" && pack.data.reply.trim()) {
          return pack.data.reply.trim();
        }
        throw new Error((pack.data && pack.data.error) || "empty grok reply");
      });
  }

  function createMemory() {
    return {
      engine: "grok",
      notes: []
    };
  }

  global.HalfacreGrok = {
    fullName: FULL_NAME,
    firstName: FIRST_NAME,
    engine: "grok",
    model: MODEL,
    greeting: GREETING,
    starters: STARTERS,
    mode: nowMode,
    createMemory: createMemory,
    looksLikeSecret: looksLikePastedSecret,
    reply: function (userText, _memory, history) {
      if (looksLikePastedSecret(userText)) {
        return Promise.resolve(secretBlock());
      }
      return askGrok(history || []).catch(function () {
        return offlineNote();
      });
    }
  };
})(window);

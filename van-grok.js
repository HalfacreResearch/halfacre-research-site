/**
 * On-page Grok (xAI) for /van.html.
 *
 * This is Grok itself, embedded full-time. Not a Grok Bot, not a fleet
 * agent, not VanCoachBot. Browser talks to van-grok.php on Hostinger;
 * the xAI key never ships in this file.
 *
 * Greeting + starters stamped from approved Van page AI brief Q1–Q15
 * (Matthew 2026-09-23). Upload-first. Two poles. Not a shop clerk open.
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

  function greeting() {
    var ctx = (global.VanOwned && global.VanOwned.avatarContext)
      ? global.VanOwned.avatarContext()
      : { text: "Avatar power-ups: none yet.", powerups: [] };
    var powered = (ctx.powerups || []).map(function (row) {
      return row.name;
    });
    var powerLine = powered.length
      ? "I already have these unlocks on your account: " + powered.join(", ") + ". They stay loaded."
      : "No paid unlocks on this account yet. That is fine. We start from what you upload.";
    return [
      "Hey Van. I’m Grok — the model on this page, not a fleet bot.",
      "",
      "Two poles. One is zero net worth. The other is an Elon-level financial structure: the most complete retirement portfolio we can build, aimed at a trillionaire path, without a stack of advisors and tax attorneys in the middle. You stay 100% in charge. This page sells research and data. It is not licensed advice.",
      "",
      "My job this sitting is both: learn every financial document you will share so we can place you between those two poles, and point you at live tools on this page when they actually move the plan.",
      "",
      powerLine,
      "",
      "Start with whatever you have in hand. Bank statements, brokerage, crypto exchanges, retirement accounts, debts, income, tax returns, real estate, metals, company papers — anything that is yours. Do not type passwords, PINs, or API keys here. Documents and the secure boxes on this page only.",
      "",
      "What can you upload first?"
    ].join("\n");
  }

  var STARTERS = [
    { label: "Upload a statement", send: "I have a financial document ready to upload. Tell me what you need first and what you will do with it." },
    { label: "Banks + brokerage", send: "Help me start with bank and brokerage statements." },
    { label: "Crypto + retirement", send: "Help me start with crypto exchanges and retirement accounts." },
    { label: "Show live tools", send: "After you explain the two poles, show only live clickable items that fit a first step. Do not invent products." }
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
      note: "Live research = pay, download, power up. Coming soon / Not ready means data or fulfillment is missing. Only BTCTreasuryBot is live among assembled bots. Unlock list is source of truth for avatar knowledge."
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
      "Hey Van. I’m Grok on this page. The live xAI path is waiting on a key for van-grok.php — we can still work the plan.",
      "",
      "Two poles: zero net worth, and an Elon-level / trillionaire retirement structure. You stay 100% in charge. Research and data only.",
      "",
      "I still won’t take keys in chat. Use the sFOX box on free Codex. Live research on this page is $4.99. Packs $29.99. Top assembled bots $149 including TaxAttorneyBot. Only BTCTreasuryBot is live among the 18 named bots. Codex is free.",
      "",
      "What financial document can you share first?"
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
          avatar: (global.VanOwned && global.VanOwned.avatarContext)
            ? global.VanOwned.avatarContext()
            : { userId: "charlie-van-halfacre", powerups: [] },
          sfox: sfoxConnected(),
          client: FULL_NAME,
          userId: "charlie-van-halfacre"
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
    greeting: greeting,
    greetingText: greeting,
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

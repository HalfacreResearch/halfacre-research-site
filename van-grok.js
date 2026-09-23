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

  function greeting() {
    var ctx = (global.VanOwned && global.VanOwned.avatarContext)
      ? global.VanOwned.avatarContext()
      : { text: "Avatar power-ups: none yet.", powerups: [] };
    var powered = (ctx.powerups || []).map(function (row) {
      return row.name;
    });
    var powerLine = powered.length
      ? "Your avatar already knows: " + powered.join(", ") + ". Those unlocks stay on your account and I load them before we talk."
      : "Your avatar starts with no paid power-ups. A live module you pay for is downloaded and written to your unlock list so I remember it next visit.";
    return [
      "Hi Van. I’m Grok — the model on this page, not a fleet bot.",
      "",
      "I’m here the whole sitting. My job is to grow your net worth in plain English, learn what accounts and goals matter, and walk you through Halfacre’s products when they help.",
      "",
      powerLine,
      "",
      "Live research modules that already exist are clickable: pay $4.99 (or $29.99 for a theme pack), download the series, and power up this avatar. Grey cards are Coming soon / Not ready — data or fulfillment is not built yet, not teaser fluff.",
      "",
      "Free Codex is on this page. Its product SoT is the existing autotrades-engine (old Codex) — connect sFOX, then trade. That is not a new engine. Hostinger DB export is not required. sFOX connect is the Codex unlock. Trade is follow-on; this page does not place a trade today.",
      "",
      "Matthew’s first assembled-bot list is on this page — 18 exact names. Only BTCTreasuryBot is the live $149 assembled bot; it uses that same Codex / autotrades path. The other 17 bots are Coming soon until built. More bot names are still coming; I will not invent extras.",
      "",
      "sFOX keys go in the Codex box above, never here.",
      "",
      "What do you want to work on first?"
    ].join("\n");
  }

  var STARTERS = [
    { label: "Connect sFOX", send: "How do I connect sFOX?" },
    { label: "What can I buy?", send: "Which research modules can I pay for, download, and power up right now?" },
    { label: "Grow net worth", send: "Help me grow my net worth. What should you know about my situation?" },
    { label: "Codex + sFOX", send: "Walk me through the free Codex module and the sFOX connect → trade path." }
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
      "I’m Grok on this page. The live xAI path is waiting on Hostinger: set XAI_API_KEY or van-grok.secret.php for van-grok.php.",
      "",
      "I still won’t take keys in chat. Use the sFOX box on the free Codex module. Codex SoT is autotrades-engine (connect → trade). Hostinger DB export is not a blocker. Live research is $4.99. Only BTCTreasuryBot is the live $149 assembled bot.",
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

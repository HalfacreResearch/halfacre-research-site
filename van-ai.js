/**
 * Charlie Van Halfacre — on-page coach.
 *
 * Priority 1: get Van to connect sFOX in the dedicated field (not chat).
 * Codex is free for the founder. Paid upgrades go to pay.html.
 * Never log keys. Never send keys to VAN_AI_ENDPOINT.
 */
(function (global) {
  "use strict";

  var FULL_NAME = "Charlie Van Halfacre";
  var FIRST_NAME = "Van";

  function sfoxConnected() {
    return global.VanSfox && global.VanSfox.state().connected;
  }

  function paidList() {
    var rows = (global.HalfacrePay && global.HalfacrePay.products) || [];
    return rows.filter(function (row) {
      return !row.free;
    });
  }

  function payHref(id) {
    if (global.HalfacrePay && typeof global.HalfacrePay.buildUrl === "function") {
      return global.HalfacrePay.buildUrl({ id: id, sku: id });
    }
    return "pay.html?id=" + encodeURIComponent(id);
  }

  function moduleLines() {
    var rows = paidList();
    if (!rows.length) {
      return "Staff can add paid modules in van-products.json. Each one uses the same PayPal pay page.";
    }
    return rows.map(function (row, i) {
      var price = global.HalfacrePay.formatMoney(row.amount);
      return (i + 1) + ". " + row.product + " — " + price + " — " + payHref(row.id);
    }).join("\n");
  }

  var GREETING = [
    "Hi Van. I’m your Halfacre coach.",
    "",
    "Your first product is already on this page: Bitcoin Treasury Codex. It is free for you. Matthew is not charging you for Codex.",
    "",
    "The most useful next step is to connect sFOX in the box above — not in this chat. That box is a special key field. The key stays on this device until Matthew has a vault. After sFOX is linked, Codex can autotrade. We will not place live trades from this page today.",
    "",
    "When you want more later, other research modules can power up this page. Those paid upgrades all go to one PayPal page.",
    "",
    "Want to connect sFOX first?"
  ].join("\n");

  var STARTERS = [
    { label: "Connect sFOX", send: "How do I connect sFOX?" },
    { label: "What is Codex?", send: "Tell me about Codex." },
    { label: "After sFOX", send: "What happens after sFOX is linked?" },
    { label: "Other power-ups", send: "What other research can help me?" }
  ];

  var PASTED_KEY_RE = /(?:^|\s)[A-Za-z0-9_\-]{24,}(?:\s|$)/;
  var SECRET_WORDS_RE =
    /\b(password|passwd|passcode|pin\b|secret[_\s-]?key|private[_\s-]?key|seed[_\s-]?phrase|recovery[_\s-]?phrase|mnemonic|ssn|social security)\b/i;

  function nowMode() {
    var endpoint = typeof global.VAN_AI_ENDPOINT === "string" ? global.VAN_AI_ENDPOINT.trim() : "";
    return endpoint ? "live" : "demo";
  }

  function looksLikePastedSecret(text) {
    var raw = String(text || "");
    return PASTED_KEY_RE.test(raw) || SECRET_WORDS_RE.test(raw);
  }

  function normalize(text) {
    return String(text || "")
      .toLowerCase()
      .replace(/['’]/g, "")
      .replace(/[^a-z0-9+\s]/g, " ")
      .replace(/\s+/g, " ")
      .trim();
  }

  function has(n, words) {
    var i;
    for (i = 0; i < words.length; i += 1) {
      if (n.indexOf(words[i]) !== -1) {
        return true;
      }
    }
    return false;
  }

  function isShortAffirm(n) {
    return /^(yes|yeah|yep|yup|ok|okay|sure|please|do it|sounds good|go ahead|thats fine|that is fine|alright|all right)$/.test(n);
  }

  function isDefer(n) {
    return has(n, [
      "later",
      "not now",
      "not today",
      "maybe later",
      "skip",
      "pass",
      "not sure",
      "dont know",
      "do not know",
      "idk",
      "nothing",
      "n/a"
    ]);
  }

  function detectTopic(n) {
    if (has(n, ["sfox", "s fox", "api connection", "connect sfox", "link sfox", "the key field", "the box"])) {
      return "sfox";
    }
    if (has(n, ["after sfox", "autotrade", "auto trade", "once it is linked", "once its linked"])) {
      return "autotrade";
    }
    if (has(n, ["codex"])) {
      return "codex";
    }
    if (has(n, ["paypal", "square", "buy now", "check out", "checkout", "pay now", "purchase", "how much", "price", "pay page", "pay for"])) {
      return "purchase";
    }
    if (has(n, ["module", "research", "power up", "powerup", "upgrade", "what can help", "climate", "flow", "tax wrapper"])) {
      return "modules";
    }
    if (has(n, ["plaid", "link a bank", "link bank", "bank login"])) {
      return "bank";
    }
    if (has(n, ["account", "bank", "401", "ira", "roth", "broker", "exchange", "cash app", "venmo", "retirement", "checking", "savings"])) {
      return "accounts";
    }
    if (has(n, ["goal", "retire", "net worth", "grow", "save", "debt", "house", "family"])) {
      return "goals";
    }
    if (has(n, ["matthew", "son", "my boy"])) {
      return "matthew";
    }
    if (has(n, ["hello", "hi van", "hey", "good morning", "good afternoon", "start over", "begin"])) {
      return "hello";
    }
    if (has(n, ["help", "what do you do", "who are you", "what is this", "how does this"])) {
      return "help";
    }
    if (has(n, ["thank", "thanks", "appreciate"])) {
      return "thanks";
    }
    if (has(n, ["connect"])) {
      return "sfox";
    }
    return "";
  }

  function replyFor(topic, memory, raw) {
    var spoken = String(raw || "").trim();

    if (topic === "secret") {
      return [
        "Please don’t type a key, password, or PIN in this chat.",
        "",
        "sFOX has its own box on this page. Paste the API key there. It stays on this device. I never need to see it, and it should not go into a conversation.",
        "",
        "If you already typed one here, treat it as exposed: disconnect, make a new key in sFOX, and use the box."
      ].join("\n");
    }

    if (topic === "sfox") {
      memory.lastTopic = "sfox";
      if (sfoxConnected()) {
        return [
          "sFOX shows as connected on this page.",
          "",
          "After it is linked, Codex can autotrade. Live autotrade is a follow-on — this page will not place a trade today.",
          "",
          "If you want, we can look at other research power-ups next. Those paid upgrades use one PayPal page."
        ].join("\n");
      }
      return [
        "Use the sFOX box on this page. Do not paste the key into this chat.",
        "",
        "1. Open your sFOX account and create an API key.",
        "2. Put it only in the dedicated field above.",
        "3. When the page says “sFOX connected,” Codex can autotrade later.",
        "",
        "The key stays on this device until Matthew has a vault. We are not sending live trades from here today."
      ].join("\n");
    }

    if (topic === "autotrade") {
      memory.lastTopic = "autotrade";
      return [
        "After sFOX is linked, Codex can autotrade.",
        "",
        "That is the point of the connection: Codex can act through sFOX instead of you typing trades by hand. Live autotrade wiring is a follow-on once the key exists. This page only keeps the connected / not connected state.",
        "",
        sfoxConnected()
          ? "You’re already connected. The next ship can use that key from a vault."
          : "Connect sFOX in the box above when you’re ready. Don’t put the key in chat."
      ].join("\n");
    }

    if (topic === "codex") {
      memory.lastTopic = "codex";
      return [
        "Bitcoin Treasury Codex is already on this page. It is your first product, and it is free for you as the founder. There is no Codex charge and no Codex PayPal button.",
        "",
        "To make Codex useful, connect sFOX in the box — not in this chat. After sFOX is linked, Codex can autotrade. We will not fire live trades from this sitting.",
        "",
        sfoxConnected()
          ? "sFOX already shows connected."
          : "Want help with that sFOX box?"
      ].join("\n");
    }

    if (topic === "purchase" || topic === "modules") {
      memory.lastTopic = "modules";
      return [
        "After Codex, other research modules can power up this page. The list is custom — staff edit van-products.json. Early paid upgrades are $1.99 each. They use the same PayPal page first. Square is approved as a second rail and is coming next. Card or guest pay is whatever PayPal shows. Not the Macro $99 or ETF $149 packs. No Stripe. Codex is not on that page.",
        "",
        moduleLines(),
        "",
        "PayPal has to work for a paid upgrade before this is ready to show as a finished client visit. Connecting sFOX is still the first job."
      ].join("\n");
    }

    if (topic === "hello") {
      memory.lastTopic = "hello";
      return GREETING;
    }

    if (topic === "help") {
      memory.lastTopic = "help";
      return [
        "I’m the coach on " + FULL_NAME + "’s page.",
        "",
        "First job: help you connect sFOX in the box on this page, so Codex — already yours, free — can autotrade later.",
        "Second: talk in ordinary words about accounts and goals. No keys in chat.",
        "Third: if you want more, paid research power-ups go to one PayPal page.",
        "",
        "I will not move money or place a live trade today."
      ].join("\n");
    }

    if (topic === "matthew") {
      memory.lastTopic = "matthew";
      return [
        "Matthew built this so the two of you can sit together.",
        "",
        "Codex is already yours, free. He wants sFOX connected in the box on this page. Paid upgrades, when you’re ready, use one PayPal page he can fill in from van-products.json.",
        "",
        "Want to do the sFOX box first?"
      ].join("\n");
    }

    if (topic === "thanks") {
      return "You’re welcome, Van. The sFOX box is the useful next step if it still says not connected.";
    }

    if (topic === "bank") {
      memory.lastTopic = "bank";
      return [
        "A bank connection is a later step, not this page, and not a login form here.",
        "",
        "The connection that matters for Codex today is sFOX, in the dedicated box. After sFOX is linked, Codex can autotrade. Don’t paste bank passwords or sFOX keys into this chat."
      ].join("\n");
    }

    if (topic === "accounts") {
      memory.lastTopic = "accounts";
      memory.mentionedAccounts = true;
      return [
        "Ordinary words are enough: a bank, a retirement account, some bitcoin. No logins in chat.",
        "",
        "For Codex, the account that matters is sFOX. Use the box on this page for that key. After it is linked, Codex can autotrade — live wiring comes later."
      ].join("\n");
    }

    if (topic === "goals") {
      memory.lastTopic = "goals";
      memory.mentionedGoals = true;
      return [
        "Goals can stay simple: sleep better about cash, grow something, help family.",
        "",
        "Codex is already here, free, to help that picture get more active later. Connecting sFOX is the first practical step. Other research power-ups can wait on the PayPal page."
      ].join("\n");
    }

    if (isShortAffirm(normalize(spoken)) && memory.lastTopic) {
      return replyFor(memory.lastTopic === "hello" ? "sfox" : memory.lastTopic, memory, spoken);
    }

    if (isDefer(normalize(spoken))) {
      memory.lastTopic = "sfox";
      return [
        "That’s fine. Nothing has to be decided this minute.",
        "",
        "When you’re ready, the useful box is sFOX on this page. Codex is already yours."
      ].join("\n");
    }

    memory.lastTopic = "general";
    return [
      "I’m with you.",
      "",
      "I won’t turn that into a login. If it was a key, use the sFOX box instead of chat.",
      "",
      "Easiest next step: connect sFOX so Codex can autotrade later. After that, we can look at paid research power-ups."
    ].join("\n");
  }

  function scriptedReply(userText, memory) {
    if (looksLikePastedSecret(userText)) {
      return replyFor("secret", memory, userText);
    }
    return replyFor(detectTopic(normalize(userText)), memory, userText);
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
      return item;
    });
  }

  function askLive(messages) {
    var url = typeof global.VAN_AI_ENDPOINT === "string" ? global.VAN_AI_ENDPOINT.trim() : "";
    if (!url) {
      return Promise.resolve(null);
    }
    return fetchWithTimeout(
      url,
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ messages: scrub(messages), client: FULL_NAME })
      },
      8000
    )
      .then(function (res) {
        if (!res.ok) {
          throw new Error("bad status");
        }
        return res.json();
      })
      .then(function (data) {
        if (data && typeof data.reply === "string" && data.reply.trim()) {
          return data.reply.trim();
        }
        throw new Error("empty reply");
      })
      .catch(function () {
        return null;
      });
  }

  function createMemory() {
    return {
      lastTopic: "sfox",
      mentionedAccounts: false,
      mentionedGoals: false,
      mentionedModules: false,
      notes: []
    };
  }

  global.VanCoach = {
    fullName: FULL_NAME,
    firstName: FIRST_NAME,
    greeting: GREETING,
    starters: STARTERS,
    mode: nowMode,
    createMemory: createMemory,
    looksLikeSecret: looksLikePastedSecret,
    reply: function (userText, memory, history) {
      var local = scriptedReply(userText, memory || createMemory());
      return askLive(history || []).then(function (live) {
        return live || local;
      });
    }
  };
})(window);

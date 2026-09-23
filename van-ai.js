/**
 * Charlie Van Halfacre — on-page coach.
 *
 * Demo-ready scripted conversation. No passwords, API keys, bank logins,
 * or live trading. Chat stays in this browser tab unless a later model
 * endpoint is configured.
 *
 * Later wiring (Matthew):
 *   window.VAN_AI_ENDPOINT = "https://example.invalid/van-coach";
 * Expected contract: POST JSON { messages: [{ role, content }] }
 * Expected reply:    JSON { reply: "..." }
 * If the endpoint is empty or the request fails, this file answers locally.
 *
 * Purchases: always send Van UX to pay.html (HalfacrePay.buildUrl).
 * Checkout is blocked until CoS / Matthew payment-rail answers.
 */
(function (global) {
  "use strict";

  var FULL_NAME = "Charlie Van Halfacre";
  var FIRST_NAME = "Van";

  function payHref(sku) {
    if (global.HalfacrePay && typeof global.HalfacrePay.buildUrl === "function") {
      return global.HalfacrePay.buildUrl({ sku: sku });
    }
    return "pay.html?sku=" + encodeURIComponent(sku);
  }

  var GREETING = [
    "Hi Van. I’m your Halfacre coach.",
    "",
    "I’m here to help you get started. We can talk about whatever you’re willing to share — accounts, goals, the ordinary picture of your money — and I’ll introduce Halfacre research modules as ways to power up this page and think about your net worth.",
    "",
    "We never need a password or an API key here. Connecting a bank or an exchange can happen later, with Matthew, through a proper setup. Not on this page, and not today.",
    "",
    "If you want a research module, every purchase from this page goes to the same pay page. Digital pay is not live yet — Matthew still has to choose the payment rail — so this page is not ready to present as a finished client product.",
    "",
    "What would you like to talk about first?"
  ].join("\n");

  var STARTERS = [
    { label: "My accounts", send: "I can tell you a little about my accounts." },
    { label: "My goals", send: "Let’s talk about my goals." },
    { label: "Research modules", send: "What research can help me?" },
    { label: "Open pay page", send: "Show me the pay page for a module." },
    { label: "Connect later", send: "How would connecting a bank work later?" },
    { label: "Bitcoin later", send: "Tell me about the Bitcoin Treasury Codex." }
  ];

  var SECRET_RE =
    /\b(password|passwd|passcode|pin\b|api[_\s-]?key|secret[_\s-]?key|private[_\s-]?key|seed[_\s-]?phrase|recovery[_\s-]?phrase|mnemonic|ssn|social security)\b|[:=]\s*\S{8,}|sk-[A-Za-z0-9]{10,}|pk_[A-Za-z0-9]{10,}/i;

  function nowMode() {
    var endpoint = typeof global.VAN_AI_ENDPOINT === "string" ? global.VAN_AI_ENDPOINT.trim() : "";
    return endpoint ? "live" : "demo";
  }

  function looksLikeSecret(text) {
    return SECRET_RE.test(String(text || ""));
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
    if (looksLikeSecret(n) || has(n, ["password", "api key", "private key", "seed phrase", "login", "log in", "username"])) {
      return "secret";
    }
    if (has(n, ["paypal", "buy now", "check out", "checkout", "pay now", "purchase", "how much", "price", "cost", "pay page", "pay for"])) {
      return "purchase";
    }
    if (has(n, ["codex", "autotrade", "auto trade", "live trade", "trading bot", "place a trade", "buy bitcoin now"])) {
      return "codex";
    }
    if (has(n, ["macro", "fear", "greed", "gold", "rates", "fed", "inflation"])) {
      return "macro";
    }
    if (has(n, ["etf", "flow", "ibit", "spot bitcoin fund"])) {
      return "etf";
    }
    if (has(n, ["module", "research", "power up", "powerup", "pack", "what can help"])) {
      return "modules";
    }
    if (has(n, ["connect", "link a bank", "link bank", "plaid", "exchange later", "sfox"])) {
      return "connect";
    }
    if (has(n, ["account", "bank", "401", "ira", "roth", "broker", "exchange", "cash app", "venmo", "retirement", "checking", "savings", "coin", "wallet"])) {
      return "accounts";
    }
    if (has(n, ["goal", "retire", "net worth", "grow", "save", "debt", "house", "family", "income", "spend"])) {
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
    return "";
  }

  function replyFor(topic, memory, raw) {
    var spoken = String(raw || "").trim();

    if (topic === "secret") {
      return [
        "Please don’t type a password, PIN, or API key here.",
        "",
        "This page does not collect logins and it does not connect to a bank or exchange. If something looks like a secret, treat it as if it should not be on this screen.",
        "",
        "When you and Matthew are ready, a later setup can connect accounts the right way. For today, ordinary words are enough — “I have a bank and a retirement account” is plenty."
      ].join("\n");
    }

    if (topic === "purchase") {
      memory.lastTopic = "modules";
      return [
        "Every module purchase from this page lands on the same pay page — any name, price, or SKU, not just one pack.",
        "",
        "Digital pay is not live. Matthew still has to answer how money is collected. There is no live PayPal button and no Stripe on that page. You can still open the line item:",
        payHref("MACRO-BTC"),
        payHref("ETF-FLOW"),
        payHref("HR-MOD-BTC"),
        "",
        "This page stays not-client-ready until that pay gate works."
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
        "Three jobs, all in ordinary language:",
        "1. Help you start, at your pace.",
        "2. Hear what you are willing to share about accounts and goals. You choose the words. No logins.",
        "3. Introduce Halfacre research modules as power-ups for this page — ways to see the picture more clearly and think about net worth.",
        "",
        "I will not move money, place a trade, or ask you to connect a bank today."
      ].join("\n");
    }

    if (topic === "matthew") {
      memory.lastTopic = "matthew";
      return [
        "Matthew built this page so the two of you can sit together and talk it through.",
        "",
        "I’m the on-page coach. He is the person who can unlock research later and, down the road, a proper account connection. You do not have to figure out the technical side.",
        "",
        "Want to sketch your accounts, talk goals, or hear about the research modules?"
      ].join("\n");
    }

    if (topic === "thanks") {
      return "You’re welcome, Van. I’m right here if you want to keep going — accounts, goals, or the research modules.";
    }

    if (topic === "connect") {
      memory.lastTopic = "connect";
      memory.mentionedConnect = true;
      return [
        "Later — not on this page — Matthew can help you connect a bank or an exchange through a proper setup.",
        "",
        "That future step is meant to save typing and keep a cleaner picture of accounts. It is not live here. There is no login form, no Plaid button, and no exchange key to paste.",
        "",
        "For this sitting, you can just tell me what exists, in your own words. A checking account. A retirement account. Some bitcoin. Whatever you’re comfortable saying."
      ].join("\n");
    }

    if (topic === "accounts") {
      memory.lastTopic = "accounts";
      memory.mentionedAccounts = true;
      if (spoken.length > 40) {
        memory.notes.push(spoken);
      }
      return [
        "Good. You don’t need to log in or paste anything sensitive.",
        "",
        "A useful first picture is just the list: bank, retirement, brokerage, cash apps, coins, a house, debt — whatever is actually yours. Rough is fine. “I have a 401(k) and a checking account” is a real start.",
        "",
        "When you want a tighter picture later, Matthew can help connect a bank or exchange the right way. Not today, and never with a password on this page.",
        "",
        "Want to name a couple of accounts you already have, or shift to goals?"
      ].join("\n");
    }

    if (topic === "goals") {
      memory.lastTopic = "goals";
      memory.mentionedGoals = true;
      if (spoken.length > 20) {
        memory.notes.push(spoken);
      }
      return [
        "Goals can stay simple.",
        "",
        "Sleep better about cash. Grow something for later. Help family. Pay down a balance. You don’t need a spreadsheet to start.",
        "",
        "Once we have even a rough aim, the research modules are how this page gets smarter — Macro×BTC for the bigger climate, ETF flow for money moving in and out of bitcoin funds, and the Bitcoin Treasury Codex later as a trading product Matthew can unlock.",
        "",
        "What would “better” look like for you in a year, in your own words?"
      ].join("\n");
    }

    if (topic === "macro") {
      memory.lastTopic = "modules";
      memory.mentionedModules = true;
      return [
        "Macro×BTC research is the “what’s the weather?” module.",
        "",
        "It looks at bitcoin next to ordinary things people already hear about — rates, the dollar, gold, fear and greed. The point is not a hot tip. It’s a clearer climate so your avatar — this page — can get smarter over time.",
        "",
        "If you want this module later, it uses the same pay page as every other upgrade:",
        payHref("MACRO-BTC"),
        "Pay is not live yet. No password, no bank login, and no trade from here.",
        "",
        "Want ETF flow next, or the future Codex?"
      ].join("\n");
    }

    if (topic === "etf") {
      memory.lastTopic = "modules";
      memory.mentionedModules = true;
      return [
        "ETF flow research watches money moving in and out of the big spot bitcoin funds.",
        "",
        "In plain English: when a lot of people put money into those funds, or take it out, that shows up as a flow. It’s a way to see demand without staring at a single price tick.",
        "",
        "This is research for your page, not a trade button. Same pay page as every other module:",
        payHref("ETF-FLOW"),
        "Checkout is not live yet.",
        "",
        "I can also walk through Macro×BTC, or the Bitcoin Treasury Codex as a future product."
      ].join("\n");
    }

    if (topic === "codex") {
      memory.lastTopic = "codex";
      memory.mentionedModules = true;
      return [
        "Bitcoin Treasury Codex is a future trading product — a way this page could get more active later.",
        "",
        "It is not live. There is no trade button here, no auto-trading, and no exchange key to paste. When Matthew is ready, he can introduce it properly.",
        "",
        "Same pay page as the other modules, with no amount until Matthew sets one:",
        payHref("BTC-TREASURY-CODEX"),
        "",
        "Want to stay with research, or sketch your accounts so the page has something to grow from?"
      ].join("\n");
    }

    if (topic === "modules") {
      memory.lastTopic = "modules";
      memory.mentionedModules = true;
      return [
        "Think of these as power-ups for this page. Different name, price, and SKU — all of them use the same pay page. Pay is not live yet.",
        "",
        "1. Macro×BTC research — bigger climate. " + payHref("MACRO-BTC"),
        "2. ETF flow research — fund flows. " + payHref("ETF-FLOW"),
        "3. Bitcoin module ($1.99 named module). " + payHref("HR-MOD-BTC"),
        "4. Bitcoin Treasury Codex — future trading product, not live. " + payHref("BTC-TREASURY-CODEX"),
        "",
        "Which of those do you want in plain English?"
      ].join("\n");
    }

    if (isShortAffirm(normalize(spoken)) && memory.lastTopic) {
      return replyFor(memory.lastTopic === "hello" ? "help" : memory.lastTopic, memory, spoken);
    }

    if (isDefer(normalize(spoken))) {
      memory.lastTopic = "help";
      return [
        "That’s fine. Nothing has to be decided today.",
        "",
        "We can sit with a light picture: you, this page, and a coach that does not ask for logins. When you want to go further, we can name accounts, talk goals, or walk the research modules.",
        "",
        "I’m here either way."
      ].join("\n");
    }

    memory.lastTopic = "general";
    return [
      "I’m with you.",
      "",
      spoken
        ? "I heard you. I won’t turn that into a form or a login."
        : "Say it in whatever words are comfortable.",
      "",
      "Useful next steps from here:",
      "• Name accounts you already have — no passwords.",
      "• Say a goal in plain English.",
      "• Hear about research modules that can power up this page later.",
      "",
      "Which of those feels easiest?"
    ].join("\n");
  }

  function scriptedReply(userText, memory) {
    var topic = detectTopic(normalize(userText));
    if (looksLikeSecret(userText)) {
      topic = "secret";
    }
    return replyFor(topic, memory, userText);
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
        body: JSON.stringify({ messages: messages, client: FULL_NAME })
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
      lastTopic: "hello",
      mentionedAccounts: false,
      mentionedGoals: false,
      mentionedModules: false,
      mentionedConnect: false,
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
    looksLikeSecret: looksLikeSecret,
    reply: function (userText, memory, history) {
      var local = scriptedReply(userText, memory || createMemory());
      return askLive(history || []).then(function (live) {
        return live || local;
      });
    }
  };
})(window);

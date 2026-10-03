/**
 * Back-compat Grok client for van.html. Same generic page logic as client-grok.js.
 * Browser talks to van-grok.php (thin wrapper around client-grok.php).
 */
(function (global) {
  "use strict";

  var PROXY = "van-grok.php";
  var MODEL = "grok-4.6";

  function firstNameOf(name) {
    var parts = String(name || "").trim().split(/\s+/);
    if (!parts[0]) return "there";
    return parts[0];
  }

  function who() {
    var c = global.HalfacreClient;
    if (c && (c.id || c.userId || c.name)) {
      return {
        fullName: String(c.name || ""),
        firstName: firstNameOf(c.name),
        userId: String(c.id || c.userId || "")
      };
    }
    return {
      fullName: "",
      firstName: "there",
      userId: ""
    };
  }

  var PASTED_KEY_RE = /(?:^|\s)[A-Za-z0-9_\-]{24,}(?:\s|$)/;
  var SECRET_WORDS_RE =
    /\b(password|passwd|passcode|pin\b|secret[_\s-]?key|private[_\s-]?key|seed[_\s-]?phrase|recovery[_\s-]?phrase|mnemonic|ssn|social security)\b/i;

  function greeting() {
    return "Grok did not answer just now. Send that again.";
  }

  var STARTERS = [
    { label: "Upload a statement", send: "I have a statement I can upload." },
    { label: "Bank and brokerage", send: "I want to start with bank and brokerage." },
    { label: "Retirement", send: "I want to start with retirement accounts." },
    { label: "Just talk", send: "I want to talk first before I upload anything." }
  ];

  function looksLikePastedSecret(text) {
    var raw = String(text || "");
    return PASTED_KEY_RE.test(raw) || SECRET_WORDS_RE.test(raw);
  }

  function sfoxConnected() {
    return global.VanSfox && global.VanSfox.state && global.VanSfox.state().connected;
  }

  function sfoxHoldings() {
    if (global.VanSfox && typeof global.VanSfox.snapshotText === "function") {
      return global.VanSfox.snapshotText();
    }
    return sfoxConnected() ? "sFOX is connected." : "sFOX is not connected.";
  }

  function secretBlock() {
    return [
      "Don’t type a key, password, or PIN in this chat.",
      "",
      "If you have an API box on this page, paste the key there. I never need to see it."
    ].join("\n");
  }

  function offlineNote() {
    return "Grok did not answer just now. Send that again.";
  }

  function endpoint() {
    if (typeof global.HALFACRE_GROK_ENDPOINT === "string" && global.HALFACRE_GROK_ENDPOINT.trim()) {
      return global.HALFACRE_GROK_ENDPOINT.trim();
    }
    return PROXY;
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

  function pageAuth() {
    var client = global.HalfacreClient || {};
    var tok = global.HalfacreSession && global.HalfacreSession.token
      ? global.HalfacreSession.token()
      : "";
    return {
      c: String(client.id || client.userId || ""),
      t: String(tok || "")
    };
  }

  function askGrokOnce(messages, opts) {
    opts = opts || {};
    var opening = !!opts.open;
    var auth = pageAuth();
    return fetchWithTimeout(
      endpoint(),
      {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        referrerPolicy: "no-referrer",
        body: JSON.stringify({
          messages: scrub(messages),
          c: auth.c,
          t: auth.t,
          sfox: false,
          open: opening
        })
      },
      60000
    )
      .then(function (res) {
        return res.json().then(function (data) {
          return { ok: res.ok, data: data };
        });
      })
      .then(function (pack) {
        if (pack.data && pack.data.comingSoon) {
          comingSoon = true;
        } else if (pack.data && pack.data.zdr === true) {
          comingSoon = false;
        }
        if (pack.data && typeof pack.data.reply === "string" && pack.data.reply.trim()) {
          return pack.data.reply.trim();
        }
        throw new Error((pack.data && pack.data.error) || "empty grok reply");
      });
  }

  function askGrok(messages, opts) {
    return askGrokOnce(messages, opts).catch(function () {
      return askGrokOnce(messages, opts);
    });
  }

  var comingSoon = true;
  var COMING_SOON =
    "Private AI chat and uploads are coming soon. We're finishing a privacy upgrade first.";

  global.HalfacreGrok = {
    get fullName() { return who().fullName; },
    get firstName() { return who().firstName; },
    engine: "grok",
    model: MODEL,
    isComingSoon: function () { return comingSoon; },
    comingSoonMessage: COMING_SOON,
    greeting: greeting,
    greetingText: greeting,
    open: function () {
      return askGrok([{ role: "user", content: "Hello." }], { open: true });
    },
    starters: STARTERS,
    mode: function () { return "grok"; },
    looksLikeSecret: looksLikePastedSecret,
    reply: function (userText, _memory, history) {
      if (looksLikePastedSecret(userText)) {
        return Promise.resolve(secretBlock());
      }
      return askGrok(history || []).catch(function (err) {
        var note = offlineNote();
        var error = new Error((err && err.message) || "offline grok");
        error.offlineNote = note;
        throw error;
      });
    },
    isOfflineNote: function (text) {
      return String(text || "").indexOf("Grok did not answer just now") !== -1
        || String(text || "").indexOf("Grok is not connected on this page right now") !== -1;
    }
  };
})(window);

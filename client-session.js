/**
 * One client identity for every desk page.
 * Van is Client 1. Same emailed token as everyone else. Talk is page.html?c=&t=.
 */
(function (global) {
  "use strict";

  var VAN = {
    id: "charley-van-halfacre",
    userId: "charlie-van-halfacre",
    name: "Charley Van Halfacre",
    number: 1,
    email: ""
  };

  function trim(value) {
    return String(value == null ? "" : value).trim();
  }

  function pathName() {
    var path = String(global.location.pathname || "").toLowerCase();
    return path.split("/").pop() || "";
  }

  function isVanId(id) {
    var v = trim(id).toLowerCase();
    return v === VAN.id || v === VAN.userId || v === "van" || v === "1";
  }

  var TOKEN_KEY = "halfacre.pageToken";
  var CHECK_EMAIL = "If this email already has an account, we just sent its private link there.";

  function queryC() {
    return trim(new URLSearchParams(global.location.search).get("c"));
  }

  function queryT() {
    return trim(new URLSearchParams(global.location.search).get("t"));
  }

  function sessionToken() {
    try {
      return trim(global.sessionStorage.getItem(TOKEN_KEY) || "");
    } catch (e) {
      return "";
    }
  }

  function rememberToken(token) {
    var t = trim(token);
    if (!t) return;
    try {
      global.sessionStorage.setItem(TOKEN_KEY, t);
    } catch (e) {}
  }

  function currentToken() {
    return queryT() || sessionToken();
  }

  function sessionClient() {
    try {
      return JSON.parse(global.localStorage.getItem("halfacre.session") || "null");
    } catch (e) {
      return null;
    }
  }

  function remember(client) {
    if (!client || !client.id) return client;
    try {
      global.localStorage.setItem("halfacre.session", JSON.stringify({
        id: client.id,
        name: client.name,
        number: client.number || null,
        email: client.email || ""
      }));
    } catch (e) {}
    return client;
  }

  function asClient(row) {
    if (!row || !row.id) return null;
    var id = trim(row.id);
    if (isVanId(id)) {
      return {
        id: VAN.id,
        userId: VAN.userId,
        name: VAN.name,
        number: 1,
        email: VAN.email
      };
    }
    return {
      id: id,
      userId: id,
      name: trim(row.name),
      number: row.number || null,
      email: trim(row.email)
    };
  }

  function withToken(url) {
    var next = String(url || "");
    var tok = currentToken();
    if (!tok || /[?&]t=/.test(next)) return next;
    var join = next.indexOf("?") >= 0 ? "&" : "?";
    return next + join + "t=" + encodeURIComponent(tok);
  }

  function talkUrl(client, hash) {
    var who = client || current();
    var url = "page.html";
    if (who && who.id) {
      url = "page.html?c=" + encodeURIComponent(who.id);
      url = withToken(url);
    }
    if (hash) url += (hash.charAt(0) === "#" ? hash : "#" + hash);
    return url;
  }

  function withClient(url, client) {
    var who = client || current();
    var next = String(url || "");
    if (who && who.id && !/[?&]c=/.test(next)) {
      var join = next.indexOf("?") >= 0 ? "&" : "?";
      next = next + join + "c=" + encodeURIComponent(who.id);
    }
    return withToken(next);
  }

  function shopUrl(kind, client) {
    return withClient("shop.html?kind=" + encodeURIComponent(kind || "research"), client);
  }

  function productUrl(id, client) {
    return withClient("product.html?id=" + encodeURIComponent(id), client);
  }

  function payUrl(row, client) {
    var base;
    if (global.HalfacrePay && global.HalfacrePay.buildUrl) {
      base = global.HalfacrePay.buildUrl(row);
    } else {
      base = "pay.html?id=" + encodeURIComponent(row && row.id ? row.id : "");
    }
    return withClient(base, client);
  }

  function current() {
    return global.HalfacreClient || null;
  }

  function setClient(client) {
    var who = asClient(client);
    if (!who) return null;
    global.HalfacreClient = who;
    remember(who);
    paintChrome(who);
    return who;
  }

  function paintChrome(client) {
    var who = client || current();
    if (!who) return;
    var label = who.number ? "Client " + who.number : "Your page";
    var whoEl = document.getElementById("who");
    var nameEl = document.getElementById("name");
    if (whoEl) whoEl.textContent = label;
    if (nameEl) nameEl.textContent = who.name || "Your page";
    if (who.name) document.title = who.name;
    Array.prototype.forEach.call(document.querySelectorAll("[data-shop]"), function (link) {
      link.href = shopUrl(link.getAttribute("data-shop"), who);
      link.referrerPolicy = "no-referrer";
    });
    Array.prototype.forEach.call(document.querySelectorAll("[data-talk]"), function (link) {
      var hash = link.getAttribute("data-talk");
      link.href = talkUrl(who, hash === "talk" ? "" : hash);
      link.referrerPolicy = "no-referrer";
    });
    var backTalk = document.getElementById("backTalk");
    if (backTalk) {
      backTalk.href = talkUrl(who);
      backTalk.referrerPolicy = "no-referrer";
    }
  }

  function fetchClient(id) {
    var tok = currentToken();
    if (!id || !tok) {
      return Promise.reject(new Error("link not valid"));
    }
    return fetch("client.php?c=" + encodeURIComponent(id) + "&t=" + encodeURIComponent(tok), { cache: "no-store" })
      .then(function (res) {
        return res.json().then(function (data) {
          return { ok: res.ok, data: data };
        });
      })
      .then(function (pack) {
        if (pack.ok && pack.data && pack.data.client && pack.data.client.name) {
          return asClient(pack.data.client);
        }
        throw new Error((pack.data && pack.data.error) || "link not valid");
      });
  }

  function resolve() {
    var c = queryC();
    var t = queryT();
    var file = pathName();
    var local = sessionClient();
    if (t) rememberToken(t);

    if (c) {
      if (!currentToken()) {
        return Promise.reject(new Error(CHECK_EMAIL));
      }
      return fetchClient(c).then(setClient);
    }
    if (file === "page.html") {
      return Promise.reject(new Error(CHECK_EMAIL));
    }
    if (local && local.id && currentToken()) {
      return fetchClient(local.id).then(setClient);
    }
    return Promise.reject(new Error(CHECK_EMAIL));
  }

  global.HalfacreSession = {
    van: VAN,
    isVanId: isVanId,
    current: current,
    setClient: setClient,
    resolve: resolve,
    token: currentToken,
    checkEmailMessage: CHECK_EMAIL,
    talkUrl: talkUrl,
    shopUrl: shopUrl,
    productUrl: productUrl,
    payUrl: payUrl,
    withClient: withClient,
    paintChrome: paintChrome
  };
})(window);

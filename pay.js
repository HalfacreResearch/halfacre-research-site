/**
 * PayPal JS SDK buttons. Create/capture go through server endpoints.
 * Fail closed when paypal-status.php says checkout is not available.
 */
(function (global) {
  "use strict";

  function trim(value) {
    return String(value == null ? "" : value).trim();
  }

  function qs() {
    return new URLSearchParams(global.location.search);
  }

  function skuFromQuery() {
    var q = qs();
    return trim(q.get("sku") || q.get("id"));
  }

  function setText(id, text) {
    var el = document.getElementById(id);
    if (el) el.textContent = text;
  }

  function token() {
    return (global.HalfacreSession && global.HalfacreSession.token)
      ? trim(global.HalfacreSession.token())
      : "";
  }

  function clientId() {
    return (global.HalfacreClient && global.HalfacreClient.id) || trim(qs().get("c"));
  }

  function authBody(extra) {
    var body = extra || {};
    body.c = clientId();
    body.t = token();
    return body;
  }

  function showHold(message) {
    var status = document.getElementById("payStatus");
    var box = document.getElementById("paypal-buttons");
    if (status) {
      status.hidden = false;
      status.textContent = message || "checkout not available yet";
    }
    if (box) box.hidden = true;
  }

  function loadSdk(clientKey) {
    return new Promise(function (resolve, reject) {
      if (global.paypal && global.paypal.Buttons) {
        resolve(global.paypal);
        return;
      }
      var script = document.createElement("script");
      script.src = "https://www.paypal.com/sdk/js?client-id=" + encodeURIComponent(clientKey) +
        "&currency=USD&intent=capture";
      script.onload = function () {
        if (global.paypal && global.paypal.Buttons) resolve(global.paypal);
        else reject(new Error("PayPal SDK missing"));
      };
      script.onerror = function () {
        reject(new Error("PayPal SDK failed to load"));
      };
      document.head.appendChild(script);
    });
  }

  function postJson(url, body) {
    return fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      cache: "no-store",
      body: JSON.stringify(body)
    }).then(function (res) {
      return res.json().then(function (data) {
        return { ok: res.ok && data && data.ok, status: res.status, data: data };
      });
    });
  }

  function paintItem(item) {
    setText("productName", item.name || item.sku);
    setText("productPrice", item.amount ? "$" + item.amount : "");
    setText("productDesc", "PayPal only. The server sets the price from the catalog.");
    var shop = document.getElementById("backShop");
    if (shop && global.HalfacreSession && global.HalfacreSession.shopUrl) {
      shop.href = global.HalfacreSession.shopUrl("research");
    }
  }

  function bootButtons(status, sku) {
    var box = document.getElementById("paypal-buttons");
    var hold = document.getElementById("payStatus");
    if (!box) return;
    if (hold) hold.hidden = true;
    box.hidden = false;
    return loadSdk(status.client_id).then(function (paypal) {
      paypal.Buttons({
        style: { layout: "vertical", color: "gold", shape: "rect", label: "paypal" },
        createOrder: function () {
          return postJson("paypal-order.php", authBody({ sku: sku })).then(function (pack) {
            if (!pack.ok || !pack.data || !pack.data.id) {
              throw new Error((pack.data && pack.data.error) || "checkout not available yet");
            }
            return pack.data.id;
          });
        },
        onApprove: function (data) {
          return postJson("paypal-capture.php", authBody({ orderID: data.orderID })).then(function (pack) {
            if (!pack.ok) {
              setText("payResult", (pack.data && pack.data.error) || "Capture failed.");
              return;
            }
            setText("payResult", "Payment recorded. Opening your page…");
            if (global.VanOwned && global.VanOwned.load) {
              global.VanOwned.load();
            }
            if (global.HalfacreDesk && global.HalfacreDesk.refresh) {
              global.HalfacreDesk.refresh();
            }
            var hash = (pack.data && pack.data.sku === "btc-treasury-bot") ? "btctreasury" : "purchased";
            var talk = (global.HalfacreSession && global.HalfacreSession.talkUrl)
              ? global.HalfacreSession.talkUrl(null, hash)
              : "page.html#" + hash;
            global.location.href = talk;
          });
        },
        onError: function () {
          setText("payResult", "PayPal could not finish checkout.");
        }
      }).render("#paypal-buttons");
    }).catch(function () {
      showHold("checkout not available yet");
    });
  }

  function start() {
    var sku = skuFromQuery();
    var session = global.HalfacreSession;
    if (!sku) {
      setText("productName", "No item selected");
      showHold("checkout not available yet");
      return;
    }
    if (!session) {
      showHold("checkout not available yet");
      return;
    }
    session.resolve().then(function () {
      if (global.HalfacreDesk && global.HalfacreDesk.mount) {
        global.HalfacreDesk.mount({
          id: clientId(),
          name: global.HalfacreClient && global.HalfacreClient.name,
          view: "shop"
        });
      }
      return fetch("paypal-status.php?sku=" + encodeURIComponent(sku), { cache: "no-store" });
    }).then(function (res) {
      return res.json();
    }).then(function (status) {
      if (!status || !status.available) {
        if (status && status.item) paintItem(status.item);
        else setText("productName", sku);
        showHold((status && status.message) || "checkout not available yet");
        return;
      }
      if (!status.item) {
        setText("productName", sku);
        showHold("That item is not for sale.");
        return;
      }
      paintItem(status.item);
      return bootButtons(status, status.item.sku);
    }).catch(function (err) {
      if (err && err.message && /link not valid|already has an account/i.test(err.message)) {
        setText("productName", "Open your emailed page link first");
        showHold("checkout not available yet");
        return;
      }
      showHold("checkout not available yet");
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start);
  } else {
    start();
  }
})(window);

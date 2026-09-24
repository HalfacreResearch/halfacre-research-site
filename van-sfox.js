/**
 * Live sFOX holdings on a client page.
 * Talks to van-sfox.php. The API key never ships in this file.
 */
(function (global) {
  "use strict";

  var API = "van-sfox.php";
  var snap = {
    connected: false,
    hint: "",
    holdings: [],
    totalUsd: null,
    asOf: 0,
    error: ""
  };

  function money(n) {
    var v = Number(n);
    if (!isFinite(v)) return "—";
    return v.toLocaleString(undefined, { style: "currency", currency: "USD" });
  }

  function amount(n, currency) {
    var v = Number(n);
    if (!isFinite(v)) return "—";
    var digits = /USD|USDC|USDT|DAI|PYUSD/i.test(currency || "") ? 2 : (Math.abs(v) >= 1 ? 6 : 8);
    return v.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: digits });
  }

  function paint() {
    var status = document.getElementById("sfoxStatus");
    var body = document.getElementById("sfoxBody");
    var count = document.getElementById("navSfoxCount");
    if (count) count.textContent = String(snap.holdings.length);
    if (!status || !body) return;

    if (snap.error) {
      status.textContent = snap.error;
      body.innerHTML = "";
      return;
    }
    if (!snap.connected) {
      status.textContent = "No sFOX account saved yet.";
      body.innerHTML = "<p class=\"side-empty\">Matthew saves the key on the desk. Balances show here.</p>";
      return;
    }
    if (!snap.holdings.length) {
      status.textContent = "Connected. No balances right now.";
      body.innerHTML = "";
      return;
    }

    var head = snap.totalUsd != null
      ? "About " + money(snap.totalUsd) + " across " + snap.holdings.length + " holding" + (snap.holdings.length === 1 ? "" : "s")
      : snap.holdings.length + " holding" + (snap.holdings.length === 1 ? "" : "s");
    status.textContent = head;

    var table = document.createElement("table");
    table.className = "sfox-table";
    table.innerHTML = "<thead><tr><th>Asset</th><th>Balance</th><th>Available</th><th>USD</th></tr></thead>";
    var tb = document.createElement("tbody");
    snap.holdings.forEach(function (row) {
      var tr = document.createElement("tr");
      tr.innerHTML =
        "<td>" + (row.currency || "") + "</td>" +
        "<td>" + amount(row.balance, row.currency) + "</td>" +
        "<td>" + amount(row.available, row.currency) + "</td>" +
        "<td>" + (row.usd != null ? money(row.usd) : "—") + "</td>";
      tb.appendChild(tr);
    });
    table.appendChild(tb);
    body.innerHTML = "";
    body.appendChild(table);
    var meta = document.getElementById("deskSfoxMeta");
    if (meta) {
      meta.textContent = snap.holdings.length + " holding" + (snap.holdings.length === 1 ? "" : "s");
    }
  }

  function load(clientId) {
    var id = String(clientId || (global.HalfacreClient && global.HalfacreClient.id) || "charley-van-halfacre");
    return fetch(API + "?c=" + encodeURIComponent(id), { cache: "no-store" })
      .then(function (res) { return res.json().then(function (data) { return { ok: res.ok, data: data }; }); })
      .then(function (pack) {
        var data = pack.data || {};
        snap = {
          connected: !!data.connected,
          hint: String(data.hint || ""),
          holdings: Array.isArray(data.holdings) ? data.holdings : [],
          totalUsd: data.totalUsd == null ? null : Number(data.totalUsd),
          asOf: Number(data.asOf || 0),
          error: pack.ok ? "" : String(data.error || "Could not load sFOX.")
        };
        paint();
        return snap;
      })
      .catch(function () {
        snap = { connected: false, hint: "", holdings: [], totalUsd: null, asOf: 0, error: "Could not load sFOX." };
        paint();
        return snap;
      });
  }

  function snapshotText() {
    if (snap.error) return "sFOX error: " + snap.error;
    if (!snap.connected) return "sFOX is not connected.";
    if (!snap.holdings.length) return "sFOX is connected. No balances.";
    var lines = snap.holdings.map(function (row) {
      var usd = row.usd != null ? " (~" + money(row.usd) + ")" : "";
      return "- " + row.currency + " " + amount(row.balance, row.currency) + usd;
    });
    if (snap.totalUsd != null) {
      lines.unshift("About " + money(snap.totalUsd) + " total.");
    }
    return lines.join("\n");
  }

  global.VanSfox = {
    load: load,
    paint: paint,
    state: function () {
      return { connected: snap.connected && !snap.error, hint: snap.hint };
    },
    snapshot: function () { return snap; },
    snapshotText: snapshotText
  };
})(window);

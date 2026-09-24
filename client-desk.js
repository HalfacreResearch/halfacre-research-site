/**
 * Sidebar + nav for uploads and purchases on a client page.
 * Talks to client-memory.php. Does not store file bytes.
 */
(function (global) {
  "use strict";

  var API = "client-memory.php";
  var state = { uploads: [], purchases: [] };
  var client = { id: "", name: "" };
  var view = "talk";

  function trim(value) {
    return String(value == null ? "" : value).trim();
  }

  function kindFromName(name) {
    var n = trim(name).toLowerCase();
    if (/(tax|1040|w-?2|1099|return)/.test(n)) return "tax";
    if (/(bank|checking|savings|statement)/.test(n)) return "bank";
    if (/(coinbase|binance|kraken|sfox|exchange|crypto)/.test(n)) return "exchange";
    if (/(schwab|fidelity|etrade|broker|robinhood)/.test(n)) return "brokerage";
    if (/(401k|401|ira|403b|retirement)/.test(n)) return "retirement";
    return "file";
  }

  function kindLabel(kind) {
    var map = {
      tax: "Tax",
      bank: "Bank",
      exchange: "Exchange",
      brokerage: "Brokerage",
      retirement: "Retirement",
      file: "File",
      module: "Avatar upgrade",
      sfox: "Exchange"
    };
    return map[kind] || "File";
  }

  function listEl(id, rows, emptyText, nameKey) {
    var box = document.getElementById(id);
    if (!box) return;
    box.innerHTML = "";
    if (!rows.length) {
      var p = document.createElement("p");
      p.className = "side-empty";
      p.textContent = emptyText;
      box.appendChild(p);
      return;
    }
    var ul = document.createElement("ul");
    ul.className = "side-list";
    rows.forEach(function (row) {
      var li = document.createElement("li");
      var title = document.createElement("span");
      title.textContent = row[nameKey] || row.name || row.id || "Item";
      var meta = document.createElement("span");
      meta.className = "kind";
      meta.textContent = kindLabel(row.kind || row.tier || "file");
      li.appendChild(title);
      li.appendChild(meta);
      ul.appendChild(li);
    });
    box.appendChild(ul);
  }

  function sfoxMeta() {
    var snap = global.VanSfox && global.VanSfox.snapshot ? global.VanSfox.snapshot() : null;
    if (!snap) return "Exchange";
    if (snap.error) return "Could not load";
    if (!snap.connected) return "Not saved yet";
    if (!snap.holdings.length) return "Connected · no balances";
    return snap.holdings.length + " holding" + (snap.holdings.length === 1 ? "" : "s");
  }

  function paintSfox() {
    var box = document.getElementById("deskSfox");
    if (!box) return;
    box.innerHTML = "";
    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "side-link" + (view === "sfox" ? " active" : "");
    btn.setAttribute("data-desk", "sfox");
    var title = document.createElement("span");
    title.textContent = "sFOX";
    var meta = document.createElement("span");
    meta.className = "kind";
    meta.id = "deskSfoxMeta";
    meta.textContent = sfoxMeta();
    btn.appendChild(title);
    btn.appendChild(meta);
    btn.addEventListener("click", function () {
      showView("sfox");
    });
    box.appendChild(btn);
  }

  function showView(name) {
    view = name === "sfox" ? "sfox" : "talk";
    var app = document.querySelector(".app");
    if (app) app.setAttribute("data-view", view);
    Array.prototype.forEach.call(document.querySelectorAll("[data-view-panel]"), function (panel) {
      panel.hidden = panel.getAttribute("data-view-panel") !== view;
    });
    Array.prototype.forEach.call(document.querySelectorAll(".nav a"), function (link) {
      link.classList.toggle("active", link.getAttribute("data-desk") === view);
    });
    paintSfox();
    if (view === "sfox") {
      app.classList.add("show-side");
      if (global.VanSfox && global.VanSfox.load) {
        global.VanSfox.load(client.id || "charley-van-halfacre").then(paintSfox);
      } else if (global.VanSfox && global.VanSfox.paint) {
        global.VanSfox.paint();
      }
    }
    if (view === "talk") {
      var line = document.getElementById("line");
      if (line) line.focus();
    }
  }

  function paint() {
    listEl("deskUploads", state.uploads, "Nothing uploaded yet.", "name");
    listEl("deskBuys", state.purchases, "No Avatar upgrades purchased yet.", "name");
    paintSfox();
    var upCount = document.getElementById("navUploadsCount");
    var buyCount = document.getElementById("navBuysCount");
    if (upCount) upCount.textContent = String(state.uploads.length);
    if (buyCount) buyCount.textContent = String(state.purchases.length);
  }

  function mergePurchases(extra) {
    var seen = {};
    var next = [];
    (state.purchases || []).concat(extra || []).forEach(function (row) {
      if (!row) return;
      var id = trim(row.id || row.name);
      if (!id || seen[id]) return;
      seen[id] = true;
      next.push({
        id: id,
        name: row.name || row.avatar_upgrade_label || id,
        kind: row.kind || "module"
      });
    });
    state.purchases = next;
  }

  function pullOwned() {
    if (!global.VanOwned || typeof global.VanOwned.ownedModules !== "function") {
      return;
    }
    try {
      mergePurchases(global.VanOwned.ownedModules());
    } catch (e) {}
  }

  function load() {
    var id = trim(client.id);
    if (!id) {
      paint();
      return Promise.resolve(state);
    }
    return fetch(API + "?c=" + encodeURIComponent(id), { cache: "no-store" })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (data && data.ok) {
          state.uploads = Array.isArray(data.uploads) ? data.uploads : [];
          state.purchases = Array.isArray(data.purchases) ? data.purchases : [];
        }
        pullOwned();
        paint();
        return state;
      })
      .catch(function () {
        pullOwned();
        paint();
        return state;
      });
  }

  function recordUpload(fileName, kind) {
    var name = trim(fileName);
    if (!name) return Promise.resolve(state);
    var row = { name: name, kind: kind || kindFromName(name), at: Date.now() };
    state.uploads = state.uploads.concat([row]);
    paint();
    return fetch(API, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ c: client.id, action: "upload", name: row.name, kind: row.kind })
    }).then(function (res) { return res.json(); }).then(function (data) {
      if (data && data.ok && Array.isArray(data.uploads)) state.uploads = data.uploads;
      if (data && data.ok && Array.isArray(data.purchases)) state.purchases = data.purchases;
      pullOwned();
      paint();
      return state;
    }).catch(function () { return state; });
  }

  function bindNav() {
    var top = document.querySelector(".top");
    var toggle = document.getElementById("navToggle");
    if (toggle && top) {
      toggle.addEventListener("click", function () {
        top.classList.toggle("open");
        document.querySelector(".app").classList.toggle("show-side");
      });
    }
    Array.prototype.forEach.call(document.querySelectorAll("[data-desk]"), function (link) {
      link.addEventListener("click", function (e) {
        var where = link.getAttribute("data-desk");
        if (where === "uploaded" || where === "purchased") {
          e.preventDefault();
          document.querySelector(".app").classList.add("show-side");
          var target = document.getElementById(where === "uploaded" ? "sideUploads" : "sideBuys");
          if (target && target.scrollIntoView) target.scrollIntoView({ block: "start" });
          return;
        }
        if (where === "sfox") {
          e.preventDefault();
          showView("sfox");
          return;
        }
        if (where === "talk") {
          e.preventDefault();
          showView("talk");
        }
      });
    });
  }

  function mount(opts) {
    opts = opts || {};
    client = {
      id: trim(opts.id || (global.HalfacreClient && (global.HalfacreClient.id || global.HalfacreClient.userId))),
      name: trim(opts.name || (global.HalfacreClient && global.HalfacreClient.name))
    };
    bindNav();
    if (global.VanOwned && typeof global.VanOwned.onChange === "function") {
      global.VanOwned.onChange(function () { pullOwned(); paint(); });
    }
    showView("talk");
    return load();
  }

  global.HalfacreDesk = {
    mount: mount,
    load: load,
    refresh: load,
    recordUpload: recordUpload,
    kindFromName: kindFromName,
    showView: showView,
    paintSfox: paintSfox,
    state: function () { return state; }
  };
})(window);

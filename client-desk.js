/**
 * Sidebar + nav for uploads and purchases on a client page.
 * Talks to client-memory.php. Does not store file bytes.
 */
(function (global) {
  "use strict";

  var API = "client-memory.php";
  var state = { uploads: [], purchases: [] };
  var client = { id: "", name: "" };

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
      module: "Avatar upgrade"
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

  function paint() {
    listEl("deskUploads", state.uploads, "Nothing uploaded yet.", "name");
    listEl("deskBuys", state.purchases, "No Avatar upgrades purchased yet.", "name");
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
        }
        if (where === "talk") {
          var line = document.getElementById("line");
          if (line) line.focus();
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
    return load();
  }

  global.HalfacreDesk = {
    mount: mount,
    load: load,
    refresh: load,
    recordUpload: recordUpload,
    kindFromName: kindFromName,
    state: function () { return state; }
  };
})(window);

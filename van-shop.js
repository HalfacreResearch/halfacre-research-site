/**
 * Shop lists and product pages. Catalog comes from van-products.json via pay-catalog.js.
 */
(function (global) {
  "use strict";

  var SECTIONS = {
    research: {
      kind: "research",
      title: "Research Modules",
      blurb: "One module is one unlock. $4.99 when it is live. Click a name for the full product page."
    },
    packs: {
      kind: "pack",
      title: "Research Packs",
      blurb: "A pack unlocks a whole sleeve. $29.99 when it is live. Click a name for the full product page."
    },
    bots: {
      kind: "bot",
      title: "Trading Bots",
      blurb: "Assembled systems. $149 when it is live. Click a name for the full product page."
    }
  };

  var GROUP_ORDER = [
    "research",
    "crypto",
    "tech",
    "dividend",
    "metals",
    "commodities",
    "etf",
    "mutual_fund",
    "wrappers"
  ];

  function trim(value) {
    return String(value == null ? "" : value).trim();
  }

  function copy() {
    return global.VanShopCopy || {
      sentence: function (row) { return trim(row && row.description) || trim(row && row.name); },
      body: function () { return ""; },
      groupLabel: function (key) { return key; }
    };
  }

  function money(row) {
    if (global.HalfacrePay && global.HalfacrePay.formatMoney) {
      return global.HalfacrePay.formatMoney(row.price_usd != null ? row.price_usd : row.amount);
    }
    var n = Number(row && row.price_usd);
    if (!Number.isFinite(n)) return "";
    if (n === 0) return "Free";
    return "$" + n.toFixed(2);
  }

  function owns(id) {
    return !!(global.VanOwned && global.VanOwned.owns && global.VanOwned.owns(id));
  }

  function statusText(row) {
    if (owns(row.id)) return "Owned";
    if (row.live) return "Live";
    return "Coming soon";
  }

  function products() {
    return (global.HalfacrePay && global.HalfacrePay.products) || [];
  }

  function sectionRows(kind) {
    return products().filter(function (row) {
      return trim(row.kind) === kind;
    });
  }

  function find(id) {
    var want = trim(id).toLowerCase();
    var list = products();
    var i;
    for (i = 0; i < list.length; i += 1) {
      if (trim(list[i].id).toLowerCase() === want) {
        return list[i];
      }
    }
    return null;
  }

  function sectionOf(row) {
    var kind = trim(row && row.kind);
    if (kind === "pack") return "packs";
    if (kind === "bot") return "bots";
    return "research";
  }

  function session() {
    return global.HalfacreSession;
  }

  function shopUrl(kind) {
    if (session() && session().shopUrl) return session().shopUrl(kind);
    return "shop.html?kind=" + encodeURIComponent(kind || "research");
  }

  function productUrl(id) {
    if (session() && session().productUrl) return session().productUrl(id);
    return "product.html?id=" + encodeURIComponent(id);
  }

  function payUrl(row) {
    if (session() && session().payUrl) return session().payUrl(row);
    if (global.HalfacrePay && global.HalfacrePay.buildUrl) {
      return global.HalfacrePay.buildUrl(row);
    }
    return "pay.html?id=" + encodeURIComponent(row.id);
  }

  function talkUrl() {
    if (session() && session().talkUrl) return session().talkUrl();
    return "van.html";
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null && text !== "") node.textContent = text;
    return node;
  }

  function groupKey(row) {
    return trim(row.department) || (row.departments && row.departments[0]) || "other";
  }

  function sortRows(rows) {
    return rows.slice().sort(function (a, b) {
      if (!!a.live !== !!b.live) return a.live ? -1 : 1;
      var ga = GROUP_ORDER.indexOf(groupKey(a));
      var gb = GROUP_ORDER.indexOf(groupKey(b));
      if (ga === -1) ga = 99;
      if (gb === -1) gb = 99;
      if (ga !== gb) return ga - gb;
      return trim(a.name).localeCompare(trim(b.name));
    });
  }

  function renderList(root, kind) {
    var meta = SECTIONS[kind] || SECTIONS.research;
    var rows = sortRows(sectionRows(meta.kind));
    root.innerHTML = "";

    var card = el("section", "card shop-list");
    card.appendChild(el("h2", "", meta.title));
    card.appendChild(el("p", "sub shop-blurb", meta.blurb));

    if (!rows.length) {
      card.appendChild(el("p", "empty", "Could not load the product list. Refresh and try again."));
      root.appendChild(card);
      return;
    }

    var liveCount = rows.filter(function (row) { return row.live; }).length;
    card.appendChild(el(
      "p",
      "shop-count",
      rows.length + " " + (rows.length === 1 ? "item" : "items") +
        " · " + liveCount + " live · " + (rows.length - liveCount) + " coming soon"
    ));

    var list = el("div", "product-list");
    var lastGroup = "";
    rows.forEach(function (row) {
      var group = groupKey(row);
      if (meta.kind === "research" && group !== lastGroup) {
        lastGroup = group;
        list.appendChild(el("h3", "product-group", copy().groupLabel(group)));
      }
      var a = el("a", "product-row");
      a.href = productUrl(row.id);
      var top = el("div", "product-row-top");
      top.appendChild(el("strong", "product-name", row.name || row.id));
      top.appendChild(el("span", "product-price", money(row)));
      a.appendChild(top);
      a.appendChild(el("p", "product-sales", copy().sentence(row)));
      a.appendChild(el("span", "product-status" + (row.live ? " live" : " soon"), statusText(row)));
      list.appendChild(a);
    });
    card.appendChild(list);
    root.appendChild(card);
  }

  function renderProduct(root, id) {
    var row = find(id);
    root.innerHTML = "";
    if (!row) {
      var miss = el("section", "card");
      miss.appendChild(el("h2", "", "Product"));
      miss.appendChild(el("p", "empty", "That product is not on the list."));
      var back = el("p", "");
      var backLink = el("a", "btn ghost", "Back to Research Modules");
      backLink.href = shopUrl("research");
      back.appendChild(backLink);
      miss.appendChild(back);
      root.appendChild(miss);
      return;
    }

    var kind = sectionOf(row);
    var card = el("section", "card product-page");
    card.appendChild(el("p", "product-kicker", SECTIONS[kind].title));
    card.appendChild(el("h1", "product-title", row.name || row.id));
    card.appendChild(el("p", "product-sales-lead", copy().sentence(row)));

    var facts = el("div", "product-facts");
    facts.appendChild(el("p", "price", money(row)));
    facts.appendChild(el("p", "product-status" + (row.live ? " live" : " soon"), statusText(row)));
    card.appendChild(facts);

    card.appendChild(el("p", "product-body", copy().body(row)));

    if (owns(row.id)) {
      card.appendChild(el("p", "product-owned", "This is already on your avatar."));
      if (row.kind !== "bot" && global.VanOwned && global.VanOwned.downloadUrl) {
        var dl = el("a", "btn", "Re-fetch download");
        dl.href = global.VanOwned.downloadUrl(row.id);
        card.appendChild(dl);
      }
    } else if (row.live && !row.free) {
      var buy = el("a", "btn", "Buy " + money(row));
      buy.href = payUrl(row);
      card.appendChild(buy);
    } else if (row.free) {
      var open = el("a", "btn", "Open Codex");
      open.href = talkUrl() + "#codex";
      card.appendChild(open);
    } else {
      card.appendChild(el("p", "product-soon", "On the list. Not for sale until the data is built."));
      var disabled = el("button", "btn", "Coming soon");
      disabled.type = "button";
      disabled.disabled = true;
      card.appendChild(disabled);
    }

    var nav = el("p", "product-back");
    var listLink = el("a", "btn ghost", "Back to " + SECTIONS[kind].title);
    listLink.href = shopUrl(kind);
    var talkLink = el("a", "btn ghost", "Back to talk");
    talkLink.href = talkUrl();
    nav.appendChild(listLink);
    nav.appendChild(talkLink);
    card.appendChild(nav);
    root.appendChild(card);
  }

  function queryKind() {
    var q = new URLSearchParams(global.location.search);
    var kind = trim(q.get("kind")).toLowerCase();
    if (kind === "pack") kind = "packs";
    if (kind === "module" || kind === "modules") kind = "research";
    if (kind === "bot") kind = "bots";
    if (!SECTIONS[kind]) kind = "research";
    return kind;
  }

  function queryId() {
    var q = new URLSearchParams(global.location.search);
    return trim(q.get("id") || q.get("sku"));
  }

  function loadCatalog() {
    if (!global.HalfacrePay || !global.HalfacrePay.load) {
      return Promise.reject(new Error("catalog missing"));
    }
    return global.HalfacrePay.load().then(function () {
      if (global.VanOwned && global.VanOwned.load) {
        return global.VanOwned.load();
      }
    });
  }

  function mountList(rootId) {
    var root = document.getElementById(rootId || "shopRoot");
    if (!root) return Promise.resolve();
    var kind = queryKind();
    document.title = SECTIONS[kind].title + " · Halfacre Research";
    var heading = document.getElementById("shopHeading");
    if (heading) heading.textContent = SECTIONS[kind].title;
    root.textContent = "Loading…";
    return loadCatalog().then(function () {
      renderList(root, kind);
    }).catch(function () {
      root.innerHTML = "";
      root.appendChild(el("p", "empty", "Could not load the product list."));
    });
  }

  function mountProduct(rootId) {
    var root = document.getElementById(rootId || "productRoot");
    if (!root) return Promise.resolve();
    var id = queryId();
    root.textContent = "Loading…";
    return loadCatalog().then(function () {
      var row = find(id);
      if (row) document.title = (row.name || id) + " · Halfacre Research";
      renderProduct(root, id);
    }).catch(function () {
      root.innerHTML = "";
      root.appendChild(el("p", "empty", "Could not load that product."));
    });
  }

  global.VanShop = {
    sections: SECTIONS,
    shopUrl: shopUrl,
    productUrl: productUrl,
    mountList: mountList,
    mountProduct: mountProduct,
    sectionOf: sectionOf
  };
})(window);

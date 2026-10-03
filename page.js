/**
 * Client page only: brand empty copy and the invalid-link gate.
 * Does not change token checks. HalfacreSession.resolve still decides access.
 */
(function (global) {
  "use strict";

  var EMPTY = {
    uploads: "No uploads yet. Add a statement when you are ready.",
    buys: "No purchases yet. Our product line is coming soon.",
    notes: "No saved notes yet."
  };

  function byId(id) {
    return document.getElementById(id);
  }

  function applyEmptyCopy() {
    var uploads = byId("deskUploads");
    if (uploads) {
      var uploadEmpty = uploads.querySelector(".side-empty");
      if (uploadEmpty) uploadEmpty.textContent = EMPTY.uploads;
    }

    var buys = byId("deskBuys");
    if (buys) {
      var buyEmpty = buys.querySelector(".side-empty");
      if (buyEmpty) buyEmpty.textContent = EMPTY.buys;
    }

    var log = byId("log");
    var talkEmpty = byId("talkEmpty");
    if (talkEmpty && log) {
      talkEmpty.hidden = !!log.querySelector(".msg");
    }
  }

  function watchEmpty() {
    applyEmptyCopy();
    var roots = [byId("sidebar"), byId("log")];
    roots.forEach(function (root) {
      if (!root || root.getAttribute("data-empty-watch") === "1") return;
      root.setAttribute("data-empty-watch", "1");
      var obs = new MutationObserver(applyEmptyCopy);
      obs.observe(root, { childList: true, subtree: true });
    });
  }

  function setHidden(el, hidden) {
    if (!el) return;
    el.hidden = !!hidden;
  }

  function showDesk() {
    setHidden(byId("stage"), true);
    setHidden(byId("desk"), false);
    var app = byId("app");
    if (app) app.hidden = false;
    watchEmpty();
  }

  function showGate(err) {
    var raw = (err && err.message) ||
      (global.HalfacreSession && global.HalfacreSession.checkEmailMessage) ||
      "link not valid";
    var title = byId("stageTitle");
    var body = byId("stageBody");
    var btn = byId("stageBtn");

    setHidden(byId("desk"), true);
    setHidden(byId("stage"), false);

    if (raw === "link not valid") {
      if (title) title.textContent = "This private link is not valid or has expired.";
      if (body) {
        body.hidden = false;
        body.textContent = "Enter your email on the signup page and we will send a new private link to the address we have on file.";
      }
    } else {
      if (title) title.textContent = raw;
      if (body) {
        body.hidden = false;
        body.textContent = "Enter your email on the signup page and we will email your private link.";
      }
    }

    if (btn) {
      btn.hidden = false;
      btn.textContent = "Get a new private link";
      btn.setAttribute("href", "/signup.html");
    }
  }

  function boot() {
    var session = global.HalfacreSession;
    if (!session || !session.resolve) {
      showGate(new Error("link not valid"));
      return;
    }

    session.resolve().then(function () {
      showDesk();
      if (global.HalfacrePage && global.HalfacrePage.bootTalk) {
        global.HalfacrePage.bootTalk();
      }
      watchEmpty();
    }).catch(function (err) {
      showGate(err);
    });
  }

  global.HalfacreClientPage = {
    boot: boot,
    applyEmptyCopy: applyEmptyCopy,
    showGate: showGate,
    showDesk: showDesk
  };
})(window);

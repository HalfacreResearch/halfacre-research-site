/**
 * Talk + upload boot for every client page (Van’s layout is the template).
 */
(function (global) {
  "use strict";

  function bootTalk() {
    var client = global.HalfacreClient;
    var grok = global.HalfacreGrok;
    var log = document.getElementById("log");
    var form = document.getElementById("form");
    var line = document.getElementById("line");
    var chips = document.getElementById("chips");
    var file = document.getElementById("file");
    if (!form || !log || !line) return;

    var sendBtn = form.querySelector(".send");
    var history = [];
    var fileInput = file;
    var uploadBtn = document.getElementById("uploadBtn");
    var uploadHint = document.getElementById("uploadHint");
    var uploadSoon = document.getElementById("uploadSoon");

    function setComingSoon(on, message) {
      var msg = message || (grok && grok.comingSoonMessage) ||
        "Private AI chat and uploads are coming soon. We're finishing a privacy upgrade first.";
      if (uploadSoon) {
        uploadSoon.hidden = !on;
        uploadSoon.textContent = msg;
      }
      if (uploadHint) uploadHint.hidden = !!on;
      if (uploadBtn) {
        uploadBtn.setAttribute("aria-disabled", on ? "true" : "false");
        uploadBtn.style.display = on ? "none" : "";
      }
      if (fileInput) fileInput.disabled = !!on;
      if (line) {
        line.disabled = !!on;
        line.placeholder = on ? "Private AI chat is coming soon" : "Talk to Grok";
      }
      if (sendBtn) sendBtn.disabled = !!on;
      if (chips) chips.hidden = !!on;
    }

    setComingSoon(true);

    if (global.HalfacrePay && global.HalfacrePay.load) {
      global.HalfacrePay.load().then(function () {
        if (global.VanOwned && global.VanOwned.load) {
          return global.VanOwned.load();
        }
      }).then(function () {
        if (global.HalfacreDesk) global.HalfacreDesk.refresh();
      }).catch(function () {
        if (global.HalfacreDesk) global.HalfacreDesk.refresh();
      });
    }

    var deskReady = global.HalfacreDesk
      ? global.HalfacreDesk.mount({
          id: client && client.id,
          name: client && client.name
        })
      : Promise.resolve();

    var sfoxReady = loadSfox(client);

    function bubble(text, mine) {
      var el = document.createElement("div");
      el.className = mine ? "msg me" : "msg";
      el.textContent = text;
      log.appendChild(el);
      el.scrollIntoView({ block: "end" });
    }

    function waitNote() {
      var el = document.createElement("div");
      el.className = "msg wait";
      el.id = "grokWait";
      el.textContent = "Grok is thinking…";
      log.appendChild(el);
      el.scrollIntoView({ block: "end" });
    }

    function clearWait() {
      var el = document.getElementById("grokWait");
      if (el && el.parentNode) el.parentNode.removeChild(el);
    }

    function talk(text, silent) {
      if (!text) return;
      if (grok && grok.isComingSoon && grok.isComingSoon()) {
        setComingSoon(true);
        return;
      }
      if (global.HalfacreDesk && global.HalfacreDesk.showView) {
        global.HalfacreDesk.showView("talk");
      }
      if (!silent) bubble(text, true);
      history.push({ role: "user", content: text });
      sendBtn.disabled = true;
      waitNote();
      var work = grok && grok.reply
        ? grok.reply(text, null, history)
        : Promise.resolve("");
      Promise.resolve(work).then(function (reply) {
        var out = String(reply || "").trim();
        if (grok && grok.isComingSoon && grok.isComingSoon()) {
          clearWait();
          setComingSoon(true, out);
          bubble(out, false);
          return;
        }
        if (!out || (grok && grok.isOfflineNote && grok.isOfflineNote(out))) {
          throw new Error("offline");
        }
        clearWait();
        setComingSoon(false);
        bubble(out, false);
        history.push({ role: "assistant", content: out });
      }).catch(function () {
        clearWait();
        bubble("Grok did not answer just now. Send that again.", false);
      }).finally(function () {
        if (!(grok && grok.isComingSoon && grok.isComingSoon()) && sendBtn) {
          sendBtn.disabled = false;
        }
      });
    }

    if (grok && grok.starters && chips) {
      chips.innerHTML = "";
      grok.starters.forEach(function (row) {
        var b = document.createElement("button");
        b.type = "button";
        b.className = "chip";
        b.textContent = row.label;
        b.onclick = function () { talk(row.send); };
        chips.appendChild(b);
      });
    }

    function openGrok() {
      sendBtn.disabled = true;
      waitNote();
      var work = grok && grok.open
        ? grok.open()
        : Promise.reject(new Error("offline"));
      Promise.resolve(work).then(function (reply) {
        var out = String(reply || "").trim();
        if (!out) throw new Error("offline");
        clearWait();
        if (grok && grok.isComingSoon && grok.isComingSoon()) {
          setComingSoon(true, out);
          bubble(out, false);
          return;
        }
        setComingSoon(false);
        bubble(out, false);
        history.push({ role: "assistant", content: out });
      }).catch(function () {
        clearWait();
        bubble("Grok did not answer just now. Send a line and it will try again.", false);
      }).finally(function () {
        if (!(grok && grok.isComingSoon && grok.isComingSoon()) && sendBtn) {
          sendBtn.disabled = false;
        }
      });
    }

    Promise.resolve(deskReady).finally(function () {
      if (global.HalfacreDesk && global.HalfacreDesk.paintSfox) {
        global.HalfacreDesk.paintSfox();
      }
      openGrok();
    });
    Promise.resolve(sfoxReady).then(function () {
      if (global.HalfacreDesk && global.HalfacreDesk.paintSfox) {
        global.HalfacreDesk.paintSfox();
      }
    });

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      if (grok && grok.isComingSoon && grok.isComingSoon()) {
        return;
      }
      var text = line.value.trim();
      line.value = "";
      talk(text);
    });

    if (file) {
      file.addEventListener("change", function () {
        if (grok && grok.isComingSoon && grok.isComingSoon()) {
          file.value = "";
          return;
        }
        var picked = file.files && file.files[0];
        if (!picked) return;
        var desk = global.HalfacreDesk;
        if (desk && desk.recordUpload) {
          desk.recordUpload(picked.name, desk.kindFromName(picked.name));
        }
        var reader = new FileReader();
        reader.onload = function () {
          var raw = String(reader.result || "");
          var clip = raw.length > 12000 ? raw.slice(0, 12000) + "\n[truncated]" : raw;
          talk("I uploaded " + picked.name + ".\n\n" + clip);
        };
        if (/\.pdf$/i.test(picked.name)) {
          talk("I uploaded a PDF named " + picked.name + ".");
        } else {
          reader.readAsText(picked);
        }
        file.value = "";
      });
    }
  }

  function loadSfox(client) {
    var id = client && client.id;
    if (!(global.HalfacreSession && global.HalfacreSession.isVanId && global.HalfacreSession.isVanId(id))) {
      if (global.HalfacreDesk && global.HalfacreDesk.paintSfox) {
        global.HalfacreDesk.paintSfox();
      }
      return Promise.resolve();
    }
    if (!global.VanSfox || !global.VanSfox.load) {
      return Promise.resolve();
    }
    return global.VanSfox.load(id).then(function () {
      if (global.HalfacreDesk && global.HalfacreDesk.paintSfox) {
        global.HalfacreDesk.paintSfox();
      }
    }).catch(function () {
      if (global.HalfacreDesk && global.HalfacreDesk.paintSfox) {
        global.HalfacreDesk.paintSfox();
      }
    });
  }

  function bootShop(kind) {
    var session = global.HalfacreSession;
    return session.resolve().then(function (client) {
      if (global.HalfacrePay && global.HalfacrePay.load) {
        global.HalfacrePay.load().then(function () {
          if (global.VanOwned && global.VanOwned.load) {
            return global.VanOwned.load();
          }
        }).then(function () {
          if (global.HalfacreDesk) global.HalfacreDesk.refresh();
        }).catch(function () {
          if (global.HalfacreDesk) global.HalfacreDesk.refresh();
        });
      }

      var deskReady = global.HalfacreDesk
        ? global.HalfacreDesk.mount({
            id: client.id,
            name: client.name,
            view: "shop"
          })
        : Promise.resolve();

      return Promise.resolve(deskReady).then(function () {
        return loadSfox(client);
      }).then(function () {
        if (kind === "product") {
          return global.VanShop && global.VanShop.mountProduct("productRoot");
        }
        return global.VanShop && global.VanShop.mountList("shopRoot");
      });
    }).catch(function (err) {
      var root = document.getElementById(kind === "product" ? "productRoot" : "shopRoot");
      if (root) {
        root.textContent = (err && err.message) || "Could not open this page.";
      }
    });
  }

  global.HalfacrePage = {
    bootTalk: bootTalk,
    bootShop: bootShop
  };
})(window);

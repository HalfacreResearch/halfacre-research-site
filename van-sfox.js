/**
 * sFOX connect UI for Van’s Codex.
 *
 * Local / client-side only, pending a Vault. No live sFOX trading calls.
 * Never log the key. Never send it to chat, analytics, or Grok.
 */
(function (global) {
  "use strict";

  var STORAGE_KEY = "halfacre.van.sfox.v1";

  function readStore() {
    try {
      var raw = global.localStorage.getItem(STORAGE_KEY);
      if (!raw) {
        return { connected: false };
      }
      var data = JSON.parse(raw);
      if (!data || typeof data !== "object") {
        return { connected: false };
      }
      return data;
    } catch (err) {
      return { connected: false };
    }
  }

  function writeStore(data) {
    global.localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
  }

  function clearStore() {
    global.localStorage.removeItem(STORAGE_KEY);
  }

  function hintFrom(key) {
    var clean = String(key || "").replace(/\s+/g, "");
    if (clean.length < 4) {
      return "••••";
    }
    return "••••" + clean.slice(-4);
  }

  function state() {
    var data = readStore();
    var connected = Boolean(data.connected && data.hasKey);
    return {
      connected: connected,
      hint: connected ? String(data.hint || "••••") : "",
      savedAt: data.savedAt || 0
    };
  }

  function connect(apiKey) {
    var key = String(apiKey || "").replace(/\s+/g, "");
    if (key.length < 8) {
      return { ok: false, error: "That key looks too short. Use the sFOX API key from your sFOX account." };
    }
    writeStore({
      connected: true,
      hasKey: true,
      hint: hintFrom(key),
      savedAt: Date.now(),
      key: key
    });
    return { ok: true, state: state() };
  }

  function disconnect() {
    clearStore();
    return { ok: true, state: state() };
  }

  /**
   * Render the dedicated key field. The value is never copied into chat.
   */
  function bind(root) {
    var status = root.querySelector("[data-sfox-status]");
    var hint = root.querySelector("[data-sfox-hint]");
    var form = root.querySelector("[data-sfox-form]");
    var input = root.querySelector("[data-sfox-key]");
    var err = root.querySelector("[data-sfox-error]");
    var disconnectBtn = root.querySelector("[data-sfox-disconnect]");

    function paint() {
      var now = state();
      root.setAttribute("data-connected", now.connected ? "yes" : "no");
      if (status) {
        status.textContent = now.connected
          ? "3 · Connected — trading follow-on"
          : "1 · Not connected";
        status.classList.toggle("wait", !now.connected);
      }
      if (hint) {
        hint.textContent = now.connected
          ? "sFOX key saved on this device " + (now.hint ? "(" + now.hint + ")" : "") + ". Connected — trading follow-on. Live autotrade is not firing until keys + the autotrades-engine scheduler (sfoxEngine / dcaEngine / rotationEngine, admin tRPC on autotrades.codexyield.com) are wired."
          : "Step 2: paste the sFOX API key here — not in chat. That connect is the Codex unlock. Execution SoT is autotrades-engine server/sfoxEngine.ts — not a new engine on this page.";
      }
      var steps = root.querySelector("[data-sfox-steps]");
      if (steps) {
        steps.setAttribute("data-state", now.connected ? "connected" : "open");
      }
      if (form) {
        form.hidden = now.connected;
      }
      if (disconnectBtn) {
        disconnectBtn.hidden = !now.connected;
      }
      if (err) {
        err.textContent = "";
      }
      if (input) {
        input.value = "";
      }
      root.dispatchEvent(new CustomEvent("sfox-change", { detail: now, bubbles: true }));
    }

    if (form) {
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        var result = connect(input ? input.value : "");
        if (!result.ok) {
          if (err) {
            err.textContent = result.error;
          }
          return;
        }
        paint();
      });
    }
    if (disconnectBtn) {
      disconnectBtn.addEventListener("click", function () {
        disconnect();
        paint();
      });
    }
    paint();
    return { state: state, paint: paint };
  }

  global.VanSfox = {
    state: state,
    connect: connect,
    disconnect: disconnect,
    bind: bind
  };
})(window);

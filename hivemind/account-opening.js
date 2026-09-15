(function () {
  "use strict";

  var KEY_PREFIX = "hivemind.client.";
  var INDEX_KEY = "hivemind.clients";
  var DRAFT_KEY = "hivemind.draft";
  var NAMED_SLOTS = {
    "charlie-van-halfacre": "charlie-van-halfacre.html",
    "matthew-halfacre": "matthew-halfacre.html",
  };
  var STANDARD_AVATARS = [
    "avatars/standard-01.svg",
    "avatars/standard-02.svg",
    "avatars/standard-03.svg",
    "avatars/standard-04.svg",
  ];

  function emptyRecord() {
    return { name: "", email: "", phone: "", headshot: "", watchedWelcome: false, faceKind: "" };
  }

  function load(clientId) {
    try {
      var raw = window.localStorage.getItem(KEY_PREFIX + clientId);
      if (!raw) {
        return emptyRecord();
      }
      var parsed = JSON.parse(raw);
      return {
        name: typeof parsed.name === "string" ? parsed.name : "",
        email: typeof parsed.email === "string" ? parsed.email : "",
        phone: typeof parsed.phone === "string" ? parsed.phone : "",
        headshot: typeof parsed.headshot === "string" ? parsed.headshot : "",
        watchedWelcome: parsed.watchedWelcome === true,
        faceKind: parsed.faceKind === "upload" || parsed.faceKind === "standard" ? parsed.faceKind : "",
      };
    } catch (err) {
      return emptyRecord();
    }
  }

  function save(clientId, record) {
    window.localStorage.setItem(KEY_PREFIX + clientId, JSON.stringify(record));
    rememberClient(clientId);
  }

  function listClientIds() {
    try {
      var raw = window.localStorage.getItem(INDEX_KEY);
      var parsed = raw ? JSON.parse(raw) : [];
      if (!Array.isArray(parsed)) {
        return [];
      }
      return parsed.filter(function (id) {
        return typeof id === "string" && id.length > 0;
      });
    } catch (err) {
      return [];
    }
  }

  function rememberClient(clientId) {
    var ids = listClientIds();
    if (ids.indexOf(clientId) === -1) {
      ids.push(clientId);
      window.localStorage.setItem(INDEX_KEY, JSON.stringify(ids));
    }
  }

  function slugFromName(name) {
    var slug = name
      .toLowerCase()
      .trim()
      .replace(/[^a-z0-9]+/g, "-")
      .replace(/^-+|-+$/g, "");
    return slug || "client";
  }

  function uniqueSlug(name) {
    var base = slugFromName(name);
    var slug = base;
    var n = 2;
    while (window.localStorage.getItem(KEY_PREFIX + slug)) {
      slug = base + "-" + n;
      n += 1;
    }
    return slug;
  }

  function identityCount(record) {
    var n = 0;
    if (record.name.trim()) n += 1;
    if (record.email.trim()) n += 1;
    if (record.phone.trim()) n += 1;
    return n;
  }

  function isClient(record) {
    return identityCount(record) === 3;
  }

  function isStandardAvatar(src) {
    return STANDARD_AVATARS.indexOf(src) !== -1;
  }

  function hasFace(record) {
    if (!record.headshot) {
      return false;
    }
    if (record.faceKind === "standard") {
      return isStandardAvatar(record.headshot);
    }
    return true;
  }

  function firstActsDone(record) {
    return isClient(record) && record.watchedWelcome === true && hasFace(record);
  }

  function validEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim());
  }

  function validPhone(value) {
    var digits = value.replace(/\D/g, "");
    return digits.length >= 7 && digits.length <= 15;
  }

  function statusLine(record) {
    var n = identityCount(record);
    if (!isClient(record)) {
      return "Not a client yet. " + n + " of 3. Name, email, and phone create the client.";
    }
    if (!record.watchedWelcome) {
      return "You are a client. First act: watch the welcome from Matthew.";
    }
    if (!hasFace(record)) {
      return "You are a client. Next: upload a headshot or pick a standard avatar.";
    }
    if (record.faceKind === "standard") {
      return "You are a client. Welcome watched. Standard avatar chosen. The Hivemind is open.";
    }
    return "You are a client. Welcome watched. Headshot in. The Hivemind is open.";
  }

  function directoryLine(record) {
    if (!isClient(record)) {
      return "Not a client yet. " + identityCount(record) + " of 3. Waiting for name, email, and phone.";
    }
    if (!firstActsDone(record)) {
      return "Client. First acts still open: welcome video, then a face (upload or standard avatar).";
    }
    if (record.faceKind === "standard") {
      return "Client. Welcome watched. Standard avatar chosen. They can use the Hivemind.";
    }
    return "Client. Welcome watched. Headshot in. They can use the Hivemind.";
  }

  function pageFor(clientId) {
    if (NAMED_SLOTS[clientId]) {
      return NAMED_SLOTS[clientId];
    }
    return "client.html?id=" + encodeURIComponent(clientId);
  }

  function queryId() {
    try {
      var params = new URLSearchParams(window.location.search);
      var id = params.get("id");
      return id && id.trim() ? id.trim() : "";
    } catch (err) {
      return "";
    }
  }

  function resolveClientId(root) {
    var preset = root.getAttribute("data-client-id");
    if (preset) {
      return preset;
    }
    var fromUrl = queryId();
    if (fromUrl) {
      return fromUrl;
    }
    if (root.hasAttribute("data-create-client")) {
      try {
        return window.localStorage.getItem(DRAFT_KEY) || "";
      } catch (err) {
        return "";
      }
    }
    return "";
  }

  function showPanel(root, step) {
    var panels = root.querySelectorAll("[data-step]");
    for (var i = 0; i < panels.length; i += 1) {
      var panel = panels[i];
      var on = panel.getAttribute("data-step") === String(step);
      panel.hidden = !on;
    }
  }

  function paintIdentity(root, record) {
    var title = root.querySelector("[data-client-title]");
    var nameEl = root.querySelector("[data-fact=name]");
    var emailEl = root.querySelector("[data-fact=email]");
    var phoneEl = root.querySelector("[data-fact=phone]");
    var shotImg = root.querySelector("[data-shot]");
    var shotEmpty = root.querySelector("[data-shot-empty]");
    if (title) {
      title.textContent = record.name.trim() || "New client";
    }
    if (nameEl) nameEl.textContent = record.name.trim() || "Waiting for the client.";
    if (emailEl) emailEl.textContent = record.email.trim() || "Waiting for the client.";
    if (phoneEl) phoneEl.textContent = record.phone.trim() || "Waiting for the client.";
    if (shotImg && shotEmpty) {
      if (hasFace(record)) {
        shotImg.src = record.headshot;
        shotImg.hidden = false;
        shotEmpty.hidden = true;
      } else {
        shotImg.removeAttribute("src");
        shotImg.hidden = true;
        shotEmpty.hidden = false;
      }
    }
    var picks = root.querySelectorAll("[data-standard-avatar]");
    for (var i = 0; i < picks.length; i += 1) {
      var pick = picks[i];
      var src = pick.getAttribute("data-standard-avatar");
      if (record.faceKind === "standard" && record.headshot === src) {
        pick.classList.add("picked");
      } else {
        pick.classList.remove("picked");
      }
    }
  }

  function paintChrome(root, record, clientId) {
    var sticky = root.querySelector("[data-account-status]");
    if (sticky) {
      sticky.textContent = statusLine(record);
    }
    var heading = root.querySelector("[data-wizard] h2");
    if (heading) {
      heading.textContent = isClient(record) ? "FIRST ACTS AS A CLIENT" : "CREATE THE CLIENT";
    }
    var next = root.querySelector("[data-financial]");
    if (next) {
      if (firstActsDone(record)) {
        next.classList.remove("locked");
      } else {
        next.classList.add("locked");
      }
    }
    var wait = root.querySelector("[data-financial-wait]");
    if (wait) {
      wait.hidden = firstActsDone(record);
    }
    var ready = root.querySelector("[data-financial-ready]");
    if (ready) {
      ready.hidden = !firstActsDone(record);
    }
    var wizard = root.querySelector("[data-wizard]");
    if (wizard) {
      wizard.hidden = firstActsDone(record);
    }
    var enter = root.querySelector("[data-enter-hub]");
    if (enter) {
      enter.hidden = !firstActsDone(record);
      if (firstActsDone(record) && clientId && enter.tagName === "A") {
        enter.setAttribute("href", "index.html");
      }
    }
    var ownPage = root.querySelector("[data-own-page]");
    if (ownPage) {
      ownPage.hidden = !firstActsDone(record) || !clientId;
      if (firstActsDone(record) && clientId && ownPage.tagName === "A") {
        ownPage.setAttribute("href", pageFor(clientId));
      }
    }
  }

  function currentStep(record) {
    if (!record.name.trim()) return 1;
    if (!record.email.trim()) return 2;
    if (!record.phone.trim()) return 3;
    if (!record.watchedWelcome) return 4;
    if (!hasFace(record)) return 5;
    return 6;
  }

  function setError(root, message) {
    var err = root.querySelector("[data-error]");
    if (err) {
      err.textContent = message || "";
    }
  }

  function bindWizard(root) {
    var creating = root.hasAttribute("data-create-client");
    var clientId = resolveClientId(root);
    var record = clientId ? load(clientId) : emptyRecord();
    if (creating && !clientId && queryId()) {
      clientId = queryId();
      record = load(clientId);
    }
    paintIdentity(root, record);
    paintChrome(root, record, clientId);
    if (firstActsDone(record)) {
      return;
    }
    showPanel(root, currentStep(record));

    var nameInput = root.querySelector("[name=client-name]");
    var emailInput = root.querySelector("[name=client-email]");
    var phoneInput = root.querySelector("[name=client-phone]");
    var fileInput = root.querySelector("[name=client-headshot]");
    if (nameInput) nameInput.value = record.name;
    if (emailInput) emailInput.value = record.email;
    if (phoneInput) phoneInput.value = record.phone;

    function persist() {
      if (!clientId) {
        return;
      }
      save(clientId, record);
      if (creating) {
        try {
          window.localStorage.setItem(DRAFT_KEY, clientId);
        } catch (err) {
          /* draft is optional */
        }
      }
    }

    root.addEventListener("submit", function (event) {
      event.preventDefault();
      var form = event.target;
      if (!(form instanceof HTMLFormElement)) {
        return;
      }
      var step = Number(form.getAttribute("data-step"));
      setError(root, "");
      if (step === 1) {
        var name = nameInput ? nameInput.value.trim() : "";
        if (!name) {
          setError(root, "The client types their own name.");
          return;
        }
        record.name = name;
        if (!clientId) {
          clientId = uniqueSlug(name);
        }
      } else if (step === 2) {
        var email = emailInput ? emailInput.value.trim() : "";
        if (!validEmail(email)) {
          setError(root, "The client types their own email.");
          return;
        }
        record.email = email;
      } else if (step === 3) {
        var phone = phoneInput ? phoneInput.value.trim() : "";
        if (!validPhone(phone)) {
          setError(root, "The client types their own phone number.");
          return;
        }
        record.phone = phone;
      } else if (step === 4) {
        if (!isClient(record)) {
          setError(root, "Name, email, and phone come first.");
          return;
        }
        record.watchedWelcome = true;
      } else {
        return;
      }
      persist();
      paintIdentity(root, record);
      paintChrome(root, record, clientId);
      if (firstActsDone(record)) {
        var financial = root.querySelector("[data-financial]");
        if (financial) {
          financial.scrollIntoView({ behavior: "smooth", block: "start" });
        }
        return;
      }
      showPanel(root, currentStep(record));
    });

    function finishFace() {
      persist();
      paintIdentity(root, record);
      paintChrome(root, record, clientId);
      var financial = root.querySelector("[data-financial]");
      if (financial) {
        financial.scrollIntoView({ behavior: "smooth", block: "start" });
      }
    }

    root.addEventListener("click", function (event) {
      var target = event.target;
      if (!(target instanceof Element)) {
        return;
      }
      var pick = target.closest("[data-standard-avatar]");
      if (!pick) {
        return;
      }
      event.preventDefault();
      setError(root, "");
      if (!isClient(record) || !record.watchedWelcome) {
        setError(root, "Watch the welcome first. Then pick a face.");
        return;
      }
      var src = pick.getAttribute("data-standard-avatar");
      if (!isStandardAvatar(src)) {
        setError(root, "That is not one of the standard avatars.");
        return;
      }
      if (!clientId) {
        clientId = uniqueSlug(record.name);
      }
      record.headshot = src;
      record.faceKind = "standard";
      finishFace();
    });

    if (fileInput) {
      fileInput.addEventListener("change", function () {
        var file = fileInput.files && fileInput.files[0];
        setError(root, "");
        if (!file) {
          return;
        }
        if (!isClient(record) || !record.watchedWelcome) {
          setError(root, "Watch the welcome first. Then upload a headshot or pick a standard avatar.");
          return;
        }
        if (!clientId) {
          clientId = uniqueSlug(record.name);
        }
        if (!file.type || file.type.indexOf("image/") !== 0) {
          setError(root, "The headshot has to be a picture the client uploads.");
          return;
        }
        var reader = new FileReader();
        reader.onload = function () {
          if (typeof reader.result !== "string") {
            setError(root, "That picture did not load. The client should try another.");
            return;
          }
          record.headshot = reader.result;
          record.faceKind = "upload";
          finishFace();
        };
        reader.readAsDataURL(file);
      });
    }
  }

  function bindDirectory() {
    var nodes = document.querySelectorAll("[data-status-for]");
    for (var i = 0; i < nodes.length; i += 1) {
      var node = nodes[i];
      var id = node.getAttribute("data-status-for");
      if (!id) {
        continue;
      }
      node.textContent = directoryLine(load(id));
    }

    var created = document.querySelector("[data-created-clients]");
    if (!created) {
      return;
    }
    while (created.firstChild) {
      created.removeChild(created.firstChild);
    }
    var ids = listClientIds();
    var extras = [];
    for (var j = 0; j < ids.length; j += 1) {
      if (!NAMED_SLOTS[ids[j]]) {
        extras.push(ids[j]);
      }
    }
    if (extras.length === 0) {
      var empty = document.createElement("p");
      empty.className = "empty";
      empty.textContent = "No clients created in this browser yet. The portal is how they come in.";
      created.appendChild(empty);
      return;
    }
    for (var k = 0; k < extras.length; k += 1) {
      var extraId = extras[k];
      var extra = load(extraId);
      var card = document.createElement("article");
      card.className = "card";
      var heading = document.createElement("h2");
      heading.textContent = extra.name.trim() || extraId;
      var para = document.createElement("p");
      para.textContent = directoryLine(extra);
      var link = document.createElement("a");
      link.className = "go";
      link.textContent = firstActsDone(extra) ? "Open their Hivemind page" : "Finish first acts";
      link.setAttribute("href", pageFor(extraId));
      card.appendChild(heading);
      card.appendChild(para);
      card.appendChild(link);
      created.appendChild(card);
    }
  }

  function boot() {
    if (document.body.hasAttribute("data-require-id")) {
      var id = queryId();
      if (!id) {
        window.location.replace("open-account.html");
        return;
      }
      var host = document.querySelector("[data-client-host]");
      if (host) {
        host.setAttribute("data-client-id", id);
        bindWizard(host);
      }
      bindDirectory();
      return;
    }
    var page = document.querySelector("[data-client-id], [data-create-client]");
    if (page) {
      bindWizard(page);
    }
    bindDirectory();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();

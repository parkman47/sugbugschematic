(function (global) {
  "use strict";

  var mounted = false;
  var runtimeEvents = [];

  function cleanUrl(value) {
    if (!value) return "";
    try {
      var url = new URL(value, document.baseURI);
      return url.origin + url.pathname;
    } catch (_) {
      return String(value || "").split("?")[0].split("#")[0];
    }
  }

  function pushEvent(kind, detail) {
    runtimeEvents.push({ at: new Date().toISOString(), kind: kind, detail: String(detail || "").slice(0, 4000) });
    if (runtimeEvents.length > 100) runtimeEvents.shift();
  }

  function installErrorCapture() {
    global.addEventListener("error", function (event) {
      pushEvent("error", (event.message || "Window error") + " @ " + cleanUrl(event.filename) + ":" + (event.lineno || 0) + ":" + (event.colno || 0));
    });
    global.addEventListener("unhandledrejection", function (event) {
      var reason = event.reason && (event.reason.stack || event.reason.message) || event.reason;
      pushEvent("unhandledrejection", reason || "Unknown rejection");
    });
  }

  function selectorFor(element) {
    if (!element || element.nodeType !== 1) return null;
    if (element.id) return "#" + CSS.escape(element.id);
    var parts = [];
    while (element && element.nodeType === 1 && parts.length < 8) {
      var part = element.localName;
      if (!part) break;
      var parent = element.parentElement;
      if (parent) {
        var siblings = Array.prototype.filter.call(parent.children, function (child) { return child.localName === element.localName; });
        if (siblings.length > 1) part += ":nth-of-type(" + (siblings.indexOf(element) + 1) + ")";
      }
      parts.unshift(part);
      element = parent;
    }
    return parts.join(" > ");
  }

  function sensitiveField(element, privateSelector) {
    if (!element || !element.matches) return false;
    if (element.closest(privateSelector)) return true;
    var joined = [element.name, element.id, element.type, element.getAttribute("autocomplete"), element.getAttribute("aria-label")].join(" ").toLowerCase().replace(/[^a-z0-9]+/g, "");
    return element.type === "password" || /(?:csrf|xsrf|nonce|session|token|authorization|cookie|password|passwd|secret|apikey|otp|onetime|payment|creditcard|cardnumber|cardholder|ccnumber|cvc|cvv)/.test(joined);
  }

  function stripExecutableContent(root) {
    Array.prototype.forEach.call(root.querySelectorAll("script, base, meta[http-equiv='refresh' i], object, embed"), function (node) { node.remove(); });
    Array.prototype.forEach.call(root.querySelectorAll("iframe"), function (node) {
      node.setAttribute("data-live-report-original-src", cleanUrl(node.getAttribute("src")));
      node.removeAttribute("src");
      node.setAttribute("srcdoc", "<p>Embedded frame omitted from inert report.</p>");
    });
    Array.prototype.forEach.call(root.querySelectorAll("*"), function (node) {
      Array.prototype.slice.call(node.attributes).forEach(function (attribute) {
        if (/^on/i.test(attribute.name)) node.removeAttribute(attribute.name);
        if ((attribute.name === "href" || attribute.name === "src" || attribute.name === "action") && /^javascript:/i.test(attribute.value.trim())) node.removeAttribute(attribute.name);
      });
    });
  }

  function serializeShadowRoot(root, privateSelector) {
    var box = document.createElement("div");
    Array.prototype.forEach.call(root.childNodes, function (node) { box.appendChild(node.cloneNode(true)); });
    Array.prototype.forEach.call(box.querySelectorAll(privateSelector), function (node) { node.textContent = "[REDACTED]"; });
    Array.prototype.forEach.call(box.querySelectorAll("input, textarea, select"), function (node) {
      if (sensitiveField(node, privateSelector)) {
        node.setAttribute("value", "[REDACTED]");
        node.textContent = "[REDACTED]";
      }
    });
    stripExecutableContent(box);
    return box.innerHTML;
  }

  function blobToDataUrl(blob) {
    return new Promise(function (resolve) {
      var reader = new FileReader();
      reader.onload = function () { resolve(reader.result); };
      reader.onerror = function () { resolve(null); };
      reader.readAsDataURL(blob);
    });
  }

  async function inlineImagePairs(pairs) {
    await Promise.all(pairs.map(async function (pair) {
      try {
        var source = pair.live.currentSrc || pair.live.src;
        if (!source || source.indexOf("data:") === 0) return;
        var response = await fetch(source, { credentials: "same-origin", cache: "no-store" });
        if (!response.ok) return;
        var dataUrl = await blobToDataUrl(await response.blob());
        if (dataUrl) pair.clone.setAttribute("src", dataUrl);
      } catch (_) { /* Cross-origin or unavailable assets remain referenced by sanitized URL. */ }
    }));
  }

  function captureStyles() {
    var rules = [];
    Array.prototype.forEach.call(document.styleSheets, function (sheet) {
      try {
        Array.prototype.forEach.call(sheet.cssRules || [], function (rule) { rules.push(rule.cssText); });
      } catch (_) { /* Cross-origin stylesheet rules cannot be read. */ }
    });
    return rules.join("\n");
  }

  function performanceContext() {
    if (!global.performance || !performance.getEntriesByType) return [];
    return performance.getEntriesByType("resource").slice(-100).map(function (entry) {
      return {
        name: cleanUrl(entry.name),
        initiatorType: entry.initiatorType,
        durationMs: Math.round(entry.duration),
        transferSize: entry.transferSize || 0,
      };
    });
  }

  async function capturePage(config) {
    var privateSelector = config.privateSelector || "[data-report-private], [data-live-report-private], input[type='password']";
    var liveRoot = document.documentElement;
    var cloneRoot = liveRoot.cloneNode(true);
    var liveNodes = [liveRoot].concat(Array.prototype.slice.call(liveRoot.querySelectorAll("*")));
    var cloneNodes = [cloneRoot].concat(Array.prototype.slice.call(cloneRoot.querySelectorAll("*")));
    var formState = [];
    var imagePairs = [];
    var shadowRoots = [];

    for (var index = 0; index < liveNodes.length; index += 1) {
      var live = liveNodes[index];
      var clone = cloneNodes[index];
      if (!clone) continue;
      var isSensitive = sensitiveField(live, privateSelector);
      if (live.matches && live.matches(privateSelector)) {
        clone.textContent = "[REDACTED]";
        Array.prototype.slice.call(clone.attributes).forEach(function (attribute) {
          if (attribute.name !== "class" && attribute.name !== "id" && !attribute.name.startsWith("data-")) clone.removeAttribute(attribute.name);
        });
        clone.setAttribute("data-live-report-redacted", "true");
      }
      if (live instanceof HTMLInputElement) {
        var inputValue = isSensitive ? "[REDACTED]" : (live.type === "file" ? "[FILE OMITTED]" : live.value);
        clone.setAttribute("value", inputValue);
        if (live.checked) clone.setAttribute("checked", ""); else clone.removeAttribute("checked");
        if (live.indeterminate) clone.setAttribute("data-live-report-indeterminate", "true");
        formState.push({ selector: selectorFor(live), tag: "input", type: live.type, name: live.name || "", value: inputValue, checked: live.checked });
      } else if (live instanceof HTMLTextAreaElement) {
        var textValue = isSensitive ? "[REDACTED]" : live.value;
        clone.textContent = textValue;
        formState.push({ selector: selectorFor(live), tag: "textarea", name: live.name || "", value: textValue });
      } else if (live instanceof HTMLSelectElement) {
        Array.prototype.forEach.call(clone.options, function (option, optionIndex) {
          if (live.options[optionIndex] && live.options[optionIndex].selected) option.setAttribute("selected", "");
          else option.removeAttribute("selected");
        });
        formState.push({ selector: selectorFor(live), tag: "select", name: live.name || "", value: isSensitive ? "[REDACTED]" : live.value, selectedIndex: live.selectedIndex });
      } else if (live instanceof HTMLDetailsElement) {
        if (live.open) clone.setAttribute("open", ""); else clone.removeAttribute("open");
      }
      if (live.scrollTop) clone.setAttribute("data-live-report-scroll-top", String(live.scrollTop));
      if (live.scrollLeft) clone.setAttribute("data-live-report-scroll-left", String(live.scrollLeft));
      if (live instanceof HTMLCanvasElement) {
        try {
          var canvasImage = document.createElement("img");
          canvasImage.src = live.toDataURL("image/png");
          canvasImage.alt = "Captured canvas";
          clone.replaceWith(canvasImage);
        } catch (_) { clone.setAttribute("data-live-report-canvas", "unavailable"); }
      } else if (live instanceof HTMLImageElement) {
        clone.setAttribute("data-live-report-original-src", cleanUrl(live.currentSrc || live.src));
        clone.removeAttribute("src");
        clone.removeAttribute("srcset");
        imagePairs.push({ live: live, clone: clone });
      }
      if (config.captureShadowDom !== false && live.shadowRoot && live.id !== "live-report-relay-host") {
        shadowRoots.push({ host: selectorFor(live), mode: live.shadowRoot.mode, html: serializeShadowRoot(live.shadowRoot, privateSelector) });
      }
    }

    var reporterHost = cloneRoot.querySelector("#live-report-relay-host");
    if (reporterHost) reporterHost.remove();
    stripExecutableContent(cloneRoot);
    if (config.inlineImages !== false) await inlineImagePairs(imagePairs);
    var capturedCss = captureStyles();
    if (capturedCss) {
      var style = document.createElement("style");
      style.setAttribute("data-live-report-captured-css", "true");
      style.textContent = capturedCss;
      var head = cloneRoot.querySelector("head");
      if (head) head.appendChild(style);
    }
    var capturedHead = cloneRoot.querySelector("head");
    if (capturedHead) {
      var csp = document.createElement("meta");
      csp.setAttribute("http-equiv", "Content-Security-Policy");
      csp.setAttribute("content", "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; form-action 'none'; base-uri 'none'; frame-src 'none'");
      capturedHead.prepend(csp);
    }
    var domHtml = "<!doctype html>\n" + cloneRoot.outerHTML;
    var maxDomChars = Number(config.maxDomChars || 8_000_000);
    if (domHtml.length > maxDomChars) {
      domHtml = domHtml.slice(0, maxDomChars) + "\n<!-- TRUNCATED BY LIVE REPORT RELAY -->";
    }
    var customContext = {};
    if (typeof config.context === "function") {
      try { customContext = await config.context() || {}; } catch (error) { customContext = { contextError: String(error) }; }
    } else if (config.context && typeof config.context === "object") customContext = config.context;
    return {
      domHtml: domHtml,
      capture: {
        capturedAt: new Date().toISOString(),
        url: config.captureUrlQuery === true ? location.href : cleanUrl(location.href),
        urlQueryKeys: Array.from(new URL(location.href).searchParams.keys()),
        title: document.title,
        referrer: config.captureUrlQuery === true ? document.referrer : cleanUrl(document.referrer),
        viewport: { width: global.innerWidth, height: global.innerHeight, devicePixelRatio: global.devicePixelRatio },
        documentSize: { width: document.documentElement.scrollWidth, height: document.documentElement.scrollHeight },
        scroll: { x: global.scrollX, y: global.scrollY },
        activeElement: selectorFor(document.activeElement),
        userAgent: navigator.userAgent,
        language: navigator.language,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
        online: navigator.onLine,
        formState: formState,
        shadowRoots: shadowRoots,
        runtimeEvents: runtimeEvents.slice(),
        resources: performanceContext(),
        custom: customContext,
      },
    };
  }

  function fileToDataUrl(file) {
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onload = function () { resolve(reader.result); };
      reader.onerror = function () { reject(reader.error); };
      reader.readAsDataURL(file);
    });
  }

  function mount(config) {
    if (mounted) return;
    if (!config || !config.endpoint || !config.project || !config.submitKey) throw new Error("LiveReportRelay requires endpoint, project, and submitKey");
    mounted = true;
    installErrorCapture();

    var host = document.createElement("div");
    host.id = "live-report-relay-host";
    host.setAttribute("data-report-private", "true");
    document.body.appendChild(host);
    var shadow = host.attachShadow({ mode: "open" });
    shadow.innerHTML = "<style>" +
      ":host{all:initial;position:fixed;right:16px;bottom:16px;z-index:2147483647;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;color:#eee}" +
      "button,textarea,input{font:inherit}.open{background:#241018;color:#fff;border:1px solid #ff6d94;border-radius:999px;padding:9px 14px;cursor:pointer;box-shadow:0 4px 18px #0008}" +
      ".panel{display:none;position:absolute;right:0;bottom:48px;width:min(360px,calc(100vw - 32px));box-sizing:border-box;background:#111;color:#eee;border:2px solid #ff6d94;border-radius:12px;padding:14px;box-shadow:0 12px 40px #000b}" +
      ".panel.visible{display:block}.title{font-weight:700;margin-bottom:10px}.types{display:flex;gap:14px;margin-bottom:10px}.types label{display:flex;gap:6px;align-items:center}" +
      "textarea{width:100%;min-height:100px;box-sizing:border-box;background:#080808;color:#fff;border:1px solid #666;border-radius:6px;padding:8px;resize:vertical}" +
      ".attach{display:block;margin:10px 0;font-size:12px;color:#bbb}.actions{display:flex;gap:8px}.actions button{flex:1;padding:8px;border-radius:6px;border:1px solid #777;cursor:pointer}.submit{background:#ff6d94;color:#16030a;border-color:#ff6d94!important;font-weight:700}.status{min-height:18px;margin-top:8px;font-size:12px;color:#bbb}" +
      "</style><button class='open' type='button'>Report</button><section class='panel' role='dialog' aria-label='Report a bug or suggestion'>" +
      "<div class='title'>Report this page</div><div class='types'><label><input class='bug' type='checkbox' checked> Bug</label><label><input class='suggestion' type='checkbox'> Suggestion</label></div>" +
      "<textarea class='note' placeholder='What happened, and what did you expect?'></textarea><label class='attach'>Optional screenshot <input class='screenshot' type='file' accept='image/png,image/jpeg,image/webp'></label>" +
      "<div class='actions'><button class='cancel' type='button'>Cancel</button><button class='submit' type='button'>Submit</button></div><div class='status' aria-live='polite'></div></section>";

    var open = shadow.querySelector(".open");
    var panel = shadow.querySelector(".panel");
    var note = shadow.querySelector(".note");
    var status = shadow.querySelector(".status");
    var submit = shadow.querySelector(".submit");
    var screenshot = shadow.querySelector(".screenshot");
    var capturePromise = null;

    function close() {
      panel.classList.remove("visible");
      note.value = "";
      screenshot.value = "";
      status.textContent = "";
      capturePromise = null;
    }
    function show() {
      panel.classList.add("visible");
      status.textContent = "Capturing page state…";
      capturePromise = capturePage(config).then(function (result) { status.textContent = "Page state captured."; return result; });
      note.focus();
    }
    open.addEventListener("click", function () { panel.classList.contains("visible") ? close() : show(); });
    shadow.querySelector(".cancel").addEventListener("click", close);
    shadow.addEventListener("paste", async function (event) {
      var items = Array.prototype.slice.call((event.clipboardData && event.clipboardData.items) || []);
      var image = items.find(function (item) { return item.type.indexOf("image/") === 0; });
      if (!image) return;
      var file = image.getAsFile();
      if (!file) return;
      var transfer = new DataTransfer();
      transfer.items.add(file);
      screenshot.files = transfer.files;
      status.textContent = "Screenshot attached.";
      event.preventDefault();
    });
    submit.addEventListener("click", async function () {
      var categories = [];
      if (shadow.querySelector(".bug").checked) categories.push("bug");
      if (shadow.querySelector(".suggestion").checked) categories.push("suggestion");
      if (!categories.length) { status.textContent = "Choose Bug, Suggestion, or both."; return; }
      if (!note.value.trim()) { status.textContent = "Please add a short description."; return; }
      submit.disabled = true;
      status.textContent = "Submitting…";
      try {
        var snapshot = await (capturePromise || capturePage(config));
        var screenshotDataUrl = screenshot.files[0] ? await fileToDataUrl(screenshot.files[0]) : null;
        var base = String(config.endpoint).replace(/\/$/, "");
        var submitUrl = config.submitUrl || (base + "/submit.php?project=" + encodeURIComponent(config.project));
        var response = await fetch(submitUrl, {
          method: "POST",
          headers: { "content-type": "application/json", "x-report-key": config.submitKey },
          body: JSON.stringify({ categories: categories, note: note.value, domHtml: snapshot.domHtml, capture: snapshot.capture, screenshotDataUrl: screenshotDataUrl }),
        });
        var result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || ("HTTP " + response.status));
        status.textContent = "Saved as " + result.id;
        setTimeout(close, 1600);
      } catch (error) {
        status.textContent = "Could not submit: " + (error.message || error);
      } finally {
        submit.disabled = false;
      }
    });
  }

  global.LiveReportRelay = { mount: mount, capturePage: capturePage };
})(window);

(function () {
  "use strict";

  var config = window.pushwiMetabox;
  var box = document.querySelector(".pushwi-box");

  if (!config || !box) {
    return;
  }

  var postId = box.getAttribute("data-post-id");
  var statusEl = box.querySelector(".pushwi-status");
  var spinner = box.querySelector(".spinner");
  var baseline = null;

  function field(name) {
    var el = box.querySelector('[name="' + name + '"]');
    return el ? el.value : "";
  }

  function hasCampaign() {
    return !!(statusEl && statusEl.querySelector(".pushwi-status-ok"));
  }

  function notify(type, message) {
    var wp = window.wp;

    if (wp && wp.data && wp.data.dispatch && wp.data.select("core/notices")) {
      wp.data.dispatch("core/notices").createNotice(type, message, {
        id: "pushwi-notice",
        isDismissible: true
      });
      return;
    }

    var old = box.querySelector(".pushwi-inline-notice");
    if (old) {
      old.parentNode.removeChild(old);
    }

    var p = document.createElement("p");
    p.className = "pushwi-inline-notice pushwi-inline-notice-" + type;
    p.textContent = message;
    box.insertBefore(p, box.firstChild);
  }

  function request(method, params) {
    var body = new window.FormData();
    var url = config.ajaxUrl;

    params.nonce = config.nonce;
    params.post_id = postId;

    if (method === "GET") {
      url += "?" + Object.keys(params).map(function (key) {
        return encodeURIComponent(key) + "=" + encodeURIComponent(params[key]);
      }).join("&");
    } else {
      Object.keys(params).forEach(function (key) {
        body.append(key, params[key]);
      });
    }

    return window.fetch(url, {
      method: method,
      credentials: "same-origin",
      body: method === "GET" ? undefined : body
    }).then(function (res) {
      return res.json();
    });
  }

  function setBusy(busy) {
    Array.prototype.forEach.call(box.querySelectorAll("[data-pushwi-dispatch]"), function (button) {
      button.disabled = busy;
    });
    if (spinner) {
      spinner.classList.toggle("is-active", busy);
    }
  }

  function dispatch(kind) {
    var question = hasCampaign() ? config.i18n.confirmResend : kind === "send" ? config.i18n.confirmSend : null;

    if (question && !window.confirm(question)) {
      return;
    }

    setBusy(true);

    request("POST", {
      action: "pushwi_dispatch",
      dispatch: kind,
      pushwi_title: field("pushwi_title"),
      pushwi_body: field("pushwi_body"),
      pushwi_audience: field("pushwi_audience")
    })
      .then(function (json) {
        var data = (json && json.data) || {};

        if (statusEl && typeof data.html === "string") {
          statusEl.innerHTML = data.html;
        }

        if (json && json.success) {
          notify("success", data.message || (kind === "draft" ? config.i18n.draft : config.i18n.sent));
        } else {
          notify("error", config.i18n.failed + " " + (data.message || config.i18n.requestFailed));
        }
      })
      .catch(function () {
        notify("error", config.i18n.requestFailed);
      })
      .then(function () {
        setBusy(false);
        refreshStatus(true);
      });
  }

  function refreshStatus(silent) {
    return request("GET", { action: "pushwi_status" })
      .then(function (json) {
        if (!json || !json.success) {
          return;
        }

        var data = json.data;

        if (statusEl) {
          statusEl.innerHTML = data.html;
        }

        if (!silent && baseline) {
          if (data.sent_at && data.sent_at !== baseline.sent_at) {
            notify("success", data.status === "draft" ? config.i18n.draft : config.i18n.sent);
          } else if (data.error && data.error !== baseline.error) {
            notify("error", config.i18n.failed + " " + data.error);
          }
        }

        baseline = data;
      })
      .catch(function () {});
  }

  box.addEventListener("click", function (event) {
    var button = event.target.closest("[data-pushwi-dispatch]");

    if (button) {
      event.preventDefault();
      dispatch(button.getAttribute("data-pushwi-dispatch"));
    }
  });

  refreshStatus(true);

  var wp = window.wp;

  if (!wp || !wp.data || !wp.data.select("core/editor")) {
    return;
  }

  var saving = false;
  var timer = null;

  wp.data.subscribe(function () {
    var editor = wp.data.select("core/editor");
    var editPost = wp.data.select("core/edit-post");
    var busy =
      (editor.isSavingPost() && !editor.isAutosavingPost()) ||
      !!(editPost && editPost.isSavingMetaBoxes && editPost.isSavingMetaBoxes());

    if (busy) {
      saving = true;
      window.clearTimeout(timer);
      return;
    }

    if (saving) {
      saving = false;
      window.clearTimeout(timer);
      timer = window.setTimeout(function () {
        refreshStatus(false);
      }, 1000);
    }
  });
})();

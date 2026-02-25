(function () {
  function ajaxCfg() {
    return {
      url: (window.BDSShortcodes && BDSShortcodes.ajaxUrl) ? BDSShortcodes.ajaxUrl : (window.ajaxurl || "/wp-admin/admin-ajax.php"),
      nonce: (window.BDSShortcodes && BDSShortcodes.nonce) ? BDSShortcodes.nonce : ""
    };
  }

  function fetchShortcodes() {
    const a = ajaxCfg();
    return window.jQuery.post(a.url, { action: "bds_shortcodes_list", nonce: a.nonce })
      .then(res => (res && res.success && res.data && Array.isArray(res.data.shortcodes)) ? res.data.shortcodes : []);
  }

  // --- NEU: state für Suche (stabiler reopen) ---
  let _bdsSearchTimer = null;
  let _bdsLastQuery = "";
  let _bdsLastSelected = "";

  function openPicker(editor) {
    fetchShortcodes().then(list => {
      if (!list.length) {
        editor.windowManager && editor.windowManager.alert
          ? editor.windowManager.alert("Keine Shortcodes gefunden.")
          : alert("Keine Shortcodes gefunden.");
        return;
      }

      // Filter anwenden
      const q = (_bdsLastQuery || "").toLowerCase().trim();
      const filtered = !q ? list : list.filter(sc => (sc || "").toLowerCase().includes(q));

      const items = (filtered.length ? filtered : ["— keine Treffer —"]).map(sc => {
        const empty = sc === "— keine Treffer —";
        return { text: empty ? sc : "[" + sc + "]", value: empty ? "" : sc };
      });

      // selected wie vorher, aber Auswahl merken / wiederherstellen
      let selected =
        (_bdsLastSelected && filtered.includes(_bdsLastSelected))
          ? _bdsLastSelected
          : (items[0] ? items[0].value : "");

      // ✅ WICHTIG: var win, damit Callbacks nicht kaputt gehen
      var win = editor.windowManager.open({
        title: "Shortcode einfügen",
        body: [
          // ✅ Search-Input als TinyMCE textbox (wird bei dir gerendert)
          {
            type: "textbox",
            name: "bds_sc_search",
            label: "Suchen",
            value: _bdsLastQuery || ""
          },
          // ✅ dein funktionierendes listbox bleibt
          {
            type: "listbox",
            name: "shortcode",
            label: "Verfügbar",
            values: items,
            value: selected,
            onselect: function () {
              selected = this.value();
              _bdsLastSelected = selected; // merken
            }
          }
        ],
        buttons: [
          {
            text: "Einfügen",
            subtype: "primary",
            onclick: function () {
              if (!selected) return;
              editor.insertContent("[" + selected + "]");
              win.close();
            }
          },
          { text: "Abbrechen", onclick: "close" }
        ],
        onPostRender: function () {
          // Search input finden und reopen “sauber” triggern
          const $ = window.jQuery;
          const root = win.getEl ? win.getEl() : null;
          if (!root) return;

          const $root = $(root);
          const $search = $root.find("input.mce-textbox").first();
          if (!$search.length) return;

          // Fokus + Cursor ans Ende
          setTimeout(function () {
            try {
              const el = $search.get(0);
              el.focus();
              const v = el.value || "";
              el.setSelectionRange(v.length, v.length);
            } catch (e) {}
          }, 0);

          // Live tippen -> debounce -> sauber schließen -> neu öffnen
          $search.off(".bds").on("input.bds", function () {
            clearTimeout(_bdsSearchTimer);
            const nextQ = $search.val();
            _bdsLastQuery = nextQ;

            _bdsSearchTimer = setTimeout(function () {
              // Dialog schließen und erst dann neu öffnen
              try { win.close(); } catch (e) {}

              setTimeout(function () {
                openPicker(editor);
              }, 120);
            }, 160);
          });

          // Enter im Suchfeld = einfügen
          $search.off("keydown.bds").on("keydown.bds", function (ev) {
            if (ev.key === "Enter") {
              ev.preventDefault();
              if (!selected) return;
              editor.insertContent("[" + selected + "]");
              win.close();
            }
          });
        }
      });
    });
  }

  // 1) TinyMCE Button (falls erlaubt)
  function tryAddTinyMCEButton(editor) {
    try {
      if (editor.settings && editor.settings._bdsAdded) return;
      editor.settings._bdsAdded = true;

      editor.addButton("bds_shortcode_picker", {
        text: "BDS-Shortcodes",
        icon: false,
        tooltip: "BDS-Shortcodes",
        onclick: function () { openPicker(editor); }
      });
    } catch (e) {}
  }

  // 2) Toolbar DOM-Injection (YOOtheme-sicher)
  function injectDomButton(editor) {
    const $ = window.jQuery;

    const iframeId = editor.iframeElement ? editor.iframeElement.id : null;
    if (!iframeId) return;

    const $iframe = $("#" + iframeId);
    if (!$iframe.length) return;

    const $tinymceWrap = $iframe.closest(".mce-tinymce");
    if (!$tinymceWrap.length) return;

    if ($tinymceWrap.data("bdsDomButton")) return;
    $tinymceWrap.data("bdsDomButton", true);

    const $toolbar = $tinymceWrap.find(".mce-toolbar-grp .mce-flow-layout").first();
    if (!$toolbar.length) return;

    const $btn = $(
      '<div class="mce-container mce-flow-layout-item mce-btn-group" role="group">' +
        '<div class="mce-container-body">' +
          '<div class="mce-widget mce-btn" role="button" tabindex="-1" aria-label="BDS Shortcodes">' +
            '<button type="button" tabindex="-1" class="bds-mce-btn">' +
              '<span class="mce-txt">BDS-Shortcodes</span>' +
            '</button>' +
          '</div>' +
        '</div>' +
      '</div>'
    );

    $btn.on("click", "button.bds-mce-btn", function (ev) {
      ev.preventDefault();
      openPicker(editor);
    });

    $toolbar.append($btn);
  }

  function onEditorReady(editor) {
    tryAddTinyMCEButton(editor);
    setTimeout(function () { injectDomButton(editor); }, 300);
    setTimeout(function () { injectDomButton(editor); }, 1200);
  }

  function hookEditors() {
    if (typeof tinymce === "undefined") return;

    (tinymce.editors || []).forEach(function (ed) {
      if (!ed) return;
      ed.on("init", function () { onEditorReady(ed); });
      if (ed.initialized) onEditorReady(ed);
    });

    tinymce.on("AddEditor", function (e) {
      if (e && e.editor) {
        e.editor.on("init", function () { onEditorReady(e.editor); });
      }
    });
  }

  function boot() {
    if (!window.jQuery || typeof tinymce === "undefined") {
      setTimeout(boot, 250);
      return;
    }
    hookEditors();
  }

  boot();
})();

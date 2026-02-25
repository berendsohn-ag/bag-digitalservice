(function () {
  function ajaxCfg() {
    return {
      url: (window.BDSShortcodes && BDSShortcodes.ajaxUrl)
        ? BDSShortcodes.ajaxUrl
        : (window.ajaxurl || "/wp-admin/admin-ajax.php"),
      nonce: (window.BDSShortcodes && BDSShortcodes.nonce) ? BDSShortcodes.nonce : ""
    };
  }

  function fetchShortcodes() {
    const a = ajaxCfg();
    return window.jQuery
      .post(a.url, { action: "bds_shortcodes_list", nonce: a.nonce })
      .then(res =>
        (res && res.success && res.data && Array.isArray(res.data.shortcodes))
          ? res.data.shortcodes
          : []
      );
  }

  // ---------- Picker (textbox + datalist) ----------
  function openPicker(editor) {
    fetchShortcodes().then(list => {
      if (!list.length) {
        editor.windowManager && editor.windowManager.alert
          ? editor.windowManager.alert("Keine Shortcodes gefunden.")
          : alert("Keine Shortcodes gefunden.");
        return;
      }

      const dlId = "bds_sc_dl_" + Math.floor(Math.random() * 1e9);

      editor.windowManager.open({
        title: "Shortcode einfügen",
        body: [
          {
            type: "textbox",
            name: "bds_sc",
            label: "Suchen",
            value: ""
          }
        ],
        buttons: [
          {
            text: "Einfügen",
            subtype: "primary",
            onclick: function () {
              const v = readFromActiveDialog();
              if (!v) return;

              editor.insertContent("[" + v + "]");
              editor.windowManager.close();
            }
          },
          { text: "Abbrechen", onclick: "close" }
        ]
      });

      // datalist ans sichtbare Modal hängen (Customizer-sicher)
      setTimeout(function () {
        const $ = window.jQuery;

        const $win = $(".mce-window:visible").last();
        const $input = $win.find("input.mce-textbox").first();
        if (!$input.length) return;

        // datalist einmalig hinzufügen
        if ($win.find("datalist#" + dlId).length === 0) {
          const $dl = $('<datalist id="' + dlId + '"></datalist>');
          list.forEach(sc => $dl.append($("<option/>").attr("value", sc)));
          $win.append($dl);
        }

        $input.attr("list", dlId);

        // Enter = Einfügen
        $input.off("keydown.bds").on("keydown.bds", function (ev) {
          if (ev.key === "Enter") {
            ev.preventDefault();
            const v = ($input.val() || "").trim();
            if (!v) return;

            editor.insertContent("[" + v + "]");
            editor.windowManager.close();
          }
        });

        try { $input.get(0).focus(); } catch (e) {}
      }, 80);

      function readFromActiveDialog() {
        const $ = window.jQuery;
        const $win = $(".mce-window:visible").last();
        const $input = $win.find("input.mce-textbox").first();
        return $input.length ? ($input.val() || "").trim() : "";
      }
    });
  }

  // ---------- Button: TinyMCE API ----------
  function tryAddTinyMCEButton(editor) {
    try {
      if (!editor || !editor.addButton) return;
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

  // ---------- Button: DOM Injection (YOOtheme-sicher) ----------
  function injectDomButton(editor) {
    const $ = window.jQuery;
    if (!$) return;

    const iframeId = editor && editor.iframeElement ? editor.iframeElement.id : null;
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

    // YOOtheme rendert Toolbars gern später -> mehrfach versuchen
    setTimeout(function () { injectDomButton(editor); }, 300);
    setTimeout(function () { injectDomButton(editor); }, 1200);
    setTimeout(function () { injectDomButton(editor); }, 2500);
  }

  // ---------- Robust Hooking: Polling + AddEditor ----------
  function hookEditorsOnce() {
    if (typeof tinymce === "undefined" || !tinymce) return false;

    let found = false;

    (tinymce.editors || []).forEach(function (ed) {
      if (!ed) return;
      found = true;

      // init hook
      if (!ed._bdsInitHooked) {
        ed._bdsInitHooked = true;
        ed.on("init", function () { onEditorReady(ed); });
      }

      // falls schon init
      if (ed.initialized) onEditorReady(ed);
    });

    // AddEditor kann im Customizer auch mal nicht feuern, aber wenn doch: nutzen
    if (!tinymce._bdsAddEditorHooked && tinymce.on) {
      tinymce._bdsAddEditorHooked = true;
      tinymce.on("AddEditor", function (e) {
        if (e && e.editor) {
          e.editor.on("init", function () { onEditorReady(e.editor); });
          // falls schon init
          if (e.editor.initialized) onEditorReady(e.editor);
        }
      });
    }

    return found;
  }

  function boot() {
    if (!window.jQuery || typeof tinymce === "undefined") {
      setTimeout(boot, 300);
      return;
    }

    // Polling: im Customizer kommen Editor/Toolbar oft verzögert
    let tries = 0;
    (function poll() {
      tries++;
      const found = hookEditorsOnce();

      // solange versuchen, bis mind. 1 Editor gefunden wurde, dann noch ein paar Runden
      if (!found && tries < 40) {
        setTimeout(poll, 300);
        return;
      }
      if (tries < 60) {
        setTimeout(poll, 600);
      }
    })();
  }

  boot();
})();

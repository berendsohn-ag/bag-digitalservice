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
    return window.jQuery.post(a.url, { action: "bds_shortcodes_list", nonce: a.nonce })
      .then(res => (res && res.success && res.data && Array.isArray(res.data.shortcodes)) ? res.data.shortcodes : []);
  }

  function openPicker(editor) {
    fetchShortcodes().then(list => {
      if (!list.length) {
        editor.windowManager && editor.windowManager.alert
          ? editor.windowManager.alert("Keine Shortcodes gefunden.")
          : alert("Keine Shortcodes gefunden.");
        return;
      }

      const $ = window.jQuery;

      const items = list.map(sc => ({ text: "[" + sc + "]", value: sc }));
      let selected = items[0].value;

      // datalist id
      const dlId = "bds_sc_dl_" + Math.floor(Math.random() * 1e9);

      // Dialog öffnen: textbox + listbox
      editor.windowManager.open({
        title: "Shortcode einfügen",
        body: [
          {
            type: "textbox",
            name: "bds_sc_search",
            label: "Suchen",
            value: ""
          },
          {
            type: "listbox",
            name: "shortcode",
            label: "Verfügbar",
            values: items,
            onselect: function () { selected = this.value(); }
          }
        ],
        buttons: [
          {
            text: "Einfügen",
            subtype: "primary",
            onclick: function () {
              // 1) wenn Suchfeld befüllt: das nehmen, sonst listbox-selected
              const v = readSearchValue() || selected;
              if (!v) return;

              editor.insertContent("[" + v + "]");
              editor.windowManager.close();
            }
          },
          { text: "Abbrechen", onclick: "close" }
        ]
      });

      // Nach Render: datalist an textbox hängen (ohne win/getEl)
      setTimeout(function () {
        const $win = $(".mce-window:visible").last();
        const $input = $win.find("input.mce-textbox").first();
        if (!$input.length) return;

        // datalist einfügen (einmalig)
        if ($win.find("datalist#" + dlId).length === 0) {
          const $dl = $('<datalist id="' + dlId + '"></datalist>');
          list.forEach(sc => $dl.append($("<option/>").attr("value", sc)));
          $win.append($dl);
        }

        $input.attr("list", dlId);

        // live: wenn exakte Übereinstimmung, selected synchronisieren
        $input.off("input.bds").on("input.bds", function () {
          const v = ($input.val() || "").trim();
          if (!v) return;

          // wenn v exakt in list ist -> selected setzen
          if (list.indexOf(v) !== -1) {
            selected = v;

            // versuchen, listbox optisch mitzuziehen (optional)
            // manchmal ist es kein echtes <select>, daher try/catch
            try {
              const $sel = $win.find("select").last();
              if ($sel.length) $sel.val(v).trigger("change");
            } catch (e) {}
          }
        });

        // Enter = einfügen
        $input.off("keydown.bds").on("keydown.bds", function (ev) {
          if (ev.key === "Enter") {
            ev.preventDefault();
            const v = readSearchValue() || selected;
            if (!v) return;

            editor.insertContent("[" + v + "]");
            editor.windowManager.close();
          }
        });

        // Fokus
        try { $input.get(0).focus(); } catch (e) {}
      }, 80);

      function readSearchValue() {
        const $win = $(".mce-window:visible").last();
        const $input = $win.find("input.mce-textbox").first();
        const v = $input.length ? ($input.val() || "").trim() : "";
        // Wenn du NUR erlaubte Shortcodes zulassen willst:
        // if (v && list.indexOf(v) === -1) return "";
        return v;
      }
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

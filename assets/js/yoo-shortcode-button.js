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

  function openPicker(editor) {
    fetchShortcodes().then(list => {
      if (!list.length) {
        editor.windowManager && editor.windowManager.alert
          ? editor.windowManager.alert("Keine Shortcodes gefunden.")
          : alert("Keine Shortcodes gefunden.");
        return;
      }

      const items = list.map(sc => ({ text: "[" + sc + "]", value: sc }));
      let selected = items[0].value;

      editor.windowManager.open({
        title: "Shortcode einfügen",
        body: [{
          type: "listbox",
          name: "shortcode",
          label: "Verfügbar",
          values: items,
          onselect: function () { selected = this.value(); }
        }],
        buttons: [
          {
            text: "Einfügen",
            subtype: "primary",
            onclick: function () {
              editor.insertContent("[" + selected + "]");
              editor.windowManager.close();
            }
          },
          { text: "Abbrechen", onclick: "close" }
        ]
      });
    });
  }

  // 1) TinyMCE Button (falls erlaubt)
  function tryAddTinyMCEButton(editor) {
    try {
      if (editor.settings && editor.settings._bdsAdded) return;
      editor.settings._bdsAdded = true;

      // Manche Setups erlauben addButton nicht mehr -> try/catch
      editor.addButton("bds_shortcode_picker", {
        text: "BDS-Shortcodes",
        icon: false,
        tooltip: "BDS-Shortcodes",
        onclick: function () { openPicker(editor); }
      });

      // Wenn Toolbar vom Theme fix ist, erscheint der Button evtl. trotzdem nicht
      // Deshalb zusätzlich DOM-Injection (siehe unten)
    } catch (e) {}
  }

  // 2) Toolbar DOM-Injection (YOOtheme-sicher)
  function injectDomButton(editor) {
    const $ = window.jQuery;

    // TinyMCE Container hat id wie mceu_21, Toolbar-Gruppe wie .mce-toolbar-grp
    // Wir suchen über den iframe id -> parent -> nächster .mce-tinymce container
    const iframeId = editor.iframeElement ? editor.iframeElement.id : null;
    if (!iframeId) return;

    const $iframe = $("#" + iframeId);
    if (!$iframe.length) return;

    const $tinymceWrap = $iframe.closest(".mce-tinymce");
    if (!$tinymceWrap.length) return;

    // Nur 1x
    if ($tinymceWrap.data("bdsDomButton")) return;
    $tinymceWrap.data("bdsDomButton", true);

    const $toolbar = $tinymceWrap.find(".mce-toolbar-grp .mce-flow-layout").first();
    if (!$toolbar.length) return;

    // Button HTML im TinyMCE-Look
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

    // DOM-Injection etwas verzögert, weil YOOtheme Toolbar manchmal nachträglich rendert
    setTimeout(function () { injectDomButton(editor); }, 300);
    setTimeout(function () { injectDomButton(editor); }, 1200);
  }

  function hookEditors() {
    if (typeof tinymce === "undefined") return;

    // bestehende
    (tinymce.editors || []).forEach(function (ed) {
      if (!ed) return;
      ed.on("init", function () { onEditorReady(ed); });
      if (ed.initialized) onEditorReady(ed);
    });

    // neue (Customizer/Builder)
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

(function () {
  function getAjax() {
    return {
      url: (window.BDSShortcodes && BDSShortcodes.ajaxUrl) ? BDSShortcodes.ajaxUrl : (window.ajaxurl || "/wp-admin/admin-ajax.php"),
      nonce: (window.BDSShortcodes && BDSShortcodes.nonce) ? BDSShortcodes.nonce : ""
    };
  }

  function fetchShortcodes() {
    const a = getAjax();
    return window.jQuery.post(a.url, { action: "bds_shortcodes_list", nonce: a.nonce })
      .then(res => (res && res.success && res.data && Array.isArray(res.data.shortcodes)) ? res.data.shortcodes : []);
  }

  function openPicker(editor) {
    fetchShortcodes().then(list => {
      if (!list.length) {
        editor.windowManager.alert("Keine Shortcodes gefunden.");
        return;
      }

      const items = list.map(sc => ({ text: "[" + sc + "]", value: sc }));
      let selected = items[0].value;

      editor.windowManager.open({
        title: "Shortcode einfügen",
        body: [
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
              editor.insertContent("[" + selected + "]");
              editor.windowManager.close();
            }
          },
          { text: "Abbrechen", onclick: "close" }
        ]
      });
    });
  }

  function addButtonToEditor(editor) {
    if (editor.settings && editor.settings.bdsShortcodeButtonAdded) return;
    editor.settings.bdsShortcodeButtonAdded = true;

    editor.addButton("bds_shortcode_picker", {
      text: "BDS",
      icon: false,
      tooltip: "BDS Shortcodes",
      onclick: function () { openPicker(editor); }
    });
  }

  function hookAllEditors() {
    if (typeof tinymce === "undefined") return;

    // vorhandene Editoren
    (tinymce.editors || []).forEach(ed => {
      if (ed && ed.initialized) addButtonToEditor(ed);
      else if (ed) ed.on("init", function () { addButtonToEditor(ed); });
    });

    // neue Editoren (YOOtheme Builder erstellt oft später)
    tinymce.on("AddEditor", function (e) {
      if (e && e.editor) {
        e.editor.on("init", function () {
          addButtonToEditor(e.editor);
        });
      }
    });
  }

  function boot() {
    if (!window.jQuery || typeof tinymce === "undefined") {
      setTimeout(boot, 300);
      return;
    }
    hookAllEditors();
  }

  boot();
})();

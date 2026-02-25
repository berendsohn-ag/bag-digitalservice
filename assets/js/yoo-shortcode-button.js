(function () {

  // helpers
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

function normalize(s) {
  return (s || "").toString().toLowerCase().trim();
}

function openPicker(editor) {
  fetchShortcodes().then(list => {
    if (!list.length) {
      editor.windowManager && editor.windowManager.alert
        ? editor.windowManager.alert("Keine Shortcodes gefunden.")
        : alert("Keine Shortcodes gefunden.");
      return;
    }

    // Dialog-State
    let selected = list[0] || "";
    let query = "";

    // Dialog öffnen (nur HTML – kein listbox)
    const win = editor.windowManager.open({
      title: "Shortcode einfügen",
      body: [
        {
          type: "container",
          html:
            '<div class="bds-sc-picker">' +
              '<label style="display:block;font-size:12px;margin:0 0 4px;">Suchen</label>' +
              '<input type="text" class="bds-sc-search" placeholder="Tippe zum Filtern…" ' +
                     'style="width:100%;box-sizing:border-box;padding:6px 8px;margin:0 0 10px;" />' +

              '<label style="display:block;font-size:12px;margin:0 0 4px;">Verfügbar</label>' +
              '<select class="bds-sc-select" size="10" ' +
                      'style="width:100%;box-sizing:border-box;padding:6px 8px;"></select>' +
            '</div>'
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
        const $ = window.jQuery;
        const $root = $(win.getEl ? win.getEl() : document.body);

        const $search = $root.find("input.bds-sc-search").first();
        const $select = $root.find("select.bds-sc-select").first();

        function renderOptions() {
          const q = normalize(query);

          const filtered = !q
            ? list
            : list.filter(sc => normalize(sc).includes(q));

          $select.empty();

          if (!filtered.length) {
            $select.append('<option value="" disabled>— keine Treffer —</option>');
            selected = "";
            return;
          }

          filtered.forEach(sc => {
            const opt = $("<option/>").val(sc).text("[" + sc + "]");
            $select.append(opt);
          });

          // Auswahl beibehalten, falls noch vorhanden – sonst erstes Element
          if (selected && filtered.includes(selected)) {
            $select.val(selected);
          } else {
            selected = filtered[0];
            $select.val(selected);
          }
        }

        // initial
        renderOptions();

        // live filter
        $search.on("input", function () {
          query = $search.val();
          renderOptions();
        });

        // select change
        $select.on("change", function () {
          selected = $select.val() || "";
        });

        // Doppelklick = einfügen
        $select.on("dblclick", function () {
          if (!selected) return;
          editor.insertContent("[" + selected + "]");
          win.close();
        });

        // Fokus direkt ins Suchfeld
        $search.trigger("focus");
      }
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

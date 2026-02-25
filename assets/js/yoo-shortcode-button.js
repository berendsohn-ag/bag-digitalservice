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

      let selected = list[0] || "";
      let query = "";

      const html =
        '<div class="bds-sc-picker">' +
          '<div style="margin-bottom:10px;">' +
            '<label style="display:block;font-size:12px;margin:0 0 4px;">Suchen</label>' +
            '<input type="text" class="bds-sc-search" placeholder="Tippe zum Filtern…" ' +
                   'style="width:100%;box-sizing:border-box;padding:6px 8px;" />' +
          '</div>' +
          '<div>' +
            '<label style="display:block;font-size:12px;margin:0 0 4px;">Verfügbar</label>' +
            '<select class="bds-sc-select" size="10" ' +
                    'style="width:100%;box-sizing:border-box;padding:6px 8px;"></select>' +
          '</div>' +
        '</div>';

      const win = editor.windowManager.open({
        title: "Shortcode einfügen",
        body: [
          // WICHTIG: htmlpanel statt container
          { type: "htmlpanel", html: html }
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
          attachHandlers();
        }
      });

      // Falls onPostRender je nach Build nicht feuert -> fallback
      setTimeout(attachHandlers, 50);
      setTimeout(attachHandlers, 250);

      function attachHandlers() {
        const $ = window.jQuery;
        const rootEl = (win && win.getEl) ? win.getEl() : null;
        if (!rootEl) return;

        const $root = $(rootEl);
        const $search = $root.find("input.bds-sc-search").first();
        const $select = $root.find("select.bds-sc-select").first();

        if (!$search.length || !$select.length) return;

        // nur 1x binden
        if ($root.data("bdsBound")) return;
        $root.data("bdsBound", true);

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
            // text: [shortcode], value: shortcode
            $select.append(
              $("<option/>").val(sc).text("[" + sc + "]")
            );
          });

          // Auswahl beibehalten oder erstes Element nehmen
          if (selected && filtered.indexOf(selected) !== -1) {
            $select.val(selected);
          } else {
            selected = filtered[0];
            $select.val(selected);
          }
        }

        renderOptions();

        // live filter
        $search.on("input", function () {
          query = $search.val();
          renderOptions();
        });

        // selection
        $select.on("change", function () {
          selected = $select.val() || "";
        });

        // Enter im Suchfeld -> erstes Element einfügen
        $search.on("keydown", function (ev) {
          if (ev.key === "Enter") {
            ev.preventDefault();
            if (!selected) return;
            editor.insertContent("[" + selected + "]");
            win.close();
          }
        });

        // doppelklick auf option -> einfügen
        $select.on("dblclick", function () {
          if (!selected) return;
          editor.insertContent("[" + selected + "]");
          win.close();
        });

        // Fokus ins Suchfeld
        try { $search.trigger("focus"); } catch (e) {}
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

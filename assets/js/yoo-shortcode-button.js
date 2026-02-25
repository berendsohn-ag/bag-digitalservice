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

    const win = editor.windowManager.open({
      title: "Shortcode einfügen",
      body: [
        // ✅ TinyMCE textbox (wird bei dir gerendert)
        {
          type: "textbox",
          name: "bds_sc_search",
          label: "Suchen",
          value: "",
        },
        // ✅ dein bewährtes listbox
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
            win.close();
          }
        },
        { text: "Abbrechen", onclick: "close" }
      ],
      onPostRender: function () {
        const $ = window.jQuery;
        const rootEl = win.getEl ? win.getEl() : null;
        if (!rootEl) return;

        const $root = $(rootEl);

        // textbox input finden (in deinem Dialog gibt’s i.d.R. nur diesen einen textbox)
        const $search = $root.find("input.mce-textbox").first();
        // listbox select finden
        const $select = $root.find("select").last();

        if (!$search.length || !$select.length) return;

        // Optionen cachen
        const opts = $select.find("option").toArray().map(o => ({
          el: o,
          value: (o.value || "").toLowerCase(),
          text: (o.text || "").toLowerCase()
        }));

        function applyFilter(q) {
          const query = (q || "").toLowerCase().trim();
          let firstVisible = null;

          opts.forEach(o => {
            const match = !query || o.text.includes(query) || o.value.includes(query);
            o.el.style.display = match ? "" : "none";
            if (match && firstVisible === null) firstVisible = o.el.value;
          });

          // wenn aktuelle Auswahl weggefiltert wurde -> erstes sichtbares wählen
          const cur = $select.val();
          const curVisible = opts.some(o => o.el.value === cur && o.el.style.display !== "none");
          if (!curVisible && firstVisible) {
            $select.val(firstVisible).trigger("change");
            selected = firstVisible;
          }
        }

        // selected sauber halten
        $select.on("change", function () {
          const v = $select.val();
          if (v) selected = v;
        });

        // live filter
        $search.on("input", function () {
          applyFilter($search.val());
        });

        // Enter im Suchfeld = einfügen
        $search.on("keydown", function (ev) {
          if (ev.key === "Enter") {
            ev.preventDefault();
            editor.insertContent("[" + selected + "]");
            win.close();
          }
        });

        // Fokus ins Suchfeld
        setTimeout(function () { try { $search.get(0).focus(); } catch(e) {} }, 0);
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

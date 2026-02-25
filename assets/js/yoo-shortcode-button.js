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

    let win = null;

    win = editor.windowManager.open({
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
          values: items
        }
      ],
      buttons: [
        {
          text: "Einfügen",
          subtype: "primary",
          onclick: function () {
            // ✅ Wert IMMER direkt aus dem echten <select> holen
            const v = readSelectedFromDialog(win);
            if (!v) return;
            editor.insertContent("[" + v + "]");
            editor.windowManager.close();
          }
        },
        { text: "Abbrechen", onclick: "close" }
      ],
      onPostRender: function () {
        attachLiveFilter(win, list);
      }
    });
  });

  // --- helpers (nur innerhalb openPicker) ---
  function norm(s) {
    return (s || "").toString().toLowerCase().trim();
  }

  function findDialogRoot(win) {
    return win && win.getEl ? win.getEl() : null;
  }

  function findSearchInput($root) {
    // TinyMCE textbox -> input.mce-textbox
    return $root.find("input.mce-textbox").first();
  }

  function findListboxSelect($root) {
    // wir nehmen das select innerhalb der listbox-row
    // (bei manchen Setups gibt’s mehrere selects, daher: das, was in der Nähe vom label "Verfügbar" ist)
    let $sel = $root.find("select").last();

    // Falls das mal falsch ist, alternative: nimm das select, das am meisten options hat
    const $all = $root.find("select");
    if ($all.length > 1) {
      let best = null, bestCount = -1;
      $all.each(function () {
        const c = this.options ? this.options.length : 0;
        if (c > bestCount) { bestCount = c; best = this; }
      });
      if (best) $sel = $(best);
    }

    return $sel;
  }

  function readSelectedFromDialog(win) {
    const $ = window.jQuery;
    const rootEl = findDialogRoot(win);
    if (!rootEl) return "";
    const $root = $(rootEl);
    const $select = findListboxSelect($root);
    return ($select && $select.length) ? ($select.val() || "") : "";
  }

  function attachLiveFilter(win, fullList) {
    const $ = window.jQuery;
    const rootEl = findDialogRoot(win);
    if (!rootEl) return;

    const $root = $(rootEl);
    const $search = findSearchInput($root);
    const $select = findListboxSelect($root);

    if (!$search.length || !$select.length) return;

    // Original-Options als Datenquelle (value/text)
    const all = fullList.slice();

    // Render-Funktion: SELECT komplett neu befüllen (stabiler als option display:none)
    function render(filtered) {
      const current = $select.val();

      $select.empty();

      if (!filtered.length) {
        $select.append($("<option/>").val("").text("— keine Treffer —"));
        $select.val("");
        return;
      }

      filtered.forEach(sc => {
        $select.append($("<option/>").val(sc).text("[" + sc + "]"));
      });

      // Auswahl beibehalten, sonst erstes Element
      if (current && filtered.indexOf(current) !== -1) {
        $select.val(current);
      } else {
        $select.val(filtered[0]);
      }

      // change triggern, damit TinyMCE intern sauber bleibt
      $select.trigger("change");
    }

    // initial
    render(all);

    // live filter
    $search.on("input", function () {
      const q = norm($search.val());
      const filtered = !q ? all : all.filter(sc => norm(sc).includes(q));
      render(filtered);
    });

    // Enter im Suchfeld = einfügen
    $search.on("keydown", function (ev) {
      if (ev.key === "Enter") {
        ev.preventDefault();
        const v = $select.val();
        if (!v) return;
        editor.insertContent("[" + v + "]");
        editor.windowManager.close();
      }
    });

    // Fokus
    setTimeout(function () { try { $search.get(0).focus(); } catch(e) {} }, 0);
  }
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

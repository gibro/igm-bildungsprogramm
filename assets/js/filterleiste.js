/* ============================================================
   BI Seminarsuche – Such- & Filterleiste (Vanilla JS)

   Zwei Betriebsarten, beide aus derselben Konfiguration
   (data-bi-config am Wurzel-Element, Fallback window.BI_FILTER_CONFIG):

   1) Ergebnisseite [bi_seminarsuche] (Standard):
      URL-Parameter-gesteuert, jeder Klick lädt die Seite neu.

   2) Eigenständige Suchmaske [bi_suchmaske] (standalone: true):
      Die Auswahl wird nur im Browser gehalten, kein Reload je Klick.
      Trefferzahl und Facetten-Zähler kommen per AJAX nach, der Button
      "Suche starten" springt mit allen Parametern auf targetUrl.

   Mehrfachauswahl mit ODER, Pipe `|` als Trennzeichen.
   Mehrere Leisten je Seite sind möglich – jede Instanz ist gekapselt.
   ============================================================ */

(function () {
  'use strict';

  /* ---- 1) Icons (Inline-SVG, Lucide-Stil) ------------------ */
  var ICONS = {
    search:  '<circle cx="11" cy="11" r="7.5"/><path d="m21 21-4.3-4.3"/>',
    x:       '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    chev:    '<path d="m6 9 6 6 6-6"/>',
    check:   '<path d="M20 6 9 17l-5-5"/>',
    pin:     '<path d="M20 10c0 4.99-5.54 10.19-7.4 11.8a1 1 0 0 1-1.2 0C9.54 20.19 4 14.99 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
    tag:     '<path d="M12.59 2.59A2 2 0 0 0 11.17 2H4a2 2 0 0 0-2 2v7.17a2 2 0 0 0 .59 1.41l8.7 8.7a2.43 2.43 0 0 0 3.42 0l6.58-6.58a2.43 2.43 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r="1.2" fill="currentColor" stroke="none"/>',
    users:   '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    file:    '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
    calendar:'<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
    screen:  '<rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>'
  };
  function svg(name, size, sw) {
    return '<svg width="' + (size || 18) + '" height="' + (size || 18) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' + (sw || 1.8) + '" stroke-linecap="round" stroke-linejoin="round">' + (ICONS[name] || '') + '</svg>';
  }

  /* ---- 2) Kleine Helfer ------------------------------------ */
  var SEP = '|';
  /* Parameter, die die Suchmaske an die Ergebnisseite weiterreicht. Die
     Facetten-Namen kommen aus der Konfiguration – so reicht es, eine neue
     Facette in PHP zu ergänzen (z. B. „form"), ohne dass sie hier
     stillschweigend verschluckt wird. */
  var FIXED_KEYS = ['q', 'form', 'von', 'bis', 'nr'];
  function queryKeys(cfg) {
    var keys = ['q'];
    (cfg.categories || []).forEach(function (cat) {
      if (cat.param && keys.indexOf(cat.param) < 0) keys.push(cat.param);
      if (cat.fromParam && keys.indexOf(cat.fromParam) < 0) keys.push(cat.fromParam);
      if (cat.toParam && keys.indexOf(cat.toParam) < 0) keys.push(cat.toParam);
    });
    FIXED_KEYS.forEach(function (k) { if (keys.indexOf(k) < 0) keys.push(k); });
    return keys;
  }

  function el(html) { var d = document.createElement('div'); d.innerHTML = html.trim(); return d.firstChild; }
  function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function fmtRange(f, t) {
    function d(s) { var a = s.split('-'); return a[2] + '.' + a[1] + '.' + a[0].slice(2); }
    if (f && t) return d(f) + ' – ' + d(t);
    if (f) return 'ab ' + d(f);
    if (t) return 'bis ' + d(t);
    return '';
  }

  /* ---- 3) Eine Filterleiste ------------------------------- */
  function createFilter(rootEl) {
    var CONFIG = {
      searchParam: 'q',
      root: '#bi-filter',
      categories: [],
      standalone: false,       // true = Suchmaske ohne Ergebnisliste
      targetUrl: '',           // Ziel des Buttons "Suche starten"
      ajaxUrl: '',             // admin-ajax.php für die Live-Zähler
      programm: '',
      kicker: 'Bildungsprogramm',
      title: 'Seminar finden',
      hint: '',
      buttonLabel: 'Suche starten'
    };

    // Konfiguration bevorzugt aus data-bi-config (überlebt Page-Builder und
    // JS-Optimierung), Fallback auf das globale window.BI_FILTER_CONFIG.
    var cfg = null;
    var raw = rootEl.getAttribute('data-bi-config');
    if (raw) { try { cfg = JSON.parse(raw); } catch (e) { cfg = null; } }
    if (!cfg && window.BI_FILTER_CONFIG) cfg = window.BI_FILTER_CONFIG;
    if (cfg) { for (var k in cfg) { if (Object.prototype.hasOwnProperty.call(cfg, k)) CONFIG[k] = cfg[k]; } }

    var standalone = !!CONFIG.standalone;
    if (!CONFIG.hint) CONFIG.hint = standalone
      ? 'Mehrfachauswahl möglich · dann Suche starten'
      : 'Mehrfachauswahl möglich · Filter wirken sofort';

    /* Zustand: offenes Panel; im Standalone-Modus zusätzlich die Auswahl selbst */
    var state = { open: null };
    var localP = new URLSearchParams();
    if (!standalone) {
      try { state.open = sessionStorage.getItem('biOpenPanel') || null; sessionStorage.removeItem('biOpenPanel'); } catch (e) {}
    }

    /* ---- URL-Parameter lesen/schreiben ---------------------- */
    function params() {
      return new URLSearchParams(standalone ? localP.toString() : window.location.search);
    }
    function getVals(p, key, multi) {
      var v = p.get(key);
      if (!v) return [];
      return multi ? v.split(SEP).filter(Boolean) : [v];
    }
    function apply(p) {
      if (standalone) {
        localP = p;
        render();
        scheduleRefresh();
      } else {
        try { sessionStorage.setItem('biOpenPanel', state.open || ''); } catch (e) {}
        window.location.search = p.toString();
      }
    }

    /* ---- "Suche starten": zur Ergebnisseite springen -------- */
    function submit() {
      var target = CONFIG.targetUrl || window.location.pathname;
      var qs = new URLSearchParams();
      // nur bekannte Filterparameter übergeben, in stabiler Reihenfolge
      queryKeys(CONFIG).forEach(function (key) {
        var v = localP.get(key);
        if (v) qs.set(key, v);
      });
      var s = qs.toString();
      if (s) target += (target.indexOf('?') >= 0 ? '&' : '?') + s;
      window.location.href = target;
    }

    /* ---- Live-Zähler + Facetten per AJAX ------------------- */
    var refreshTimer = null, refreshSeq = 0;
    function scheduleRefresh() {
      if (!standalone || !CONFIG.ajaxUrl || typeof window.fetch !== 'function') return;
      clearTimeout(refreshTimer);
      refreshTimer = setTimeout(refreshNow, 120);
    }
    function refreshNow() {
      var seq = ++refreshSeq;
      var body = new URLSearchParams();
      body.set('action', 'bi_filter_refresh');
      if (CONFIG.programm) body.set('programm', CONFIG.programm);
      // fest vorgegebene Seminarform (Shortcode-Attribut form="…") mitschicken
      if (CONFIG.form) body.set('bi_force_form', CONFIG.form);
      queryKeys(CONFIG).forEach(function (key) {
        var v = localP.get(key);
        if (v) body.set(key, v);
      });
      rootEl.classList.add('bi-is-loading');
      window.fetch(CONFIG.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString()
      })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (seq !== refreshSeq) return; // veraltete Antwort verwerfen
          rootEl.classList.remove('bi-is-loading');
          if (!res || !res.success || !res.data) return;
          if (res.data.categories) CONFIG.categories = res.data.categories;
          if (res.data.count != null) rootEl.setAttribute('data-bi-count', res.data.count);
          if (res.data.total != null) rootEl.setAttribute('data-bi-total', res.data.total);
          render();
        })
        .catch(function () {
          if (seq === refreshSeq) rootEl.classList.remove('bi-is-loading');
        });
    }

    /* ---- Auswahl ändern ------------------------------------ */
    function toggleVal(cat, value) {
      var p = params();
      var cur = getVals(p, cat.param, cat.multi);
      var idx = cur.indexOf(value);
      if (cat.multi) {
        if (idx >= 0) cur.splice(idx, 1); else cur.push(value);
        if (cur.length) p.set(cat.param, cur.join(SEP)); else p.delete(cat.param);
      } else {
        if (idx >= 0) p.delete(cat.param); else p.set(cat.param, value);
      }
      p.delete('paged');
      apply(p);
    }

    function removeParam(key, value) {
      var p = params();
      if (value != null) {
        var cat = CONFIG.categories.filter(function (c) { return c.param === key; })[0];
        var cur = getVals(p, key, cat && cat.multi);
        cur = cur.filter(function (v) { return v !== value; });
        if (cur.length) p.set(key, cur.join(SEP)); else p.delete(key);
      } else {
        p.delete(key);
      }
      p.delete('paged');
      apply(p);
    }

    /* ---- Rendering ----------------------------------------- */
    function render() {
      var p = params();

      // Fokus/Cursor im Suchfeld über den Neuaufbau retten (Standalone rendert beim Tippen neu)
      var prevInput = rootEl.querySelector('.bi-search input');
      var keepFocus = !!prevInput && document.activeElement === prevInput;
      var caret = keepFocus ? prevInput.selectionStart : null;

      rootEl.innerHTML = '';
      rootEl.className = 'bi-filter' + (standalone ? ' bi-filter-standalone' : '');
      var card = el('<div class="bi-card"></div>');

      var total = rootEl.getAttribute('data-bi-total') || '';
      var count = rootEl.getAttribute('data-bi-count') || '';
      var num, lbl;
      if (count !== '' && total !== '' && count !== total) {
        num = count; lbl = 'von ' + total + ' Seminaren';
      } else {
        num = count !== '' ? count : total; lbl = 'buchbare Seminare';
      }
      var head = '<div class="bi-head">' +
        '<div><div class="bi-kicker">' + escapeHtml(CONFIG.kicker) + '</div>' +
        '<h2 class="bi-title">' + escapeHtml(CONFIG.title) + '</h2></div>' +
        (num !== '' ? '<div class="bi-count"><div class="bi-count-num">' + num + '</div>' +
          '<div class="bi-count-label">' + lbl + '</div></div>' : '') +
        '</div>';
      card.appendChild(el(head));

      /* Suchfeld */
      var q = p.get(CONFIG.searchParam) || '';
      var search = el('<div class="bi-search"><span class="bi-search-icon">' + svg('search', 20) + '</span>' +
        '<input type="text" placeholder="Stichwort oder Seminartitel suchen …">' +
        (q ? '<button class="bi-search-clear" aria-label="Suche löschen">' + svg('x', 15, 2) + '</button>' : '') +
        '</div>');
      var input = search.querySelector('input');
      input.value = q;
      var deb;
      input.addEventListener('input', function () {
        clearTimeout(deb);
        deb = setTimeout(function () {
          var pp = params();
          if (input.value) pp.set(CONFIG.searchParam, input.value); else pp.delete(CONFIG.searchParam);
          pp.delete('paged');
          apply(pp);
        }, 450);
      });
      if (standalone) {
        // Enter im Suchfeld startet die Suche direkt
        input.addEventListener('keydown', function (ev) {
          if (ev.key !== 'Enter') return;
          ev.preventDefault();
          clearTimeout(deb);
          if (input.value) localP.set(CONFIG.searchParam, input.value); else localP.delete(CONFIG.searchParam);
          submit();
        });
      }
      var clearBtn = search.querySelector('.bi-search-clear');
      if (clearBtn) clearBtn.addEventListener('click', function () { removeParam(CONFIG.searchParam); });
      card.appendChild(search);

      card.appendChild(el('<p class="bi-hint">' + escapeHtml(CONFIG.hint) + '</p>'));

      /* Filter-Buttons */
      var triggers = el('<div class="bi-triggers"></div>');
      CONFIG.categories.forEach(function (cat) {
        if (cat.type !== 'daterange' && (!cat.options || !cat.options.length)) return; // leere Facette ausblenden
        var isDate = cat.type === 'daterange';
        var selCount = isDate
          ? ((p.get(cat.fromParam) || p.get(cat.toParam)) ? 1 : 0)
          : getVals(p, cat.param, cat.multi).length;
        var open = state.open === (cat.param || cat.type);
        var btn = el('<button class="bi-trigger" type="button" aria-expanded="' + open + '">' +
          '<span class="bi-trigger-icon">' + svg(cat.icon) + '</span>' + cat.label +
          (isDate
            ? (selCount ? '<span class="bi-dot"></span>' : '')
            : (selCount ? '<span class="bi-badge">' + selCount + '</span>' : '')) +
          '<span class="bi-trigger-chev">' + svg('chev', 15, 2) + '</span></button>');
        btn.addEventListener('click', function () {
          var key = cat.param || cat.type;
          state.open = state.open === key ? null : key;
          render();
        });
        triggers.appendChild(btn);
      });
      card.appendChild(triggers);

      var openCat = CONFIG.categories.filter(function (c) { return (c.param || c.type) === state.open; })[0];
      if (openCat) card.appendChild(renderPanel(openCat, p));

      var chips = collectChips(p);
      if (chips.length) {
        var active = el('<div class="bi-active"><span class="bi-active-label">Aktive Filter</span></div>');
        chips.forEach(function (c) {
          var chip = el('<span class="bi-chip"><span class="bi-chip-cat">' + c.cat + '</span>' +
            '<span>' + escapeHtml(c.label) + '</span>' +
            '<button class="bi-chip-x" aria-label="Filter entfernen">' + svg('x', 12, 2.4) + '</button></span>');
          chip.querySelector('.bi-chip-x').addEventListener('click', c.onRemove);
          active.appendChild(chip);
        });
        var reset = el('<button class="bi-reset" type="button">Alle zurücksetzen</button>');
        reset.addEventListener('click', function () { apply(new URLSearchParams()); });
        active.appendChild(reset);
        card.appendChild(active);
      }

      /* "Suche starten" – nur in der eigenständigen Suchmaske */
      if (standalone) {
        var sub = el('<div class="bi-submit">' +
          '<button class="bi-submit-btn" type="button">' + svg('search', 18, 2.2) +
          '<span>' + escapeHtml(CONFIG.buttonLabel) + '</span>' +
          (count !== '' ? '<span class="bi-submit-count">' + escapeHtml(count) + ' Treffer</span>' : '') +
          '</button></div>');
        sub.querySelector('button').addEventListener('click', submit);
        card.appendChild(sub);
      }

      rootEl.appendChild(card);

      if (keepFocus) {
        var newInput = rootEl.querySelector('.bi-search input');
        if (newInput) {
          newInput.focus();
          try { newInput.setSelectionRange(caret, caret); } catch (e) {}
        }
      }
    }

    function renderPanel(cat, p) {
      var panel = el('<div class="bi-panel"></div>');
      var isDate = cat.type === 'daterange';
      var hasSel = isDate ? !!(p.get(cat.fromParam) || p.get(cat.toParam)) : getVals(p, cat.param, cat.multi).length > 0;

      var head = el('<div class="bi-panel-head"><span class="bi-panel-title">' + cat.label + ' wählen</span>' +
        '<div class="bi-panel-actions">' +
        (hasSel ? '<button class="bi-link" type="button">zurücksetzen</button>' : '') +
        '<button class="bi-panel-close" aria-label="Schließen">' + svg('x', 16, 2) + '</button></div></div>');
      var clear = head.querySelector('.bi-link');
      if (clear) clear.addEventListener('click', function () {
        var pp = params();
        if (isDate) { pp.delete(cat.fromParam); pp.delete(cat.toParam); } else pp.delete(cat.param);
        pp.delete('paged');
        apply(pp);
      });
      head.querySelector('.bi-panel-close').addEventListener('click', function () { state.open = null; render(); });
      panel.appendChild(head);

      if (isDate) {
        panel.appendChild(renderDatePanel(cat, p));
      } else {
        var opts = el('<div class="bi-options"></div>');
        var sel = getVals(p, cat.param, cat.multi);
        cat.options.forEach(function (o) {
          if (o.separator) { opts.appendChild(el('<div class="bi-opt-break" aria-hidden="true"></div>')); return; }
          var on = sel.indexOf(o.value) >= 0;
          var cnt = o.count ? '<span class="bi-opt-count">' + escapeHtml(String(o.count)) + '</span>' : '';
          var b = el('<button class="bi-opt" type="button" aria-pressed="' + on + '">' +
            (on ? svg('check', 14, 2.4) : '') + '<span>' + escapeHtml(o.label) + '</span>' + cnt + '</button>');
          b.addEventListener('click', function () { toggleVal(cat, o.value); });
          opts.appendChild(b);
        });
        panel.appendChild(opts);
      }
      return panel;
    }

    function renderDatePanel(cat, p) {
      var wrap = el('<div></div>');
      var presets = el('<div class="bi-presets">' +
        '<button class="bi-preset" data-preset="month" type="button">Diesen Monat</button>' +
        '<button class="bi-preset" data-preset="next3" type="button">Nächste 3 Monate</button></div>');
      function iso(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
      presets.querySelectorAll('.bi-preset').forEach(function (b) {
        b.addEventListener('click', function () {
          var pp = params(), now = new Date();
          if (b.dataset.preset === 'month') { pp.set(cat.fromParam, iso(new Date(now.getFullYear(), now.getMonth(), 1))); pp.set(cat.toParam, iso(new Date(now.getFullYear(), now.getMonth() + 1, 0))); }
          else { var e = new Date(); e.setMonth(e.getMonth() + 3); pp.set(cat.fromParam, iso(now)); pp.set(cat.toParam, iso(e)); }
          pp.delete('paged');
          apply(pp);
        });
      });
      wrap.appendChild(presets);

      var dates = el('<div class="bi-dates">' +
        '<label>von<input type="text" data-k="' + cat.fromParam + '" placeholder="TT.MM.JJJJ"></label>' +
        '<label>bis<input type="text" data-k="' + cat.toParam + '" placeholder="TT.MM.JJJJ"></label></div>');
      dates.querySelectorAll('input').forEach(function (inp) {
        inp.value = p.get(inp.dataset.k) || '';
        inp.addEventListener('change', function () {
          var pp = params();
          if (inp.value) pp.set(inp.dataset.k, inp.value); else pp.delete(inp.dataset.k);
          pp.delete('paged');
          apply(pp);
        });
      });
      wrap.appendChild(dates);

      if (typeof flatpickr !== 'undefined') {
        if (flatpickr.l10ns && flatpickr.l10ns.de) flatpickr.localize(flatpickr.l10ns.de);
        var common = { altInput: true, altFormat: 'd.m.Y', dateFormat: 'Y-m-d', minDate: 'today', allowInput: true };
        var fromInp = dates.querySelector('[data-k="' + cat.fromParam + '"]');
        var toInp   = dates.querySelector('[data-k="' + cat.toParam + '"]');
        var bisPicker = flatpickr(toInp, Object.assign({}, common, { defaultDate: p.get(cat.toParam) || null }));
        flatpickr(fromInp, Object.assign({}, common, {
          defaultDate: p.get(cat.fromParam) || null,
          onChange: function (sel) { bisPicker.set('minDate', sel[0] || 'today'); }
        }));
      }
      return wrap;
    }

    function collectChips(p) {
      var chips = [];
      var q = p.get(CONFIG.searchParam);
      if (q) chips.push({ cat: 'Suche', label: '„' + q + '“', onRemove: function () { removeParam(CONFIG.searchParam); } });
      CONFIG.categories.forEach(function (cat) {
        if (cat.type === 'daterange') {
          var f = p.get(cat.fromParam), t = p.get(cat.toParam);
          if (f || t) chips.push({ cat: 'Zeitraum', label: fmtRange(f, t), onRemove: function () { var pp = params(); pp.delete(cat.fromParam); pp.delete(cat.toParam); pp.delete('paged'); apply(pp); } });
          return;
        }
        getVals(p, cat.param, cat.multi).forEach(function (v) {
          var o = (cat.options || []).filter(function (x) { return x.value === v; })[0];
          chips.push({ cat: cat.label, label: o ? o.label : v, onRemove: function () { removeParam(cat.param, v); } });
        });
      });
      return chips;
    }

    render();
    return { config: CONFIG, render: render, root: rootEl };
  }

  /* ---- 4) Start: alle Leisten auf der Seite initialisieren -- */
  function init() {
    var instances = [];
    var nodes = document.querySelectorAll('[data-bi-config]');
    if (nodes.length) {
      for (var i = 0; i < nodes.length; i++) instances.push(createFilter(nodes[i]));
    } else {
      // Fallback: Konfiguration nur global vorhanden (ohne data-Attribut)
      var sel = (window.BI_FILTER_CONFIG && window.BI_FILTER_CONFIG.root) || '#bi-filter';
      var node = document.querySelector(sel);
      if (node) instances.push(createFilter(node));
    }
    window.BIFilter = {
      instances: instances,
      config: instances.length ? instances[0].config : null,
      render: function () { instances.forEach(function (inst) { inst.render(); }); }
    };
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();

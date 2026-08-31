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
    screen:  '<rect width="20" height="14" x="2" y="3" rx="2"/><path d="M8 21h8"/><path d="M12 17v4"/>',
    /* Programm(jahr): bewusst ein Buch und kein zweiter Kalender – der Kalender
       gehört schon zum Zeitraum-Chip und wäre daneben nicht zu unterscheiden. */
    book:    '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
    /* Eigene Chips („Schnellzugriff"): ein Blitz, kein Stern – ein Stern läse
       sich als „Favorit", also als etwas, das der Besucher selbst gesetzt hat. */
    bolt:    '<path d="M13 2 4.1 12.7a.6.6 0 0 0 .5 1H11l-1 8.3 8.9-10.7a.6.6 0 0 0-.5-1H12z"/>'
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
    /* Auch die Parameter der eigenen Chips: Ein Chip darf einen Filter setzen,
       dessen Facette in den Einstellungen abgeschaltet ist – dann steht sie in
       den Kategorien nicht, und die Suchmaske ließe den Wert beim Absprung
       sonst stillschweigend fallen. */
    (cfg.shortcuts || []).forEach(function (sc) {
      Object.keys(sc.params || {}).forEach(function (k) { if (keys.indexOf(k) < 0) keys.push(k); });
    });
    FIXED_KEYS.forEach(function (k) { if (keys.indexOf(k) < 0) keys.push(k); });
    return keys;
  }

  /* Vorschlagsarten: Überschrift und Symbol je Gruppe. Die Reihenfolge im
     Kasten bestimmt der Server – hier steht nur, wie eine Gruppe heißt. */
  var AC_TITEL = { wort: 'Suchbegriffe', filter: 'Filter', seminar: 'Seminare' };
  var AC_ICON  = { wort: 'search', filter: 'tag', seminar: 'file' };
  /* Fortlaufende Nummer je Leiste – die Vorschläge brauchen eindeutige IDs,
     damit aria-activedescendant bei mehreren Leisten je Seite stimmt. */
  var AC_SEQ = 0;

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
      shortcuts: [],           // eigene Chips aus den Einstellungen
      standalone: false,       // true = Suchmaske ohne Ergebnisliste
      targetUrl: '',           // Ziel des Buttons "Suche starten"
      targetTop: 0,            // 1 = Sprung übernimmt das ganze Fenster (iframe)
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

    // Diese Trefferliste samt Filtern für den Zurück-Link der Detailseiten
    // merken (siehe zurueck.js). Nur die Ergebnisseite ist ein Ziel, auf das
    // sich zurückkehren lohnt – die Suchmaske hält ihre Auswahl ohnehin nur
    // im Browser und hätte nach dem Absprung nichts mehr zu zeigen.
    if (!standalone) {
      try { sessionStorage.setItem('biSuche', window.location.pathname + window.location.search); } catch (e) {}
    }
    /* Der graue Hinweis unter dem Suchfeld nennt seit 1.107.0 auch, WAS die
       Suche liest: Sie vergleicht nicht mehr nur den Titel, sondern alle
       Angaben zum Seminar – Beschreibung, Themen, Ort, Referent*innen,
       Ansprechpartner*in, Nummer und die Filterbegriffe. Wer das nicht weiß,
       tippt weiter nur Titelwörter ein. Seit 1.108.0 steht dort auch, dass es
       beim Tippen Vorschläge gibt – ein Kasten, der erst beim zweiten Zeichen
       aufgeht, wird sonst schlicht übersehen. */
    if (!CONFIG.hint) CONFIG.hint = standalone
      ? 'Suche über alle Seminardaten · Vorschläge beim Tippen · dann Suche starten'
      : 'Suche über alle Seminardaten · Vorschläge beim Tippen, Enter sucht · Filter wirken sofort';

    /* Zustand: offenes Panel; im Standalone-Modus zusätzlich die Auswahl selbst */
    var state = { open: null };
    var localP = new URLSearchParams();
    // Noch nicht abgeschickter Text im Suchfeld (null = Feld folgt dem q-Parameter).
    // Die Volltextsuche läuft erst mit Enter bzw. "Suche starten", der Tippstand
    // muss aber Neuaufbauten der Leiste (AJAX-Refresh, Panel öffnen) überleben.
    var typedQ = null;
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

    /* ---- Suchtext übernehmen -------------------------------- */
    // Liefert den aktuell im Feld stehenden Text (auch wenn er noch nicht
    // abgeschickt wurde) und schreibt ihn in die übergebenen Parameter.
    function commitSearch(p) {
      var inp = rootEl.querySelector('.bi-search input');
      var v = inp ? inp.value.trim() : (typedQ != null ? typedQ.trim() : null);
      if (v == null) return p;
      typedQ = null;
      if (v) p.set(CONFIG.searchParam, v); else p.delete(CONFIG.searchParam);
      p.delete('paged');
      return p;
    }

    /* ---- Ein Ziel ansteuern --------------------------------- */
    // Eingebettete Maske mit ?bi_ziel=oben: Das Ziel soll nicht in den flachen
    // Rahmen, sondern ins ganze Fenster. Ein Rahmen darf das oberste Fenster
    // steuern, solange ein Klick dahintersteht – und der steht hier, sonst
    // wäre die Funktion nicht gelaufen.
    function goTo(target) {
      if (CONFIG.targetTop) {
        try {
          window.top.location.href = target;
        } catch (e) {
          // Verbietet es eine sandbox-Regel doch, ist ein neuer Tab immer noch
          // besser als ein Knopf, der nichts tut.
          window.open(target, '_blank', 'noopener');
        }
        return;
      }
      window.location.href = target;
    }

    /* ---- "Suche starten": zur Ergebnisseite springen -------- */
    function submit() {
      commitSearch(localP);
      var target = CONFIG.targetUrl || window.location.pathname;
      var qs = new URLSearchParams();
      // nur bekannte Filterparameter übergeben, in stabiler Reihenfolge
      queryKeys(CONFIG).forEach(function (key) {
        var v = localP.get(key);
        if (v) qs.set(key, v);
      });
      var s = qs.toString();
      if (s) target += (target.indexOf('?') >= 0 ? '&' : '?') + s;
      goTo(target);
    }

    /* ========================================================
       Autovervollständigung

       Drei Arten von Vorschlägen, weil es drei Arten von Absicht gibt:
       ein Suchbegriff zu Ende geschrieben, ein Filter als Chip, oder gleich
       das Seminar selbst. Welche das sind, entscheidet der Server
       (BI_Filter::vorschlaege) – hier steht nur, wie sie aussehen und was
       ein Klick bewirkt.

       DIE VOLLTEXTSUCHE LÄUFT WEITERHIN ERST MIT ENTER. Die Vorschläge sind
       ein Angebot, keine Suche beim Tippen: Wer sie ignoriert und Enter
       drückt, bekommt genau das, was im Feld steht.
       ======================================================== */
    var acSeq    = ++AC_SEQ;
    var acItems  = [];    // zuletzt geholte Vorschläge
    var acActive = -1;    // Tastaturauswahl; -1 = das Getippte selbst
    var acOpen   = false;
    var acTimer  = null;
    var acCtrl   = null;  // AbortController der laufenden Anfrage

    function acId(i) { return 'bi-ac-' + acSeq + '-' + i; }
    function acBox() { return rootEl.querySelector('.bi-ac'); }
    function acInput() { return rootEl.querySelector('.bi-search input'); }

    function acClose() {
      acOpen = false;
      acActive = -1;
      acPaint();
    }

    function acPaint() {
      var box = acBox(), inp = acInput();
      if (!box) return;
      if (!acOpen || !acItems.length) {
        box.innerHTML = '';
        box.hidden = true;
        if (inp) { inp.setAttribute('aria-expanded', 'false'); inp.removeAttribute('aria-activedescendant'); }
        return;
      }
      var html = '', gruppe = '';
      acItems.forEach(function (it, i) {
        if (it.typ !== gruppe) {
          gruppe = it.typ;
          html += '<div class="bi-ac-head" role="presentation">' + escapeHtml(AC_TITEL[gruppe] || '') + '</div>';
        }
        var aktiv = (i === acActive);
        html += '<div class="bi-ac-item' + (aktiv ? ' is-active' : '') + '" role="option" id="' + acId(i) + '"' +
          ' aria-selected="' + (aktiv ? 'true' : 'false') + '" data-i="' + i + '">' +
          '<span class="bi-ac-icon">' + svg(AC_ICON[it.typ] || 'search', 16) + '</span>' +
          '<span class="bi-ac-text"><span class="bi-ac-label">' + escapeHtml(it.label || '') + '</span>' +
          (it.zusatz ? '<span class="bi-ac-zusatz">' + escapeHtml(it.zusatz) + '</span>' : '') +
          '</span></div>';
      });
      box.innerHTML = html;
      box.hidden = false;
      if (inp) {
        inp.setAttribute('aria-expanded', 'true');
        if (acActive >= 0) inp.setAttribute('aria-activedescendant', acId(acActive));
        else inp.removeAttribute('aria-activedescendant');
      }
      var aktivEl = box.querySelector('.is-active');
      if (aktivEl && aktivEl.scrollIntoView) aktivEl.scrollIntoView({ block: 'nearest' });
    }

    /* Ab drei Zeichen und nach einer Denkpause von 300 ms. Beides ist keine
       Kosmetik: Jede Anfrage kostet auf dem Server einen vollen
       WordPress-Start, und bei zwei Zeichen passt ohnehin fast alles. */
    var AC_MIN = 3, AC_PAUSE = 300;

    function acFetch(text) {
      if (!CONFIG.ajaxUrl || typeof window.fetch !== 'function' || text.length < AC_MIN) {
        acItems = [];
        acClose();
        return;
      }
      // Die vorige Anfrage abbrechen: Sonst kann eine langsamere Antwort auf
      // eine ältere Eingabe die neuere überschreiben.
      if (acCtrl) { try { acCtrl.abort(); } catch (e) {} }
      var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
      acCtrl = ctrl;

      var body = new URLSearchParams();
      body.set('action', 'bi_suche_vorschlag');
      body.set('q', text);
      if (CONFIG.programm) body.set('bi_force_programm', CONFIG.programm);
      if (CONFIG.form) body.set('bi_force_form', CONFIG.form);

      fetch(CONFIG.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString(),
        signal: ctrl ? ctrl.signal : undefined
      })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (acCtrl !== ctrl) return;                 // eine neuere Anfrage ist unterwegs
          var inp = acInput();
          if (!inp || inp.value.trim() !== text) return; // inzwischen weitergetippt
          acItems  = (j && j.success && j.data && j.data.items) ? j.data.items : [];
          acActive = -1;
          acOpen   = acItems.length > 0;
          acPaint();
        })
        .catch(function () { /* abgebrochen oder offline: dann eben keine Vorschläge */ });
    }

    function acTippen(text) {
      clearTimeout(acTimer);
      if (text.trim().length < AC_MIN) { acItems = []; acClose(); return; }
      // Erst nach einer Pause fragen – sonst löst jeder Buchstabe eine eigene
      // Anfrage aus, und die letzte Antwort gewänne per Zufall.
      acTimer = setTimeout(function () { acFetch(text.trim()); }, AC_PAUSE);
    }

    function acWaehlen(i) {
      var it = acItems[i];
      if (!it) return;
      var inp = acInput();
      acItems = [];
      acClose();

      if ('seminar' === it.typ && it.url) {
        goTo(it.url);
        return;
      }

      if ('filter' === it.typ) {
        var p = params();
        var vals = getVals(p, it.param, true);
        if (vals.indexOf(it.value) < 0) vals.push(it.value);
        p.set(it.param, vals.join(SEP));
        p.delete('paged');
        // Das angefangene Wort ist jetzt der Chip – es doppelt als Suchtext
        // stehen zu lassen, engte die Treffer ein zweites Mal ein. Vorherige
        // Wörter bleiben: „arbeitsrecht sprock" wird zu „arbeitsrecht" + Chip.
        var rest = (inp ? inp.value : '').trim().split(/\s+/).slice(0, -1).join(' ');
        if (rest) p.set(CONFIG.searchParam, rest); else p.delete(CONFIG.searchParam);
        typedQ = null;
        if (inp) inp.value = rest;
        apply(p);
        return;
      }

      // 'wort': das angefangene Wort zu Ende schreiben und suchen
      if (inp) inp.value = it.q || it.label;
      typedQ = it.q || it.label;
      if (standalone) submit(); else apply(commitSearch(params()));
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
      // Fest vorgegebenes Programmjahr und feste Seminarform (Shortcode-Attribute
      // programm="…" / form="…") unter eigenem Namen mitschicken: „programm" ist
      // zugleich eine Facette und würde von der Auswahl aus der Leiste überschrieben.
      if (CONFIG.programm) body.set('bi_force_programm', CONFIG.programm);
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

    /* ---- Eigene Chips („Schnellzugriff") -------------------- */
    /* Ein Chip ist eine gespeicherte Suche: Er SETZT die Parameter, die in
       seiner Adresse standen, und nimmt beim zweiten Klick genau sie wieder
       zurück. Er ersetzt die übrige Auswahl also nicht – wer erst „Online"
       angetippt hat und dann den Chip, behält beides. Alles andere wäre eine
       stille Rücknahme dessen, was der Besucher gerade eingestellt hat.

       Mehrfachwerte werden wertweise verglichen und wertweise entfernt: Setzt
       ein Chip ziel=A und der Besucher wählt zusätzlich ziel=B, gilt der Chip
       weiterhin als aktiv, und sein Zurücknehmen lässt B stehen. */
    var SINGLE_KEYS = ['q', 'von', 'bis'];   // Parameter ohne Mehrfachwerte
    function scVals(v) { return String(v || '').split(SEP).filter(Boolean); }
    function scKeys(sc) { return Object.keys((sc && sc.params) || {}); }

    function shortcutActive(sc, p) {
      var keys = scKeys(sc);
      if (!keys.length) return false;
      return keys.every(function (k) {
        var cur = p.get(k);
        if (!cur) return false;
        if (SINGLE_KEYS.indexOf(k) >= 0) return cur === sc.params[k];
        var have = scVals(cur);
        return scVals(sc.params[k]).every(function (v) { return have.indexOf(v) >= 0; });
      });
    }

    function toggleShortcut(sc) {
      var p  = params();
      var on = shortcutActive(sc, p);
      scKeys(sc).forEach(function (k) {
        var werte = scVals(sc.params[k]);
        var einzeln = SINGLE_KEYS.indexOf(k) >= 0;
        if (on) {
          if (einzeln) { p.delete(k); return; }
          var rest = scVals(p.get(k)).filter(function (v) { return werte.indexOf(v) < 0; });
          if (rest.length) p.set(k, rest.join(SEP)); else p.delete(k);
        } else {
          if (einzeln) { p.set(k, sc.params[k]); return; }
          var cur = scVals(p.get(k));
          werte.forEach(function (v) { if (cur.indexOf(v) < 0) cur.push(v); });
          p.set(k, cur.join(SEP));
        }
      });
      // Bringt der Chip einen Suchtext mit (oder nimmt ihn zurück), gewinnt er
      // gegen das, was gerade ununterbrochen im Feld steht.
      if (scKeys(sc).indexOf('q') >= 0) typedQ = null;
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

      /* Suchfeld – die Volltextsuche läuft erst mit Enter, nicht bei jedem Zeichen.
         Gesucht wird über alle Datenfelder des Seminars (BI_Suche::meta_keys).
         Beim Tippen kommen Vorschläge dazu (siehe Autovervollständigung oben);
         sie sind ein Angebot und ersetzen das Enter nicht. */
      var q = p.get(CONFIG.searchParam) || '';
      var shown = typedQ != null ? typedQ : q;
      var search = el('<div class="bi-search"><span class="bi-search-icon">' + svg('search', 20) + '</span>' +
        '<input type="search" enterkeyhint="search" autocomplete="off" spellcheck="false"' +
        ' role="combobox" aria-autocomplete="list" aria-expanded="false" aria-haspopup="listbox"' +
        ' aria-controls="bi-ac-' + acSeq + '" aria-label="Seminare durchsuchen"' +
        ' placeholder="Suchbegriff eingeben und Enter drücken – gesucht wird in allen Seminardaten">' +
        (shown ? '<button class="bi-search-clear" aria-label="Suche löschen">' + svg('x', 15, 2) + '</button>' : '') +
        '<div class="bi-ac" id="bi-ac-' + acSeq + '" role="listbox" aria-label="Vorschläge" hidden></div>' +
        '</div>');
      var input = search.querySelector('input');
      input.value = shown;
      // Tippen ändert die Suche noch nicht, wird aber für Neuaufbauten gemerkt.
      input.addEventListener('input', function () {
        typedQ = input.value;
        acTippen(input.value);
      });
      input.addEventListener('keydown', function (ev) {
        if ('ArrowDown' === ev.key || 'ArrowUp' === ev.key) {
          if (!acOpen || !acItems.length) return;
          ev.preventDefault();
          // -1 steht für „das Getippte" – von dort geht es in beide Richtungen
          // wieder heraus, damit man die eigene Eingabe zurückbekommt.
          if ('ArrowDown' === ev.key) acActive = (acActive + 1 >= acItems.length) ? -1 : acActive + 1;
          else acActive = (acActive - 1 < -1) ? acItems.length - 1 : acActive - 1;
          acPaint();
          return;
        }
        if ('Escape' === ev.key) {
          if (acOpen) { ev.preventDefault(); acClose(); }
          return;
        }
        if ('Enter' !== ev.key) return;
        ev.preventDefault();
        if (acOpen && acActive >= 0) {
          acWaehlen(acActive);          // ein ausgewählter Vorschlag gewinnt
          return;
        }
        acClose();
        if (standalone) {
          submit();                     // Enter springt direkt zur Ergebnisseite
        } else {
          apply(commitSearch(params()));
        }
      });
      // Der Kasten schließt beim Verlassen des Feldes – aber erst, nachdem ein
      // Klick auf einen Vorschlag durch ist (deshalb mousedown mit
      // preventDefault am Kasten selbst, siehe unten).
      input.addEventListener('blur', function () { setTimeout(acClose, 120); });
      input.addEventListener('focus', function () {
        if (acItems.length && input.value.trim().length >= AC_MIN) { acOpen = true; acPaint(); }
      });

      var acEl = search.querySelector('.bi-ac');
      // mousedown statt click: So verliert das Eingabefeld den Fokus gar nicht
      // erst, und der Kasten ist beim Klick noch da.
      acEl.addEventListener('mousedown', function (ev) {
        var item = ev.target.closest ? ev.target.closest('.bi-ac-item') : null;
        if (!item) return;
        ev.preventDefault();
        acWaehlen(parseInt(item.getAttribute('data-i'), 10));
      });

      var clearBtn = search.querySelector('.bi-search-clear');
      if (clearBtn) clearBtn.addEventListener('click', function () {
        typedQ = null;
        acItems = [];
        acClose();
        if (q) { removeParam(CONFIG.searchParam); return; }
        // Nur getippter, noch nicht abgeschickter Text: Feld leeren, nichts neu laden
        input.value = '';
        clearBtn.remove();
        input.focus();
      });
      card.appendChild(search);

      card.appendChild(el('<p class="bi-hint">' + escapeHtml(CONFIG.hint) + '</p>'));

      /* Eigene Chips – über den Filtern, weil sie der schnellere Weg zum selben
         Ziel sind: erst das fertige Angebot, dann das Zusammenstellen von Hand. */
      var shortcuts = CONFIG.shortcuts || [];
      if (shortcuts.length) {
        var quick = el('<div class="bi-quick"><span class="bi-quick-label">Schnellzugriff</span></div>');
        shortcuts.forEach(function (sc) {
          if (!scKeys(sc).length) return;
          var on = shortcutActive(sc, p);
          var b = el('<button class="bi-quick-btn" type="button" aria-pressed="' + on + '">' +
            '<span class="bi-quick-icon">' + svg(on ? 'check' : 'bolt', 15, 2.2) + '</span>' +
            '<span>' + escapeHtml(sc.label) + '</span></button>');
          b.addEventListener('click', function () { toggleShortcut(sc); });
          quick.appendChild(b);
        });
        if (quick.querySelector('.bi-quick-btn')) card.appendChild(quick);
      }

      /* Filter-Buttons.
         Der aufgeklappte Bereich hängt DIREKT hinter seinem Button – innerhalb
         derselben Flex-Zeile, als volle Breite. Auf dem Handy stehen die
         Buttons in mehreren Reihen; lag der Bereich hinter allen Reihen, öffnete
         ein Tipp oben etwas, das erst unterhalb von vier weiteren Buttons
         auftauchte – man sah nicht, dass überhaupt etwas passiert ist.
         Auf breiten Schirmen ändert sich dadurch nichts, solange die Buttons in
         eine Zeile passen: „hinter dem Button" ist dann dieselbe Stelle wie
         „hinter der Zeile". */
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
        if (open) triggers.appendChild(renderPanel(cat, p));
      });
      card.appendChild(triggers);

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

      // Erst JETZT hängt der Vorschlagskasten im Dokument – vorher fände
      // acPaint() ihn nicht und ein offener Kasten verschwände bei jedem
      // Neuaufbau (AJAX-Zähler, Panel geöffnet).
      acPaint();
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

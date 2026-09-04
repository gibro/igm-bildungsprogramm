/**
 * Admin-Seite „Benachrichtigungen": Formatier-Leiste über den Textfeldern und
 * das Ein-/Ausblenden der Felder, die nur zu bestimmten Auswahlen gehören.
 *
 * Formatier-Leiste:
 *
 * Die Knöpfe fügen ausschließlich Steuerzeichen in das Textfeld ein – es gibt
 * kein verstecktes HTML und keinen zweiten Zustand, den man verlieren könnte.
 * Gespeichert wird immer genau das, was im Textfeld steht.
 *
 * data-wrap   Auswahl mit dem Zeichen umschließen (**fett**)
 * data-block  jede markierte Zeile mit dem Präfix versehen (- Punkt), erneuter
 *             Klick entfernt es wieder
 * data-insert Text an der Cursorposition einfügen
 * data-link   [Auswahl](https://) einfügen und die URL markieren
 */
(function () {
  'use strict';

  function areaOf(btn) {
    var box = btn.closest('.bi-mailedit');
    return box ? box.querySelector('.bi-mailedit__area') : null;
  }

  /** Ersetzt den Bereich [from, to) und setzt die Auswahl auf selStart..selEnd */
  function replaceRange(area, from, to, text, selStart, selEnd) {
    var val = area.value;
    area.value = val.slice(0, from) + text + val.slice(to);
    area.focus();
    area.setSelectionRange(selStart, selEnd);
    // Damit WordPress/Browser den geänderten Wert als Eingabe mitbekommen
    area.dispatchEvent(new Event('input', { bubbles: true }));
  }

  function wrap(area, token) {
    var s = area.selectionStart;
    var e = area.selectionEnd;
    var sel = area.value.slice(s, e);
    var len = token.length;

    // Schon umschlossen? -> Auszeichnung wieder entfernen
    if (area.value.slice(s - len, s) === token && area.value.slice(e, e + len) === token) {
      replaceRange(area, s - len, e + len, sel, s - len, s - len + sel.length);
      return;
    }
    if (sel.slice(0, len) === token && sel.slice(-len) === token && sel.length > 2 * len) {
      var inner = sel.slice(len, -len);
      replaceRange(area, s, e, inner, s, s + inner.length);
      return;
    }

    var text = sel || 'Text';
    replaceRange(area, s, e, token + text + token, s + len, s + len + text.length);
  }

  function block(area, prefix) {
    var val = area.value;
    var s = val.lastIndexOf('\n', area.selectionStart - 1) + 1;
    var e = val.indexOf('\n', area.selectionEnd);
    if (e === -1) e = val.length;

    var lines = val.slice(s, e).split('\n');
    var alreadySet = lines.every(function (l) { return l.indexOf(prefix) === 0; });

    var out = lines.map(function (l) {
      return alreadySet ? l.slice(prefix.length) : prefix + l;
    }).join('\n');

    replaceRange(area, s, e, out, s, s + out.length);
  }

  function insert(area, text) {
    var s = area.selectionStart;
    var e = area.selectionEnd;
    replaceRange(area, s, e, text, s + text.length, s + text.length);
  }

  function link(area) {
    var s = area.selectionStart;
    var e = area.selectionEnd;
    var sel = area.value.slice(s, e) || 'Linktext';
    var url = 'https://';
    var text = '[' + sel + '](' + url + ')';
    // Cursor auf die URL, damit sie direkt überschrieben werden kann
    var urlStart = s + sel.length + 3;
    replaceRange(area, s, e, text, urlStart, urlStart + url.length);
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.bi-mailedit__btn');
    if (!btn) return;
    var area = areaOf(btn);
    if (!area) return;

    ev.preventDefault();
    if (btn.dataset.wrap) wrap(area, btn.dataset.wrap);
    else if (btn.dataset.block) block(area, btn.dataset.block);
    else if (btn.dataset.insert) insert(area, btn.dataset.insert);
    else if (btn.dataset.link) link(area);
  });

  var FIELDS = '.bi-mailedit__area, .bi-mailedit__field';

  /* Letzte Cursorposition merken: Beim Griff zur Platzhalter-Auswahl verliert das
     Feld den Fokus. Wurde es nie angeklickt, wird am Ende angefügt statt am Anfang. */
  document.addEventListener('blur', function (ev) {
    var f = ev.target;
    if (!f.classList || !f.matches || !f.matches(FIELDS)) return;
    f.dataset.selStart = f.selectionStart;
    f.dataset.selEnd = f.selectionEnd;
  }, true);

  /**
   * Platzhalter-Auswahl: setzt den gewählten Platzhalter an der Cursorposition ein.
   * Zielfeld ist das mehrzeilige Textfeld (.bi-mailedit__area) oder – bei einzeiligen
   * Feldern wie „Antworten an" – das Eingabefeld (.bi-mailedit__field).
   */
  document.addEventListener('change', function (ev) {
    var sel = ev.target;
    if (!sel.classList || !sel.classList.contains('bi-mailedit__ph')) return;
    if (!sel.value) return;

    var box = sel.closest('.bi-mailedit');
    var area = box ? box.querySelector(FIELDS) : null;
    if (area) {
      var s = area.dataset.selStart, e = area.dataset.selEnd;
      if (s === undefined) { s = area.value.length; e = s; }   // noch nie im Feld gewesen
      area.setSelectionRange(Number(s), Number(e));
      insert(area, withSeparator(area, sel.value));
      area.dataset.selStart = area.selectionStart;
      area.dataset.selEnd = area.selectionEnd;
    }
    sel.selectedIndex = 0;
  });

  /**
   * In Adressfeldern („Antworten an") das Komma mitliefern.
   *
   * Ohne das entsteht beim Anklicken mehrerer Platzhalter eine zusammengeklebte
   * Kette wie {email}{geschaeftsstelle_email} – die ergibt beim Versand keine
   * gültige Adresse mehr und der Header fällt komplett weg. In mehrzeiligen
   * Textfeldern wird nichts ergänzt, dort ist der Platzhalter Fließtext.
   */
  function withSeparator(field, value) {
    if (!field.classList.contains('bi-mailedit__field')) return value;
    var before = field.value.slice(0, field.selectionStart);
    var after = field.value.slice(field.selectionEnd);
    if (/[^\s,]$/.test(before)) value = ', ' + value;   // davor steht schon eine Adresse
    if (/^[^\s,]/.test(after)) value = value + ', ';    // dahinter folgt direkt eine
    return value;
  }

  /**
   * Bearbeiten-Maske: Zeilen mit data-when-type / data-when-schedule gehören nur zu
   * einer bestimmten Auswahl. Sie werden lediglich versteckt, nicht entfernt – die
   * Werte gehen also beim Speichern nicht verloren, wenn man die Auswahl kurz ändert.
   */
  function syncRows(form) {
    var type = form.querySelector('.bi-trigger-type');
    var schedule = form.querySelector('.bi-trigger-schedule');

    form.querySelectorAll('[data-when-type]').forEach(function (row) {
      row.hidden = !type || row.dataset.whenType !== type.value;
    });
    form.querySelectorAll('[data-when-schedule]').forEach(function (row) {
      row.hidden = !schedule || row.dataset.whenSchedule !== schedule.value;
    });
  }

  /**
   * Bedingung: Die Werteliste gehört zur gewählten Taxonomie. Beim Wechsel wird
   * sie aus data-terms neu aufgebaut. Der gespeicherte Wert (data-current) bleibt
   * wählbar, auch wenn er zu keinem Begriff passt – dann sichtbar markiert, damit
   * ein Tippfehler aus alten Beständen nicht stumm verschwindet, sondern auffällt.
   */
  function syncCondValue(form) {
    var tax = form.querySelector('#bi_cond_tax');
    var sel = form.querySelector('.bi-cond-value');
    if (!tax || !sel) return;

    var lists = {};
    try { lists = JSON.parse(sel.dataset.terms || '{}'); } catch (e) { lists = {}; }
    var names = lists[tax.value] || [];
    var chosen = sel.value;
    var current = sel.dataset.current || '';

    sel.innerHTML = '';
    sel.appendChild(new Option('— Wert wählen —', ''));
    if (current && names.indexOf(current) === -1) {
      sel.appendChild(new Option(current + ' (kein solcher Begriff!)', current));
    }
    names.forEach(function (name) {
      sel.appendChild(new Option(name, name));
    });

    // Bisherige Auswahl behalten, wenn es sie in der neuen Liste gibt – sonst
    // den gespeicherten Wert, sonst leer.
    var keep = [chosen, current].filter(function (v) {
      return v && (names.indexOf(v) !== -1 || v === current);
    });
    sel.value = keep.length ? keep[0] : '';
    sel.disabled = !tax.value;
  }

  function initForm() {
    var form = document.querySelector('.bi-trigger-form');
    if (!form) return;

    syncRows(form);
    syncCondValue(form);
    form.addEventListener('change', function (ev) {
      if (!ev.target.classList) return;
      if (ev.target.classList.contains('bi-trigger-type') ||
          ev.target.classList.contains('bi-trigger-schedule')) {
        syncRows(form);
      }
      if (ev.target.id === 'bi_cond_tax') {
        syncCondValue(form);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initForm);
  } else {
    initForm();
  }
})();

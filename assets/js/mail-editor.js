/**
 * Formatier-Leiste über den Textfeldern der Mail-Benachrichtigungen.
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

  document.addEventListener('change', function (ev) {
    var sel = ev.target;
    if (!sel.classList || !sel.classList.contains('bi-mailedit__ph')) return;
    if (!sel.value) return;

    var box = sel.closest('.bi-mailedit');
    var area = box ? box.querySelector('.bi-mailedit__area') : null;
    if (area) insert(area, sel.value);
    sel.selectedIndex = 0;
  });
})();

/**
 * Termine einer Ausbildungsreihe von der Reihe aus zuordnen.
 *
 * Läuft nur auf der Bearbeiten-Seite eines bi_reihe (siehe
 * BI_Reihen::admin_assets). Der Server liefert nach jeder Änderung das
 * Innenleben der Box frisch zurück – die Seite selbst wird NICHT neu geladen,
 * sonst wären die ungespeicherten Eingaben in den übrigen Feldern der Reihe weg.
 *
 * Gespeichert wird am Seminar im Feld „Teil | Reihe"; diese Oberfläche schreibt
 * es nur richtig geschrieben hinein (BI_Reihen::ajax_zuordnen).
 */
(function () {
  'use strict';

  var CFG = window.biReiheAdmin || {};
  var box = document.getElementById('bi-reihe-box');
  if (!box || !CFG.ajaxUrl) return;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function el(id) { return document.getElementById(id); }

  /** Kurze Rückmeldung über der Trefferliste – Erfolge verschwinden von selbst. */
  function melde(text, fehler) {
    var ziel = el('bi-reihe-treffer');
    if (!ziel) return;
    ziel.innerHTML = '<div class="notice notice-' + (fehler ? 'error' : 'success') +
      ' inline" style="margin:0"><p>' + esc(text) + '</p></div>';
    if (!fehler) window.setTimeout(function () { if (ziel.firstChild) ziel.innerHTML = ''; }, 4000);
  }

  function post(action, daten) {
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('nonce', CFG.nonce || '');
    body.set('reihe', box.getAttribute('data-reihe') || '0');
    Object.keys(daten || {}).forEach(function (k) { body.set(k, daten[k]); });

    return fetch(CFG.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: body.toString()
    }).then(function (r) { return r.json(); });
  }

  /** Antwort mit frischem Box-Inhalt einsetzen (Ereignisse hängen am Container). */
  function uebernehmen(a) {
    var neu = a && a.data && a.data.html;
    if (neu) box.innerHTML = neu;
    if (a && a.data && a.data.text) melde(a.data.text, !a.success);
  }

  function suchen() {
    var q = el('bi-reihe-q');
    var ziel = el('bi-reihe-treffer');
    if (!q || !ziel) return;

    ziel.innerHTML = '<p class="description">Suche läuft …</p>';
    post('bi_reihe_suche', { q: q.value }).then(function (a) {
      if (!a.success) { melde((a.data && a.data.text) || 'Fehler bei der Suche.', true); return; }
      var t = (a.data && a.data.treffer) || [];
      if (!t.length) { ziel.innerHTML = '<p class="description">Keine Seminare gefunden.</p>'; return; }

      var zeilen = t.map(function (s) {
        // Hängt der Termin schon an einer ANDEREN Reihe, wird das benannt –
        // Zuordnen zöge ihn dort weg.
        var hinweis = '';
        if (s.eigen) {
          hinweis = '<span style="color:#646970">bereits in dieser Reihe</span>';
        } else if (s.reihe) {
          hinweis = '<span style="color:#b32d2e">jetzt in „' + esc(s.reihe) + '"</span>';
        }
        return '<tr><td style="width:110px">' + esc(s.datum || '—') + '</td>' +
          '<td style="width:120px">' + esc(s.nummer || '—') + '</td>' +
          '<td>' + esc(s.titel) + '<br><span class="description">' + esc(s.ort || '') +
          (hinweis ? ' · ' + hinweis : '') + '</span></td>' +
          '<td style="width:110px"><button type="button" class="button bi-reihe-zu" data-termin="' + s.id + '">' +
          (s.eigen ? 'Teil ändern' : 'Zuordnen') + '</button></td></tr>';
      }).join('');

      ziel.innerHTML = '<table class="widefat striped"><tbody>' + zeilen + '</tbody></table>';
    }).catch(function () { melde('Die Suche ist fehlgeschlagen.', true); });
  }

  /* Ein Zuhörer am Container statt einer je Knopf: Der Inhalt wird nach jeder
     Änderung ausgetauscht, einzeln gesetzte Ereignisse wären danach weg. */
  box.addEventListener('click', function (e) {
    var zu = e.target.closest ? e.target.closest('.bi-reihe-zu') : null;
    var los = e.target.closest ? e.target.closest('.bi-reihe-los') : null;

    if (e.target.id === 'bi-reihe-suchen') { e.preventDefault(); suchen(); return; }

    if (zu) {
      e.preventDefault();
      zu.disabled = true;
      post('bi_reihe_zuordnen', {
        termin: zu.getAttribute('data-termin'),
        teil: (el('bi-reihe-teil') || {}).value || 1,
        durchgang: (el('bi-reihe-durchgang') || {}).value || 0
      }).then(uebernehmen).catch(function () { melde('Zuordnen fehlgeschlagen.', true); });
      return;
    }

    if (los) {
      e.preventDefault();
      if (!window.confirm('Zuordnung dieses Termins zur Reihe lösen?')) return;
      los.disabled = true;
      post('bi_reihe_zuordnen', { termin: los.getAttribute('data-termin'), loesen: 1 })
        .then(uebernehmen).catch(function () { melde('Lösen fehlgeschlagen.', true); });
    }
  });

  /* Enter im Suchfeld sucht, statt die Reihe zu speichern. */
  box.addEventListener('keydown', function (e) {
    if ('Enter' === e.key && e.target.id === 'bi-reihe-q') {
      e.preventDefault();
      suchen();
    }
  });
})();

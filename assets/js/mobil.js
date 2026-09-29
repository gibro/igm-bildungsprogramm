/* ============================================================
   BI Seminarsuche – Mobile Darstellung der Detailseiten

   Gegenstück zum Abschnitt „Mobil (bis 640px)" in detailseiten.css.
   Das Markup ist dasselbe wie am Schreibtisch; jeder Abschnitt trägt
   eine Marke (data-bi-mobil="ueberblick|termine|kosten|kontakt|…"),
   die Tableiste steht schon im Markup. Hier passiert nur das Schalten:

     1. Tabs.  Klick auf einen Tab macht die Abschnitte mit seiner Marke
        sichtbar (Klasse is-aktiv), alle anderen verschwinden. Das CSS
        blendet nur aus, wenn die Seite die Klasse igm-seite--tabs trägt –
        und die setzt dieses Skript erst, wenn der Rahmen schmaler als
        640px ist. Ohne Skript bleibt alles sichtbar, untereinander.

     2. Sprünge.  Ein Element mit data-bi-tab-ziel („Inhalte der Reihe
        ansehen", „Reihe buchen") schaltet auf den genannten Tab um.

     3. Klappe.  Die Geschäftsstellen-Variante hat keinen Link, sondern
        die PLZ-Suche. Der Knopf der Aktionsleiste (data-bi-panel) klappt
        sie über der Leiste auf und wieder zu.

   Warum keine Bildlaufposition gemerkt wird: Beim Tabwechsel soll der
   Inhalt oben beginnen (Entwurf). Liegt die Tableiste bereits über dem
   Bildrand, wird zu ihr gerollt – auch im Rahmen einer Fremdseite, denn
   scrollIntoView rollt die einbettende Seite mit.
   ============================================================ */

(function () {
  'use strict';

  var MQ = '(max-width: 640px)';

  function liste(wurzel, selektor) {
    return Array.prototype.slice.call(wurzel.querySelectorAll(selektor));
  }

  function init() {
    var seite = document.querySelector('.igm-seite--seminar, .igm-seite--reihe');
    if (!seite) return;

    var tabs = seite.querySelector('.igm-mobil-tabs');
    // Ohne Tableiste (nur ein Abschnitt) gibt es nichts zu schalten – dann
    // bleibt auch die Klasse weg, und alles steht untereinander.
    if (!tabs) return;

    var knoepfe    = liste(tabs, '[data-bi-tab]');
    var schluessel = knoepfe.map(function (b) { return b.getAttribute('data-bi-tab'); });
    var abschnitte = liste(seite, '[data-bi-mobil]');

    /* ---- 1) Tabs ------------------------------------------ */

    function zeigen(key, rollen) {
      if (schluessel.indexOf(key) === -1) return;

      knoepfe.forEach(function (b) {
        var ist = b.getAttribute('data-bi-tab') === key;
        b.classList.toggle('is-aktiv', ist);
        b.setAttribute('aria-selected', ist ? 'true' : 'false');
      });
      abschnitte.forEach(function (el) {
        var marke = el.getAttribute('data-bi-mobil');
        if (marke === 'buchen') return;   // die Klappe hat ihren eigenen Schalter
        el.classList.toggle('is-aktiv', marke === key);
      });

      if (rollen && tabs.getBoundingClientRect().top < 0) {
        try { tabs.scrollIntoView({ block: 'start' }); } catch (e) { tabs.scrollIntoView(true); }
      }
    }

    knoepfe.forEach(function (b) {
      b.addEventListener('click', function () {
        zeigen(b.getAttribute('data-bi-tab'), true);
      });
    });

    /* ---- 2) Sprünge aus dem Inhalt ------------------------ */

    seite.addEventListener('click', function (ev) {
      var ziel = ev.target && ev.target.closest ? ev.target.closest('[data-bi-tab-ziel]') : null;
      if (!ziel) return;
      var key = ziel.getAttribute('data-bi-tab-ziel');
      if (schluessel.indexOf(key) === -1) return;
      ev.preventDefault();
      zeigen(key, true);
    });

    /* ---- 3) Klappe (Geschäftsstellen-Variante) ------------ */

    var schalter = seite.querySelector('[data-bi-panel]');
    var klappe   = schalter ? document.getElementById(schalter.getAttribute('data-bi-panel') || '') : null;
    if (schalter && klappe) {
      schalter.addEventListener('click', function () {
        var offen = !klappe.classList.contains('is-aktiv');
        klappe.classList.toggle('is-aktiv', offen);
        schalter.setAttribute('aria-expanded', offen ? 'true' : 'false');
        if (!offen) return;
        try { klappe.scrollIntoView({ block: 'nearest' }); } catch (e) { /* alter Browser */ }
        var feld = klappe.querySelector('input, button, a');
        if (feld) {
          try { feld.focus({ preventScroll: true }); } catch (e) { feld.focus(); }
        }
      });
    }

    /* ---- Start --------------------------------------------- */

    // Ein Anker „#tab-termine" öffnet den Tab gleich; sonst der erste.
    var start = (window.location.hash || '').replace(/^#tab-/, '');
    zeigen(schluessel.indexOf(start) >= 0 ? start : schluessel[0], false);

    // Wirksam nur bis 640px. Darüber fehlt die Klasse, und die Marken sind
    // ohne Wirkung – dieselbe Seite, nur zweispaltig.
    var mq = window.matchMedia ? window.matchMedia(MQ) : null;
    function anwenden() {
      seite.classList.toggle('igm-seite--tabs', !mq || mq.matches);
    }
    if (mq) {
      if (mq.addEventListener) mq.addEventListener('change', anwenden);
      else if (mq.addListener) mq.addListener(anwenden);
    }
    anwenden();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

/* ============================================================
   BI Seminarsuche – „Zurück zur Suche"

   Der Link im Kopf der Detailseite zeigt serverseitig auf die
   Übersichtsseite ohne Filter. Das stimmt immer, ist aber selten das,
   was jemand sucht: Wer über eine gefilterte Trefferliste gekommen ist,
   will genau dorthin zurück.

   filterleiste.js merkt sich auf der Ergebnisseite Pfad und Filter unter
   sessionStorage['biSuche']. Hier wird der Link darauf umgebogen – nur
   im selben Tab, nur auf eigene Adressen.

   Warum nicht document.referrer: Der fehlt bei strengen Referrer-Regeln,
   und beim Sprung von Termin zu Termin („Weitere Termine") zeigt er auf
   die vorige Detailseite statt auf die Liste.
   ============================================================ */

(function () {
  'use strict';

  var KEY = 'biSuche';

  /**
   * Nur eigene, absolute Pfade zulassen. „//example.org" wäre ein fremder
   * Host, obwohl es mit einem Schrägstrich beginnt – deshalb die zweite
   * Prüfung.
   */
  function istEigenerPfad(wert) {
    return typeof wert === 'string'
      && wert.charAt(0) === '/'
      && wert.charAt(1) !== '/'
      && wert.charAt(1) !== '\\';
  }

  function init() {
    var link = document.querySelector('a[data-bi-zurueck]');
    if (!link) return;

    var ziel;
    try { ziel = sessionStorage.getItem(KEY); } catch (e) { return; }
    if (!istEigenerPfad(ziel)) return;

    // Auf der Liste selbst wäre der Link ein Sprung auf der Stelle.
    if (ziel === window.location.pathname + window.location.search) return;

    link.setAttribute('href', ziel);
    if (ziel.indexOf('?') > 0) {
      var span = link.querySelector('span');
      if (span) span.textContent = 'Zurück zu den Treffern';
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

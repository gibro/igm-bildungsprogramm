/* ============================================================
   BI Seminarsuche – „Link kopieren" auf der Seminarseite

   Der Knopf ist ein echter Link (Ziel: die dauerhafte Adresse auf
   bildung.igmetall.de). Ohne JavaScript öffnet er sie im ganzen
   Fenster; mit JavaScript landet sie stattdessen in der Zwischenablage.

   Warum drei Wege: Im Rahmen (iframe) einer fremden Website ist die
   Zwischenablage gesperrt, solange der Rahmen sie nicht ausdrücklich
   freigibt (allow="clipboard-write"). Dann bleibt execCommand('copy'),
   das die meisten Browser bei einem Klick noch zulassen – und wenn auch
   das scheitert, steht der Link markiert im Feld zum Selbst-Kopieren.
   ============================================================ */

(function () {
  'use strict';

  function altweg(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;';
    document.body.appendChild(ta);
    ta.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(ta);
    return ok;
  }

  function kopieren(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text).then(
        function () { return true; },
        function () { return altweg(text); }
      );
    }
    return Promise.resolve(altweg(text));
  }

  document.addEventListener('click', function (ev) {
    var knopf = ev.target && ev.target.closest ? ev.target.closest('[data-bi-link-kopieren]') : null;
    if (!knopf) return;
    ev.preventDefault();

    var box = knopf.closest('.igm-box--link');
    var feld = box ? box.querySelector('.igm-link__feld') : null;
    var status = box ? box.querySelector('.igm-link__status') : null;
    var url = knopf.getAttribute('href');

    kopieren(url).then(function (ok) {
      if (ok) {
        if (status) status.textContent = 'Link kopiert.';
        knopf.classList.add('is-kopiert');
        setTimeout(function () {
          knopf.classList.remove('is-kopiert');
          if (status) status.textContent = '';
        }, 2500);
        return;
      }
      // Letzter Weg: Link zeigen und markieren.
      if (feld) {
        feld.hidden = false;
        feld.focus();
        feld.select();
      }
      if (status) status.textContent = 'Bitte den markierten Link kopieren (Strg+C bzw. ⌘+C).';
    });
  });
})();

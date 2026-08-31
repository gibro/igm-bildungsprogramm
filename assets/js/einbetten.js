/* ============================================================
   BI Seminarsuche – Einbetter für fremde Websites

   Gegenstück zu embed.js. Dieses Skript läuft NICHT im Rahmen,
   sondern auf der einbettenden Website. Es baut den <iframe> an
   der Stelle, an der es selbst steht, und hört auf die
   Höhenmeldungen aus dem Rahmen.

   Einbindung dort – eine Zeile, kein weiterer Code:

     <script src="https://bildung.igmetall.de/wp-content/plugins/igm-bildungsprogramm/assets/js/einbetten.js"
             data-bi-seite="/seminare/"></script>

   Alle Angaben sind freiwillig:

     data-bi-seite   Pfad auf bildung.igmetall.de. Vorgabe: /seminare/
                     Filter dürfen dranhängen: "/seminare/?thema=digitalisierung"
     data-bi-hoehe   Starthöhe in Pixeln, bis die erste Meldung kommt (1200)
     data-bi-max     Obergrenze in Pixeln (20000)
     data-bi-scroll  "aus" = nach einem Klick im Rahmen nicht nach oben rollen
     data-bi-titel   Beschriftung des Rahmens für Screenreader

   Warum ein Skript und nicht nur ein <iframe>: Ein Rahmen wächst
   nicht mit seinem Inhalt. Ohne Höhenmeldung bekäme die
   Trefferliste eine eigene Bildlaufleiste – zwei Bildläufe
   übereinander, und die Fußzeile der Fremdseite klebte direkt
   unter einem abgeschnittenen Seminar.

   Die Herkunft, der geglaubt wird, leitet sich aus der eigenen
   Adresse dieses Skripts ab. Es kann also nur der Server Höhen
   melden, von dem das Skript selbst stammt.
   ============================================================ */

(function () {
  'use strict';

  /* ---- 1) Das eigene <script>-Element finden -------------- */

  // document.currentScript zeigt beim Ausführen auf genau dieses Element –
  // solange das Skript nicht mit async/defer geladen wird. Der Rückfall auf
  // das letzte Skript im Dokument stimmt in dem Fall ebenfalls, weil ein
  // synchron geladenes Skript immer das zuletzt geparste ist.
  var selbst = document.currentScript;
  if (!selbst) {
    var alle = document.getElementsByTagName('script');
    selbst = alle[alle.length - 1];
  }
  if (!selbst || !selbst.src) return;

  // Zweimal eingebunden? Dann nur einmal bauen.
  if (selbst.getAttribute('data-bi-fertig')) return;
  selbst.setAttribute('data-bi-fertig', '1');

  function attr(name, vorgabe) {
    var v = selbst.getAttribute(name);
    return (v === null || v === '') ? vorgabe : v;
  }

  function zahl(name, vorgabe) {
    var n = parseInt(attr(name, ''), 10);
    return isFinite(n) && n > 0 ? n : vorgabe;
  }

  /* ---- 2) Herkunft und Zieladresse ------------------------ */

  // Adressen über ein <a>-Element auflösen statt über URL(): Das versteht
  // auch der Internet Explorer, und relative Angaben werden dabei gleich
  // vollständig gemacht.
  function aufloesen(adresse, basis) {
    var a = document.createElement('a');
    if (basis) {
      // Ein zweiter Durchgang macht aus "/seminare/" die vollständige
      // Adresse auf dem richtigen Host – ohne ihn zeigte sie auf die
      // einbettende Website.
      a.href = basis;
      var wurzel = a.protocol + '//' + a.host;
      a.href = /^https?:/i.test(adresse) ? adresse : wurzel + (adresse.charAt(0) === '/' ? '' : '/') + adresse;
    } else {
      a.href = adresse;
    }
    return a;
  }

  var quelle = aufloesen(selbst.src);
  var HERKUNFT = quelle.protocol + '//' + quelle.host;

  var ziel = aufloesen(attr('data-bi-seite', '/seminare/'), selbst.src);

  // Nur Adressen auf demselben Server, von dem dieses Skript kommt. Eine
  // fremde Adresse in data-bi-seite wäre ein offener Rahmen für beliebige
  // Inhalte auf der Website, die das Skript eingebunden hat.
  if (ziel.host.toLowerCase() !== quelle.host.toLowerCase()) return;

  var adresse = ziel.href;
  if (adresse.indexOf('bi_embed=') === -1) {
    adresse += (adresse.indexOf('?') >= 0 ? '&' : '?') + 'bi_embed=1';
  }

  /* ---- 3) Rahmen bauen ------------------------------------ */

  var START = zahl('data-bi-hoehe', 1200);
  var MAX = zahl('data-bi-max', 20000);
  var MIN = 200;
  var scrollen = attr('data-bi-scroll', '') !== 'aus';

  var rahmen = document.createElement('iframe');
  rahmen.src = adresse;
  rahmen.title = attr('data-bi-titel', 'Seminarsuche der IG Metall');
  rahmen.setAttribute('scrolling', 'no');
  rahmen.setAttribute('frameborder', '0');
  rahmen.setAttribute('allowtransparency', 'true');
  rahmen.style.cssText = 'display:block;width:100%;border:0;margin:0;height:' + START + 'px;';

  // Vor das Skript-Element setzen: So steht der Rahmen genau dort, wo im
  // Seiteninhalt die eine Zeile eingefügt wurde.
  selbst.parentNode.insertBefore(rahmen, selbst);

  /* ---- 4) Auf Meldungen aus dem Rahmen hören -------------- */

  window.addEventListener('message', function (ev) {
    // Beide Prüfungen sind nötig. Die Herkunft schließt fremde Server aus,
    // der Vergleich der Quelle schließt andere Rahmen desselben Servers aus –
    // sonst verstellten sich zwei Einbettungen auf einer Seite gegenseitig.
    if (ev.origin !== HERKUNFT) return;
    if (ev.source !== rahmen.contentWindow) return;

    var d = ev.data;
    if (!d || typeof d !== 'object') return;

    if (d.typ === 'bi-embed:hoehe') {
      var h = parseInt(d.hoehe, 10);
      if (!isFinite(h)) return;
      // Grenzen nach oben und unten: Eine fehlerhafte Meldung soll die
      // Seite weder auf 100.000 Pixel aufziehen noch auf null falten.
      rahmen.style.height = Math.min(Math.max(h, MIN), MAX) + 'px';
      return;
    }

    // Nach einem Klick im Rahmen steht dessen neue Seite oben, die
    // einbettende Website aber noch da, wo sie stand. Nur zurückrollen,
    // wenn der Rahmen tatsächlich nach oben aus dem Bild gelaufen ist –
    // sonst wäre es ein Ruck ohne Anlass.
    if (d.typ === 'bi-embed:oben' && scrollen) {
      if (rahmen.getBoundingClientRect().top < 0) {
        if (rahmen.scrollIntoView) {
          try {
            rahmen.scrollIntoView({ behavior: 'smooth', block: 'start' });
          } catch (e) {
            rahmen.scrollIntoView(true);
          }
        }
      }
    }
  });
})();

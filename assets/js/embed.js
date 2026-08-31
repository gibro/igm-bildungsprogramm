/* ============================================================
   BI Seminarsuche – Einbettungsmodus (iframe)

   Drei Aufgaben, alle nur für den Fall, dass die Seite tatsächlich in
   einem fremden Rahmen steckt:

   1. Höhe melden. Ein iframe wächst nicht mit seinem Inhalt; ohne
      Meldung bekäme die Trefferliste eine eigene Bildlaufleiste im
      Rahmen – zwei Bildläufe übereinander, der Klassiker unter den
      Einbettungsärgernissen.

   2. Nach oben melden. Wer aus der Trefferliste heraus ein Seminar
      öffnet, sieht sonst die Mitte der neuen Seite: Der Rahmen ist
      kürzer geworden, die Fremdseite steht aber noch da, wo sie stand.

   3. Fremde Links aus dem Rahmen führen. Ein Link auf igmetall.de oder
      auf eine Bildungsstätte gehört nicht in den Rahmen – dort stünde
      eine vollständige Website in einem Ausschnitt.

   4. Auf iframe-resizer antworten. Manche Redaktionssysteme bauen ihre
      Einbettung mit dieser verbreiteten Bibliothek – Magnolia
      (cms.igmetall.cloud, sprockhoevel.igmetall.digital) tut es. Deren
      Rahmen fragt nach der Höhe in einem eigenen Format und wartet
      vergeblich, solange nur unser Format gesprochen wird.

   Die Meldungen gehen an window.parent. Die Fremdseite entscheidet
   selbst, ob sie ihnen folgt; sie sollte dabei event.origin prüfen
   (Beispielcode in der Anleitung).
   ============================================================ */

(function () {
  'use strict';

  // Nicht im Rahmen? Dann ist hier nichts zu tun. Der Zugriff auf
  // window.top kann bei fremder Herkunft eine Ausnahme werfen – dann
  // ist die Antwort erst recht „ja, im Rahmen“.
  var imRahmen;
  try { imRahmen = window.self !== window.top; } catch (e) { imRahmen = true; }
  if (!imRahmen) return;

  var CFG = window.biEmbed || {};
  var PARAM = CFG.param || 'bi_embed';
  var EIGEN = (CFG.host || window.location.host).toLowerCase();

  document.documentElement.classList.add('bi-embed-html');

  /* ---- 1) Höhe melden ------------------------------------- */

  var zuletzt = 0;

  /**
   * Gemessen wird der <body>, NICHT das <html>.
   *
   * Der Grund ist eine Eigenheit, die genau das Gegenteil dessen bewirkt,
   * was man erwartet: scrollHeight des Wurzelelements ist mindestens so
   * groß wie das Fenster. Im Rahmen ist „das Fenster“ aber der Rahmen –
   * also genau der Wert, den wir erst berechnen wollen. Der Rahmen könnte
   * damit wachsen, aber nie wieder schrumpfen: Von der langen
   * Trefferliste auf eine kurze Seminarseite bliebe er auf voller Höhe
   * stehen, mit Hunderten Pixeln Leere darunter.
   *
   * Der <body> hat keine Fensterbindung. Seine Höhe ist die seines
   * Inhalts – nach oben wie nach unten.
   */
  function hoehe() {
    var b = document.body;
    if (!b) return 0;

    var h = b.getBoundingClientRect().height;

    // Ränder des <body> liegen außerhalb seines Kastens und würden unten
    // abgeschnitten. embed.css setzt sie auf null, ein Theme kann sie
    // wieder setzen – deshalb hier mitgerechnet statt vorausgesetzt.
    var cs = window.getComputedStyle ? window.getComputedStyle(b) : null;
    if (cs) {
      h += (parseFloat(cs.marginTop) || 0) + (parseFloat(cs.marginBottom) || 0);
    }

    // Notnagel: Sollte der <body> keine eigene Höhe haben (etwa weil alle
    // Kinder schweben), ist ein zu großer Wert besser als ein leerer Rahmen.
    if (h < 1) h = b.scrollHeight || document.documentElement.scrollHeight || 0;

    return Math.ceil(h);
  }

  function melden() {
    var h = hoehe();
    // Unter zwei Pixeln Unterschied lohnt keine Meldung: Ein
    // Unterschied von einem Pixel kann allein aus dem Runden stammen
    // und schaukelte sich sonst mit dem Neu-Layout der Fremdseite auf.
    if (Math.abs(h - zuletzt) < 2) return;
    zuletzt = h;
    try {
      window.parent.postMessage({ typ: 'bi-embed:hoehe', hoehe: h }, '*');
    } catch (e) { /* Fremdseite lehnt Meldungen ab – dann eben nicht */ }

    // Dieselbe Zahl noch einmal in der Sprache des Rahmens, falls er
    // iframe-resizer spricht (siehe 1b). Ohne Handschlag passiert nichts.
    resizerMelden('resize', h);
  }

  var timer = null;
  function meldenSpaeter() {
    clearTimeout(timer);
    timer = setTimeout(melden, 60);
  }

  if (typeof window.ResizeObserver === 'function') {
    // Ebenfalls der <body>: Das <html> ändert seine Größe nicht, es ist an
    // den Rahmen gebunden – ein Beobachter dort meldete nie etwas.
    var beobachter = new window.ResizeObserver(meldenSpaeter);
    if (document.body) {
      beobachter.observe(document.body);
    } else {
      document.addEventListener('DOMContentLoaded', function () { beobachter.observe(document.body); });
    }
  } else {
    // Ohne ResizeObserver: regelmäßig nachsehen. Zwei Meldungen pro
    // Sekunde reichen für aufklappende Filterpanels und Bilder.
    setInterval(melden, 500);
  }

  window.addEventListener('load', melden);
  window.addEventListener('resize', meldenSpaeter);
  document.addEventListener('DOMContentLoaded', melden);
  melden();

  /* ---- 1b) Auf iframe-resizer antworten -------------------- */

  /*
   * Warum das hier steht
   * --------------------
   * Magnolia – das Redaktionssystem hinter cms.igmetall.cloud und
   * sprockhoevel.igmetall.digital – baut jede Einbettung mit iframe-resizer
   * (Stand: v3.6.3). Dessen Rahmen schickt beim Laden eine Nachricht ins
   * Kind und wartet auf Antwort im selben Format. Bleibt sie aus, steht der
   * Rahmen auf den 150 Pixeln, die ein <iframe> ohne Höhenangabe von Haus
   * aus hat, und die Bibliothek schreibt nach fünf Sekunden in die Konsole:
   *
   *     [iFrameSizer][Host page: …] IFrame has not responded within
   *     5 seconds. Check iFrameResizer.contentWindow.js has been loaded
   *     in iFrame.
   *
   * Der übliche Weg wäre, iframeResizer.contentWindow.js mit auszuliefern.
   * Die Antwort ist aber kurz genug, um sie hier zu geben – das erspart eine
   * fremde Datei im Plugin und die Frage, welche ihrer Fassungen zu welcher
   * Fassung auf der Gegenseite passt.
   *
   * Das Format ist in beide Richtungen dasselbe: der Präfix, dann Felder
   * mit Doppelpunkt getrennt. Vom Rahmen kommt zuerst seine eigene Kennung,
   * zurück gehen Kennung, Höhe, Breite und der Anlass:
   *
   *     [iFrameSizer]<kennung>:<hoehe>:<breite>:<anlass>
   *
   * Die Kennung wird nicht geraten, sondern aus der Nachricht des Rahmens
   * übernommen. Damit ist das hier stumm, solange niemand fragt: Auf einer
   * Seite ohne iframe-resizer wird nie eine Kennung gesetzt und nie etwas
   * gesendet.
   */

  var RESIZER = '[iFrameSizer]';
  var resizerKennung = null;

  function resizerMelden(anlass, h) {
    if (!resizerKennung) return;
    if (typeof h !== 'number') h = hoehe();

    var b = document.body;
    var breite = b ? Math.ceil(b.getBoundingClientRect().width) : 0;

    try {
      window.parent.postMessage(RESIZER + resizerKennung + ':' + h + ':' + breite + ':' + anlass, '*');
    } catch (e) { /* siehe melden() */ }
  }

  window.addEventListener('message', function (ev) {
    var d = ev.data;
    if (typeof d !== 'string' || d.slice(0, RESIZER.length) !== RESIZER) return;

    var kennung = d.slice(RESIZER.length).split(':')[0];
    if (!kennung) return;

    // Erst beim ersten Mal – und nach einer Navigation im Rahmen erneut,
    // weil der Rahmen seine Nachricht bei jedem load wiederholt und das
    // neue Dokument die Kennung noch nicht kennt.
    var ersteMal = (resizerKennung !== kennung);
    resizerKennung = kennung;

    // „init“ ist der Anlass, auf den der Rahmen wartet: Er beendet damit
    // seine Wartezeit. Jede spätere Meldung läuft über melden().
    if (ersteMal) resizerMelden('init');
  });

  /* ---- 2) Nach oben melden -------------------------------- */

  // Beim ersten Aufschlag NICHT scrollen: Die Fremdseite steht dann noch
  // am Anfang, und ein Sprung dorthin wäre ein Ruck ohne Anlass. Erst
  // eine Navigation im Rahmen ist ein Grund. Erkennbar an der History-
  // Länge – ein frisch geladener Rahmen hat genau einen Eintrag.
  window.addEventListener('load', function () {
    if (window.history.length <= 1) return;
    try {
      window.parent.postMessage({ typ: 'bi-embed:oben' }, '*');
    } catch (e) { /* siehe oben */ }
  });

  /* ---- 3) Fremde Links aus dem Rahmen führen -------------- */

  function istEigen(a) {
    if (!a.host) return true;                       // relativer Link
    return a.host.toLowerCase() === EIGEN;
  }

  document.addEventListener('click', function (ev) {
    var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
    if (!a || a.target) return;                     // eigenes target respektieren

    var href = a.getAttribute('href') || '';
    if (href.charAt(0) === '#') return;             // Sprungmarke
    if (!/^https?:/i.test(a.protocol)) return;      // mailto:, tel:

    if (istEigen(a)) {
      // Fangnetz für Adressen, die kein PHP-Filter erzeugt hat (fest
      // verdrahtete Links in Seiteninhalten). Sec-Fetch-Dest fängt das
      // ebenfalls ab – aber nur in neueren Browsern.
      if (a.search.indexOf(PARAM + '=') === -1) {
        a.search = a.search ? a.search + '&' + PARAM + '=1' : '?' + PARAM + '=1';
      }
      return;
    }

    // Fremde Adresse: im neuen Tab öffnen statt im Ausschnitt.
    a.target = '_blank';
    a.rel = (a.rel ? a.rel + ' ' : '') + 'noopener noreferrer';
  }, true);

  /* ---- Formulare (Anmeldung) ------------------------------ */

  // Das Anmeldeformular schickt sein Ziel im versteckten Feld „redirect“
  // mit, das serverseitig die vollständige Adresse samt Parameter trägt.
  // Die action-Adresse selbst kann trotzdem ohne Parameter dastehen,
  // wenn sie fest im Inhalt steht – hier nachgezogen.
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (!f || f.tagName !== 'FORM') return;

    var ziel = f.getAttribute('action');
    if (!ziel) return;                               // leer = gleiche Adresse

    var a = document.createElement('a');
    a.href = ziel;
    if (!istEigen(a) || !/^https?:/i.test(a.protocol)) return;
    if (a.search.indexOf(PARAM + '=') !== -1) return;

    a.search = a.search ? a.search + '&' + PARAM + '=1' : '?' + PARAM + '=1';
    f.setAttribute('action', a.href);
  }, true);
})();

/* ============================================================
   BI Seminarsuche – Marketing-Builder (Backend)
   Sammelt die Formularfelder, holt per AJAX Vorschau + fertigen
   Shortcode und bindet Mediathek-Auswahl sowie Kopieren-Button.

   Zwei Achsen bestimmen, was die Maske zeigt:
     ZIEL         (kachelziel)  filter | reihe | reihen  -> data-ziel="…"
     DARSTELLUNG  (darstellung) kachel | liste           -> data-darst="…"
   Ein Element mit der Klasse .bi-kb-only trägt eine der beiden Angaben
   oder beide und verschwindet, sobald eine davon nicht mehr passt.
   ============================================================ */
(function () {
	'use strict';

	var form = document.getElementById('bi-kb-form');
	var cfg  = window.biKachelBuilder || {};
	if (!form || !cfg.ajaxUrl) {
		return;
	}

	var previewInner = document.getElementById('bi-kb-preview-inner');
	var shortcodeBox = document.getElementById('bi-kb-shortcode');
	var timer = null;
	var pending = null; // laufender Request wird von neuerem überholt

	function ziel() {
		var el = form.querySelector('[name=kachelziel]:checked');
		return el ? el.value : 'filter';
	}

	/* Die Liste gibt es nur für die Filterauswahl. Steht das Ziel auf einer
	   Ausbildungsreihe, gilt „Kachel" – auch wenn der Radioknopf daneben noch
	   auf „Liste" steht, weil er gerade ausgeblendet ist. Sonst schickte die
	   Maske eine Darstellung mit, die sie gar nicht mehr anbietet. */
	function darstellung() {
		if (ziel() !== 'filter') {
			return 'kachel';
		}
		var el = form.querySelector('[name=darstellung]:checked');
		return (el && el.value === 'liste') ? 'liste' : 'kachel';
	}

	function collect() {
		var data = {};
		var layoutEl = form.querySelector('[name=layout]:checked');
		data.layout = layoutEl ? layoutEl.value : '1';
		data.kachelziel = ziel();
		data.darstellung = darstellung();

		['ueberschrift', 'bild', 'ratio', 'fokus', 'titel', 'text', 'button', 'q', 'nr', 'von', 'bis', 'suche_url', 'reihe', 'spalten', 'anzahl'].forEach(function (k) {
			var el = form.querySelector('[name="' + k + '"]');
			data[k] = el ? el.value : '';
		});

		/* Abgewählter Button = leere Beschriftung. Die eingetippte Beschriftung
		   bleibt im Feld stehen – wer den Haken wieder setzt, hat sie zurück,
		   statt sie neu tippen zu müssen. */
		var btnAn = form.querySelector('[name=button_an]');
		if (btnAn && !btnAn.checked) {
			data.button = '';
		}

		/* Kennzahlen und Beschreibungstext: das Häkchen wird zu ja/nein, damit der
		   Shortcode dasselbe spricht wie der Attributwert. */
		var metaEl = form.querySelector('[name=meta]');
		data.meta = (metaEl && metaEl.checked) ? 'ja' : 'nein';
		var textEl = form.querySelector('[name=beschreibung]');
		data.beschreibung = (textEl && textEl.checked) ? 'ja' : 'nein';

		/* Reihen-Übersicht: „alle" heißt leeres Attribut – dann entscheidet der
		   Shortcode selbst, und eine später angelegte Reihe ist von allein dabei. */
		var modusEl = form.querySelector('[name=reihen_modus]:checked');
		if (modusEl && modusEl.value === 'auswahl') {
			var reihen = [];
			form.querySelectorAll('input[name="reihen[]"]:checked').forEach(function (cb) {
				reihen.push(cb.value);
			});
			data.reihen = reihen.join('|');
		} else {
			data.reihen = '';
		}

		/* Seminarform gehört dazu: Sie steht als Ankreuzfeld in derselben
		   Filterspalte und wirkte bis dahin nicht, weil sie hier fehlte. */
		['form', 'ort', 'thema', 'ziel', 'frei'].forEach(function (k) {
			var vals = [];
			form.querySelectorAll('input[name="' + k + '[]"]:checked').forEach(function (cb) {
				vals.push(cb.value);
			});
			data[k] = vals.join('|');
		});

		/* Mehrfachauswahl-Listen: dieselbe Pipe-Schreibweise wie bei den
		   Ankreuzfeldern, damit PHP nur eine Form kennen muss. */
		['programm'].forEach(function (k) {
			var vals = [];
			form.querySelectorAll('select[name="' + k + '[]"] option:checked').forEach(function (o) {
				if (o.value) vals.push(o.value);
			});
			data[k] = vals.join('|');
		});

		return data;
	}

	function refresh() {
		var data = collect();
		var body = new URLSearchParams();
		body.set('action', 'bi_kachel_preview');
		body.set('_wpnonce', cfg.nonce);
		Object.keys(data).forEach(function (k) {
			body.set(k, data[k]);
		});

		var req = fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		});
		pending = req;

		req.then(function (r) { return r.json(); }).then(function (res) {
			if (req !== pending || !res || !res.success) {
				return; // veraltete Antwort verwerfen
			}
			previewInner.innerHTML = res.data.html;
			shortcodeBox.value = res.data.shortcode;
			// Klicks in der Vorschau nicht auf die echte Suchseite durchlassen
			previewInner.querySelectorAll('a').forEach(function (a) {
				a.addEventListener('click', function (e) { e.preventDefault(); });
			});
		}).catch(function () { /* z. B. abgebrochen – nächster Refresh kommt */ });
	}

	function schedule() {
		clearTimeout(timer);
		timer = setTimeout(refresh, 350);
	}

	/* ---------- Ziel der Kachel: zeigt nur, was zu diesem Ziel gehört ---------- */

	var BTN_DEFAULTS = { filter: 'Zu den Seminaren', reihe: 'Zur Ausbildungsreihe', reihen: 'Zur Ausbildungsreihe', liste: 'Alle %d Seminare anzeigen' };
	var buttonInput = form.querySelector('[name=button]');
	/* Zuletzt gewählte Vorschau-Breite der Kachel – sie soll den Ausflug in die
	   Listenansicht überleben. */
	var kachelBreite = '400';

	function applyZiel() {
		var z = ziel();
		var d = darstellung();

		form.querySelectorAll('.bi-kb-only').forEach(function (el) {
			var fuerZiel  = el.getAttribute('data-ziel');
			var fuerDarst = el.getAttribute('data-darst');
			var passt = (!fuerZiel  || fuerZiel.split(' ').indexOf(z) !== -1) &&
			            (!fuerDarst || fuerDarst.split(' ').indexOf(d) !== -1);
			el.style.display = passt ? '' : 'none';
		});

		/* Die Vorschau-Breite ist eine Frage an die Kachel: Sie steht in einer Box,
		   und wie schmal die sein darf, sieht man nur, indem man sie schmal macht.
		   Eine Liste läuft immer über die volle Breite ihrer Box – die Knöpfe
		   hätten dort nichts zu entscheiden. */
		var widths     = document.querySelector('.bi-kb-widths');
		var previewBox = document.getElementById('bi-kb-preview');
		if (widths) { widths.style.display = (d === 'liste') ? 'none' : ''; }
		if (previewBox) { previewBox.style.maxWidth = (d === 'liste') ? '100%' : (kachelBreite + 'px'); }

		/* Auswahlliste der Reihen nur bei „nur ausgewählte" */
		var modusEl = form.querySelector('[name=reihen_modus]:checked');
		var liste   = document.getElementById('bi-kb-reihen-liste');
		if (liste) {
			liste.style.display = (modusEl && modusEl.value === 'auswahl') ? '' : 'none';
		}

		/* Die Button-Beschriftung mitziehen, solange sie die Vorgabe eines
		   anderen Ziels bzw. der anderen Darstellung ist. Eine eigene
		   Beschriftung bleibt unangetastet. */
		if (buttonInput) {
			var ist = buttonInput.value.trim();
			var fremd = Object.keys(BTN_DEFAULTS).some(function (k) { return BTN_DEFAULTS[k] === ist; });
			if (ist === '' || fremd) {
				buttonInput.value = (d === 'liste') ? BTN_DEFAULTS.liste : BTN_DEFAULTS[z];
			}
		}
	}

	/* ---------- Button an/aus: ohne Button gibt es nichts zu beschriften ---------- */

	function applyButton() {
		var btnAn   = form.querySelector('[name=button_an]');
		var btnFeld = document.getElementById('bi-kb-button-feld');
		if (btnFeld) {
			btnFeld.style.display = (btnAn && !btnAn.checked) ? 'none' : '';
		}
	}

	form.addEventListener('change', function (e) {
		if (e.target && e.target.name && /^(kachelziel|darstellung|reihen_modus)$/.test(e.target.name)) {
			applyZiel();
		}
		if (e.target && e.target.name === 'button_an') {
			applyButton();
		}
	});

	form.addEventListener('input', schedule);
	form.addEventListener('change', schedule);
	applyZiel();
	applyButton();

	/* ---------- Bild aus der Mediathek wählen + Fokuspunkt ---------- */

	var bildInput    = form.querySelector('[name=bild]');
	var fokusInput   = form.querySelector('[name=fokus]');
	var bildBox      = document.getElementById('bi-kb-bild-box');
	var bildThumb    = document.getElementById('bi-kb-bild-thumb');
	var fokusMarker  = document.getElementById('bi-kb-fokus-marker');
	var fokusHint    = document.getElementById('bi-kb-fokus-hint');
	var fokusReset   = document.getElementById('bi-kb-fokus-reset');
	var bildWaehlen  = document.getElementById('bi-kb-bild-waehlen');
	var bildLoeschen = document.getElementById('bi-kb-bild-entfernen');
	var mediaFrame = null;

	function clearFokus() {
		fokusInput.value = '';
		fokusMarker.style.display = 'none';
	}

	function setFokus(x, y) {
		fokusInput.value = x + '% ' + y + '%';
		fokusMarker.style.left = x + '%';
		fokusMarker.style.top = y + '%';
		fokusMarker.style.display = 'block';
	}

	if (bildWaehlen) {
		bildWaehlen.addEventListener('click', function (e) {
			e.preventDefault();
			if (!window.wp || !wp.media) {
				return;
			}
			if (!mediaFrame) {
				mediaFrame = wp.media({
					title: 'Kachel-Bild wählen',
					button: { text: 'Bild übernehmen' },
					multiple: false,
					library: { type: 'image' }
				});
				mediaFrame.on('select', function () {
					var att = mediaFrame.state().get('selection').first().toJSON();
					bildInput.value = att.id;
					var thumb = (att.sizes && (att.sizes.medium || att.sizes.thumbnail)) ? (att.sizes.medium || att.sizes.thumbnail).url : att.url;
					bildThumb.src = thumb;
					bildBox.style.display = 'inline-block';
					fokusHint.style.display = '';
					bildLoeschen.style.display = '';
					clearFokus(); // neues Bild = Fokus neu setzen
					schedule();
				});
			}
			mediaFrame.open();
		});
	}

	if (bildLoeschen) {
		bildLoeschen.addEventListener('click', function (e) {
			e.preventDefault();
			bildInput.value = '';
			bildThumb.src = '';
			bildBox.style.display = 'none';
			fokusHint.style.display = 'none';
			bildLoeschen.style.display = 'none';
			clearFokus();
			schedule();
		});
	}

	if (bildBox) {
		bildBox.addEventListener('click', function (e) {
			var rect = bildThumb.getBoundingClientRect();
			if (!rect.width || !rect.height) {
				return;
			}
			var x = Math.round(Math.min(100, Math.max(0, (e.clientX - rect.left) / rect.width * 100)));
			var y = Math.round(Math.min(100, Math.max(0, (e.clientY - rect.top) / rect.height * 100)));
			setFokus(x, y);
			schedule();
		});
	}

	if (fokusReset) {
		fokusReset.addEventListener('click', function (e) {
			e.preventDefault();
			clearFokus();
			schedule();
		});
	}

	/* ---------- Vorschau-Breite umschalten ---------- */

	var preview = document.getElementById('bi-kb-preview');
	document.querySelectorAll('.bi-kb-widths button[data-width]').forEach(function (btn) {
		btn.addEventListener('click', function () {
			kachelBreite = btn.getAttribute('data-width');
			preview.style.maxWidth = kachelBreite + 'px';
			document.querySelectorAll('.bi-kb-widths button').forEach(function (b) { b.classList.remove('active'); });
			btn.classList.add('active');
		});
	});

	/* ---------- Shortcode kopieren ---------- */

	var copyBtn = document.getElementById('bi-kb-copy');
	var copied  = document.getElementById('bi-kb-copied');

	if (copyBtn) {
		copyBtn.addEventListener('click', function () {
			var text = shortcodeBox.value;
			var done = function () {
				copied.style.display = '';
				setTimeout(function () { copied.style.display = 'none'; }, 2000);
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done);
			} else {
				shortcodeBox.select();
				document.execCommand('copy');
				done();
			}
		});
	}

	/* ---------- Kachel speichern ---------- */

	var saveForm  = document.getElementById('bi-kb-save-form');
	var nameInput = document.getElementById('bi-kb-kachelname');

	/* Der Builder schickt sich selbst nie ab – er lebt von der Vorschau. Zum
	   Speichern werden dieselben Felder, die auch die Vorschau bekommt, in ein
	   verstecktes Formular gelegt und mit ihm abgeschickt. Ergebnis: eine echte
	   Weiterleitung mit Meldung, kein stiller Hintergrundaufruf. */
	function speichern(alsNeu) {
		if (!saveForm || !nameInput) {
			return;
		}
		var name = (nameInput.value || '').trim();
		if (!name) {
			nameInput.focus();
			return;
		}
		// Reste eines vorherigen Klicks entfernen
		Array.prototype.slice.call(saveForm.querySelectorAll('.bi-kb-tmp')).forEach(function (el) {
			el.parentNode.removeChild(el);
		});
		var data = collect();
		Object.keys(data).forEach(function (k) {
			var i = document.createElement('input');
			i.type = 'hidden';
			i.name = k;
			i.value = data[k];
			i.className = 'bi-kb-tmp';
			saveForm.appendChild(i);
		});
		saveForm.querySelector('[name=kachel_name]').value = name;
		if (alsNeu) {
			saveForm.querySelector('[name=kachel_key]').value = '';
		}
		saveForm.submit();
	}

	var saveBtn = document.getElementById('bi-kb-speichern');
	if (saveBtn) {
		saveBtn.addEventListener('click', function () { speichern(false); });
	}
	var saveNewBtn = document.getElementById('bi-kb-speichern-neu');
	if (saveNewBtn) {
		saveNewBtn.addEventListener('click', function () { speichern(true); });
	}

	/* ---------- Gespeicherte Kachel in die Maske laden ---------- */

	function vorbelegen(v) {
		if (!v) {
			return;
		}
		var atts = v.atts || {};

		var zielEl = form.querySelector('[name=kachelziel][value="' + (v.ziel || 'filter') + '"]');
		if (zielEl) { zielEl.checked = true; }

		var darstEl = form.querySelector('[name=darstellung][value="' + (v.darstellung || 'kachel') + '"]');
		if (darstEl) { darstEl.checked = true; }

		var layoutEl = form.querySelector('[name=layout][value="' + (atts.layout || '1') + '"]');
		if (layoutEl) { layoutEl.checked = true; }

		['ueberschrift', 'bild', 'ratio', 'fokus', 'titel', 'text', 'button', 'q', 'nr', 'von', 'bis', 'suche_url', 'reihe', 'spalten', 'anzahl'].forEach(function (k) {
			var el = form.querySelector('[name="' + k + '"]');
			if (el && typeof atts[k] === 'string') { el.value = atts[k]; }
		});

		['form', 'ort', 'thema', 'ziel', 'frei'].forEach(function (k) {
			var vals = (atts[k] || '').split('|').filter(Boolean);
			form.querySelectorAll('input[name="' + k + '[]"]').forEach(function (cb) {
				cb.checked = vals.indexOf(cb.value) !== -1;
			});
		});

		var progVals = (atts.programm || '').split('|').filter(Boolean);
		form.querySelectorAll('select[name="programm[]"] option').forEach(function (o) {
			o.selected = progVals.indexOf(o.value) !== -1;
		});

		/* Leere Reihen-Liste heißt „alle" – das ist die Vorauswahl, es bleibt
		   also nur der umgekehrte Fall zu setzen. */
		var reihenVals = (atts.reihen || '').split('|').filter(Boolean);
		if (reihenVals.length) {
			var modus = form.querySelector('[name=reihen_modus][value=auswahl]');
			if (modus) { modus.checked = true; }
			form.querySelectorAll('input[name="reihen[]"]').forEach(function (cb) {
				cb.checked = reihenVals.indexOf(cb.value) !== -1;
			});
		}

		var metaEl = form.querySelector('[name=meta]');
		if (metaEl) { metaEl.checked = ('nein' !== atts.meta); }
		var textEl = form.querySelector('[name=beschreibung]');
		if (textEl) { textEl.checked = ('nein' !== atts.beschreibung); }
		/* Eine gespeicherte Kachel ohne Button hat button="" – der Haken muss das
		   abbilden, sonst behauptet die Maske einen Button, den die Kachel nicht
		   hat. Fehlt das Attribut ganz (alte Kachel), gilt: Button an. */
		var btnAn = form.querySelector('[name=button_an]');
		if (btnAn) {
			btnAn.checked = ('string' !== typeof atts.button) || '' !== atts.button.trim();
		}

		if (v.bildUrl && bildThumb && bildBox) {
			bildThumb.src = v.bildUrl;
			bildBox.style.display = 'inline-block';
			if (fokusHint) { fokusHint.style.display = ''; }
			if (bildLoeschen) { bildLoeschen.style.display = ''; }
			var m = /^(\d{1,3})%\s+(\d{1,3})%$/.exec(atts.fokus || '');
			if (m) { setFokus(parseInt(m[1], 10), parseInt(m[2], 10)); }
		}
	}

	/* ---------- Start: gespeicherte Kachel laden, dann Vorschau ---------- */
	vorbelegen(cfg.vorbelegung);
	applyZiel();
	applyButton();
	refresh();
})();

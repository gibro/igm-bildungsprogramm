/* ============================================================
   BI Seminarsuche – Kachel-Builder (Backend)
   Sammelt die Formularfelder, holt per AJAX Vorschau + fertigen
   Shortcode und bindet Mediathek-Auswahl sowie Kopieren-Button.
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

	function collect() {
		var data = {};
		var layoutEl = form.querySelector('[name=layout]:checked');
		data.layout = layoutEl ? layoutEl.value : '1';

		['ueberschrift', 'bild', 'ratio', 'fokus', 'titel', 'text', 'button', 'q', 'nr', 'von', 'bis', 'programm', 'suche_url'].forEach(function (k) {
			var el = form.querySelector('[name="' + k + '"]');
			data[k] = el ? el.value : '';
		});

		['ort', 'thema', 'ziel', 'frei'].forEach(function (k) {
			var vals = [];
			form.querySelectorAll('input[name="' + k + '[]"]:checked').forEach(function (cb) {
				vals.push(cb.value);
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

	form.addEventListener('input', schedule);
	form.addEventListener('change', schedule);

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
			preview.style.maxWidth = btn.getAttribute('data-width') + 'px';
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

	/* ---------- Start: leere Kachel anzeigen ---------- */
	refresh();
})();

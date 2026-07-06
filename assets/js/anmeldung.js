/* ============================================================
   BI Bildungsprogramm – Anmeldeformular (Wizard-Logik)
   Schrittnavigation, Validierung, Fortschritt. Vanilla JS.
   Versand läuft serverseitig (POST -> Redirect -> Erfolgs-Screen).
   ============================================================ */

(function () {
  'use strict';

  function init(root) {
    var form = root.querySelector('.bi-wiz__form');
    if (!form) return; // Erfolgs-Screen o. Ä. -> keine Logik

    var steps   = Array.prototype.slice.call(form.querySelectorAll('.bi-wiz__step'));
    var items   = Array.prototype.slice.call(form.querySelectorAll('.bi-wiz__step-item'));
    var fill    = form.querySelector('.bi-wiz__progress-fill');
    var curEl   = form.querySelector('.bi-wiz__cur');
    var back    = form.querySelector('.bi-wiz__back');
    var primary = form.querySelector('.bi-wiz__primary');
    var total   = steps.length;
    var step    = 0;

    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }

    // Live-Zusammenfassung der Eingaben (Schritte 1–3) für Schritt 4
    function reviewItems() {
      var out = [];
      steps.forEach(function (sec, idx) {
        if (idx === total - 1) return; // Abschluss-Schritt selbst überspringen
        Array.prototype.forEach.call(sec.querySelectorAll('.bi-wiz__field'), function (fld) {
          var labelEl = fld.querySelector('.bi-wiz__label');
          var label = labelEl ? labelEl.textContent.replace('*', '').trim() : '';
          var value = '';
          var radios = fld.querySelectorAll('input[type="radio"]');
          if (radios.length) {
            Array.prototype.forEach.call(radios, function (r) { if (r.checked) value = (r.parentNode.textContent || '').trim(); });
          } else {
            var ctrl = fld.querySelector('select, input, textarea');
            if (!ctrl) return;
            if (ctrl.tagName === 'SELECT') {
              var opt = ctrl.options[ctrl.selectedIndex];
              value = (opt && opt.value) ? opt.text.trim() : '';
            } else {
              value = (ctrl.value || '').trim();
            }
          }
          if (label && value) out.push({ label: label, value: value });
        });
      });
      return out;
    }

    function renderReview() {
      var box = form.querySelector('[data-review]');
      if (!box) return;
      var items = reviewItems();
      if (!items.length) {
        box.innerHTML = '<p class="bi-wiz__review-empty">Bitte fülle die vorherigen Schritte aus.</p>';
        return;
      }
      box.innerHTML = items.map(function (it) {
        return '<div class="bi-wiz__review-item"><span class="bi-wiz__review-label">' + escapeHtml(it.label) +
          '</span><span class="bi-wiz__review-val">' + escapeHtml(it.value) + '</span></div>';
      }).join('');
    }

    function render() {
      steps.forEach(function (s, i) { s.classList.toggle('is-active', i === step); });
      items.forEach(function (it, i) {
        it.classList.toggle('is-active', i === step);
        it.classList.toggle('is-done', i < step);
        it.classList.toggle('is-todo', i > step);
        var circle = it.querySelector('.bi-wiz__circle');
        if (circle) circle.textContent = (i < step) ? '✓' : String(i + 1);
      });
      if (fill) fill.style.width = Math.round(((step + 1) / total) * 100) + '%';
      if (curEl) curEl.textContent = String(step + 1);
      if (back) back.hidden = (step === 0);
      if (primary) primary.textContent = (step === total - 1) ? 'Verbindlich anmelden' : 'Weiter →';
      renderReview();
    }

    function goTo(n) {
      step = Math.max(0, Math.min(total - 1, n));
      render();
      if (root.getBoundingClientRect().top < 0) {
        root.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }

    function fieldInvalid(el) {
      var req = !!el.getAttribute('data-req');
      if (el.type === 'checkbox') return req && !el.checked;
      var val = (el.value || '').trim();
      if (req && !val) return true;
      if (val) {
        var t = el.getAttribute('data-type');
        if (t === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) return true;
        if (t === 'plz' && !/^\d{5}$/.test(val)) return true;
      }
      return false;
    }

    function mark(el, bad) {
      el.classList.toggle('bi-invalid', bad);
      var consent = el.closest('.bi-wiz__consent');
      if (consent) consent.classList.toggle('bi-invalid', bad);
    }

    function validateStep(i) {
      var ok = true;
      var controls = steps[i].querySelectorAll('[data-req], [data-type]');
      Array.prototype.forEach.call(controls, function (el) {
        var bad = fieldInvalid(el);
        mark(el, bad);
        if (bad) ok = false;
      });
      var msg = steps[i].querySelector('.bi-wiz__msg');
      if (msg) msg.hidden = ok;
      return ok;
    }

    function firstInvalid() {
      for (var i = 0; i < total; i++) {
        if (!validateStep(i)) return i;
      }
      return -1;
    }

    if (back) back.addEventListener('click', function () { goTo(step - 1); });
    items.forEach(function (it) {
      it.addEventListener('click', function () { goTo(parseInt(it.getAttribute('data-goto'), 10) || 0); });
    });

    form.addEventListener('submit', function (e) {
      if (step < total - 1) {
        e.preventDefault();
        if (validateStep(step)) goTo(step + 1);
        return;
      }
      var bad = firstInvalid();
      if (bad !== -1) {
        e.preventDefault();
        goTo(bad);
      }
      // andernfalls: nativer Submit an admin-post.php
    });

    // Tippt der Nutzer in ein markiertes Feld -> Fehler live entfernen + Übersicht aktualisieren
    form.addEventListener('input', function (e) {
      var el = e.target;
      if (el.classList && el.classList.contains('bi-invalid')) mark(el, fieldInvalid(el));
      renderReview();
    });
    form.addEventListener('change', function (e) {
      var el = e.target;
      if (el.classList && el.classList.contains('bi-invalid')) mark(el, fieldInvalid(el));
      renderReview();
    });

    render();
  }

  function boot() {
    Array.prototype.forEach.call(document.querySelectorAll('.bi-wiz'), init);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();

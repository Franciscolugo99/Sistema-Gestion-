// public/assets/js/caja_historial.js
// UI helpers para Historial de Caja (FLUS)
(function () {
  'use strict';

  function q(sel, ctx) { return (ctx || document).querySelector(sel); }
  function qa(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }

  function toggleDetails(btn) {
    var id = btn.getAttribute('data-id');
    if (!id) return;
    var row = q('tr[data-details="' + id + '"]');
    if (!row) return;

    var expanded = btn.getAttribute('aria-expanded') === 'true';
    btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
    btn.textContent = expanded ? '▾' : '▴';
    row.hidden = expanded;
  }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.js-toggle-details');
    if (!btn) return;
    ev.preventDefault();
    toggleDetails(btn);
  });

  // Accesibilidad: enter/space también activa en botones con foco
  document.addEventListener('keydown', function (ev) {
    var active = document.activeElement;
    if (!active) return;
    if (!active.classList || !active.classList.contains('js-toggle-details')) return;
    if (ev.key === 'Enter' || ev.key === ' ') {
      ev.preventDefault();
      toggleDetails(active);
    }
  });
})();

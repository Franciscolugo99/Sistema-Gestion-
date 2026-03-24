// public/assets/js/facturacion_nc.js
// UX v2 — flujo guiado para emisión de Nota de Crédito
(function () {
  'use strict';

  /* ─── helpers ─────────────────────────────────────────── */
  const $ = (sel, ctx = document) => ctx.querySelector(sel);
  const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

  function formatMoney(n) {
    if (isNaN(n)) return '$0,00';
    return '$' + n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  }

  function roundMoney(n) {
    return Math.round((Number(n) || 0) * 100) / 100;
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    }[ch]));
  }

  /* ─── step tracker ────────────────────────────────────── */
  function setActiveStep(n) {
    $$('.nc-step-item').forEach((el, i) => {
      el.classList.toggle('is-active', i + 1 === n);
      el.classList.toggle('is-done',   i + 1 < n);
    });
  }

  /* ─── type selector ───────────────────────────────────── */
  function initTypeSelector() {
    const radios   = $$('[name="nc_type"]');
    const formTotal   = $('#nc-form-total');
    const formParcial = $('#nc-form-parcial');
    const step3       = $('#nc-step3');
    const typeCards   = $$('.nc-type-card');

    if (!radios.length) return;

    function applyType(val) {
      typeCards.forEach(card => card.classList.toggle('is-selected', card.dataset.value === val));
      if (formTotal)   formTotal.hidden   = val !== 'TOTAL';
      if (formParcial) formParcial.hidden = val !== 'PARTIAL';
      if (step3)       step3.hidden       = false;
      setActiveStep(3);
      // scroll step3 into view
      step3?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    radios.forEach(r => r.addEventListener('change', () => applyType(r.value)));

    // Clickable cards
    typeCards.forEach(card => {
      card.addEventListener('click', () => {
        const val = card.dataset.value;
        const radio = $(`[name="nc_type"][value="${val}"]`);
        if (radio) { radio.checked = true; applyType(val); }
      });
    });

    // If a radio is already checked (back button / refresh), apply it
    radios.forEach(r => { if (r.checked) applyType(r.value); });
  }

  /* ─── live totals for parcial ─────────────────────────── */
  function initLiveTotals() {
    const liveTotal = $('#nc-live-total');
    const liveCount = $('#nc-live-count');
    const submitBtn = $('#nc-submit-parcial');

    function recalc() {
      let total = 0;
      let count = 0;

      $$('.nc-line-row').forEach(row => {
        const input      = $('.nc-qty-input', row);
        const unitPrice  = parseFloat(row.dataset.unitPrice ?? 0);
        const maxQty     = Math.max(0, parseFloat(row.dataset.maxQty ?? input?.max ?? 0) || 0);
        const fullAmount = roundMoney(parseFloat(row.dataset.fullAmount ?? 0) || 0);
        let qty          = Math.max(0, parseFloat(input?.value ?? 0) || 0);

        if (qty > maxQty) qty = maxQty;
        if (input && String(qty) !== String(parseFloat(input.value || '0') || 0)) {
          input.value = qty > 0 ? String(qty) : '0';
        }

        const isFullLine = maxQty > 0 && Math.abs(qty - maxQty) <= 0.0009;
        const lineTotal  = qty > 0 ? (isFullLine ? fullAmount : roundMoney(qty * unitPrice)) : 0;

        total = roundMoney(total + lineTotal);
        if (qty > 0) count++;

        const preview = $('.nc-line-amount-preview', row);
        if (preview) {
          preview.textContent = qty > 0 ? formatMoney(lineTotal) : '—';
          preview.classList.toggle('has-value', qty > 0);
        }

        row.classList.toggle('is-editing', qty > 0);
      });

      if (liveTotal) liveTotal.textContent = formatMoney(total);
      if (liveCount) liveCount.textContent = count + (count === 1 ? ' línea' : ' líneas');
      if (submitBtn) submitBtn.disabled = total < 0.009;

      const bar = $('#nc-live-bar');
      if (bar) bar.classList.toggle('is-visible', total >= 0.009);
    }

    $$('.nc-qty-input').forEach(input => {
      input.addEventListener('input', recalc);
      input.addEventListener('change', recalc);
    });

    // Select all
    $('#nc-select-all')?.addEventListener('click', () => {
      $$('.nc-qty-input').forEach(input => { input.value = input.max; });
      recalc();
    });

    // Clear all
    $('#nc-clear-all')?.addEventListener('click', () => {
      $$('.nc-qty-input').forEach(input => { input.value = 0; });
      recalc();
    });

    recalc();
  }

  /* ─── SweetAlert2 confirmations ───────────────────────── */
  function initConfirmations() {
    const swal = window.Swal;
    if (!swal) return;

    // --- PARCIAL confirm
    const formParcial = $('#nc-form-parcial form');
    const submitParcial = $('#nc-submit-parcial');

    if (formParcial && submitParcial) {
      submitParcial.addEventListener('click', async (e) => {
        e.preventDefault();

        const lines = [];
        $$('.nc-line-row').forEach(row => {
          const input = $('.nc-qty-input', row);
          const qty   = parseFloat(input?.value ?? 0) || 0;
          if (qty > 0) {
            const desc  = row.dataset.desc ?? '—';
            const price = parseFloat(row.dataset.unitPrice ?? 0);
            lines.push(`<li><b>${desc}</b> — ${qty} u. → ${formatMoney(qty * price)}</li>`);
          }
        });

        if (!lines.length) {
          swal.fire({ icon: 'warning', title: 'Sin ítems seleccionados', text: 'Indicá al menos una cantidad mayor a cero.', confirmButtonText: 'Entendido' });
          return;
        }

        const motivo = formParcial.querySelector('[name="motivo"]')?.value.trim() || '(sin motivo)';
        const total  = $('#nc-live-total')?.textContent ?? '';
        const motivoHtml = escapeHtml(motivo);
        const totalHtml  = escapeHtml(total);

        const result = await swal.fire({
          icon: 'question',
          title: 'Confirmar NC parcial',
          html: `
            <p style="margin-bottom:12px;text-align:left;">Se emitirá una <strong>Nota de Crédito parcial</strong> ante ARCA por los siguientes ítems:</p>
            <ul style="text-align:left;margin:0 0 12px;padding-left:18px;">${lines.join('')}</ul>
            <p style="text-align:left;"><strong>Motivo:</strong> ${motivoHtml}</p>
            <p style="font-size:1.1rem;margin-top:14px;font-weight:700;">Total a acreditar: ${totalHtml}</p>
            <p style="color:#dc2626;font-size:.85rem;margin-top:8px;">Esta acción es irreversible una vez confirmada ante ARCA.</p>`,
          showCancelButton: true,
          confirmButtonText: 'Emitir NC parcial',
          cancelButtonText: 'Revisar',
          confirmButtonColor: '#0ea5e9',
        });

        if (result.isConfirmed) formParcial.submit();
      });
    }

    // --- TOTAL confirm
    const formTotal   = $('#nc-form-total form');
    const submitTotal = $('#nc-submit-total');

    if (formTotal && submitTotal) {
      submitTotal.addEventListener('click', async (e) => {
        e.preventDefault();

        const totalAmount = formTotal.querySelector('.nc-total-amount-value')?.textContent ?? '';
        const motivo      = formTotal.querySelector('[name="motivo"]')?.value.trim() || '(sin motivo)';
        const totalAmountHtml = escapeHtml(totalAmount);
        const motivoHtml = escapeHtml(motivo || '(sin motivo)');

        const result = await swal.fire({
          icon: 'warning',
          title: '¿Anular la factura completa?',
          html: `
            <p>Se emitirá una <strong>Nota de Crédito total</strong> ante ARCA anulando el saldo fiscal disponible.</p>
            <p style="font-size:1.1rem;font-weight:700;margin:14px 0;">Monto a acreditar: ${totalAmountHtml}</p>
            <p><strong>Motivo:</strong> ${motivoHtml}</p>
            <p style="color:#dc2626;font-size:.85rem;margin-top:8px;">Esta acción es irreversible una vez confirmada ante ARCA.</p>`,
          showCancelButton: true,
          confirmButtonText: 'Sí, anular todo',
          cancelButtonText: 'Cancelar',
          confirmButtonColor: '#dc2626',
        });

        if (result.isConfirmed) formTotal.submit();
      });
    }
  }

  /* ─── stepper init ────────────────────────────────────── */
  function initStepper() {
    const pageWrap = document.querySelector('[data-nc-has-factura]');
    const hasFact  = pageWrap?.dataset.ncHasFactura === '1';
    if (!hasFact) {
      setActiveStep(1);
    } else {
      setActiveStep(2);
    }
  }

  /* ─── init ────────────────────────────────────────────── */
  document.addEventListener('DOMContentLoaded', () => {
    initStepper();
    initTypeSelector();
    initLiveTotals();
    initConfirmations();
  });
})();

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

  function formatQuantity(n) {
    return Number(n || 0).toLocaleString('es-AR', { maximumFractionDigits: 3 });
  }

  function lineCredit(row, rawQty) {
    const input      = $('.nc-qty-input', row);
    const unitPrice  = parseFloat(row.dataset.unitPrice ?? 0) || 0;
    const maxQty     = Math.max(0, parseFloat(row.dataset.maxQty ?? input?.max ?? 0) || 0);
    const fullAmount = roundMoney(parseFloat(row.dataset.fullAmount ?? 0) || 0);
    const qty        = Math.min(maxQty, Math.max(0, parseFloat(rawQty ?? input?.value ?? 0) || 0));
    const isFullLine = maxQty > 0 && Math.abs(qty - maxQty) <= 0.0009;
    const total      = qty > 0 ? (isFullLine ? fullAmount : roundMoney(qty * unitPrice)) : 0;

    return { qty, total };
  }

  function setLiveBarVisible(visible) {
    const bar = $('#nc-live-bar');
    if (!bar) return;

    bar.hidden = !visible;
    bar.classList.toggle('is-visible', visible);
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

  function notify(type, message) {
    const text = String(message ?? '').trim();
    if (!text) return;

    if (window.Notif) {
      if ((type === 'success' || type === 'ok') && typeof window.Notif.exito === 'function') {
        window.Notif.exito(text);
        return;
      }
      if ((type === 'warning' || type === 'warn') && typeof window.Notif.advertencia === 'function') {
        window.Notif.advertencia(text);
        return;
      }
      if (typeof window.Notif.error === 'function') {
        window.Notif.error(text);
        return;
      }
    }

    if (typeof window.showToast === 'function') {
      window.showToast(text, type === 'success' || type === 'ok' ? 'success' : 'error');
    }
  }

  function initFlashNotifications() {
    const pageWrap = document.querySelector('[data-nc-has-factura]');
    if (!pageWrap) return;

    const ok = String(pageWrap.dataset.ncOk || '').trim();
    const error = String(pageWrap.dataset.ncError || '').trim();
    if (!ok && !error) return;

    window.setTimeout(() => {
      if (ok) notify('success', ok);
      if (error) notify('error', error);
    }, 120);
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
      const hasPartialTotal = val === 'PARTIAL' && $$('.nc-line-row').some(row => lineCredit(row).total >= 0.009);
      setLiveBarVisible(hasPartialTotal);
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
        const input = $('.nc-qty-input', row);
        const credit = lineCredit(row, input?.value);
        const { qty, total: lineTotal } = credit;
        if (input && String(qty) !== String(parseFloat(input.value || '0') || 0)) {
          input.value = qty > 0 ? String(qty) : '0';
        }

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

      const partialSelected = $('[name="nc_type"][value="PARTIAL"]')?.checked ?? true;
      setLiveBarVisible(partialSelected && total >= 0.009);
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

    const formParcial = $('#nc-form-parcial form');
    const submitParcial = $('#nc-submit-parcial');
    let partialConfirmationOpen = false;

    if (formParcial && submitParcial) {
      submitParcial.addEventListener('click', async (e) => {
        e.preventDefault();
        if (partialConfirmationOpen) return;
        partialConfirmationOpen = true;
        submitParcial.disabled = true;

        try {
          const lines = [];
          $$('.nc-line-row').forEach(row => {
            const credit = lineCredit(row);
            if (credit.qty <= 0) return;

            const descHtml = escapeHtml(row.dataset.desc || 'Sin descripción');
            const qtyHtml = escapeHtml(formatQuantity(credit.qty));
            lines.push(`
              <li class="nc-confirm-line">
                <span class="nc-confirm-line-copy"><strong>${descHtml}</strong><small>${qtyHtml} unidad${credit.qty === 1 ? '' : 'es'}</small></span>
                <strong class="nc-confirm-line-total">${escapeHtml(formatMoney(credit.total))}</strong>
              </li>`);
          });

          if (!lines.length) {
            await swal.fire({ icon: 'warning', title: 'Sin ítems seleccionados', text: 'Indicá al menos una cantidad mayor a cero.', confirmButtonText: 'Entendido' });
            return;
          }

          const motivo = formParcial.querySelector('[name="motivo"]')?.value.trim() || 'Sin motivo informado';
          const total  = $('#nc-live-total')?.textContent ?? '';
          const motivoHtml = escapeHtml(motivo);
          const totalHtml  = escapeHtml(total);
          const dangerColor = getComputedStyle(document.querySelector('.nc-page')).getPropertyValue('--nc-danger').trim() || '#ef4444';

          const result = await swal.fire({
            icon: 'warning',
            title: 'Revisá la NC parcial',
            html: `
              <div class="nc-confirm-content">
                <p class="nc-confirm-intro">ARCA recibirá una Nota de Crédito por estos ítems:</p>
                <ul class="nc-confirm-lines">${lines.join('')}</ul>
                <p class="nc-confirm-motive"><span>Motivo</span><strong>${motivoHtml}</strong></p>
                <div class="nc-confirm-total"><span>Total a acreditar</span><strong>${totalHtml}</strong></div>
                <p class="nc-confirm-warning">Una vez autorizada por ARCA, esta operación no se puede deshacer.</p>
              </div>`,
            customClass: { popup: 'nc-confirm-dialog', htmlContainer: 'nc-confirm-html' },
            showCancelButton: true,
            confirmButtonText: 'Emitir NC parcial',
            cancelButtonText: 'Revisar',
            confirmButtonColor: dangerColor,
            focusCancel: true,
            reverseButtons: true,
          });

          if (result.isConfirmed) HTMLFormElement.prototype.submit.call(formParcial);
        } finally {
          partialConfirmationOpen = false;
          submitParcial.disabled = false;
        }
      });
    }

    const formTotal   = $('#nc-form-total form');
    const submitTotal = $('#nc-submit-total');
    let totalConfirmationOpen = false;

    if (formTotal && submitTotal) {
      submitTotal.addEventListener('click', async (e) => {
        e.preventDefault();
        if (totalConfirmationOpen) return;
        totalConfirmationOpen = true;
        submitTotal.disabled = true;

        try {
          const totalAmount = formTotal.closest('#nc-form-total')?.querySelector('.nc-total-amount-value')?.textContent ?? '';
          const motivo      = formTotal.querySelector('[name="motivo"]')?.value.trim() || 'Sin motivo informado';
          const totalAmountHtml = escapeHtml(totalAmount);
          const motivoHtml = escapeHtml(motivo);
          const dangerColor = getComputedStyle(document.querySelector('.nc-page')).getPropertyValue('--nc-danger').trim() || '#ef4444';

          const result = await swal.fire({
            icon: 'warning',
            title: 'Revisá la anulación total',
            html: `
              <div class="nc-confirm-content">
                <p class="nc-confirm-intro">ARCA recibirá una Nota de Crédito por todo el saldo fiscal disponible.</p>
                <p class="nc-confirm-motive"><span>Motivo</span><strong>${motivoHtml}</strong></p>
                <div class="nc-confirm-total"><span>Total a acreditar</span><strong>${totalAmountHtml}</strong></div>
                <p class="nc-confirm-warning">Una vez autorizada por ARCA, esta operación no se puede deshacer.</p>
              </div>`,
            customClass: { popup: 'nc-confirm-dialog', htmlContainer: 'nc-confirm-html' },
            showCancelButton: true,
            confirmButtonText: 'Sí, anular todo',
            cancelButtonText: 'Revisar',
            confirmButtonColor: dangerColor,
            focusCancel: true,
            reverseButtons: true,
          });

          if (result.isConfirmed) HTMLFormElement.prototype.submit.call(formTotal);
        } finally {
          totalConfirmationOpen = false;
          submitTotal.disabled = false;
        }
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
    initFlashNotifications();
    initStepper();
    initTypeSelector();
    initLiveTotals();
    initConfirmations();
  });
})();

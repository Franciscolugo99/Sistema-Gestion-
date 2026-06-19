/**
 * flus_notif.js — Sistema de notificaciones FLUS v2.1
 * ─────────────────────────────────────────────────────
 * Reemplaza window.alert / confirm / prompt con SweetAlert2.
 * - 100% local (sin CDN): assets/vendor/sweetalert2/
 * - Theme-aware: lee data-theme del <html> en cada llamada
 * - Fallback automático si Swal no está disponible
 * - window.alert queda sobreescrito globalmente
 *
 * Expone: window.Notif  (objeto con métodos async)
 */

(function (global) {
  'use strict';

  // Evita doble carga (por páginas que agreguen flus_notif.js en extraJs)
  if (global.__FLUS_NOTIF_LOADED__) return;
  global.__FLUS_NOTIF_LOADED__ = true;

  // SweetAlert2 por encima de drawers/overlays del sistema (z-index global)
  (function ensureSwalZIndex() {
    const id = 'flus-swal-zindex';
    if (document.getElementById(id)) return;
    const st = document.createElement('style');
    st.id = id;
    st.textContent = '.swal2-container{z-index:2147483647!important;}';
    document.head.appendChild(st);
  })();


  /* ─── Detección de tema ───────────────────────────────────── */
  function isDark() {
    return (
      document.documentElement.dataset.theme === 'dark' ||
      document.body?.dataset?.theme === 'dark'
    );
  }

  function cssVar(name, fallbackDark, fallbackLight) {
    const sources = [document.body, document.documentElement].filter(Boolean);
    for (const source of sources) {
      const raw = getComputedStyle(source).getPropertyValue(name).trim();
      if (raw) return raw;
    }
    return isDark() ? fallbackDark : fallbackLight;
  }

  function themeColors() {
    return {
      background: cssVar('--panel',       '#1e293b', '#ffffff'),
      color:      cssVar('--text',        '#f1f5f9', '#1e293b'),
      confirmBtn: cssVar('--accent-cyan', '#06b6d4', '#0891b2'),
      dangerBtn:  cssVar('--danger',      '#ef4444', '#dc2626'),
      cancelBtn:  cssVar('--muted',       '#475569', '#64748b'),
    };
  }

  /* ─── Helpers de seguridad ───────────────────────────────── */
  function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
      ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[c])
    );
  }

  function stripHtml(html) {
    return String(html || '')
      .replace(/<br\s*\/?>/gi, '\n')
      .replace(/<\/?p[^>]*>/gi, '\n')
      .replace(/<strong[^>]*>(.*?)<\/strong>/gi, '$1')
      .replace(/<[^>]+>/g, '')
      .replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>')
      .replace(/&quot;/g,'"').replace(/&#039;/g,"'")
      .trim();
  }

  function swalOk() { return typeof global.Swal !== 'undefined'; }

  /* ─── Toast factory ──────────────────────────────────────── */
  function makeToast(bgDark, bgLight, colorDark, colorLight, timerMs) {
    if (!swalOk()) return null;
    return Swal.mixin({
      toast: true, position: 'top-end',
      showConfirmButton: false,
      timer: timerMs || 2800, timerProgressBar: true,
      background: isDark() ? bgDark : bgLight,
      color:      isDark() ? colorDark : colorLight,
      iconColor:  isDark() ? colorDark : colorLight,
      customClass: { popup: 'flus-toast-popup' },
    });
  }

  /* ─── API pública ───────────────────────────────────────── */
  const Notif = {

    /**
     * Modal de confirmación — reemplaza window.confirm()
     * @param {string} titulo
     * @param {string} htmlMsg  — HTML (se sanitiza si opts.useText=true)
     * @param {object} opts  icon | confirmText | cancelText | confirmColor | useText | danger
     * @returns {Promise<boolean>}
     */
    async confirmar(titulo, htmlMsg, opts = {}) {
      if (!swalOk()) {
        return Promise.resolve(window._nativeConfirm(
          `${titulo}\n\n${stripHtml(htmlMsg)}`
        ));
      }
      const c = themeColors();
      const body = opts.useText
        ? { text: stripHtml(htmlMsg) }
        : { html: htmlMsg };

      const r = await Swal.fire({
        title: titulo, ...body,
        icon: opts.icon || 'question',
        showCancelButton: true,
        confirmButtonText: opts.confirmText || '✅ Confirmar',
        cancelButtonText:  opts.cancelText  || '❌ Cancelar',
        confirmButtonColor: opts.confirmColor || (opts.danger ? c.dangerBtn : c.confirmBtn),
        cancelButtonColor:  c.cancelBtn,
        focusCancel: opts.danger === true,
        reverseButtons: true,
        background: c.background, color: c.color,
        customClass: {
          popup: 'flus-modal-popup',
          confirmButton: 'flus-btn-confirm',
          cancelButton:  'flus-btn-cancel',
        },
      });
      return r.isConfirmed;
    },

    /**
     * Input modal — reemplaza window.prompt()
     * @param {string} titulo
     * @param {string} mensaje
     * @param {object} opts  defaultValue | inputType | placeholder | inputLabel | validator
     * @returns {Promise<string|null>}  null si canceló
     */
    async prompt(titulo, mensaje, opts = {}) {
      if (!swalOk()) {
        return Promise.resolve(
          global._nativePrompt(titulo + (mensaje ? '\n' + mensaje : ''), opts.defaultValue ?? '')
        );
      }
      const c = themeColors();
      const r = await Swal.fire({
        title: titulo,
        text: mensaje || undefined,
        input: opts.inputType || 'text',
        inputLabel: opts.inputLabel || undefined,
        inputPlaceholder: opts.placeholder || '',
        inputValue: opts.defaultValue ?? '',
        showCancelButton: true,
        confirmButtonText: opts.confirmText || '✅ Aceptar',
        cancelButtonText:  opts.cancelText  || '❌ Cancelar',
        confirmButtonColor: c.confirmBtn,
        cancelButtonColor:  c.cancelBtn,
        reverseButtons: true,
        background: c.background, color: c.color,
        customClass: {
          popup:         'flus-modal-popup',
          input:         'flus-prompt-input',
          confirmButton: 'flus-btn-confirm',
          cancelButton:  'flus-btn-cancel',
        },
        preConfirm: (val) => {
          if (opts.validator) {
            const err = opts.validator(val);
            if (err) { Swal.showValidationMessage(err); return false; }
          }
          return val;
        },
      });
      return r.isConfirmed ? (r.value ?? '') : null;
    },

    /** Toast verde — éxito */
    exito(msg) {
      const t = makeToast('#162c1e','#f0fdf4','#4ade80','#166534', 2500);
      t?.fire({ icon: 'success', title: escHtml(msg) });
    },

    /** Alias compat */
    success(msg) {
      Notif.exito(msg);
    },

    /** Toast rojo — error */
    error(msg) {
      const t = makeToast('#2d1515','#fef2f2','#f87171','#991b1b', 3500);
      t?.fire({ icon: 'error', title: escHtml(msg) });
    },

    /** Toast amarillo — advertencia */
    advertencia(msg) {
      const t = makeToast('#2a1f0a','#fffbeb','#fbbf24','#92400e', 3200);
      t?.fire({ icon: 'warning', title: escHtml(msg) });
    },

    /** Toast azul — info */
    info(msg) {
      const t = makeToast('#0c1f2e','#eff6ff','#60a5fa','#1e40af', 2800);
      t?.fire({ icon: 'info', title: escHtml(msg) });
    },
  };

  /* ─── Override global de window.alert ───────────────────── */
  // Guardamos los nativos por si el fallback los necesita
  global._nativeAlert   = global.alert.bind(global);
  global._nativeConfirm = global.confirm.bind(global);
  global._nativePrompt  = global.prompt.bind(global);

  // alert() → toast de error o advertencia (fire-and-forget, seguro de sobreescribir)
  global.alert = function (msg) {
    const s = String(msg || '');
    // Heurística: si parece error, rojo; si no, advertencia
    const esError = /error|fall[oó]|no se pudo|inválido|falta|csrf/i.test(s);
    if (esError) Notif.error(s);
    else         Notif.advertencia(s);
  };

  /* ─── Exponer ────────────────────────────────────────────── */
  global.Notif = Notif;

})(window);

(function () {
  'use strict';

  function notify(type, message) {
    const text = String(message || '').trim();
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

  function initDocumentoComercialFlash() {
    const page = document.querySelector('[data-doc-flash-ok], [data-doc-flash-error]');
    if (!page) return;

    const ok = String(page.dataset.docFlashOk || '').trim();
    const error = String(page.dataset.docFlashError || '').trim();
    if (!ok && !error) return;

    window.setTimeout(() => {
      if (ok) notify('success', ok);
      if (error) notify('error', error);
    }, 120);
  }

  document.addEventListener('DOMContentLoaded', initDocumentoComercialFlash);
})();

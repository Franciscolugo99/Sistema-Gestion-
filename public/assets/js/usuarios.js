/**
 * FLUS - Módulo Usuarios (listado)
 * v4: toggle sin inline-onclick + manejo robusto de fetch/JSON + re-enable botón
 */
'use strict';

(function () {
  function getCsrf() {
    return (typeof window.getCsrfToken === 'function') ? (window.getCsrfToken() || '') : '';
  }

  async function parseJsonSafe(resp) {
    try { return await resp.json(); } catch (_) { return null; }
  }

  function setBtnLoading(btn, loading) {
    if (!btn) return;
    btn.disabled = !!loading;
    btn.classList.toggle('loading', !!loading);
  }

  async function toggleUserStatus(userId, currentlyActive, btnEl) {
    const newActive = !currentlyActive;
    const actionLabel = newActive ? 'activar' : 'desactivar';

    if (!window.Notif || typeof window.Notif.confirmar !== 'function') return;
    const ok = await window.Notif.confirmar(
      `${newActive ? 'Activar' : 'Desactivar'} usuario`,
      `¿Confirmás que querés ${actionLabel} este usuario?`,
      {
        icon: newActive ? 'question' : 'warning',
        confirmText: newActive ? 'Activar usuario' : 'Desactivar usuario',
        cancelText: 'Cancelar',
        useText: true,
        danger: !newActive,
      }
    );
    if (!ok) return;

    setBtnLoading(btnEl, true);

    try {
      const resp = await fetch('api/usuario_toggle_estado.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json',
        },
        body: JSON.stringify({
          user_id: userId,
          activo: newActive ? 1 : 0,
          csrf_token: getCsrf(),
        }),
      });

      const data = await parseJsonSafe(resp);

      if (!resp.ok) {
        const msg = (data && (data.message || data.error)) || `HTTP ${resp.status}`;
        throw new Error(msg);
      }
      if (!(data && (data.ok || data.success))) {
        const msg = (data && (data.message || data.error)) || 'No se pudo cambiar el estado';
        throw new Error(msg);
      }

      // Actualizar fila sin recarga
      const row = document.querySelector(`tr[data-user-id="${userId}"]`);
      if (row) {
        const badge = row.querySelector('.badge-estado');
        if (badge) {
          badge.className = newActive ? 'badge-estado badge-estado--ok' : 'badge-estado badge-estado--off';
          badge.innerHTML = `<span class="badge-dot"></span>${newActive ? 'Activo' : 'Inactivo'}`;
        }
      }

      // Actualizar botón
      if (btnEl) {
        btnEl.dataset.active = newActive ? '1' : '0';
        btnEl.className = `action-btn ${newActive ? 'action-btn--deactivate' : 'action-btn--activate'} js-toggle-user`;
        btnEl.title = newActive ? 'Desactivar usuario' : 'Activar usuario';

        const icon = newActive
          ? `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M18.36 6.64A9 9 0 0 1 20.77 15M6.16 6.16a9 9 0 1 0 12.68 12.68M2 2l20 20"/>
              <path d="M9 9v3a3 3 0 0 0 5.12 2.12"/>
            </svg>`
          : `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Z"/><path d="m9 12 2 2 4-4"/>
            </svg>`;

        btnEl.innerHTML = `${icon}<span>${newActive ? 'Desactivar' : 'Activar'}</span>`;
      }

      if (newActive) window.Notif.exito('Usuario activado correctamente.');
      else window.Notif.advertencia('Usuario desactivado correctamente.');
    } catch (err) {
      console.error('toggleUserStatus error:', err);
      window.Notif.error(err && err.message ? err.message : 'No se pudo cambiar el estado del usuario.');
    } finally {
      setBtnLoading(btnEl, false);
    }
  }

  // Compat: si quedó algún inline onclick viejo, no rompe
  window.toggleUserStatus = toggleUserStatus;

  document.addEventListener('DOMContentLoaded', function () {
    // Toggle: event delegation (sin inline onclick)
    document.addEventListener('click', function (e) {
      const btn = e.target.closest('.js-toggle-user');
      if (!btn) return;

      const tr = btn.closest('tr');
      const trId = (tr && tr.dataset) ? tr.dataset.userId : '';
      const userId = parseInt(btn.dataset.userId || trId || '0', 10);
      const currentlyActive = (btn.dataset.active || '0') === '1';
      if (!userId) return;

      toggleUserStatus(userId, currentlyActive, btn);
    });

    // Búsqueda con Enter
    const searchInput = document.getElementById('search');
    if (searchInput) {
      searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') this.closest('form').submit();
      });
    }

    // Animación de aparición de filas
    document.querySelectorAll('[data-users-autosubmit]').forEach(function (control) {
      control.addEventListener('change', function () {
        if (control.form) control.form.submit();
      });
    });

    const clearSearchBtn = document.querySelector('[data-users-clear-search]');
    if (clearSearchBtn) {
      clearSearchBtn.addEventListener('click', function () {
        const form = clearSearchBtn.closest('form');
        const input = form ? form.querySelector('[name="search"]') : null;
        if (input) input.value = '';
        if (form) form.submit();
      });
    }

    const rows = document.querySelectorAll('.tabla-usuarios tbody tr');
    rows.forEach((row, i) => {
      row.style.animationDelay = `${i * 30}ms`;
      row.classList.add('row-appear');
    });
  });
})();

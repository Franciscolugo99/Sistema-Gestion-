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

    const ok = window.confirm(`¿${newActive ? 'Activar' : 'Desactivar'} este usuario?`);
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

      showInlineFlash(newActive ? 'success' : 'warning',
        `Usuario ${newActive ? 'activado' : 'desactivado'} correctamente`);
    } catch (err) {
      console.error('toggleUserStatus error:', err);
      alert('Error: ' + (err && err.message ? err.message : 'No se pudo cambiar el estado'));
    } finally {
      setBtnLoading(btnEl, false);
    }
  }

  // Compat: si quedó algún inline onclick viejo, no rompe
  window.toggleUserStatus = toggleUserStatus;

  function showInlineFlash(type, message) {
    const existing = document.querySelector('.inline-flash');
    if (existing) existing.remove();

    const cls = type === 'success' ? 'alert-success' : 'alert-warning';
    const icon = type === 'success'
      ? `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>`
      : `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`;

    const div = document.createElement('div');
    div.className = `alert ${cls} inline-flash`;
    div.style.cssText = 'position:fixed;top:72px;right:24px;z-index:9000;min-width:280px;max-width:400px;animation:slideInRight .25s ease;';
    div.innerHTML = `${icon} ${message}`;
    document.body.appendChild(div);
    setTimeout(() => div.remove(), 3500);
  }

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
    const rows = document.querySelectorAll('.tabla-usuarios tbody tr');
    rows.forEach((row, i) => {
      row.style.animationDelay = `${i * 30}ms`;
      row.classList.add('row-appear');
    });
  });
})();

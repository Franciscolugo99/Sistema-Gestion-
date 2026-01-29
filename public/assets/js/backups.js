/**
 * Backups - JavaScript mejorado
 * Funcionalidades: AJAX requests, loading states, mejor UX
 */
// public/assets/js/backups.js
(function() {
  'use strict';

  // Elementos del DOM
  const createBackupForm = document.getElementById('createBackupForm');
  const btnCrearBackup = document.getElementById('btnCrearBackup');
  const loadingOverlay = document.getElementById('loadingOverlay');
  const loadingText = document.getElementById('loadingText');
  const backupsTableBody = document.getElementById('backupsTableBody');

  // Inicialización
  init();

  function init() {
    if (createBackupForm) {
      createBackupForm.addEventListener('submit', handleCreateBackup);
    }

    // Delegación de clicks (restore / mantenimiento)
    document.addEventListener('click', (ev) => {
      const restoreBtn = ev.target.closest('.btn-restore');
      if (restoreBtn) {
        ev.preventDefault();
        const f = restoreBtn.getAttribute('data-file') || '';
        if (f) handleRestoreBackup(f);
        return;
      }

      const maintBtn = ev.target.closest('#btnMaintenanceOff');
      if (maintBtn) {
        ev.preventDefault();
        handleMaintenanceOff();
      }
    });

    // Auto-hide alerts después de 5 segundos
    autoHideAlerts();
  }

  /**
   * Maneja la creación de backup con AJAX
   */
  async function handleCreateBackup(e) {
    e.preventDefault();

    // Deshabilitar botón y mostrar loading
    btnCrearBackup.disabled = true;
    showLoading(true, 'Creando backup...');

    const formData = new FormData(createBackupForm);
    formData.append('ajax', '1');

    try {
      const response = await fetch(window.location.href, {
        method: 'POST',
        body: formData
      });

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const data = await response.json();

      // Ocultar loading
      showLoading(false);

      // Mostrar resultado
      if (data.ok || data.success) {
        showAlert('success', data.message || 'Backup creado exitosamente');
        
        // Actualizar tabla si hay items
        if (data.items && data.items.length > 0) {
          updateBackupsTable(data.items);
        }
      } else {
        showAlert('error', data.message || 'Error al crear el backup');
      }

    } catch (error) {
      console.error('Error al crear backup:', error);
      showLoading(false);
      showAlert('error', 'Error de conexión. Verificá tu conexión a internet e intentá de nuevo.');
    } finally {
      // Re-habilitar botón
      btnCrearBackup.disabled = false;
    }
  }


  /**
   * Restaurar un backup existente (AJAX)
   */
  async function handleRestoreBackup(file) {
    const fileSafe = String(file || '').trim();
    if (!fileSafe) return;

    const confirmWord = prompt(
      `⚠️ RESTAURAR BACKUP

Esto REEMPLAZA la base de datos actual por el contenido del backup:

${fileSafe}

Escribí RESTAURAR para continuar.`
    );
    if (confirmWord !== 'RESTAURAR') return;

    const csrf = (document.querySelector('input[name="csrf_token"]') || {}).value || '';
    if (!csrf) {
      showAlert('error', 'CSRF faltante. Recargá la página e intentá de nuevo.');
      return;
    }

    // Deshabilitar acciones principales
    if (btnCrearBackup) btnCrearBackup.disabled = true;

    showLoading(true, 'Restaurando backup... No cierres esta ventana.');

    const formData = new FormData();
    formData.append('csrf_token', csrf);
    formData.append('accion', 'restaurar');
    formData.append('file', fileSafe);
    formData.append('ajax', '1');

    try {
      const response = await fetch(window.location.href, {
        method: 'POST',
        body: formData
      });

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const data = await response.json();
      showLoading(false);

      if (data.ok || data.success) {
        showAlert('success', data.message || 'Restauración completada');
        // Mejor recargar: pueden cambiar datos/permisos
        setTimeout(() => window.location.reload(), 800);
      } else {
        showAlert('error', data.message || 'Error al restaurar el backup');
      }

    } catch (error) {
      console.error('Error al restaurar backup:', error);
      showLoading(false);
      showAlert('error', 'Error de conexión durante la restauración.');
    } finally {
      if (btnCrearBackup) btnCrearBackup.disabled = false;
    }
  }

  /**
   * Desactivar mantenimiento manualmente (si quedó activo)
   */
  async function handleMaintenanceOff() {
    const confirmWord = prompt('Escribí SALIR para desactivar el modo mantenimiento.');
    if (confirmWord !== 'SALIR') return;

    const csrf = (document.querySelector('input[name="csrf_token"]') || {}).value || '';
    if (!csrf) {
      showAlert('error', 'CSRF faltante. Recargá la página e intentá de nuevo.');
      return;
    }

    showLoading(true, 'Desactivando mantenimiento...');

    const formData = new FormData();
    formData.append('csrf_token', csrf);
    formData.append('accion', 'maintenance_off');
    formData.append('confirm', 'SALIR');
    formData.append('ajax', '1');

    try {
      const response = await fetch(window.location.href, {
        method: 'POST',
        body: formData
      });

      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }

      const data = await response.json();
      showLoading(false);

      if (data.ok || data.success) {
        showAlert('success', data.message || 'Mantenimiento desactivado');
        setTimeout(() => window.location.reload(), 600);
      } else {
        showAlert('error', data.message || 'No se pudo desactivar mantenimiento');
      }

    } catch (error) {
      console.error('Error maintenance_off:', error);
      showLoading(false);
      showAlert('error', 'Error de conexión.');
    }
  }

  /**
   * Muestra/oculta el overlay de loading
   */
  function showLoading(show, text) {
    if (loadingText && typeof text === 'string' && text.trim() !== '') {
      loadingText.textContent = text;
    }
    if (loadingOverlay) {
      loadingOverlay.style.display = show ? 'flex' : 'none';
    }
  }

  /**
   * Muestra una alerta temporal
   */
  function showAlert(type, message) {
    // Remover alertas existentes
    const existingAlerts = document.querySelectorAll('.alert');
    existingAlerts.forEach(alert => alert.remove());

    // Crear nueva alerta
    const alert = document.createElement('div');
    alert.className = type === 'success' ? 'alert alert-ok' : 'alert alert-err';
    
    const icon = type === 'success' 
      ? '<svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'
      : '<svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
    
    alert.innerHTML = icon + '<span>' + escapeHtml(message) + '</span>';

    // Insertar después del panel-head
    const panelHead = document.querySelector('.panel-head');
    if (panelHead && panelHead.nextSibling) {
      panelHead.parentNode.insertBefore(alert, panelHead.nextSibling);
    }

    // Auto-hide después de 5 segundos
    setTimeout(() => {
      alert.style.animation = 'slideOut 0.3s ease';
      setTimeout(() => alert.remove(), 300);
    }, 5000);
  }

  /**
   * Actualiza la tabla de backups con nuevos datos
   */
  function updateBackupsTable(items) {
    if (!backupsTableBody || !items || items.length === 0) return;

    // Actualizar stats
    updateStats(items);

    // Generar HTML para las filas
    const rowsHtml = items.map((item, idx) => {
      const time = parseInt(item.mtime);
      const now = Math.floor(Date.now() / 1000);
      const diff = now - time;
      
      let rel;
      if (diff < 3600) {
        rel = 'Hace ' + Math.round(diff / 60) + ' min';
      } else if (diff < 86400) {
        rel = 'Hace ' + Math.round(diff / 3600) + ' hs';
      } else if (diff < 604800) {
        rel = 'Hace ' + Math.round(diff / 86400) + ' días';
      } else {
        rel = formatDate(time);
      }

      const dateAbs = formatDateTime(time);
      const csrfToken = document.querySelector('input[name="csrf_token"]').value;

      return `
        <tr>
          <td>
            <div class="file-info">
              <svg class="icon file-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
              </svg>
              <span class="mono">${escapeHtml(item.file)}</span>
            </div>
          </td>
          <td class="size-cell">${formatBytes(parseInt(item.size))}</td>
          <td class="date-cell">
            <span class="date-rel">${escapeHtml(rel)}</span>
            <span class="date-abs">${escapeHtml(dateAbs)}</span>
          </td>
          <td class="actions-cell t-right">
            <a class="btn btn-ghost btn-sm" 
               href="backup_download.php?f=${encodeURIComponent(item.file)}"
               title="Descargar backup">
              <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
              </svg>
              Descargar
            </a>

            <button class="btn btn-warning btn-sm btn-restore" type="button" data-file="${escapeHtml(item.file)}" title="Restaurar este backup">
              <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="1 4 1 10 7 10"/>
                <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
              </svg>
              Restaurar
            </button>

            <form method="post" class="inline-form" onsubmit="return confirmarBorrado('${escapeHtml(item.file)}');">
              <input type="hidden" name="csrf_token" value="${escapeHtml(csrfToken)}">
              <input type="hidden" name="accion" value="borrar">
              <input type="hidden" name="file" value="${escapeHtml(item.file)}">
              <button class="btn btn-danger btn-sm" type="submit" title="Eliminar backup">
                <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <polyline points="3 6 5 6 21 6"/>
                  <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                </svg>
                Borrar
              </button>
            </form>
          </td>
        </tr>
      `;
    }).join('');

    backupsTableBody.innerHTML = rowsHtml;
  }

  /**
   * Actualiza las estadísticas en los cards
   */
  function updateStats(items) {
    // Total de backups
    const totalElement = document.querySelector('.stat-card:nth-child(1) .stat-value');
    if (totalElement) {
      totalElement.textContent = items.length;
    }

    // Tamaño total
    const totalSize = items.reduce((sum, item) => sum + parseInt(item.size), 0);
    const sizeElement = document.querySelector('.stat-card:nth-child(2) .stat-value');
    if (sizeElement) {
      sizeElement.textContent = formatBytes(totalSize);
    }

    // Último backup
    if (items.length > 0) {
      const lastTime = parseInt(items[0].mtime);
      const lastElement = document.querySelector('.stat-card:nth-child(3) .stat-value');
      if (lastElement) {
        lastElement.textContent = formatShortDateTime(lastTime);
      }
    }
  }

  /**
   * Auto-hide para las alertas existentes
   */
  function autoHideAlerts() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
      setTimeout(() => {
        alert.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => alert.remove(), 300);
      }, 5000);
    });
  }

  // Utilidades

  function escapeHtml(text) {
    const map = {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
  }

  function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
  }

  function formatDate(timestamp) {
    const date = new Date(timestamp * 1000);
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    return `${day}/${month}/${year}`;
  }

  function formatDateTime(timestamp) {
    const date = new Date(timestamp * 1000);
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear();
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const seconds = String(date.getSeconds()).padStart(2, '0');
    return `${day}/${month}/${year} ${hours}:${minutes}:${seconds}`;
  }

  function formatShortDateTime(timestamp) {
    const date = new Date(timestamp * 1000);
    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${day}/${month} ${hours}:${minutes}`;
  }

  // Agregar animación slideOut al CSS si no existe
  const style = document.createElement('style');
  style.textContent = `
    @keyframes slideOut {
      from {
        opacity: 1;
        transform: translateY(0);
      }
      to {
        opacity: 0;
        transform: translateY(-10px);
      }
    }
  `;
  document.head.appendChild(style);

})();
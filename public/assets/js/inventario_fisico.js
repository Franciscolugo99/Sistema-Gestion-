/* FLUS - Inventario Físico v2.0 (inventario_fisico.js)
   - Detección de productos ya contados
   - Modo ciego y modo rápido
   - Preview de diferencia en tiempo real
   - Atajos de teclado
   - Filtro de tabla
*/

(function () {
  'use strict';

  // ═══════════════════════════════════════════════════════════════════════════
  // CONFIGURACIÓN Y ESTADO
  // ═══════════════════════════════════════════════════════════════════════════
  const CONFIG = {
    searchDebounce: 200,
    apiEndpoint: 'api/system_api.php',
  };

  const state = {
    productosContados: {},
    modoCiego: false,
    modoRapido: false,
    productoSeleccionado: null,
    sessionCategoriaId: 0,
    sessionCategoriaNombre: '',
  };

  // ═══════════════════════════════════════════════════════════════════════════
  // HELPERS
  // ═══════════════════════════════════════════════════════════════════════════
  function $(id) {
    return document.getElementById(id);
  }

  function $$(selector) {
    return document.querySelectorAll(selector);
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = String(text ?? '');
    return div.innerHTML;
  }

  function formatNumber(n, decimals = 2) {
    return Number(n).toLocaleString('es-AR', {
      minimumFractionDigits: decimals,
      maximumFractionDigits: decimals,
    });
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // CARGAR PRODUCTOS YA CONTADOS
  // ═══════════════════════════════════════════════════════════════════════════
  function cargarProductosContados() {
    const panel = document.querySelector('.invf');
    if (!panel) return;

    try {
      const data = panel.dataset.productosContados;
      if (data) {
        state.productosContados = JSON.parse(data);
      }
      state.sessionCategoriaId = Number(panel.dataset.sessionCategoryId || 0);
      state.sessionCategoriaNombre = String(panel.dataset.sessionCategoryName || '').trim();
    } catch (e) {
      console.warn('Error parsing productosContados:', e);
    }
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // MODOS (CIEGO / RÁPIDO)
  // ═══════════════════════════════════════════════════════════════════════════
  function initModos() {
    const toggleCiego = $('toggleModoCiego');
    const toggleRapido = $('toggleModoRapido');
    const panel = document.querySelector('.invf');

    if (toggleCiego) {
      state.modoCiego = toggleCiego.checked;
      if (state.modoCiego && panel) {
        panel.classList.add('modo-ciego');
      }

      toggleCiego.addEventListener('change', function () {
        state.modoCiego = this.checked;
        if (panel) {
          panel.classList.toggle('modo-ciego', state.modoCiego);
        }
        // Actualizar URL sin recargar
        updateUrlParam('ciego', state.modoCiego ? '1' : null);
      });
    }

    if (toggleRapido) {
      state.modoRapido = toggleRapido.checked;

      toggleRapido.addEventListener('change', function () {
        state.modoRapido = this.checked;
        updateUrlParam('rapido', state.modoRapido ? '1' : null);
      });
    }
  }

  function updateUrlParam(key, value) {
    const url = new URL(window.location.href);
    if (value === null) {
      url.searchParams.delete(key);
    } else {
      url.searchParams.set(key, value);
    }
    window.history.replaceState({}, '', url.toString());
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // BÚSQUEDA DE PRODUCTOS
  // ═══════════════════════════════════════════════════════════════════════════
  let searchTimeout = null;
  let abortController = null;

  function initBusqueda() {
    const searchInput = $('buscarProducto');
    const searchResults = $('searchResults');
    const formConteo = $('formConteo');

    if (!searchInput || !searchResults) return;

    function hideResults() {
      searchResults.classList.remove('show');
      searchResults.innerHTML = '';
    }

    function showResults(html) {
      searchResults.innerHTML = html;
      searchResults.classList.add('show');
    }

    async function buscarProductos(query) {
      if (query.length < 2) {
        hideResults();
        return;
      }

      // Cancelar búsqueda anterior
      if (abortController) {
        abortController.abort();
      }
      abortController = new AbortController();

      try {
        const params = new URLSearchParams({
          action: 'inventario_buscar_producto',
          q: query,
        });
        if (state.sessionCategoriaId > 0) {
          params.set('categoria_id', String(state.sessionCategoriaId));
        }
        if (state.sessionCategoriaNombre) {
          params.set('categoria_nombre', state.sessionCategoriaNombre);
        }

        const res = await fetch(
          `${CONFIG.apiEndpoint}?${params.toString()}`,
          {
            headers: { Accept: 'application/json' },
            signal: abortController.signal,
          }
        );

        const data = await res.json();
        const ok = data && (data.ok === true || data.success === true);
        const arr = (data && (data.productos || data.data)) || [];

        if (ok && Array.isArray(arr) && arr.length > 0) {
          const html = arr
            .map((p) => {
              const id = Number(p.id || 0);
              const codigo = escapeHtml(p.codigo || '');
              const nombre = escapeHtml(p.nombre || '');
              const stock = Number(p.stock || 0);

              // Verificar si ya fue contado
              const yaContado = state.productosContados[id];
              const yaContadoClass = yaContado ? 'ya-contado' : '';
              const yaContadoBadge = yaContado
                ? `<span class="search-result-ya-badge">✓ Contado: ${formatNumber(yaContado.cantidad)}</span>`
                : '';

              return `
                <div class="search-result-item ${yaContadoClass}" 
                     data-id="${id}" 
                     data-codigo="${codigo}" 
                     data-nombre="${nombre}" 
                     data-stock="${stock}"
                     data-ya-contado="${yaContado ? yaContado.cantidad : ''}">
                  <div class="producto-codigo">${codigo}${yaContadoBadge}</div>
                  <div class="producto-nombre">${nombre}</div>
                  <div class="producto-stock">Stock: ${formatNumber(stock)}</div>
                </div>
              `;
            })
            .join('');

          showResults(html);
        } else {
          showResults('<div class="search-result-item" style="color: var(--inv-muted);">No se encontraron productos</div>');
        }
      } catch (err) {
        if (err.name !== 'AbortError') {
          console.warn('Error buscando productos:', err);
          showResults('<div class="search-result-item" style="color: var(--inv-danger);">Error al buscar</div>');
        }
      }
    }

    // Input event con debounce
    searchInput.addEventListener('input', function () {
      clearTimeout(searchTimeout);
      const q = this.value.trim();

      if (q.length < 2) {
        hideResults();
        return;
      }

      searchTimeout = setTimeout(() => buscarProductos(q), CONFIG.searchDebounce);
    });

    // Click en resultado
    searchResults.addEventListener('click', function (e) {
      const item = e.target.closest('.search-result-item');
      if (!item || item.textContent.includes('No se encontraron') || item.textContent.includes('Error')) {
        return;
      }

      const id = Number(item.dataset.id || 0);
      const codigo = item.dataset.codigo || '';
      const nombre = item.dataset.nombre || '';
      const stock = Number(item.dataset.stock || 0);
      const yaContado = item.dataset.yaContado ? Number(item.dataset.yaContado) : null;

      seleccionarProducto(id, codigo, nombre, stock, yaContado);
    });

    // Cerrar al hacer click fuera
    document.addEventListener('click', function (e) {
      if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
        hideResults();
      }
    });

    // Navegación con teclado
    searchInput.addEventListener('keydown', function (e) {
      if (!searchResults.classList.contains('show')) return;

      const items = searchResults.querySelectorAll('.search-result-item:not([style*="color"])');
      if (items.length === 0) return;

      const current = searchResults.querySelector('.search-result-item.selected');
      let index = current ? Array.from(items).indexOf(current) : -1;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        index = Math.min(index + 1, items.length - 1);
        items.forEach((item, i) => item.classList.toggle('selected', i === index));
        items[index]?.scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        index = Math.max(index - 1, 0);
        items.forEach((item, i) => item.classList.toggle('selected', i === index));
        items[index]?.scrollIntoView({ block: 'nearest' });
      } else if (e.key === 'Enter' && index >= 0) {
        e.preventDefault();
        items[index].click();
      } else if (e.key === 'Escape') {
        hideResults();
      }
    });
  }

  function applySearchPrefill() {
    const searchInput = $('buscarProducto');
    if (!searchInput) return;

    const preset = new URLSearchParams(window.location.search).get('q');
    if (!preset) return;

    searchInput.value = preset;
    searchInput.dispatchEvent(new Event('input', { bubbles: true }));
    searchInput.focus();
    searchInput.select?.();
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // SELECCIONAR PRODUCTO
  // ═══════════════════════════════════════════════════════════════════════════
  async function seleccionarProducto(id, codigo, nombre, stock, yaContadoCantidad = null) {
    const productoId = $('productoId');
    const selCodigo = $('selCodigo');
    const selNombre = $('selNombre');
    const selStock = $('selStock');
    const searchInput = $('buscarProducto');
    const searchResults = $('searchResults');
    const formConteo = $('formConteo');
    const cantidad = $('cantidadContada');
    const yaContadoBadge = $('yaContadoBadge');
    const yaContadoCantidadEl = $('yaContadoCantidad');

    if (!productoId || !formConteo) return;

    // Guardar estado
    state.productoSeleccionado = { id, codigo, nombre, stock };

    // Actualizar UI
    productoId.value = String(id);
    if (selCodigo) selCodigo.textContent = codigo;
    if (selNombre) selNombre.textContent = nombre;
    if (selStock) selStock.textContent = formatNumber(stock);

    // Mostrar badge "Ya contado" si aplica
    if (yaContadoBadge && yaContadoCantidadEl) {
      if (yaContadoCantidad !== null) {
        yaContadoCantidadEl.textContent = formatNumber(yaContadoCantidad);
        yaContadoBadge.classList.remove('is-hidden');

        // Mostrar confirmación
        const _yaContadoOk = await Notif.confirmar(
          '🔄 Producto ya contado',
          `<p>Este producto ya fue contado: <strong>${formatNumber(yaContadoCantidad)} unidades</strong>.</p><p style='color:var(--muted,#94a3b8);font-size:.88rem;margin-top:6px'>¿Querés actualizar el conteo?</p>`,
          { icon: 'warning', confirmText: '✅ Actualizar', cancelText: '❌ Cancelar' }
        );
        if (!_yaContadoOk) { cancelarSeleccion(); return; }
      } else {
        yaContadoBadge.classList.add('is-hidden');
      }
    }

    // Ocultar buscador, mostrar formulario
    if (searchInput) searchInput.classList.add('is-hidden');
    if (searchResults) searchResults.classList.remove('show');
    formConteo.classList.remove('is-hidden');

    // Limpiar y enfocar cantidad
    if (cantidad) {
      cantidad.value = '';
      cantidad.focus();
    }

    // Actualizar preview
    actualizarPreviewDiferencia();
  }

  window.seleccionarProducto = seleccionarProducto;

  // ═══════════════════════════════════════════════════════════════════════════
  // CANCELAR SELECCIÓN
  // ═══════════════════════════════════════════════════════════════════════════
  function cancelarSeleccion() {
    const searchInput = $('buscarProducto');
    const searchResults = $('searchResults');
    const formConteo = $('formConteo');
    const yaContadoBadge = $('yaContadoBadge');
    const conteoPreview = $('conteoPreview');

    state.productoSeleccionado = null;

    if (formConteo) formConteo.classList.add('is-hidden');
    if (searchInput) {
      searchInput.classList.remove('is-hidden');
      searchInput.value = '';
      searchInput.focus();
    }
    if (searchResults) {
      searchResults.classList.remove('show');
      searchResults.innerHTML = '';
    }
    if (yaContadoBadge) yaContadoBadge.classList.add('is-hidden');
    if (conteoPreview) conteoPreview.classList.add('is-hidden');
  }

  window.cancelarSeleccion = cancelarSeleccion;

  // ═══════════════════════════════════════════════════════════════════════════
  // PREVIEW DE DIFERENCIA
  // ═══════════════════════════════════════════════════════════════════════════
  function actualizarPreviewDiferencia() {
    const cantidad = $('cantidadContada');
    const conteoPreview = $('conteoPreview');
    const previewDiferencia = $('previewDiferencia');
    const labelHint = $('labelHintDif');

    if (!cantidad || !state.productoSeleccionado) return;

    const contado = parseFloat(cantidad.value) || 0;
    const stockSistema = state.productoSeleccionado.stock || 0;
    const diferencia = contado - stockSistema;

    // Actualizar hint en el label
    if (labelHint && contado > 0) {
      if (Math.abs(diferencia) < 0.001) {
        labelHint.textContent = '(= stock)';
        labelHint.className = 'label-hint';
      } else if (diferencia > 0) {
        labelHint.textContent = `(+${formatNumber(diferencia)} sobrante)`;
        labelHint.className = 'label-hint positive';
      } else {
        labelHint.textContent = `(${formatNumber(diferencia)} faltante)`;
        labelHint.className = 'label-hint negative';
      }
    } else if (labelHint) {
      labelHint.textContent = '';
    }

    // Mostrar preview
    if (conteoPreview && previewDiferencia && contado > 0) {
      conteoPreview.classList.remove('is-hidden');

      if (Math.abs(diferencia) < 0.001) {
        previewDiferencia.textContent = '0 (sin diferencia)';
        previewDiferencia.className = 'conteo-preview-value ok';
      } else if (diferencia > 0) {
        previewDiferencia.textContent = `+${formatNumber(diferencia)} sobrante`;
        previewDiferencia.className = 'conteo-preview-value positive';
      } else {
        previewDiferencia.textContent = `${formatNumber(diferencia)} faltante`;
        previewDiferencia.className = 'conteo-preview-value negative';
      }
    } else if (conteoPreview) {
      conteoPreview.classList.add('is-hidden');
    }
  }

  function initPreviewDiferencia() {
    const cantidad = $('cantidadContada');
    if (cantidad) {
      cantidad.addEventListener('input', actualizarPreviewDiferencia);
    }
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // SUBMIT DEL FORMULARIO
  // ═══════════════════════════════════════════════════════════════════════════
  function initFormSubmit() {
    const formConteo = $('formConteo');
    if (!formConteo) return;

    formConteo.addEventListener('submit', function (e) {
      // Si modo rápido está activo, hacer AJAX submit
      if (state.modoRapido) {
        e.preventDefault();
        submitConteoRapido(formConteo);
      }
      // Si no, dejar que el form se envíe normalmente
    });
  }

  async function submitConteoRapido(form) {
    const btnRegistrar = $('btnRegistrar');
    const searchInput = $('buscarProducto');

    if (btnRegistrar) {
      btnRegistrar.disabled = true;
      btnRegistrar.innerHTML = '<span class="spinner"></span> Guardando...';
    }

    try {
      const formData = new FormData(form);

      const res = await fetch(window.location.href, {
        method: 'POST',
        body: formData,
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
        },
      });

      let data = null;
      const contentType = res.headers.get('content-type') || '';
      if (contentType.includes('application/json')) {
        data = await res.json();
      } else {
        throw new Error('Respuesta no JSON');
      }

      if (data.ok) {
        // Actualizar productos contados en estado
        const productoId = Number(formData.get('producto_id'));
        const cantidad = parseFloat(formData.get('cantidad')) || 0;
        state.productosContados[productoId] = { cantidad };

        // Mostrar mensaje de éxito breve
        mostrarNotificacion('Conteo registrado', 'success');

        // Volver al buscador
        cancelarSeleccion();

        // Recargar para actualizar la tabla (o actualizar con JS)
        setTimeout(() => {
          window.location.reload();
        }, 500);
      } else {
        mostrarNotificacion(data.error || data.mensaje || 'Error al registrar', 'error');
      }
    } catch (err) {
      console.error('Error en submit rápido:', err);
      mostrarNotificacion('No se pudo registrar el conteo', 'error');
    } finally {
      if (btnRegistrar) {
        btnRegistrar.disabled = false;
        btnRegistrar.innerHTML = `
          <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          Registrar Conteo
          <kbd>Enter</kbd>
        `;
      }
    }
  }

  function mostrarNotificacion(mensaje, tipo = 'info') {
    // Crear notificación temporal
    const noti = document.createElement('div');
    noti.className = `inv-notificacion inv-notificacion--${tipo}`;
    noti.innerHTML = `
      <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        ${tipo === 'success' 
          ? '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'
          : '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>'
        }
      </svg>
      <span>${escapeHtml(mensaje)}</span>
    `;

    document.body.appendChild(noti);

    // Animar entrada
    setTimeout(() => noti.classList.add('show'), 10);

    // Remover después de 2s
    setTimeout(() => {
      noti.classList.remove('show');
      setTimeout(() => noti.remove(), 300);
    }, 2000);
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // FILTRO DE TABLA
  // ═══════════════════════════════════════════════════════════════════════════
  function initFiltroTabla() {
    const filtroInput = $('filtrarConteos');
    const tabla = $('tablaConteos');

    if (!filtroInput || !tabla) return;

    filtroInput.addEventListener('input', function () {
      const query = this.value.toLowerCase().trim();
      const rows = tabla.querySelectorAll('tbody tr');

      rows.forEach((row) => {
        const texto = row.dataset.producto || row.textContent.toLowerCase();
        row.style.display = texto.includes(query) ? '' : 'none';
      });
    });
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // ATAJOS DE TECLADO
  // ═══════════════════════════════════════════════════════════════════════════
  function initAtajos() {
    document.addEventListener('keydown', function (e) {
      // F5 - Ir al buscador
      if (e.key === 'F5') {
        e.preventDefault();
        const searchInput = $('buscarProducto');
        if (searchInput && !searchInput.classList.contains('is-hidden')) {
          searchInput.focus();
          searchInput.select();
        }
      }

      // Escape - Cancelar selección o cerrar modal
      if (e.key === 'Escape') {
        const formConteo = $('formConteo');
        if (formConteo && !formConteo.classList.contains('is-hidden')) {
          cancelarSeleccion();
        }
      }
    });
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // MODAL DE AYUDA
  // ═══════════════════════════════════════════════════════════════════════════
  function initModalAyuda() {
    const btnAyuda = $('btnAyuda');
    const modalAyuda = $('modalAyuda');

    if (btnAyuda && modalAyuda) {
      btnAyuda.addEventListener('click', function () {
        modalAyuda.showModal();
      });
    }
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // ESTILOS DINÁMICOS PARA NOTIFICACIÓN
  // ═══════════════════════════════════════════════════════════════════════════
  function injectStyles() {
    if (document.getElementById('inv-dynamic-styles')) return;

    const style = document.createElement('style');
    style.id = 'inv-dynamic-styles';
    style.textContent = `
      .inv-notificacion {
        position: fixed;
        bottom: 20px;
        right: 20px;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.875rem 1.25rem;
        background: var(--panel, #fff);
        border: 1px solid var(--panel-border, #e2e8f0);
        border-radius: 10px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.15);
        font-size: 0.875rem;
        font-weight: 600;
        z-index: 10000;
        transform: translateY(100px);
        opacity: 0;
        transition: all 0.3s ease;
      }
      .inv-notificacion.show {
        transform: translateY(0);
        opacity: 1;
      }
      .inv-notificacion--success {
        border-color: rgba(34, 197, 94, 0.3);
        color: #16a34a;
      }
      .inv-notificacion--error {
        border-color: rgba(239, 68, 68, 0.3);
        color: #dc2626;
      }
      [data-theme="dark"] .inv-notificacion--success { color: #4ade80; }
      [data-theme="dark"] .inv-notificacion--error { color: #f87171; }
      
      .search-result-item.selected {
        background: var(--inv-bg-subtle, #f8fafc);
      }
      
      @keyframes spin {
        to { transform: rotate(360deg); }
      }
      .spinner {
        display: inline-block;
        width: 14px;
        height: 14px;
        border: 2px solid currentColor;
        border-right-color: transparent;
        border-radius: 50%;
        animation: spin 0.6s linear infinite;
      }
    `;
    document.head.appendChild(style);
  }

  // ═══════════════════════════════════════════════════════════════════════════
  // INICIALIZACIÓN
  // ═══════════════════════════════════════════════════════════════════════════
  function confirmInPage(title, message, form) {
    return new Promise((resolve) => {
      const overlay = document.createElement('div');
      overlay.className = 'inv-confirm-overlay';
      overlay.innerHTML = `
        <div class="inv-confirm-modal" role="dialog" aria-modal="true">
          <h3>${escapeHtml(title)}</h3>
          <p>${escapeHtml(message)}</p>
          <div class="inv-confirm-actions">
            <button type="button" class="btn btn-secondary" data-action="cancel">Cancelar</button>
            <button type="button" class="btn btn-warning" data-action="confirm">${escapeHtml(form.dataset.confirmText || 'Confirmar')}</button>
          </div>
        </div>
      `;
      const finish = (ok) => {
        overlay.remove();
        resolve(ok);
      };
      overlay.addEventListener('click', (event) => {
        if (event.target === overlay || event.target?.dataset?.action === 'cancel') finish(false);
        if (event.target?.dataset?.action === 'confirm') finish(true);
      });
      document.body.appendChild(overlay);
      overlay.querySelector('[data-action="cancel"]')?.focus();
    });
  }
  function initConfirmForms() {
    document.querySelectorAll('.js-inv-confirm-form').forEach((form) => {
      if (form.dataset.confirmBound === '1') return;
      form.dataset.confirmBound = '1';

      form.addEventListener('submit', async (event) => {
        if (form.dataset.confirmSubmitting === '1') return;

        const title = form.dataset.confirmTitle || 'Confirmar accion';
        const message = form.dataset.confirmMessage || 'Queres continuar?';
        let ok = true;

        event.preventDefault();

        if (window.Notif && typeof window.Notif.confirmar === 'function') {
          ok = await window.Notif.confirmar(
            title,
            `<p>${escapeHtml(message)}</p>`,
            {
              icon: form.dataset.confirmIcon || 'question',
              confirmText: form.dataset.confirmText || 'Confirmar',
              cancelText: form.dataset.cancelText || 'Cancelar',
              confirmColor: form.dataset.confirmColor || undefined,
            }
          );
        } else {
          ok = await confirmInPage(title, message, form);
        }

        if (!ok) return;
        form.dataset.confirmSubmitting = '1';
        form.submit();
      });
    });
  }
  function init() {
    injectStyles();
    cargarProductosContados();
    initModos();
    initBusqueda();
    applySearchPrefill();
    initPreviewDiferencia();
    initFormSubmit();
    initFiltroTabla();
    initAtajos();
    initModalAyuda();
    initConfirmForms();

    console.log('📋 Inventario Físico v2.0 iniciado');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

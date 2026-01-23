/* ============================================================================
   FLUS - STOCK.JS v5.0 (Refactorizado)
   - Consistente con ProductosManager/VentasManager
   - beforeunload cuando modal abierto
   - Keyboard hints
   - Filtros activos removibles
   - Actualización inline sin reload
   - Confirmación para ajustes grandes
============================================================================ */

const StockManager = {
  // ============================================
  // ESTADO
  // ============================================
  state: {
    currentIsPesable: false,
    currentProductoId: null,
    currentStockActual: 0,
    pendingFormData: null,
    modalOpen: false, // Para beforeunload
  },

  // ============================================
  // CONFIGURACIÓN
  // ============================================
  config: {
    CONFIRM_THRESHOLD: 50,
    ALERTS_SHOWN_KEY: 'stock_alerts_shown_session',
    TOAST_DURATION: 3500,
  },

  // ============================================
  // INICIALIZACIÓN
  // ============================================
  init() {
    console.log('[StockManager] Inicializando v5.0...');

    this.setupEventListeners();
    this.setupKeyboardShortcuts();
    this.setupKPIClickFilters();
    this.setupFiltrosRemove();
    this.setupBeforeUnload();
    this.checkLowStockAlerts();

    console.log('[StockManager] ✓ Inicialización completa');
  },

  // ============================================
  // EVENT LISTENERS
  // ============================================
  setupEventListeners() {
    const limitSel = document.getElementById('limitSel');
    const filtersForm = document.getElementById('stockFilters');

    // Cambio de límite: resetea página
    limitSel?.addEventListener('change', () => {
      if (!filtersForm) return;
      const pageInput = filtersForm.querySelector('input[name="page"]');
      if (pageInput) pageInput.value = '1';
      filtersForm.submit();
    });

    // Click fuera del modal cierra
    const modal = document.getElementById('modalAjusteStock');
    modal?.addEventListener('click', (e) => {
      if (e.target === modal) this.closeModal();
    });

    const modalConfirm = document.getElementById('modalConfirmacion');
    modalConfirm?.addEventListener('click', (e) => {
      if (e.target === modalConfirm) this.closeConfirmModal();
    });

    // Ajustar step/min cuando cambia el tipo
    const tipoSel = document.getElementById('ajuste_tipo');
    tipoSel?.addEventListener('change', () => {
      this.syncCantidadInput();
      this.updateCantidadHint();
    });

    // Contador de caracteres en motivo
    const motivoInput = document.getElementById('ajuste_motivo');
    const motivoChars = document.getElementById('motivo_chars');
    motivoInput?.addEventListener('input', () => {
      if (motivoChars) {
        motivoChars.textContent = motivoInput.value.length;
      }
    });

    // Actualizar hint cuando cambia cantidad
    const cantidadInput = document.getElementById('ajuste_cantidad');
    cantidadInput?.addEventListener('input', () => this.updateCantidadHint());
  },

  // ============================================
  // KEYBOARD SHORTCUTS
  // ============================================
  setupKeyboardShortcuts() {
    document.addEventListener('keydown', (e) => {
      // Ctrl/Cmd + K: Focus búsqueda
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        const search = document.querySelector('input[name="q"]');
        search?.focus();
        search?.select();
      }

      // ESC: cerrar modales
      if (e.key === 'Escape') {
        this.closeModal();
        this.closeConfirmModal();
      }
    });

    // Mostrar keyboard hints brevemente al cargar
    setTimeout(() => {
      const hints = document.getElementById('keyboardHints');
      if (hints) {
        hints.classList.add('show');
        setTimeout(() => hints.classList.remove('show'), 4000);
      }
    }, 1000);
  },

  // ============================================
  // KPIs CLICKEABLES
  // ============================================
  setupKPIClickFilters() {
    document.querySelectorAll('.stat-clickable').forEach(card => {
      card.style.cursor = 'pointer';
      card.addEventListener('click', () => {
        const estado = card.dataset.filterEstado;
        const url = new URL(window.location.href);
        url.searchParams.set('tab', 'general');
        url.searchParams.set('page', '1');
        
        if (estado) {
          url.searchParams.set('estado', estado);
        } else {
          url.searchParams.delete('estado');
        }
        
        window.location.href = url.toString();
      });
    });
  },

  // ============================================
  // FILTROS REMOVIBLES (NUEVO)
  // ============================================
  setupFiltrosRemove() {
    document.querySelectorAll('.filtro-remove').forEach(btn => {
      btn.addEventListener('click', () => {
        const filter = btn.dataset.filter;
        const url = new URL(window.location.href);

        switch (filter) {
          case 'q':
            url.searchParams.delete('q');
            break;
          case 'estado':
            url.searchParams.delete('estado');
            break;
          case 'categoria':
            url.searchParams.delete('categoria');
            break;
          case 'proveedor':
            url.searchParams.delete('proveedor');
            break;
        }

        url.searchParams.set('page', '1');
        window.location.href = url.toString();
      });
    });
  },

  // ============================================
  // BEFORE UNLOAD (NUEVO)
  // ============================================
  setupBeforeUnload() {
    window.addEventListener('beforeunload', (e) => {
      if (this.state.modalOpen) {
        e.preventDefault();
        e.returnValue = '';
        return '';
      }
    });
  },

  // ============================================
  // ALERTAS DE STOCK BAJO
  // ============================================
  checkLowStockAlerts() {
    if (sessionStorage.getItem(this.config.ALERTS_SHOWN_KEY)) {
      return;
    }

    const bajoStock = document.querySelector('.stat-bajo .stat-value');
    const sinStock = document.querySelector('.stat-sin .stat-value');

    const bajo = parseInt(bajoStock?.textContent || '0', 10);
    const sin = parseInt(sinStock?.textContent || '0', 10);

    if (sin > 0) {
      this.showToast(`⚠️ Hay ${sin} producto${sin > 1 ? 's' : ''} sin stock`, 'warning', 5000);
      sessionStorage.setItem(this.config.ALERTS_SHOWN_KEY, 'true');
    } else if (bajo > 0) {
      this.showToast(`ℹ️ Hay ${bajo} producto${bajo > 1 ? 's' : ''} con bajo stock`, 'info', 3500);
      sessionStorage.setItem(this.config.ALERTS_SHOWN_KEY, 'true');
    }
  },

  // ============================================
  // MODAL: ABRIR
  // ============================================
  quickAdjust(productoId, productoNombre, esPesable, stockActualRaw, stockMinimoRaw, stockActualDisplay, stockMinimoDisplay) {
    const modal = document.getElementById('modalAjusteStock');
    const form = document.getElementById('formAjusteStock');

    if (!modal || !form) return;

    this.state.currentIsPesable = !!esPesable;
    this.state.currentProductoId = productoId;
    // Usar RAW para cálculos (evita problemas con formato local tipo "0,250")
    const stockRawNum = (typeof stockActualRaw === 'number')
      ? stockActualRaw
      : parseFloat(String(stockActualRaw ?? '').replace(',', '.'));
    this.state.currentStockActual = Number.isFinite(stockRawNum) ? stockRawNum : 0;
    this.state.modalOpen = true; // Para beforeunload

    // Reset form
    form.reset();
    document.getElementById('motivo_chars').textContent = '0';

    // Set producto_id
    const idInput = document.getElementById('ajuste_producto_id');
    if (idInput) idInput.value = String(productoId);

    // Render nombre (sin innerHTML para evitar XSS)
    const nameBox = document.getElementById('ajuste_producto_nombre');
    if (nameBox) {
      nameBox.replaceChildren();

      const strong = document.createElement('strong');
      strong.textContent = String(productoNombre ?? '');

      const small = document.createElement('small');
      small.className = 'text-muted';
      small.textContent = this.state.currentIsPesable ? ' (Pesable - KG)' : ' (Por unidad)';

      nameBox.appendChild(strong);
      nameBox.appendChild(small);
    }

    // Mostrar stock actual y mínimo
    const stockActualEl = document.getElementById('ajuste_stock_actual');
    const stockMinimoEl = document.getElementById('ajuste_stock_minimo');
    
    const stockActualTxt = (stockActualDisplay ?? stockActualRaw ?? '-');
    const stockMinTxt = (stockMinimoDisplay ?? stockMinimoRaw ?? '-');

    if (stockActualEl) stockActualEl.textContent = stockActualTxt;
    if (stockMinimoEl) stockMinimoEl.textContent = stockMinTxt;

    // Sync step/min según pesable
    this.syncCantidadInput();
    this.updateCantidadHint();

    // Show modal
    modal.classList.add('show');

    // Focus cantidad
    setTimeout(() => {
      document.getElementById('ajuste_cantidad')?.focus();
    }, 100);
  },

  // ============================================
  // MODAL: CERRAR
  // ============================================
  closeModal() {
    document.getElementById('modalAjusteStock')?.classList.remove('show');
    this.state.pendingFormData = null;
    this.state.modalOpen = false; // Para beforeunload
  },

  closeConfirmModal() {
    document.getElementById('modalConfirmacion')?.classList.remove('show');
  },

  // ============================================
  // SYNC INPUT CANTIDAD
  // ============================================
  syncCantidadInput() {
    const cantidadInput = document.getElementById('ajuste_cantidad');
    if (!cantidadInput) return;

    if (this.state.currentIsPesable) {
      cantidadInput.step = '0.001';
      cantidadInput.min = '0.001';
      cantidadInput.placeholder = 'Ej: 0.250';
    } else {
      cantidadInput.step = '1';
      cantidadInput.min = '1';
      cantidadInput.placeholder = 'Ej: 1';
    }
  },

  // ============================================
  // PREVIEW RESULTADO
  // ============================================
  updateCantidadHint() {
    const hintEl = document.getElementById('ajuste_cantidad_hint');
    if (!hintEl) return;

    const tipo = document.getElementById('ajuste_tipo')?.value || '';
    const cantidadStr = document.getElementById('ajuste_cantidad')?.value || '';
    const cantidad = parseFloat(cantidadStr);

    if (!cantidadStr || isNaN(cantidad) || cantidad <= 0) {
      hintEl.textContent = '';
      return;
    }

    const stockActual = this.state.currentStockActual;
    const esNegativo = ['salida', 'ajuste_neg', 'perdida'].includes(tipo);
    const cambio = esNegativo ? -cantidad : cantidad;
    const nuevoStock = stockActual + cambio;

    if (nuevoStock < 0) {
      hintEl.textContent = `⚠️ El stock quedaría negativo (${nuevoStock.toFixed(this.state.currentIsPesable ? 3 : 0)})`;
      hintEl.className = 'form-hint text-danger';
    } else {
      hintEl.textContent = `→ Stock resultante: ${nuevoStock.toFixed(this.state.currentIsPesable ? 3 : 0)}`;
      hintEl.className = 'form-hint text-muted';
    }
  },

  // ============================================
  // SUBMIT AJUSTE
  // ============================================
  async submitAdjust(event) {
    event.preventDefault();

    const form = event.target;

    // Validaciones front
    const tipo = (document.getElementById('ajuste_tipo')?.value || '').trim();
    const cantidadStr = (document.getElementById('ajuste_cantidad')?.value || '').trim();
    const cantidad = Number(cantidadStr);

    if (!tipo) {
      this.showToast('Seleccioná un tipo de ajuste.', 'warning');
      return;
    }
    if (!cantidadStr || Number.isNaN(cantidad) || cantidad <= 0) {
      this.showToast('Ingresá una cantidad válida (mayor a 0).', 'warning');
      return;
    }

    // Si NO es pesable, obligar enteros
    if (!this.state.currentIsPesable && !Number.isInteger(cantidad)) {
      this.showToast('Para productos por unidad, la cantidad debe ser entera.', 'warning');
      return;
    }

    // Verificar si el stock quedaría negativo
    const esNegativo = ['salida', 'ajuste_neg', 'perdida'].includes(tipo);
    if (esNegativo) {
      const nuevoStock = this.state.currentStockActual - cantidad;
      if (nuevoStock < 0) {
        this.showToast('El stock no puede quedar negativo.', 'error');
        return;
      }

      // Pedir confirmación para ajustes grandes
      if (cantidad >= this.config.CONFIRM_THRESHOLD) {
        this.state.pendingFormData = new FormData(form);
        this.state.pendingFormData.append('action', 'ajustar');
        
        const msgEl = document.getElementById('confirmacion_mensaje');
        if (msgEl) {
          msgEl.textContent = `Estás por registrar una ${tipo === 'perdida' ? 'pérdida' : 'salida'} de ${cantidad} unidades. ¿Confirmar?`;
        }
        
        document.getElementById('modalConfirmacion')?.classList.add('show');
        return;
      }
    }

    await this.executeAdjust(form);
  },

  // ============================================
  // CONFIRMAR AJUSTE GRANDE
  // ============================================
  async confirmarAjuste() {
    this.closeConfirmModal();
    
    if (!this.state.pendingFormData) return;

    const form = document.getElementById('formAjusteStock');
    await this.executeAdjust(form, this.state.pendingFormData);
    this.state.pendingFormData = null;
  },

  // ============================================
  // EJECUTAR AJUSTE
  // ============================================
  async executeAdjust(form, formData = null) {
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn?.textContent || 'Confirmar';

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Procesando...';
    }

    try {
      if (!formData) {
        formData = new FormData(form);
        formData.append('action', 'ajustar');
      }

      const response = await fetch('stock_ajax.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
      });

      const text = await response.text();
      let data = null;
      try {
        data = JSON.parse(text);
      } catch {
        // No es JSON
      }

      if (!response.ok) {
        const msg = (data && data.message) ? data.message : `Error HTTP ${response.status}`;
        throw new Error(msg);
      }

      if (!data || data.success !== true) {
        const msg = (data && data.message) ? data.message : 'Error al ajustar stock';
        throw new Error(msg);
      }

      // Éxito
      const extra = data.data || null;
      if (extra && (extra.stock_anterior !== undefined && extra.stock_nuevo !== undefined)) {
        this.showToast(
          `✓ Stock actualizado (${extra.stock_anterior} → ${extra.stock_nuevo})`,
          'success',
          3500
        );
      } else {
        this.showToast('✓ Stock actualizado correctamente', 'success', 3000);
      }

      this.closeModal();

      // Actualizar fila en lugar de reload
      if (extra) {
        this.updateRowInPlace(extra);
      }

    } catch (err) {
      console.error('Stock adjust error:', err);
      this.showToast(err?.message || 'Error al procesar la solicitud', 'error', 4500);

      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
      }
    }
  },

  // ============================================
  // ACTUALIZAR FILA IN-PLACE
  // ============================================
  updateRowInPlace(data) {
    const row = document.querySelector(`tr[data-id="${data.producto_id}"]`);
    if (!row) {
      window.location.href = window.location.href;
      return;
    }

    const currentTab = new URLSearchParams(window.location.search).get('tab') || 'general';
    const estadoAnterior = row.className.match(/row-(\w+)/)?.[1];
    const estadoNuevo = data.estado_nuevo;

    // 1. Actualizar valor de stock
    const stockValueEl = row.querySelector('.stock-value');
    if (stockValueEl) {
      stockValueEl.textContent = data.stock_nuevo;
    }

    // 2. Actualizar data attributes
    row.dataset.stock = data.stock_nuevo_raw;

    // 3. Actualizar barra de stock
    const barFill = row.querySelector('.stock-bar-fill');
    if (barFill) {
      barFill.style.width = `${data.stock_pct}%`;
      barFill.className = `stock-bar-fill stock-bar-${estadoNuevo}`;
    }

    // 4. Actualizar tag de estado
    const tag = row.querySelector('.tag');
    if (tag) {
      tag.className = `tag tag-${estadoNuevo}`;
      tag.textContent = estadoNuevo.charAt(0).toUpperCase() + estadoNuevo.slice(1);
    }

    // 5. Actualizar clase de fila
    row.className = row.className.replace(/row-\w+/, `row-${estadoNuevo}`);

    // 6. Actualizar parámetros del botón de ajuste
    const adjustBtn = row.querySelector('button[onclick*="quickAdjust"]');
    if (adjustBtn) {
      const nombre = data.producto_nombre || '';
      const esPesable = data.es_pesable ? 'true' : 'false';
      adjustBtn.setAttribute('onclick', 
        `StockManager.quickAdjust(${data.producto_id}, ${JSON.stringify(nombre)}, ${esPesable}, ${JSON.stringify(data.stock_nuevo_raw)}, ${JSON.stringify(String(row.dataset.stockMinimo ?? '0'))}, ${JSON.stringify(data.stock_nuevo)}, ${JSON.stringify(data.stock_minimo)})`
      );
    }

    // 7. Si el producto ya no corresponde a la vista/filtro actual, hacer fade-out
    const params = new URLSearchParams(window.location.search);
    const estadoFiltro = (currentTab !== 'alertas') ? (params.get('estado') || '') : '';

    let shouldRemove = false;
    let toastMsg = '';

    if (currentTab === 'alertas') {
      // En alertas solo deben quedar SIN y BAJO
      if (!['sin', 'bajo'].includes(estadoNuevo)) {
        shouldRemove = true;
        toastMsg = (estadoNuevo === 'ok')
          ? '✓ Producto movido a stock OK'
          : '✓ Producto ya no está en alertas';
      }
    } else if (estadoFiltro) {
      if (estadoNuevo !== estadoFiltro) {
        shouldRemove = true;
        toastMsg = '✓ Producto ya no coincide con el filtro';
      }
    }

    if (shouldRemove) {
      row.classList.add('row-fading-out');
      setTimeout(() => {
        row.remove();
        this.updateResultsCount();
        if (toastMsg) this.showToast(toastMsg, 'success', 2000);
      }, 500);
    } else {
      // Highlight temporal
      row.classList.add('row-updated');
      setTimeout(() => row.classList.remove('row-updated'), 2500);
    }

    // 8. Actualizar KPIs si hay cambio de estado
    if (estadoAnterior !== estadoNuevo) {
      this.updateKPICount(estadoAnterior, -1);
      this.updateKPICount(estadoNuevo, 1);
    }
  },

  // ============================================
  // ACTUALIZAR KPIs
  // ============================================
  updateKPICount(estado, delta) {
    const mapping = {
      'ok': '.stat-ok .stat-value',
      'bajo': '.stat-bajo .stat-value',
      'sin': '.stat-sin .stat-value',
      'inactivo': '.stat-inactivo .stat-value',
    };

    const selector = mapping[estado];
    if (!selector) return;

    const el = document.querySelector(selector);
    if (el) {
      const current = parseInt(el.textContent || '0', 10);
      el.textContent = Math.max(0, current + delta);
    }

    if (estado === 'bajo' || estado === 'sin') {
      this.updateAlertBadge();
    }
  },

  updateAlertBadge() {
    const bajo = parseInt(document.querySelector('.stat-bajo .stat-value')?.textContent || '0', 10);
    const sin = parseInt(document.querySelector('.stat-sin .stat-value')?.textContent || '0', 10);
    const total = bajo + sin;

    const badge = document.querySelector('.tab-badge');
    if (badge) {
      if (total > 0) {
        badge.textContent = total;
        badge.style.display = '';
      } else {
        badge.style.display = 'none';
      }
    }
  },

  updateResultsCount() {
    const tbody = document.getElementById('stockTableBody');
    const resultsInfo = document.querySelector('.results-info');

    if (!tbody || !resultsInfo) return;

    const visibleRows = tbody.querySelectorAll('tr:not(.row-fading-out)').length;

    // Si la página quedó vacía (último item removido), recargar es lo más seguro
    if (visibleRows === 0) {
      window.location.reload();
      return;
    }

    const text = (resultsInfo.textContent || '').trim();

    // Formato esperado: "Mostrando X-Y de Z productos"
    const m = text.match(/Mostrando\s+([\d,]+)\s*-\s*([\d,]+)\s+de\s+([\d,]+)\s+productos/i);
    if (!m) return;

    const from = parseInt(m[1].replace(/,/g, ''), 10);
    const totalOld = parseInt(m[3].replace(/,/g, ''), 10);

    const total = Math.max(0, totalOld - 1);
    const to = Math.min(total, from + visibleRows - 1);

    resultsInfo.textContent = `Mostrando ${from.toLocaleString()}-${to.toLocaleString()} de ${total.toLocaleString()} productos`;
  },

  // ============================================
  // REFRESH PAGE
  // ============================================
  refreshPage() {
    sessionStorage.removeItem(this.config.ALERTS_SHOWN_KEY);
    window.location.href = window.location.href;
  },

  // ============================================
  // TOAST SYSTEM
  // ============================================
  showToast(message, type = 'info', duration = null) {
    const container = document.getElementById('toastContainer');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;

    const icons = { success: '✓', error: '✕', warning: '⚠', info: 'ℹ' };

    const icon = document.createElement('span');
    icon.className = 'toast-icon';
    icon.textContent = icons[type] || icons.info;

    const msg = document.createElement('span');
    msg.className = 'toast-message';
    msg.textContent = String(message ?? '');

    toast.appendChild(icon);
    toast.appendChild(msg);
    container.appendChild(toast);

    requestAnimationFrame(() => toast.classList.add('toast-show'));

    const ms = duration ?? this.config.TOAST_DURATION;
    setTimeout(() => {
      toast.classList.remove('toast-show');
      setTimeout(() => toast.remove(), 250);
    }, ms);
  },
};

// Hacer global para onclick="StockManager..."
window.StockManager = StockManager;

document.addEventListener('DOMContentLoaded', () => StockManager.init());

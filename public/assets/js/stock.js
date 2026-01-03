/* ============================================================================
   FLUS - STOCK.JS (V1)
   - Ajuste rápido de stock con modal
   - Notificaciones toast
   - Atajos teclado
   - Manejo robusto de errores (JSON / no JSON)
   - Evita XSS (sin innerHTML con datos)
   - ✅ Compatible con onclick="StockManager..."
============================================================================ */

const StockManager = {
  state: {
    currentIsPesable: false,
  },

  init() {
    this.setupEventListeners();
    this.setupKeyboardShortcuts();
    this.checkLowStockAlerts();
  },

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

    // ESC cierra modal
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') this.closeModal();
    });

    // Click fuera del modal cierra
    const modal = document.getElementById('modalAjusteStock');
    modal?.addEventListener('click', (e) => {
      if (e.target === modal) this.closeModal();
    });

    // Ajustar step/min cuando cambia el tipo
    const tipoSel = document.getElementById('ajuste_tipo');
    tipoSel?.addEventListener('change', () => this.syncCantidadInput());
  },

  setupKeyboardShortcuts() {
    document.addEventListener('keydown', (e) => {
      // Ctrl/Cmd + K: Focus búsqueda
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        document.querySelector('input[name="q"]')?.focus();
      }
    });
  },

  checkLowStockAlerts() {
    const bajoStock = document.querySelector('.stat-bajo .stat-value');
    const sinStock = document.querySelector('.stat-sin .stat-value');

    const bajo = parseInt(bajoStock?.textContent || '0', 10);
    const sin  = parseInt(sinStock?.textContent || '0', 10);

    if (sin > 0) {
      this.showToast(`⚠️ Hay ${sin} producto${sin > 1 ? 's' : ''} sin stock`, 'warning', 5000);
    } else if (bajo > 0) {
      this.showToast(`ℹ️ Hay ${bajo} producto${bajo > 1 ? 's' : ''} con bajo stock`, 'info', 3500);
    }
  },

  // --- Modal: abrir ---
  quickAdjust(productoId, productoNombre, esPesable) {
    const modal = document.getElementById('modalAjusteStock');
    const form  = document.getElementById('formAjusteStock');

    if (!modal || !form) return;

    this.state.currentIsPesable = !!esPesable;

    // Reset form (mantiene csrf_token porque está en el HTML como hidden)
    form.reset();

    // Set producto_id
    const idInput = document.getElementById('ajuste_producto_id');
    if (idInput) idInput.value = String(productoId);

    // Render nombre SIN innerHTML (evita XSS / roturas)
    const nameBox = document.getElementById('ajuste_producto_nombre');
    if (nameBox) {
      nameBox.replaceChildren();

      const strong = document.createElement('strong');
      strong.textContent = String(productoNombre ?? '');

      const small = document.createElement('small');
      small.className = 'text-muted';
      small.textContent = this.state.currentIsPesable ? '(Pesable - KG)' : '(Por unidad)';

      nameBox.appendChild(strong);
      nameBox.appendChild(small);
    }

    // Sync step/min según pesable
    this.syncCantidadInput();

    // Show modal
    modal.classList.add('show');

    // Focus cantidad
    setTimeout(() => {
      document.getElementById('ajuste_cantidad')?.focus();
    }, 80);
  },

  // --- Placeholder ---
  openBulkAdjust() {
    this.showToast('Función de ajuste masivo en desarrollo', 'info');
  },

  closeModal() {
    document.getElementById('modalAjusteStock')?.classList.remove('show');
  },

  // Ajusta min/step según si es pesable
  syncCantidadInput() {
    const cantidadInput = document.getElementById('ajuste_cantidad');
    if (!cantidadInput) return;

    if (this.state.currentIsPesable) {
      cantidadInput.step = '0.001';
      cantidadInput.min  = '0.001';
      cantidadInput.placeholder = 'Ej: 0.250';
    } else {
      cantidadInput.step = '1';
      cantidadInput.min  = '1';
      cantidadInput.placeholder = 'Ej: 1';
    }
  },

  // --- Submit ---
  async submitAdjust(event) {
    event.preventDefault();

    const form = event.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn?.textContent || 'Confirmar';

    // Validaciones front mínimas
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

    // Disable botón
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Procesando...';
    }

    try {
      const formData = new FormData(form);
      formData.append('action', 'ajustar');

      const response = await fetch('stock_ajax.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
      });

      // Puede venir HTML (403/500) -> no asumir JSON
      const text = await response.text();
      let data = null;
      try { data = JSON.parse(text); } catch { /* no json */ }

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
      if (extra && (extra.stock_anterior || extra.stock_nuevo)) {
        this.showToast(
          `✓ Stock actualizado (${extra.stock_anterior ?? '?'} → ${extra.stock_nuevo ?? '?'})`,
          'success',
          3500
        );
      } else {
        this.showToast('✓ Stock actualizado correctamente', 'success', 3000);
      }

      this.closeModal();

      // Reload simple para V1
      setTimeout(() => window.location.reload(), 700);

    } catch (err) {
      console.error('Stock adjust error:', err);
      this.showToast(err?.message || 'Error al procesar la solicitud', 'error', 4500);

      // Re-enable
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
      }
    }
  },

  // --- Toast ---
  showToast(message, type = 'info', duration = 3000) {
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

    // Animar entrada
    requestAnimationFrame(() => toast.classList.add('toast-show'));

    // Salida
    setTimeout(() => {
      toast.classList.remove('toast-show');
      setTimeout(() => toast.remove(), 250);
    }, duration);
  },
};

// ✅ IMPORTANTÍSIMO: hacer global para que funcione con onclick="StockManager..."
window.StockManager = StockManager;

document.addEventListener('DOMContentLoaded', () => StockManager.init());

/* ============================================================================
   FLUS - VENTAS.JS v5.0 (Refactorizado)
   - Objeto único VentasManager (consistente con ProductosManager/StockManager)
   - Sistema de Toasts (no más alert())
   - KPIs clickeables
   - Atajos de teclado completos
   - Protección XSS
   - Autocomplete clientes mejorado
============================================================================ */

const VentasManager = {
  // ============================================
  // ESTADO
  // ============================================
  state: {
    chartsInitialized: false,
    selectedClienteId: null,
    searchTimeout: null,
    paperSize: '80',
  },

  // ============================================
  // CONFIGURACIÓN
  // ============================================
  config: {
    PAPER_KEY: 'flus-paper',
    DEBOUNCE_SEARCH_MS: 300,
    TOAST_DURATION: 3500,
  },

  // ============================================
  // INICIALIZACIÓN
  // ============================================
  init() {
    console.log('[VentasManager] Inicializando...');

    this.loadPaperSize();
    this.bindPaperSelect();
    this.bindChartsToggle();
    this.bindAdvancedFilters();
    this.bindHoraAmpmSelects();
    this.bindClienteAutocomplete();
    this.bindChipsRapidos();
    this.bindFiltrosRemove();
    this.bindKPIClicks();
    this.bindPreviewModal();
    this.bindTicketModal();
    this.bindShareButtons();
    this.bindKeyboardShortcuts();

    console.log('[VentasManager] ✓ Inicialización completa');
  },

  // ============================================
  // UTILIDADES
  // ============================================
  escapeHtml(text) {
    if (text == null) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
  },

  // ============================================
  // SISTEMA DE TOASTS
  // ============================================
  showToast(message, type = 'info', duration = null) {
    const container = document.getElementById('toastContainer') || this.createToastContainer();
    
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
    
    requestAnimationFrame(() => toast.classList.add('show'));

    const ms = duration ?? this.config.TOAST_DURATION;
    setTimeout(() => {
      toast.classList.remove('show');
      setTimeout(() => toast.remove(), 300);
    }, ms);
  },

  createToastContainer() {
    const container = document.createElement('div');
    container.id = 'toastContainer';
    container.className = 'toast-container';
    document.body.appendChild(container);
    return container;
  },

  // ============================================
  // PAPER SIZE
  // ============================================
  loadPaperSize() {
    try {
      this.state.paperSize = localStorage.getItem(this.config.PAPER_KEY) || '80';
    } catch {
      this.state.paperSize = '80';
    }
  },

  savePaperSize(value) {
    this.state.paperSize = value;
    try {
      localStorage.setItem(this.config.PAPER_KEY, value);
    } catch {}
  },

  bindPaperSelect() {
    const paperSel = document.getElementById('paperSel');
    if (!paperSel) return;

    paperSel.value = this.state.paperSize;
    paperSel.addEventListener('change', () => {
      this.savePaperSize(paperSel.value);
    });
  },

  // ============================================
  // GRÁFICOS
  // ============================================
  bindChartsToggle() {
    const btnCharts = document.getElementById('btnCharts');
    const chartsPanel = document.getElementById('chartsPanel');

    if (!btnCharts || !chartsPanel) return;

    btnCharts.addEventListener('click', () => {
      chartsPanel.classList.toggle('hidden');
      if (!this.state.chartsInitialized && !chartsPanel.classList.contains('hidden')) {
        this.initCharts();
        this.state.chartsInitialized = true;
      }
    });
  },

  initCharts() {
    if (typeof Chart === 'undefined' || !window.VENTAS_DATA) return;

    const data = window.VENTAS_DATA;

    // Chart ventas por día
    const ctxVentas = document.getElementById('chartVentas');
    if (ctxVentas && data.chartVentas.labels.length) {
      new Chart(ctxVentas, {
        type: 'bar',
        data: {
          labels: data.chartVentas.labels,
          datasets: [{
            label: 'Ventas',
            data: data.chartVentas.ventas,
            backgroundColor: 'rgba(6, 182, 212, 0.7)',
            borderRadius: 6
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 } }
          }
        }
      });
    }

    // Chart medios de pago
    const ctxMedios = document.getElementById('chartMedios');
    if (ctxMedios && data.chartMedios.labels.length) {
      new Chart(ctxMedios, {
        type: 'doughnut',
        data: {
          labels: data.chartMedios.labels,
          datasets: [{
            data: data.chartMedios.data,
            backgroundColor: data.chartMedios.colors,
            borderWidth: 0
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'bottom' }
          }
        }
      });
    }
  },

  // ============================================
  // FILTROS AVANZADOS
  // ============================================
  bindAdvancedFilters() {
    const btnMore = document.getElementById('btnMoreFilters');
    const advFilters = document.getElementById('advancedFilters');

    if (!btnMore || !advFilters) return;

    // Verificar si hay filtros avanzados activos
    const hasAdvanced = advFilters.querySelectorAll('input, select');
    let hasValue = false;
    hasAdvanced.forEach(el => {
      if (el.value) hasValue = true;
    });
    if (hasValue) advFilters.classList.remove('hidden');

    btnMore.addEventListener('click', () => {
      advFilters.classList.toggle('hidden');
      btnMore.textContent = advFilters.classList.contains('hidden') ? '+ Filtros' : '- Filtros';
    });
  },

  // ============================================
  // SELECTORES HORA AM/PM
  // ============================================
  bindHoraAmpmSelects() {
    const form = document.getElementById('ventasForm');
    if (!form) return;

    // Elementos para hora_desde
    const horaDesdeHora = document.getElementById('horaDesdeHora');
    const horaDesdeMin = document.getElementById('horaDesdeMin');
    const horaDesdeAmpm = document.getElementById('horaDesdeAmpm');
    const horaDesdeHidden = document.getElementById('horaDesdeHidden');

    // Elementos para hora_hasta
    const horaHastaHora = document.getElementById('horaHastaHora');
    const horaHastaMin = document.getElementById('horaHastaMin');
    const horaHastaAmpm = document.getElementById('horaHastaAmpm');
    const horaHastaHidden = document.getElementById('horaHastaHidden');

    // Cargar valores iniciales desde hidden inputs (formato 24h -> 12h AM/PM)
    if (horaDesdeHidden?.value) {
      this.loadAmpmFromTime(horaDesdeHidden.value, horaDesdeHora, horaDesdeMin, horaDesdeAmpm);
    }
    if (horaHastaHidden?.value) {
      this.loadAmpmFromTime(horaHastaHidden.value, horaHastaHora, horaHastaMin, horaHastaAmpm);
    }

    // Actualizar hidden inputs cuando cambian los selects
    const updateDesde = () => {
      if (horaDesdeHidden) {
        horaDesdeHidden.value = this.ampmToTime(horaDesdeHora?.value, horaDesdeMin?.value, horaDesdeAmpm?.value);
      }
    };
    const updateHasta = () => {
      if (horaHastaHidden) {
        horaHastaHidden.value = this.ampmToTime(horaHastaHora?.value, horaHastaMin?.value, horaHastaAmpm?.value);
      }
    };

    horaDesdeHora?.addEventListener('change', updateDesde);
    horaDesdeMin?.addEventListener('change', updateDesde);
    horaDesdeAmpm?.addEventListener('change', updateDesde);
    horaHastaHora?.addEventListener('change', updateHasta);
    horaHastaMin?.addEventListener('change', updateHasta);
    horaHastaAmpm?.addEventListener('change', updateHasta);

    // Asegurar que se actualicen antes de enviar el form
    form.addEventListener('submit', () => {
      updateDesde();
      updateHasta();
    });
  },

  // Convertir formato 24h (HH:MM) a selectores 12h AM/PM
  loadAmpmFromTime(time24, horaSelect, minSelect, ampmSelect) {
    if (!time24 || !horaSelect || !minSelect || !ampmSelect) return;
    
    const [h, m] = time24.split(':').map(Number);
    
    let hora12 = h % 12;
    if (hora12 === 0) hora12 = 12;
    const ampm = h < 12 ? 'AM' : 'PM';
    
    // Redondear minutos al más cercano disponible (00, 15, 30, 45)
    const mins = ['00', '15', '30', '45'];
    const minStr = String(m).padStart(2, '0');
    const closestMin = mins.reduce((prev, curr) => 
      Math.abs(parseInt(curr) - m) < Math.abs(parseInt(prev) - m) ? curr : prev
    );
    
    horaSelect.value = hora12;
    minSelect.value = closestMin;
    ampmSelect.value = ampm;
  },

  // Convertir selectores 12h AM/PM a formato 24h (HH:MM)
  ampmToTime(hora, min, ampm) {
    if (!hora || hora === '') return '';
    
    let h = parseInt(hora);
    const m = min || '00';
    
    if (ampm === 'PM' && h !== 12) {
      h += 12;
    } else if (ampm === 'AM' && h === 12) {
      h = 0;
    }
    
    return String(h).padStart(2, '0') + ':' + m;
  },

  // ============================================
  // AUTOCOMPLETE CLIENTES
  // ============================================
  bindClienteAutocomplete() {
    const clienteInput = document.getElementById('clienteSearch');
    const clienteIdInput = document.getElementById('clienteIdHidden');
    const clienteDropdown = document.getElementById('clienteDropdown');
    const btnClear = document.getElementById('btnClearCliente');

    if (!clienteInput || !clienteDropdown) return;

    this.state.selectedClienteId = clienteIdInput?.value || null;

    // Input handler con debounce
    clienteInput.addEventListener('input', () => {
      const q = clienteInput.value.trim();

      if (q === '') {
        if (clienteIdInput) clienteIdInput.value = '';
        this.state.selectedClienteId = null;
        this.hideClienteDropdown();
        return;
      }

      clearTimeout(this.state.searchTimeout);
      this.state.searchTimeout = setTimeout(() => this.searchClientes(q), this.config.DEBOUNCE_SEARCH_MS);
    });

    // Click fuera cierra dropdown
    document.addEventListener('click', (e) => {
      if (!clienteInput.contains(e.target) && !clienteDropdown.contains(e.target)) {
        this.hideClienteDropdown();
      }
    });

    // Keyboard navigation
    clienteInput.addEventListener('keydown', (e) => {
      const items = clienteDropdown.querySelectorAll('.cliente-item');
      const active = clienteDropdown.querySelector('.cliente-item.active');
      let idx = Array.from(items).indexOf(active);

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        idx = Math.min(idx + 1, items.length - 1);
        items.forEach((it, i) => it.classList.toggle('active', i === idx));
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        idx = Math.max(idx - 1, 0);
        items.forEach((it, i) => it.classList.toggle('active', i === idx));
      } else if (e.key === 'Enter' && active) {
        e.preventDefault();
        this.selectCliente(active.dataset.id, active.dataset.nombre);
      } else if (e.key === 'Escape') {
        this.hideClienteDropdown();
      }
    });

    // Botón limpiar
    if (btnClear) {
      btnClear.addEventListener('click', () => {
        clienteInput.value = '';
        if (clienteIdInput) clienteIdInput.value = '';
        this.state.selectedClienteId = null;
        this.hideClienteDropdown();
      });
    }
  },

  async searchClientes(q) {
    if (q.length < 2) {
      this.hideClienteDropdown();
      return;
    }

    try {
      const res = await fetch(`api/ventas_api.php?action=buscar_clientes&q=${encodeURIComponent(q)}`);
      const data = await res.json();

      if (data.success && data.clientes.length > 0) {
        this.showClienteDropdown(data.clientes);
      } else {
        this.showClienteDropdown([], 'No se encontraron clientes');
      }
    } catch (e) {
      console.error('Error buscando clientes:', e);
      this.hideClienteDropdown();
    }
  },

  showClienteDropdown(clientes, emptyMsg = '') {
    const dropdown = document.getElementById('clienteDropdown');
    if (!dropdown) return;

    if (clientes.length === 0) {
      dropdown.innerHTML = `<div class="cliente-empty">${this.escapeHtml(emptyMsg)}</div>`;
    } else {
      dropdown.innerHTML = clientes.map((c, i) => {
        // Escape seguro para atributos HTML
        const safeNombre = this.escapeHtml(c.nombre || '').replace(/"/g, '&quot;');
        return `
        <div class="cliente-item ${i === 0 ? 'active' : ''}" 
             data-id="${c.id}" 
             data-nombre="${safeNombre}">
          <span class="cliente-nombre">${this.escapeHtml(c.nombre)}</span>
          ${c.documento ? `<span class="cliente-doc">${this.escapeHtml(c.documento)}</span>` : ''}
        </div>
      `;
      }).join('');

      // Click en items
      dropdown.querySelectorAll('.cliente-item').forEach(item => {
        item.addEventListener('click', () => {
          this.selectCliente(item.dataset.id, item.dataset.nombre);
        });
      });
    }

    dropdown.classList.add('show');
  },

  hideClienteDropdown() {
    const dropdown = document.getElementById('clienteDropdown');
    if (dropdown) dropdown.classList.remove('show');
  },

  selectCliente(id, nombre) {
    const clienteInput = document.getElementById('clienteSearch');
    const clienteIdInput = document.getElementById('clienteIdHidden');

    if (clienteInput) clienteInput.value = nombre;
    if (clienteIdInput) clienteIdInput.value = id;
    this.state.selectedClienteId = id;

    this.hideClienteDropdown();
  },

  // ============================================
  // CHIPS RÁPIDOS
  // ============================================
  bindChipsRapidos() {
    const form = document.getElementById('ventasForm');
    const desdeInput = document.querySelector('input[name="desde"]');
    const hastaInput = document.querySelector('input[name="hasta"]');
    const horaDesdeHidden = document.getElementById('horaDesdeHidden');
    const horaHastaHidden = document.getElementById('horaHastaHidden');
    const advFilters = document.getElementById('advancedFilters');

    // Chips de fecha
    document.querySelectorAll('.chip[data-range]').forEach(chip => {
      chip.addEventListener('click', () => {
        const range = chip.dataset.range;
        const today = new Date();
        let desde, hasta;

        switch (range) {
          case 'today':
            desde = hasta = this.formatDate(today);
            break;
          case 'yesterday':
            const yesterday = new Date(today);
            yesterday.setDate(yesterday.getDate() - 1);
            desde = hasta = this.formatDate(yesterday);
            break;
          case '7d':
            hasta = this.formatDate(today);
            const d7 = new Date(today);
            d7.setDate(d7.getDate() - 7);
            desde = this.formatDate(d7);
            break;
          case '30d':
            hasta = this.formatDate(today);
            const d30 = new Date(today);
            d30.setDate(d30.getDate() - 30);
            desde = this.formatDate(d30);
            break;
        }

        if (desdeInput) desdeInput.value = desde;
        if (hastaInput) hastaInput.value = hasta;

        const page = document.getElementById('hiddenPage');
        if (page) page.value = '1';

        if (form) form.submit();
      });
    });

    // Chips de hora
    document.querySelectorAll('.chip[data-hora]').forEach(chip => {
      chip.addEventListener('click', () => {
        const [h1, h2] = chip.dataset.hora.split(',');

        // Actualizar hidden inputs
        if (horaDesdeHidden) horaDesdeHidden.value = h1;
        if (horaHastaHidden) horaHastaHidden.value = h2;

        // Actualizar selectores visuales AM/PM
        this.loadAmpmFromTime(h1, 
          document.getElementById('horaDesdeHora'),
          document.getElementById('horaDesdeMin'),
          document.getElementById('horaDesdeAmpm')
        );
        this.loadAmpmFromTime(h2, 
          document.getElementById('horaHastaHora'),
          document.getElementById('horaHastaMin'),
          document.getElementById('horaHastaAmpm')
        );

        // Mostrar filtros avanzados
        if (advFilters) advFilters.classList.remove('hidden');

        const page = document.getElementById('hiddenPage');
        if (page) page.value = '1';

        if (form) form.submit();
      });
    });
  },

  formatDate(date) {
    // IMPORTANTE: NO usar toISOString() porque usa UTC y en Argentina puede correrte el día
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  },

  // ============================================
  // REMOVER FILTROS
  // ============================================
  bindFiltrosRemove() {
    document.querySelectorAll('.filtro-remove').forEach(btn => {
      btn.addEventListener('click', () => {
        const filter = btn.dataset.filter;
        const url = new URL(window.location.href);

        switch (filter) {
          case 'medio':
            url.searchParams.delete('medio');
            break;
          case 'estado':
            url.searchParams.delete('estado');
            break;
          case 'fecha':
            // Limpiar fecha y hora juntas (ahora son un rango combinado)
            url.searchParams.delete('desde');
            url.searchParams.delete('hasta');
            url.searchParams.delete('hora_desde');
            url.searchParams.delete('hora_hasta');
            break;
          case 'cliente':
            url.searchParams.delete('cliente_id');
            break;
        }

        url.searchParams.set('page', '1');
        window.location.href = url.toString();
      });
    });
  },

  // ============================================
  // KPIs CLICKEABLES
  // ============================================
  bindKPIClicks() {
    // KPI cards clickeables
    document.querySelectorAll('.vkpi-card[data-filter]').forEach(card => {
      card.style.cursor = 'pointer';
      card.addEventListener('click', () => {
        const filterType = card.dataset.filter;
        const filterValue = card.dataset.filterValue || '';
        
        const url = new URL(window.location.href);
        url.searchParams.set('page', '1');

        switch (filterType) {
          case 'estado':
            url.searchParams.set('estado', filterValue);
            break;
          case 'medio':
            url.searchParams.set('medio', filterValue);
            break;
          case 'fecha':
            // Hoy
            const today = this.formatDate(new Date());
            url.searchParams.set('desde', today);
            url.searchParams.set('hasta', today);
            break;
        }

        window.location.href = url.toString();
      });
    });

    // Chips de medios de pago clickeables
    document.querySelectorAll('.vkpi-chip[data-medio]').forEach(chip => {
      chip.style.cursor = 'pointer';
      chip.addEventListener('click', () => {
        const medio = chip.dataset.medio;
        const url = new URL(window.location.href);
        url.searchParams.set('medio', medio);
        url.searchParams.set('page', '1');
        window.location.href = url.toString();
      });
    });
  },

  // ============================================
  // MODAL PREVIEW
  // ============================================
  bindPreviewModal() {
    const modal = document.getElementById('previewModal');
    const previewId = document.getElementById('previewId');
    const previewBody = document.getElementById('previewBody');
    const previewLink = document.getElementById('previewLink');

    if (!modal) return;

    document.querySelectorAll('[data-preview]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = btn.dataset.preview;

        modal.classList.remove('hidden');
        previewId.textContent = id;
        previewLink.href = `venta_detalle.php?id=${id}`;
        previewBody.innerHTML = '<div class="loading">Cargando...</div>';

        try {
          const res = await fetch(`api/ventas_api.php?action=venta_preview&id=${id}`);
          const data = await res.json();

          if (data.ok || data.success) {
            const v = data.venta;

            // Construir items con escape XSS
            const itemsHtml = (v.items || []).map(i => {
              const cant = parseFloat(i.cantidad).toFixed(i.cantidad == Math.floor(i.cantidad) ? 0 : 2);
              const subtotal = parseFloat(i.subtotal || i.precio * i.cantidad).toFixed(2);
              return `<li>${this.escapeHtml(cant)}x ${this.escapeHtml(i.nombre)} - $${this.escapeHtml(subtotal)}</li>`;
            }).join('');

            // Badge de estado
            const estadoBadge = (v.estado === 'ANULADA')
              ? '<span class="badge-estado anulada">Anulada</span>'
              : '<span class="badge-estado emitida">Emitida</span>';

            previewBody.innerHTML = `
              <div style="display:grid; gap:12px;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                  <span><strong>Fecha:</strong> ${this.escapeHtml(v.fecha)}</span>
                  ${estadoBadge}
                </div>
                <div><strong>Cliente:</strong> ${this.escapeHtml(v.cliente || 'Consumidor Final')}</div>
                <div><strong>Medio:</strong> <span class="badge-medio">${this.escapeHtml(v.medio_pago || 'N/A')}</span></div>
                <hr style="border:none;border-top:1px solid var(--panel-border);">
                <div><strong>Productos:</strong></div>
                <ul style="margin:0; padding-left:20px; font-size:0.9rem;">
                  ${itemsHtml}
                </ul>
                <hr style="border:none;border-top:1px solid var(--panel-border);">
                <div style="text-align:right; font-size:1.3rem; font-weight:700;">
                  Total: $${this.escapeHtml(parseFloat(v.total).toFixed(2))}
                </div>
              </div>
            `;
          } else {
            previewBody.innerHTML = '<p style="color:#ef4444;">Error al cargar</p>';
          }
        } catch (e) {
          previewBody.innerHTML = '<p style="color:#ef4444;">Error de conexión</p>';
        }
      });
    });

    // Cerrar modal
    modal.querySelectorAll('[data-close]').forEach(btn => {
      btn.addEventListener('click', () => modal.classList.add('hidden'));
    });
    modal.querySelector('.modal-backdrop')?.addEventListener('click', () => {
      modal.classList.add('hidden');
    });
  },

  // ============================================
  // MODAL TICKET
  // ============================================
  bindTicketModal() {
    const modal = document.getElementById('ticketModal');
    const ticketId = document.getElementById('ticketId');
    const ticketFrame = document.getElementById('ticketFrame');
    const btnPrint = document.getElementById('btnPrintTicket');

    if (!modal) return;

    document.querySelectorAll('[data-ticket]').forEach(btn => {
      btn.addEventListener('click', () => {
        const id = btn.dataset.ticket;
        const paper = this.state.paperSize;

        modal.classList.remove('hidden');
        ticketId.textContent = id;
        ticketFrame.src = `ticket.php?id=${id}&paper=${paper}`;
      });
    });

    // Print
    if (btnPrint) {
      btnPrint.addEventListener('click', () => {
        if (ticketFrame && ticketFrame.contentWindow) {
          ticketFrame.contentWindow.print();
        }
      });
    }

    // Cerrar
    modal.querySelectorAll('[data-close]').forEach(btn => {
      btn.addEventListener('click', () => {
        modal.classList.add('hidden');
        ticketFrame.src = '';
      });
    });
    modal.querySelector('.modal-backdrop')?.addEventListener('click', () => {
      modal.classList.add('hidden');
      ticketFrame.src = '';
    });
  },

  // ============================================
  // COMPARTIR TICKET
  // ============================================
  bindShareButtons() {
    document.querySelectorAll('[data-share]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = btn.dataset.share;
        const method = btn.dataset.method;

        if (method === 'whatsapp') {
          await this.shareWhatsApp(id);
        } else if (method === 'email') {
          await this.shareEmail(id);
        }
      });
    });
  },

  async shareWhatsApp(ventaId) {
    const phone = await Notif.prompt('📱 Enviar por WhatsApp', '', { placeholder: 'Ej: 5491155667788', confirmText: '✅ Enviar', inputLabel: 'Número con código de país' });
    if (!phone) return;

    try {
      const res = await fetch('api/ventas_api.php?action=send_ticket_whatsapp', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ venta_id: parseInt(ventaId), phone })
      });
      const data = await res.json();

      if (data.success && data.url) {
        window.open(data.url, '_blank');
        this.showToast('Abriendo WhatsApp...', 'success');
      } else {
        this.showToast(data.error || 'Error al generar link', 'error');
      }
    } catch (e) {
      this.showToast('Error de conexión', 'error');
    }
  },

  async shareEmail(ventaId) {
    const email = await Notif.prompt('📧 Enviar por email', '', { placeholder: 'cliente@ejemplo.com', confirmText: '✅ Enviar', inputType: 'email' });
    if (!email) return;

    try {
      const res = await fetch('api/ventas_api.php?action=send_ticket_email', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ venta_id: parseInt(ventaId), email })
      });
      const data = await res.json();

      if (data.ok || data.success) {
        this.showToast(data.message, 'success');
      } else {
        this.showToast(data.error || 'Error al enviar email', 'error');
        if (data.fallback_url) {
          Notif.confirmar('🔗 Copiar link', '<p>¿Querés copiar el link del ticket al portapapeles?</p>', { icon: 'info', confirmText: '✅ Copiar', cancelText: 'No' }).then(ok => {
            if (ok) {
              navigator.clipboard?.writeText(data.fallback_url);
              this.showToast('Link copiado al portapapeles', 'success');
            }
          });
        }
      }
    } catch (e) {
      this.showToast('Error de conexión', 'error');
    }
  },

  // ============================================
  // ATAJOS DE TECLADO
  // ============================================
  bindKeyboardShortcuts() {
    document.addEventListener('keydown', (e) => {
      // Ctrl+K: Focus búsqueda
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        const search = document.querySelector('input[name="venta_id"]');
        search?.focus();
        search?.select();
      }

      // Ctrl+E: Toggle export o gráficos
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'e') {
        e.preventDefault();
        const btnCharts = document.getElementById('btnCharts');
        btnCharts?.click();
      }

      // Escape: Cerrar modales
      if (e.key === 'Escape') {
        document.getElementById('previewModal')?.classList.add('hidden');
        const ticketModal = document.getElementById('ticketModal');
        if (ticketModal) {
          ticketModal.classList.add('hidden');
          document.getElementById('ticketFrame').src = '';
        }
        this.hideClienteDropdown();
      }
    });
  },

  // ============================================
  // UTILIDAD: Refresh página
  // ============================================
  refreshPage() {
    window.location.reload();
  },

  // ============================================
  // UTILIDAD: Ir a página
  // ============================================
  goToPage(page) {
    const url = new URL(window.location.href);
    url.searchParams.set('page', page);
    window.location.href = url.toString();
  },
};

// Hacer global para compatibilidad
window.VentasManager = VentasManager;

// Inicializar
document.addEventListener('DOMContentLoaded', () => VentasManager.init());

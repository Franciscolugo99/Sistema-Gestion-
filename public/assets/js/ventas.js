// ventas.js v4.1 - FLUS PRO (Corregido XSS + Autocomplete clientes)
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('ventasForm');
  
  // ============================================
  // ESCAPE HTML - Prevenir XSS
  // ============================================
  function escapeHtml(text) {
    if (text == null) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
  }
  
  // ============================================
  // PAPER SIZE
  // ============================================
  const paperSel = document.getElementById('paperSel');
  const PAPER_KEY = 'flus-paper';
  
  function getPaper() {
    try { return localStorage.getItem(PAPER_KEY) || '80'; } catch { return '80'; }
  }
  
  function setPaper(v) {
    try { localStorage.setItem(PAPER_KEY, v); } catch {}
  }
  
  if (paperSel) {
    paperSel.value = getPaper();
    paperSel.addEventListener('change', () => setPaper(paperSel.value));
  }
  
  // ============================================
  // CHARTS TOGGLE
  // ============================================
  const btnCharts = document.getElementById('btnCharts');
  const chartsPanel = document.getElementById('chartsPanel');
  let chartsInit = false;
  
  if (btnCharts && chartsPanel) {
    btnCharts.addEventListener('click', () => {
      chartsPanel.classList.toggle('hidden');
      if (!chartsInit && !chartsPanel.classList.contains('hidden')) {
        initCharts();
        chartsInit = true;
      }
    });
  }
  
  function initCharts() {
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
  }
  
  // ============================================
  // ADVANCED FILTERS TOGGLE
  // ============================================
  const btnMore = document.getElementById('btnMoreFilters');
  const advFilters = document.getElementById('advancedFilters');
  
  if (btnMore && advFilters) {
    // Check if any advanced filter is active
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
  }
  
  // ============================================
  // CLIENTE AUTOCOMPLETE
  // ============================================
  const clienteInput = document.getElementById('clienteSearch');
  const clienteIdInput = document.getElementById('clienteIdHidden');
  const clienteDropdown = document.getElementById('clienteDropdown');
  
  let searchTimeout = null;
  let selectedClienteId = clienteIdInput?.value || null;
  
  if (clienteInput && clienteDropdown) {
    // Input handler con debounce
    clienteInput.addEventListener('input', () => {
      const q = clienteInput.value.trim();
      
      // Limpiar ID si se borra el texto
      if (q === '') {
        if (clienteIdInput) clienteIdInput.value = '';
        selectedClienteId = null;
        hideDropdown();
        return;
      }
      
      // Debounce
      clearTimeout(searchTimeout);
      searchTimeout = setTimeout(() => searchClientes(q), 300);
    });
    
    // Click fuera cierra dropdown
    document.addEventListener('click', (e) => {
      if (!clienteInput.contains(e.target) && !clienteDropdown.contains(e.target)) {
        hideDropdown();
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
        selectCliente(active.dataset.id, active.dataset.nombre);
      } else if (e.key === 'Escape') {
        hideDropdown();
      }
    });
  }
  
  async function searchClientes(q) {
    if (q.length < 2) {
      hideDropdown();
      return;
    }
    
    try {
      const res = await fetch(`api/ventas_api.php?action=buscar_clientes&q=${encodeURIComponent(q)}`);
      const data = await res.json();
      
      if (data.success && data.clientes.length > 0) {
        showDropdown(data.clientes);
      } else {
        showDropdown([], 'No se encontraron clientes');
      }
    } catch (e) {
      console.error('Error buscando clientes:', e);
      hideDropdown();
    }
  }
  
  function showDropdown(clientes, emptyMsg = '') {
    if (!clienteDropdown) return;
    
    if (clientes.length === 0) {
      clienteDropdown.innerHTML = `<div class="cliente-empty">${escapeHtml(emptyMsg)}</div>`;
    } else {
      clienteDropdown.innerHTML = clientes.map((c, i) => `
        <div class="cliente-item ${i === 0 ? 'active' : ''}" 
             data-id="${c.id}" 
             data-nombre="${escapeHtml(c.nombre)}">
          <span class="cliente-nombre">${escapeHtml(c.nombre)}</span>
          ${c.documento ? `<span class="cliente-doc">${escapeHtml(c.documento)}</span>` : ''}
        </div>
      `).join('');
      
      // Click en items
      clienteDropdown.querySelectorAll('.cliente-item').forEach(item => {
        item.addEventListener('click', () => {
          selectCliente(item.dataset.id, item.dataset.nombre);
        });
      });
    }
    
    clienteDropdown.classList.add('show');
  }
  
  function hideDropdown() {
    if (clienteDropdown) {
      clienteDropdown.classList.remove('show');
    }
  }
  
  function selectCliente(id, nombre) {
    if (clienteInput) clienteInput.value = nombre;
    if (clienteIdInput) clienteIdInput.value = id;
    selectedClienteId = id;
    hideDropdown();
  }
  
  // Botón limpiar cliente
  const btnClearCliente = document.getElementById('btnClearCliente');
  if (btnClearCliente) {
    btnClearCliente.addEventListener('click', () => {
      if (clienteInput) clienteInput.value = '';
      if (clienteIdInput) clienteIdInput.value = '';
      selectedClienteId = null;
    });
  }
  
  // ============================================
  // DATE CHIPS
  // ============================================
  const desde = document.querySelector('input[name="desde"]');
  const hasta = document.querySelector('input[name="hasta"]');
  
  function fmt(d) {
    return d.toISOString().split('T')[0];
  }
  
  document.querySelectorAll('.chip[data-range]').forEach(chip => {
    chip.addEventListener('click', () => {
      const r = chip.dataset.range;
      const now = new Date();
      let d1 = new Date(now), d2 = new Date(now);
      
      switch (r) {
        case 'today':
          break;
        case 'yesterday':
          d1.setDate(d1.getDate() - 1);
          d2.setDate(d2.getDate() - 1);
          break;
        case '7d':
          d1.setDate(d1.getDate() - 6);
          break;
        case '30d':
          d1.setDate(d1.getDate() - 29);
          break;
      }
      
      if (desde) desde.value = fmt(d1);
      if (hasta) hasta.value = fmt(d2);
      
      const page = document.getElementById('hiddenPage');
      if (page) page.value = '1';
      
      if (form) form.submit();
    });
  });
  
  // ============================================
  // TIME CHIPS
  // ============================================
  const horaDesde = document.querySelector('input[name="hora_desde"]');
  const horaHasta = document.querySelector('input[name="hora_hasta"]');
  
  document.querySelectorAll('.chip[data-hora]').forEach(chip => {
    chip.addEventListener('click', () => {
      const [h1, h2] = chip.dataset.hora.split(',');
      
      if (horaDesde) horaDesde.value = h1;
      if (horaHasta) horaHasta.value = h2;
      
      // Show advanced filters
      if (advFilters) advFilters.classList.remove('hidden');
      
      const page = document.getElementById('hiddenPage');
      if (page) page.value = '1';
      
      if (form) form.submit();
    });
  });
  
  // ============================================
  // REMOVE FILTERS
  // ============================================
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
          url.searchParams.delete('desde');
          url.searchParams.delete('hasta');
          break;
        case 'hora':
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
  
  // ============================================
  // PREVIEW MODAL (con escape XSS)
  // ============================================
  const previewModal = document.getElementById('previewModal');
  const previewId = document.getElementById('previewId');
  const previewBody = document.getElementById('previewBody');
  const previewLink = document.getElementById('previewLink');
  
  document.querySelectorAll('[data-preview]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.dataset.preview;
      if (!previewModal) return;
      
      previewModal.classList.remove('hidden');
      previewId.textContent = id;
      previewLink.href = `venta_detalle.php?id=${id}`;
      previewBody.innerHTML = '<div class="loading">Cargando...</div>';
      
      try {
        const res = await fetch(`api/ventas_api.php?action=venta_preview&id=${id}`);
        const data = await res.json();
        
        if (data.success) {
          const v = data.venta;
          
          // Construir items con escape XSS
          const itemsHtml = (v.items || []).map(i => {
            const cant = parseFloat(i.cantidad).toFixed(i.cantidad == Math.floor(i.cantidad) ? 0 : 2);
            const subtotal = parseFloat(i.subtotal || i.precio * i.cantidad).toFixed(2);
            return `<li>${escapeHtml(cant)}x ${escapeHtml(i.nombre)} - $${escapeHtml(subtotal)}</li>`;
          }).join('');
          
          // Badge de estado
          const estadoBadge = (v.estado === 'ANULADA') 
            ? '<span class="badge-estado anulada">Anulada</span>'
            : '<span class="badge-estado emitida">Emitida</span>';
          
          previewBody.innerHTML = `
            <div style="display:grid; gap:12px;">
              <div style="display:flex; justify-content:space-between; align-items:center;">
                <span><strong>Fecha:</strong> ${escapeHtml(v.fecha)}</span>
                ${estadoBadge}
              </div>
              <div><strong>Cliente:</strong> ${escapeHtml(v.cliente || 'Consumidor Final')}</div>
              <div><strong>Medio:</strong> <span class="badge-medio">${escapeHtml(v.medio_pago || 'N/A')}</span></div>
              <hr style="border:none;border-top:1px solid var(--panel-border);">
              <div><strong>Productos:</strong></div>
              <ul style="margin:0; padding-left:20px; font-size:0.9rem;">
                ${itemsHtml}
              </ul>
              <hr style="border:none;border-top:1px solid var(--panel-border);">
              <div style="text-align:right; font-size:1.3rem; font-weight:700;">
                Total: $${escapeHtml(parseFloat(v.total).toFixed(2))}
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
  
  // Close preview
  previewModal?.querySelectorAll('[data-close]').forEach(btn => {
    btn.addEventListener('click', () => previewModal.classList.add('hidden'));
  });
  previewModal?.querySelector('.modal-backdrop')?.addEventListener('click', () => {
    previewModal.classList.add('hidden');
  });
  
  // ============================================
  // TICKET MODAL
  // ============================================
  const ticketModal = document.getElementById('ticketModal');
  const ticketId = document.getElementById('ticketId');
  const ticketFrame = document.getElementById('ticketFrame');
  const btnPrint = document.getElementById('btnPrintTicket');
  
  document.querySelectorAll('[data-ticket]').forEach(btn => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.ticket;
      const paper = getPaper();
      
      if (!ticketModal) return;
      
      ticketModal.classList.remove('hidden');
      ticketId.textContent = id;
      ticketFrame.src = `ticket.php?id=${id}&paper=${paper}`;
    });
  });
  
  // Print ticket
  if (btnPrint) {
    btnPrint.addEventListener('click', () => {
      if (ticketFrame && ticketFrame.contentWindow) {
        ticketFrame.contentWindow.print();
      }
    });
  }
  
  // Close ticket
  ticketModal?.querySelectorAll('[data-close]').forEach(btn => {
    btn.addEventListener('click', () => {
      ticketModal.classList.add('hidden');
      ticketFrame.src = '';
    });
  });
  ticketModal?.querySelector('.modal-backdrop')?.addEventListener('click', () => {
    ticketModal.classList.add('hidden');
    ticketFrame.src = '';
  });
  
  // ============================================
  // SHARE TICKET (WhatsApp / Email)
  // ============================================
  document.querySelectorAll('[data-share]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = btn.dataset.share;
      const method = btn.dataset.method; // 'whatsapp' o 'email'
      
      if (method === 'whatsapp') {
        const phone = prompt('Ingresá el número de WhatsApp (ej: 1155667788):');
        if (!phone) return;
        
        try {
          const res = await fetch('api/ventas_api.php?action=send_ticket_whatsapp', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ venta_id: parseInt(id), phone })
          });
          const data = await res.json();
          
          if (data.success && data.url) {
            window.open(data.url, '_blank');
          } else {
            alert(data.error || 'Error al generar link');
          }
        } catch (e) {
          alert('Error de conexión');
        }
      } else if (method === 'email') {
        const email = prompt('Ingresá el email del cliente:');
        if (!email) return;
        
        try {
          const res = await fetch('api/ventas_api.php?action=send_ticket_email', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ venta_id: parseInt(id), email })
          });
          const data = await res.json();
          
          if (data.success) {
            alert(data.message);
          } else {
            alert(data.error || 'Error al enviar email');
            if (data.fallback_url) {
              if (confirm('¿Querés copiar el link del ticket?')) {
                navigator.clipboard?.writeText(data.fallback_url);
                alert('Link copiado al portapapeles');
              }
            }
          }
        } catch (e) {
          alert('Error de conexión');
        }
      }
    });
  });
  
  // ============================================
  // KEYBOARD SHORTCUTS
  // ============================================
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      previewModal?.classList.add('hidden');
      ticketModal?.classList.add('hidden');
      if (ticketFrame) ticketFrame.src = '';
      hideDropdown();
    }
  });
});

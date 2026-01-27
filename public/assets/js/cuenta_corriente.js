/**
 * cuenta_corriente.js
 * FLUS - Interactividad del módulo de Cuentas Corrientes
 */

(function() {
  'use strict';

  // ═══════════════════════════════════════════════════════════════════
  // ELEMENTOS
  // ═══════════════════════════════════════════════════════════════════
  const drawer = document.getElementById('drawerPago');
  const overlay = document.getElementById('drawerOverlay');
  const btnNuevoPago = document.getElementById('btnNuevoPago');
  const btnCerrarDrawer = document.getElementById('btnCerrarDrawer');
  const btnCancelarPago = document.getElementById('btnCancelarPago');
  const btnConfirmarPago = document.getElementById('btnConfirmarPago');
  const btnPagarTodo = document.getElementById('btnPagarTodo');
  const formPago = document.getElementById('formPago');
  
  // Campos del formulario
  const pagoClienteId = document.getElementById('pagoClienteId');
  const pagoClienteNombre = document.getElementById('pagoClienteNombre');
  const pagoClienteSaldo = document.getElementById('pagoClienteSaldo');
  const pagoMonto = document.getElementById('pagoMonto');
  const clienteSelected = document.getElementById('clienteSelected');
  const clienteSearch = document.getElementById('clienteSearch');
  const buscarClienteInput = document.getElementById('buscarClienteInput');
  const clienteSearchResults = document.getElementById('clienteSearchResults');

  // Estado
  let currentSaldo = window.currentSaldo || 0;
  let searchTimeout = null;

  // ═══════════════════════════════════════════════════════════════════
  // UTILIDADES
  // ═══════════════════════════════════════════════════════════════════
  
  function formatMoney(value) {
    return new Intl.NumberFormat('es-AR', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    }).format(value);
  }

  function parseMoney(str) {
    if (!str) return 0;
    const cleaned = str.replace(/\./g, '').replace(',', '.');
    return parseFloat(cleaned) || 0;
  }

  function showToast(message, type = 'success') {
    if (window.FLUS && window.FLUS.toast) {
      window.FLUS.toast(message, type);
    } else {
      alert(message);
    }
  }

  function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : '';
  }

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // ═══════════════════════════════════════════════════════════════════
  // DRAWER PAGO
  // ═══════════════════════════════════════════════════════════════════

  function openDrawer(clienteId = null, clienteNombre = null, saldo = null) {
    if (!drawer) return;

    if (formPago) formPago.reset();
    
    if (clienteId && clienteNombre) {
      if (pagoClienteId) pagoClienteId.value = clienteId;
      if (pagoClienteNombre) pagoClienteNombre.textContent = clienteNombre;
      currentSaldo = parseFloat(saldo) || 0;
      if (pagoClienteSaldo) pagoClienteSaldo.textContent = 'Saldo: $' + formatMoney(currentSaldo);
      
      if (clienteSelected) clienteSelected.style.display = 'flex';
      if (clienteSearch) clienteSearch.classList.remove('active');
    } else {
      if (pagoClienteId) pagoClienteId.value = '';
      if (pagoClienteNombre) pagoClienteNombre.textContent = 'Seleccionar cliente...';
      if (pagoClienteSaldo) pagoClienteSaldo.textContent = '';
      currentSaldo = 0;
      
      if (clienteSelected) clienteSelected.style.display = 'none';
      if (clienteSearch) clienteSearch.classList.add('active');
    }

    drawer.classList.add('active');
    if (overlay) overlay.classList.add('active');
    document.body.style.overflow = 'hidden';

    setTimeout(() => {
      if (clienteId && pagoMonto) {
        pagoMonto.focus();
      } else if (buscarClienteInput) {
        buscarClienteInput.focus();
      }
    }, 300);
  }

  function closeDrawer() {
    if (!drawer) return;
    drawer.classList.remove('active');
    if (overlay) overlay.classList.remove('active');
    document.body.style.overflow = '';
  }

  // ═══════════════════════════════════════════════════════════════════
  // BÚSQUEDA DE CLIENTES
  // ═══════════════════════════════════════════════════════════════════

  async function buscarClientes(query) {
    if (!clienteSearchResults) return;
    if (query.length < 2) {
      clienteSearchResults.classList.remove('active');
      return;
    }

    try {
      const response = await fetch(`api/cuenta_corriente_api.php?action=buscar_clientes&q=${encodeURIComponent(query)}`);
      const data = await response.json();

      if (data.success && data.clientes.length > 0) {
        clienteSearchResults.innerHTML = data.clientes.map(c => `
          <div class="cliente-result-item" 
               data-id="${c.id}" 
               data-nombre="${escapeHtml(c.nombre)}" 
               data-saldo="${c.cc_saldo}">
            <strong>${escapeHtml(c.nombre)}</strong>
            <span style="float:right;color:#ef4444;">$${formatMoney(c.cc_saldo)}</span>
          </div>
        `).join('');
        clienteSearchResults.classList.add('active');
      } else {
        clienteSearchResults.innerHTML = '<div class="cliente-result-item">No se encontraron clientes</div>';
        clienteSearchResults.classList.add('active');
      }
    } catch (error) {
      console.error('Error buscando clientes:', error);
    }
  }

  function seleccionarCliente(id, nombre, saldo) {
    if (pagoClienteId) pagoClienteId.value = id;
    if (pagoClienteNombre) pagoClienteNombre.textContent = nombre;
    currentSaldo = parseFloat(saldo) || 0;
    if (pagoClienteSaldo) pagoClienteSaldo.textContent = 'Saldo: $' + formatMoney(currentSaldo);
    
    if (clienteSelected) clienteSelected.style.display = 'flex';
    if (clienteSearch) clienteSearch.classList.remove('active');
    if (clienteSearchResults) clienteSearchResults.classList.remove('active');
    
    if (pagoMonto) pagoMonto.focus();
  }

  // ═══════════════════════════════════════════════════════════════════
  // FORMULARIO DE PAGO
  // ═══════════════════════════════════════════════════════════════════

  async function enviarPago(event) {
    event.preventDefault();

    if (!pagoClienteId || !pagoClienteId.value) {
      showToast('Seleccioná un cliente', 'error');
      return;
    }

    const monto = parseMoney(pagoMonto?.value || '0');
    if (monto <= 0) {
      showToast('Ingresá un monto válido', 'error');
      if (pagoMonto) pagoMonto.focus();
      return;
    }

    const medioPago = formPago?.querySelector('input[name="medio_pago"]:checked')?.value;
    if (!medioPago) {
      showToast('Seleccioná un medio de pago', 'error');
      return;
    }

    if (btnConfirmarPago) {
      btnConfirmarPago.disabled = true;
      btnConfirmarPago.innerHTML = '<span class="spinner"></span> Procesando...';
    }

    try {
      const formData = new FormData(formPago);
      formData.set('monto', monto);
      formData.append('action', 'registrar_pago');

      const response = await fetch('api/cuenta_corriente_api.php', {
        method: 'POST',
        body: formData
      });

      const result = await response.json();

      if (result.success) {
        showToast('Pago registrado correctamente', 'success');
        closeDrawer();
        setTimeout(() => location.reload(), 500);
      } else {
        showToast(result.error || 'Error al registrar el pago', 'error');
      }
    } catch (error) {
      console.error('Error:', error);
      showToast('Error de conexión', 'error');
    } finally {
      if (btnConfirmarPago) {
        btnConfirmarPago.disabled = false;
        btnConfirmarPago.innerHTML = `
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="20 6 9 17 4 12"/>
          </svg>
          Registrar Pago
        `;
      }
    }
  }

  // ═══════════════════════════════════════════════════════════════════
  // REVERSAR MOVIMIENTO
  // ═══════════════════════════════════════════════════════════════════
  
  async function reversarMovimiento(movimientoId, tipo, monto) {
    const motivo = prompt(`Ingresá el motivo para reversar este ${tipo.toLowerCase()} de $${formatMoney(monto)}:`);
    
    if (!motivo || motivo.trim() === '') {
      return;
    }
    
    if (!confirm(`¿Confirmar reversa del ${tipo.toLowerCase()} por $${formatMoney(monto)}?\n\nMotivo: ${motivo}`)) {
      return;
    }
    
    try {
      const formData = new FormData();
      formData.append('action', 'reversar_movimiento');
      formData.append('movimiento_id', movimientoId);
      formData.append('motivo', motivo.trim());
      formData.append('csrf_token', getCsrfToken());
      
      const response = await fetch('api/cuenta_corriente_api.php', {
        method: 'POST',
        body: formData
      });
      
      const result = await response.json();
      
      if (result.success) {
        showToast('Movimiento reversado correctamente', 'success');
        setTimeout(() => location.reload(), 500);
      } else {
        showToast(result.error || 'Error al reversar', 'error');
      }
    } catch (error) {
      console.error('Error:', error);
      showToast('Error de conexión', 'error');
    }
  }

  // ═══════════════════════════════════════════════════════════════════
  // RECALCULAR SALDO (POST + CSRF)
  // ═══════════════════════════════════════════════════════════════════
  
  async function recalcularSaldo(clienteId) {
    if (!confirm('¿Recalcular el saldo desde los movimientos?\n\nEsto corrige inconsistencias si las hubiera.')) {
      return;
    }
    
    try {
      const formData = new FormData();
      formData.append('action', 'recalcular_saldo');
      formData.append('cliente_id', clienteId);
      formData.append('csrf_token', getCsrfToken());
      
      const response = await fetch('api/cuenta_corriente_api.php', {
        method: 'POST',
        body: formData
      });
      const result = await response.json();
      
      if (result.success) {
        if (result.corregido) {
          showToast(`Saldo corregido: $${formatMoney(result.saldo_anterior)} → $${formatMoney(result.saldo_calculado)}`, 'success');
        } else {
          showToast('El saldo ya era correcto', 'success');
        }
        setTimeout(() => location.reload(), 1000);
      } else {
        showToast(result.error || 'Error al recalcular', 'error');
      }
    } catch (error) {
      console.error('Error:', error);
      showToast('Error de conexión', 'error');
    }
  }

  // ═══════════════════════════════════════════════════════════════════
  // DRAWER DE AJUSTE
  // ═══════════════════════════════════════════════════════════════════
  
  const drawerAjuste = document.getElementById('drawerAjuste');
  const overlayAjuste = document.getElementById('drawerOverlayAjuste');
  const formAjuste = document.getElementById('formAjuste');
  const btnCerrarDrawerAjuste = document.getElementById('btnCerrarDrawerAjuste');
  const btnCancelarAjuste = document.getElementById('btnCancelarAjuste');
  const btnConfirmarAjuste = document.getElementById('btnConfirmarAjuste');
  const ajusteMonto = document.getElementById('ajusteMonto');
  const ajusteConcepto = document.getElementById('ajusteConcepto');
  
  function openDrawerAjuste() {
    if (!drawerAjuste) return;
    if (formAjuste) formAjuste.reset();
    
    drawerAjuste.classList.add('active');
    if (overlayAjuste) overlayAjuste.classList.add('active');
    document.body.style.overflow = 'hidden';
    
    setTimeout(() => {
      if (ajusteMonto) ajusteMonto.focus();
    }, 300);
  }
  
  function closeDrawerAjuste() {
    if (!drawerAjuste) return;
    drawerAjuste.classList.remove('active');
    if (overlayAjuste) overlayAjuste.classList.remove('active');
    document.body.style.overflow = '';
  }
  
  async function enviarAjuste(event) {
    event.preventDefault();
    
    const monto = parseMoney(ajusteMonto?.value || '0');
    if (monto <= 0) {
      showToast('Ingresá un monto válido', 'error');
      if (ajusteMonto) ajusteMonto.focus();
      return;
    }
    
    const concepto = ajusteConcepto?.value?.trim() || '';
    if (!concepto) {
      showToast('El concepto es obligatorio', 'error');
      if (ajusteConcepto) ajusteConcepto.focus();
      return;
    }
    
    const tipoAjuste = formAjuste?.querySelector('input[name="tipo_ajuste"]:checked')?.value;
    const tipoLabel = tipoAjuste === 'positivo' ? 'aumenta deuda' : 'reduce deuda';
    
    if (!confirm(`¿Confirmar ajuste?\n\nTipo: ${tipoLabel}\nMonto: $${formatMoney(monto)}\nConcepto: ${concepto}`)) {
      return;
    }
    
    if (btnConfirmarAjuste) {
      btnConfirmarAjuste.disabled = true;
      btnConfirmarAjuste.innerHTML = '<span class="spinner"></span> Procesando...';
    }
    
    try {
      const formData = new FormData(formAjuste);
      formData.set('monto', monto);
      formData.set('tipo', tipoAjuste);
      formData.append('action', 'registrar_ajuste');
      
      const response = await fetch('api/cuenta_corriente_api.php', {
        method: 'POST',
        body: formData
      });
      
      const result = await response.json();
      
      if (result.success) {
        showToast('Ajuste registrado correctamente', 'success');
        closeDrawerAjuste();
        setTimeout(() => location.reload(), 500);
      } else {
        showToast(result.error || 'Error al registrar ajuste', 'error');
      }
    } catch (error) {
      console.error('Error:', error);
      showToast('Error de conexión', 'error');
    } finally {
      if (btnConfirmarAjuste) {
        btnConfirmarAjuste.disabled = false;
        btnConfirmarAjuste.innerHTML = `
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
            <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
          </svg>
          Registrar Ajuste
        `;
      }
    }
  }
  
  // Event listeners drawer ajuste
  if (btnCerrarDrawerAjuste) btnCerrarDrawerAjuste.addEventListener('click', closeDrawerAjuste);
  if (btnCancelarAjuste) btnCancelarAjuste.addEventListener('click', closeDrawerAjuste);
  if (overlayAjuste) overlayAjuste.addEventListener('click', closeDrawerAjuste);
  if (formAjuste) formAjuste.addEventListener('submit', enviarAjuste);
  
  // Formatear monto ajuste
  if (ajusteMonto) {
    ajusteMonto.addEventListener('blur', () => {
      const value = parseMoney(ajusteMonto.value);
      if (value > 0) {
        ajusteMonto.value = formatMoney(value);
      }
    });
  }

  // ═══════════════════════════════════════════════════════════════════
  // EVENT LISTENERS
  // ═══════════════════════════════════════════════════════════════════

  // Botón nuevo pago
  if (btnNuevoPago) {
    btnNuevoPago.addEventListener('click', () => openDrawer());
  }

  // Botones de pago rápido desde la tabla
  document.querySelectorAll('[data-action="pago-rapido"]').forEach(btn => {
    btn.addEventListener('click', () => {
      const { clienteId, clienteNombre, saldo } = btn.dataset;
      openDrawer(clienteId, clienteNombre, saldo);
    });
  });

  // Cerrar drawer
  if (btnCerrarDrawer) btnCerrarDrawer.addEventListener('click', closeDrawer);
  if (btnCancelarPago) btnCancelarPago.addEventListener('click', closeDrawer);
  if (overlay) overlay.addEventListener('click', closeDrawer);

  // Cerrar con Escape - manejado más abajo para ambos drawers

  // Botón "Pagar todo"
  if (btnPagarTodo) {
    btnPagarTodo.addEventListener('click', () => {
      if (currentSaldo > 0 && pagoMonto) {
        pagoMonto.value = formatMoney(currentSaldo);
      }
    });
  }

  // Búsqueda de clientes
  if (buscarClienteInput) {
    buscarClienteInput.addEventListener('input', () => {
      clearTimeout(searchTimeout);
      searchTimeout = setTimeout(() => {
        buscarClientes(buscarClienteInput.value.trim());
      }, 300);
    });
  }

  // Seleccionar cliente de resultados
  if (clienteSearchResults) {
    clienteSearchResults.addEventListener('click', (e) => {
      const item = e.target.closest('.cliente-result-item');
      if (item && item.dataset.id) {
        seleccionarCliente(item.dataset.id, item.dataset.nombre, item.dataset.saldo);
      }
    });
  }

  // Enviar formulario
  if (formPago) {
    formPago.addEventListener('submit', enviarPago);
  }

  // Formatear monto
  if (pagoMonto) {
    pagoMonto.addEventListener('blur', () => {
      const value = parseMoney(pagoMonto.value);
      if (value > 0) {
        pagoMonto.value = formatMoney(value);
      }
    });
  }

  // Botones reversar
  document.querySelectorAll('[data-action="reversar"]').forEach(btn => {
    btn.addEventListener('click', () => {
      const { movimientoId, tipo, monto } = btn.dataset;
      reversarMovimiento(movimientoId, tipo, parseFloat(monto));
    });
  });

  // Botón recalcular
  const btnRecalcular = document.getElementById('btnRecalcular');
  if (btnRecalcular) {
    btnRecalcular.addEventListener('click', () => {
      const clienteId = btnRecalcular.dataset.clienteId;
      if (clienteId) {
        recalcularSaldo(clienteId);
      }
    });
  }

  // Botón ajuste - abre drawer
  const btnAjuste = document.getElementById('btnAjuste');
  if (btnAjuste) {
    btnAjuste.addEventListener('click', () => {
      openDrawerAjuste();
    });
  }
  
  // Escape cierra ambos drawers
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      if (drawer?.classList.contains('active')) {
        closeDrawer();
      }
      if (drawerAjuste?.classList.contains('active')) {
        closeDrawerAjuste();
      }
    }
  });

  // ═══════════════════════════════════════════════════════════════════
  // SPINNER STYLES
  // ═══════════════════════════════════════════════════════════════════

  const style = document.createElement('style');
  style.textContent = `
    .spinner {
      display: inline-block;
      width: 16px;
      height: 16px;
      border: 2px solid rgba(255,255,255,0.3);
      border-top-color: white;
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
    }
    @keyframes spin {
      to { transform: rotate(360deg); }
    }
  `;
  document.head.appendChild(style);

})();

/**
 * caja_sesion_detalle.js
 * FLUS v3.2.2 - Filtros y funcionalidad para detalle de sesión de caja
 */

(function() {
  'use strict';

  // ═══════════════════════════════════════════════════════════════════
  // ELEMENTOS DOM
  // ═══════════════════════════════════════════════════════════════════
  const filtroMetodo = document.getElementById('filtroMetodo');
  const ocultarAnuladas = document.getElementById('ocultarAnuladas');
  const btnLimpiar = document.getElementById('btnLimpiarFiltros');
  const tablaVentas = document.getElementById('tablaVentas');
  const noResultsMsg = document.getElementById('noResultsMsg');

  // Si no hay tabla de ventas, no hacer nada
  if (!tablaVentas) return;

  const filas = tablaVentas.querySelectorAll('tbody .venta-row');

  // ═══════════════════════════════════════════════════════════════════
  // FUNCIÓN DE FILTRADO
  // ═══════════════════════════════════════════════════════════════════
  function aplicarFiltros() {
    const metodoSeleccionado = filtroMetodo ? filtroMetodo.value.toLowerCase() : '';
    const ocultarAnuladasChecked = ocultarAnuladas ? ocultarAnuladas.checked : false;

    let visibles = 0;

    filas.forEach(fila => {
      const metodo = (fila.dataset.metodo || '').toLowerCase();
      const esAnulada = fila.dataset.anulada === '1';

      let mostrar = true;

      // Filtro por método de pago
      if (metodoSeleccionado && metodo !== metodoSeleccionado) {
        mostrar = false;
      }

      // Filtro por anuladas
      if (ocultarAnuladasChecked && esAnulada) {
        mostrar = false;
      }

      // Aplicar visibilidad
      fila.classList.toggle('is-hidden', !mostrar);
      
      if (mostrar) visibles++;
    });

    // Mostrar mensaje si no hay resultados
    if (noResultsMsg) {
      noResultsMsg.classList.toggle('is-hidden', visibles !== 0);
    }

    // Actualizar contador visual si existe
    actualizarContador(visibles);
  }

  // ═══════════════════════════════════════════════════════════════════
  // ACTUALIZAR CONTADOR
  // ═══════════════════════════════════════════════════════════════════
  function actualizarContador(visibles) {
    const countSpan = document.querySelector('.ventas-count');
    if (countSpan) {
      const total = filas.length;
      if (visibles < total) {
        countSpan.textContent = `(${visibles} de ${total})`;
      } else {
        countSpan.textContent = `(${total})`;
      }
    }
  }

  // ═══════════════════════════════════════════════════════════════════
  // LIMPIAR FILTROS
  // ═══════════════════════════════════════════════════════════════════
  function limpiarFiltros() {
    if (filtroMetodo) filtroMetodo.value = '';
    if (ocultarAnuladas) ocultarAnuladas.checked = true;
    aplicarFiltros();
  }

  // ═══════════════════════════════════════════════════════════════════
  // EVENT LISTENERS
  // ═══════════════════════════════════════════════════════════════════
  if (filtroMetodo) {
    filtroMetodo.addEventListener('change', aplicarFiltros);
  }

  if (ocultarAnuladas) {
    ocultarAnuladas.addEventListener('change', aplicarFiltros);
  }

  if (btnLimpiar) {
    btnLimpiar.addEventListener('click', limpiarFiltros);
  }

  // ═══════════════════════════════════════════════════════════════════
  // TOOLTIPS (para hints)
  // ═══════════════════════════════════════════════════════════════════
  const hints = document.querySelectorAll('.hint[title]');
  hints.forEach(hint => {
    // Mejorar accesibilidad
    hint.setAttribute('role', 'tooltip');
    hint.setAttribute('tabindex', '0');
    
    // En móviles, mostrar tooltip al tap
    hint.addEventListener('click', function(e) {
      e.preventDefault();
      const title = this.getAttribute('title');
      if (title) {
        // Crear tooltip temporal
        showTooltip(this, title);
      }
    });
  });

  function showTooltip(element, text) {
    // Remover tooltips existentes
    const existingTooltip = document.querySelector('.flus-tooltip');
    if (existingTooltip) existingTooltip.remove();

    const tooltip = document.createElement('div');
    tooltip.className = 'flus-tooltip';
    tooltip.textContent = text;

    document.body.appendChild(tooltip);

    // Posicionar
    const rect = element.getBoundingClientRect();
    const tooltipRect = tooltip.getBoundingClientRect();
    
    let top = rect.bottom + window.scrollY + 8;
    let left = rect.left + window.scrollX + (rect.width / 2) - (tooltipRect.width / 2);

    // Ajustar si se sale de la pantalla
    if (left < 10) left = 10;
    if (left + tooltipRect.width > window.innerWidth - 10) {
      left = window.innerWidth - tooltipRect.width - 10;
    }

    tooltip.style.top = top + 'px';
    tooltip.style.left = left + 'px';

    // Función para limpiar tooltip y listener
    function cleanup() {
      if (tooltip.parentNode) {
        tooltip.remove();
      }
      document.removeEventListener('click', handleOutsideClick);
    }

    // Handler para click fuera
    function handleOutsideClick(e) {
      if (!tooltip.contains(e.target) && e.target !== element) {
        cleanup();
      }
    }

    // Remover después de 3 segundos
    const timeoutId = setTimeout(cleanup, 3000);

    // También remover al hacer click fuera
    document.addEventListener('click', handleOutsideClick);
  }

  // ═══════════════════════════════════════════════════════════════════
  // INICIALIZACIÓN
  // ═══════════════════════════════════════════════════════════════════
  // Aplicar filtros iniciales (ocultar anuladas por defecto)
  aplicarFiltros();

})();

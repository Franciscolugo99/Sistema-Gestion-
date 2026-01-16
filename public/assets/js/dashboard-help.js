/**
 * dashboard-help.js - Sistema de ayuda contextual para Dashboard FLUS
 * 
 * Incluye:
 * - Tooltips mejorados con información detallada
 * - Panel de ayuda rápida
 * - Tour guiado para nuevos usuarios
 * 
 * INSTRUCCIONES: Incluir este archivo después de dashboard.js
 */

(function() {
  'use strict';

  // ===========================================
  // DEFINICIONES DE AYUDA (Tooltips)
  // ===========================================
  const KPI_HELP = {
    ventas: {
      title: '¿Qué son las Ventas?',
      desc: 'Número total de tickets o transacciones completadas en el período.',
      calc: 'Cuenta de ventas con estado EMITIDA',
      tip: 'Compara con períodos anteriores para identificar tendencias de crecimiento.'
    },
    facturacion: {
      title: '¿Qué es la Facturación?',
      desc: 'Suma total del dinero recibido por todas las ventas. Es tu ingreso bruto.',
      calc: 'Σ (total de cada venta emitida)',
      tip: 'Para conocer tu ganancia real, revisa el margen después de descontar costos.'
    },
    ticket_promedio: {
      title: '¿Qué es el Ticket Promedio?',
      desc: 'Cuánto gasta en promedio cada cliente por compra.',
      calc: 'Facturación ÷ Número de ventas',
      tip: 'Para aumentarlo: ofrece combos, productos complementarios o promociones por monto mínimo.'
    },
    unidades: {
      title: '¿Qué son las Unidades Vendidas?',
      desc: 'Cantidad total de productos vendidos (suma de todos los items).',
      calc: 'Σ (cantidad de cada línea de venta)',
      tip: 'Útil para planificar reposición de stock.'
    },
    ganancia: {
      title: '¿Qué es la Ganancia Bruta?',
      desc: 'Lo que queda después de restar el costo de la mercadería vendida.',
      calc: 'Facturación − Costo total de productos',
      tip: 'Si es negativa, estás vendiendo por debajo del costo. ¡Revisa tus precios!'
    },
    margen: {
      title: '¿Qué es el Margen?',
      desc: 'Porcentaje de ganancia sobre cada peso vendido.',
      calc: '(Ganancia ÷ Facturación) × 100',
      tip: 'Un margen del 30-40% es saludable para retail. Menos del 20% puede ser problemático.'
    },
    costos: {
      title: '¿Qué es el Total de Costos?',
      desc: 'Lo que pagaste por la mercadería que vendiste.',
      calc: 'Σ (cantidad × costo unitario)',
      tip: 'Mantén actualizados los costos de productos para cálculos precisos.'
    },
    descuentos: {
      title: '¿Qué son los Descuentos?',
      desc: 'Dinero que dejaste de cobrar por promociones activas.',
      calc: 'Σ (descuentos de promociones)',
      tip: 'Verifica que las promos generen más ventas de las que cuestan.'
    },
    anulaciones: {
      title: '¿Qué son las Ventas Anuladas?',
      desc: 'Ventas que se cancelaron después de emitirse.',
      calc: 'Cuenta de ventas con estado ANULADA',
      tip: 'Una tasa mayor al 5% indica problemas. Investiga las causas.'
    },
    tasa_anulacion: {
      title: '¿Qué es la Tasa de Anulación?',
      desc: 'Porcentaje de ventas anuladas respecto al total.',
      calc: '(Anuladas ÷ Total) × 100',
      tip: 'Menos del 2% es excelente, 2-5% normal, más del 5% requiere atención.'
    },
    monto_anulado: {
      title: '¿Qué es el Monto Anulado?',
      desc: 'Valor total de las ventas que fueron canceladas.',
      calc: 'Σ (total de ventas anuladas)',
      tip: 'Representa dinero que esperabas recibir pero no se concretó.'
    }
  };

  // ===========================================
  // PREGUNTAS FRECUENTES
  // ===========================================
  const FAQ = [
    {
      q: '¿Cómo cambio el período de fechas?',
      a: 'Usa los botones rápidos (Hoy, 7d, 30d) o selecciona fechas personalizadas con los campos "Desde" y "Hasta".'
    },
    {
      q: '¿Qué significa el porcentaje en verde/rojo?',
      a: 'Muestra la variación respecto al período anterior. Verde = mejoró, Rojo = empeoró, Gris = sin cambio.'
    },
    {
      q: '¿Por qué mis costos aparecen en $0?',
      a: 'Asegúrate de que tus productos tengan el campo "costo" configurado correctamente.'
    },
    {
      q: '¿Qué son los productos dormidos?',
      a: 'Son productos con stock pero sin ventas en los últimos 30 días. Considera promocionarlos o liquidarlos.'
    },
    {
      q: '¿Cómo exporto los datos?',
      a: 'Haz clic en "Exportar" y selecciona el tipo de reporte que necesitas. Se descargará un archivo CSV.'
    },
    {
      q: '¿Los datos se actualizan en tiempo real?',
      a: 'Los datos se actualizan cada vez que recargas la página. Hay un cache de 5 minutos para mejorar rendimiento.'
    }
  ];

  // ===========================================
  // TOUR GUIADO
  // ===========================================
  const TOUR_STEPS = [
    {
      element: '.dash-kpi-row:first-of-type',
      title: 'Métricas principales',
      content: 'Aquí ves un resumen rápido: ventas totales, facturación, ticket promedio y unidades vendidas. Haz clic en el "?" para más detalles.'
    },
    {
      element: '.dash-presets',
      title: 'Filtros rápidos',
      content: 'Usa estos botones para cambiar rápidamente el período: hoy, última semana, mes, etc.'
    },
    {
      element: '.cierre-caja-section',
      title: 'Cierre de Caja',
      content: 'Resumen del día actual: cuánto vendiste, cuánto en efectivo vs otros medios. Ideal para el arqueo.'
    },
    {
      element: '.insights-container',
      title: 'Insights automáticos',
      content: 'El sistema analiza tus datos y te da recomendaciones accionables.'
    },
    {
      element: '.dash-export-dd',
      title: 'Exportar datos',
      content: 'Descarga reportes en CSV para análisis más detallados o para compartir.'
    }
  ];

  // ===========================================
  // INICIALIZACIÓN
  // ===========================================
  function initDashboardHelp() {
    initTooltips();
    initQuickHelp();
    checkFirstVisit();
  }

  // ===========================================
  // SISTEMA DE TOOLTIPS
  // ===========================================
  let activeTooltip = null;
  let tooltipEl = null;

  function initTooltips() {
    // Crear el elemento tooltip si no existe
    tooltipEl = document.createElement('div');
    tooltipEl.className = 'kpi-tooltip';
    tooltipEl.setAttribute('role', 'tooltip');
    tooltipEl.setAttribute('aria-hidden', 'true');
    document.body.appendChild(tooltipEl);

    // Event delegation para botones de ayuda
    document.addEventListener('click', handleTooltipClick);
    document.addEventListener('keydown', handleTooltipKeyboard);

    // Cerrar al hacer clic fuera
    document.addEventListener('click', (e) => {
      if (activeTooltip && !e.target.closest('.kpi-help') && !e.target.closest('.kpi-tooltip')) {
        hideTooltip();
      }
    });
  }

  function handleTooltipClick(e) {
    const helpBtn = e.target.closest('.kpi-help');
    if (!helpBtn) return;

    e.preventDefault();
    e.stopPropagation();

    const key = helpBtn.dataset.tooltip;
    if (!key || !KPI_HELP[key]) return;

    if (activeTooltip === helpBtn) {
      hideTooltip();
    } else {
      showTooltip(helpBtn, KPI_HELP[key]);
    }
  }

  function handleTooltipKeyboard(e) {
    if (e.key === 'Escape' && activeTooltip) {
      hideTooltip();
    }
  }

  function showTooltip(btn, data) {
    if (activeTooltip) hideTooltip();

    activeTooltip = btn;
    btn.setAttribute('aria-expanded', 'true');

    tooltipEl.innerHTML = `
      <div class="kpi-tooltip-title">${escapeHtml(data.title)}</div>
      <div class="kpi-tooltip-desc">${escapeHtml(data.desc)}</div>
      <div class="kpi-tooltip-calc"><strong>Cálculo:</strong> ${escapeHtml(data.calc)}</div>
      <div class="kpi-tooltip-tip">${escapeHtml(data.tip)}</div>
    `;

    positionTooltip(btn);
    tooltipEl.classList.add('is-show');
    tooltipEl.setAttribute('aria-hidden', 'false');
  }

  function hideTooltip() {
    if (activeTooltip) {
      activeTooltip.setAttribute('aria-expanded', 'false');
      activeTooltip = null;
    }
    tooltipEl.classList.remove('is-show');
    tooltipEl.setAttribute('aria-hidden', 'true');
  }

  function positionTooltip(btn) {
    const rect = btn.getBoundingClientRect();
    const tooltipRect = tooltipEl.getBoundingClientRect();
    const padding = 12;

    let top = rect.bottom + padding;
    let left = rect.left - (tooltipRect.width / 2) + (rect.width / 2);

    // Ajustar si se sale de la pantalla
    if (left < padding) left = padding;
    if (left + tooltipRect.width > window.innerWidth - padding) {
      left = window.innerWidth - tooltipRect.width - padding;
    }

    if (top + tooltipRect.height > window.innerHeight - padding) {
      top = rect.top - tooltipRect.height - padding;
    }

    tooltipEl.style.top = `${top}px`;
    tooltipEl.style.left = `${left}px`;
  }

  // ===========================================
  // PANEL DE AYUDA RÁPIDA
  // ===========================================
  function initQuickHelp() {
    // Crear botón flotante
    const toggleBtn = document.createElement('button');
    toggleBtn.className = 'quick-help-toggle';
    toggleBtn.setAttribute('aria-label', 'Abrir ayuda');
    toggleBtn.setAttribute('aria-expanded', 'false');
    toggleBtn.innerHTML = '?';
    document.body.appendChild(toggleBtn);

    // Crear panel
    const panel = document.createElement('div');
    panel.className = 'quick-help-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'Ayuda rápida');
    panel.innerHTML = `
      <div class="quick-help-header">
        <span>❓</span> Ayuda Rápida
      </div>
      <div class="quick-help-content">
        ${FAQ.map((item, i) => `
          <div class="quick-help-item" data-index="${i}">
            <div class="quick-help-q">${escapeHtml(item.q)}</div>
            <div class="quick-help-a">${escapeHtml(item.a)}</div>
          </div>
        `).join('')}
        <div class="quick-help-item" style="padding-top: 8px; border-top: 1px solid rgba(148,163,184,0.15);">
          <button type="button" class="tour-start-btn" style="
            width: 100%;
            padding: 12px;
            border-radius: 10px;
            border: 1px solid rgba(56, 189, 248, 0.3);
            background: rgba(56, 189, 248, 0.1);
            color: var(--accent-color, #38bdf8);
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.2s ease;
          ">
            🎯 Iniciar tour guiado
          </button>
        </div>
      </div>
    `;
    document.body.appendChild(panel);

    // Event listeners
    toggleBtn.addEventListener('click', () => {
      const isOpen = panel.classList.toggle('is-open');
      toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      toggleBtn.innerHTML = isOpen ? '✕' : '?';
    });

    // FAQ acordeón
    panel.querySelectorAll('.quick-help-q').forEach(q => {
      q.addEventListener('click', () => {
        const item = q.closest('.quick-help-item');
        item.classList.toggle('is-open');
      });
    });

    // Botón de tour
    panel.querySelector('.tour-start-btn')?.addEventListener('click', () => {
      panel.classList.remove('is-open');
      toggleBtn.setAttribute('aria-expanded', 'false');
      toggleBtn.innerHTML = '?';
      startTour();
    });
  }

  // ===========================================
  // TOUR GUIADO
  // ===========================================
  let tourOverlay = null;
  let tourSpotlight = null;
  let tourTooltip = null;
  let currentStep = 0;

  function checkFirstVisit() {
    const visited = localStorage.getItem('flus-dashboard-visited');
    if (!visited) {
      // Mostrar sugerencia de tour después de 2 segundos
      setTimeout(() => {
        if (confirm('¿Es tu primera vez en el dashboard? ¿Te gustaría un tour guiado?')) {
          startTour();
        }
        localStorage.setItem('flus-dashboard-visited', '1');
      }, 2000);
    }
  }

  function startTour() {
    currentStep = 0;
    createTourElements();
    showTourStep(currentStep);
  }

  function createTourElements() {
    if (tourOverlay) return;

    tourOverlay = document.createElement('div');
    tourOverlay.className = 'dashboard-tour-overlay';
    document.body.appendChild(tourOverlay);

    tourSpotlight = document.createElement('div');
    tourSpotlight.className = 'dashboard-tour-spotlight';
    document.body.appendChild(tourSpotlight);

    tourTooltip = document.createElement('div');
    tourTooltip.className = 'dashboard-tour-tooltip';
    document.body.appendChild(tourTooltip);
  }

  function showTourStep(index) {
    const step = TOUR_STEPS[index];
    if (!step) {
      endTour();
      return;
    }

    const element = document.querySelector(step.element);
    if (!element) {
      // Skip si el elemento no existe
      nextTourStep();
      return;
    }

    // Scroll al elemento
    element.scrollIntoView({ behavior: 'smooth', block: 'center' });

    setTimeout(() => {
      const rect = element.getBoundingClientRect();
      const padding = 8;

      // Posicionar spotlight
      tourSpotlight.style.top = `${rect.top - padding}px`;
      tourSpotlight.style.left = `${rect.left - padding}px`;
      tourSpotlight.style.width = `${rect.width + padding * 2}px`;
      tourSpotlight.style.height = `${rect.height + padding * 2}px`;

      // Contenido del tooltip
      tourTooltip.innerHTML = `
        <div class="tour-step-counter">Paso ${index + 1} de ${TOUR_STEPS.length}</div>
        <div class="tour-title">${escapeHtml(step.title)}</div>
        <div class="tour-content">${escapeHtml(step.content)}</div>
        <div class="tour-actions">
          <button type="button" class="tour-btn tour-btn-skip">Salir</button>
          <button type="button" class="tour-btn tour-btn-next">
            ${index === TOUR_STEPS.length - 1 ? 'Finalizar' : 'Siguiente →'}
          </button>
        </div>
      `;

      // Posicionar tooltip
      const tooltipTop = rect.bottom + padding + 12;
      tourTooltip.style.top = `${tooltipTop}px`;
      tourTooltip.style.left = `${Math.max(16, rect.left)}px`;

      // Event listeners
      tourTooltip.querySelector('.tour-btn-skip').addEventListener('click', endTour);
      tourTooltip.querySelector('.tour-btn-next').addEventListener('click', nextTourStep);
    }, 300);
  }

  function nextTourStep() {
    currentStep++;
    showTourStep(currentStep);
  }

  function endTour() {
    if (tourOverlay) {
      tourOverlay.remove();
      tourOverlay = null;
    }
    if (tourSpotlight) {
      tourSpotlight.remove();
      tourSpotlight = null;
    }
    if (tourTooltip) {
      tourTooltip.remove();
      tourTooltip = null;
    }
  }

  // ===========================================
  // UTILIDADES
  // ===========================================
  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // ===========================================
  // INIT
  // ===========================================
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDashboardHelp);
  } else {
    initDashboardHelp();
  }

  // Exponer para uso externo
  window.DashboardHelp = {
    showTooltip,
    hideTooltip,
    startTour,
    endTour
  };

})();
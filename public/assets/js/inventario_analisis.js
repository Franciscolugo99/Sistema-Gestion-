/*
 * inventario_analisis.js
 * Dashboard de Análisis de Inventario (FLUS)
 * v2.0.x
 *
 * Fix principal:
 * - Cuando FLUS cambia de tema (claro/oscuro) NO se recarga la página.
 * - Chart.js no actualiza colores (ticks/tooltip) automáticamente.
 * - Este script observa el cambio de tema y recrea los charts.
 *
 * Compatible con:
 * - html.dark / body.dark
 * - data-theme="dark" en html/body
 */

(function () {
  'use strict';

  const charts = {
    categorias: null,
    proveedores: null,
    tendencia: null,
  };

  let lastThemeKey = null;
  let rebuildTimer = null;

  /* =========================
     THEME DETECTION
  ========================= */
  function isDarkMode() {
    const html = document.documentElement;
    const body = document.body;
    const htmlDark = html.classList.contains('dark') || html.classList.contains('theme-dark');
    const bodyDark = body && (body.classList.contains('dark') || body.classList.contains('theme-dark'));
    const htmlData = (html.getAttribute('data-theme') || '').toLowerCase() === 'dark';
    const bodyData = body && ((body.getAttribute('data-theme') || '').toLowerCase() === 'dark');
    return !!(htmlDark || bodyDark || htmlData || bodyData);
  }

  function themeKey() {
    return isDarkMode() ? 'dark' : 'light';
  }

  function applyChartThemeDefaults() {
    if (!window.Chart) return;

    const dark = isDarkMode();

    // Colores base (afectan ticks/labels por default)
    window.Chart.defaults.color = dark ? 'rgba(255,255,255,0.78)' : '#475569';
    window.Chart.defaults.borderColor = dark ? 'rgba(255,255,255,0.10)' : 'rgba(15,23,42,0.12)';

    // Fuente: hereda del body para mantener look FLUS
    try {
      const ff = getComputedStyle(document.body).fontFamily;
      if (ff) window.Chart.defaults.font.family = ff;
    } catch (_) {}
  }

  function getTooltipTheme() {
    const dark = isDarkMode();
    return {
      backgroundColor: dark ? 'rgba(15,23,42,0.92)' : 'rgba(255,255,255,0.96)',
      titleColor: dark ? 'rgba(255,255,255,0.92)' : '#0f172a',
      bodyColor: dark ? 'rgba(255,255,255,0.80)' : '#334155',
      borderColor: dark ? 'rgba(255,255,255,0.12)' : 'rgba(15,23,42,0.12)',
    };
  }

  function getScaleTheme() {
    const dark = isDarkMode();
    return {
      tickColor: dark ? 'rgba(255,255,255,0.72)' : '#64748b',
      gridColor: dark ? 'rgba(255,255,255,0.10)' : 'rgba(15,23,42,0.08)',
      borderColor: dark ? 'rgba(255,255,255,0.10)' : 'rgba(15,23,42,0.10)',
    };
  }

  /* =========================
     HELPERS
  ========================= */
  function formatMoney(value) {
    return new Intl.NumberFormat('es-AR', {
      style: 'currency',
      currency: 'ARS',
      minimumFractionDigits: 0,
    }).format(value);
  }

  function formatNumber(value) {
    return new Intl.NumberFormat('es-AR').format(value);
  }

  function truncate(str, len) {
    if (!str) return '';
    return str.length > len ? str.substring(0, len) + '...' : str;
  }

  function getColorPalette() {
    return [
      '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6',
      '#06b6d4', '#f97316', '#84cc16', '#ec4899', '#6366f1'
    ];
  }

  function destroyCharts() {
    Object.keys(charts).forEach((k) => {
      if (charts[k] && typeof charts[k].destroy === 'function') {
        try { charts[k].destroy(); } catch (_) {}
      }
      charts[k] = null;
    });
  }

  /* =========================
     CHARTS
  ========================= */
  function createCustomLegend(containerId, labels, colors, values) {
    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = '';
    labels.forEach((label, i) => {
      const item = document.createElement('div');
      item.className = 'inv-legend-item';

      const colorBox = document.createElement('span');
      colorBox.className = 'inv-legend-color';
      colorBox.style.backgroundColor = colors[i];

      const text = document.createElement('span');
      text.textContent = `${label}: ${values[i]}%`;

      item.appendChild(colorBox);
      item.appendChild(text);
      container.appendChild(item);
    });
  }

  function initCharts() {
    const data = window.FLUS_INV_DATA || {};

    // No hay datos o no está Chart.js aún
    if (!window.Chart) return;

    // Si no hay canvas, no hacemos nada
    const hasAnyCanvas =
      document.getElementById('chartCategorias') ||
      document.getElementById('chartProveedores') ||
      document.getElementById('chartTendencia');
    if (!hasAnyCanvas) return;

    applyChartThemeDefaults();

    const tooltipTheme = getTooltipTheme();
    const scaleTheme = getScaleTheme();

    // Chart Categorías (pie)
    const ctxCat = document.getElementById('chartCategorias');
    if (ctxCat && data.categorias) {
      const categorias = data.categorias;
      const labels = categorias.map((c) => truncate(c.categoria, 12));
      // Back-end devuelve {inversion} (InventarioAnalisis.php). Algunos builds viejos devolvían {total}.
      const values = categorias.map((c) => Number(c.inversion ?? c.total ?? 0));
      const total = values.reduce((a, b) => a + b, 0);
      const percentages = total > 0 ? values.map((v) => ((v / total) * 100).toFixed(1)) : values.map(() => '0.0');

      const colors = getColorPalette();

      charts.categorias = new Chart(ctxCat, {
        type: 'doughnut',
        data: {
          labels,
          datasets: [{
            data: values,
            backgroundColor: colors,
            borderWidth: 2,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '65%',
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: tooltipTheme.backgroundColor,
              titleColor: tooltipTheme.titleColor,
              bodyColor: tooltipTheme.bodyColor,
              borderColor: tooltipTheme.borderColor,
              borderWidth: 1,
              callbacks: {
                label: function (context) {
                  const value = context.raw;
                  const pct = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';
                  return `${pct}% (${formatMoney(value)})`;
                }
              }
            }
          }
        }
      });

      createCustomLegend('legendCategorias', labels, colors, percentages);
    }

    // Chart Proveedores (bar)
    const ctxProv = document.getElementById('chartProveedores');
    if (ctxProv && data.proveedores) {
      const proveedores = data.proveedores.slice(0, 8);

      const valuesProv = proveedores.map((p) => Number(p.inversion ?? p.total ?? 0));

      charts.proveedores = new Chart(ctxProv, {
        type: 'bar',
        data: {
          labels: proveedores.map((p) => truncate(p.proveedor, 10)),
          datasets: [{
            label: 'Inversión',
            data: valuesProv,
            backgroundColor: '#3b82f6',
            borderRadius: 8,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: tooltipTheme.backgroundColor,
              titleColor: tooltipTheme.titleColor,
              bodyColor: tooltipTheme.bodyColor,
              borderColor: tooltipTheme.borderColor,
              borderWidth: 1,
              callbacks: {
                label: function (context) {
                  return formatMoney(context.raw);
                }
              }
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              grid: { color: scaleTheme.gridColor },
              border: { color: scaleTheme.borderColor },
              ticks: {
                color: scaleTheme.tickColor,
                callback: function (value) {
                  return '$' + formatNumber(value);
                }
              }
            },
            x: {
              grid: { display: false },
              border: { color: scaleTheme.borderColor },
              ticks: { color: scaleTheme.tickColor }
            }
          }
        }
      });
    }

    // Chart Tendencia (line)
    const ctxTend = document.getElementById('chartTendencia');
    if (ctxTend && data.tendencia) {
      charts.tendencia = new Chart(ctxTend, {
        type: 'line',
        data: {
          labels: data.tendencia.map((d) => d.fecha),
          datasets: [{
            label: 'Ventas',
            // Back-end devuelve {total_vendido} (InventarioAnalisis.php). Algunos builds viejos devolvían {total}.
            data: data.tendencia.map((d) => Number(d.total_vendido ?? d.total ?? 0)),
            borderColor: '#10b981',
            backgroundColor: 'rgba(16, 185, 129, 0.10)',
            tension: 0.4,
            fill: true,
            pointRadius: 3,
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: tooltipTheme.backgroundColor,
              titleColor: tooltipTheme.titleColor,
              bodyColor: tooltipTheme.bodyColor,
              borderColor: tooltipTheme.borderColor,
              borderWidth: 1,
              callbacks: {
                label: function (context) {
                  return formatMoney(context.raw);
                }
              }
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              grid: { color: scaleTheme.gridColor },
              border: { color: scaleTheme.borderColor },
              ticks: {
                color: scaleTheme.tickColor,
                callback: function (value) {
                  return '$' + formatNumber(value);
                }
              }
            },
            x: {
              grid: { display: false },
              border: { color: scaleTheme.borderColor },
              ticks: { color: scaleTheme.tickColor }
            }
          }
        }
      });
    }
  }

  /* =========================
     THEME OBSERVER (rebuild)
  ========================= */
  function scheduleRebuild() {
    if (rebuildTimer) clearTimeout(rebuildTimer);
    rebuildTimer = setTimeout(() => {
      // Re-crear charts con el tema ya aplicado
      destroyCharts();
      initCharts();
    }, 120);
  }

  function setupThemeObserver() {
    const html = document.documentElement;
    const body = document.body;

    lastThemeKey = themeKey();

    const obs = new MutationObserver(() => {
      const k = themeKey();
      if (k !== lastThemeKey) {
        lastThemeKey = k;
        scheduleRebuild();
      }
    });

    obs.observe(html, { attributes: true, attributeFilter: ['class', 'data-theme'] });
    if (body) obs.observe(body, { attributes: true, attributeFilter: ['class', 'data-theme'] });
  }

  function waitForChart(maxMs = 2500) {
    return new Promise((resolve, reject) => {
      const start = Date.now();
      const timer = setInterval(() => {
        if (window.Chart) {
          clearInterval(timer);
          resolve();
          return;
        }
        if (Date.now() - start > maxMs) {
          clearInterval(timer);
          reject(new Error('Chart.js no disponible'));
        }
      }, 50);
    });
  }

  function printInventoryReport() {
    document.body.classList.add('printing');
    setTimeout(() => {
      window.print();
      document.body.classList.remove('printing');
    }, 100);
  }

  function buildUrlWithParam(baseUrl, key, value) {
    const separator = baseUrl.includes('?') ? (baseUrl.endsWith('?') || baseUrl.endsWith('&') ? '' : '&') : '?';
    return `${baseUrl}${separator}${encodeURIComponent(key)}=${encodeURIComponent(value)}`;
  }

  function setupInventoryControls() {
    document.querySelectorAll('[data-inv-print]').forEach((btn) => {
      btn.addEventListener('click', printInventoryReport);
    });

    document.querySelectorAll('[data-inv-refresh]').forEach((btn) => {
      btn.addEventListener('click', () => window.location.reload());
    });

    document.querySelectorAll('[data-inv-auto-submit]').forEach((select) => {
      select.addEventListener('change', () => {
        if (select.form) select.form.submit();
      });
    });

    document.querySelectorAll('[data-inv-order-url]').forEach((select) => {
      select.addEventListener('change', () => {
        const baseUrl = select.getAttribute('data-inv-order-url') || '?';
        window.location.href = buildUrlWithParam(baseUrl, 'orden', select.value);
      });
    });
  }

  /* =========================
     INIT
  ========================= */
  document.addEventListener('DOMContentLoaded', () => {
    setupInventoryControls();

    // Esperamos a Chart.js si se carga después del script.
    waitForChart().catch(() => {}).finally(() => {
      initCharts();
      setupThemeObserver();
    });

    // Funciones globales
    window.scrollToSection = function (id) {
      const el = document.getElementById(id);
      if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        el.classList.add('highlight-section');
        setTimeout(() => el.classList.remove('highlight-section'), 2000);
      }
    };

    window.exportarPDF = printInventoryReport;

    // Estilos dinámicos
    const style = document.createElement('style');
    style.textContent = `
      .highlight-section { animation: highlight 2s ease; }
      @keyframes highlight {
        0%, 100% { box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        50% { box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.4); }
      }
      body.printing .page-actions,
      body.printing .inv-tabs,
      body.printing .inv-filters-panel,
      body.printing .btn { display: none !important; }
    `;
    document.head.appendChild(style);
  });

})();

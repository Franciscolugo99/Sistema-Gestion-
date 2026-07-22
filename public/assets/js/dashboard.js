// public/assets/js/dashboard.js - v3 con soporte para filtro categoría
if (window.__flus_dashboard_js_loaded) {
  // evita doble carga accidental
} else {
  window.__flus_dashboard_js_loaded = true;

  let __dashCharts = [];

  function bootDashboard() {
    const data = window.dashboardData || {};

    initPresetChips(data);
    initToast();
    initExportLinks();
    initSparklines();
    initCategoryFilter();
    initTopProductControls(data);
    initScrollShadows();
    initTimeFilters();

    renderAllCharts(data);

    watchThemeChanges(() => {
      renderAllCharts(data);
      initSparklines();
    });
  }

  function scheduleDashboardBoot() {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", bootDashboard, { once: true });
    } else {
      bootDashboard();
    }
  }

/* =========================
   HELPERS NUM / FORMAT
========================= */
const nfMoney0 = new Intl.NumberFormat("es-AR", { maximumFractionDigits: 0 });
const nfNum1 = new Intl.NumberFormat("es-AR", { maximumFractionDigits: 1 });

function num(v, fallback = 0) {
  const n = typeof v === "number" ? v : parseFloat(v);
  return Number.isFinite(n) ? n : fallback;
}

function numFromText(value, fallback = 0) {
  if (typeof value === "number") return Number.isFinite(value) ? value : fallback;
  const text = String(value ?? "").trim();
  if (!text) return fallback;

  const normalized = text
    .replace(/\$/g, "")
    .replace(/\s+/g, "")
    .replace(/\./g, "")
    .replace(/,/g, ".")
    .replace(/[^\d.-]/g, "");

  const n = parseFloat(normalized);
  return Number.isFinite(n) ? n : fallback;
}

function hasSomePositive(arr) {
  return Array.isArray(arr) && arr.length > 0 && arr.some((v) => num(v, 0) > 0);
}

function escapeHtml(value) {
  return String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}

/* =========================
   THEME
========================= */
function getTheme() {
  const t =
    document.documentElement.dataset.theme ||
    document.body.dataset.theme ||
    localStorage.getItem("flus-theme") ||
    "dark";
  return String(t).toLowerCase() === "light" ? "light" : "dark";
}

function applyChartDefaults(theme) {
  const textColor = theme === "light" ? "#0f172a" : "#e5e7eb";
  const gridColor =
    theme === "light" ? "rgba(148,163,184,0.55)" : "rgba(148,163,184,0.28)";

  Chart.defaults.color = textColor;
  Chart.defaults.borderColor = gridColor;
  Chart.defaults.font.family =
    'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
  Chart.defaults.font.size = 12;

  return { textColor, gridColor };
}

function destroyCharts() {
  __dashCharts.forEach((c) => {
    try {
      c.destroy();
    } catch {}
  });
  __dashCharts = [];
}

function watchThemeChanges(onChange) {
  let t = null;
  const debounced = () => {
    clearTimeout(t);
    t = setTimeout(onChange, 60);
  };

  const obs = new MutationObserver((mutations) => {
    for (const m of mutations) {
      if (m.type === "attributes" && m.attributeName === "data-theme") {
        debounced();
        return;
      }
    }
  });

  obs.observe(document.documentElement, { attributes: true });
  obs.observe(document.body, { attributes: true });
}

/* =========================
   EMPTY STATE
========================= */
function getOrCreateEmptyMsg(canvas, emptyId, text = "Sin datos") {
  if (!canvas) return null;

  if (emptyId) {
    const e = document.getElementById(emptyId);
    if (e) return e;
  }

  const wrap =
    canvas.closest(".chart-wrap") ||
    canvas.closest(".chart-wrap-wide") ||
    canvas.parentElement;
  if (!wrap) return null;

  let e = wrap.querySelector(".chart-empty");
  if (!e) {
    e = document.createElement("div");
    e.className = "chart-empty";
    e.style.display = "none";
    e.style.placeItems = "center";
    e.style.textAlign = "center";
    e.style.opacity = "0.75";
    e.style.padding = "18px";
    wrap.appendChild(e);
  }
  e.textContent = text;
  return e;
}

function getDetailRows(detailsId) {
  const detail = document.getElementById(detailsId);
  if (!detail) return [];

  const rows = Array.from(detail.querySelectorAll("tbody tr"));
  return rows.filter((row) => !row.querySelector(".muted"));
}

function updateCardSub(canvasId, text) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;
  const card = canvas.closest(".dash-card");
  if (!card) return;
  const sub = card.querySelector(".dash-card-sub");
  if (sub && text) sub.textContent = text;
}

function getDataWithDomFallback(data) {
  const resolved = { ...(data || {}) };

  if (!hasSomePositive(resolved.ventasData)) {
    const rows = getDetailRows("chartVentasData");
    if (rows.length) {
      resolved.ventasLabels = rows.map((row) => row.cells[0]?.textContent?.trim() || "");
      resolved.ventasData = rows.map((row) => numFromText(row.cells[1]?.textContent, 0));
    }
  }

  if (!hasSomePositive(resolved.topProdData) && !Array.isArray(resolved.topProdRows) && !resolved.topProdSets) {
    const rows = getDetailRows("chartTopProductosData");
    if (rows.length) {
      resolved.topProdLabels = rows.map((row) => row.cells[0]?.textContent?.trim() || "");
      resolved.topProdData = rows.map((row) => numFromText(row.cells[1]?.textContent, 0));
    }
  }

  if (!Array.isArray(resolved.metodosPago) || !hasSomePositive(resolved.metodosPago.map((m) => num(m?.monto, 0)))) {
    const rows = getDetailRows("chartMetodosPagoData");
    if (rows.length) {
      resolved.metodosPago = rows.map((row) => ({
        medio_pago: row.cells[0]?.textContent?.trim() || "N/A",
        monto: numFromText(row.cells[1]?.textContent, 0),
      }));
    }
  }

  if (!Array.isArray(resolved.categorias) || !hasSomePositive(resolved.categorias.map((c) => num(c?.ventas ?? c?.monto, 0)))) {
    const rows = getDetailRows("chartCategoriasData");
    if (rows.length) {
      resolved.categorias = rows.map((row) => ({
        categoria: row.cells[0]?.textContent?.trim() || "Sin categoria",
        ventas: numFromText(row.cells[1]?.textContent, 0),
      }));
    }
  }

  if (!Array.isArray(resolved.ventasPorHora) || !hasSomePositive(resolved.ventasPorHora.map((h) => num(h?.monto, 0)))) {
    const rows = getDetailRows("chartHorariosData");
    if (rows.length) {
      resolved.ventasPorHora = rows.map((row) => ({
        hora: parseInt((row.cells[0]?.textContent || "0").split(":")[0], 10) || 0,
        cantidad: numFromText(row.cells[1]?.textContent, 0),
        monto: numFromText(row.cells[2]?.textContent, 0),
      }));
    }
  }

  return resolved;
}

/* =========================
   SPARKLINES (HiDPI + clear + guardas)
========================= */
function initSparklines() {
  const sparklines = document.querySelectorAll(".mini-sparkline");

  sparklines.forEach((canvas) => {
    let values = [];
    try {
      values = JSON.parse(canvas.dataset.values || "[]");
    } catch {
      values = [];
    }
    if (!Array.isArray(values) || values.length === 0) return;

    const rect = canvas.getBoundingClientRect();
    const cssW = Math.max(
      80,
      Math.floor(rect.width || canvas.offsetWidth || 100)
    );
    const cssH = Math.max(
      26,
      Math.floor(rect.height || canvas.offsetHeight || 30)
    );

    const dpr = Math.max(1, window.devicePixelRatio || 1);
    canvas.width = Math.floor(cssW * dpr);
    canvas.height = Math.floor(cssH * dpr);
    canvas.style.width = cssW + "px";
    canvas.style.height = cssH + "px";

    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    ctx.setTransform(1, 0, 0, 1, 0, 0);
    ctx.scale(dpr, dpr);
    ctx.clearRect(0, 0, cssW, cssH);

    const theme = getTheme();
    const padding = 2;

    const vv = values.map((x) => num(x, 0));
    const max = Math.max(...vv, 1);
    const min = Math.min(...vv, 0);
    const range = max - min || 1;

    ctx.beginPath();
    ctx.strokeStyle = theme === "light" ? "#0891b2" : "#22d3ee";
    ctx.lineWidth = 1.5;

    if (vv.length === 1) {
      const x = cssW / 2;
      const y = cssH - padding - ((vv[0] - min) / range) * (cssH - padding * 2);
      ctx.arc(x, y, 2.2, 0, Math.PI * 2);
      ctx.fillStyle = ctx.strokeStyle;
      ctx.fill();
      return;
    }

    vv.forEach((val, idx) => {
      const x = (idx / (vv.length - 1)) * (cssW - padding * 2) + padding;
      const y = cssH - padding - ((val - min) / range) * (cssH - padding * 2);
      if (idx === 0) ctx.moveTo(x, y);
      else ctx.lineTo(x, y);
    });

    ctx.stroke();

    ctx.lineTo(cssW - padding, cssH - padding);
    ctx.lineTo(padding, cssH - padding);
    ctx.closePath();

    const gradient = ctx.createLinearGradient(0, 0, 0, cssH);
    gradient.addColorStop(
      0,
      theme === "light" ? "rgba(8,145,178,0.2)" : "rgba(34,211,238,0.2)"
    );
    gradient.addColorStop(1, "rgba(8,145,178,0)");
    ctx.fillStyle = gradient;
    ctx.fill();
  });
}

/* =========================
   🆕 FILTRO DE CATEGORÍA
========================= */
function initCategoryFilter() {
  const select = document.getElementById("dashCategoria");
  if (!select) return;
  
  select.addEventListener("change", function() {
    const form = document.getElementById("dashFilters");
    if (form) {
      const panel = document.querySelector(".dashboard-panel");
      if (panel) {
        panel.style.opacity = "0.6";
        panel.style.pointerEvents = "none";
      }
      form.submit();
    }
  });
}

function topProductMetricLabel(metric) {
  if (metric === "ganancia") return "Ganancia";
  if (metric === "ventas") return "Importe";
  return "Unidades";
}

function topProductMetricSuffix(metric) {
  return metric === "unidades" ? " un." : "";
}

function formatTopProductMetric(metric, value) {
  return metric === "unidades"
    ? nfNum1.format(num(value, 0))
    : "$ " + nfMoney0.format(num(value, 0));
}

function resolveTopProductRows(data) {
  const metric = String(data.topProdMetric || "unidades");
  const limit = Number.parseInt(data.topProdLimit, 10) || 10;
  const set = data.topProdSets?.[metric] || data.topProdSets?.unidades || {};
  const rows = Array.isArray(set.rows)
    ? set.rows.slice(0, limit)
    : (Array.isArray(data.topProdRows) ? data.topProdRows.slice(0, limit) : []);
  const effectiveMetric = String(set.effective_metric || metric);
  const labels = rows.map((row) => String(row?.nombre || ""));
  const values = rows.map((row) => num(row?.[effectiveMetric], 0));

  return { rows, labels, values, metric, effectiveMetric, limit };
}

function renderTopProductTable(data) {
  const tbody = document.getElementById("chartTopProductosRows");
  const summary = document.getElementById("chartTopProductosSummary");
  if (!tbody) return;

  const { rows, limit } = resolveTopProductRows(data);
  if (summary) {
    summary.textContent = rows.length
      ? `Ver datos (${rows.length} de ${limit} solicitados)`
      : "Ver datos";
  }

  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="4" class="muted">Sin datos</td></tr>';
    return;
  }

  tbody.innerHTML = rows.map((row) => `
    <tr>
      <td>${escapeHtml(String(row?.nombre || ""))}</td>
      <td>${escapeHtml(nfNum1.format(num(row?.unidades, 0)))}</td>
      <td>$ ${escapeHtml(nfMoney0.format(num(row?.ventas, 0)))}</td>
      <td>$ ${escapeHtml(nfMoney0.format(num(row?.ganancia, 0)))}</td>
    </tr>
  `).join("");
}

function applyTopProductSelection(data, options = {}) {
  const metricSelect = document.querySelector('select[name="top_metric"]');
  const limitSelect = document.querySelector('select[name="top_limit"]');
  const metric = String(options.metric || metricSelect?.value || data.topProdMetric || "unidades");
  const limit = Number.parseInt(String(options.limit || limitSelect?.value || data.topProdLimit || 10), 10);

  data.topProdMetric = ["unidades", "ventas", "ganancia"].includes(metric) ? metric : "unidades";
  data.topProdLimit = [5, 10, 15, 20].includes(limit) ? limit : 10;

  const resolved = resolveTopProductRows(data);
  data.topProdLabels = resolved.labels;
  data.topProdData = resolved.values;
  data.topProdRows = resolved.rows;
  data.topProdEffectiveMetric = resolved.effectiveMetric;

  renderTopProductTable(data);
  renderAllCharts(data);

  if (options.updateUrl !== false) {
    const url = new URL(window.location.href);
    url.searchParams.set("top_metric", data.topProdMetric);
    url.searchParams.set("top_limit", String(data.topProdLimit));
    window.history.replaceState({}, "", url);
  }
}

function initTopProductControls(data) {
  const resolved = resolveTopProductRows(data);
  data.topProdLabels = resolved.labels;
  data.topProdData = resolved.values;
  data.topProdRows = resolved.rows;
  data.topProdEffectiveMetric = resolved.effectiveMetric;
  renderTopProductTable(data);
  document.querySelectorAll("[data-dash-top-control]").forEach((control) => {
    control.addEventListener("change", function() {
      applyTopProductSelection(data);
    });
  });
}

/* =========================
   🆕 SCROLL SHADOWS
========================= */
function initScrollShadows() {
  const tables = document.querySelectorAll(".dormidos-table-wrap, .stock-table-wrap");
  
  tables.forEach(wrap => {
    const checkScroll = () => {
      const hasScroll = wrap.scrollWidth > wrap.clientWidth;
      const isScrolledRight = wrap.scrollLeft + wrap.clientWidth >= wrap.scrollWidth - 5;
      
      if (hasScroll && !isScrolledRight) {
        wrap.classList.add("has-scroll");
      } else {
        wrap.classList.remove("has-scroll");
      }
    };
    
    checkScroll();
    wrap.addEventListener("scroll", checkScroll);
    window.addEventListener("resize", checkScroll);
  });
}

/* =========================
   CHARTS
========================= */
function renderAllCharts(data) {
  if (typeof Chart === "undefined") return;

  destroyCharts();

  const loadingCards = Array.from(document.querySelectorAll(".dash-card"))
    .filter((c) => c.querySelector("canvas"));
  loadingCards.forEach((c) => c.classList.add("is-loading"));
  
  try {
    const theme = getTheme();
    const { textColor, gridColor } = applyChartDefaults(theme);
    const chartData = getDataWithDomFallback(data);

    const palette =
      theme === "light"
        ? {
            primary: "#0891b2",
            primaryFill: "rgba(8,145,178,0.18)",
            secondary: "#7c3aed",
            secondaryFill: "rgba(124,58,237,0.18)",
            accent: "#16a34a",
            warning: "#f59e0b",
            danger: "#ef4444",
            info: "#2563eb",
            donut: [
              "#0891b2",
              "#7c3aed",
              "#16a34a",
              "#f59e0b",
              "#ef4444",
              "#2563eb",
              "#06b6d4",
              "#8b5cf6",
              "#10b981",
              "#f97316",
            ],
            donutBorder: "rgba(255,255,255,0.9)",
          }
        : {
            primary: "#22d3ee",
            primaryFill: "rgba(34,211,238,0.18)",
            secondary: "#a78bfa",
            secondaryFill: "rgba(167,139,250,0.22)",
            accent: "#34d399",
            warning: "#fbbf24",
            danger: "#fb7185",
            info: "#60a5fa",
            donut: [
              "#22d3ee",
              "#a78bfa",
              "#34d399",
              "#fbbf24",
              "#fb7185",
              "#60a5fa",
              "#06b6d4",
              "#c084fc",
              "#10b981",
              "#f97316",
            ],
            donutBorder: "rgba(15,23,42,0.65)",
          };

    const _render = (fn) => { try { fn(); } catch (e) { console.error("[dashboard chart]", e); } };

    _render(() => renderLineChart(
      "chartVentas", "noVentasMsg",
      chartData.ventasLabels, chartData.ventasData,
      palette, textColor, gridColor
    ));
    _render(() => renderBarChart(
      "chartTopProductos", "noTopMsg",
      chartData.topProdLabels, chartData.topProdData,
      palette, textColor, gridColor
    ));
    _render(() => renderMetodosPago(chartData.metodosPago, palette, textColor));
    _render(() => renderCategorias(chartData.categorias, palette, textColor, gridColor));
    _render(() => renderHorarios(chartData.ventasPorHora, palette, textColor, gridColor));
    _render(() => renderProductosRentables(chartData.productosRentables, palette, textColor, gridColor));
  } finally {
    window.setTimeout(() => {
      loadingCards.forEach((c) => c.classList.remove("is-loading"));
    }, 0);
  }
}

/* =========================
   GRÁFICO: Ventas por día
========================= */
function renderLineChart(
  canvasId,
  emptyId,
  labels,
  values,
  palette,
  textColor,
  gridColor
) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;

  const empty = getOrCreateEmptyMsg(
    canvas,
    emptyId,
    "No hay ventas en el rango"
  );

  const safeVals = Array.isArray(values) ? values.map((v) => num(v, 0)) : [];
  const hasData = hasSomePositive(safeVals);

  if (!hasData) {
    canvas.style.display = "none";
    if (empty) empty.style.display = "grid";
    return;
  }

  canvas.style.display = "block";
  if (empty) empty.style.display = "none";

  const safeLabels = Array.isArray(labels) ? labels : [];

  const n = safeVals.length;
  let displayLabels = safeLabels.map((d) => formatShortDate(d));
  let displayValues = safeVals;
  let granularity = "día";
  let maxTicks = 10;
  if (n > 120) {
    const agg = aggregateByMonth(safeLabels, safeVals);
    if (agg.values.length > 0) {
      displayLabels = agg.labels;
      displayValues = agg.values;
      granularity = "mes";
      maxTicks = 18;
    }
  } else if (n > 60) {
    const agg = aggregateByWeek(safeLabels, safeVals);
    if (agg.values.length > 0) {
      displayLabels = agg.labels;
      displayValues = agg.values;
      granularity = "semana";
      maxTicks = 18;
    }
  }

  const total = displayValues.reduce((a, b) => a + b, 0);
  const avg   = displayValues.length > 0 ? total / displayValues.length : 0;

  const chart = new Chart(canvas.getContext("2d"), {
    type: "line",
    data: {
      labels: displayLabels,
      datasets: [
        {
          label: "Ventas",
          data: displayValues,
          tension: 0.25,
          fill: true,
          borderColor: palette.primary,
          backgroundColor: palette.primaryFill,
          pointBackgroundColor: palette.primary,
          pointBorderColor: palette.primary,
          pointRadius: 2.5,
          borderWidth: 2,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx) => ` ${nfNum1.format(num(ctx.parsed.y, 0))} tickets`,
          },
        },
      },
      interaction: { mode: "index", intersect: false },
      scales: {
        x: {
          grid: { color: gridColor },
          ticks: { color: textColor, maxTicksLimit: maxTicks },
        },
        y: {
          beginAtZero: true,
          grid: { color: gridColor },
          ticks: { color: textColor, precision: 0 },
        },
      },
    },
  });

  __dashCharts.push(chart);
  updateCardSub(canvasId, `${nfNum1.format(total)} tickets · prom. ${nfNum1.format(avg)}/${granularity}`);
}

/* =========================
   GRÁFICO: Top productos
========================= */
function renderBarChart(
  canvasId,
  emptyId,
  labels,
  values,
  palette,
  textColor,
  gridColor
) {
  const canvas = document.getElementById(canvasId);
  if (!canvas) return;

  const empty = getOrCreateEmptyMsg(canvas, emptyId, "Sin datos");

  const safeVals = Array.isArray(values) ? values.map((v) => num(v, 0)) : [];
  const hasData = hasSomePositive(safeVals);

  if (!hasData) {
    canvas.style.display = "none";
    if (empty) empty.style.display = "grid";
    return;
  }

  canvas.style.display = "block";
  if (empty) empty.style.display = "none";

  const safeLabels = Array.isArray(labels)
    ? labels.map((s) => String(s ?? ""))
    : [];
  const metric = String(window.dashboardData?.topProdEffectiveMetric || window.dashboardData?.topProdMetric || "unidades");
  const metricLabel = metric === "ganancia"
    ? "Ganancia"
    : metric === "ventas"
      ? "Importe"
      : "Unidades";
  const suffix = metric === "unidades" ? " un." : "";
  const formatMetric = (value) => metric === "unidades"
    ? nfNum1.format(num(value, 0))
    : "$ " + nfMoney0.format(num(value, 0));

  const chart = new Chart(canvas.getContext("2d"), {
    type: "bar",
    data: {
      labels: safeLabels,
      datasets: [
        {
          label: metricLabel,
          data: safeVals,
          backgroundColor: palette.secondaryFill,
          borderColor: palette.secondary,
          borderWidth: 1,
          borderRadius: 10,
          maxBarThickness: 40,
        },
      ],
    },
    options: {
      indexAxis: "y",
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx) => ` ${formatMetric(ctx.parsed.x)}${suffix}`,
          },
        },
      },
      scales: {
        x: {
          beginAtZero: true,
          grid: { color: gridColor },
          ticks: { color: textColor, precision: 0 },
        },
        y: {
          grid: { color: gridColor },
          ticks: { color: textColor, autoSkip: false },
        },
      },
    },
  });

  __dashCharts.push(chart);
  const topLabel = safeLabels[0] || "";
  if (topLabel) updateCardSub(canvasId, `Top: ${topLabel} - ${formatMetric(safeVals[0] || 0)}${suffix}`);
}

/* =========================
   GRÁFICO: Métodos de pago
========================= */
function renderMetodosPago(metodosPago, palette, textColor) {
  const canvas = document.getElementById("chartMetodosPago");
  if (!canvas) return;

  const empty = getOrCreateEmptyMsg(
    canvas,
    null,
    "Sin datos de métodos de pago"
  );

  const list = Array.isArray(metodosPago) ? metodosPago : [];
  const labels = list.map((m) => String(m?.medio_pago ?? "N/A"));
  const values = list.map((m) => num(m?.monto, 0));

  const hasData = hasSomePositive(values);
  if (!hasData) {
    canvas.style.display = "none";
    if (empty) empty.style.display = "grid";
    return;
  }
  canvas.style.display = "block";
  if (empty) empty.style.display = "none";

  const chart = new Chart(canvas.getContext("2d"), {
    type: "doughnut",
    data: {
      labels,
      datasets: [
        {
          data: values,
          backgroundColor: palette.donut.slice(0, Math.max(1, labels.length)),
          borderColor: palette.donutBorder,
          borderWidth: 2,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: "bottom",
          labels: { color: textColor, boxWidth: 14 },
        },
        tooltip: {
          callbacks: {
            label: function (context) {
              const label = context.label || "";
              const value = num(context.parsed, 0);
              const total = (context.dataset.data || []).reduce(
                (a, b) => a + num(b, 0),
                0
              );
              const pct =
                total > 0 ? ((value / total) * 100).toFixed(1) : "0.0";
              return `${label}: $${nfMoney0.format(value)} (${pct}%)`;
            },
          },
        },
      },
      cutout: "60%",
    },
  });

  __dashCharts.push(chart);
  const metTotal = values.reduce((a, b) => a + b, 0);
  const metPeak  = values.indexOf(Math.max(...values));
  if (metPeak >= 0 && metTotal > 0) {
    const pct = Math.round((values[metPeak] / metTotal) * 100);
    updateCardSub("chartMetodosPago", `${labels[metPeak]} ${pct}%`);
  }
}

/* =========================
   GRÁFICO: Categorías (fix TOTAL/rollup + tolerancia keys)
========================= */
function renderCategorias(categorias, palette, textColor, gridColor) {
  const canvas = document.getElementById("chartCategorias");
  if (!canvas) return;

  const empty = getOrCreateEmptyMsg(canvas, null, "Sin datos de categorías");

  const raw = Array.isArray(categorias) ? categorias : [];

  // ✅ tolerante: acepta distintas keys de categoría
  const getCatName = (c) =>
    String(c?.categoria ?? c?.cat ?? c?.nombre_categoria ?? c?.nombre ?? "").trim();

  // ✅ Quitamos filas que no son categorías reales (NULL / "" / "TOTAL")
  const list = raw.filter((c) => {
    const cat = getCatName(c);
    if (!cat) return false;
    if (cat.toLowerCase() === "total") return false;
    return true;
  });

  const labels = list.map((c) => getCatName(c));
  const values = list.map((c) => num(c?.ventas ?? c?.monto, 0));

  const hasData = hasSomePositive(values);
  if (!hasData) {
    canvas.style.display = "none";
    if (empty) empty.style.display = "grid";
    return;
  }

  canvas.style.display = "block";
  if (empty) empty.style.display = "none";

  const chart = new Chart(canvas.getContext("2d"), {
    type: "bar",
    data: {
      labels,
      datasets: [
        {
          label: "Ventas ($)",
          data: values,
          backgroundColor: palette.donut.slice(0, Math.max(1, labels.length)),
          borderColor: palette.donutBorder,
          borderWidth: 1,
          borderRadius: 8,
          maxBarThickness: 52,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx) => ` $${nfMoney0.format(num(ctx.parsed.y, 0))}`,
          },
        },
      },
      scales: {
        x: {
          grid: { color: gridColor },
          ticks: { color: textColor, maxRotation: 45, minRotation: 0 },
        },
        y: {
          beginAtZero: true,
          grid: { color: gridColor },
          ticks: { color: textColor },
        },
      },
    },
  });

  __dashCharts.push(chart);
  if (labels[0]) updateCardSub("chartCategorias", `Top: ${labels[0]}`);
}


/* =========================
   GRÁFICO: Horarios pico
========================= */
function renderHorarios(ventasPorHora, palette, textColor, gridColor) {
  const canvas = document.getElementById("chartHorarios");
  if (!canvas) return;

  const empty = getOrCreateEmptyMsg(canvas, null, "Sin datos por hora");

  const list = Array.isArray(ventasPorHora) ? ventasPorHora : [];

  const dataMap = {};
  list.forEach((v) => {
    const h = parseInt(v?.hora, 10);
    if (Number.isFinite(h)) dataMap[h] = num(v?.monto, 0);
  });

  // Recortar horas vacías al inicio y al final; mantener al menos +/-1h de margen
  const activeHours = Object.keys(dataMap).map(Number).filter((h) => dataMap[h] > 0);
  const firstH = activeHours.length ? Math.max(0,  Math.min(...activeHours) - 1) : 0;
  const lastH  = activeHours.length ? Math.min(23, Math.max(...activeHours) + 1) : 23;
  const horasRango = Array.from({ length: lastH - firstH + 1 }, (_, i) => firstH + i);

  const labels = horasRango.map((h) => `${h}:00`);
  const values = horasRango.map((h) => num(dataMap[h], 0));

  const hasData = hasSomePositive(values);
  if (!hasData) {
    canvas.style.display = "none";
    if (empty) empty.style.display = "grid";
    return;
  }
  canvas.style.display = "block";
  if (empty) empty.style.display = "none";

  const peakIdx  = values.reduce((iMax, v, i) => v > values[iMax] ? i : iMax, 0);
  const bgColors = values.map((v, i) => (i === peakIdx && v > 0) ? palette.warning      : palette.primaryFill);
  const bdColors = values.map((v, i) => (i === peakIdx && v > 0) ? palette.warning      : palette.primary);

  const chart = new Chart(canvas.getContext("2d"), {
    type: "bar",
    data: {
      labels,
      datasets: [
        {
          label: "Facturación ($)",
          data: values,
          backgroundColor: bgColors,
          borderColor: bdColors,
          borderWidth: 1,
          borderRadius: 6,
          maxBarThickness: 44,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx) => `$${nfMoney0.format(num(ctx.parsed.y, 0))}`,
          },
        },
      },
      scales: {
        x: {
          grid: { color: gridColor },
          ticks: { color: textColor, maxRotation: 0 },
        },
        y: {
          beginAtZero: true,
          grid: { color: gridColor },
          ticks: { color: textColor },
        },
      },
    },
  });

  __dashCharts.push(chart);
  if (labels[peakIdx]) updateCardSub("chartHorarios", `Pico: ${labels[peakIdx]} hs`);
}

/* =========================
   GRÁFICO: Productos rentables
========================= */
function renderProductosRentables(productos, palette, textColor, gridColor) {
  const canvas = document.getElementById("chartRentables");
  if (!canvas) return;

  const empty = getOrCreateEmptyMsg(canvas, null, "Sin datos de rentabilidad");

  const list = Array.isArray(productos) ? productos : [];
  const labels = list.map((p) => String(p?.nombre ?? "Producto"));
  const ventas = list.map((p) => num(p?.ventas, 0));
  const costos = list.map((p) => num(p?.costos, 0));
  const ganancias = list.map((p) => num(p?.ganancia, 0));

  const hasData =
    hasSomePositive(ventas) ||
    hasSomePositive(costos) ||
    hasSomePositive(ganancias);
  if (!hasData) {
    canvas.style.display = "none";
    if (empty) empty.style.display = "grid";
    return;
  }
  canvas.style.display = "block";
  if (empty) empty.style.display = "none";

  const chart = new Chart(canvas.getContext("2d"), {
    type: "bar",
    data: {
      labels,
      datasets: [
        {
          label: "Ventas",
          data: ventas,
          backgroundColor: palette.primary,
          borderRadius: 6,
          maxBarThickness: 36,
        },
        {
          label: "Costos",
          data: costos,
          backgroundColor: palette.danger,
          borderRadius: 6,
          maxBarThickness: 36,
        },
        {
          label: "Ganancia",
          data: ganancias,
          backgroundColor: palette.accent,
          borderRadius: 6,
          maxBarThickness: 36,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: true,
          position: "top",
          labels: { color: textColor, boxWidth: 12 },
        },
        tooltip: {
          callbacks: {
            label: function (context) {
              return `${context.dataset.label}: $${nfMoney0.format(
                num(context.parsed.y, 0)
              )}`;
            },
          },
        },
      },
      scales: {
        x: { grid: { color: gridColor }, ticks: { color: textColor } },
        y: {
          beginAtZero: true,
          grid: { color: gridColor },
          ticks: { color: textColor },
        },
      },
    },
  });

  __dashCharts.push(chart);
  if (labels[0]) updateCardSub("chartRentables", `Mejor ganancia: ${labels[0]}`);
}

/* =========================
   PRESETS
========================= */
function initPresetChips(data) {
  const form = document.getElementById("dashFilters");
  const fromInput = document.getElementById("dashFrom");
  const toInput = document.getElementById("dashTo");
  const chips = Array.from(document.querySelectorAll(".dash-chip"));
  if (!form || !fromInput || !toInput || chips.length === 0) return;

  markActivePreset(chips, data);

  chips.forEach((c) => {
    if (!c.hasAttribute("aria-pressed")) c.setAttribute("aria-pressed", "false");
  });

  chips.forEach((chip) => {
    chip.addEventListener("click", () => {
      const preset = chip.dataset.preset;
      if (!preset) return;

      const now = new Date();
      const todayStr = formatDate(now);

      let fromStr = todayStr;
      let toStr = todayStr;

      if (preset === "7d") {
        const from = new Date(now);
        from.setDate(from.getDate() - 6);
        fromStr = formatDate(from);
      } else if (preset === "30d") {
        const from = new Date(now);
        from.setDate(from.getDate() - 29);
        fromStr = formatDate(from);
      } else if (preset === "month") {
        const from = new Date(now.getFullYear(), now.getMonth(), 1);
        fromStr = formatDate(from);
      }

      fromInput.value = fromStr;
      toInput.value = toStr;
      form.submit();
    });
  });
}

function markActivePreset(chips, data) {
  const from = data.from;
  const to = data.to;
  if (!from || !to) return;

  const today = new Date();
  const todayStr = formatDate(today);

  const fromDate = parseDate(from);
  const toDate = parseDate(to);
  if (!fromDate || !toDate) return;

  const diffMs = toDate - fromDate;
  const diffDays = Math.round(diffMs / (1000 * 60 * 60 * 24)) + 1;

  let active = null;
  if (from === to && to === todayStr) active = "today";
  if (!active && diffDays === 7 && formatDate(toDate) === todayStr)
    active = "7d";
  if (!active && diffDays === 30 && formatDate(toDate) === todayStr)
    active = "30d";

  const firstOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
  if (!active && from === formatDate(firstOfMonth) && to === todayStr)
    active = "month";

  if (!active) return;
  chips.forEach((chip) => {
    const isActive = chip.dataset.preset === active;
    chip.classList.toggle("is-active", isActive);
    chip.setAttribute("aria-pressed", isActive ? "true" : "false");
  });
}

/* =========================
   TOAST
========================= */
function initToast() {
  const toast = document.getElementById("dashToast");
  if (!toast) return;

  const msg = toast.dataset.message || "";
  if (!msg) return;

  const from = toast.dataset.from || "";
  const to = toast.dataset.to || "";
  let detail = "";
  if (from && to)
    detail = ` (${formatHumanDate(from)} → ${formatHumanDate(to)})`;

  toast.textContent = msg + detail;
  toast.style.display = "block";
  toast.classList.add("is-show");

  setTimeout(() => {
    toast.classList.add("is-hide");
    setTimeout(() => (toast.style.display = "none"), 300);
  }, 4000);
}

/* =========================
   UTILIDADES FECHA
========================= */
function formatDate(date) {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, "0");
  const d = String(date.getDate()).padStart(2, "0");
  return `${y}-${m}-${d}`;
}

function parseDate(str) {
  const parts = String(str).split("-");
  if (parts.length !== 3) return null;
  const [y, m, d] = parts.map((p) => parseInt(p, 10));
  if (!y || !m || !d) return null;
  return new Date(y, m - 1, d);
}

function formatShortDate(ymd) {
  const d = parseDate(ymd);
  if (!d) return ymd;
  const day = String(d.getDate()).padStart(2, "0");
  const month = String(d.getMonth() + 1).padStart(2, "0");
  return `${day}/${month}`;
}

function formatHumanDate(ymd) {
  const d = parseDate(ymd);
  if (!d) return ymd;
  const day = String(d.getDate()).padStart(2, "0");
  const month = String(d.getMonth() + 1).padStart(2, "0");
  const year = d.getFullYear();
  return `${day}/${month}/${year}`;
}

const MONTHS_ES = ["Ene","Feb","Mar","Abr","May","Jun","Jul","Ago","Sep","Oct","Nov","Dic"];

function aggregateByWeek(rawLabels, rawValues) {
  const map = new Map();
  const order = [];
  rawLabels.forEach((lbl, i) => {
    const d = parseDate(lbl);
    if (!d) return;
    const dow = d.getDay();
    const monday = new Date(d);
    monday.setDate(d.getDate() - ((dow + 6) % 7));
    const key = formatDate(monday);
    if (!map.has(key)) { map.set(key, 0); order.push(key); }
    map.set(key, map.get(key) + num(rawValues[i], 0));
  });
  return {
    labels: order.map((k) => {
      const d = parseDate(k);
      if (!d) return k;
      return `${String(d.getDate()).padStart(2,"0")}/${String(d.getMonth()+1).padStart(2,"0")}`;
    }),
    values: order.map((k) => map.get(k)),
  };
}

function aggregateByMonth(rawLabels, rawValues) {
  const map = new Map();
  const order = [];
  rawLabels.forEach((lbl, i) => {
    const d = parseDate(lbl);
    if (!d) return;
    const key = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,"0")}`;
    if (!map.has(key)) { map.set(key, 0); order.push(key); }
    map.set(key, map.get(key) + num(rawValues[i], 0));
  });
  return {
    labels: order.map((k) => {
      const [y, m] = k.split("-").map(Number);
      if (!y || !m || !MONTHS_ES[m - 1]) return k;
      return `${MONTHS_ES[m-1]} ${y}`;
    }),
    values: order.map((k) => map.get(k)),
  };
}

/* =========================
   EXPORT LINKS (actualiza from/to)
========================= */
function initExportLinks() {
  const fromInput = document.getElementById("dashFrom");
  const toInput = document.getElementById("dashTo");
  const catSelect = document.getElementById("dashCategoria");
  const horaDesdeInput = document.getElementById("dashHoraDesde");
  const horaHastaInput = document.getElementById("dashHoraHasta");
  const horaDesdeAmpm = document.getElementById("dashHoraDesdeAmpm");
  const horaHastaAmpm = document.getElementById("dashHoraHastaAmpm");
  const links = Array.from(document.querySelectorAll(".dash-export"));
  const dd = document.getElementById("dashExportDD");

  if (!fromInput || !toInput || links.length === 0) return;

  const buildHref = (type, from, to, categoria, horaDesde, horaHasta, ampmD, ampmH) => {
    let url = `dashboard_export.php?type=${encodeURIComponent(type)}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;

    if (categoria) url += `&categoria=${encodeURIComponent(categoria)}`;
    if (horaDesde) url += `&hora_desde=${encodeURIComponent(horaDesde)}`;
    if (horaHasta) url += `&hora_hasta=${encodeURIComponent(horaHasta)}`;
    if (ampmD) url += `&hora_desde_ampm=${encodeURIComponent(ampmD)}`;
    if (ampmH) url += `&hora_hasta_ampm=${encodeURIComponent(ampmH)}`;

    return url;
  };

  const refresh = () => {
    const from = fromInput.value || "";
    const to = toInput.value || "";
    const categoria = catSelect ? catSelect.value : "";

    const horaDesde = horaDesdeInput ? horaDesdeInput.value : "";
    const horaHasta = horaHastaInput ? horaHastaInput.value : "";

    const ampmD = horaDesdeAmpm ? horaDesdeAmpm.value : "";
    const ampmH = horaHastaAmpm ? horaHastaAmpm.value : "";

    links.forEach((a) => {
      const type = a.dataset.exportType || "";
      if (!type || !from || !to) return;
      a.setAttribute("href", buildHref(type, from, to, categoria, horaDesde, horaHasta, ampmD, ampmH));
    });
  };

  refresh();

  ["change", "input"].forEach((ev) => {
    fromInput.addEventListener(ev, refresh);
    toInput.addEventListener(ev, refresh);
  });

  if (catSelect) catSelect.addEventListener("change", refresh);

  if (horaDesdeInput) ["change", "input"].forEach(ev => horaDesdeInput.addEventListener(ev, refresh));
  if (horaHastaInput) ["change", "input"].forEach(ev => horaHastaInput.addEventListener(ev, refresh));

  if (horaDesdeAmpm) horaDesdeAmpm.addEventListener("change", refresh);
  if (horaHastaAmpm) horaHastaAmpm.addEventListener("change", refresh);

  links.forEach((a) => {
    a.addEventListener("click", () => {
      if (dd && dd.open) dd.open = false;
    });
  });
}


/* =========================
   FILTROS DE HORA
========================= */
function initTimeFilters() {
  const horaDesde = document.getElementById("dashHoraDesde");
  const horaHasta = document.getElementById("dashHoraHasta");
  const ampmDesde = document.getElementById("dashHoraDesdeAmpm");
  const ampmHasta = document.getElementById("dashHoraHastaAmpm");
  const fromInput = document.getElementById("dashFrom");
  const toInput   = document.getElementById("dashTo");
  const clearHoursButton = document.querySelector("[data-dash-clear-hours]");

  if (!horaDesde || !horaHasta) return;

  const pad2 = (n) => String(n).padStart(2, "0");

  function normalizeTimeTo24(timeStr, ampm) {
    if (!timeStr) return "";
    const [hh, mm] = timeStr.split(":");
    let h = parseInt(hh, 10);
    let m = parseInt(mm, 10);
    if (!Number.isFinite(h) || !Number.isFinite(m)) return "";
    if (h < 0 || h > 23 || m < 0 || m > 59) return "";

    const mode = (ampm || "AUTO").toUpperCase();
    if (mode === "AM" || mode === "PM") {
      // Si ya es 13..23, ignoramos AM/PM
      if (h >= 13) return `${pad2(h)}:${pad2(m)}`;

      // 12h -> 24h
      if (h === 12) h = (mode === "AM") ? 0 : 12;
      else if (h >= 1 && h <= 11 && mode === "PM") h += 12;
    }
    return `${pad2(h)}:${pad2(m)}`;
  }

  function timeToMinutes(t24) {
    const [h, m] = t24.split(":").map(Number);
    return (h * 60) + m;
  }

  function syncAmpmLock(timeInput, ampmSelect) {
    if (!ampmSelect) return;
    const v = timeInput.value || "";
    const hh = parseInt(v.split(":")[0] || "0", 10);
    const lock = Number.isFinite(hh) && hh >= 13;
    ampmSelect.disabled = lock;
    if (lock) ampmSelect.value = "AUTO";
  }

  // Validamos solo si from==to (mismo día)
  const validate = () => {
    horaHasta.setCustomValidity("");

    const dFrom = fromInput ? fromInput.value : "";
    const dTo   = toInput ? toInput.value : "";
    if (dFrom && dTo && dFrom !== dTo) return; // rango multi-día: no bloqueamos por horas

    if (horaDesde.value && horaHasta.value) {
      const d24 = normalizeTimeTo24(horaDesde.value, ampmDesde ? ampmDesde.value : "AUTO");
      const h24 = normalizeTimeTo24(horaHasta.value, ampmHasta ? ampmHasta.value : "AUTO");
      if (d24 && h24 && timeToMinutes(h24) <= timeToMinutes(d24)) {
        horaHasta.setCustomValidity("La hora final debe ser posterior a la hora inicial");
      }
    }
  };

  horaDesde.addEventListener("change", () => { syncAmpmLock(horaDesde, ampmDesde); validate(); });
  horaHasta.addEventListener("change", () => { syncAmpmLock(horaHasta, ampmHasta); validate(); });
  if (ampmDesde) ampmDesde.addEventListener("change", validate);
  if (ampmHasta) ampmHasta.addEventListener("change", validate);
  if (fromInput) fromInput.addEventListener("change", validate);
  if (toInput)   toInput.addEventListener("change", validate);
  if (clearHoursButton) {
    clearHoursButton.addEventListener("click", () => {
      horaDesde.value = "";
      horaHasta.value = "";
      const form = document.getElementById("dashFilters");
      if (form) form.submit();
    });
  }

  // Init
  syncAmpmLock(horaDesde, ampmDesde);
  syncAmpmLock(horaHasta, ampmHasta);
  validate();
}

scheduleDashboardBoot();

}

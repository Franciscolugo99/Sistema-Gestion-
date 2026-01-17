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
    initScrollShadows();
    initTimeFilters();

    renderAllCharts(data);

    watchThemeChanges(() => {
      renderAllCharts(data);
      initSparklines();
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bootDashboard);
  } else {
    bootDashboard();
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

function hasSomePositive(arr) {
  return Array.isArray(arr) && arr.length > 0 && arr.some((v) => num(v, 0) > 0);
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
  
  // Agregar efecto visual al cambiar
  select.addEventListener("change", function() {
    const form = document.getElementById("dashFilters");
    if (form) {
      // Mostrar loading
      const panel = document.querySelector(".dashboard-panel");
      if (panel) {
        panel.style.opacity = "0.6";
        panel.style.pointerEvents = "none";
      }
    }
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

    renderLineChart(
      "chartVentas",
      "noVentasMsg",
      data.ventasLabels,
      data.ventasData,
      palette,
      textColor,
      gridColor
    );

    renderBarChart(
      "chartTopProductos",
      "noTopMsg",
      data.topProdLabels,
      data.topProdData,
      palette,
      textColor,
      gridColor
    );

    renderMetodosPago(data.metodosPago, palette, textColor);
    renderCategorias(data.categorias, palette, textColor, gridColor);
    renderHorarios(data.ventasPorHora, palette, textColor, gridColor);
    renderProductosRentables(
      data.productosRentables,
      palette,
      textColor,
      gridColor
    );
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

  const chart = new Chart(canvas.getContext("2d"), {
    type: "line",
    data: {
      labels: safeLabels.map((d) => formatShortDate(d)),
      datasets: [
        {
          label: "Ventas",
          data: safeVals,
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
      plugins: { legend: { display: false } },
      interaction: { mode: "index", intersect: false },
      scales: {
        x: {
          grid: { color: gridColor },
          ticks: { color: textColor, maxTicksLimit: 10 },
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

  const chart = new Chart(canvas.getContext("2d"), {
    type: "bar",
    data: {
      labels: safeLabels,
      datasets: [
        {
          label: "Unidades",
          data: safeVals,
          backgroundColor: palette.secondaryFill,
          borderColor: palette.secondary,
          borderWidth: 1,
          borderRadius: 10,
        },
      ],
    },
    options: {
      indexAxis: "y",
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
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
}

/* =========================
   GRÁFICO: Categorías
========================= */
function renderCategorias(categorias, palette, textColor, gridColor) {
  const canvas = document.getElementById("chartCategorias");
  if (!canvas) return;

  const empty = getOrCreateEmptyMsg(canvas, null, "Sin datos de categorías");

  const list = Array.isArray(categorias) ? categorias : [];
  const labels = list.map((c) => String(c?.categoria ?? "Sin Categoría"));
  const values = list.map((c) => num(c?.ventas, 0));

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
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
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
}

/* =========================
   GRÁFICO: Horarios pico
========================= */
function renderHorarios(ventasPorHora, palette, textColor, gridColor) {
  const canvas = document.getElementById("chartHorarios");
  if (!canvas) return;

  const empty = getOrCreateEmptyMsg(canvas, null, "Sin datos por hora");

  const list = Array.isArray(ventasPorHora) ? ventasPorHora : [];

  const horasCompletas = Array.from({ length: 24 }, (_, i) => i);
  const dataMap = {};
  list.forEach((v) => {
    const h = parseInt(v?.hora, 10);
    if (Number.isFinite(h)) dataMap[h] = num(v?.monto, 0);
  });

  const labels = horasCompletas.map((h) => `${h}:00`);
  const values = horasCompletas.map((h) => num(dataMap[h], 0));

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
          label: "Facturación ($)",
          data: values,
          backgroundColor: palette.primaryFill,
          borderColor: palette.primary,
          borderWidth: 1,
          borderRadius: 6,
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
            label: function (context) {
              return `$${nfMoney0.format(num(context.parsed.y, 0))}`;
            },
          },
        },
      },
      scales: {
        x: {
          grid: { color: gridColor },
          ticks: {
            color: textColor,
            maxRotation: 0,
            callback: function (val, idx) {
              return idx % 2 === 0 ? this.getLabelForValue(val) : "";
            },
          },
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
        },
        {
          label: "Costos",
          data: costos,
          backgroundColor: palette.danger,
          borderRadius: 6,
        },
        {
          label: "Ganancia",
          data: ganancias,
          backgroundColor: palette.accent,
          borderRadius: 6,
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

/* =========================
   EXPORT LINKS (actualiza from/to)
========================= */
function initExportLinks() {
  const fromInput = document.getElementById("dashFrom");
  const toInput = document.getElementById("dashTo");
  const catSelect = document.getElementById("dashCategoria");
  const horaDesdeInput = document.getElementById("dashHoraDesde");
  const horaHastaInput = document.getElementById("dashHoraHasta");
  const links = Array.from(document.querySelectorAll(".dash-export"));
  const dd = document.getElementById("dashExportDD");

  if (!fromInput || !toInput || links.length === 0) return;

  const buildHref = (type, from, to, categoria, horaDesde, horaHasta) => {
    let url = `dashboard_export.php?type=${encodeURIComponent(type)}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;
    if (categoria) {
      url += `&categoria=${encodeURIComponent(categoria)}`;
    }
    if (horaDesde) {
      url += `&hora_desde=${encodeURIComponent(horaDesde)}`;
    }
    if (horaHasta) {
      url += `&hora_hasta=${encodeURIComponent(horaHasta)}`;
    }
    return url;
  };

  const refresh = () => {
    const from = fromInput.value || "";
    const to = toInput.value || "";
    const categoria = catSelect ? catSelect.value : "";
    const horaDesde = horaDesdeInput ? horaDesdeInput.value : "";
    const horaHasta = horaHastaInput ? horaHastaInput.value : "";
    
    links.forEach((a) => {
      const type = a.dataset.exportType || "";
      if (!type || !from || !to) return;
      a.setAttribute("href", buildHref(type, from, to, categoria, horaDesde, horaHasta));
    });
  };

  refresh();

  ["change", "input"].forEach((ev) => {
    fromInput.addEventListener(ev, refresh);
    toInput.addEventListener(ev, refresh);
  });
  
  if (catSelect) {
    catSelect.addEventListener("change", refresh);
  }
  
  if (horaDesdeInput) {
    horaDesdeInput.addEventListener("change", refresh);
  }
  
  if (horaHastaInput) {
    horaHastaInput.addEventListener("change", refresh);
  }

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
  
  if (!horaDesde || !horaHasta) return;
  
  // Validar que hora_hasta > hora_desde
  const validateTimeRange = () => {
    if (horaDesde.value && horaHasta.value) {
      if (horaDesde.value > horaHasta.value) {
        horaHasta.setCustomValidity("La hora final debe ser posterior a la hora inicial");
      } else {
        horaHasta.setCustomValidity("");
      }
    } else {
      horaHasta.setCustomValidity("");
    }
  };
  
  horaDesde.addEventListener("change", validateTimeRange);
  horaHasta.addEventListener("change", validateTimeRange);
  
  // Limpiar validación al escribir
  horaDesde.addEventListener("input", () => horaDesde.setCustomValidity(""));
  horaHasta.addEventListener("input", () => horaHasta.setCustomValidity(""));
  
  // Tooltips informativos
  const addTooltip = (element, message) => {
    element.title = message;
    element.setAttribute("aria-label", message);
  };
  
  addTooltip(horaDesde, "Filtrar ventas desde esta hora (formato 24h)");
  addTooltip(horaHasta, "Filtrar ventas hasta esta hora (formato 24h)");
  
  // Sugerencias de horarios comunes (preset buttons)
  const form = document.getElementById("dashFilters");
  if (form) {
    // Agregar botones de preset de horarios después de los presets existentes
    const presetsContainer = form.querySelector(".dash-presets");
    if (presetsContainer && !presetsContainer.querySelector(".time-preset-separator")) {
      const separator = document.createElement("span");
      separator.className = "time-preset-separator";
      separator.textContent = "|";
      separator.style.cssText = "margin: 0 4px; opacity: 0.3;";
      
      const timePresets = [
        { label: "🌅 Mañana", desde: "06:00", hasta: "12:00" },
        { label: "☀️ Tarde", desde: "12:00", hasta: "18:00" },
        { label: "🌙 Noche", desde: "18:00", hasta: "23:59" }
      ];
      
      const fragment = document.createDocumentFragment();
      fragment.appendChild(separator);
      
      timePresets.forEach(preset => {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = "dash-chip time-preset";
        btn.textContent = preset.label;
        btn.title = `${preset.desde} - ${preset.hasta}`;
        btn.onclick = (e) => {
          e.preventDefault();
          horaDesde.value = preset.desde;
          horaHasta.value = preset.hasta;
          validateTimeRange();
          
          // Visual feedback
          btn.classList.add("is-active");
          setTimeout(() => btn.classList.remove("is-active"), 300);
        };
        fragment.appendChild(btn);
      });
      
      presetsContainer.appendChild(fragment);
    }
  }
}

}
// assets/js/dashboard.js

let __dashCharts = [];

document.addEventListener("DOMContentLoaded", () => {
  const data = window.dashboardData || {};

  initPresetChips(data);
  initToast();
  initKpiTooltips();

  // Render inicial + re-render cuando cambia el tema
  renderAllCharts(data);
  watchThemeChanges(() => renderAllCharts(data));
});

/* =========================
   THEME (robusto)
========================= */
function getTheme() {
  const t =
    document.documentElement.dataset.theme ||
    document.body.dataset.theme ||
    localStorage.getItem("flus-theme") ||
    localStorage.getItem("theme") ||
    "dark";
  return String(t).toLowerCase() === "light" ? "light" : "dark";
}

function applyChartDefaults(theme) {
  const textColor = theme === "light" ? "#0f172a" : "#e5e7eb";
  const gridColor =
    theme === "light"
      ? "rgba(148,163,184,0.55)"
      : "rgba(148,163,184,0.28)";

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
   CHARTS
========================= */
function renderAllCharts(data) {
  if (typeof Chart === "undefined") return;

  destroyCharts();

  const theme = getTheme();
  const { textColor, gridColor } = applyChartDefaults(theme);

  const palette =
    theme === "light"
      ? {
          line: "#0891b2",
          lineFill: "rgba(8,145,178,0.18)",
          bar: "#7c3aed",
          barFill: "rgba(124,58,237,0.18)",
          donut: ["#0891b2", "#7c3aed", "#16a34a", "#f59e0b", "#ef4444", "#2563eb"],
          donutBorder: "rgba(255,255,255,0.9)",
        }
      : {
          line: "#22d3ee",
          lineFill: "rgba(34,211,238,0.18)",
          bar: "#a78bfa",
          barFill: "rgba(167,139,250,0.22)",
          donut: ["#22d3ee", "#a78bfa", "#34d399", "#fbbf24", "#fb7185", "#60a5fa"],
          donutBorder: "rgba(15,23,42,0.65)",
        };

  // ---------- Ventas por día ----------
  {
    const canvas = document.getElementById("chartVentas7d");
    const empty = document.getElementById("noVentasMsg");
    if (!canvas || !empty) return;

    const labels = data.ventasLabels || [];
    const values = data.ventasData || [];
    const hasData = values.some((v) => v > 0);

    if (!hasData) {
      canvas.style.display = "none";
      empty.style.display = "grid";
    } else {
      canvas.style.display = "block";
      empty.style.display = "none";

      const chart = new Chart(canvas.getContext("2d"), {
        type: "line",
        data: {
          labels,
          datasets: [
            {
              label: "Ventas",
              data: values,
              tension: 0.25,
              fill: true,
              borderColor: palette.line,
              backgroundColor: palette.lineFill,
              pointBackgroundColor: palette.line,
              pointBorderColor: palette.line,
              pointRadius: 2.5,
              borderWidth: 2,
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
              ticks: {
                display: true,
                color: textColor,
                maxTicksLimit: 10,
                callback: (value, idx) => formatShortDate(labels[idx] || ""),
              },
            },
            y: {
              beginAtZero: true,
              grid: { color: gridColor },
              ticks: { display: true, color: textColor, precision: 0 },
            },
          },
        },
      });

      __dashCharts.push(chart);
    }
  }

  // ---------- Top productos ----------
  {
    const canvas = document.getElementById("chartTopProductos");
    const empty = document.getElementById("noTopMsg");
    if (!canvas || !empty) return;

    const labels = data.topProdLabels || [];
    const values = data.topProdData || [];
    const hasData = values.some((v) => v > 0);

    if (!hasData) {
      canvas.style.display = "none";
      empty.style.display = "grid";
    } else {
      canvas.style.display = "block";
      empty.style.display = "none";

      const chart = new Chart(canvas.getContext("2d"), {
        type: "bar",
        data: {
          labels,
          datasets: [
            {
              label: "Unidades",
              data: values,
              backgroundColor: palette.barFill,
              borderColor: palette.bar,
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
              ticks: { display: true, color: textColor, precision: 0 },
            },
            y: {
              grid: { color: gridColor },
              ticks: {
                display: true,
                color: textColor,
                autoSkip: false,
              },
            },
          },
        },
      });

      __dashCharts.push(chart);
    }
  }

  // ---------- Movimientos por tipo ----------
  {
    const canvas = document.getElementById("chartTipos");
    const empty = document.getElementById("noTiposMsg");
    if (!canvas || !empty) return;

    const labels = data.tiposLabels || [];
    const values = data.tiposData || [];
    const hasData = values.some((v) => v > 0);

    if (!hasData) {
      canvas.style.display = "none";
      empty.style.display = "grid";
    } else {
      canvas.style.display = "block";
      empty.style.display = "none";

      const colors = labels.map((_, i) => palette.donut[i % palette.donut.length]);

      const chart = new Chart(canvas.getContext("2d"), {
        type: "doughnut",
        data: {
          labels,
          datasets: [
            {
              data: values,
              backgroundColor: colors,
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
              position: "right",
              labels: { color: textColor, boxWidth: 14 },
            },
          },
        },
      });

      __dashCharts.push(chart);
    }
  }
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
  if (!active && diffDays === 7 && formatDate(toDate) === todayStr) active = "7d";
  if (!active && diffDays === 30 && formatDate(toDate) === todayStr) active = "30d";

  const firstOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
  if (!active && from === formatDate(firstOfMonth) && to === todayStr) active = "month";

  if (!active) return;
  chips.forEach((chip) => chip.classList.toggle("is-active", chip.dataset.preset === active));
}

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

/* =========================
   TOAST
========================= */
function initToast() {
  const toast = document.getElementById("dashToast");
  if (!toast) return;

  const msg = toast.dataset.message || "";
  const from = toast.dataset.from || "";
  const to = toast.dataset.to || "";
  if (!msg) return;

  let detail = "";
  if (from && to) detail = ` (${formatHumanDate(from)} → ${formatHumanDate(to)})`;

  toast.textContent = msg + detail;
  toast.style.display = "block";
  toast.classList.add("is-show");

  setTimeout(() => {
    toast.classList.add("is-hide");
    setTimeout(() => (toast.style.display = "none"), 300);
  }, 4000);
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
   KPI Tooltips
========================= */
function initKpiTooltips() {
  const helps = Array.from(document.querySelectorAll(".kpi-help"));
  if (helps.length === 0) return;

  let tooltip = document.querySelector(".kpi-tooltip");
  if (!tooltip) {
    tooltip = document.createElement("div");
    tooltip.className = "kpi-tooltip";
    document.body.appendChild(tooltip);
  }

  helps.forEach((btn) => {
    btn.addEventListener("mouseenter", (ev) => {
      const help = btn.getAttribute("data-help") || "";
      if (!help) return;
      tooltip.textContent = help;
      tooltip.classList.add("is-show");
      positionTooltip(tooltip, ev.clientX, ev.clientY);
    });

    btn.addEventListener("mousemove", (ev) => {
      if (!tooltip.classList.contains("is-show")) return;
      positionTooltip(tooltip, ev.clientX, ev.clientY);
    });

    btn.addEventListener("mouseleave", () => tooltip.classList.remove("is-show"));
  });
}

function positionTooltip(tooltip, clientX, clientY) {
  const padding = 10;
  const { innerWidth, innerHeight } = window;
  const rect = tooltip.getBoundingClientRect();

  let x = clientX + 12;
  let y = clientY + 12;

  if (x + rect.width + padding > innerWidth) x = innerWidth - rect.width - padding;
  if (y + rect.height + padding > innerHeight) y = innerHeight - rect.height - padding;

  tooltip.style.left = `${x}px`;
  tooltip.style.top = `${y}px`;
}

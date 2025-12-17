// assets/js/dashboard-charts.js

document.addEventListener("DOMContentLoaded", () => {
  const data = window.dashboardData || {};

  const ventasLabels = data.ventasLabels || [];
  const ventasData = data.ventasData || [];
  const topProdLabels = data.topProdLabels || [];
  const topProdData = data.topProdData || [];
  const tiposLabels = data.tiposLabels || [];
  const tiposData = data.tiposData || [];

  // Helper para formatear fechas YYYY-MM-DD -> DD/MM
  const formatDia = (d) => {
    if (!d || typeof d !== "string") return d;
    const [y, m, day] = d.split("-");
    if (!y || !m || !day) return d;
    return `${day}/${m}`;
  };

  // === Chart 1: Ventas últimos 7 días ===
  const canvasVentas = document.getElementById("chartVentas7d");
  if (canvasVentas && ventasLabels.length) {
    const ctx1 = canvasVentas.getContext("2d");

    new Chart(ctx1, {
      type: "line",
      data: {
        labels: ventasLabels.map(formatDia),
        datasets: [
          {
            label: "Ventas",
            data: ventasData,
            tension: 0.3,
            fill: true,
            borderWidth: 2,
          },
        ],
      },
      options: {
        plugins: {
          legend: { display: false },
        },
        scales: {
          x: {
            ticks: { font: { size: 11 } },
          },
          y: {
            beginAtZero: true,
            ticks: { stepSize: 1 },
          },
        },
      },
    });
  }

  // === Chart 2: Top productos (30 días) ===
  const canvasTop = document.getElementById("chartTopProductos");
  if (canvasTop && topProdLabels.length) {
    const ctx2 = canvasTop.getContext("2d");

    new Chart(ctx2, {
      type: "bar",
      data: {
        labels: topProdLabels,
        datasets: [
          {
            label: "Cantidad vendida",
            data: topProdData,
            borderWidth: 1,
          },
        ],
      },
      options: {
        indexAxis: "y",
        plugins: {
          legend: { display: false },
        },
        scales: {
          x: { beginAtZero: true },
        },
      },
    });
  }

  // === Chart 3: Movimientos por tipo (30 días) ===
  const canvasTipos = document.getElementById("chartTipos");
  if (canvasTipos && tiposLabels.length) {
    const ctx3 = canvasTipos.getContext("2d");

    new Chart(ctx3, {
      type: "doughnut",
      data: {
        labels: tiposLabels,
        datasets: [
          {
            data: tiposData,
            borderWidth: 1,
          },
        ],
      },
      options: {
        plugins: {
          legend: {
            position: "bottom",
          },
        },
        cutout: "60%",
      },
    });
  }
});

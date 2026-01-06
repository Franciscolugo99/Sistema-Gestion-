// public/assets/js/ventas-charts.js
// Requiere: Chart.js 4.x en el HTML
// <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>

document.addEventListener("DOMContentLoaded", () => {
  // =============================================
  // GRÁFICO 1: Ventas por día (línea)
  // =============================================
  const initVentasPorDia = async () => {
    const canvas = document.getElementById("chartVentasPorDia");
    if (!canvas) return;

    try {
      const res = await fetch("api/index.php?action=stats_ventas_por_dia");
      const data = await res.json();

      if (!data.success || !data.stats) return;

      const ctx = canvas.getContext("2d");
      new Chart(ctx, {
        type: "line",
        data: {
          labels: data.stats.map((d) => d.fecha),
          datasets: [
            {
              label: "Ventas ($)",
              data: data.stats.map((d) => parseFloat(d.total)),
              borderColor: "rgb(59, 130, 246)",
              backgroundColor: "rgba(59, 130, 246, 0.1)",
              tension: 0.3,
              fill: true,
            },
            {
              label: "Cantidad",
              data: data.stats.map((d) => parseInt(d.cantidad)),
              borderColor: "rgb(34, 197, 94)",
              backgroundColor: "rgba(34, 197, 94, 0.1)",
              tension: 0.3,
              fill: true,
              yAxisID: "y1",
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          interaction: {
            mode: "index",
            intersect: false,
          },
          plugins: {
            title: {
              display: true,
              text: "Ventas diarias (últimos 30 días)",
              font: { size: 14, weight: "600" },
            },
            legend: {
              display: true,
              position: "top",
            },
            tooltip: {
              callbacks: {
                label: function (context) {
                  let label = context.dataset.label || "";
                  if (label) {
                    label += ": ";
                  }
                  if (context.parsed.y !== null) {
                    if (context.datasetIndex === 0) {
                      label += new Intl.NumberFormat("es-AR", {
                        style: "currency",
                        currency: "ARS",
                      }).format(context.parsed.y);
                    } else {
                      label += context.parsed.y + " ventas";
                    }
                  }
                  return label;
                },
              },
            },
          },
          scales: {
            y: {
              type: "linear",
              display: true,
              position: "left",
              title: {
                display: true,
                text: "Monto ($)",
              },
              ticks: {
                callback: function (value) {
                  return "$" + value.toLocaleString("es-AR");
                },
              },
            },
            y1: {
              type: "linear",
              display: true,
              position: "right",
              title: {
                display: true,
                text: "Cantidad",
              },
              grid: {
                drawOnChartArea: false,
              },
            },
          },
        },
      });
    } catch (err) {
      console.error("Error cargando gráfico ventas por día:", err);
    }
  };

  // =============================================
  // GRÁFICO 2: Ventas por medio de pago (dona)
  // =============================================
  const initVentasPorMedio = async () => {
    const canvas = document.getElementById("chartVentasPorMedio");
    if (!canvas) return;

    try {
      const res = await fetch("api/index.php?action=stats_ventas_por_medio");
      const data = await res.json();

      if (!data.success || !data.stats) return;

      const colores = {
        EFECTIVO: "rgb(34, 197, 94)",
        MP: "rgb(59, 130, 246)",
        DEBITO: "rgb(251, 191, 36)",
        CREDITO: "rgb(139, 92, 246)",
        SIN_ESPECIFICAR: "rgb(156, 163, 175)",
      };

      const ctx = canvas.getContext("2d");
      new Chart(ctx, {
        type: "doughnut",
        data: {
          labels: data.stats.map((d) => d.medio_pago),
          datasets: [
            {
              label: "Ventas",
              data: data.stats.map((d) => parseFloat(d.total)),
              backgroundColor: data.stats.map((d) => colores[d.medio_pago] || "#ccc"),
              borderWidth: 2,
              borderColor: "#fff",
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            title: {
              display: true,
              text: "Distribución por medio de pago",
              font: { size: 14, weight: "600" },
            },
            legend: {
              display: true,
              position: "bottom",
            },
            tooltip: {
              callbacks: {
                label: function (context) {
                  const label = context.label || "";
                  const value = context.parsed || 0;
                  const total = context.dataset.data.reduce((a, b) => a + b, 0);
                  const percentage = ((value / total) * 100).toFixed(1);

                  return [
                    label,
                    new Intl.NumberFormat("es-AR", {
                      style: "currency",
                      currency: "ARS",
                    }).format(value),
                    `${percentage}%`,
                  ].join(" - ");
                },
              },
            },
          },
        },
      });
    } catch (err) {
      console.error("Error cargando gráfico medios de pago:", err);
    }
  };

  // =============================================
  // GRÁFICO 3: Top productos (barras)
  // =============================================
  const initTopProductos = async () => {
    const canvas = document.getElementById("chartTopProductos");
    if (!canvas) return;

    try {
      const res = await fetch("api/index.php?action=stats_top_productos&limit=10");
      const data = await res.json();

      if (!data.success || !data.productos) return;

      const ctx = canvas.getContext("2d");
      new Chart(ctx, {
        type: "bar",
        data: {
          labels: data.productos.map((p) => p.nombre),
          datasets: [
            {
              label: "Unidades vendidas",
              data: data.productos.map((p) => parseInt(p.unidades)),
              backgroundColor: "rgba(59, 130, 246, 0.8)",
              borderColor: "rgb(59, 130, 246)",
              borderWidth: 1,
            },
          ],
        },
        options: {
          indexAxis: "y",
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            title: {
              display: true,
              text: "Top 10 productos más vendidos",
              font: { size: 14, weight: "600" },
            },
            legend: {
              display: false,
            },
            tooltip: {
              callbacks: {
                afterLabel: function (context) {
                  const producto = data.productos[context.dataIndex];
                  return [
                    `Total vendido: ${new Intl.NumberFormat("es-AR", {
                      style: "currency",
                      currency: "ARS",
                    }).format(producto.total)}`,
                    `En ${producto.num_ventas} ventas`,
                  ];
                },
              },
            },
          },
          scales: {
            x: {
              beginAtZero: true,
              title: {
                display: true,
                text: "Unidades",
              },
            },
          },
        },
      });
    } catch (err) {
      console.error("Error cargando top productos:", err);
    }
  };

  // =============================================
  // GRÁFICO 4: Comparativa período (barras agrupadas)
  // =============================================
  const initComparativaPeriodo = async () => {
    const canvas = document.getElementById("chartComparativa");
    if (!canvas) return;

    // Obtener fechas del filtro actual
    const desde = document.getElementById("desde")?.value;
    const hasta = document.getElementById("hasta")?.value;

    if (!desde || !hasta) return;

    try {
      const res = await fetch(
        `api/index.php?action=stats_comparativa&desde=${desde}&hasta=${hasta}`
      );
      const data = await res.json();

      if (!data.success || !data.stats) return;

      const ctx = canvas.getContext("2d");
      new Chart(ctx, {
        type: "bar",
        data: {
          labels: ["Período actual", "Período anterior"],
          datasets: [
            {
              label: "Ventas",
              data: [data.stats.actual.cantidad, data.stats.anterior.cantidad],
              backgroundColor: "rgba(59, 130, 246, 0.8)",
              borderColor: "rgb(59, 130, 246)",
              borderWidth: 1,
            },
            {
              label: "Total ($)",
              data: [data.stats.actual.total, data.stats.anterior.total],
              backgroundColor: "rgba(34, 197, 94, 0.8)",
              borderColor: "rgb(34, 197, 94)",
              borderWidth: 1,
              yAxisID: "y1",
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            title: {
              display: true,
              text: "Comparativa con período anterior",
              font: { size: 14, weight: "600" },
            },
            legend: {
              display: true,
              position: "top",
            },
            tooltip: {
              callbacks: {
                label: function (context) {
                  let label = context.dataset.label || "";
                  if (context.datasetIndex === 0) {
                    label += ": " + context.parsed.y + " ventas";
                  } else {
                    label +=
                      ": " +
                      new Intl.NumberFormat("es-AR", {
                        style: "currency",
                        currency: "ARS",
                      }).format(context.parsed.y);
                  }

                  // Mostrar diferencia porcentual
                  const dataset = context.dataset.data;
                  if (dataset[0] && dataset[1]) {
                    const diff = ((dataset[0] - dataset[1]) / dataset[1]) * 100;
                    const sign = diff > 0 ? "+" : "";
                    label += ` (${sign}${diff.toFixed(1)}%)`;
                  }

                  return label;
                },
              },
            },
          },
          scales: {
            y: {
              type: "linear",
              display: true,
              position: "left",
              title: {
                display: true,
                text: "Cantidad de ventas",
              },
            },
            y1: {
              type: "linear",
              display: true,
              position: "right",
              title: {
                display: true,
                text: "Monto ($)",
              },
              grid: {
                drawOnChartArea: false,
              },
              ticks: {
                callback: function (value) {
                  return "$" + value.toLocaleString("es-AR");
                },
              },
            },
          },
        },
      });

      // Mostrar card con variación porcentual
      const variacionCard = document.getElementById("variacionCard");
      if (variacionCard && data.stats.actual.total && data.stats.anterior.total) {
        const diff =
          ((data.stats.actual.total - data.stats.anterior.total) / data.stats.anterior.total) *
          100;
        const isPositive = diff > 0;

        variacionCard.innerHTML = `
          <div class="kpi">
            <div class="kpi-label">Variación vs período anterior</div>
            <div class="kpi-value ${isPositive ? "positive" : "negative"}">
              ${isPositive ? "+" : ""}${diff.toFixed(1)}%
            </div>
          </div>
        `;
      }
    } catch (err) {
      console.error("Error cargando comparativa:", err);
    }
  };

  // =============================================
  // GRÁFICO 5: Heatmap de ventas por hora/día
  // =============================================
  const initHeatmapVentas = async () => {
    const canvas = document.getElementById("chartHeatmap");
    if (!canvas) return;

    try {
      const res = await fetch("api/index.php?action=stats_ventas_heatmap");
      const data = await res.json();

      if (!data.success || !data.heatmap) return;

      // Usar Chart.js con plugin matrix (si está disponible)
      // O crear visualización personalizada con canvas
      renderCustomHeatmap(canvas, data.heatmap);
    } catch (err) {
      console.error("Error cargando heatmap:", err);
    }
  };

  const renderCustomHeatmap = (canvas, heatmapData) => {
    const ctx = canvas.getContext("2d");
    const cellWidth = 30;
    const cellHeight = 30;
    const dias = ["Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom"];
    const horas = Array.from({ length: 24 }, (_, i) => `${i}:00`);

    canvas.width = cellWidth * 24 + 60;
    canvas.height = cellHeight * 7 + 40;

    // Encontrar valor máximo para escala de color
    const maxValue = Math.max(...heatmapData.flat());

    // Dibujar celdas
    heatmapData.forEach((row, dia) => {
      row.forEach((value, hora) => {
        const x = hora * cellWidth + 60;
        const y = dia * cellHeight + 40;

        // Color basado en intensidad
        const intensity = maxValue > 0 ? value / maxValue : 0;
        const color = getHeatmapColor(intensity);

        ctx.fillStyle = color;
        ctx.fillRect(x, y, cellWidth - 1, cellHeight - 1);

        // Texto con cantidad
        if (value > 0) {
          ctx.fillStyle = intensity > 0.5 ? "#fff" : "#000";
          ctx.font = "10px sans-serif";
          ctx.textAlign = "center";
          ctx.textBaseline = "middle";
          ctx.fillText(value.toString(), x + cellWidth / 2, y + cellHeight / 2);
        }
      });

      // Etiquetas días
      ctx.fillStyle = "#666";
      ctx.font = "12px sans-serif";
      ctx.textAlign = "right";
      ctx.fillText(dias[dia], 50, dia * cellHeight + 40 + cellHeight / 2);
    });

    // Etiquetas horas
    horas.forEach((hora, i) => {
      if (i % 2 === 0) {
        ctx.fillStyle = "#666";
        ctx.font = "10px sans-serif";
        ctx.textAlign = "center";
        ctx.fillText(hora, i * cellWidth + 60 + cellWidth / 2, 25);
      }
    });
  };

  const getHeatmapColor = (intensity) => {
    // Escala de azul claro a azul oscuro
    const r = Math.floor(220 - intensity * 161);
    const g = Math.floor(230 - intensity * 100);
    const b = Math.floor(250 - intensity * 4);
    return `rgb(${r}, ${g}, ${b})`;
  };

  // =============================================
  // Inicializar todos los gráficos
  // =============================================
  const initAllCharts = () => {
    initVentasPorDia();
    initVentasPorMedio();
    initTopProductos();
    initComparativaPeriodo();
    initHeatmapVentas();
  };

  // Toggle panel de gráficos
  const btnToggleCharts = document.getElementById("btnToggleCharts");
  const chartsPanel = document.getElementById("chartsPanel");

  if (btnToggleCharts && chartsPanel) {
    const isExpanded = localStorage.getItem("flus-charts-expanded") === "true";

    if (isExpanded) {
      chartsPanel.classList.remove("hidden");
      initAllCharts();
    }

    btnToggleCharts.addEventListener("click", () => {
      const willExpand = chartsPanel.classList.contains("hidden");

      chartsPanel.classList.toggle("hidden");
      localStorage.setItem("flus-charts-expanded", willExpand.toString());

      if (willExpand) {
        initAllCharts();
      }

      btnToggleCharts.querySelector("span").textContent = willExpand
        ? "Ocultar gráficos"
        : "Mostrar gráficos";
    });
  }
});
// public/assets/js/ventas.js
document.addEventListener("DOMContentLoaded", () => {
  const form =
    document.getElementById("ventasFilters") ||
    document.getElementById("ventasForm");

  // ==========================================================
  // 1. FUNCIONALIDADES EXISTENTES (mantener)
  // ==========================================================

  // Scroll arriba
  const btnScrollTop = document.getElementById("btnScrollTop");
  if (btnScrollTop) {
    btnScrollTop.addEventListener("click", () => {
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
  }

  // PAPEL (80 / 58) – localStorage + aplicar a links
  const PAPER_KEY = "flus-paper-size";
  const paperSel = document.getElementById("paperSel");

  function normalizePaper(v) {
    return v === "58" ? "58" : "80";
  }

  function getPaper() {
    try {
      return normalizePaper(localStorage.getItem(PAPER_KEY) || "80");
    } catch {
      return "80";
    }
  }

  function setPaper(v) {
    const paper = normalizePaper(v);
    try {
      localStorage.setItem(PAPER_KEY, paper);
    } catch {}
    if (paperSel) paperSel.value = paper;
    applyPaperToLinks(paper);
    return paper;
  }

  function setUrlParam(href, key, value) {
    try {
      const u = new URL(href, window.location.href);
      u.searchParams.set(key, value);
      return u.pathname + "?" + u.searchParams.toString();
    } catch {
      const hasQ = href.includes("?");
      const re = new RegExp("([?&])" + key + "=([^&]*)");
      if (re.test(href))
        return href.replace(re, `$1${key}=${encodeURIComponent(value)}`);
      return href + (hasQ ? "&" : "?") + `${key}=${encodeURIComponent(value)}`;
    }
  }

  function applyPaperToLinks(paper) {
    const links = document.querySelectorAll('a[href^="ticket.php"]');
    links.forEach((a) => {
      const href = a.getAttribute("href") || "";
      a.setAttribute("href", setUrlParam(href, "paper", paper));
    });
  }

  const initPaper = getPaper();
  if (paperSel) paperSel.value = initPaper;
  applyPaperToLinks(initPaper);

  if (paperSel) {
    paperSel.addEventListener("change", () => setPaper(paperSel.value));
  }

  // PERSISTENCIA FILTROS
  const FILTERS_KEY = "flus-ventas-filters-v2";

  function clearFiltersStorage() {
    try {
      localStorage.removeItem(FILTERS_KEY);
    } catch {}
  }

  const clearLink = document.getElementById("ventasClear");
  if (clearLink) {
    clearLink.addEventListener("click", (e) => {
      e.preventDefault();
      clearFiltersStorage();
      const u = new URL(
        clearLink.getAttribute("href") || "ventas.php",
        window.location.href,
      );
      u.searchParams.set("clear", "1");
      window.location.href = u.pathname + "?" + u.searchParams.toString();
    });
  }

  // Chips de rango (MEJORADOS con más opciones)
  const desde = document.getElementById("desde");
  const hasta = document.getElementById("hasta");
  const chips = document.querySelectorAll(".chip[data-range]");

  function fmt(d) {
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${d.getFullYear()}-${m}-${day}`;
  }

  function getMonday(d) {
    const day = d.getDay();
    const diff = d.getDate() - day + (day === 0 ? -6 : 1);
    return new Date(d.setDate(diff));
  }

  function getFirstDayOfMonth(d) {
    return new Date(d.getFullYear(), d.getMonth(), 1);
  }

  function getLastDayOfMonth(d) {
    return new Date(d.getFullYear(), d.getMonth() + 1, 0);
  }

  chips.forEach((chip) => {
    chip.addEventListener("click", () => {
      const now = new Date();
      const r = chip.getAttribute("data-range");
      let d1, d2;

      switch (r) {
        case "today":
          d1 = d2 = new Date(now);
          break;

        case "yesterday":
          d1 = d2 = new Date(now);
          d1.setDate(d1.getDate() - 1);
          break;

        case "7d":
          d1 = new Date(now);
          d1.setDate(d1.getDate() - 6);
          d2 = new Date(now);
          break;

        case "30d":
          d1 = new Date(now);
          d1.setDate(d1.getDate() - 29);
          d2 = new Date(now);
          break;

        case "this_week":
          d1 = getMonday(new Date(now));
          d2 = new Date(now);
          break;

        case "last_week":
          d2 = getMonday(new Date(now));
          d2.setDate(d2.getDate() - 1); // Domingo anterior
          d1 = getMonday(new Date(d2));
          break;

        case "this_month":
          d1 = getFirstDayOfMonth(new Date(now));
          d2 = new Date(now);
          break;

        case "last_month":
          d2 = getFirstDayOfMonth(new Date(now));
          d2.setDate(d2.getDate() - 1); // Último día del mes anterior
          d1 = getFirstDayOfMonth(new Date(d2));
          break;

        default:
          return;
      }

      if (desde) desde.value = fmt(d1);
      if (hasta) hasta.value = fmt(d2);

      const page = document.getElementById("page");
      if (page) page.value = "1";

      if (form) form.submit();
    });
  });

  // Cambio per_page
  const perPageSel = document.getElementById("per_page");
  if (perPageSel) {
    perPageSel.addEventListener("change", () => {
      const page = document.getElementById("page");
      if (page) page.value = "1";
      if (form) form.submit();
    });
  }

  // ==========================================================
  // 2. AUTOCOMPLETE PARA CLIENTE
  // ==========================================================

  const clienteBuscar = document.getElementById("cliente_buscar");
  const clienteId = document.getElementById("cliente_id");
  const clienteDropdown = document.getElementById("cliente_dropdown");
  let clienteSearchTimeout = null;

  if (clienteBuscar && clienteDropdown) {
    clienteBuscar.addEventListener("input", function () {
      const q = this.value.trim();

      clearTimeout(clienteSearchTimeout);

      if (q.length < 2) {
        clienteDropdown.classList.add("hidden");
        clienteId.value = "";
        return;
      }

      clienteSearchTimeout = setTimeout(async () => {
        try {
          const response = await fetch(
            `api/ventas_api.php?action=buscar_clientes&q=${encodeURIComponent(q)}`,
          );
          const data = await response.json();

          if (data.success && data.clientes.length > 0) {
            clienteDropdown.innerHTML = data.clientes
              .map(
                (c) => `
                <div class="autocomplete-item" data-id="${c.id}" data-nombre="${c.nombre}">
                  <div class="ac-nombre">${c.nombre}</div>
                  <div class="ac-detalle">${c.documento || ""} ${c.telefono ? "· " + c.telefono : ""}</div>
                </div>
              `,
              )
              .join("");
            clienteDropdown.classList.remove("hidden");

            // Click en item
            clienteDropdown
              .querySelectorAll(".autocomplete-item")
              .forEach((item) => {
                item.addEventListener("click", function () {
                  clienteBuscar.value = this.dataset.nombre;
                  clienteId.value = this.dataset.id;
                  clienteDropdown.classList.add("hidden");
                });
              });
          } else {
            clienteDropdown.innerHTML =
              '<div class="autocomplete-empty">No se encontraron clientes</div>';
            clienteDropdown.classList.remove("hidden");
          }
        } catch (error) {
          console.error("Error buscando clientes:", error);
          clienteDropdown.classList.add("hidden");
        }
      }, 300);
    });

    // Cerrar dropdown al hacer click afuera
    document.addEventListener("click", function (e) {
      if (
        !clienteBuscar.contains(e.target) &&
        !clienteDropdown.contains(e.target)
      ) {
        clienteDropdown.classList.add("hidden");
      }
    });
  }

  // ==========================================================
  // 3. AUTOCOMPLETE PARA PRODUCTO
  // ==========================================================

  const productoBuscar = document.getElementById("producto_buscar");
  const productoId = document.getElementById("producto_id");
  const productoDropdown = document.getElementById("producto_dropdown");
  let productoSearchTimeout = null;

  if (productoBuscar && productoDropdown) {
    productoBuscar.addEventListener("input", function () {
      const q = this.value.trim();

      clearTimeout(productoSearchTimeout);

      if (q.length < 2) {
        productoDropdown.classList.add("hidden");
        productoId.value = "";
        return;
      }

      productoSearchTimeout = setTimeout(async () => {
        try {
          const response = await fetch(
            `api/actions/buscar_productos.php?query=${encodeURIComponent(q)}`,
          );
          const data = await response.json();

          if (data.success && data.productos.length > 0) {
            productoDropdown.innerHTML = data.productos
              .map(
                (p) => `
                <div class="autocomplete-item" data-id="${p.id}" data-nombre="${p.nombre}">
                  <div class="ac-nombre">${p.codigo} - ${p.nombre}</div>
                  <div class="ac-detalle">Stock: ${p.stock} · $${parseFloat(p.precio).toFixed(2)}</div>
                </div>
              `,
              )
              .join("");
            productoDropdown.classList.remove("hidden");

            // Click en item
            productoDropdown
              .querySelectorAll(".autocomplete-item")
              .forEach((item) => {
                item.addEventListener("click", function () {
                  productoBuscar.value = this.dataset.nombre;
                  productoId.value = this.dataset.id;
                  productoDropdown.classList.add("hidden");
                });
              });
          } else {
            productoDropdown.innerHTML =
              '<div class="autocomplete-empty">No se encontraron productos</div>';
            productoDropdown.classList.remove("hidden");
          }
        } catch (error) {
          console.error("Error buscando productos:", error);
          productoDropdown.classList.add("hidden");
        }
      }, 300);
    });

    // Cerrar dropdown al hacer click afuera
    document.addEventListener("click", function (e) {
      if (
        !productoBuscar.contains(e.target) &&
        !productoDropdown.contains(e.target)
      ) {
        productoDropdown.classList.add("hidden");
      }
    });
  }

  // ==========================================================
  // 4. REMOVER FILTROS ACTIVOS
  // ==========================================================

  document.querySelectorAll(".filter-remove").forEach((btn) => {
    btn.addEventListener("click", function (e) {
      e.preventDefault();
      const filter = this.dataset.filter;
      const url = new URL(window.location.href);

      switch (filter) {
        case "medio":
          url.searchParams.delete("medio");
          break;
        case "estado":
          url.searchParams.delete("estado");
          break;
        case "fecha":
          url.searchParams.delete("desde");
          url.searchParams.delete("hasta");
          break;
        case "cliente":
          url.searchParams.delete("cliente_id");
          break;
        case "producto":
          url.searchParams.delete("producto_id");
          break;
        case "terminal":
          url.searchParams.delete("terminal_id");
          break;
        case "facturado":
          url.searchParams.delete("facturado");
          break;
      }

      url.searchParams.set("page", "1");
      window.location.href = url.pathname + "?" + url.searchParams.toString();
    });
  });

  // ==========================================================
  // 5. VISTA PREVIA RÁPIDA (QUICK PREVIEW)
  // ==========================================================

  const quickPreviewModal = document.getElementById("quickPreviewModal");
  const closePreview = document.getElementById("closePreview");
  const previewCerrar = document.getElementById("previewCerrar");
  const previewVerDetalle = document.getElementById("previewVerDetalle");
  const previewImprimir = document.getElementById("previewImprimir");
  const previewContent = document.getElementById("previewContent");
  const previewVentaId = document.getElementById("previewVentaId");

  // Botones de preview en tabla
  document.querySelectorAll(".btn-mini-preview").forEach((btn) => {
    btn.addEventListener("click", async function () {
      const ventaId = this.dataset.ventaId;
      showQuickPreview(ventaId);
    });
  });

  async function showQuickPreview(ventaId) {
    if (!quickPreviewModal || !previewContent || !previewVentaId) return;

    // Mostrar modal
    quickPreviewModal.classList.remove("hidden");
    previewVentaId.textContent = ventaId;

    // Mostrar loading
    previewContent.innerHTML = `
      <div class="preview-loading">
        <div class="spinner"></div>
        <p>Cargando...</p>
      </div>
    `;

    // Actualizar links
    if (previewVerDetalle) {
      previewVerDetalle.href = `venta_detalle.php?id=${ventaId}`;
    }
    if (previewImprimir) {
      previewImprimir.href = `ticket.php?id=${ventaId}&paper=${getPaper()}`;
    }

    try {
      const response = await fetch(
        `api/ventas_api.php?action=venta_preview&id=${ventaId}`,
      );
      const data = await response.json();

      if (data.success) {
        const venta = data.venta;

        previewContent.innerHTML = `
          <div class="preview-grid">
            <div class="preview-section">
              <h4>Información General</h4>
              <div class="preview-row">
                <span class="preview-label">Fecha</span>
                <span class="preview-value">${venta.fecha}</span>
              </div>
              <div class="preview-row">
                <span class="preview-label">Cliente</span>
                <span class="preview-value">${venta.cliente}</span>
              </div>
              <div class="preview-row">
                <span class="preview-label">Total</span>
                <span class="preview-value strong">$${parseFloat(venta.total).toFixed(2)}</span>
              </div>
            </div>

            <div class="preview-section">
              <h4>Productos (${venta.items.length})</h4>
              <div class="preview-items">
                ${venta.items
                  .map(
                    (item) => `
                  <div class="preview-item">
                    <span>${item.cantidad}x ${item.nombre}</span>
                    <span>$${parseFloat(item.subtotal).toFixed(2)}</span>
                  </div>
                `,
                  )
                  .join("")}
              </div>
            </div>
          </div>
        `;
      } else {
        previewContent.innerHTML = `
          <div class="preview-error">
            <p>⚠️ Error al cargar la venta</p>
          </div>
        `;
      }
    } catch (error) {
      console.error("Error loading preview:", error);
      previewContent.innerHTML = `
        <div class="preview-error">
          <p>⚠️ Error de conexión</p>
        </div>
      `;
    }
  }

  // Cerrar modal
  if (closePreview) {
    closePreview.addEventListener("click", () => {
      quickPreviewModal.classList.add("hidden");
    });
  }

  if (previewCerrar) {
    previewCerrar.addEventListener("click", () => {
      quickPreviewModal.classList.add("hidden");
    });
  }

  // Cerrar al hacer click en backdrop
  if (quickPreviewModal) {
    quickPreviewModal.addEventListener("click", function (e) {
      if (e.target === this) {
        this.classList.add("hidden");
      }
    });
  }

  // ==========================================================
  // 6. SELECCIÓN MÚLTIPLE Y ACCIONES EN MASA
  // ==========================================================

  const selectAll = document.getElementById("selectAll");
  const bulkActionsBar = document.getElementById("bulkActionsBar");
  const bulkCount = document.getElementById("bulkCount");
  const btnDeselectAll = document.getElementById("btnDeselectAll");
  const btnBulkPrint = document.getElementById("btnBulkPrint");
  const btnBulkExport = document.getElementById("btnBulkExport");

  let selectedVentas = new Set();

  function updateBulkActions() {
    if (selectedVentas.size > 0) {
      bulkActionsBar.classList.remove("hidden");
      bulkCount.textContent = selectedVentas.size;
    } else {
      bulkActionsBar.classList.add("hidden");
    }
  }

  // Seleccionar todas
  if (selectAll) {
    selectAll.addEventListener("change", function () {
      const checkboxes = document.querySelectorAll(".venta-check");
      checkboxes.forEach((cb) => {
        cb.checked = this.checked;
        if (this.checked) {
          selectedVentas.add(parseInt(cb.value));
        } else {
          selectedVentas.delete(parseInt(cb.value));
        }
      });
      updateBulkActions();
    });
  }

  // Checkboxes individuales
  document.querySelectorAll(".venta-check").forEach((cb) => {
    cb.addEventListener("change", function () {
      const id = parseInt(this.value);
      if (this.checked) {
        selectedVentas.add(id);
      } else {
        selectedVentas.delete(id);
        if (selectAll) selectAll.checked = false;
      }
      updateBulkActions();
    });
  });

  // Deseleccionar todas
  if (btnDeselectAll) {
    btnDeselectAll.addEventListener("click", () => {
      selectedVentas.clear();
      document.querySelectorAll(".venta-check").forEach((cb) => {
        cb.checked = false;
      });
      if (selectAll) selectAll.checked = false;
      updateBulkActions();
    });
  }

  // Imprimir en masa
  if (btnBulkPrint) {
    btnBulkPrint.addEventListener("click", () => {
      if (selectedVentas.size === 0) {
        alert("No hay ventas seleccionadas");
        return;
      }

      const ids = Array.from(selectedVentas).join(",");
      const paper = getPaper();
      window.open(`ticket_bulk.php?ids=${ids}&paper=${paper}`, "_blank");
    });
  }

  // Exportar selección
  if (btnBulkExport) {
    btnBulkExport.addEventListener("click", () => {
      if (selectedVentas.size === 0) {
        alert("No hay ventas seleccionadas");
        return;
      }

      const url = new URL(window.location.href);
      // Agregar IDs seleccionados como filtro
      url.searchParams.set("ids", Array.from(selectedVentas).join(","));
      url.searchParams.set("export", "csv");
      window.location.href = url.toString();
    });
  }

  // ==========================================================
  // 7. GRÁFICOS (Chart.js)
  // ==========================================================

  const btnToggleCharts = document.getElementById("btnToggleCharts");
  const chartsPanel = document.getElementById("chartsPanel");
  let chartsLoaded = false;

  if (btnToggleCharts && chartsPanel) {
    btnToggleCharts.addEventListener("click", async function () {
      chartsPanel.classList.toggle("hidden");

      if (!chartsPanel.classList.contains("hidden") && !chartsLoaded) {
        this.textContent = "⏳ Cargando gráficos...";
        this.disabled = true;

        await loadCharts();

        this.textContent = "📊 Ocultar gráficos";
        this.disabled = false;
        chartsLoaded = true;
      } else {
        this.textContent = chartsPanel.classList.contains("hidden")
          ? "📊 Mostrar gráficos"
          : "📊 Ocultar gráficos";
      }
    });
  }

  async function loadCharts() {
    try {
      // Gráfico 1: Ventas por día
      const ventasPorDiaResponse = await fetch(
        "api/ventas_api.php?action=stats_ventas_por_dia",
      );
      const ventasPorDiaData = await ventasPorDiaResponse.json();

      if (ventasPorDiaData.success) {
        const ctx = document.getElementById("chartVentasPorDia");
        if (ctx && typeof Chart !== "undefined") {
          new Chart(ctx, {
            type: "line",
            data: {
              labels: ventasPorDiaData.stats.map((s) => s.fecha),
              datasets: [
                {
                  label: "Total vendido",
                  data: ventasPorDiaData.stats.map((s) => parseFloat(s.total)),
                  borderColor: "rgb(6, 182, 212)",
                  backgroundColor: "rgba(6, 182, 212, 0.1)",
                  tension: 0.3,
                  fill: true,
                },
              ],
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: {
                  display: false,
                },
              },
              scales: {
                y: {
                  beginAtZero: true,
                  ticks: {
                    callback: function (value) {
                      return "$" + value.toFixed(0);
                    },
                  },
                },
              },
            },
          });
        }
      }

      // Gráfico 2: Medios de pago
      const mediosPagoResponse = await fetch(
        "api/ventas_api.php?action=stats_ventas_por_medio",
      );
      const mediosPagoData = await mediosPagoResponse.json();

      if (mediosPagoData.success) {
        const ctx = document.getElementById("chartMediosPago");
        if (ctx && typeof Chart !== "undefined") {
          const colores = {
            EFECTIVO: "#10b981",
            MP: "#3b82f6",
            DEBITO: "#f59e0b",
            CREDITO: "#8b5cf6",
            SIN_ESPECIFICAR: "#6b7280",
          };

          new Chart(ctx, {
            type: "doughnut",
            data: {
              labels: mediosPagoData.stats.map((s) => s.medio_pago),
              datasets: [
                {
                  data: mediosPagoData.stats.map((s) => parseFloat(s.total)),
                  backgroundColor: mediosPagoData.stats.map(
                    (s) => colores[s.medio_pago] || "#6b7280",
                  ),
                },
              ],
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: {
                  position: "bottom",
                },
                tooltip: {
                  callbacks: {
                    label: function (context) {
                      return context.label + ": $" + context.parsed.toFixed(2);
                    },
                  },
                },
              },
            },
          });
        }
      }
    } catch (error) {
      console.error("Error loading charts:", error);
    }
  }

  // ==========================================================
  // 8. HELPERS Y UTILIDADES
  // ==========================================================

  function formatMoney(value) {
    return "$" + parseFloat(value).toFixed(2);
  }

  // Toast notifications
  function showToast(message, type = "info") {
    const toast = document.createElement("div");
    toast.className = `toast toast-${type} show`;
    toast.innerHTML = `
      <div class="toast-content">
        <div class="toast-icon">${type === "success" ? "✓" : "ℹ"}</div>
        <div class="toast-message">${message}</div>
      </div>
    `;

    document.body.appendChild(toast);

    setTimeout(() => {
      toast.classList.remove("show");
      setTimeout(() => toast.remove(), 300);
    }, 3000);
  }

  // Hacer disponible globalmente
  window.showToast = showToast;
  window.showQuickPreview = showQuickPreview;
});

/**
 * FLUS - Módulo de Gestión de Precios
 * JavaScript para historial, herramientas masivas y análisis de márgenes
 *
 * @version 2.0.0
 */

(function () {
  "use strict";

  // ============================================
  // CONFIGURACIÓN Y ESTADO
  // ============================================
  const CONFIG = {
    debounceDelay: 300,
    previewLimit: 5,
    apiEndpoint: "api/precios_api.php",
  };

  const state = {
    selectedProducts: new Map(), // id => {codigo, nombre, precio, costo}
    categorias: [],
    loading: false,
    currentView: "herramientas",
  };

  // ============================================
  // UTILIDADES
  // ============================================
  function debounce(fn, delay) {
    let timer;
    return function (...args) {
      clearTimeout(timer);
      timer = setTimeout(() => fn.apply(this, args), delay);
    };
  }

  function formatCurrency(value) {
    return new Intl.NumberFormat("es-AR", {
      style: "currency",
      currency: "ARS",
      minimumFractionDigits: 2,
    }).format(value);
  }

  function formatPercent(value) {
    const sign = value >= 0 ? "+" : "";
    return sign + value.toFixed(1) + "%";
  }

  function h(str) {
    const div = document.createElement("div");
    div.textContent = str;
    return div.innerHTML;
  }

  function getCSRFToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.content : "";
  }

  async function apiRequest(action, data = {}) {
    const formData = new FormData();
    formData.append("action", action);
    formData.append("csrf_token", getCSRFToken());

    for (const [key, value] of Object.entries(data)) {
      formData.append(
        key,
        typeof value === "object" ? JSON.stringify(value) : value,
      );
    }

    const response = await fetch(CONFIG.apiEndpoint, {
      method: "POST",
      body: formData,
    });

    if (!response.ok) {
      throw new Error("Error de red");
    }

    return response.json();
  }

  // ============================================
  // GESTIÓN DE SELECCIÓN
  // ============================================
  function toggleProductSelection(checkbox) {
    const id = parseInt(checkbox.value, 10);
    const row = checkbox.closest(".producto-row");

    if (checkbox.checked) {
      state.selectedProducts.set(id, {
        id: id,
        codigo: row.dataset.codigo || "",
        nombre: row.dataset.nombre || "",
        precio: parseFloat(row.dataset.precio) || 0,
        costo: parseFloat(row.dataset.costo) || 0,
      });
    } else {
      state.selectedProducts.delete(id);
    }

    updateSelectionUI();
    updateCategoryCheckboxes();
    updatePreview();
  }

  function toggleCategorySelection(checkbox) {
    const catItem = checkbox.closest(".categoria-item");
    const productCheckboxes = catItem.querySelectorAll(
      '.producto-row input[type="checkbox"]',
    );

    productCheckboxes.forEach((cb) => {
      cb.checked = checkbox.checked;
      const id = parseInt(cb.value, 10);
      const row = cb.closest(".producto-row");

      if (checkbox.checked) {
        state.selectedProducts.set(id, {
          id: id,
          codigo: row.dataset.codigo || "",
          nombre: row.dataset.nombre || "",
          precio: parseFloat(row.dataset.precio) || 0,
          costo: parseFloat(row.dataset.costo) || 0,
        });
      } else {
        state.selectedProducts.delete(id);
      }
    });

    updateSelectionUI();
    updatePreview();
  }

  function selectAll(select = true) {
    document
      .querySelectorAll('.producto-row input[type="checkbox"]')
      .forEach((cb) => {
        cb.checked = select;
        const id = parseInt(cb.value, 10);
        const row = cb.closest(".producto-row");

        if (select) {
          state.selectedProducts.set(id, {
            id: id,
            codigo: row.dataset.codigo || "",
            nombre: row.dataset.nombre || "",
            precio: parseFloat(row.dataset.precio) || 0,
            costo: parseFloat(row.dataset.costo) || 0,
          });
        }
      });

    if (!select) {
      state.selectedProducts.clear();
    }

    updateSelectionUI();
    updateCategoryCheckboxes();
    updatePreview();
  }

  function clearSelection() {
    selectAll(false);
  }

  function updateSelectionUI() {
    const count = state.selectedProducts.size;

    // Actualizar contador
    const counterEl = document.getElementById("selectionCount");
    if (counterEl) {
      counterEl.textContent = count;
    }

    // Actualizar sección completa
    const counterSection = document.querySelector(".selection-counter");
    if (counterSection) {
      counterSection.style.display = count > 0 ? "flex" : "none";
    }

    // Actualizar input oculto
    const idsInput = document.getElementById("productoIds");
    if (idsInput) {
      idsInput.value = Array.from(state.selectedProducts.keys()).join(",");

    const idsInputMargen = document.getElementById("productoIdsMargen");
    if (idsInputMargen) {
      idsInputMargen.value = idsInput.value;
    }
    }

    // Habilitar/deshabilitar botones
    document.querySelectorAll(".btn-apply").forEach((btn) => {
      btn.disabled = count === 0;
    });
  }

  function updateCategoryCheckboxes() {
    document.querySelectorAll(".categoria-item").forEach((catItem) => {
      const catCheckbox = catItem.querySelector(".categoria-checkbox");
      const productCheckboxes = catItem.querySelectorAll(
        '.producto-row input[type="checkbox"]',
      );

      if (productCheckboxes.length === 0) return;

      const checkedCount = Array.from(productCheckboxes).filter(
        (cb) => cb.checked,
      ).length;

      catCheckbox.checked = checkedCount === productCheckboxes.length;
      catCheckbox.indeterminate =
        checkedCount > 0 && checkedCount < productCheckboxes.length;
    });
  }

  // ============================================
  // PREVIEW DE CAMBIOS
  // ============================================
  function updatePreview() {
    const previewContainer = document.getElementById("previewList");
    if (!previewContainer) return;

    const porcentaje =
      parseFloat(document.getElementById("porcentajeInput")?.value) || 0;
    const redondeo =
      document.getElementById("redondeoSelect")?.value || "NINGUNO";

    if (state.selectedProducts.size === 0 || porcentaje === 0) {
      previewContainer.innerHTML =
        '<p class="text-muted" style="text-align: center; padding: 1rem;">Seleccioná productos y un porcentaje para ver la vista previa</p>';
      return;
    }

    let html = "";
    let count = 0;

    for (const [id, product] of state.selectedProducts) {
      if (count >= CONFIG.previewLimit) {
        const remaining = state.selectedProducts.size - CONFIG.previewLimit;
        html += `<div class="preview-item" style="color: var(--text-muted); font-style: italic;">... y ${remaining} producto(s) más</div>`;
        break;
      }

      const precioAnterior = product.precio;
      let precioNuevo = precioAnterior * (1 + porcentaje / 100);
      precioNuevo = aplicarRedondeo(precioNuevo, redondeo);

      const isIncrease = precioNuevo > precioAnterior;

      html += `
                <div class="preview-item">
                    <span class="nombre" title="${h(product.nombre)}">${h(product.nombre)}</span>
                    <span class="precios">
                        <span class="old">${formatCurrency(precioAnterior)}</span>
                        <span class="arrow">→</span>
                        <span class="new ${isIncrease ? "increase" : ""}">${formatCurrency(precioNuevo)}</span>
                    </span>
                </div>
            `;
      count++;
    }

    previewContainer.innerHTML = html;
  }

  function aplicarRedondeo(precio, tipo) {
    switch (tipo) {
      case "ENTERO":
        return Math.round(precio);
      case "5":
        return Math.round(precio / 5) * 5;
      case "10":
        return Math.round(precio / 10) * 10;
      case "50":
        return Math.round(precio / 50) * 50;
      case "100":
        return Math.round(precio / 100) * 100;
      case "990":
        return Math.floor(precio / 1000) * 1000 + 990;
      default:
        return Math.round(precio * 100) / 100;
    }
  }

  // ============================================
  // ACORDEONES DE CATEGORÍAS
  // ============================================
  function toggleCategoria(header) {
    const catItem = header.closest(".categoria-item");
    const isExpanded = catItem.classList.contains("expanded");

    // Cerrar otros si se quiere comportamiento de acordeón
    // document.querySelectorAll('.categoria-item.expanded').forEach(item => {
    //     if (item !== catItem) item.classList.remove('expanded');
    // });

    catItem.classList.toggle("expanded", !isExpanded);
  }

  function expandAll() {
    document.querySelectorAll(".categoria-item").forEach((item) => {
      item.classList.add("expanded");
    });
  }

  function collapseAll() {
    document.querySelectorAll(".categoria-item").forEach((item) => {
      item.classList.remove("expanded");
    });
  }

  // ============================================
  // BÚSQUEDA DE PRODUCTOS
  // ============================================
  const handleSearch = debounce(function (query) {
    query = query.trim().toLowerCase();

    document.querySelectorAll(".categoria-item").forEach((catItem) => {
      const productos = catItem.querySelectorAll(".producto-row");
      let visibleCount = 0;

      productos.forEach((row) => {
        const codigo = (row.dataset.codigo || "").toLowerCase();
        const nombre = (row.dataset.nombre || "").toLowerCase();
        const matches =
          query === "" || codigo.includes(query) || nombre.includes(query);

        row.style.display = matches ? "" : "none";
        if (matches) visibleCount++;
      });

      // Mostrar/ocultar categoría según si tiene productos visibles
      catItem.style.display = visibleCount > 0 ? "" : "none";

      // Expandir categoría si tiene resultados de búsqueda
      if (query !== "" && visibleCount > 0) {
        catItem.classList.add("expanded");
      }

      // Actualizar contador de categoría
      const countEl = catItem.querySelector(".categoria-count");
      if (countEl && query !== "") {
        countEl.textContent = visibleCount;
      }
    });
  }, CONFIG.debounceDelay);

  // ============================================
  // FORMULARIOS
  // ============================================
  function handleAjusteSubmit(e) {
    e.preventDefault();

    if (state.selectedProducts.size === 0) {
      showAlert("Seleccioná al menos un producto", "warning");
      return;
    }

    const porcentaje =
      parseFloat(document.getElementById("porcentajeInput")?.value) || 0;
    if (porcentaje === 0) {
      showAlert("El porcentaje no puede ser 0", "warning");
      return;
    }

    const count = state.selectedProducts.size;
    const action = porcentaje > 0 ? "aumentar" : "disminuir";
    const msg = `¿Estás seguro de ${action} el precio de ${count} producto(s) en ${Math.abs(porcentaje)}%?`;

    if (!confirm(msg)) return;

    // Submit del form
    const form = e.target;
    form.submit();
  }

  function handleMargenSubmit(e) {
    e.preventDefault();

    if (state.selectedProducts.size === 0) {
      showAlert("Seleccioná al menos un producto", "warning");
      return;
    }

    const margen =
      parseFloat(document.getElementById("margenInput")?.value) || 0;
    if (margen <= 0) {
      showAlert("El margen debe ser mayor a 0", "warning");
      return;
    }

    const count = state.selectedProducts.size;
    const msg = `¿Aplicar un margen del ${margen}% sobre el costo a ${count} producto(s)?`;

    if (!confirm(msg)) return;

    // Submit del form
    const form = e.target;
    form.submit();
  }

  // ============================================
  // ALERTAS
  // ============================================
  function showAlert(message, type = "info") {
    const existing = document.querySelector(".alert-floating");
    if (existing) existing.remove();

    const icons = {
      success:
        '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
      warning:
        '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
      error:
        '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
      info: '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
    };

    const alert = document.createElement("div");
    alert.className = `alert alert-${type === "error" ? "err" : type === "success" ? "ok" : type} alert-floating`;
    alert.style.cssText =
      "position: fixed; top: 1rem; right: 1rem; z-index: 9999; min-width: 300px; animation: slideIn 0.3s ease;";
    alert.innerHTML = `
            <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                ${icons[type] || icons.info}
            </svg>
            <span>${h(message)}</span>
        `;

    document.body.appendChild(alert);

    setTimeout(() => {
      alert.style.animation = "slideOut 0.3s ease";
      setTimeout(() => alert.remove(), 300);
    }, 4000);
  }

  // ============================================
  // HISTORIAL - FILTROS
  // ============================================
  function initHistorialFilters() {
    const tipoFilter = document.getElementById("filtroTipo");
    const fechaDesde = document.getElementById("filtroFechaDesde");
    const fechaHasta = document.getElementById("filtroFechaHasta");

    if (tipoFilter) {
      tipoFilter.addEventListener("change", applyHistorialFilters);
    }
    if (fechaDesde) {
      fechaDesde.addEventListener("change", applyHistorialFilters);
    }
    if (fechaHasta) {
      fechaHasta.addEventListener("change", applyHistorialFilters);
    }
  }

  function applyHistorialFilters() {
    const tipo = document.getElementById("filtroTipo")?.value || "";
    const desde = document.getElementById("filtroFechaDesde")?.value || "";
    const hasta = document.getElementById("filtroFechaHasta")?.value || "";

    document.querySelectorAll(".hist-item").forEach((item) => {
      const itemTipo = item.dataset.tipo || "";
      const itemFecha = item.dataset.fecha || "";

      let show = true;

      if (tipo && itemTipo !== tipo) {
        show = false;
      }

      if (desde && itemFecha < desde) {
        show = false;
      }

      if (hasta && itemFecha > hasta) {
        show = false;
      }

      item.style.display = show ? "" : "none";
    });
  }

  // ============================================
  // MÁRGENES - UMBRAL DINÁMICO
  // ============================================
  function initMargenesFilters() {
    const umbralInput = document.getElementById("umbralInput");
    if (umbralInput) {
      umbralInput.addEventListener("change", function () {
        const form = this.closest("form");
        if (form) form.submit();
      });
    }
  }

  // ============================================
  // KEYBOARD SHORTCUTS
  // ============================================
  function initKeyboardShortcuts() {
    document.addEventListener("keydown", function (e) {
      // Ctrl/Cmd + A = Seleccionar todos
      if ((e.ctrlKey || e.metaKey) && String(e.key).toLowerCase() === "a") {
        const searchInput = document.getElementById("searchProductos");
        if (document.activeElement !== searchInput) {
          e.preventDefault();
          selectAll(true);
        }
      }

      // Escape = Limpiar selección
      if (e.key === "Escape") {
        clearSelection();
      }

      // Ctrl/Cmd + Shift + E = Expandir todo (evita conflictos del navegador)
      if ((e.ctrlKey || e.metaKey) && e.shiftKey && String(e.key).toLowerCase() === "e") {
        e.preventDefault();
        expandAll();
      }

      // Ctrl/Cmd + Shift + C = Colapsar todo
      if ((e.ctrlKey || e.metaKey) && e.shiftKey && String(e.key).toLowerCase() === "c") {
        e.preventDefault();
        collapseAll();
      }
    });
  }

  // ============================================
  // INICIALIZACIÓN
  // ============================================
  function init() {
    // Detectar vista actual
    const urlParams = new URLSearchParams(window.location.search);
    state.currentView = urlParams.get("v") || "historial";

    // Búsqueda de productos
    const searchInput = document.getElementById("searchProductos");
    if (searchInput) {
      searchInput.addEventListener("input", (e) =>
        handleSearch(e.target.value),
      );
    }

    // Checkboxes de productos
    document
      .querySelectorAll('.producto-row input[type="checkbox"]')
      .forEach((cb) => {
        cb.addEventListener("change", () => toggleProductSelection(cb));
      });

    // Checkboxes de categorías
    document.querySelectorAll(".categoria-checkbox").forEach((cb) => {
      cb.addEventListener("change", () => toggleCategorySelection(cb));
    });

    // Toggle de categorías (acordeón)
    document.querySelectorAll(".categoria-header").forEach((header) => {
      header.addEventListener("click", (e) => {
        // No toggle si click en checkbox
        if (e.target.closest('input[type="checkbox"]')) return;
        toggleCategoria(header);
      });
    });

    // Formulario de ajuste masivo
    const formAjuste = document.getElementById("formAjusteMasivo");
    if (formAjuste) {
      formAjuste.addEventListener("submit", handleAjusteSubmit);
    }

    // Formulario de margen
    const formMargen = document.getElementById("formAplicarMargen");
    if (formMargen) {
      formMargen.addEventListener("submit", handleMargenSubmit);
    }

    // Input de porcentaje para preview
    const porcentajeInput = document.getElementById("porcentajeInput");
    if (porcentajeInput) {
      porcentajeInput.addEventListener("input", debounce(updatePreview, 200));
    }

    // Select de redondeo para preview
    const redondeoSelect = document.getElementById("redondeoSelect");
    if (redondeoSelect) {
      redondeoSelect.addEventListener("change", updatePreview);
    }

    // Botón limpiar selección
    const clearBtn = document.getElementById("clearSelectionBtn");
    if (clearBtn) {
      clearBtn.addEventListener("click", clearSelection);
    }

    // Botones expandir/colapsar
    const expandBtn = document.getElementById("expandAllBtn");
    if (expandBtn) {
      expandBtn.addEventListener("click", expandAll);
    }

    const collapseBtn = document.getElementById("collapseAllBtn");
    if (collapseBtn) {
      collapseBtn.addEventListener("click", collapseAll);
    }

    // Inicializar filtros según vista
    if (state.currentView === "historial") {
      initHistorialFilters();
    }

    if (state.currentView === "margenes") {
      initMargenesFilters();
    }

    // Keyboard shortcuts
    initKeyboardShortcuts();

    // Actualizar UI inicial
    updateSelectionUI();

    console.log("[FLUS Precios] Módulo inicializado");
  }

  // Exponer funciones necesarias globalmente
  window.FLUSPrecios = {
    selectAll,
    clearSelection,
    expandAll,
    collapseAll,
    showAlert,
  };

  // Iniciar cuando DOM esté listo
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  // Estilos para animaciones de alertas
  const style = document.createElement("style");
  style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
    `;
  document.head.appendChild(style);
})();

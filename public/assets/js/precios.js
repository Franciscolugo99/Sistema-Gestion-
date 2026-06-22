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
    selectedProducts: new Map(), // id => {id,categoria,codigo,nombre,precio,costo}
    categorias: [],
    loading: false,
    currentView: "herramientas",
    productosConPerdida: 0,
    productosConMargenBajo: 0,

    // Herramientas: para evitar render masivo (3000+ productos)
    defaultCategoriesHtml: "",
    searchQuery: "",
    searchMode: false,
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
    const categoria = (row?.dataset.cat || "").trim();

    if (checkbox.checked) {
      state.selectedProducts.set(id, {
        id: id,
        categoria: categoria,
        codigo: row?.dataset.codigo || "",
        nombre: row?.dataset.nombre || "",
        precio: parseFloat(row?.dataset.precio) || 0,
        costo: parseFloat(row?.dataset.costo) || 0,
      });
    } else {
      state.selectedProducts.delete(id);
    }

    updateSelectionUI();
    updateCategoryCheckboxes();
    updatePreview();
  }

  async function toggleCategorySelection(checkbox) {
    const catItem = checkbox.closest(".categoria-item");
    if (!catItem) return;

    const catName = (catItem.dataset.cat || "").trim();
    const isSearch = String(catItem.dataset.search || "0") === "1";

    // En modo búsqueda, seleccionamos/desseleccionamos SOLO lo visible
    if (isSearch) {
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
            categoria: (row?.dataset.cat || catName).trim(),
            codigo: row?.dataset.codigo || "",
            nombre: row?.dataset.nombre || "",
            precio: parseFloat(row?.dataset.precio) || 0,
            costo: parseFloat(row?.dataset.costo) || 0,
          });
        } else {
          state.selectedProducts.delete(id);
        }
      });

      updateSelectionUI();
      updateCategoryCheckboxes();
      updatePreview();
      return;
    }

    // Modo normal: seleccionar toda la categoría sin renderizar todo
    if (checkbox.checked) {
      const total = parseInt(catItem.dataset.count || "0", 10) || 0;
      if (total > 600) {
        const ok = await Notif.confirmar(
          "📦 Selección grande",
          `<p>Vas a seleccionar <strong>${total} productos</strong> de la categoría "<strong>${catName}</strong>".</p><p style='color:var(--muted,#94a3b8);font-size:.88rem'>Puede tardar unos segundos.</p>`,
          { icon: "info", confirmText: "✅ Continuar", cancelText: "❌ Cancelar" }
        );
        if (!ok) { checkbox.checked = false; return; }
      }

      try {
        const q = ""; // categoría completa
        const url = buildHerramientasUrl({
          ajax_categoria_ids: 1,
          cat: catName,
          q: q,
          _ts: Date.now(),
        });

        const res = await fetch(url, { headers: { Accept: "application/json" } });
        const data = await res.json();

        if (!data || !data.success) {
          throw new Error(data?.error || "Error al cargar la categoría");
        }

        (data.products || []).forEach((p) => {
          if (!p || !p.id) return;
          state.selectedProducts.set(parseInt(p.id, 10), {
            id: parseInt(p.id, 10),
            categoria: (p.categoria || catName).trim(),
            codigo: p.codigo || "",
            nombre: p.nombre || "",
            precio: parseFloat(p.precio) || 0,
            costo: parseFloat(p.costo) || 0,
          });
        });

        // Sincronizar checkboxes visibles (si la categoría ya estaba cargada)
        const container = catItem.querySelector(".categoria-productos");
        if (container) syncCheckboxesFromState(container);
      } catch (err) {
        showAlert(err?.message || "No se pudo seleccionar la categoría", "warning");
        checkbox.checked = false;
        return;
      }
    } else {
      // Deseleccionar todo lo que pertenezca a la categoría
      for (const [id, p] of state.selectedProducts) {
        if (((p?.categoria || "").trim() || "") === catName) {
          state.selectedProducts.delete(id);
        }
      }

      const container = catItem.querySelector(".categoria-productos");
      if (container) syncCheckboxesFromState(container);
    }

    updateSelectionUI();
    updateCategoryCheckboxes();
    updatePreview();
  }

  function selectAll(select = true) {
    document
      .querySelectorAll('.producto-row input[type="checkbox"]')
      .forEach((cb) => {
        cb.checked = select;
        const id = parseInt(cb.value, 10);
        const row = cb.closest(".producto-row");
        const categoria = (row?.dataset.cat || "").trim();

        if (select) {
          state.selectedProducts.set(id, {
            id: id,
            categoria: categoria,
            codigo: row?.dataset.codigo || "",
            nombre: row?.dataset.nombre || "",
            precio: parseFloat(row?.dataset.precio) || 0,
            costo: parseFloat(row?.dataset.costo) || 0,
          });
        } else {
          state.selectedProducts.delete(id);
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
    document.querySelectorAll(".btn-apply:not([data-allow-empty])").forEach((btn) => {
      btn.disabled = count === 0;
    });
  }

  function countSelectedByCategory(catName) {
    let c = 0;
    for (const [, p] of state.selectedProducts) {
      if (((p?.categoria || "").trim() || "") === catName) c++;
    }
    return c;
  }

  function updateCategoryCheckboxes() {
    document.querySelectorAll(".categoria-item").forEach((catItem) => {
      const catCheckbox = catItem.querySelector(".categoria-checkbox");
      if (!catCheckbox) return;

      const catName = (catItem.dataset.cat || "").trim();
      const isSearch = String(catItem.dataset.search || "0") === "1";

      let total = 0;
      let selected = 0;

      if (isSearch) {
        const productCheckboxes = catItem.querySelectorAll(
          '.producto-row input[type="checkbox"]',
        );
        total = productCheckboxes.length;
        selected = Array.from(productCheckboxes).filter((cb) => cb.checked).length;
      } else {
        total = parseInt(catItem.dataset.count || "0", 10) || 0;
        selected = countSelectedByCategory(catName);
      }

      catCheckbox.checked = total > 0 && selected === total;
      catCheckbox.indeterminate = selected > 0 && selected < total;
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
      // Ocultar alerta si existe
      hidePreviewAlert();
      return;
    }

    let html = "";
    let count = 0;
    let productosConPerdida = 0;
    let productosConMargenBajo = 0;

    for (const [id, product] of state.selectedProducts) {
      if (count >= CONFIG.previewLimit) {
        const remaining = state.selectedProducts.size - CONFIG.previewLimit;
        html += `<div class="preview-item" style="color: var(--pm-muted); font-style: italic;">... y ${remaining} producto(s) más</div>`;
        break;
      }

      const precioAnterior = product.precio;
      const costo = product.costo || 0;
      let precioNuevo = precioAnterior * (1 + porcentaje / 100);
      precioNuevo = aplicarRedondeo(precioNuevo, redondeo);

      const isIncrease = precioNuevo > precioAnterior;
      
      // Calcular si quedará con pérdida o margen bajo
      let alertClass = "";
      let alertIcon = "";
      if (costo > 0) {
        if (precioNuevo < costo) {
          productosConPerdida++;
          alertClass = "preview-item--danger";
          alertIcon = `<span class="preview-alert-icon" title="¡Quedará por debajo del costo!">⚠️</span>`;
        } else {
          const margenNuevo = ((precioNuevo - costo) / costo) * 100;
          if (margenNuevo < 10) {
            productosConMargenBajo++;
            alertClass = "preview-item--warning";
          }
        }
      }

      html += `
                <div class="preview-item ${alertClass}">
                    <span class="nombre" title="${h(product.nombre)}">${alertIcon}${h(product.nombre)}</span>
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
    
    // Mostrar alerta si hay productos con pérdida
    showPreviewAlert(productosConPerdida, productosConMargenBajo);
    
    // Guardar estado para la confirmación
    state.productosConPerdida = productosConPerdida;
    state.productosConMargenBajo = productosConMargenBajo;
  }
  
  function showPreviewAlert(conPerdida, conMargenBajo) {
    let alertContainer = document.getElementById("previewAlertContainer");
    
    if (!alertContainer) {
      // Crear contenedor de alertas si no existe
      const previewSection = document.querySelector(".preview-section");
      if (!previewSection) return;
      
      alertContainer = document.createElement("div");
      alertContainer.id = "previewAlertContainer";
      alertContainer.style.marginBottom = "0.75rem";
      previewSection.insertBefore(alertContainer, previewSection.querySelector(".preview-list"));
    }
    
    if (conPerdida === 0 && conMargenBajo === 0) {
      alertContainer.innerHTML = "";
      return;
    }
    
    let html = "";
    
    if (conPerdida > 0) {
      html += `
        <div class="preview-alert preview-alert--danger">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
          </svg>
          <span><strong>${conPerdida}</strong> producto(s) quedarán <strong>por debajo del costo</strong></span>
        </div>
      `;
    }
    
    if (conMargenBajo > 0) {
      html += `
        <div class="preview-alert preview-alert--warning">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
          <span><strong>${conMargenBajo}</strong> producto(s) tendrán margen menor al 10%</span>
        </div>
      `;
    }
    
    alertContainer.innerHTML = html;
  }
  
  function hidePreviewAlert() {
    const alertContainer = document.getElementById("previewAlertContainer");
    if (alertContainer) {
      alertContainer.innerHTML = "";
    }
    state.productosConPerdida = 0;
    state.productosConMargenBajo = 0;
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
  // ACORDEONES DE CATEGORÍAS + CARGA LAZY
  // ============================================

  function buildHerramientasUrl(params = {}) {
    const url = new URL(window.location.href);
    url.searchParams.set("v", "herramientas");
    Object.entries(params).forEach(([k, v]) => {
      if (v === undefined || v === null || v === "") {
        url.searchParams.delete(k);
      } else {
        url.searchParams.set(k, String(v));
      }
    });
    return url.pathname + "?" + url.searchParams.toString();
  }

  function syncCheckboxesFromState(container) {
    if (!container) return;
    container
      .querySelectorAll('.producto-row input[type="checkbox"]')
      .forEach((cb) => {
        const id = parseInt(cb.value, 10);
        const selected = state.selectedProducts.has(id);
        cb.checked = selected;

        // Si está seleccionado pero faltan datos, los completamos desde el DOM
        if (selected) {
          const row = cb.closest(".producto-row");
          const current = state.selectedProducts.get(id);
          if (row && current) {
            state.selectedProducts.set(id, {
              ...current,
              categoria: (current.categoria || row.dataset.cat || "").trim(),
              codigo: current.codigo || row.dataset.codigo || "",
              nombre: current.nombre || row.dataset.nombre || "",
              precio: Number.isFinite(current.precio)
                ? current.precio
                : parseFloat(row.dataset.precio) || 0,
              costo: Number.isFinite(current.costo)
                ? current.costo
                : parseFloat(row.dataset.costo) || 0,
            });
          }
        }
      });
  }

  function preloadSelectedProductsFromServer() {
    const items = Array.isArray(window.FLUS_PRESELECT_PRODUCTOS)
      ? window.FLUS_PRESELECT_PRODUCTOS
      : [];
    if (items.length === 0) return;

    items.forEach((p) => {
      const id = parseInt(p?.id, 10);
      if (!id) return;

      state.selectedProducts.set(id, {
        id: id,
        categoria: (p.categoria || "").trim(),
        codigo: p.codigo || "",
        nombre: p.nombre || "",
        precio: parseFloat(p.precio) || 0,
        costo: parseFloat(p.costo) || 0,
      });
    });

    const margenInput = document.getElementById("margenInput");
    const margenSugerido = parseFloat(window.FLUS_PRESELECT_MARGEN);
    if (margenInput && !margenInput.value && Number.isFinite(margenSugerido)) {
      margenInput.value = String(margenSugerido);
    }

    syncCheckboxesFromState(document);
    updateCategoryCheckboxes();
    updatePreview();
  }

  async function loadCategoria(catItem, { append = false } = {}) {
    const catName = (catItem?.dataset.cat || "").trim();
    const container = catItem?.querySelector(".categoria-productos");
    if (!catName || !container) return;

    // Si estamos en modo búsqueda, no lazy-load (ya viene renderizado)
    const isSearch = String(catItem.dataset.search || "0") === "1";
    if (isSearch) return;

    const offset = parseInt(container.dataset.offset || "0", 10) || 0;
    const limit = 60;

    // UI loading
    container.classList.add("is-loading");
    if (!append) {
      container.innerHTML = '<div class="categoria-loading">Cargando…</div>';
    } else {
      const existingMore = container.querySelector(".categoria-loadmore");
      if (existingMore) existingMore.remove();
      container.insertAdjacentHTML(
        "beforeend",
        '<div class="categoria-loading">Cargando más…</div>',
      );
    }

    try {
      const url = buildHerramientasUrl({
        ajax_categoria: 1,
        cat: catName,
        offset: offset,
        limit: limit,
        _ts: Date.now(),
      });

      const res = await fetch(url, { headers: { Accept: "application/json" } });
      const data = await res.json();

      if (!data || !data.success) {
        throw new Error(data?.error || "Error al cargar productos");
      }

      container.classList.remove("is-loading");

      const html = data.html || "";
      if (!append) {
        container.innerHTML = html || '<div class="categoria-empty">Sin productos.</div>';
      } else {
        // quitar loading
        container.querySelectorAll(".categoria-loading").forEach((el) => el.remove());
        container.insertAdjacentHTML("beforeend", html);
      }

      container.dataset.loaded = "1";
      container.dataset.offset = String(data.next_offset || 0);

      // Load more
      container.querySelectorAll(".categoria-loadmore").forEach((el) => el.remove());
      if (data.has_more) {
        const more = document.createElement("div");
        more.className = "categoria-loadmore";
        more.innerHTML =
          '<button type="button" class="btn btn-ghost btn-sm btn-load-more">Cargar más</button>';
        container.appendChild(more);
      }

      // Sync selection state
      syncCheckboxesFromState(container);
      updateCategoryCheckboxes();
      updateSelectionUI();
      updatePreview();
    } catch (err) {
      container.classList.remove("is-loading");
      container.innerHTML =
        '<div class="categoria-error">No se pudieron cargar productos. Probá de nuevo.</div>';
      showAlert(err?.message || "No se pudieron cargar productos", "warning");
    }
  }

  function toggleCategoria(header) {
    const catItem = header.closest(".categoria-item");
    if (!catItem) return;

    const isExpanded = catItem.classList.contains("expanded");
    catItem.classList.toggle("expanded", !isExpanded);

    // Al expandir por primera vez, cargamos el primer chunk
    if (!isExpanded) {
      const container = catItem.querySelector(".categoria-productos");
      const isSearch = String(catItem.dataset.search || "0") === "1";
      if (!isSearch && container && container.dataset.loaded !== "1") {
        loadCategoria(catItem, { append: false });
      }
    }
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
  // BÚSQUEDA SERVER-SIDE (evita DOM enorme)
  // ============================================
  const handleSearch = debounce(async function (query) {
    const list = document.querySelector(".categorias-list");
    if (!list) return;

    query = String(query || "").trim();
    state.searchQuery = query;

    if (query.length === 0) {
      // Restaurar vista base (solo headers)
      if (state.defaultCategoriesHtml) {
        list.innerHTML = state.defaultCategoriesHtml;
      }
      state.searchMode = false;

      // Sincronizar checks según selección actual
      syncCheckboxesFromState(list);
      updateCategoryCheckboxes();
      updateSelectionUI();
      updatePreview();
      return;
    }

    if (query.length < 2) {
      // Hint liviano
      list.innerHTML =
        '<div class="categorias-hint">Escribí al menos 2 caracteres para buscar.</div>';
      return;
    }

    state.searchMode = true;
    list.classList.add("is-loading");
    list.innerHTML = '<div class="categorias-loading">Buscando…</div>';

    try {
      const url = buildHerramientasUrl({ ajax_search: 1, q: query, _ts: Date.now() });
      const res = await fetch(url, { headers: { Accept: "application/json" } });
      const data = await res.json();

      if (!data || data.success === false) {
        throw new Error(data?.error || "Error al buscar");
      }

      list.classList.remove("is-loading");
      list.innerHTML = data.html || '<div class="categorias-empty">Sin resultados.</div>';

      // Sincronizar checks según selección actual
      syncCheckboxesFromState(list);
      updateCategoryCheckboxes();
      updateSelectionUI();
      updatePreview();
    } catch (err) {
      list.classList.remove("is-loading");
      list.innerHTML =
        '<div class="categorias-empty">No se pudo completar la búsqueda.</div>';
      showAlert(err?.message || "No se pudo buscar", "warning");
    }
  }, CONFIG.debounceDelay);

  // ============================================
  // FORMULARIOS
  // ============================================
  async function handleAjusteSubmit(e) {
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
    
    // Verificar si hay productos que quedarán con pérdida
    let msg = `¿Estás seguro de ${action} el precio de ${count} producto(s) en ${Math.abs(porcentaje)}%?`;
    
    if (state.productosConPerdida > 0) {
      msg = `⚠️ ¡ATENCIÓN!\n\n${state.productosConPerdida} producto(s) quedarán VENDIENDO A PÉRDIDA (por debajo del costo).\n\n¿Estás SEGURO de que querés aplicar este ajuste?`;
      
      // Doble confirmación para pérdidas
      if (!await Notif.confirmar(
        "⚠️ Productos a pérdida",
        `<p style='color:#f87171'><strong>${state.productosConPerdida} producto(s)</strong> quedarán <strong>vendiendo a pérdida</strong> (por debajo del costo).</p><p>¿Estás seguro de aplicar este ajuste?</p>`,
        { icon: "error", confirmText: "⚠️ Sí, aplicar igual", cancelText: "❌ Cancelar" }
      )) return;
      if (!await Notif.confirmar(
        "🔴 Confirmación final",
        "<p>Esta acción <strong>generará pérdidas</strong>. ¿Confirmás que querés continuar?</p>",
        { icon: "error", confirmText: "🔴 Confirmar pérdidas", cancelText: "❌ Cancelar", confirmColor: "#e53935" }
      )) return;
    } else if (state.productosConMargenBajo > 0) {
      if (!await Notif.confirmar(
        "⚠️ Margen bajo",
        `<p>${action.charAt(0).toUpperCase()+action.slice(1)} el precio de <strong>${count} producto(s)</strong> en <strong>${Math.abs(porcentaje)}%</strong>.</p><p style='color:#fbbf24'>⚠️ ${state.productosConMargenBajo} producto(s) tendrán margen menor al 10%.</p>`,
        { icon: "warning", confirmText: "✅ Aplicar igual", cancelText: "❌ Cancelar" }
      )) return;
    } else {
      if (!await Notif.confirmar(
        "💲 Ajuste de precios",
        `<p>${action.charAt(0).toUpperCase()+action.slice(1)} el precio de <strong>${count} producto(s)</strong> en <strong>${Math.abs(porcentaje)}%</strong>.</p>`,
        { icon: "question", confirmText: "✅ Aplicar", cancelText: "❌ Cancelar" }
      )) return;
    }

    // Submit del form
    const form = e.target;
    form.submit();
  }

  async function handleMargenSubmit(e) {
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

    if (!await Notif.confirmar(
      "💲 Ajuste por margen",
      `<p>Aplicar un margen del <strong>${margen}%</strong> sobre el costo a <strong>${count} producto(s)</strong>.</p>`,
      { icon: "question", confirmText: "✅ Aplicar", cancelText: "❌ Cancelar" }
    )) return;

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
    const form = document.getElementById("historialFiltersForm");
    if (!form) return;

    form.querySelectorAll('[data-autosubmit="1"]').forEach((control) => {
      control.addEventListener("change", applyHistorialFilters);
    });
  }
  
  function clearHistorialFilters() {
    const form = document.getElementById("historialFiltersForm");
    const tipoFilter = document.getElementById("filtroTipo");
    const fechaDesde = document.getElementById("filtroFechaDesde");
    const fechaHasta = document.getElementById("filtroFechaHasta");
    const perPage = document.getElementById("historialPerPage");
    
    if (tipoFilter) tipoFilter.value = "";
    if (fechaDesde) fechaDesde.value = "";
    if (fechaHasta) fechaHasta.value = "";
    if (perPage) perPage.value = perPage.dataset.defaultValue || perPage.value;

    if (form) {
      form.submit();
      return;
    }

    document.querySelectorAll(".hist-item").forEach((item) => {
      item.style.display = "";
    });
  }

  function applyHistorialFilters() {
    const form = document.getElementById("historialFiltersForm");
    if (form) {
      form.submit();
      return;
    }

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

    // Herramientas: cache de vista base (solo headers)
    const categoriasList = document.querySelector(".categorias-list");
    if (categoriasList && !state.defaultCategoriesHtml) {
      state.defaultCategoriesHtml = categoriasList.innerHTML;
    }

    // Búsqueda server-side
    const searchInput = document.getElementById("searchProductos");
    if (searchInput) {
      searchInput.addEventListener("input", (e) => handleSearch(e.target.value));
    }

    // Delegación: checkboxes + toggles + load more
    if (categoriasList) {
      categoriasList.addEventListener("change", async (e) => {
        const t = e.target;
        if (!t) return;

        if (t.matches('.producto-row input[type="checkbox"]')) {
          toggleProductSelection(t);
        }

        if (t.matches('.categoria-checkbox')) {
          await toggleCategorySelection(t);
        }
      });

      categoriasList.addEventListener("click", (e) => {
        const t = e.target;
        if (!t) return;

        // Load more
        const btnMore = t.closest(".btn-load-more");
        if (btnMore) {
          const catItem = btnMore.closest(".categoria-item");
          if (catItem) loadCategoria(catItem, { append: true });
          return;
        }

        // Toggle categoría
        const header = t.closest(".categoria-header");
        if (header) {
          // No toggle si click en checkbox
          if (t.closest('input[type="checkbox"]')) return;
          toggleCategoria(header);
        }
      });

      // Sync inicial
      syncCheckboxesFromState(categoriasList);
      updateCategoryCheckboxes();
    }

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
    preloadSelectedProductsFromServer();
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

(() => {
  "use strict";

  const API_BASE = "api/index.php";

  function getCsrfFromPage() {
    const page = document.getElementById("promos-page");
    return (
      page?.dataset?.csrf ||
      (window.getCsrfToken && window.getCsrfToken()) ||
      document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ||
      ""
    );
  }

  function notify(message, type = "info") {
    const text = String(message || "").trim();
    if (!text) return;

    if (window.Notif) {
      if ((type === "success" || type === "ok") && typeof window.Notif.exito === "function") {
        window.Notif.exito(text);
        return;
      }
      if ((type === "warning" || type === "warn") && typeof window.Notif.advertencia === "function") {
        window.Notif.advertencia(text);
        return;
      }
      if (type === "error" && typeof window.Notif.error === "function") {
        window.Notif.error(text);
        return;
      }
      if (typeof window.Notif.info === "function") {
        window.Notif.info(text);
        return;
      }
    }

    if (typeof window.showToast === "function") {
      window.showToast(text, type);
      return;
    }

    const toast = document.getElementById("promoToast");
    if (!toast) return;

    toast.textContent = text;
    toast.className = `promo-toast show promo-toast--${type}`;
    setTimeout(() => toast.classList.remove("show"), 2800);
  }

  async function fetchJsonGet(url) {
    const res = await fetch(url, {
      method: "GET",
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json" },
    });

    const text = await res.text();
    let data = {};
    try {
      data = text ? JSON.parse(text) : {};
    } catch {
      console.error("Respuesta no JSON:", text);
      throw new Error("La API devolvio un formato invalido.");
    }

    if (!res.ok) throw new Error(data?.error || `Error HTTP ${res.status}`);
    return data;
  }

  function debounce(fn, wait = 250) {
    let t = null;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), wait);
    };
  }

  function escapeHtml(text) {
    return String(text ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function escapeRegExp(text) {
    return String(text ?? "").replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  }

  function highlightMatch(label, query) {
    if (!query) return escapeHtml(label);
    const re = new RegExp(`(${escapeRegExp(query)})`, "ig");
    return escapeHtml(label).replace(re, "<mark>$1</mark>");
  }

  document.addEventListener("DOMContentLoaded", () => {
    const page = document.getElementById("promos-page");
    if (!page) return;

    const filtroTexto = document.getElementById("filtroTexto");
    const filtroTipo = document.getElementById("filtroTipo");
    const filtroEstado = document.getElementById("filtroEstado");

    function aplicarFiltros() {
      const q = (filtroTexto?.value || "").trim().toLowerCase();
      const tipo = (filtroTipo?.value || "").trim();
      const estado = (filtroEstado?.value || "").trim();

      document.querySelectorAll("tr.promo-row").forEach((tr) => {
        const rowTipo = tr.dataset.tipo || "";
        const rowEstado = tr.dataset.estado || "";

        const okTipo = !tipo || rowTipo === tipo;
        const okEstado = !estado || rowEstado === estado;
        const okText = !q || (tr.textContent || "").toLowerCase().includes(q);

        tr.style.display = okTipo && okEstado && okText ? "" : "none";
      });
    }

    filtroTexto?.addEventListener("input", debounce(aplicarFiltros, 200));
    filtroTipo?.addEventListener("change", aplicarFiltros);
    filtroEstado?.addEventListener("change", aplicarFiltros);

    const overlay = document.getElementById("promoEditOverlay");
    const form = document.getElementById("promoEditForm");
    const title = document.getElementById("promoEditTitle");
    const btnClose = document.getElementById("promoCloseBtn");

    const inpNombre = document.getElementById("promoNombre");
    const selTipo = document.getElementById("promoTipo");
    const selProducto = document.getElementById("promoProducto");
    const inpN = document.getElementById("promoN");
    const inpM = document.getElementById("promoM");
    const inpPct = document.getElementById("promoPct");
    const comboPrecio = document.getElementById("comboPrecio");
    const comboItems = document.getElementById("comboItemsContainer");
    const btnAddItem = document.getElementById("btnAddComboItem");
    const boxSimples = document.getElementById("promoSimplesFields");
    const boxCombo = document.getElementById("promoComboFields");

    let currentPromoId = null;
    let productosCache = null;
    const pickerRegistry = new Set();
    let panelDirty = false;
    let initialFormSnapshot = "";
    let isHydratingPanel = false;
    let closePanelDialogOpen = false;
    let panelReturnFocusEl = null;

    function serializeFormState() {
      const tipo = selTipo?.value || "";
      const state = {
        nombre: (inpNombre?.value || "").trim(),
        tipo,
        producto_id: Number(selProducto?.value || 0) || 0,
        n: inpN?.value || "",
        m: inpM?.value || "",
        porcentaje: inpPct?.value || "",
        precio_combo: comboPrecio?.value || "",
        items: [],
      };

      if (tipo === "COMBO_FIJO") {
        state.items = Array.from(comboItems?.querySelectorAll(".combo-item-row") || []).map((row) => ({
          producto_id: Number(row.querySelector(".combo-prod")?.value || 0) || 0,
          cantidad: row.querySelector(".combo-cant")?.value || "",
        }));
      }

      return JSON.stringify(state);
    }

    function markPanelClean() {
      initialFormSnapshot = serializeFormState();
      panelDirty = false;
    }

    function syncDirtyState() {
      if (!currentPromoId || isHydratingPanel) return;
      panelDirty = serializeFormState() !== initialFormSnapshot;
    }

    function focusPanelInitialField() {
      if (!inpNombre) return;

      const focusNombre = () => {
        if (!overlay?.classList.contains("open")) return;
        panelReturnFocusEl?.blur?.();
        try {
          inpNombre.focus({ preventScroll: true });
        } catch (err) {
          inpNombre.focus();
        }
        inpNombre.select?.();
      };

      window.requestAnimationFrame(() => window.requestAnimationFrame(focusNombre));
      window.setTimeout(focusNombre, 180);
      window.setTimeout(focusNombre, 320);
    }

    function openPanel() {
      overlay?.classList.add("open");
      document.body?.classList.add("promos-panel-open");
      focusPanelInitialField();
    }

    function closeAllPickers(except = null) {
      pickerRegistry.forEach((picker) => {
        if (picker !== except) picker.close();
      });
    }

    function forceClosePanel() {
      overlay?.classList.remove("open");
      form?.reset();
      if (comboItems) comboItems.innerHTML = "";
      if (boxSimples) boxSimples.style.display = "block";
      if (boxCombo) boxCombo.style.display = "none";
      currentPromoId = null;
      panelDirty = false;
      initialFormSnapshot = "";
      isHydratingPanel = false;
      closeAllPickers();
      document.body?.classList.remove("promos-panel-open");
      const returnEl = panelReturnFocusEl;
      panelReturnFocusEl = null;
      if (returnEl && document.contains(returnEl)) {
        window.setTimeout(() => returnEl.focus?.(), 0);
      }
    }

    async function requestClosePanel(e) {
      if (e) {
        e.preventDefault?.();
        e.stopPropagation?.();
      }
      if (!overlay?.classList.contains("open")) return;
      if (window.Swal && typeof Swal.isVisible === "function" && Swal.isVisible()) return;
      if (closePanelDialogOpen) return;

      if (panelDirty) {
        closePanelDialogOpen = true;
        try {
          const ok = await Notif.confirmar(
            "Cambios sin guardar",
            "<p>Hay cambios sin guardar. Cerrar de todos modos?</p>",
            { icon: "warning", confirmText: "Cerrar igual", cancelText: "Quedarme" }
          );
          if (!ok) return;
        } finally {
          closePanelDialogOpen = false;
        }
      }

      forceClosePanel();
    }

    function toggleCampos(tipo) {
      const isCombo = tipo === "COMBO_FIJO";
      if (boxSimples) boxSimples.style.display = isCombo ? "none" : "block";
      if (boxCombo) boxCombo.style.display = isCombo ? "block" : "none";
    }

    document.addEventListener("keydown", (e) => {
      if (e.key !== "Escape") return;
      if (overlay?.classList.contains("open")) requestClosePanel(e);
      if (modalEliminar?.classList.contains("show")) {
        modalEliminar.classList.remove("show");
        promoAEliminar = null;
      }
    });

    btnClose?.addEventListener("click", (e) => requestClosePanel(e));
    overlay?.addEventListener("click", (e) => {
      if (e.target === overlay) requestClosePanel(e);
    });

    window.addEventListener("beforeunload", (e) => {
      if (!overlay?.classList.contains("open") || !panelDirty) return;
      e.preventDefault();
      e.returnValue = "";
    });

    document.addEventListener("click", (e) => {
      if (!e.target.closest(".promo-search-picker")) {
        closeAllPickers();
      }
    });

    async function getProductos() {
      if (productosCache) return productosCache;
      const data = await fetchJsonGet(`${API_BASE}?action=promo_productos`);
      productosCache = data.productos || [];
      return productosCache;
    }

    function productLabel(producto) {
      return `[${producto.codigo}] ${producto.nombre}`;
    }

    async function cargarProductosSelect(selectEl, productoId = null) {
      if (!selectEl) return;
      const productos = await getProductos();

      selectEl.innerHTML = "";
      productos.forEach((producto) => {
        const opt = document.createElement("option");
        opt.value = producto.id;
        opt.textContent = productLabel(producto);
        if (productoId && Number(productoId) === Number(producto.id)) {
          opt.selected = true;
        }
        selectEl.appendChild(opt);
      });
    }

    function enhanceProductPicker(selectEl, { placeholder = "Buscar por codigo o nombre..." } = {}) {
      if (!selectEl) return null;
      if (selectEl._promoPicker) return selectEl._promoPicker;

      const wrapper = document.createElement("div");
      wrapper.className = "promo-search-picker";

      const input = document.createElement("input");
      input.type = "text";
      input.className = "input promo-search-input";
      input.placeholder = placeholder;
      input.autocomplete = "off";

      const dropdown = document.createElement("div");
      dropdown.className = "promo-search-suggestions";

      selectEl.parentNode.insertBefore(wrapper, selectEl);
      wrapper.appendChild(input);
      wrapper.appendChild(dropdown);
      wrapper.appendChild(selectEl);

      selectEl.classList.add("promo-search-source");
      selectEl.required = false;

      let filtered = [];
      let activeIndex = -1;

      function close() {
        dropdown.classList.remove("is-open");
        dropdown.innerHTML = "";
        activeIndex = -1;
      }

      async function sync() {
        const productos = await getProductos();
        const selected = productos.find((producto) => Number(producto.id) === Number(selectEl.value || 0));
        input.value = selected ? productLabel(selected) : "";
        input.dataset.selectedValue = selected ? String(selected.id) : "";
      }

      function choose(producto) {
        selectEl.value = String(producto.id);
        input.value = productLabel(producto);
        input.dataset.selectedValue = String(producto.id);
        input.dataset.selectedLabel = input.value;
        close();
        syncDirtyState();
      }

      async function render(query, preserveIndex = false) {
        const productos = await getProductos();
        const q = String(query || "").trim().toLowerCase();
        if (q.length < 1) {
          close();
          return;
        }

        filtered = productos
          .filter((producto) => (`${producto.codigo} ${producto.nombre}`).toLowerCase().includes(q))
          .slice(0, 8);

        if (!filtered.length) {
          activeIndex = -1;
        } else if (!preserveIndex) {
          activeIndex = 0;
        } else {
          activeIndex = Math.min(Math.max(activeIndex, 0), filtered.length - 1);
        }

        if (!filtered.length) {
          dropdown.innerHTML = '<div class="promo-search-item promo-search-item--empty">Sin resultados</div>';
          dropdown.classList.add("is-open");
          return;
        }

        dropdown.innerHTML = filtered
          .map((producto, index) => `
            <button type="button" class="promo-search-item ${index === activeIndex ? "is-active" : ""}" data-id="${escapeHtml(producto.id)}">
              <span class="promo-search-label">${highlightMatch(productLabel(producto), q)}</span>
            </button>
          `)
          .join("");

        dropdown.classList.add("is-open");
      }

      input.addEventListener("input", async () => {
        if (!input.value.trim()) {
          selectEl.value = "";
          input.dataset.selectedValue = "";
          close();
          return;
        }

        if (input.value.trim() !== input.dataset.selectedLabel) {
          selectEl.value = "";
          input.dataset.selectedValue = "";
        }

        closeAllPickers(selectEl._promoPicker);
        await render(input.value);
      });

      input.addEventListener("focus", async () => {
        closeAllPickers(selectEl._promoPicker);
        if (input.value.trim()) await render(input.value);
      });

      input.addEventListener("keydown", async (e) => {
        if (!filtered.length) return;

        if (e.key === "ArrowDown") {
          e.preventDefault();
          activeIndex = Math.min(activeIndex + 1, filtered.length - 1);
          await render(input.value, true);
        } else if (e.key === "ArrowUp") {
          e.preventDefault();
          activeIndex = Math.max(activeIndex - 1, 0);
          await render(input.value, true);
        } else if (e.key === "Enter") {
          e.preventDefault();
          if (filtered[activeIndex]) choose(filtered[activeIndex]);
        } else if (e.key === "Escape") {
          close();
        }
      });

      dropdown.addEventListener("mousedown", (e) => e.preventDefault());
      dropdown.addEventListener("click", async (e) => {
        const item = e.target.closest(".promo-search-item[data-id]");
        if (!item) return;
        const productos = await getProductos();
        const producto = productos.find((candidate) => String(candidate.id) === item.dataset.id);
        if (producto) choose(producto);
      });

      const picker = {
        input,
        close,
        sync: async () => {
          await sync();
          input.dataset.selectedLabel = input.value || "";
          syncDirtyState();
        },
      };

      selectEl._promoPicker = picker;
      pickerRegistry.add(picker);
      return picker;
    }

    const promoProductPicker = enhanceProductPicker(selProducto);

    form?.addEventListener("input", () => {
      syncDirtyState();
    });

    form?.addEventListener("change", () => {
      syncDirtyState();
    });

    async function agregarItemComboUI(prodId = null, cant = 1) {
      if (!comboItems) return null;

      const row = document.createElement("div");
      row.className = "combo-item-row";
      row.innerHTML = `
        <select class="combo-prod"></select>
        <input type="number" class="combo-cant" min="0.001" step="0.001" value="${cant}">
        <button type="button" class="combo-del" aria-label="Quitar item">&times;</button>
      `;
      comboItems.appendChild(row);

      const sel = row.querySelector(".combo-prod");
      await cargarProductosSelect(sel, prodId);
      const picker = enhanceProductPicker(sel, { placeholder: "Buscar producto..." });
      await picker?.sync();

      row.querySelector(".combo-del")?.addEventListener("click", () => {
        row.remove();
        syncDirtyState();
      });
      syncDirtyState();
      return row;
    }

    btnAddItem?.addEventListener("click", () => {
      agregarItemComboUI();
    });

    async function cargarPromo(id) {
      try {
        const data = await fetchJsonGet(`${API_BASE}?action=promo_obtener&id=${encodeURIComponent(id)}`);
        if (!data.ok) return notify(data.error || "No se pudo cargar la promocion.", "error");

        const promo = data.promo;
        currentPromoId = promo.id;
        isHydratingPanel = true;

        if (title) title.textContent = `Editar promocion #${promo.id}`;
        if (inpNombre) inpNombre.value = promo.nombre || "";
        if (selTipo) selTipo.value = promo.tipo || "";

        toggleCampos(promo.tipo);

        if (promo.tipo !== "COMBO_FIJO") {
          await cargarProductosSelect(selProducto, promo.producto_id);
          await promoProductPicker?.sync();
          if (inpN) inpN.value = promo.n ?? "";
          if (inpM) inpM.value = promo.m ?? "";
          if (inpPct) inpPct.value = promo.porcentaje ?? "";
        } else {
          if (comboPrecio) comboPrecio.value = promo.precio_combo ?? "";
          if (comboItems) comboItems.innerHTML = "";
          for (const item of promo.items || []) {
            await agregarItemComboUI(item.producto_id, item.cantidad);
          }
          if ((promo.items || []).length === 0) {
            await agregarItemComboUI();
          }
        }

        isHydratingPanel = false;
        markPanelClean();
        openPanel();
      } catch (err) {
        isHydratingPanel = false;
        console.error(err);
        notify(err.message || "Error al cargar la promocion.", "error");
      }
    }

    function validarPayload(payload) {
      if (!payload.nombre || payload.nombre.trim().length < 2) return "El nombre es obligatorio (minimo 2 caracteres).";

      if (payload.tipo === "N_PAGA_M") {
        if (!payload.producto_id) return "Selecciona un producto.";
        if (!payload.n || payload.n < 2) return "En NxM, N debe ser >= 2.";
        if (!payload.m || payload.m < 1) return "En NxM, M debe ser >= 1.";
        if (payload.m >= payload.n) return "En NxM, M debe ser menor que N (ej: 3x2).";
      }

      if (payload.tipo === "NTH_PCT") {
        if (!payload.producto_id) return "Selecciona un producto.";
        if (!payload.n || payload.n < 2) return 'En "% a la N", N debe ser >= 2.';
        if (payload.porcentaje == null || Number.isNaN(payload.porcentaje)) return "Ingresa el porcentaje.";
        if (payload.porcentaje <= 0 || payload.porcentaje > 100) return "El porcentaje debe estar entre 1 y 100.";
      }

      if (payload.tipo === "COMBO_FIJO") {
        if (!payload.precio_combo || payload.precio_combo <= 0) return "El precio del combo debe ser mayor a 0.";
        if (!Array.isArray(payload.items) || payload.items.length === 0) return "El combo debe tener al menos 1 producto.";
        for (const item of payload.items) {
          if (!item.producto_id) return "Hay un item sin producto.";
          if (!item.cantidad || item.cantidad <= 0) return "Hay un item con cantidad invalida.";
        }
      }

      return null;
    }

    form?.addEventListener("submit", async (e) => {
      e.preventDefault();
      if (!currentPromoId) return;

      const tipo = selTipo?.value || "";
      const payload = { id: currentPromoId, nombre: (inpNombre?.value || "").trim(), tipo };

      if (tipo === "N_PAGA_M") {
        payload.producto_id = Number(selProducto?.value || 0) || null;
        payload.n = Number(inpN?.value || 0) || null;
        payload.m = Number(inpM?.value || 0) || null;
      }

      if (tipo === "NTH_PCT") {
        payload.producto_id = Number(selProducto?.value || 0) || null;
        payload.n = Number(inpN?.value || 0) || null;
        payload.porcentaje = inpPct?.value !== "" ? Number(inpPct.value) : null;
      }

      if (tipo === "COMBO_FIJO") {
        payload.precio_combo = Number(comboPrecio?.value || 0) || 0;
        payload.items = [];
        comboItems?.querySelectorAll(".combo-item-row").forEach((row) => {
          const prod = Number(row.querySelector(".combo-prod")?.value || 0) || 0;
          const cant = Number(row.querySelector(".combo-cant")?.value || 0) || 0;
          if (prod && cant > 0) payload.items.push({ producto_id: prod, cantidad: cant });
        });
      }

      const errMsg = validarPayload(payload);
      if (errMsg) return notify(errMsg, "error");

      const csrf = getCsrfFromPage();
      if (!csrf) return notify("Falta CSRF. Recarga y prueba de nuevo.", "error");
      if (!window.apiJson) return notify("Falta apiJson (app.js).", "error");

      try {
        await window.apiJson(`${API_BASE}?action=promo_actualizar`, payload, { method: "POST" });
        markPanelClean();
        notify("Promocion actualizada correctamente.", "success");
        setTimeout(() => window.location.reload(), 500);
      } catch (err) {
        console.error(err);
        notify(err.message || "Error al guardar la promocion.", "error");
      }
    });

    document.addEventListener("click", async (e) => {
      const btn = e.target.closest(".btn-edit-promo");
      if (!btn) return;
      const id = btn.dataset.id;
      if (!id) return;

      if (overlay?.classList.contains("open") && panelDirty && Number(id) !== Number(currentPromoId)) {
        const ok = await Notif.confirmar(
          "Cambios sin guardar",
          "<p>Hay cambios sin guardar en la promo actual. Abrir otra igual?</p>",
          { icon: "warning", confirmText: "Abrir igual", cancelText: "Quedarme" }
        );
        if (!ok) return;
      }

      panelReturnFocusEl = btn;
      cargarPromo(id);
    });

    const modalEliminar = document.getElementById("modalEliminarPromo");
    const btnCancelarDel = document.getElementById("btnCancelarEliminarPromo");
    const btnConfirmDel = document.getElementById("btnConfirmarEliminarPromo");
    const delPromoName = document.getElementById("delPromoName");

    let promoAEliminar = null;

    document.addEventListener("click", (e) => {
      const btn = e.target.closest(".js-delete-promo");
      if (!btn) return;

      const id = btn.dataset.id;
      const row = document.querySelector(`tr.promo-row[data-id="${id}"]`);
      const nombre = row?.querySelector(".promo-name")?.textContent?.trim() || "";

      promoAEliminar = { id, nombre };
      if (delPromoName) delPromoName.textContent = nombre;
      modalEliminar?.classList.add("show");
    });

    btnCancelarDel?.addEventListener("click", () => {
      modalEliminar?.classList.remove("show");
      promoAEliminar = null;
    });

    modalEliminar?.addEventListener("click", (e) => {
      if (e.target === modalEliminar) {
        modalEliminar.classList.remove("show");
        promoAEliminar = null;
      }
    });

    btnConfirmDel?.addEventListener("click", async () => {
      if (!promoAEliminar?.id) return;

      const csrf = getCsrfFromPage();
      if (!csrf) return notify("Falta CSRF. Recarga y prueba de nuevo.", "error");
      if (!window.apiJson) return notify("Falta apiJson (app.js).", "error");

      const id = promoAEliminar.id;

      try {
        await window.apiJson(`${API_BASE}?action=promo_eliminar&id=${encodeURIComponent(id)}`, {}, { method: "POST" });
        const row = document.querySelector(`tr.promo-row[data-id="${id}"]`);
        if (row) {
          row.classList.add("fade-out");
          setTimeout(() => row.remove(), 250);
        }
        notify("Promocion eliminada correctamente.", "success");
      } catch (err) {
        console.error(err);
        notify(err.message || "Error al eliminar la promocion.", "error");
      }

      modalEliminar?.classList.remove("show");
      promoAEliminar = null;
    });
  });
})();














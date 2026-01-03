// public/assets/js/promos.js v3
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
    const toast = document.getElementById("promoToast");
    if (!toast) return alert(message);

    toast.textContent = message;
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
      throw new Error("La API devolvió un formato inválido.");
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

  document.addEventListener("DOMContentLoaded", () => {
    const page = document.getElementById("promos-page");
    if (!page) return;

    // ===================== FILTROS =====================
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

        let okText = true;
        if (q) okText = (tr.textContent || "").toLowerCase().includes(q);

        tr.style.display = okTipo && okEstado && okText ? "" : "none";
      });
    }

    filtroTexto?.addEventListener("input", debounce(aplicarFiltros, 200));
    filtroTipo?.addEventListener("change", aplicarFiltros);
    filtroEstado?.addEventListener("change", aplicarFiltros);

    // ===================== PANEL EDITAR =====================
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

    function openPanel() {
      overlay?.classList.add("open");
    }
    function closePanel() {
      overlay?.classList.remove("open");
      form?.reset();
      if (comboItems) comboItems.innerHTML = "";
      if (boxSimples) boxSimples.style.display = "block";
      if (boxCombo) boxCombo.style.display = "none";
      currentPromoId = null;
    }
    function toggleCampos(tipo) {
      const isCombo = tipo === "COMBO_FIJO";
      if (boxSimples) boxSimples.style.display = isCombo ? "none" : "block";
      if (boxCombo) boxCombo.style.display = isCombo ? "block" : "none";
    }

    document.addEventListener("keydown", (e) => {
      if (e.key !== "Escape") return;
      if (overlay?.classList.contains("open")) closePanel();
      if (modalEliminar?.classList.contains("show")) {
        modalEliminar.classList.remove("show");
        promoAEliminar = null;
      }
    });

    btnClose?.addEventListener("click", closePanel);
    overlay?.addEventListener("click", (e) => {
      if (e.target === overlay) closePanel();
    });

    // ===================== PRODUCTOS (cache) =====================
    let productosCache = null;

    async function getProductos() {
      if (productosCache) return productosCache;
      const data = await fetchJsonGet(`${API_BASE}?action=productos`);
      productosCache = data.productos || [];
      return productosCache;
    }

    async function cargarProductosSelect(selectEl, productoId = null) {
      if (!selectEl) return;
      const productos = await getProductos();

      selectEl.innerHTML = "";
      productos.forEach((p) => {
        const opt = document.createElement("option");
        opt.value = p.id;
        opt.textContent = `[${p.codigo}] ${p.nombre}`;
        opt.dataset.precio = p.precio || 0;
        if (productoId && Number(productoId) === Number(p.id)) opt.selected = true;
        selectEl.appendChild(opt);
      });
    }

    function agregarItemComboUI(prodId = null, cant = 1) {
      if (!comboItems) return null;

      const row = document.createElement("div");
      row.className = "combo-item-row";
      row.innerHTML = `
        <select class="combo-prod"></select>
        <input type="number" class="combo-cant" min="0.001" step="0.001" value="${cant}">
        <button type="button" class="combo-del" aria-label="Quitar item">×</button>
      `;
      comboItems.appendChild(row);

      const sel = row.querySelector(".combo-prod");
      cargarProductosSelect(sel, prodId);

      row.querySelector(".combo-del")?.addEventListener("click", () => row.remove());
      return row;
    }

    btnAddItem?.addEventListener("click", () => agregarItemComboUI());

    // ===================== CARGAR PROMO =====================
    async function cargarPromo(id) {
      try {
        const data = await fetchJsonGet(`${API_BASE}?action=obtener&id=${encodeURIComponent(id)}`);
        if (!data.ok) return notify(data.error || "No se pudo cargar la promoción.", "error");

        const p = data.promo;
        currentPromoId = p.id;

        if (title) title.textContent = `Editar promoción #${p.id}`;
        if (inpNombre) inpNombre.value = p.nombre || "";
        if (selTipo) selTipo.value = p.tipo || "";

        toggleCampos(p.tipo);

        if (p.tipo !== "COMBO_FIJO") {
          await cargarProductosSelect(selProducto, p.producto_id);
          if (inpN) inpN.value = p.n ?? "";
          if (inpM) inpM.value = p.m ?? "";
          if (inpPct) inpPct.value = p.porcentaje ?? "";
        } else {
          if (comboPrecio) comboPrecio.value = p.precio_combo ?? "";
          if (comboItems) comboItems.innerHTML = "";
          (p.items || []).forEach((it) => agregarItemComboUI(it.producto_id, it.cantidad));
          if ((p.items || []).length === 0) agregarItemComboUI();
        }

        openPanel();
      } catch (err) {
        console.error(err);
        notify(err.message || "Error al cargar la promoción.", "error");
      }
    }

    // ===================== VALIDAR + GUARDAR =====================
    function validarPayload(payload) {
      if (!payload.nombre || payload.nombre.trim().length < 2) return "El nombre es obligatorio (mínimo 2 caracteres).";

      if (payload.tipo === "N_PAGA_M") {
        if (!payload.producto_id) return "Seleccioná un producto.";
        if (!payload.n || payload.n < 2) return "En NxM, N debe ser >= 2.";
        if (!payload.m || payload.m < 1) return "En NxM, M debe ser >= 1.";
        if (payload.m >= payload.n) return "En NxM, M debe ser menor que N (ej: 3x2).";
      }

      if (payload.tipo === "NTH_PCT") {
        if (!payload.producto_id) return "Seleccioná un producto.";
        if (!payload.n || payload.n < 2) return 'En "% a la N°", N debe ser >= 2.';
        if (payload.porcentaje == null || Number.isNaN(payload.porcentaje)) return "Ingresá el porcentaje.";
        if (payload.porcentaje <= 0 || payload.porcentaje > 100) return "El porcentaje debe estar entre 1 y 100.";
      }

      if (payload.tipo === "COMBO_FIJO") {
        if (!payload.precio_combo || payload.precio_combo <= 0) return "El precio del combo debe ser mayor a 0.";
        if (!Array.isArray(payload.items) || payload.items.length === 0) return "El combo debe tener al menos 1 producto.";
        for (const it of payload.items) {
          if (!it.producto_id) return "Hay un item sin producto.";
          if (!it.cantidad || it.cantidad <= 0) return "Hay un item con cantidad inválida.";
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
      if (!csrf) return notify("Falta CSRF. Recargá y probá de nuevo.", "error");
      if (!window.apiJson) return notify("Falta apiJson (app.js). Cargá app.js antes.", "error");

      try {
        await window.apiJson(`${API_BASE}?action=actualizar`, payload, { method: "POST" });
        notify("Promoción actualizada correctamente.", "success");
        setTimeout(() => window.location.reload(), 500);
      } catch (err) {
        console.error(err);
        notify(err.message || "Error al guardar la promoción.", "error");
      }
    });

    // Delegación editar
    document.addEventListener("click", (e) => {
      const btn = e.target.closest(".btn-edit-promo");
      if (!btn) return;
      const id = btn.dataset.id;
      if (id) cargarPromo(id);
    });

    // ===================== ELIMINAR (modal seguro) =====================
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
      if (!csrf) return notify("Falta CSRF. Recargá y probá de nuevo.", "error");
      if (!window.apiJson) return notify("Falta apiJson (app.js).", "error");

      const id = promoAEliminar.id;

      try {
        await window.apiJson(`${API_BASE}?action=eliminar&id=${encodeURIComponent(id)}`, {}, { method: "POST" });
        const row = document.querySelector(`tr.promo-row[data-id="${id}"]`);
        if (row) {
          row.classList.add("fade-out");
          setTimeout(() => row.remove(), 250);
        }
        notify("Promoción eliminada correctamente.", "success");
      } catch (err) {
        console.error(err);
        notify(err.message || "Error al eliminar la promoción.", "error");
      }

      modalEliminar?.classList.remove("show");
      promoAEliminar = null;
    });
  });
})();

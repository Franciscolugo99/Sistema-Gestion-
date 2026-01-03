// public/assets/js/promos.js v2.0
// Sistema de gestión de promociones
(() => {
  "use strict";

  // =============================================================================
  // CONFIGURACIÓN
  // =============================================================================
  const API_BASE = "api/index.php";

  // =============================================================================
  // UTILIDADES
  // =============================================================================

  /**
   * Obtener token CSRF para protección contra ataques
   */
  function getCsrf() {
    return (
      (window.getCsrfToken && window.getCsrfToken()) ||
      document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ||
      ""
    );
  }

  /**
   * Mostrar notificación toast al usuario
   * @param {string} message - Mensaje a mostrar
   * @param {string} type - Tipo: 'info', 'success', 'error', 'warning'
   */
  function notify(message, type = "info") {
    const toast = document.getElementById("promoToast");
    if (!toast) {
      alert(message);
      return;
    }
    
    toast.textContent = message;
    toast.className = `promo-toast show promo-toast--${type}`;
    
    setTimeout(() => {
      toast.classList.remove("show");
    }, 3000);
  }

  /**
   * Realizar petición GET y parsear JSON
   * @param {string} url - URL del endpoint
   * @returns {Promise<Object>} Datos parseados
   */
  async function fetchJsonGet(url) {
    const res = await fetch(url, {
      method: "GET",
      credentials: "same-origin",
      cache: "no-store",
      headers: { Accept: "application/json" },
    });

    const text = await res.text();
    let data;
    
    try {
      data = text ? JSON.parse(text) : {};
    } catch (e) {
      console.error("Respuesta no JSON:", text);
      throw new Error("La API devolvió un formato inválido.");
    }

    if (!res.ok) {
      const msg = data?.error || `Error HTTP ${res.status}`;
      throw new Error(msg);
    }
    
    return data;
  }

  /**
   * Debounce para optimizar eventos de input
   * @param {Function} fn - Función a ejecutar
   * @param {number} wait - Milisegundos de espera
   * @returns {Function} Función debounced
   */
  function debounce(fn, wait = 250) {
    let timeout = null;
    return (...args) => {
      clearTimeout(timeout);
      timeout = setTimeout(() => fn(...args), wait);
    };
  }

  // =============================================================================
  // INICIALIZACIÓN
  // =============================================================================
  document.addEventListener("DOMContentLoaded", () => {
    const page = document.getElementById("promos-page");
    if (!page) return;

    // -------------------------------------------------------------------------
    // SISTEMA DE FILTROS
    // -------------------------------------------------------------------------
    const filtroTexto = document.getElementById("filtroTexto");
    const filtroTipo = document.getElementById("filtroTipo");
    const filtroEstado = document.getElementById("filtroEstado");

    /**
     * Aplicar filtros de búsqueda a la tabla
     */
    function aplicarFiltros() {
      const q = (filtroTexto?.value || "").trim().toLowerCase();
      const tipo = (filtroTipo?.value || "").trim();
      const estado = (filtroEstado?.value || "").trim();

      const rows = document.querySelectorAll("tr.promo-row");
      
      rows.forEach((tr) => {
        const rowTipo = tr.dataset.tipo || "";
        const rowEstado = tr.dataset.estado || "";
        
        const hayTipo = !tipo || rowTipo === tipo;
        const hayEstado = !estado || rowEstado === estado;

        let hayTexto = true;
        if (q) {
          const txt = (tr.textContent || "").toLowerCase();
          hayTexto = txt.includes(q);
        }

        tr.style.display = (hayTipo && hayEstado && hayTexto) ? "" : "none";
      });
    }

    const aplicarFiltrosDebounced = debounce(aplicarFiltros, 250);
    filtroTexto?.addEventListener("input", aplicarFiltrosDebounced);
    filtroTipo?.addEventListener("change", aplicarFiltros);
    filtroEstado?.addEventListener("change", aplicarFiltros);

    // -------------------------------------------------------------------------
    // ELEMENTOS DEL DOM - PANEL DE EDICIÓN
    // -------------------------------------------------------------------------
    const overlay = document.getElementById("promoEditOverlay");
    const form = document.getElementById("promoEditForm");
    const title = document.getElementById("promoEditTitle");
    const btnClose = document.getElementById("promoCloseBtn");

    // Campos del formulario
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

    // Modal de eliminación
    const modalEliminar = document.getElementById("modalEliminarPromo");
    const btnCancelarDel = document.getElementById("btnCancelarEliminarPromo");
    const btnConfirmDel = document.getElementById("btnConfirmarEliminarPromo");

    // Estado
    let currentPromoId = null;
    let promoAEliminar = null;

    // -------------------------------------------------------------------------
    // FUNCIONES DE UI
    // -------------------------------------------------------------------------

    /**
     * Abrir panel de edición
     */
    function openPanel() {
      overlay?.classList.add("open");
    }

    /**
     * Cerrar panel de edición y resetear formulario
     */
    function closePanel() {
      overlay?.classList.remove("open");
      form?.reset();
      if (comboItems) comboItems.innerHTML = "";
      if (boxSimples) boxSimples.style.display = "block";
      if (boxCombo) boxCombo.style.display = "none";
      currentPromoId = null;
    }

    /**
     * Mostrar/ocultar campos según tipo de promoción
     * @param {string} tipo - Tipo de promoción
     */
    function toggleCampos(tipo) {
      const isCombo = tipo === "COMBO_FIJO";
      if (boxSimples) boxSimples.style.display = isCombo ? "none" : "block";
      if (boxCombo) boxCombo.style.display = isCombo ? "block" : "none";
    }

    // -------------------------------------------------------------------------
    // GESTIÓN DE TECLADO
    // -------------------------------------------------------------------------

    /**
     * Cerrar modales con tecla ESC
     */
    document.addEventListener("keydown", (e) => {
      if (e.key !== "Escape") return;

      if (overlay?.classList.contains("open")) {
        closePanel();
      }
      
      if (modalEliminar?.classList.contains("show")) {
        modalEliminar.classList.remove("show");
        promoAEliminar = null;
      }
    });

    // -------------------------------------------------------------------------
    // GESTIÓN DE PRODUCTOS
    // -------------------------------------------------------------------------

    /**
     * Cargar productos en un select
     * @param {HTMLSelectElement} selectEl - Elemento select a poblar
     * @param {number|null} productoId - ID del producto a preseleccionar
     */
    async function cargarProductosSelect(selectEl, productoId = null) {
      if (!selectEl) return;

      const data = await fetchJsonGet(`${API_BASE}?action=productos`);
      const productos = data.productos || [];

      selectEl.innerHTML = "";
      
      productos.forEach((p) => {
        const opt = document.createElement("option");
        opt.value = p.id;
        opt.textContent = `[${p.codigo}] ${p.nombre}`;
        opt.dataset.precio = p.precio || 0;
        
        if (productoId && Number(productoId) === Number(p.id)) {
          opt.selected = true;
        }
        
        selectEl.appendChild(opt);
      });
    }

    // -------------------------------------------------------------------------
    // GESTIÓN DE COMBOS
    // -------------------------------------------------------------------------

    /**
     * Agregar fila de item de combo en el UI
     * @param {number|null} prodId - ID del producto
     * @param {number} cant - Cantidad del producto
     * @returns {HTMLElement|null} Elemento creado
     */
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

      row.querySelector(".combo-del").addEventListener("click", () => row.remove());
      
      return row;
    }

    btnAddItem?.addEventListener("click", () => agregarItemComboUI());

    // -------------------------------------------------------------------------
    // CARGAR PROMOCIÓN PARA EDITAR
    // -------------------------------------------------------------------------

    /**
     * Cargar datos de una promoción en el formulario
     * @param {number} id - ID de la promoción
     */
    async function cargarPromo(id) {
      try {
        const data = await fetchJsonGet(
          `${API_BASE}?action=obtener&id=${encodeURIComponent(id)}`
        );

        if (!data.ok) {
          notify(data.error || "No se pudo cargar la promoción.", "error");
          return;
        }

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

          (p.items || []).forEach((it) => 
            agregarItemComboUI(it.producto_id, it.cantidad)
          );
          
          if ((p.items || []).length === 0) {
            agregarItemComboUI();
          }
        }

        openPanel();
      } catch (err) {
        console.error(err);
        notify(err.message || "Error al cargar la promoción.", "error");
      }
    }

    // -------------------------------------------------------------------------
    // VALIDACIONES
    // -------------------------------------------------------------------------

    /**
     * Validar payload antes de enviar
     * @param {Object} payload - Datos a validar
     * @returns {string|null} Mensaje de error o null si es válido
     */
    function validarPayload(payload) {
      if (!payload.nombre || payload.nombre.trim().length < 2) {
        return "El nombre es obligatorio (mínimo 2 caracteres).";
      }

      if (payload.tipo === "N_PAGA_M") {
        if (!payload.producto_id) return "Seleccioná un producto.";
        if (!payload.n || payload.n < 2) return "En NxM, N debe ser >= 2.";
        if (!payload.m || payload.m < 1) return "En NxM, M debe ser >= 1.";
        if (payload.m >= payload.n) return "En NxM, M debe ser menor que N (ej: 3x2).";
      }

      if (payload.tipo === "NTH_PCT") {
        if (!payload.producto_id) return "Seleccioná un producto.";
        if (!payload.n || payload.n < 2) return 'En "% a la N°", N debe ser >= 2.';
        if (payload.porcentaje == null || Number.isNaN(payload.porcentaje)) {
          return "Ingresá el porcentaje.";
        }
        if (payload.porcentaje <= 0 || payload.porcentaje > 100) {
          return "El porcentaje debe estar entre 1 y 100.";
        }
      }

      if (payload.tipo === "COMBO_FIJO") {
        if (!payload.precio_combo || payload.precio_combo <= 0) {
          return "El precio del combo debe ser mayor a 0.";
        }
        if (!Array.isArray(payload.items) || payload.items.length === 0) {
          return "El combo debe tener al menos 1 producto.";
        }
        for (const it of payload.items) {
          if (!it.producto_id) return "Hay un item sin producto.";
          if (!it.cantidad || it.cantidad <= 0) return "Hay un item con cantidad inválida.";
        }
      }

      return null;
    }

    // -------------------------------------------------------------------------
    // GUARDAR PROMOCIÓN
    // -------------------------------------------------------------------------

    form?.addEventListener("submit", async (e) => {
      e.preventDefault();
      if (!currentPromoId) return;

      const tipo = selTipo?.value || "";

      const payload = {
        id: currentPromoId,
        nombre: (inpNombre?.value || "").trim(),
        tipo,
      };

      // Agregar campos según tipo de promoción
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
          if (prod && cant > 0) {
            payload.items.push({ producto_id: prod, cantidad: cant });
          }
        });
      }

      // Validar antes de enviar
      const errMsg = validarPayload(payload);
      if (errMsg) {
        notify(errMsg, "error");
        return;
      }

      const csrf = getCsrf();
      if (!csrf) {
        notify("Falta CSRF. Recargá y probá de nuevo.", "error");
        return;
      }

      if (!window.apiJson) {
        notify("Falta apiJson (app.js). Asegurate de cargar app.js antes.", "error");
        return;
      }

      try {
        await window.apiJson(`${API_BASE}?action=actualizar`, payload, {
          method: "POST",
        });

        notify("Promoción actualizada correctamente.", "success");
        setTimeout(() => window.location.reload(), 600);
      } catch (err) {
        console.error(err);
        notify(err.message || "Error al guardar la promoción.", "error");
      }
    });

    // -------------------------------------------------------------------------
    // EDITAR PROMOCIÓN (delegación de eventos)
    // -------------------------------------------------------------------------

    document.addEventListener("click", (e) => {
      const btn = e.target.closest(".btn-edit-promo");
      if (!btn) return;

      const id = btn.dataset.id;
      if (id) cargarPromo(id);
    });

    // -------------------------------------------------------------------------
    // DUPLICAR PROMOCIÓN
    // -------------------------------------------------------------------------

    document.addEventListener("click", async (e) => {
      const btn = e.target.closest(".btn-duplicate-promo");
      if (!btn) return;

      const id = btn.dataset.id;

      if (!confirm("¿Duplicar esta promoción?")) return;

      try {
        const data = await fetchJsonGet(`${API_BASE}?action=obtener&id=${id}`);

        if (!data.ok) {
          notify(data.error || "No se pudo cargar la promoción.", "error");
          return;
        }

        const promo = data.promo;

        // Preparar para duplicar
        delete promo.id;
        promo.nombre = promo.nombre + " (copia)";
        promo.activo = 0;

        await window.apiJson(`${API_BASE}?action=crear`, promo, { method: "POST" });

        notify("Promoción duplicada correctamente.", "success");
        setTimeout(() => window.location.reload(), 600);
      } catch (err) {
        console.error(err);
        notify(err.message || "Error al duplicar la promoción.", "error");
      }
    });

    // -------------------------------------------------------------------------
    // CERRAR PANEL DE EDICIÓN
    // -------------------------------------------------------------------------

    btnClose?.addEventListener("click", closePanel);

    overlay?.addEventListener("click", (e) => {
      if (e.target === overlay) closePanel();
    });

    // -------------------------------------------------------------------------
    // ELIMINAR PROMOCIÓN
    // -------------------------------------------------------------------------

    document.addEventListener("click", (e) => {
      const btn = e.target.closest(".js-delete-promo");
      if (!btn) return;

      promoAEliminar = {
        id: btn.dataset.id,
        nombre: btn.dataset.nombre || "",
      };

      if (!modalEliminar) return;

      const textEl = modalEliminar.querySelector(".modal-text");
      if (textEl) {
        textEl.innerHTML =
          `¿Eliminar la promoción <strong>${promoAEliminar.nombre}</strong>?` +
          "<br><small>Esta acción no se puede deshacer.</small>";
      }

      modalEliminar.classList.add("show");
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

      const csrf = getCsrf();
      if (!csrf) {
        notify("Falta CSRF. Recargá y probá de nuevo.", "error");
        return;
      }

      if (!window.apiJson) {
        notify("Falta apiJson (app.js).", "error");
        return;
      }

      const id = promoAEliminar.id;

      try {
        await window.apiJson(
          `${API_BASE}?action=eliminar&id=${encodeURIComponent(id)}`,
          {},
          { method: "POST" }
        );

        const row = document.querySelector(`tr.promo-row[data-id="${id}"]`);
        if (row) {
          row.classList.add("fade-out");
          setTimeout(() => row.remove(), 300);
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
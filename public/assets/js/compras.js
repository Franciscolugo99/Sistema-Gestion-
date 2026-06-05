/**
 * COMPRAS.JS - Versión mejorada (segura) con edición de descuentos por ítem
 *
 * Incluye:
 * - Edición de descuentos por ítem en borradores (modal)
 * - Vista previa de subtotal (con descuento por ítem)
 * - Modo "Agregar Rápido" (toggle UI)
 * - ? Indicadores visuales (badge / celda descuento)
 * - ? Debounce en descuento global
 *
 * Fixes de seguridad/robustez:
 * - Mitigación XSS: NO se inyectan nombres/códigos sin escapar
 * - ? Modal sin listeners colgados (AbortController + closeModal centralizado)
 * - ? Indicadores sincronizados desde recalcTotal()
 */

document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("compraForm");
  const searchInput = document.getElementById("itemBuscar");
  const suggestionsBox = document.getElementById("suggestions");
  const inQty = document.getElementById("itemCantidad");
  const inCost = document.getElementById("itemCosto");
  const unitLbl = document.getElementById("itemUnidad");
  const qtyFieldContainer = document.getElementById("qtyFieldContainer");
  const btnAdd = document.getElementById("btnAddItem");
  const table = document.getElementById("itemsTable");
  const tbody = table?.querySelector("tbody");
  const totalLbl = document.getElementById("totalLbl");
  const totalBrutoLbl = document.getElementById("totalBrutoLbl");
  const descuentoTotalLbl = document.getElementById("descuentoTotalLbl");
  const descuentoItemsLbl = document.getElementById("descuentoItemsLbl");
  const descuentoTipo = document.getElementById("descuentoTipo");
  const descuentoValor = document.getElementById("descuentoValor");

  const itemDescTipo = document.getElementById("itemDescTipo");
  const itemDescValor = document.getElementById("itemDescValor");
  const btnResetCompra = document.getElementById("btnResetCompra");
  const proveedorInput = document.getElementById("proveedorInput");
  const proveedorIdInput = document.getElementById("proveedorId");
  const proveedorMatch = document.getElementById("proveedorMatch");
  const proveedoresData = document.getElementById("proveedoresData");

  if (!tbody) return;

  let productosData = [];
  let selectedProduct = null;
  let itemsAdded = [];
  let autoIdCounter = Date.now();
  let hasUnsavedChanges = false;
  let quickAddMode = false;
  let bulkQuickAddInProgress = false;
  const compraIdInput = form?.querySelector('input[name="compra_id"]');
  let autosaveTimer = null;
  let autosaveInFlight = false;
  let autosaveQueued = false;
  let autosaveLastSignature = "";
  let autosaveStatusEl = null;
  let isManualSubmit = false;

  /* ============================================================================
     CONSTANTES Y UTILS
  ============================================================================ */
  const MAX_QTY = 99999;
  const MAX_COST = 9999999;

  const round2 = (n) => Math.round((Number(n) || 0) * 100) / 100;

  const escapeRegExp = (s) => String(s).replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

  function escapeHtml(s) {
    return String(s ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function fmtMoney(n) {
    return (
      "$" +
      Number(n || 0).toLocaleString("es-AR", {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
      })
    );
  }

  function isPesable(product) {
    const unidad = String(product?.unidad || "").toUpperCase();
    return (
      Number(product?.esPesable || 0) === 1 ||
      ["KG", "G", "LT", "ML"].includes(unidad)
    );
  }

  function fmtQty(qty, product) {
    const n = Number(qty || 0);
    const pes = isPesable(product);
    return pes
      ? n.toLocaleString("es-AR", {
          minimumFractionDigits: 3,
          maximumFractionDigits: 3,
        })
      : n.toLocaleString("es-AR", {
          minimumFractionDigits: 0,
          maximumFractionDigits: 0,
        });
  }

  function actionIcon(name) {
    const icons = {
      edit:
        '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 20h4.5L19 9.5 14.5 5 4 15.5V20zm12.2-16.8 2.6 2.6c.4.4.4 1 0 1.4l-1.6 1.6-4-4 1.6-1.6c.4-.4 1-.4 1.4 0z"/></svg>',
      discount:
        '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 10V5a1 1 0 0 0-1-1h-5L4 14l6 6 10-10zM7.5 9A1.5 1.5 0 1 1 9 7.5 1.5 1.5 0 0 1 7.5 9z"/></svg>',
      delete:
        '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3h6l1 2h4v2H4V5h4l1-2zm1 6h2v8h-2V9zm4 0h2v8h-2V9zM7 9h2v8H7V9zm-1 11h12a1 1 0 0 0 1-1V7H5v12a1 1 0 0 0 1 1z"/></svg>',
    };
    return icons[name] || "";
  }

  function buildRowActionsMarkup() {
    return `
      <div class="row-actions">
        <button type="button" class="btn-icon" title="Editar cantidad y costo" aria-label="Editar cantidad y costo" data-action="edit">${actionIcon("edit")}</button>
        <button type="button" class="btn-icon btn-icon-warning" title="Editar descuento" aria-label="Editar descuento" data-action="edit-discount">${actionIcon("discount")}</button>
        <button type="button" class="btn-icon btn-icon-danger" title="Eliminar item" aria-label="Eliminar item" data-action="delete">${actionIcon("delete")}</button>
      </div>
    `;
  }
  function normalizeDiscount(tipo, valor, subtotal) {
    const t =
      String(tipo || "MONTO").toUpperCase() === "PORC" ? "PORC" : "MONTO";
    let v = Number(valor || 0);
    if (!Number.isFinite(v) || v < 0) v = 0;

    let monto = 0;
    if (subtotal > 0 && v > 0) {
      if (t === "PORC") {
        if (v > 100) v = 100;
        monto = subtotal * (v / 100);
      } else {
        if (v > subtotal) v = subtotal;
        monto = v;
      }
    }
    monto = round2(monto);
    v = round2(v);
    return { tipo: t, valor: v, monto };
  }

  /* ============================================================================
     PRODUCTOS PESABLES - UX MEJORADA
  ============================================================================ */
  function applyQtyInputRules(product) {
    const pes = isPesable(product);
    inQty.step = pes ? "0.001" : "1";
    inQty.min = pes ? "0.001" : "1";
    if (!inQty.value || Number(inQty.value) <= 0) {
      inQty.value = pes ? "1.000" : "1";
    }
  }

  function validatePesableInput(e) {
    const val = e.target.value;
    const num = parseFloat(val);

    if (val && !isNaN(num)) {
      const parts = String(val).split(".");
      if (parts[1] && parts[1].length > 3) {
        e.target.value = num.toFixed(3);
        showToast("Maximo 3 decimales para productos pesables", "info");
      }
    }
  }

  function updatePesableUI(product) {
    const isPes = isPesable(product);

    // Limpiar UI previa
    const existingBadge = qtyFieldContainer.querySelector(".badge-pesable");
    const existingHelp = qtyFieldContainer.querySelector(".help-pesable");
    if (existingBadge) existingBadge.remove();
    if (existingHelp) existingHelp.remove();

    inQty.classList.remove("input-pesable");
    inQty.removeEventListener("input", validatePesableInput);

    if (isPes) {
      // Badge visual
      const badge = document.createElement("span");
      badge.className = "badge-pesable";
      badge.textContent = `PESABLE ${String(product?.unidad || "").toUpperCase()}`;
      qtyFieldContainer.querySelector("label")?.appendChild(badge);

      // Placeholder dinámico
      const unidadLower = String(product?.unidad || "").toLowerCase();
      inQty.placeholder = `Ej: 2.500 (${unidadLower})`;
      inQty.classList.add("input-pesable");

      // Ayuda contextual (sin innerHTML para evitar XSS)
      const help = document.createElement("div");
      help.className = "help-pesable";
      const strong = document.createElement("strong");
      strong.textContent = "Producto pesable: ";
      const span = document.createElement("span");
      span.textContent = "Ingresa el peso con 3 decimales.";
      const br = document.createElement("br");
      const txt = document.createTextNode("Ejemplo: ");
      const code = document.createElement("code");
      code.textContent = "1.500";
      const txt2 = document.createTextNode(` = 1.5 ${unidadLower}`);
      help.appendChild(strong);
      help.appendChild(span);
      help.appendChild(br);
      help.appendChild(txt);
      help.appendChild(code);
      help.appendChild(txt2);
      qtyFieldContainer.appendChild(help);

      // Validación en tiempo real
      inQty.addEventListener("input", validatePesableInput);
    } else {
      inQty.placeholder = "Cantidad (unidades enteras)";
    }
  }

  /* ============================================================================
     CARGAR PRODUCTOS
  ============================================================================ */
  function loadProducts() {
    const select = document.getElementById("productosData");
    if (!select) return;

    productosData = [];
    Array.from(select.options).forEach((opt) => {
      if (!opt.value) return;

      // Limpiar nombre (quitar "(COD)" pero preservar paréntesis en nombre real)
      const rawText = opt.textContent.trim();
      const cleanName = rawText.replace(/\s*\([^)]+\)\s*$/, "").trim();

      productosData.push({
        id: parseInt(opt.value, 10),
        nombre: cleanName,
        display: rawText,
        codigo: opt.dataset.codigo || "",
        esPesable: parseInt(opt.dataset.esPesable || 0, 10),
        unidad: opt.dataset.unidad || "UNIDAD",
        ultimoCosto: parseFloat(opt.dataset.ultimoCosto || 0),
        proveedorId: parseInt(opt.dataset.proveedorId || 0, 10),
        proveedorNombre: opt.dataset.proveedorNombre || "",
      });
    });
  }
  loadProducts();

  /* ============================================================================
     AUTOCOMPLETE MEJORADO (SEGURO)
  ============================================================================ */
  function highlightMatch(text, query) {
    const str = String(text ?? "");
    const q = String(query ?? "");
    if (!q) return escapeHtml(str);

    const re = new RegExp(escapeRegExp(q), "gi");
    let out = "";
    let last = 0;

    for (const m of str.matchAll(re)) {
      const start = m.index ?? 0;
      const end = start + String(m[0]).length;
      out += escapeHtml(str.slice(last, start));
      out += "<mark>" + escapeHtml(str.slice(start, end)) + "</mark>";
      last = end;
    }
    out += escapeHtml(str.slice(last));
    return out;
  }

  function selectProduct(id) {
    const product = productosData.find((p) => p.id === id);
    if (!product) return;

    selectedProduct = product;
    searchInput.value = product.nombre;
    suggestionsBox.classList.remove("active");

    applyQtyInputRules(product);
    updatePesableUI(product);

    // Prefill cost
    if (product.ultimoCosto > 0) {
      inCost.value = round2(product.ultimoCosto).toFixed(2);
    } else {
      inCost.value = "0.00";
    }

    unitLbl.textContent = `Unidad: ${product.unidad}`;

    updateSubtotalPreview();

    inQty.focus();
    inQty.select?.();
  }

  function applySearchPrefill() {
    if (!searchInput) return;

    const preset = new URLSearchParams(window.location.search).get("product_q");
    if (!preset) return;

    searchInput.value = preset;
    searchInput.dispatchEvent(new Event("input", { bubbles: true }));
    searchInput.focus();
    searchInput.select?.();
  }

  if (searchInput && suggestionsBox) {
    let debounceTimer;
    let isSearchActive = false;

    searchInput.addEventListener("input", (e) => {
      selectedProduct = null;
      unitLbl.textContent = "Unidad: UNIDAD";
      updatePesableUI({ esPesable: 0, unidad: "UNIDAD" });
      applyQtyInputRules({ esPesable: 0, unidad: "UNIDAD" });
      updateSubtotalPreview();

      clearTimeout(debounceTimer);
      const query = e.target.value.trim().toLowerCase();

      if (query.length < 2) {
        suggestionsBox.classList.remove("active");
        suggestionsBox.innerHTML = "";
        isSearchActive = false;
        return;
      }

      isSearchActive = true;
      suggestionsBox.innerHTML =
        '<div class="suggestion-item loading-item">Buscando...</div>';
      suggestionsBox.classList.add("active");

      debounceTimer = setTimeout(() => {
        const filtered = productosData
          .filter(
            (p) =>
              p.nombre.toLowerCase().includes(query) ||
              String(p.codigo || "")
                .toLowerCase()
                .includes(query),
          )
          .slice(0, 8);

        if (filtered.length === 0) {
          suggestionsBox.innerHTML =
            '<div class="suggestion-item empty">No se encontraron productos</div>';
          suggestionsBox.classList.add("active");
          return;
        }

        suggestionsBox.innerHTML = filtered
          .map((p) => {
            const nameHtml = highlightMatch(p.nombre, query);
            const codeHtml = escapeHtml(p.codigo || "");
            const priceHtml =
              p.ultimoCosto > 0
                ? `<div class="sug-price">Ultimo: ${fmtMoney(p.ultimoCosto)}</div>`
                : "";
            return `
              <div class="suggestion-item" data-id="${p.id}">
                <div class="sug-main">
                  <strong>${nameHtml}</strong>
                  <span class="sug-code">${codeHtml}</span>
                </div>
                ${priceHtml}
              </div>
            `;
          })
          .join("");

        suggestionsBox.classList.add("active");

        suggestionsBox
          .querySelectorAll(".suggestion-item[data-id]")
          .forEach((item) => {
            item.addEventListener("click", () => {
              selectProduct(parseInt(item.dataset.id, 10));
              isSearchActive = false;
            });
          });
      }, 200);
    });

    document.addEventListener("click", (e) => {
      if (
        !searchInput.contains(e.target) &&
        !suggestionsBox.contains(e.target)
      ) {
        suggestionsBox.classList.remove("active");
        isSearchActive = false;
      }
    });

    searchInput.addEventListener("keydown", (e) => {
      const items = suggestionsBox.querySelectorAll(
        ".suggestion-item[data-id]",
      );
      if (!items.length) {
        if (e.key === "Enter" && isSearchActive) {
          e.preventDefault();
          return;
        }
        return;
      }

      const active = suggestionsBox.querySelector(
        ".suggestion-item.keyboard-active",
      );
      let index = active ? Array.from(items).indexOf(active) : -1;

      if (e.key === "ArrowDown") {
        e.preventDefault();
        index = Math.min(index + 1, items.length - 1);
        items.forEach((item, i) =>
          item.classList.toggle("keyboard-active", i === index),
        );
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        index = Math.max(index - 1, 0);
        items.forEach((item, i) =>
          item.classList.toggle("keyboard-active", i === index),
        );
      } else if (e.key === "Enter") {
        e.preventDefault();
        const target = active || items[0];
        if (target) {
          selectProduct(parseInt(target.dataset.id, 10));
          isSearchActive = false;
        }
      }
    });
  }

  /* ============================================================================
     VISTA PREVIA SUBTOTAL CON DESCUENTO (por ítem actual)
  ============================================================================ */
  function updateSubtotalPreview() {
    const existingPreview = document.getElementById("subtotalPreview");
    if (existingPreview) existingPreview.remove();

    if (!selectedProduct) return;
    const itemsGrid = document.querySelector(".items-grid");
    if (!itemsGrid) return;

    const qty = parseFloat(inQty.value || 0);
    const cost = parseFloat(inCost.value || 0);
    const dTipo = String(itemDescTipo?.value || "MONTO").toUpperCase();
    const dVal = parseFloat(itemDescValor?.value || 0);

    if (qty <= 0 || cost < 0) return;

    const subtotal = round2(qty * cost);
    const norm = normalizeDiscount(dTipo, dVal, subtotal);
    const final = round2(subtotal - norm.monto);

    const preview = document.createElement("div");
    preview.id = "subtotalPreview";
    preview.className = "subtotal-preview";

    if (norm.monto > 0) {
      preview.innerHTML = `
        <div class="preview-line">
          <span>Subtotal bruto:</span>
          <span>${fmtMoney(subtotal)}</span>
        </div>
        <div class="preview-line discount">
          <span>Descuento:</span>
          <span>-${fmtMoney(norm.monto)}</span>
        </div>
        <div class="preview-line total">
          <span>Subtotal neto:</span>
          <span>${fmtMoney(final)}</span>
        </div>
      `;
    } else {
      preview.innerHTML = `
        <div class="preview-line total">
          <span>Subtotal:</span>
          <span>${fmtMoney(subtotal)}</span>
        </div>
      `;
    }

    const previewHost = document.querySelector(".field-btn-add") || itemsGrid;
    previewHost.appendChild(preview);
  }

  [inQty, inCost, itemDescTipo, itemDescValor].forEach((el) => {
    if (!el) return;
    el.addEventListener("input", updateSubtotalPreview);
    el.addEventListener("change", updateSubtotalPreview);
  });

  /* ============================================================================
     MANEJO DE FILAS
  ============================================================================ */
  function removeEmptyRow() {
    const empty = tbody.querySelector(".empty-row");
    if (empty) empty.remove();
  }

  function addEmptyRowIfNeeded() {
    if (!tbody.querySelector("tr[data-row='item']")) {
      tbody.innerHTML = `
        <tr class="empty-row">
          <td colspan="6" class="empty-cell">
            <div class="empty-state">
              <svg class="empty-state-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path d="M3 6h3l2.4 9.5a1 1 0 0 0 1 .75H18a1 1 0 0 0 .98-.8L20 9H7"/>
                <circle cx="10" cy="20" r="1.5"/>
                <circle cx="17" cy="20" r="1.5"/>
              </svg>
              <p class="empty-state-title">Sin productos todavia</p>
              <p class="empty-state-sub">Buscá un producto arriba o usá <strong>Ver lista de productos</strong> para agregar varios a la vez.</p>
            </div>
          </td>
        </tr>
      `;
    }
  }

  function syncRowDiscountVisual(tr, hasDiscount) {
    const descCell = tr.querySelector(".desc-item-cell");
    if (descCell) descCell.classList.toggle("has-discount", !!hasDiscount);

    const badge = tr.querySelector(".item-discount-badge");
    if (badge) badge.hidden = !hasDiscount;
  }

  /* ============================================================================
     RECALCULO TOTALES (incluye sync visual)
  ============================================================================ */
  let recalcDebounceTimer;
  function recalcTotal() {
    let bruto = 0;
    let descItems = 0;

    tbody.querySelectorAll("tr[data-row='item']").forEach((tr) => {
      const subtotal = round2(Number(tr.dataset.subtotal || 0));
      bruto += subtotal;

      const hTipo = tr.querySelector('input[name="item_descuento_tipo[]"]');
      const hVal = tr.querySelector('input[name="item_descuento_valor[]"]');

      const tipo = String(hTipo?.value || "MONTO").toUpperCase();
      const valRaw = Number(hVal?.value || 0);

      const norm = normalizeDiscount(tipo, valRaw, subtotal);

      // Normalizar hidden value si hace falta (clamp)
      if (hTipo && hTipo.value !== norm.tipo) hTipo.value = norm.tipo;
      if (hVal && round2(Number(hVal.value || 0)) !== round2(norm.valor)) {
        hVal.value = String(norm.valor);
      }

      tr.dataset.descItemMonto = String(norm.monto);

      const descCell = tr.querySelector(".desc-item-cell");
      if (descCell) {
        descCell.textContent =
          norm.monto > 0 ? "-" + fmtMoney(norm.monto) : fmtMoney(0);
      }

      // Sync visual
      syncRowDiscountVisual(tr, norm.monto > 0);

      descItems += norm.monto;
    });

    bruto = round2(bruto);
    descItems = round2(descItems);

    const baseGlobal = round2(Math.max(0, bruto - descItems));

    if (totalBrutoLbl) totalBrutoLbl.textContent = fmtMoney(bruto);
    if (descuentoItemsLbl)
      descuentoItemsLbl.textContent = "-" + fmtMoney(descItems);

    // Descuento global
    const tipoG = String(descuentoTipo?.value || "MONTO").toUpperCase();
    const valGRaw = Number(descuentoValor?.value || 0);
    const normG = normalizeDiscount(tipoG, valGRaw, baseGlobal);

    // Ajustar max del input
    if (descuentoValor) {
      descuentoValor.max = normG.tipo === "PORC" ? "100" : String(baseGlobal);
      const curr = Number(descuentoValor.value || 0);
      if (Number.isFinite(curr) && round2(curr) !== round2(normG.valor)) {
        descuentoValor.value = String(normG.valor);
      }
    }
    if (descuentoTipo && descuentoTipo.value !== normG.tipo) {
      descuentoTipo.value = normG.tipo;
    }

    const totalFinal = round2(Math.max(0, baseGlobal - normG.monto));

    if (descuentoTotalLbl)
      descuentoTotalLbl.textContent = "-" + fmtMoney(normG.monto);
    if (totalLbl) totalLbl.textContent = fmtMoney(totalFinal);
  }

  function resetForm() {
    selectedProduct = null;
    searchInput.value = "";
    inCost.value = "0";
    if (itemDescTipo) itemDescTipo.value = "MONTO";
    if (itemDescValor) itemDescValor.value = "0";
    unitLbl.textContent = "Unidad: UNIDAD";

    // Limpiar UI pesable
    const existingBadge = qtyFieldContainer.querySelector(".badge-pesable");
    const existingHelp = qtyFieldContainer.querySelector(".help-pesable");
    if (existingBadge) existingBadge.remove();
    if (existingHelp) existingHelp.remove();
    inQty.classList.remove("input-pesable");
    inQty.placeholder = "";

    // Resetear input cantidad (fix: step/min)
    inQty.step = "1";
    inQty.min = "1";
    inQty.value = "1";
    inQty.removeEventListener("input", validatePesableInput);

    // Limpiar preview
    const preview = document.getElementById("subtotalPreview");
    if (preview) preview.remove();

    // En modo rápido, devolvé foco a búsqueda
    searchInput.focus();
  }

  /* ============================================================================
     UI HELPERS
  ============================================================================ */
  function showToast(msg, type = "info") {
    const text = String(msg || "").trim();
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

    if (window.showToast) {
      window.showToast(msg, type);
      return;
    }
    const toast = document.createElement("div");
    toast.className = `toast compras-toast toast-${type} show`;
    toast.textContent = msg;
    document.body.appendChild(toast);

    setTimeout(() => toast.classList.add("show"), 10);
    setTimeout(() => {
      toast.classList.remove("show");
      setTimeout(() => toast.remove(), 250);
    }, 2800);
  }

  function ensureAutosaveStatus() {
    if (autosaveStatusEl) return autosaveStatusEl;

    autosaveStatusEl = document.getElementById("autosave-status");
    if (autosaveStatusEl) return autosaveStatusEl;

    const host =
      document.querySelector(".module-header-actions") ||
      form?.querySelector(".form-grid") ||
      form;

    autosaveStatusEl = document.createElement("div");
    autosaveStatusEl.id = "autosave-status";
    autosaveStatusEl.className = "autosave-status autosave-status-idle";
    autosaveStatusEl.setAttribute("aria-live", "polite");
    host?.appendChild(autosaveStatusEl);
    return autosaveStatusEl;
  }

  function setAutosaveStatus(message, tone = "idle") {
    const el = ensureAutosaveStatus();
    if (!el) return;
    el.className = `autosave-status autosave-status-${tone}`;
    el.textContent = message;
  }

  function getDraftRows() {
    return Array.from(tbody.querySelectorAll("tr[data-row='item']"));
  }

  function hasDraftableData() {
    return (
      normalizeProviderValue(proveedorInput?.value || "") !== "" &&
      getDraftRows().length > 0
    );
  }

  function buildAutosavePayload() {
    const rows = getDraftRows();
    return {
      compraId: parseInt(compraIdInput?.value || "0", 10) || 0,
      proveedor: String(proveedorInput?.value || "").trim(),
      proveedorId: parseInt(proveedorIdInput?.value || "0", 10) || 0,
      tipoComp: String(form?.querySelector('[name="tipo_comp"]')?.value || "").trim(),
      nroComp: String(form?.querySelector('[name="nro_comp"]')?.value || "").trim(),
      observacion: String(form?.querySelector('[name="observacion"]')?.value || "").trim(),
      descuentoTipo: String(descuentoTipo?.value || "MONTO").toUpperCase(),
      descuentoValor: String(descuentoValor?.value || "0").trim(),
      items: rows.map((tr) => ({
        productoId: String(tr.querySelector('input[name="producto_id[]"]')?.value || ""),
        cantidad: String(tr.querySelector('input[name="cantidad[]"]')?.value || ""),
        costo: String(tr.querySelector('input[name="costo_unitario[]"]')?.value || ""),
        descuentoTipo: String(
          tr.querySelector('input[name="item_descuento_tipo[]"]')?.value || "MONTO",
        ).toUpperCase(),
        descuentoValor: String(
          tr.querySelector('input[name="item_descuento_valor[]"]')?.value || "0",
        ).trim(),
      })),
    };
  }

  function buildAutosaveSignature() {
    return JSON.stringify(buildAutosavePayload());
  }

  function syncDraftUrl(compraId) {
    if (!compraId || !window.history?.replaceState) return;
    const url = new URL(window.location.href);
    url.searchParams.set("editar", String(compraId));
    url.searchParams.delete("saved");
    window.history.replaceState({}, "", url.toString());
  }

  function updateCompraId(compraId) {
    if (!compraIdInput || !compraId) return;
    compraIdInput.value = String(compraId);
    syncDraftUrl(compraId);
  }

  async function refreshSavedPurchaseRow(compraId) {
    const listBody = document.querySelector(".compras-list tbody");
    if (!listBody || !compraId) return;

    try {
      const url = new URL(window.location.href);
      url.searchParams.set("editar", String(compraId));
      const response = await fetch(url.toString(), {
        method: "GET",
        credentials: "same-origin",
        headers: { Accept: "text/html" },
      });
      if (!response.ok) return;

      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, "text/html");
      const savedRow = Array.from(doc.querySelectorAll(".compras-list tbody tr"))
        .find((row) => row.querySelector("td")?.textContent?.trim() === `#${compraId}`);
      if (!savedRow) return;

      const currentRow = Array.from(listBody.querySelectorAll("tr"))
        .find((row) => row.querySelector("td")?.textContent?.trim() === `#${compraId}`);
      if (currentRow) {
        currentRow.replaceWith(savedRow);
      } else {
        listBody.querySelector("tr .empty-cell")?.closest("tr")?.remove();
        listBody.prepend(savedRow);
      }

      const count = doc.querySelector(".compras-list-count");
      const currentCount = document.querySelector(".compras-list-count");
      if (count && currentCount) {
        currentCount.textContent = count.textContent;
      }
    } catch {
      // El borrador ya quedo persistido; el listado se actualizara al recargar.
    }
  }

  function scheduleAutosave(delay = 1200) {
    if (!form || isManualSubmit) return;
    clearTimeout(autosaveTimer);
    autosaveTimer = window.setTimeout(() => {
      autosaveDraft();
    }, delay);
  }

  function markDraftDirty({ immediate = false } = {}) {
    hasUnsavedChanges = true;
    if (autosaveInFlight) autosaveQueued = true;
    setAutosaveStatus("Cambios pendientes", "pending");
    scheduleAutosave(immediate ? 250 : 1200);
  }

  async function autosaveDraft() {
    if (!form || isManualSubmit || !hasUnsavedChanges) return;
    if (autosaveInFlight) {
      autosaveQueued = true;
      return;
    }

    updateProveedorState({ autocorrect: false });
    if (!hasDraftableData()) {
      setAutosaveStatus("Autosave listo al completar proveedor e items", "idle");
      return;
    }

    const signature = buildAutosaveSignature();
    if (signature === autosaveLastSignature) {
      hasUnsavedChanges = false;
      setAutosaveStatus("Borrador al dia", "saved");
      return;
    }

    autosaveInFlight = true;
    setAutosaveStatus("Guardando borrador...", "saving");

    const body = new FormData(form);
    body.set("accion", "guardar_borrador");
    body.set("autosave", "1");

    try {
      const response = await fetch(form.getAttribute("action") || window.location.href, {
        method: "POST",
        body,
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          Accept: "application/json",
        },
        credentials: "same-origin",
      });

      const payload = await response.json().catch(() => null);
      if (!response.ok || !payload?.ok) {
        throw new Error(payload?.message || "No se pudo guardar el borrador");
      }

      const savedCompraId = parseInt(payload.compra_id || "0", 10) || 0;
      updateCompraId(savedCompraId);
      autosaveLastSignature = buildAutosaveSignature();
      hasUnsavedChanges = false;
      setAutosaveStatus("Borrador guardado automaticamente", "saved");
      await refreshSavedPurchaseRow(savedCompraId);
    } catch (error) {
      const message =
        error instanceof Error && error.message
          ? error.message
          : "No se pudo guardar el borrador";
      setAutosaveStatus(message, "error");
    } finally {
      autosaveInFlight = false;
      if (autosaveQueued) {
        autosaveQueued = false;
        hasUnsavedChanges = true;
        scheduleAutosave(300);
      }
    }
  }

  function flushAutosaveBeacon() {
    if (
      !form ||
      isManualSubmit ||
      !hasUnsavedChanges ||
      !hasDraftableData() ||
      typeof navigator.sendBeacon !== "function"
    ) {
      return;
    }

    const signature = buildAutosaveSignature();
    if (signature === autosaveLastSignature) return;

    const body = new FormData(form);
    body.set("accion", "guardar_borrador");
    body.set("autosave", "1");

    const sent = navigator.sendBeacon(
      form.getAttribute("action") || window.location.href,
      body,
    );

    if (sent) {
      autosaveLastSignature = signature;
      hasUnsavedChanges = false;
      setAutosaveStatus("Borrador enviado al salir", "saved");
    }
  }


  function normalizeProviderValue(value) {
    return String(value || "")
      .trim()
      .replace(/\s+/g, " ")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLocaleLowerCase("es-AR");
  }

  function findProveedorMatches(value) {
    if (!proveedoresData) return [];
    const normalized = normalizeProviderValue(value);
    if (!normalized) return [];

    const options = Array.from(proveedoresData.options).filter(
      (option) => normalizeProviderValue(option.value),
    );
    const exact = options.find(
      (option) => normalizeProviderValue(option.value) === normalized,
    );
    if (exact) return [exact];

    const startsWith = options.filter((option) =>
      normalizeProviderValue(option.value).startsWith(normalized),
    );
    if (startsWith.length) return startsWith;

    return options.filter((option) =>
      normalizeProviderValue(option.value).includes(normalized),
    );
  }

  function findProveedorOption(value) {
    const matches = findProveedorMatches(value);
    return matches.length === 1 ? matches[0] : null;
  }

  function updateProveedorState({ autocorrect = false } = {}) {
    if (!proveedorInput || !proveedorIdInput || !proveedorMatch) return;

    const rawValue = proveedorInput.value;
    const hasValue = normalizeProviderValue(rawValue) !== "";
    const matches = findProveedorMatches(rawValue);
    const option = matches.length === 1 ? matches[0] : null;

    if (!hasValue) {
      proveedorIdInput.value = "0";
      proveedorMatch.hidden = true;
      proveedorMatch.className = "provider-match";
      proveedorMatch.textContent = "";
      return;
    }

    if (option) {
      proveedorIdInput.value = option.dataset.id || "0";
      if (
        autocorrect ||
        normalizeProviderValue(rawValue) === normalizeProviderValue(option.value)
      ) {
        proveedorInput.value = option.value;
      }
      proveedorMatch.hidden = false;
      proveedorMatch.className = "provider-match is-linked";
      proveedorMatch.textContent = "Proveedor existente";
      return;
    }

    proveedorIdInput.value = "0";
    proveedorMatch.hidden = false;
    proveedorMatch.className =
      matches.length > 1 ? "provider-match is-ambiguous" : "provider-match is-new";
    proveedorMatch.textContent =
      matches.length > 1
        ? `Hay ${matches.length} coincidencias. Segui escribiendo o elegi una sugerencia.`
        : "Se creara un proveedor nuevo al guardar";
  }
  function focusFieldWithMessage(field, msg) {
    if (msg) showToast(msg, "warning");
    field?.scrollIntoView({ behavior: "smooth", block: "center" });
    field?.focus();
  }

  function hasPendingChanges() {
    return hasUnsavedChanges && itemsAdded.length > 0;
  }

  function confirmLeavePage(onConfirm) {
    const title = 'Salir de compras';
    const body = '<p>Hay cambios sin guardar en esta compra.</p><p>Si salis ahora, podrias perder lo cargado.</p>';

    if (window.Notif?.confirmar) {
      window.Notif.confirmar(title, body, {
        icon: 'warning',
        confirmText: 'Salir igual',
        cancelText: 'Quedarme',
      }).then((ok) => {
        if (ok) onConfirm?.();
      });
      return;
    }

    showConfirm('Hay cambios sin guardar en esta compra. Si salis ahora, podrias perder lo cargado.', onConfirm, null, 'Salir igual', 'Quedarme');
  }

  function showConfirm(
    msg,
    onConfirm,
    onCancel = null,
    confirmText = "Confirmar",
    cancelText = "Cancelar",
  ) {
    const modal = document.createElement("div");
    modal.className = "modal-overlay compras-modal-overlay";

    modal.innerHTML = `
      <div class="modal-box">
        <p class="modal-message">${escapeHtml(msg)}</p>
        <div class="modal-actions">
          <button type="button" class="btn btn-secondary js-cancel">${escapeHtml(cancelText)}</button>
          <button type="button" class="btn btn-primary js-confirm">${escapeHtml(confirmText)}</button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);
    setTimeout(() => modal.classList.add("active"), 10);

    const close = () => {
      modal.classList.remove("active");
      setTimeout(() => modal.remove(), 180);
    };

    modal.querySelector(".js-confirm")?.addEventListener("click", () => {
      onConfirm && onConfirm();
      close();
    });

    modal.querySelector(".js-cancel")?.addEventListener("click", () => {
      onCancel && onCancel();
      close();
    });

    modal.addEventListener("click", (e) => {
      if (e.target === modal) close();
    });

    document.addEventListener(
      "keydown",
      (e) => {
        if (e.key === "Escape") close();
      },
      { once: true },
    );
  }

  /* ============================================================================
     EDITAR DESCUENTO POR ÍTEM (MODAL SEGURO)
  ============================================================================ */
  if (proveedorInput) {
    updateProveedorState();
    proveedorInput.addEventListener("input", () => {
      updateProveedorState();
      markDraftDirty();
    });
    proveedorInput.addEventListener("change", () => {
      updateProveedorState();
      markDraftDirty();
    });
    proveedorInput.addEventListener("blur", () =>
      updateProveedorState({ autocorrect: true }),
    );
    proveedorInput.addEventListener("keydown", (event) => {
      if (event.key !== "Enter") return;
      event.preventDefault();
      updateProveedorState({ autocorrect: true });
    });
  }
  document.addEventListener("submit", (event) => {
    const confirmForm = event.target;
    if (!(confirmForm instanceof HTMLFormElement) || !confirmForm.matches(".js-compra-confirm-form")) {
      return;
    }

    event.preventDefault();
    const title = confirmForm.dataset.confirmTitle || "Confirmar accion";
    const message = confirmForm.dataset.confirmMessage || "Revisa esta accion antes de continuar.";
    showConfirm(`${title}. ${message}`, () => confirmForm.submit(), null, "Continuar");
  });

  if (form) {
    form.addEventListener("keydown", (event) => {
      if (event.key !== "Enter") return;

      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
      if (target.tagName === "TEXTAREA") return;

      if (target === proveedorInput) {
        event.preventDefault();
        updateProveedorState({ autocorrect: true });
        return;
      }

      if (
        target.matches(
          ".quick-add-search, .quick-add-check, .quick-add-qty, .quick-add-cost",
        )
      ) {
        event.preventDefault();
        return;
      }

      if (target === searchInput) return;

      if (target === inQty || target === inCost || target === itemDescValor) {
        event.preventDefault();
        addItem();
        return;
      }

      if (target.matches("input, select")) {
        event.preventDefault();
      }
    });

    form.addEventListener("submit", (event) => {
      updateProveedorState({ autocorrect: true });
      if (!proveedorInput || !proveedorIdInput) return;

      const hasName = normalizeProviderValue(proveedorInput.value) !== "";
      const hasProveedorId = Number(proveedorIdInput.value || 0) > 0;
      if (!hasName || hasProveedorId) return;

      event.preventDefault();
      showConfirm(
        `No existe un proveedor cargado con el nombre "${proveedorInput.value.trim()}". Si continuas se creara uno nuevo.`,
        () => {
          isManualSubmit = true;
          clearTimeout(autosaveTimer);
          form.submit();
        },
        null,
        "Crear y guardar",
      );
    });
  }

  [
    form?.querySelector('[name="tipo_comp"]'),
    form?.querySelector('[name="nro_comp"]'),
    form?.querySelector('[name="observacion"]'),
  ].forEach((field) => {
    if (!field) return;
    field.addEventListener("input", () => markDraftDirty());
    field.addEventListener("change", () => markDraftDirty());
  });

  function openDiscountEditor(tr, product) {
    const hTipo = tr.querySelector('input[name="item_descuento_tipo[]"]');
    const hVal = tr.querySelector('input[name="item_descuento_valor[]"]');

    const currentTipo = String(hTipo?.value || "MONTO").toUpperCase();
    const currentVal = parseFloat(hVal?.value || 0);

    const modal = document.createElement("div");
    modal.className = "modal-overlay compras-modal-overlay";

    // Render seguro (nombre/código escapados)
    modal.innerHTML = `
      <div class="modal-box discount-editor-modal">
        <div class="modal-header">
          <h3>Editar descuento</h3>
          <button type="button" class="btn-close js-close" aria-label="Cerrar">x</button>
        </div>
        <div class="modal-body">
          <div class="product-info">
            <strong>${escapeHtml(product?.nombre || "")}</strong>
            <span class="product-code">${escapeHtml(product?.codigo || "")}</span>
          </div>

          <div class="discount-form">
            <div class="field">
              <label>Tipo de descuento</label>
              <select id="editDescTipo" class="form-input">
                <option value="MONTO" ${currentTipo === "MONTO" ? "selected" : ""}>Monto fijo ($)</option>
                <option value="PORC" ${currentTipo === "PORC" ? "selected" : ""}>Porcentaje (%)</option>
              </select>
            </div>

            <div class="field">
              <label>Valor</label>
              <input
                type="number"
                id="editDescValor"
                class="form-input"
                step="0.01"
                min="0"
                value="${Number.isFinite(currentVal) ? currentVal : 0}"
                placeholder="0.00"
              >
            </div>

            <div class="discount-preview" id="discountPreview"></div>
          </div>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn btn-secondary js-cancel">Cancelar</button>
          <button type="button" class="btn btn-primary js-save">Guardar</button>
        </div>
      </div>
    `;

    document.body.appendChild(modal);
    setTimeout(() => modal.classList.add("active"), 10);

    const controller = new AbortController();
    const { signal } = controller;
    let closed = false;

    const closeModal = () => {
      if (closed) return;
      closed = true;
      controller.abort();
      modal.classList.remove("active");
      setTimeout(() => modal.remove(), 160);
    };

    const editTipoSelect = modal.querySelector("#editDescTipo");
    const editValorInput = modal.querySelector("#editDescValor");
    const previewDiv = modal.querySelector("#discountPreview");
    const btnSave = modal.querySelector(".js-save");
    const btnCancel = modal.querySelector(".js-cancel");
    const btnClose = modal.querySelector(".js-close");

    const getRowSubtotal = () => {
      const qty = parseFloat(
        tr.querySelector('input[name="cantidad[]"]')?.value || 0,
      );
      const cost = parseFloat(
        tr.querySelector('input[name="costo_unitario[]"]')?.value || 0,
      );
      return round2(qty * cost);
    };

    function updateDiscountPreview() {
      const subtotal = getRowSubtotal();
      const tipo = editTipoSelect?.value || "MONTO";
      const val = parseFloat(editValorInput?.value || 0);

      const norm = normalizeDiscount(tipo, val, subtotal);
      const final = round2(subtotal - norm.monto);

      if (!previewDiv) return;

      if (norm.monto > 0) {
        previewDiv.innerHTML = `
          <div class="preview-row">
            <span>Subtotal bruto:</span>
            <span>${fmtMoney(subtotal)}</span>
          </div>
          <div class="preview-row discount">
            <span>Descuento:</span>
            <span>-${fmtMoney(norm.monto)}</span>
          </div>
          <div class="preview-row total">
            <span>Subtotal neto:</span>
            <span>${fmtMoney(final)}</span>
          </div>
        `;
      } else {
        previewDiv.innerHTML = `
          <div class="preview-row muted">
            <span>Sin descuento</span>
            <span>${fmtMoney(subtotal)}</span>
          </div>
        `;
      }
    }

    editTipoSelect?.addEventListener("change", updateDiscountPreview, {
      signal,
    });
    editValorInput?.addEventListener("input", updateDiscountPreview, {
      signal,
    });
    updateDiscountPreview();

    btnSave?.addEventListener(
      "click",
      () => {
        const subtotal = getRowSubtotal();
        const tipo = editTipoSelect?.value || "MONTO";
        const val = parseFloat(editValorInput?.value || 0);
        const norm = normalizeDiscount(tipo, val, subtotal);

        if (hTipo) hTipo.value = norm.tipo;
        if (hVal) hVal.value = String(norm.valor);

        markDraftDirty();
        recalcTotal();

        tr.classList.add("highlight-update");
        setTimeout(() => tr.classList.remove("highlight-update"), 450);

        closeModal();
        showToast("Descuento actualizado", "success");
      },
      { signal },
    );

    btnCancel?.addEventListener("click", closeModal, { signal });
    btnClose?.addEventListener("click", closeModal, { signal });

    modal.addEventListener(
      "click",
      (e) => {
        if (e.target === modal) closeModal();
      },
      { signal },
    );

    document.addEventListener(
      "keydown",
      (e) => {
        if (e.key === "Escape") closeModal();
        if (
          e.key === "Enter" &&
          (document.activeElement === editValorInput ||
            document.activeElement === editTipoSelect)
        ) {
          // Enter dentro del modal = guardar
          e.preventDefault();
          btnSave?.click();
        }
      },
      { signal },
    );

    editValorInput?.focus();
    editValorInput?.select?.();
  }

  /* ============================================================================
     EDICIÓN INLINE (cantidad / costo)
  ============================================================================ */
  function enableEditMode(tr, product) {
    const qtyCell = tr.querySelector(".editable-cell[data-field='cantidad']");
    const costCell = tr.querySelector(".editable-cell[data-field='costo']");
    const cells = [qtyCell, costCell].filter(Boolean);

    cells.forEach((cell, idx) => {
      const valueSpan = cell.querySelector(".cell-value");
      const editInput = cell.querySelector(".cell-edit");
      if (!valueSpan || !editInput) return;

      const field = cell.dataset.field;
      const hiddenName =
        field === "cantidad" ? "cantidad[]" : "costo_unitario[]";
      const hiddenInput = tr.querySelector(`input[name="${hiddenName}"]`);

      if (field === "cantidad") {
        const pes = isPesable(product);
        editInput.step = pes ? "0.001" : "1";
        editInput.min = pes ? "0.001" : "1";
      } else {
        editInput.step = "0.01";
        editInput.min = "0";
      }

      if (hiddenInput) editInput.value = hiddenInput.value;

      const openEditor = () => {
        valueSpan.hidden = true;
        editInput.disabled = false;
        editInput.hidden = false;
      };

      const closeEditor = () => {
        valueSpan.hidden = false;
        editInput.disabled = true;
        editInput.hidden = true;
      };

      openEditor();

      if (idx === 0) {
        editInput.focus();
        editInput.select?.();
      }

      const controller = new AbortController();
      let hasClosed = false;

      const validateValue = (fieldName, v) => {
        if (fieldName === "cantidad") {
          if (!(v > 0)) return "Cantidad invalida";
          if (v > MAX_QTY)
            return `Cantidad muy alta (max: ${MAX_QTY.toLocaleString()})`;
        }
        if (fieldName === "costo") {
          if (v < 0) return "Costo invalido";
          if (v > MAX_COST)
            return `Costo muy alto (max: ${fmtMoney(MAX_COST)})`;
        }
        return "";
      };

      const commitValue = () => {
        const newValue = parseFloat(editInput.value || 0);
        const err = validateValue(field, newValue);

        if (err) {
          showToast(err, "warning");
          editInput.focus();
          editInput.select?.();
          return false;
        }

        if (hiddenInput) hiddenInput.value = newValue;

        valueSpan.textContent =
          field === "cantidad" ? fmtQty(newValue, product) : fmtMoney(newValue);

        const qtyVal = parseFloat(
          tr.querySelector(`input[name="cantidad[]"]`)?.value || 0,
        );
        const costVal = parseFloat(
          tr.querySelector(`input[name="costo_unitario[]"]`)?.value || 0,
        );
        const newSubtotal = round2(qtyVal * costVal);

        tr.dataset.subtotal = String(newSubtotal);
        tr.querySelector(".subtotal-cell").textContent = fmtMoney(newSubtotal);

        markDraftDirty();
        recalcTotal();
        showToast("Item actualizado", "success");

        closeEditor();
        hasClosed = true;
        controller.abort();
        return true;
      };

      const cancelEdit = () => {
        if (hiddenInput) editInput.value = hiddenInput.value;
        closeEditor();
        hasClosed = true;
        controller.abort();
      };

      editInput.addEventListener(
        "blur",
        () => {
          if (hasClosed) return;
          commitValue();
        },
        { signal: controller.signal },
      );

      editInput.addEventListener(
        "keydown",
        (e) => {
          if (e.key === "Enter") {
            e.preventDefault();
            commitValue();
          } else if (e.key === "Escape") {
            e.preventDefault();
            cancelEdit();
          }
        },
        { signal: controller.signal },
      );
    });
  }

  /* ============================================================================
     CREAR/ELIMINAR FILAS
  ============================================================================ */
  function createNewRow(product, qty, cost, dTipo = "MONTO", dVal = 0) {
    removeEmptyRow();

    qty = Number(qty);
    cost = Number(cost);

    const pes = isPesable(product);
    const qtyStep = pes ? "0.001" : "1";
    const qtyMin = pes ? "0.001" : "1";

    const subtotal = round2(qty * cost);
    const norm = normalizeDiscount(dTipo, dVal, subtotal);

    const rowId = autoIdCounter++;

    const tr = document.createElement("tr");
    tr.dataset.row = "item";
    tr.dataset.rowId = String(rowId);
    tr.dataset.subtotal = String(subtotal);
    tr.dataset.descItemMonto = String(norm.monto);
    tr.classList.add("fade-in");
    if (pes) tr.classList.add("is-pesable-row");

    const safeName = escapeHtml(product?.nombre || "");
    const safeCode = escapeHtml(product?.codigo || "");
    const safeUnit = escapeHtml(String(product?.unidad || "").toUpperCase());

    tr.innerHTML = `
      <td>
        <div class="item-name">
          <span class="item-name-text">${safeName}</span>
          ${pes ? `<span class="unit-tag">${safeUnit}</span>` : ""}
          <span class="item-discount-badge" ${norm.monto > 0 ? "" : "hidden"}>Con descuento</span>
        </div>
        <div class="item-code">${safeCode}</div>
      </td>
      <td class="right editable-cell" data-field="cantidad">
        <span class="cell-value">${fmtQty(qty, product)}</span>
        <input type="number" class="cell-edit" value="${qty}" step="${qtyStep}" min="${qtyMin}" disabled hidden>
      </td>
      <td class="right editable-cell" data-field="costo">
        <span class="cell-value">${fmtMoney(cost)}</span>
        <input type="number" class="cell-edit" value="${cost}" step="0.01" min="0" disabled hidden>
      </td>
      <td class="right desc-item-cell ${norm.monto > 0 ? "has-discount" : ""}">
        ${norm.monto > 0 ? "-" + fmtMoney(norm.monto) : fmtMoney(0)}
      </td>
      <td class="right subtotal-cell">${fmtMoney(subtotal)}</td>
      <td class="center actions-cell">
        ${buildRowActionsMarkup()}
        <input type="hidden" name="producto_id[]" value="${product.id}">
        <input type="hidden" name="cantidad[]" value="${qty}">
        <input type="hidden" name="costo_unitario[]" value="${cost}">
        <input type="hidden" name="item_descuento_tipo[]" value="${norm.tipo}">
        <input type="hidden" name="item_descuento_valor[]" value="${norm.valor}">
      </td>
    `;

    tr.querySelector("[data-action='edit']")?.addEventListener("click", () =>
      enableEditMode(tr, product),
    );
    tr.querySelector("[data-action='edit-discount']")?.addEventListener(
      "click",
      () => openDiscountEditor(tr, product),
    );
    tr.querySelector("[data-action='delete']")?.addEventListener("click", () =>
      deleteRow(tr, rowId),
    );

    tbody.appendChild(tr);
    itemsAdded.push({ rowId, productId: product.id });

    markDraftDirty();
    recalcTotal();
    if (!bulkQuickAddInProgress) {
      resetForm();
      showToast("Producto agregado", "success");
    }
  }

  function deleteRow(tr, rowId) {
    showConfirm("Eliminar este producto de la compra?", () => {
      tr.classList.add("fade-out");
      setTimeout(() => {
        tr.remove();
        itemsAdded = itemsAdded.filter((it) => it.rowId !== rowId);
        markDraftDirty();
        addEmptyRowIfNeeded();
        recalcTotal();
      }, 180);
    });
  }

  /* ============================================================================
     AGREGAR ITEM
  ============================================================================ */
  function addItem() {
    if (!selectedProduct) {
      showToast("Selecciona un producto primero", "warning");
      searchInput.focus();
      return;
    }

    const qty = parseFloat(inQty.value || 0);
    const cost = parseFloat(inCost.value || 0);

    const dTipo = String(itemDescTipo?.value || "MONTO").toUpperCase();
    let dVal = parseFloat(itemDescValor?.value || 0);
    if (!Number.isFinite(dVal) || dVal < 0) dVal = 0;

    if (!(qty > 0)) {
      showToast("La cantidad debe ser mayor a 0", "warning");
      inQty.focus();
      return;
    }
    if (cost < 0) {
      showToast("El costo no puede ser negativo", "warning");
      inCost.focus();
      return;
    }
    if (qty > MAX_QTY) {
      showToast(
        `Cantidad muy alta (maximo: ${MAX_QTY.toLocaleString()})`,
        "warning",
      );
      inQty.focus();
      return;
    }
    if (cost > MAX_COST) {
      showToast(`Costo muy alto (maximo: ${fmtMoney(MAX_COST)})`, "warning");
      inCost.focus();
      return;
    }

    const existing = itemsAdded.find(
      (it) => it.productId === selectedProduct.id,
    );

    if (existing) {
      showConfirm(
        "Este producto ya esta en la lista. Queres sumar cantidad en la linea existente?",
        () => {
          const tr = tbody.querySelector(`tr[data-row-id="${existing.rowId}"]`);
          if (!tr) return createNewRow(selectedProduct, qty, cost, dTipo, dVal);

          const hiddenQty = tr.querySelector(`input[name="cantidad[]"]`);
          const hiddenCost = tr.querySelector(`input[name="costo_unitario[]"]`);

          const newQty = parseFloat(hiddenQty?.value || 0) + qty;

          if (newQty > MAX_QTY) {
            showToast(
              `La suma superaria el maximo permitido (${MAX_QTY.toLocaleString()})`,
              "warning",
            );
            return;
          }

          if (hiddenQty) hiddenQty.value = newQty;
          if (hiddenCost) hiddenCost.value = cost;

          tr.querySelector(
            ".editable-cell[data-field='cantidad'] .cell-value",
          ).textContent = fmtQty(newQty, selectedProduct);
          tr.querySelector(
            ".editable-cell[data-field='costo'] .cell-value",
          ).textContent = fmtMoney(cost);

          const newSubtotal = round2(newQty * cost);
          tr.dataset.subtotal = String(newSubtotal);
          tr.querySelector(".subtotal-cell").textContent =
            fmtMoney(newSubtotal);

          tr.classList.add("highlight-update");
          setTimeout(() => tr.classList.remove("highlight-update"), 450);

          markDraftDirty();
          recalcTotal();
          resetForm();
          showToast("Item actualizado", "success");
        },
        () => {
          createNewRow(selectedProduct, qty, cost, dTipo, dVal);
        },
        "Sumar",
        "Agregar nueva linea",
      );
      return;
    }

    createNewRow(selectedProduct, qty, cost, dTipo, dVal);
  }

  function addProductToDraft(product, qty, cost) {
    if (!product) return { status: "skipped", reason: "missing_product" };
    if (!(qty > 0) || cost < 0 || qty > MAX_QTY || cost > MAX_COST) {
      return { status: "skipped", reason: "invalid_values" };
    }

    const existing = itemsAdded.find((it) => it.productId === product.id);
    bulkQuickAddInProgress = true;

    if (existing) {
      const tr = tbody.querySelector(`tr[data-row-id="${existing.rowId}"]`);
      if (!tr) {
        createNewRow(product, qty, cost, "MONTO", 0);
        bulkQuickAddInProgress = false;
        return { status: "added" };
      }

      const hiddenQty = tr.querySelector('input[name="cantidad[]"]');
      const hiddenCost = tr.querySelector('input[name="costo_unitario[]"]');
      const newQty = parseFloat(hiddenQty?.value || 0) + qty;
      if (newQty > MAX_QTY) {
        bulkQuickAddInProgress = false;
        return { status: "skipped", reason: "max_qty" };
      }

      if (hiddenQty) hiddenQty.value = newQty;
      if (hiddenCost) hiddenCost.value = cost;
      tr.querySelector(".editable-cell[data-field='cantidad'] .cell-value").textContent = fmtQty(newQty, product);
      tr.querySelector(".editable-cell[data-field='costo'] .cell-value").textContent = fmtMoney(cost);

      const newSubtotal = round2(newQty * cost);
      tr.dataset.subtotal = String(newSubtotal);
      tr.querySelector(".subtotal-cell").textContent = fmtMoney(newSubtotal);
      tr.classList.add("highlight-update");
      setTimeout(() => tr.classList.remove("highlight-update"), 450);
      markDraftDirty();
      recalcTotal();
      bulkQuickAddInProgress = false;
      return { status: "merged" };
    }

    createNewRow(product, qty, cost, "MONTO", 0);
    bulkQuickAddInProgress = false;
    return { status: "added" };
  }

  /* ============================================================================
     EVENT LISTENERS
  ============================================================================ */
  if (btnAdd) {
    btnAdd.addEventListener("click", addItem);

    [inQty, inCost].forEach((input) => {
      input?.addEventListener("keydown", (e) => {
        if (e.key === "Enter") {
          e.preventDefault();
          addItem();
        }
      });
    });
  }

  // Debounce en descuento global
  [descuentoTipo, descuentoValor].forEach((el) => {
    if (!el) return;
    el.addEventListener("input", () => {
      markDraftDirty();
      clearTimeout(recalcDebounceTimer);
      recalcDebounceTimer = setTimeout(recalcTotal, 150);
    });
    el.addEventListener("change", () => {
      markDraftDirty();
      recalcTotal();
    });
  });

  if (form) {
    form.addEventListener("submit", (e) => {
      updateProveedorState();

      if (!proveedorInput || normalizeProviderValue(proveedorInput.value) === "") {
        e.preventDefault();
        focusFieldWithMessage(proveedorInput, "Ingresa un proveedor antes de guardar el borrador");
        return;
      }

      if (!tbody.querySelector("tr[data-row='item']")) {
        e.preventDefault();
        const itemsGrid = document.querySelector('.items-grid');
        itemsGrid?.scrollIntoView({ behavior: "smooth", block: "center" });
        showToast("Agrega al menos 1 item a la compra", "warning");
        return;
      }

      isManualSubmit = true;
      clearTimeout(autosaveTimer);
      hasUnsavedChanges = false;
      setAutosaveStatus("Guardando borrador manualmente...", "saving");
    });
  }

  /* ============================================================================
     MODO AGREGAR RÁPIDO (UI TOGGLE)
  ============================================================================ */
  function createQuickAddToggle() {
    const toggleContainer = document.createElement("div");
    toggleContainer.className = "quick-add-toggle";
    toggleContainer.innerHTML = `
      <input type="checkbox" id="quickAddCheckbox" class="quick-add-checkbox-hidden">
      <label class="quick-add-toggle-btn" for="quickAddCheckbox">
        <span class="quick-add-toggle-icon" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="8" y1="6" x2="21" y2="6"/>
            <line x1="8" y1="12" x2="21" y2="12"/>
            <line x1="8" y1="18" x2="21" y2="18"/>
            <line x1="3" y1="6" x2="3.01" y2="6"/>
            <line x1="3" y1="12" x2="3.01" y2="12"/>
            <line x1="3" y1="18" x2="3.01" y2="18"/>
          </svg>
        </span>
        <span class="quick-add-toggle-copy">
          <span class="toggle-text">Ver lista de productos</span>
          <span class="toggle-hint">del proveedor · frecuentes · todos</span>
        </span>
        <span class="quick-add-toggle-arrow" aria-hidden="true">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="6 9 12 15 18 9"/>
          </svg>
        </span>
      </label>
    `;

    const itemsGrid = document.querySelector(".items-grid");
    if (itemsGrid) {
      const setQuickAddMode = (enabled) => {
        quickAddMode = enabled;
        toggleContainer.classList.toggle("is-open", enabled);
      };

      itemsGrid.insertAdjacentElement("beforebegin", toggleContainer);

      const checkbox = document.getElementById("quickAddCheckbox");
      const stored = localStorage.getItem("flus_lista_productos_open");
      const initialOpen = stored === "1";
      if (initialOpen) checkbox.checked = true;
      setQuickAddMode(initialOpen);

      checkbox?.addEventListener("change", (e) => {
        const enabled = !!e.target.checked;
        setQuickAddMode(enabled);
        localStorage.setItem("flus_lista_productos_open", enabled ? "1" : "0");
      });
    }
  }
  createQuickAddToggle();

  /* ============================================================================
     CARGAR ITEMS EN MODO EDICIÓN
  ============================================================================ */
  const preloadedItems = tbody.querySelectorAll(".preloaded-item");
  if (preloadedItems.length > 0) {
    removeEmptyRow();

    preloadedItems.forEach((stub) => {
      const productId = parseInt(stub.dataset.productoId);
      const qty = parseFloat(stub.dataset.cantidad);
      const cost = parseFloat(stub.dataset.costo);
      const descTipo = String(stub.dataset.descTipo || "MONTO").toUpperCase();
      const descValRaw = parseFloat(stub.dataset.descValor || "0");
      const descVal = Number.isFinite(descValRaw) ? descValRaw : 0;

      const esPesable = parseInt(stub.dataset.esPesable);
      const unidad = stub.dataset.unidad;
      const nombre = stub.dataset.nombre;
      const codigo = stub.dataset.codigo;

      const productMock = {
        id: productId,
        nombre: nombre,
        codigo: codigo,
        esPesable: esPesable,
        unidad: unidad,
      };

      const pes2 = isPesable(productMock);
      const qtyStep2 = pes2 ? "0.001" : "1";
      const qtyMin2 = pes2 ? "0.001" : "1";

      const subtotal = round2(qty * cost);
      const norm = normalizeDiscount(descTipo, descVal, subtotal);

      const rowId = autoIdCounter++;

      const tr = document.createElement("tr");
      tr.dataset.row = "item";
      tr.dataset.rowId = String(rowId);
      tr.dataset.subtotal = String(subtotal);
      tr.dataset.descItemMonto = String(norm.monto);
      if (pes2) tr.classList.add("is-pesable-row");

      tr.innerHTML = `
        <td>
          <div class="item-name">
            <span class="item-name-text">${escapeHtml(nombre)}</span>
            ${pes2 ? `<span class="unit-tag">${escapeHtml(String(unidad || "").toUpperCase())}</span>` : ""}
            <span class="item-discount-badge" ${norm.monto > 0 ? "" : "hidden"}>Con descuento</span>
          </div>
          <div class="item-code">${escapeHtml(codigo)}</div>
        </td>
        <td class="right editable-cell" data-field="cantidad">
          <span class="cell-value">${fmtQty(qty, productMock)}</span>
          <input type="number" class="cell-edit" value="${qty}" step="${qtyStep2}" min="${qtyMin2}" disabled hidden>
        </td>
        <td class="right editable-cell" data-field="costo">
          <span class="cell-value">${fmtMoney(cost)}</span>
          <input type="number" class="cell-edit" value="${cost}" step="0.01" min="0" disabled hidden>
        </td>
        <td class="right desc-item-cell ${norm.monto > 0 ? "has-discount" : ""}">
          ${norm.monto > 0 ? "-" + fmtMoney(norm.monto) : fmtMoney(0)}
        </td>
        <td class="right subtotal-cell">${fmtMoney(subtotal)}</td>
        <td class="center actions-cell">
        ${buildRowActionsMarkup()}
          <input type="hidden" name="producto_id[]" value="${productId}">
          <input type="hidden" name="cantidad[]" value="${qty}">
          <input type="hidden" name="costo_unitario[]" value="${cost}">
          <input type="hidden" name="item_descuento_tipo[]" value="${norm.tipo}">
          <input type="hidden" name="item_descuento_valor[]" value="${norm.valor}">
        </td>
      `;

      tr.querySelector("[data-action='edit']")?.addEventListener("click", () =>
        enableEditMode(tr, productMock),
      );
      tr.querySelector("[data-action='edit-discount']")?.addEventListener(
        "click",
        () => openDiscountEditor(tr, productMock),
      );
      tr.querySelector("[data-action='delete']")?.addEventListener(
        "click",
        () => deleteRow(tr, rowId),
      );

      tbody.appendChild(tr);
      itemsAdded.push({ rowId, productId });

      stub.remove();
    });

    recalcTotal();
  }

  /* ============================================================================
     PREVENIR PÉRDIDA DE DATOS
  ============================================================================ */
  if (btnResetCompra) {
    btnResetCompra.addEventListener("click", () => {
      const goReset = () => {
        flushAutosaveBeacon();
        hasUnsavedChanges = false;
        window.location.href = "compras.php";
      };

      if (hasPendingChanges()) {
        confirmLeavePage(goReset);
        return;
      }

      goReset();
    });
  }

  document.addEventListener("click", (event) => {
    const link = event.target.closest('a[href]');
    if (!link || !hasPendingChanges()) return;

    const href = link.getAttribute('href') || '';
    if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
    if (link.target === '_blank' || link.hasAttribute('download')) return;
    if (link.closest('#modalDetalle')) return;

    event.preventDefault();
    confirmLeavePage(() => {
      flushAutosaveBeacon();
      hasUnsavedChanges = false;
      window.location.href = link.href;
    });
  });

  window.addEventListener("beforeunload", (e) => {
    if (hasUnsavedChanges && itemsAdded.length > 0) {
      flushAutosaveBeacon();
      e.preventDefault();
      e.returnValue = "";
      return "";
    }
  });

  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "hidden") {
      flushAutosaveBeacon();
    }
  });

  window.addEventListener("pagehide", () => {
    flushAutosaveBeacon();
  });

  /* ============================================================================
     INICIALIZACIÓN
  ============================================================================ */
  window.FlusComprasQuick = {
    getProducts: () => productosData.slice(),
    getProveedorState: () => ({
      proveedorId: parseInt(proveedorIdInput?.value || "0", 10),
      proveedorNombre: String(proveedorInput?.value || "").trim(),
      proveedorNombreNormalizado: normalizeProviderValue(proveedorInput?.value || ""),
    }),
    normalizeProviderValue,
    addProductToDraft,
    isPesable,
    fmtMoney,
    onProviderChange(callback) {
      if (!proveedorInput || typeof callback !== "function") return () => {};
      const handler = () => callback(this.getProveedorState());
      proveedorInput.addEventListener("input", handler);
      proveedorInput.addEventListener("change", handler);
      proveedorInput.addEventListener("blur", handler);
      return () => {
        proveedorInput.removeEventListener("input", handler);
        proveedorInput.removeEventListener("change", handler);
        proveedorInput.removeEventListener("blur", handler);
      };
    },
  };

  setAutosaveStatus(
    Number(compraIdInput?.value || 0) > 0
      ? "Borrador automatico activo"
      : "Borrador automatico listo",
    "idle",
  );
  if (searchInput) searchInput.focus();
  applySearchPrefill();
  addEmptyRowIfNeeded();
  recalcTotal();
});




/**
 * COMPRAS.JS - Versión mejorada (segura) con edición de descuentos por ítem
 *
 * Incluye:
 * - ✅ Edición de descuentos por ítem en borradores (modal)
 * - ✅ Vista previa de subtotal (con descuento por ítem)
 * - ✅ Modo "Agregar Rápido" (toggle UI)
 * - ✅ Indicadores visuales (badge / celda descuento)
 * - ✅ Debounce en descuento global
 *
 * Fixes de seguridad/robustez:
 * - ✅ Mitigación XSS: NO se inyectan nombres/códigos sin escapar
 * - ✅ Modal sin listeners colgados (AbortController + closeModal centralizado)
 * - ✅ Indicadores sincronizados desde recalcTotal()
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

  if (!tbody) return;

  let productosData = [];
  let selectedProduct = null;
  let itemsAdded = [];
  let autoIdCounter = Date.now();
  let hasUnsavedChanges = false;
  let quickAddMode = false;

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
        showToast("Máximo 3 decimales para productos pesables", "info");
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
      badge.textContent = `⚖️ ${String(product?.unidad || "").toUpperCase()}`;
      qtyFieldContainer.querySelector("label")?.appendChild(badge);

      // Placeholder dinámico
      const unidadLower = String(product?.unidad || "").toLowerCase();
      inQty.placeholder = `Ej: 2.500 (${unidadLower})`;
      inQty.classList.add("input-pesable");

      // Ayuda contextual (sin innerHTML para evitar XSS)
      const help = document.createElement("div");
      help.className = "help-pesable";
      const strong = document.createElement("strong");
      strong.textContent = "💡 Producto pesable: ";
      const span = document.createElement("span");
      span.textContent = "Ingresá el peso con 3 decimales.";
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
        '<div class="suggestion-item loading-item">🔍 Buscando...</div>';
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
                ? `<div class="sug-price">Último: ${fmtMoney(p.ultimoCosto)}</div>`
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
    if (!btnAdd || !btnAdd.parentElement) return;

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

    btnAdd.parentElement.appendChild(preview);
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
      tbody.innerHTML =
        '<tr class="empty-row"><td colspan="6" class="empty-cell">Todavía no agregaste ítems.</td></tr>';
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
          <h3>✏️ Editar descuento</h3>
          <button type="button" class="btn-close js-close" aria-label="Cerrar">✕</button>
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
                <option value="MONTO" ${currentTipo === "MONTO" ? "selected" : ""}>💵 Monto fijo ($)</option>
                <option value="PORC" ${currentTipo === "PORC" ? "selected" : ""}>📊 Porcentaje (%)</option>
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
          <button type="button" class="btn btn-primary js-save">💾 Guardar</button>
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

        hasUnsavedChanges = true;
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
        valueSpan.style.display = "none";
        editInput.disabled = false;
        editInput.style.display = "block";
      };

      const closeEditor = () => {
        valueSpan.style.display = "inline";
        editInput.disabled = true;
        editInput.style.display = "none";
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
          if (!(v > 0)) return "Cantidad inválida";
          if (v > MAX_QTY)
            return `Cantidad muy alta (máx: ${MAX_QTY.toLocaleString()})`;
        }
        if (fieldName === "costo") {
          if (v < 0) return "Costo inválido";
          if (v > MAX_COST)
            return `Costo muy alto (máx: ${fmtMoney(MAX_COST)})`;
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

        hasUnsavedChanges = true;
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

    const safeName = escapeHtml(product?.nombre || "");
    const safeCode = escapeHtml(product?.codigo || "");

    tr.innerHTML = `
      <td>
        <div class="item-name">
          <span class="item-name-text">${safeName}</span>
          <span class="item-discount-badge" ${norm.monto > 0 ? "" : "hidden"}>🏷️ Con descuento</span>
        </div>
        <div class="item-code">${safeCode}</div>
      </td>
      <td class="right editable-cell" data-field="cantidad">
        <span class="cell-value">${fmtQty(qty, product)}</span>
        <input type="number" class="cell-edit" value="${qty}" step="${qtyStep}" min="${qtyMin}" disabled style="display:none;">
      </td>
      <td class="right editable-cell" data-field="costo">
        <span class="cell-value">${fmtMoney(cost)}</span>
        <input type="number" class="cell-edit" value="${cost}" step="0.01" min="0" disabled style="display:none;">
      </td>
      <td class="right desc-item-cell ${norm.monto > 0 ? "has-discount" : ""}">
        ${norm.monto > 0 ? "-" + fmtMoney(norm.monto) : fmtMoney(0)}
      </td>
      <td class="right subtotal-cell">${fmtMoney(subtotal)}</td>
      <td class="center">
        <button type="button" class="btn-icon" title="Editar cantidad/costo" data-action="edit">✏️</button>
        <button type="button" class="btn-icon" title="Editar descuento" data-action="edit-discount">🏷️</button>
        <button type="button" class="btn-icon btn-icon-danger" title="Eliminar" data-action="delete">🗑️</button>

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

    hasUnsavedChanges = true;
    recalcTotal();
    resetForm();
    showToast("Producto agregado", "success");
  }

  function deleteRow(tr, rowId) {
    showConfirm("¿Eliminar este producto de la compra?", () => {
      tr.classList.add("fade-out");
      setTimeout(() => {
        tr.remove();
        itemsAdded = itemsAdded.filter((it) => it.rowId !== rowId);
        hasUnsavedChanges = true;
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
      showToast("Seleccioná un producto primero", "warning");
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
        `Cantidad muy alta (máximo: ${MAX_QTY.toLocaleString()})`,
        "warning",
      );
      inQty.focus();
      return;
    }
    if (cost > MAX_COST) {
      showToast(`Costo muy alto (máximo: ${fmtMoney(MAX_COST)})`, "warning");
      inCost.focus();
      return;
    }

    const existing = itemsAdded.find(
      (it) => it.productId === selectedProduct.id,
    );

    if (existing) {
      showConfirm(
        "Este producto ya está en la lista. ¿Sumar cantidad en la línea existente?",
        () => {
          const tr = tbody.querySelector(`tr[data-row-id="${existing.rowId}"]`);
          if (!tr) return createNewRow(selectedProduct, qty, cost, dTipo, dVal);

          const hiddenQty = tr.querySelector(`input[name="cantidad[]"]`);
          const hiddenCost = tr.querySelector(`input[name="costo_unitario[]"]`);

          const newQty = parseFloat(hiddenQty?.value || 0) + qty;

          if (newQty > MAX_QTY) {
            showToast(
              `La suma superaría el máximo permitido (${MAX_QTY.toLocaleString()})`,
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

          hasUnsavedChanges = true;
          recalcTotal();
          resetForm();
          showToast("Item actualizado", "success");
        },
        () => {
          createNewRow(selectedProduct, qty, cost, dTipo, dVal);
        },
        "Sumar",
        "Agregar nueva línea",
      );
      return;
    }

    createNewRow(selectedProduct, qty, cost, dTipo, dVal);
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
      hasUnsavedChanges = true;
      clearTimeout(recalcDebounceTimer);
      recalcDebounceTimer = setTimeout(recalcTotal, 150);
    });
    el.addEventListener("change", () => {
      hasUnsavedChanges = true;
      recalcTotal();
    });
  });

  if (form) {
    form.addEventListener("submit", (e) => {
      if (!tbody.querySelector("tr[data-row='item']")) {
        e.preventDefault();
        showToast("Agregá al menos 1 ítem a la compra", "warning");
        return;
      }
      hasUnsavedChanges = false;
    });
  }

  /* ============================================================================
     MODO AGREGAR RÁPIDO (UI TOGGLE)
  ============================================================================ */
  function createQuickAddToggle() {
    const toggleContainer = document.createElement("div");
    toggleContainer.className = "quick-add-toggle";
    toggleContainer.innerHTML = `
      <label class="toggle-label">
        <input type="checkbox" id="quickAddCheckbox">
        <span class="toggle-text">⚡ Modo agregar rápido</span>
        <span class="toggle-hint">(mantener búsqueda activa)</span>
      </label>
    `;

    const itemsGrid = document.querySelector(".items-grid");
    if (itemsGrid) {
      itemsGrid.insertAdjacentElement("beforebegin", toggleContainer);

      const checkbox = document.getElementById("quickAddCheckbox");
      checkbox?.addEventListener("change", (e) => {
        quickAddMode = !!e.target.checked;
        if (quickAddMode) {
          showToast("⚡ Modo rápido activado", "info");
        }
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

      tr.innerHTML = `
        <td>
          <div class="item-name">
            <span class="item-name-text">${escapeHtml(nombre)}</span>
            <span class="item-discount-badge" ${norm.monto > 0 ? "" : "hidden"}>🏷️ Con descuento</span>
          </div>
          <div class="item-code">${escapeHtml(codigo)}</div>
        </td>
        <td class="right editable-cell" data-field="cantidad">
          <span class="cell-value">${fmtQty(qty, productMock)}</span>
          <input type="number" class="cell-edit" value="${qty}" step="${qtyStep2}" min="${qtyMin2}" disabled style="display:none;">
        </td>
        <td class="right editable-cell" data-field="costo">
          <span class="cell-value">${fmtMoney(cost)}</span>
          <input type="number" class="cell-edit" value="${cost}" step="0.01" min="0" disabled style="display:none;">
        </td>
        <td class="right desc-item-cell ${norm.monto > 0 ? "has-discount" : ""}">
          ${norm.monto > 0 ? "-" + fmtMoney(norm.monto) : fmtMoney(0)}
        </td>
        <td class="right subtotal-cell">${fmtMoney(subtotal)}</td>
        <td class="center">
          <button type="button" class="btn-icon" title="Editar cantidad/costo" data-action="edit">✏️</button>
          <button type="button" class="btn-icon" title="Editar descuento" data-action="edit-discount">🏷️</button>
          <button type="button" class="btn-icon btn-icon-danger" title="Eliminar" data-action="delete">🗑️</button>

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
  window.addEventListener("beforeunload", (e) => {
    if (hasUnsavedChanges && itemsAdded.length > 0) {
      e.preventDefault();
      e.returnValue = "";
      return "";
    }
  });

  /* ============================================================================
     INICIALIZACIÓN
  ============================================================================ */
  if (searchInput) searchInput.focus();
  addEmptyRowIfNeeded();
  recalcTotal();
});

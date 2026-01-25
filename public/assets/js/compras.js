/**
 * COMPRAS.JS - Versión corregida con todos los fixes
 * Fixes aplicados:
 * - resetForm() ahora resetea step/min del input cantidad
 * - enableEditMode() usa AbortController para manejo correcto de listeners
 * - Agregado beforeunload para prevenir pérdida de datos
 * - Mejorado feedback de carga en autocomplete
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

  /* ============================================================================
     CONSTANTES Y UTILS
  ============================================================================ */
  const MAX_QTY = 99999;
  const MAX_COST = 9999999;

  const round2 = (n) => Math.round((Number(n) || 0) * 100) / 100;
  const escapeRegExp = (s) => String(s).replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

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

  /* ============================================================================
     PRODUCTOS PESABLES - UX MEJORADA
  ============================================================================ */
  function applyQtyInputRules(product) {
    const pes = isPesable(product);
    inQty.step = pes ? "0.001" : "1";
    inQty.min = pes ? "0.001" : "1";
    if (!inQty.value || Number(inQty.value) <= 0)
      inQty.value = pes ? "1.000" : "1";
  }

  function updatePesableUI(product) {
    const isPes = isPesable(product);
    
    // Limpiar UI previa
    const existingBadge = qtyFieldContainer.querySelector('.badge-pesable');
    const existingHelp = qtyFieldContainer.querySelector('.help-pesable');
    if (existingBadge) existingBadge.remove();
    if (existingHelp) existingHelp.remove();
    
    inQty.classList.remove('input-pesable');
    inQty.removeEventListener('input', validatePesableInput);
    
    if (isPes) {
      // Badge visual
      const badge = document.createElement('span');
      badge.className = 'badge-pesable';
      badge.innerHTML = `⚖️ ${product.unidad}`;
      qtyFieldContainer.querySelector('label').appendChild(badge);
      
      // Placeholder dinámico
      const unidadLower = product.unidad.toLowerCase();
      inQty.placeholder = `Ej: 2.500 (${unidadLower})`;
      inQty.classList.add('input-pesable');
      
      // Ayuda contextual
      const help = document.createElement('div');
      help.className = 'help-pesable';
      help.innerHTML = `
        <strong>💡 Producto pesable:</strong> 
        Ingresá el peso con 3 decimales.
        <br>Ejemplo: <code>1.500</code> = 1.5 ${unidadLower}
      `;
      qtyFieldContainer.appendChild(help);
      
      // Validación en tiempo real
      inQty.addEventListener('input', validatePesableInput);
      
    } else {
      inQty.placeholder = 'Cantidad (unidades enteras)';
    }
  }

  function validatePesableInput(e) {
    const val = e.target.value;
    const num = parseFloat(val);
    
    if (val && !isNaN(num)) {
      const parts = val.split('.');
      if (parts[1] && parts[1].length > 3) {
        e.target.value = num.toFixed(3);
        showToast('Máximo 3 decimales para productos pesables', 'info');
      }
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
      // Solo quitar el último paréntesis si parece ser código
      const cleanName = rawText.replace(/\s*\([^)]+\)\s*$/, '').trim();

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
     AUTOCOMPLETE MEJORADO
  ============================================================================ */
  function highlightMatch(text, query) {
    const safe = escapeRegExp(query);
    const regex = new RegExp(`(${safe})`, "gi");
    return String(text).replace(regex, "<mark>$1</mark>");
  }

  function selectProduct(id) {
    const product = productosData.find((p) => p.id === id);
    if (!product) return;

    selectedProduct = product;
    searchInput.value = product.nombre;
    suggestionsBox.classList.remove("active");

    // Aplicar reglas y UI para pesables
    applyQtyInputRules(product);
    updatePesableUI(product);

    // Prefill cost
    if (product.ultimoCosto > 0) {
      inCost.value = round2(product.ultimoCosto).toFixed(2);
    } else {
      inCost.value = "0.00";
    }

    unitLbl.textContent = `Unidad: ${product.unidad}`;

    inQty.focus();
    inQty.select();
  }

  if (searchInput && suggestionsBox) {
    let debounceTimer;
    let isSearchActive = false;

    searchInput.addEventListener("input", (e) => {
      // Invalidar selección previa
      selectedProduct = null;
      unitLbl.textContent = "Unidad: UNIDAD";
      updatePesableUI({ esPesable: 0, unidad: "UNIDAD" });
      applyQtyInputRules({ esPesable: 0, unidad: "UNIDAD" });

      clearTimeout(debounceTimer);
      const query = e.target.value.trim().toLowerCase();

      if (query.length < 2) {
        suggestionsBox.classList.remove("active");
        suggestionsBox.innerHTML = "";
        isSearchActive = false;
        return;
      }

      isSearchActive = true;
      
      // FIX: Mostrar feedback de carga inmediato
      suggestionsBox.innerHTML = '<div class="suggestion-item loading-item">🔍 Buscando...</div>';
      suggestionsBox.classList.add("active");
      
      debounceTimer = setTimeout(() => {
        const filtered = productosData
          .filter(
            (p) =>
              p.nombre.toLowerCase().includes(query) ||
              p.codigo.toLowerCase().includes(query)
          )
          .slice(0, 8);

        if (filtered.length === 0) {
          suggestionsBox.innerHTML =
            '<div class="suggestion-item empty">No se encontraron productos</div>';
          suggestionsBox.classList.add("active");
          return;
        }

        suggestionsBox.innerHTML = filtered
          .map(
            (p) => `
            <div class="suggestion-item" data-id="${p.id}">
              <div class="sug-main">
                <strong>${highlightMatch(p.nombre, query)}</strong>
                <span class="sug-code">${p.codigo}</span>
              </div>
              ${
                p.ultimoCosto > 0
                  ? `<div class="sug-price">Último: ${fmtMoney(
                      p.ultimoCosto
                    )}</div>`
                  : ""
              }
            </div>
          `
          )
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
        ".suggestion-item[data-id]"
      );
      if (!items.length) {
        // Enter sin sugerencias - prevenir si búsqueda activa
        if (e.key === "Enter" && isSearchActive) {
          e.preventDefault();
          return;
        }
        return;
      }

      const active = suggestionsBox.querySelector(
        ".suggestion-item.keyboard-active"
      );
      let index = active ? Array.from(items).indexOf(active) : -1;

      if (e.key === "ArrowDown") {
        e.preventDefault();
        index = Math.min(index + 1, items.length - 1);
        items.forEach((item, i) =>
          item.classList.toggle("keyboard-active", i === index)
        );
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        index = Math.max(index - 1, 0);
        items.forEach((item, i) =>
          item.classList.toggle("keyboard-active", i === index)
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

  function recalcTotal() {
  let bruto = 0;
  let descItems = 0;

  // Recalcular por fila (por si cambió qty/costo en edición)
  tbody.querySelectorAll("tr[data-row='item']").forEach((tr) => {
    const subtotal = round2(Number(tr.dataset.subtotal || 0));
    bruto += subtotal;

    // Descuento por ítem (tipo/valor guardado en hidden)
    const hTipo = tr.querySelector('input[name="item_descuento_tipo[]"]');
    const hVal  = tr.querySelector('input[name="item_descuento_valor[]"]');

    const tipo = String(hTipo?.value || "MONTO").toUpperCase();
    let val = Number(hVal?.value || 0);
    if (!Number.isFinite(val) || val < 0) val = 0;

    let dMonto = 0;
    if (subtotal > 0 && val > 0) {
      if (tipo === "PORC") {
        if (val > 100) val = 100;
        dMonto = subtotal * (val / 100);
      } else {
        if (val > subtotal) val = subtotal;
        dMonto = val;
      }
    }

    dMonto = round2(dMonto);

    // Reflejar clamp en hidden
    if (hVal && round2(Number(hVal.value || 0)) !== round2(val)) {
      hVal.value = String(round2(val));
    }

    tr.dataset.descItemMonto = String(dMonto);

    // Pintar celda descuento
    const descCell = tr.querySelector(".desc-item-cell");
    if (descCell) {
      descCell.textContent = dMonto > 0 ? "-" + fmtMoney(dMonto) : fmtMoney(0);
    }

    descItems += dMonto;
  });

  bruto = round2(bruto);
  descItems = round2(descItems);

  const baseGlobal = round2(Math.max(0, bruto - descItems));

  if (totalBrutoLbl) totalBrutoLbl.textContent = fmtMoney(bruto);
  if (descuentoItemsLbl) descuentoItemsLbl.textContent = "-" + fmtMoney(descItems);

  // Descuento global (sobre baseGlobal)
  const tipoG = (descuentoTipo?.value || "MONTO").toUpperCase();
  let valG = Number(descuentoValor?.value || 0);
  if (!Number.isFinite(valG) || valG < 0) valG = 0;

  let descG = 0;
  if (baseGlobal > 0 && valG > 0) {
    if (tipoG === "PORC") {
      if (valG > 100) valG = 100;
      descG = baseGlobal * (valG / 100);
      if (descuentoValor) descuentoValor.max = "100";
    } else {
      if (valG > baseGlobal) valG = baseGlobal;
      descG = valG;
      if (descuentoValor) descuentoValor.max = String(baseGlobal);
    }
  } else {
    if (descuentoValor) descuentoValor.max = tipoG === "PORC" ? "100" : String(baseGlobal);
  }

  // Reflejar clamp global
  if (descuentoValor) {
    const curr = Number(descuentoValor.value || 0);
    if (Number.isFinite(curr) && round2(curr) !== round2(valG)) {
      descuentoValor.value = String(round2(valG));
    }
  }

  descG = round2(descG);
  const totalFinal = round2(Math.max(0, baseGlobal - descG));

  if (descuentoTotalLbl) descuentoTotalLbl.textContent = "-" + fmtMoney(descG);
  if (totalLbl) totalLbl.textContent = fmtMoney(totalFinal);
}

  // FIX: resetForm ahora resetea correctamente el step/min del input
  function resetForm() {
    selectedProduct = null;
    searchInput.value = "";
    inCost.value = "0";
    if (itemDescTipo) itemDescTipo.value = "MONTO";
    if (itemDescValor) itemDescValor.value = "0";
    unitLbl.textContent = "Unidad: UNIDAD";
    
    // Limpiar UI pesable
    const existingBadge = qtyFieldContainer.querySelector('.badge-pesable');
    const existingHelp = qtyFieldContainer.querySelector('.help-pesable');
    if (existingBadge) existingBadge.remove();
    if (existingHelp) existingHelp.remove();
    inQty.classList.remove('input-pesable');
    inQty.placeholder = '';
    
    // FIX: Resetear input cantidad a valores por defecto (unidades enteras)
    inQty.step = "1";
    inQty.min = "1";
    inQty.value = "1";
    inQty.removeEventListener('input', validatePesableInput);
    
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
    cancelText = "Cancelar"
  ) {
    const modal = document.createElement("div");
    modal.className = "modal-overlay compras-modal-overlay";

    modal.innerHTML = `
      <div class="modal-box">
        <p class="modal-message">${msg}</p>
        <div class="modal-actions">
          <button class="btn btn-secondary js-cancel">${cancelText}</button>
          <button class="btn btn-primary js-confirm">${confirmText}</button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);
    setTimeout(() => modal.classList.add("active"), 10);

    const close = () => {
      modal.classList.remove("active");
      setTimeout(() => modal.remove(), 180);
    };

    modal.querySelector(".js-confirm").addEventListener("click", () => {
      onConfirm && onConfirm();
      close();
    });

    modal.querySelector(".js-cancel").addEventListener("click", () => {
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
      { once: true }
    );
  }

  /* ============================================================================
     EDICIÓN INLINE - FIX: Usar AbortController para manejo correcto de listeners
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
      const hiddenName = field === "cantidad" ? "cantidad[]" : "costo_unitario[]";
      const hiddenInput = tr.querySelector(`input[name="${hiddenName}"]`);

      // Ajustes de step/min (importante para evitar stepMismatch en type=number)
      if (field === "cantidad") {
        const pes = isPesable(product);
        editInput.step = pes ? "0.001" : "1";
        editInput.min = pes ? "0.001" : "1";
      } else {
        editInput.step = "0.01";
        editInput.min = "0";
      }

      // Sincronizar valor del editor con el hidden real (source of truth)
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

      // Abrir editor
      openEditor();

      // Focus SOLO en cantidad (primero)
      if (idx === 0) {
        editInput.focus();
        editInput.select?.();
      }

      // FIX: Usar AbortController para limpiar listeners correctamente
      const controller = new AbortController();
      let hasClosed = false;

      const validateValue = (fieldName, v) => {
        if (fieldName === "cantidad") {
          if (!(v > 0)) return "Cantidad inválida";
          if (v > MAX_QTY) return `Cantidad muy alta (máx: ${MAX_QTY.toLocaleString()})`;
        }
        if (fieldName === "costo") {
          if (v < 0) return "Costo inválido";
          if (v > MAX_COST) return `Costo muy alto (máx: ${fmtMoney(MAX_COST)})`;
        }
        return "";
      };

      const commitValue = () => {
        const newValue = parseFloat(editInput.value || 0);
        const err = validateValue(field, newValue);

        // Si es inválido, NO cerrar: permitir corrección inmediata
        if (err) {
          showToast(err, "warning");
          // re-enfocar para corregir
          editInput.focus();
          editInput.select?.();
          return false;
        }

        // Update hidden (lo que realmente se envía)
        if (hiddenInput) hiddenInput.value = newValue;

        // Update UI (span)
        valueSpan.textContent =
          field === "cantidad" ? fmtQty(newValue, product) : fmtMoney(newValue);

        // Recalc subtotal
        const qtyVal = parseFloat(
          tr.querySelector(`input[name="cantidad[]"]`).value || 0
        );
        const costVal = parseFloat(
          tr.querySelector(`input[name="costo_unitario[]"]`).value || 0
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
        // Volver al valor original (hidden real)
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
        { signal: controller.signal }
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
        { signal: controller.signal }
      );
    });
  }


  /* ============================================================================
     CREAR/ELIMINAR FILAS
  ============================================================================ */
  function createNewRow(product, qty, cost, dTipo = 'MONTO', dVal = 0) {
    removeEmptyRow();

    qty = Number(qty);
    cost = Number(cost);

    const pes = isPesable(product);
    const qtyStep = pes ? "0.001" : "1";
    const qtyMin  = pes ? "0.001" : "1";


    const subtotal = round2(qty * cost);

    // Descuento por ítem
    dTipo = String(dTipo || "MONTO").toUpperCase();
    if (dTipo !== "PORC" && dTipo !== "MONTO") dTipo = "MONTO";
    dVal = Number(dVal);
    if (!Number.isFinite(dVal) || dVal < 0) dVal = 0;

    let dMonto = 0;
    if (subtotal > 0 && dVal > 0) {
      if (dTipo === "PORC") {
        if (dVal > 100) dVal = 100;
        dMonto = subtotal * (dVal / 100);
      } else {
        if (dVal > subtotal) dVal = subtotal;
        dMonto = dVal;
      }
    }
    dMonto = round2(dMonto);

    const rowId = autoIdCounter++;

    const tr = document.createElement("tr");
    tr.dataset.row = "item";
    tr.dataset.rowId = String(rowId);
    tr.dataset.subtotal = String(subtotal);
    tr.dataset.descItemMonto = String(dMonto);
    tr.classList.add("fade-in");

    tr.innerHTML = `
      <td>
        <div class="item-name">${product.nombre}</div>
        <div class="item-code">${product.codigo}</div>
      </td>
      <td class="right editable-cell" data-field="cantidad">
        <span class="cell-value">${fmtQty(qty, product)}</span>
        <input type="number" class="cell-edit" value="${qty}" step="${qtyStep}" min="${qtyMin}" disabled style="display:none;">
      </td>
      <td class="right editable-cell" data-field="costo">
        <span class="cell-value">${fmtMoney(cost)}</span>
        <input type="number" class="cell-edit" value="${cost}" step="0.01" min="0" disabled style="display:none;">
      </td>
      <td class="right desc-item-cell">${dMonto > 0 ? "-" + fmtMoney(dMonto) : fmtMoney(0)}</td>
      <td class="right subtotal-cell">${fmtMoney(subtotal)}</td>
      <td class="center">
        <button type="button" class="btn-icon" title="Editar" data-action="edit">
          ✏️
        </button>
        <button type="button" class="btn-icon btn-icon-danger" title="Eliminar" data-action="delete">
          🗑️
        </button>

        <input type="hidden" name="producto_id[]" value="${product.id}">
        <input type="hidden" name="cantidad[]" value="${qty}">
        <input type="hidden" name="costo_unitario[]" value="${cost}">
        <input type="hidden" name="item_descuento_tipo[]" value="${dTipo}">
        <input type="hidden" name="item_descuento_valor[]" value="${dVal}">
      </td>
    `;

    tr.querySelector("[data-action='edit']").addEventListener("click", () =>
      enableEditMode(tr, product)
    );
    tr.querySelector("[data-action='delete']").addEventListener("click", () =>
      deleteRow(tr, rowId)
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
     AGREGAR ITEM (con validaciones mejoradas)
  ============================================================================ */
  function addItem() {
    if (!selectedProduct) {
      showToast("Seleccioná un producto primero", "warning");
      searchInput.focus();
      return;
    }

    const qty = parseFloat(inQty.value || 0);
    const cost = parseFloat(inCost.value || 0);

    const dTipo = String(itemDescTipo?.value || 'MONTO').toUpperCase();
    let dVal = parseFloat(itemDescValor?.value || 0);
    if (!Number.isFinite(dVal) || dVal < 0) dVal = 0;

    // Validaciones básicas
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

    // Validaciones de rangos
    if (qty > MAX_QTY) {
      showToast(`Cantidad muy alta (máximo: ${MAX_QTY.toLocaleString()})`, "warning");
      inQty.focus();
      return;
    }
    if (cost > MAX_COST) {
      showToast(`Costo muy alto (máximo: ${fmtMoney(MAX_COST)})`, "warning");
      inCost.focus();
      return;
    }

    // Producto duplicado
    const existing = itemsAdded.find(
      (it) => it.productId === selectedProduct.id
    );
    
    if (existing) {
      showConfirm(
        "Este producto ya está en la lista. ¿Sumar cantidad en la línea existente?",
        () => {
          // Sumar en la primer ocurrencia
          const tr = tbody.querySelector(`tr[data-row-id="${existing.rowId}"]`);
          if (!tr) return createNewRow(selectedProduct, qty, cost, dTipo, dVal);

          const hiddenQty = tr.querySelector(`input[name="cantidad[]"]`);
          const hiddenCost = tr.querySelector(`input[name="costo_unitario[]"]`);

          const newQty = parseFloat(hiddenQty.value || 0) + qty;
          
          // Validar nueva cantidad
          if (newQty > MAX_QTY) {
            showToast(`La suma superaría el máximo permitido (${MAX_QTY.toLocaleString()})`, "warning");
            return;
          }

          hiddenQty.value = newQty;
          hiddenCost.value = cost;

          tr.querySelector(
            ".editable-cell[data-field='cantidad'] .cell-value"
          ).textContent = fmtQty(newQty, selectedProduct);
          tr.querySelector(
            ".editable-cell[data-field='costo'] .cell-value"
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
        "Agregar nueva línea"
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


// Descuento: recalcular en vivo
[descuentoTipo, descuentoValor].forEach((el) => {
  if (!el) return;
  el.addEventListener("input", () => {
    hasUnsavedChanges = true;
    recalcTotal();
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
      // Si se envía el form, desactivar la advertencia de salida
      hasUnsavedChanges = false;
    });
  }

  /* ============================================================================
     CARGAR ITEMS EN MODO EDICIÓN
  ============================================================================ */
  const preloadedItems = tbody.querySelectorAll('.preloaded-item');
  if (preloadedItems.length > 0) {
    removeEmptyRow();
    
    preloadedItems.forEach(stub => {
      const productId = parseInt(stub.dataset.productoId);
      const qty = parseFloat(stub.dataset.cantidad);
      const cost = parseFloat(stub.dataset.costo);
      const descTipo = (stub.dataset.descTipo || 'MONTO').toUpperCase();
      const descValRaw = parseFloat(stub.dataset.descValor || '0');
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
        unidad: unidad
      };

      const pes2 = isPesable(productMock);
      const qtyStep2 = pes2 ? "0.001" : "1";
      const qtyMin2  = pes2 ? "0.001" : "1";

  
      const subtotal = round2(qty * cost);
      let dMonto = 0;
      let dVal = descVal;
      if (dVal < 0) dVal = 0;
      if (subtotal > 0 && dVal > 0) {
        if (descTipo === 'PORC') {
          if (dVal > 100) dVal = 100;
          dMonto = subtotal * (dVal / 100);
        } else {
          if (dVal > subtotal) dVal = subtotal;
          dMonto = dVal;
        }
      }
      dMonto = round2(dMonto);

      const rowId = autoIdCounter++;
      
      const tr = document.createElement("tr");
      tr.dataset.row = "item";
      tr.dataset.rowId = String(rowId);
      tr.dataset.subtotal = String(subtotal);
      tr.dataset.descItemMonto = String(dMonto);
      
      tr.innerHTML = `
        <td>
          <div class="item-name">${nombre}</div>
          <div class="item-code">${codigo}</div>
        </td>
        <td class="right editable-cell" data-field="cantidad">
          <span class="cell-value">${fmtQty(qty, productMock)}</span>
          <input type="number" class="cell-edit" value="${qty}" step="${qtyStep2}" min="${qtyMin2}" disabled style="display:none;">
        </td>
        <td class="right editable-cell" data-field="costo">
          <span class="cell-value">${fmtMoney(cost)}</span>
          <input type="number" class="cell-edit" value="${cost}" step="0.01" min="0" disabled style="display:none;">
        </td>
        <td class="right desc-item-cell">${dMonto > 0 ? "-" + fmtMoney(dMonto) : fmtMoney(0)}</td>
        <td class="right subtotal-cell">${fmtMoney(subtotal)}</td>
        <td class="center">
          <button type="button" class="btn-icon" title="Editar" data-action="edit">
            ✏️
          </button>
          <button type="button" class="btn-icon btn-icon-danger" title="Eliminar" data-action="delete">
            🗑️
          </button>

          <input type="hidden" name="producto_id[]" value="${productId}">
          <input type="hidden" name="cantidad[]" value="${qty}">
          <input type="hidden" name="costo_unitario[]" value="${cost}">
          <input type="hidden" name="item_descuento_tipo[]" value="${descTipo}">
          <input type="hidden" name="item_descuento_valor[]" value="${dVal}">
        </td>
      `;
      
      tr.querySelector("[data-action='edit']").addEventListener("click", () =>
        enableEditMode(tr, productMock)
      );
      tr.querySelector("[data-action='delete']").addEventListener("click", () =>
        deleteRow(tr, rowId)
      );
      
      tbody.appendChild(tr);
      itemsAdded.push({ rowId, productId });
      
      stub.remove();
    });
    
    recalcTotal();
  }

  /* ============================================================================
     FIX: PREVENIR PÉRDIDA DE DATOS - beforeunload
  ============================================================================ */
  window.addEventListener('beforeunload', (e) => {
    if (hasUnsavedChanges && itemsAdded.length > 0) {
      e.preventDefault();
      e.returnValue = ''; // Necesario para Chrome
      return ''; // Necesario para otros navegadores
    }
  });

  /* ============================================================================
     INICIALIZACIÓN
  ============================================================================ */
  if (searchInput) searchInput.focus();
  addEmptyRowIfNeeded();
  recalcTotal();
});

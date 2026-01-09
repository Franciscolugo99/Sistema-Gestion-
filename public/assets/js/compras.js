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

  if (!tbody) return;

  let productosData = [];
  let selectedProduct = null;
  let itemsAdded = [];
  let autoIdCounter = Date.now();

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
      inQty.removeEventListener('input', validatePesableInput);
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
      }, 250);
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
        '<tr class="empty-row"><td colspan="5" class="empty-cell">Todavía no agregaste ítems.</td></tr>';
    }
  }

  function recalcTotal() {
    let total = 0;
    tbody.querySelectorAll("tr[data-row='item']").forEach((tr) => {
      total += Number(tr.dataset.subtotal || 0);
    });
    total = round2(total);
    if (totalLbl) totalLbl.textContent = fmtMoney(total);
  }

  function resetForm() {
    selectedProduct = null;
    searchInput.value = "";
    inQty.value = "1";
    inCost.value = "0";
    unitLbl.textContent = "Unidad: UNIDAD";
    
    // Limpiar UI pesable
    const existingBadge = qtyFieldContainer.querySelector('.badge-pesable');
    const existingHelp = qtyFieldContainer.querySelector('.help-pesable');
    if (existingBadge) existingBadge.remove();
    if (existingHelp) existingHelp.remove();
    inQty.classList.remove('input-pesable');
    inQty.placeholder = '';
    
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
     EDICIÓN INLINE
  ============================================================================ */
  function enableEditMode(tr, product) {
    const qtyCell = tr.querySelector(".editable-cell[data-field='cantidad']");
    const costCell = tr.querySelector(".editable-cell[data-field='costo']");
    const cells = [qtyCell, costCell].filter(Boolean);

    cells.forEach((cell, idx) => {
      const valueSpan = cell.querySelector(".cell-value");
      const editInput = cell.querySelector(".cell-edit");

      // Ajustes de step
      if (cell.dataset.field === "cantidad") {
        const pes = isPesable(product);
        editInput.step = pes ? "0.001" : "1";
        editInput.min = pes ? "0.001" : "1";
      } else {
        editInput.step = "0.01";
        editInput.min = "0";
      }

      valueSpan.style.display = "none";
      editInput.style.display = "block";

      // Focus SOLO en cantidad (primero)
      if (idx === 0) {
        editInput.focus();
        editInput.select();
      }

      let canceled = false;

      const saveEdit = () => {
        if (canceled) return;

        const field = cell.dataset.field;
        const newValue = parseFloat(editInput.value || 0);

        // Validación
        if (field === "cantidad") {
          if (!(newValue > 0)) {
            showToast("Cantidad inválida", "warning");
            return;
          }
          if (newValue > MAX_QTY) {
            showToast(`Cantidad muy alta (máx: ${MAX_QTY.toLocaleString()})`, "warning");
            return;
          }
        }
        
        if (field === "costo") {
          if (newValue < 0) {
            showToast("Costo inválido", "warning");
            return;
          }
          if (newValue > MAX_COST) {
            showToast(`Costo muy alto (máx: ${fmtMoney(MAX_COST)})`, "warning");
            return;
          }
        }

        // Update hidden
        const hiddenName =
          field === "cantidad" ? "cantidad[]" : "costo_unitario[]";
        const hiddenInput = tr.querySelector(`input[name="${hiddenName}"]`);
        hiddenInput.value = newValue;

        // Update UI
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

        valueSpan.style.display = "inline";
        editInput.style.display = "none";

        recalcTotal();
        showToast("Item actualizado", "success");
      };

      editInput.addEventListener("blur", saveEdit, { once: true });
      editInput.addEventListener(
        "keydown",
        (e) => {
          if (e.key === "Enter") {
            e.preventDefault();
            saveEdit();
          } else if (e.key === "Escape") {
            canceled = true;
            valueSpan.style.display = "inline";
            editInput.style.display = "none";
          }
        },
        { once: true }
      );
    });
  }

  /* ============================================================================
     CREAR/ELIMINAR FILAS
  ============================================================================ */
  function createNewRow(product, qty, cost) {
    removeEmptyRow();

    qty = Number(qty);
    cost = Number(cost);

    const subtotal = round2(qty * cost);
    const rowId = autoIdCounter++;

    const tr = document.createElement("tr");
    tr.dataset.row = "item";
    tr.dataset.rowId = String(rowId);
    tr.dataset.subtotal = String(subtotal);
    tr.classList.add("fade-in");

    tr.innerHTML = `
      <td>
        <div class="item-name">${product.nombre}</div>
        <div class="item-code">${product.codigo}</div>
      </td>
      <td class="right editable-cell" data-field="cantidad">
        <span class="cell-value">${fmtQty(qty, product)}</span>
        <input type="number" class="cell-edit" value="${qty}" style="display:none;">
      </td>
      <td class="right editable-cell" data-field="costo">
        <span class="cell-value">${fmtMoney(cost)}</span>
        <input type="number" class="cell-edit" value="${cost}" style="display:none;">
      </td>
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
          if (!tr) return createNewRow(selectedProduct, qty, cost);

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

          recalcTotal();
          resetForm();
          showToast("Item actualizado", "success");
        },
        () => {
          createNewRow(selectedProduct, qty, cost);
        },
        "Sumar",
        "Agregar nueva línea"
      );
      return;
    }

    createNewRow(selectedProduct, qty, cost);
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

  if (form) {
    form.addEventListener("submit", (e) => {
      if (!tbody.querySelector("tr[data-row='item']")) {
        e.preventDefault();
        showToast("Agregá al menos 1 ítem a la compra", "warning");
      }
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
      
      const subtotal = round2(qty * cost);
      const rowId = autoIdCounter++;
      
      const tr = document.createElement("tr");
      tr.dataset.row = "item";
      tr.dataset.rowId = String(rowId);
      tr.dataset.subtotal = String(subtotal);
      
      tr.innerHTML = `
        <td>
          <div class="item-name">${nombre}</div>
          <div class="item-code">${codigo}</div>
        </td>
        <td class="right editable-cell" data-field="cantidad">
          <span class="cell-value">${fmtQty(qty, productMock)}</span>
          <input type="number" class="cell-edit" value="${qty}" style="display:none;">
        </td>
        <td class="right editable-cell" data-field="costo">
          <span class="cell-value">${fmtMoney(cost)}</span>
          <input type="number" class="cell-edit" value="${cost}" style="display:none;">
        </td>
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
     INICIALIZACIÓN
  ============================================================================ */
  if (searchInput) searchInput.focus();
  addEmptyRowIfNeeded();
  recalcTotal();
});
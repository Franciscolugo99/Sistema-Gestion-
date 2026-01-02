document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("compraForm");
  const searchInput = document.getElementById("itemBuscar");
  const suggestionsBox = document.getElementById("suggestions");
  const inQty = document.getElementById("itemCantidad");
  const inCost = document.getElementById("itemCosto");
  const unitLbl = document.getElementById("itemUnidad");
  const btnAdd = document.getElementById("btnAddItem");
  const table = document.getElementById("itemsTable");
  const tbody = table?.querySelector("tbody");
  const totalLbl = document.getElementById("totalLbl");

  if (!tbody) return;

  let productosData = [];
  let selectedProduct = null;

  // Track rows (permite múltiples líneas del mismo producto si querés)
  let itemsAdded = []; // { rowId, productId }

  /* -----------------------------
    Utils
  ------------------------------ */
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

  function applyQtyInputRules(product) {
    const pes = isPesable(product);
    inQty.step = pes ? "0.001" : "1";
    inQty.min = pes ? "0.001" : "1";
    if (!inQty.value || Number(inQty.value) <= 0)
      inQty.value = pes ? "1.000" : "1";
  }

  /* -----------------------------
    Load products from DOM
  ------------------------------ */
  function loadProducts() {
    const select = document.getElementById("productosData");
    if (!select) return;

    productosData = [];
    Array.from(select.options).forEach((opt) => {
      if (!opt.value) return;

      // opt.textContent viene como "Nombre (COD)" desde PHP
      // lo limpiamos para que "nombre" sea solo el nombre real:
      const rawText = opt.textContent.trim();
      const cleanName = rawText.replace(/\s*\([^)]*\)\s*$/, "");

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

  /* -----------------------------
    Autocomplete
  ------------------------------ */
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

    // Set rules for qty
    applyQtyInputRules(product);

    // Prefill cost
    if (product.ultimoCosto > 0) {
      inCost.value = round2(product.ultimoCosto).toFixed(2);
    }

    unitLbl.textContent = `Unidad: ${product.unidad}`;

    inQty.focus();
    inQty.select();
  }

  if (searchInput && suggestionsBox) {
    let debounceTimer;

    searchInput.addEventListener("input", (e) => {
      // Importante: si el usuario cambia el texto, invalidamos la selección previa
      selectedProduct = null;
      unitLbl.textContent = "Unidad: UNIDAD";
      applyQtyInputRules({ esPesable: 0, unidad: "UNIDAD" });

      clearTimeout(debounceTimer);
      const query = e.target.value.trim().toLowerCase();

      if (query.length < 2) {
        suggestionsBox.classList.remove("active");
        suggestionsBox.innerHTML = "";
        return;
      }

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
      }
    });

    searchInput.addEventListener("keydown", (e) => {
      const items = suggestionsBox.querySelectorAll(
        ".suggestion-item[data-id]"
      );
      if (!items.length) return;

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
        if (target) selectProduct(parseInt(target.dataset.id, 10));
      }
    });
  }

  /* -----------------------------
    Rows
  ------------------------------ */
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
    searchInput.focus();
  }

  function showToast(msg, type = "info") {
    if (window.showToast) {
      window.showToast(msg, type);
      return;
    }
    const toast = document.createElement("div");
    toast.className = "toast compras-toast toast-success show";

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

        // Validación: qty > 0, costo >= 0
        if (field === "cantidad" && !(newValue > 0)) {
          showToast("Cantidad inválida", "warning");
          return;
        }
        if (field === "costo" && newValue < 0) {
          showToast("Costo inválido", "warning");
          return;
        }

        // Update hidden
        const hiddenName =
          field === "cantidad" ? "cantidad[]" : "costo_unitario[]";
        const hiddenInput = tr.querySelector(`input[name="${hiddenName}"]`);
        hiddenInput.value = newValue;

        // Update UI
        valueSpan.textContent =
          field === "cantidad" ? fmtQty(newValue, product) : fmtMoney(newValue);

        // Recalc subtotal (redondeado)
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

  function createNewRow(product, qty, cost) {
    removeEmptyRow();

    qty = Number(qty);
    cost = Number(cost);

    const subtotal = round2(qty * cost);
    const rowId = Date.now() + Math.floor(Math.random() * 1000);

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

        <!-- Hidden inputs (válidos dentro de td) -->
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

  function addItem() {
    if (!selectedProduct) {
      showToast("Seleccioná un producto primero", "warning");
      searchInput.focus();
      return;
    }

    const qty = parseFloat(inQty.value || 0);
    const cost = parseFloat(inCost.value || 0);

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

    // Si ya existe ese producto, te pregunto si querés sumar o crear línea nueva
    const existing = itemsAdded.find(
      (it) => it.productId === selectedProduct.id
    );
    if (existing) {
      showConfirm(
        "Este producto ya está en la lista. ¿Sumar cantidad en la línea existente?",
        () => {
          // sumar en la primer ocurrencia
          const tr = tbody.querySelector(`tr[data-row-id="${existing.rowId}"]`);
          if (!tr) return createNewRow(selectedProduct, qty, cost);

          const hiddenQty = tr.querySelector(`input[name="cantidad[]"]`);
          const hiddenCost = tr.querySelector(`input[name="costo_unitario[]"]`);

          const newQty = parseFloat(hiddenQty.value || 0) + qty;
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

  if (searchInput) searchInput.focus();
});

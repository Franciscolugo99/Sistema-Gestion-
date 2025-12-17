document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("compraForm");
  const selProd = document.getElementById("itemProducto");
  const inQty = document.getElementById("itemCantidad");
  const inCost = document.getElementById("itemCosto");
  const unitLbl = document.getElementById("itemUnidad");
  const btnAdd = document.getElementById("btnAddItem");
  const table = document.getElementById("itemsTable");
  const tbody = table ? table.querySelector("tbody") : null;
  const totalLbl = document.getElementById("totalLbl");

  if (!form || !selProd || !inQty || !inCost || !btnAdd || !tbody || !totalLbl) return;

  function fmtMoney(n) {
    const v = Number(n || 0);
    return "$" + v.toLocaleString("es-AR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function fmtQty(qty, esPesable, unidad) {
    const n = Number(qty || 0);
    if (Number.isNaN(n)) return String(qty || "");
    const isPes = Number(esPesable) === 1 || ["KG","G","LT","ML"].includes(String(unidad || "").toUpperCase());
    return isPes
      ? n.toLocaleString("es-AR", { minimumFractionDigits: 3, maximumFractionDigits: 3 })
      : n.toLocaleString("es-AR", { minimumFractionDigits: 0, maximumFractionDigits: 0 });
  }

  function updateUnitLabel() {
    const opt = selProd.selectedOptions[0];
    const unidad = (opt?.dataset?.unidad || "UNIDAD").toUpperCase();
    unitLbl.textContent = "Unidad: " + unidad;
  }
  selProd.addEventListener("change", updateUnitLabel);
  updateUnitLabel();

  function recalcTotal() {
    let total = 0;
    tbody.querySelectorAll("tr[data-row='item']").forEach((tr) => {
      total += Number(tr.dataset.subtotal || 0);
    });
    totalLbl.textContent = fmtMoney(total);
  }

  function removeEmptyRow() {
    const empty = tbody.querySelector(".empty-row");
    if (empty) empty.remove();
  }

  btnAdd.addEventListener("click", () => {
    const pid = Number(selProd.value || 0);
    if (!pid) return;

    const opt = selProd.selectedOptions[0];
    const nombre = opt?.textContent?.trim() || "Producto";
    const esPesable = Number(opt?.dataset?.esPesable || 0);
    const unidad = (opt?.dataset?.unidad || "UNIDAD").toUpperCase();

    const qty = Number(inQty.value || 0);
    const cost = Number(inCost.value || 0);

    if (!(qty > 0)) return;
    if (cost < 0) return;

    removeEmptyRow();

    const subtotal = qty * cost;

    const tr = document.createElement("tr");
    tr.dataset.row = "item";
    tr.dataset.subtotal = String(subtotal);

    tr.innerHTML = `
      <td>${nombre}</td>
      <td class="right">${fmtQty(qty, esPesable, unidad)}</td>
      <td class="right">${fmtMoney(cost)}</td>
      <td class="right">${fmtMoney(subtotal)}</td>
      <td class="center">
        <button type="button" class="btn-line js-del">Quitar</button>
      </td>

      <input type="hidden" name="producto_id[]" value="${pid}">
      <input type="hidden" name="cantidad[]" value="${qty}">
      <input type="hidden" name="costo_unitario[]" value="${cost}">
    `;

    tr.querySelector(".js-del").addEventListener("click", () => {
      tr.remove();
      if (!tbody.querySelector("tr[data-row='item']")) {
        tbody.innerHTML = `<tr class="empty-row"><td colspan="5" class="empty-cell">Todavía no agregaste ítems.</td></tr>`;
      }
      recalcTotal();
    });

    tbody.appendChild(tr);
    recalcTotal();
  });

  form.addEventListener("submit", (e) => {
    if (!tbody.querySelector("tr[data-row='item']")) {
      e.preventDefault();
      if (window.showToast) window.showToast("Agregá al menos 1 ítem.");
    }
  });
});

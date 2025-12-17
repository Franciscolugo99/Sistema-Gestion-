// assets/js/promo_combo_form.js

document.addEventListener("DOMContentLoaded", () => {
  const btnAdd = document.querySelector("#btn-add-item");
  const tbody = document.querySelector("#tabla-items-combo tbody");
  const form = document.querySelector("form.promo-form");

  if (!btnAdd || !tbody) return;

  // -------------------------
  // Helpers
  // -------------------------
  function qs(row, sel) {
    return row ? row.querySelector(sel) : null;
  }

  function getRows() {
    return Array.from(tbody.querySelectorAll("tr"));
  }

  function normalizeMoneyAr(str) {
    // Soporta "1.234,56" / "1234.56" / "$ 1.234,56"
    const s = String(str || "")
      .trim()
      .replace(/[^0-9,.\-]/g, "");
    if (!s) return 0;

    if (s.includes(",")) {
      // AR: 1.234,56
      const t = s.replace(/\./g, "").replace(",", ".");
      const n = Number(t);
      return Number.isFinite(n) ? n : 0;
    }

    const n = Number(s);
    return Number.isFinite(n) ? n : 0;
  }

  function isValidYmd(s) {
    // YYYY-MM-DD
    if (!s) return true; // opcional
    if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return false;
    const d = new Date(s + "T00:00:00");
    return !Number.isNaN(d.getTime());
  }

  function showError(msg) {
    // Si tenés alertas lindas en CSS, después lo cambiamos a toast
    alert(msg);
  }

  function refreshDuplicateHints() {
    // Marca duplicados en selects (visual liviano, sin CSS nuevo)
    const rows = getRows();
    const seen = new Map(); // pid -> count

    rows.forEach((r) => {
      const sel = qs(r, 'select[name="item_producto_id[]"]');
      if (!sel) return;
      const pid = Number(sel.value || 0);
      if (pid > 0) seen.set(pid, (seen.get(pid) || 0) + 1);
    });

    rows.forEach((r) => {
      const sel = qs(r, 'select[name="item_producto_id[]"]');
      if (!sel) return;
      const pid = Number(sel.value || 0);
      if (pid > 0 && (seen.get(pid) || 0) > 1) {
        sel.title = "Producto repetido (se sumará en el backend).";
        sel.style.outline = "2px solid rgba(239,68,68,.6)";
      } else {
        sel.title = "";
        sel.style.outline = "";
      }
    });
  }

  // -------------------------
  // Agregar fila
  // -------------------------
  btnAdd.addEventListener("click", () => {
    const firstRow = tbody.querySelector("tr");
    if (!firstRow) return;

    const clone = firstRow.cloneNode(true);

    // Limpiar valores
    const select = qs(clone, 'select[name="item_producto_id[]"]');
    const input = qs(clone, 'input[name="item_cantidad[]"]');

    if (select) select.value = "";
    if (input) input.value = "1";

    tbody.appendChild(clone);
    refreshDuplicateHints();
  });

  // -------------------------
  // Quitar fila (delegado)
  // -------------------------
  tbody.addEventListener("click", (event) => {
    const btn = event.target.closest(".btn-remove-item");
    if (!btn) return;

    const rows = getRows();
    if (rows.length <= 1) {
      // Dejar al menos 1 fila
      const r0 = rows[0];
      const sel = qs(r0, 'select[name="item_producto_id[]"]');
      const inp = qs(r0, 'input[name="item_cantidad[]"]');
      if (sel) sel.value = "";
      if (inp) inp.value = "1";
      refreshDuplicateHints();
      return;
    }

    const tr = btn.closest("tr");
    if (tr) tr.remove();
    refreshDuplicateHints();
  });

  // -------------------------
  // Marcar duplicados al cambiar selects
  // -------------------------
  tbody.addEventListener("change", (event) => {
    const sel = event.target.closest('select[name="item_producto_id[]"]');
    if (!sel) return;
    refreshDuplicateHints();
  });

  // -------------------------
  // Validaciones antes de enviar
  // -------------------------
  form?.addEventListener("submit", (e) => {
    // Nombre
    const nombre = document.querySelector('input[name="nombre"]')?.value?.trim() || "";
    if (nombre.length < 1) {
      e.preventDefault();
      showError("El nombre del combo es obligatorio.");
      return;
    }

    // Precio combo
    const precioStr = document.querySelector('input[name="precio_combo"]')?.value || "";
    const precio = normalizeMoneyAr(precioStr);
    if (!(precio > 0)) {
      e.preventDefault();
      showError("El precio del combo debe ser mayor que 0.");
      return;
    }

    // Fechas
    const fi = document.querySelector('input[name="fecha_inicio"]')?.value || "";
    const ff = document.querySelector('input[name="fecha_fin"]')?.value || "";
    if (!isValidYmd(fi)) {
      e.preventDefault();
      showError("Fecha inicio inválida.");
      return;
    }
    if (!isValidYmd(ff)) {
      e.preventDefault();
      showError("Fecha fin inválida.");
      return;
    }
    if (fi && ff && fi > ff) {
      e.preventDefault();
      showError('La fecha "Desde" no puede ser mayor que "Hasta".');
      return;
    }

    // Items
    const rows = getRows();
    let okItems = 0;

    for (const r of rows) {
      const sel = qs(r, 'select[name="item_producto_id[]"]');
      const inp = qs(r, 'input[name="item_cantidad[]"]');
      const pid = Number(sel?.value || 0);
      const cant = Number(inp?.value || 0);

      // Si está incompleto, frenamos (evita submit con filas vacías)
      if (pid <= 0) {
        e.preventDefault();
        showError("Hay una fila sin producto. Elegí un producto o quitá la fila.");
        return;
      }
      if (!(cant > 0)) {
        e.preventDefault();
        showError("Hay una fila con cantidad inválida. Debe ser mayor a 0.");
        return;
      }

      okItems++;
    }

    if (okItems <= 0) {
      e.preventDefault();
      showError("El combo debe tener al menos 1 producto.");
      return;
    }

    // Nota: si hay duplicados, el backend los agrupa (lo marcamos visualmente)
  });

  // Estado inicial duplicados
  refreshDuplicateHints();
});

// public/assets/js/promo_combo_form.js
document.addEventListener("DOMContentLoaded", () => {
  const btnAdd = document.querySelector("#btn-add-item");
  const tbody = document.querySelector("#tabla-items-combo tbody");
  const form = document.querySelector("form.promo-form");
  const comboPrecio = document.querySelector('input[name="precio_combo"]');

  if (!btnAdd || !tbody) return;

  function qs(row, sel) {
    return row ? row.querySelector(sel) : null;
  }

  function getRows() {
    return Array.from(tbody.querySelectorAll("tr"));
  }

  function normalizeMoneyAr(str) {
    const s = String(str || "")
      .trim()
      .replace(/[^0-9,.\-]/g, "");
    if (!s) return 0;

    if (s.includes(",")) {
      const t = s.replace(/\./g, "").replace(",", ".");
      const n = Number(t);
      return Number.isFinite(n) ? n : 0;
    }

    const n = Number(s);
    return Number.isFinite(n) ? n : 0;
  }

  function isValidYmd(s) {
    if (!s) return true;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return false;
    const d = new Date(s + "T00:00:00");
    return !Number.isNaN(d.getTime());
  }

  function showError(msg) {
    alert(msg);
  }

  function refreshDuplicateHints() {
    const rows = getRows();
    const seen = new Map();

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

  function calcularAhorroCombo() {
    const precioCombo = normalizeMoneyAr(comboPrecio?.value || "0");
    const rows = getRows();

    let precioNormal = 0;

    for (const row of rows) {
      const sel = qs(row, 'select[name="item_producto_id[]"]');
      const inp = qs(row, 'input[name="item_cantidad[]"]');

      const option = sel?.selectedOptions[0];
      const precio = Number(option?.dataset.precio || 0);
      const cant = Number(inp?.value || 0);

      precioNormal += precio * cant;
    }

    const ahorro = precioNormal - precioCombo;
    const ahorroPct = precioNormal > 0 ? (ahorro / precioNormal) * 100 : 0;

    let previewEl = document.getElementById("combo-preview");

    if (!previewEl) {
      const container = comboPrecio?.closest(".field");
      if (container) {
        previewEl = document.createElement("div");
        previewEl.id = "combo-preview";
        previewEl.className = "combo-preview";
        container.appendChild(previewEl);
      }
    }

    if (previewEl && ahorro > 0) {
      previewEl.innerHTML = `
        <div class="alert alert-success">
          <div>
            <strong>Ahorro del combo:</strong> $${ahorro.toFixed(2)} (${ahorroPct.toFixed(1)}%)
            <br><small>Precio normal: $${precioNormal.toFixed(2)} - Precio combo: $${precioCombo.toFixed(2)}</small>
          </div>
        </div>
      `;
    } else if (previewEl && precioCombo > 0 && precioNormal > 0) {
      previewEl.innerHTML = `
        <div class="alert alert-warning">
          <div>
            <strong>Atención:</strong> El precio del combo es mayor o igual al precio normal.
            <br><small>No hay ahorro para el cliente.</small>
          </div>
        </div>
      `;
    } else if (previewEl) {
      previewEl.innerHTML = "";
    }
  }

  btnAdd.addEventListener("click", () => {
    const firstRow = tbody.querySelector("tr");
    if (!firstRow) return;

    const clone = firstRow.cloneNode(true);

    const select = qs(clone, 'select[name="item_producto_id[]"]');
    const input = qs(clone, 'input[name="item_cantidad[]"]');

    if (select) select.value = "";
    if (input) input.value = "1";

    tbody.appendChild(clone);
    refreshDuplicateHints();
    calcularAhorroCombo();
  });

  tbody.addEventListener("click", (event) => {
    const btn = event.target.closest(".btn-remove-item");
    if (!btn) return;

    const rows = getRows();
    if (rows.length <= 1) {
      const r0 = rows[0];
      const sel = qs(r0, 'select[name="item_producto_id[]"]');
      const inp = qs(r0, 'input[name="item_cantidad[]"]');
      if (sel) sel.value = "";
      if (inp) inp.value = "1";
      refreshDuplicateHints();
      calcularAhorroCombo();
      return;
    }

    const tr = btn.closest("tr");
    if (tr) tr.remove();
    refreshDuplicateHints();
    calcularAhorroCombo();
  });

  tbody.addEventListener("change", (event) => {
    const sel = event.target.closest('select[name="item_producto_id[]"]');
    if (sel) refreshDuplicateHints();
    calcularAhorroCombo();
  });

  tbody.addEventListener("input", (event) => {
    const inp = event.target.closest('input[name="item_cantidad[]"]');
    if (inp) calcularAhorroCombo();
  });

  comboPrecio?.addEventListener("input", calcularAhorroCombo);

  form?.addEventListener("submit", (e) => {
    const nombre = document.querySelector('input[name="nombre"]')?.value?.trim() || "";
    if (nombre.length < 1) {
      e.preventDefault();
      showError("El nombre del combo es obligatorio.");
      return;
    }

    const precioStr = document.querySelector('input[name="precio_combo"]')?.value || "";
    const precio = normalizeMoneyAr(precioStr);
    if (!(precio > 0)) {
      e.preventDefault();
      showError("El precio del combo debe ser mayor que 0.");
      return;
    }

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

    const rows = getRows();
    let okItems = 0;

    for (const r of rows) {
      const sel = qs(r, 'select[name="item_producto_id[]"]');
      const inp = qs(r, 'input[name="item_cantidad[]"]');
      const pid = Number(sel?.value || 0);
      const cant = Number(inp?.value || 0);

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
  });

  refreshDuplicateHints();
  calcularAhorroCombo();
});
/**
 * public/assets/js/promo_combo_form.js v3
 * - Usa toast en lugar de alert
 * - Duplicados: marca + fusiona al guardar
 * - Preview ahorro con colores
 * - Validaciones
 */
(function () {
  "use strict";

  const CONFIG = {
    minCantidad: 0.001,
    maxCantidad: 9999.999,
    decimalesCantidad: 3,
  };

  let el = {};

  function qs(parent, selector) {
    return parent ? parent.querySelector(selector) : null;
  }

  function initElements() {
    el = {
      btnAdd: document.querySelector("#btn-add-item"),
      tbody: document.querySelector("#tabla-items-combo tbody"),
      form: document.querySelector("form.promo-form"),
      comboPrecio: document.querySelector('input[name="precio_combo"]'),
      fechaInicio: document.querySelector('input[name="fecha_inicio"]'),
      fechaFin: document.querySelector('input[name="fecha_fin"]'),
      nombrePromo: document.querySelector('input[name="nombre"]'),
      preview: document.querySelector("#combo-preview"),
      toast: document.querySelector("#formToast"),
    };
    return el.btnAdd && el.tbody && el.form;
  }

  function getRows() {
    return Array.from(el.tbody.querySelectorAll("tr"));
  }

  function normalizeMoneyAr(str) {
    const s = String(str || "").trim().replace(/[^0-9,.\-]/g, "");
    if (!s) return 0;
    if (s.includes(",")) return Number(s.replace(/\./g, "").replace(",", ".")) || 0;
    return Number(s) || 0;
  }

  function isValidYmd(dateStr) {
    if (!dateStr) return true;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) return false;
    const d = new Date(dateStr + "T00:00:00");
    return !Number.isNaN(d.getTime());
  }

  // Toast en lugar de alert
  function toast(msg, type = "info") {
    if (!el.toast) {
      // Fallback a alert si no hay toast
      alert(msg);
      return;
    }
    
    el.toast.textContent = msg;
    el.toast.className = `form-toast show toast-${type}`;
    
    setTimeout(() => {
      el.toast.classList.remove("show");
    }, 3500);
  }

  function fusionarDuplicados() {
    const rows = getRows();
    const map = new Map();

    rows.forEach((row) => {
      const sel = qs(row, 'select[name="item_producto_id[]"]');
      const inp = qs(row, 'input[name="item_cantidad[]"]');
      if (!sel || !inp) return;

      const pid = Number(sel.value || 0);
      const cant = Number(inp.value || 0);

      if (pid > 0) {
        if (map.has(pid)) {
          const data = map.get(pid);
          data.cantidad += cant;
          data.filasAEliminar.push(row);
        } else {
          map.set(pid, { cantidad: cant, input: inp, filasAEliminar: [] });
        }
      }
    });

    let fusionados = 0;
    map.forEach((data) => {
      if (data.filasAEliminar.length > 0) {
        data.input.value = data.cantidad.toFixed(CONFIG.decimalesCantidad);
        data.filasAEliminar.forEach((r) => r.remove());
        fusionados++;
      }
    });

    return fusionados;
  }

  function marcarDuplicados() {
    const rows = getRows();
    const count = new Map();

    rows.forEach((row) => {
      const sel = qs(row, 'select[name="item_producto_id[]"]');
      if (!sel) return;
      const pid = Number(sel.value || 0);
      if (pid > 0) count.set(pid, (count.get(pid) || 0) + 1);
    });

    rows.forEach((row) => {
      const sel = qs(row, 'select[name="item_producto_id[]"]');
      if (!sel) return;
      const pid = Number(sel.value || 0);
      const c = count.get(pid) || 0;

      if (pid > 0 && c > 1) {
        sel.style.outline = "2px solid var(--accent-cyan, #22d3ee)";
        sel.style.outlineOffset = "2px";
        sel.title = `Producto repetido (${c} veces). Se fusionará al guardar.`;
      } else {
        sel.style.outline = "";
        sel.style.outlineOffset = "";
        sel.title = "";
      }
    });
  }

  function calcularAhorroCombo() {
    if (!el.preview) return;
    
    const precioCombo = normalizeMoneyAr(el.comboPrecio?.value || "0");
    const rows = getRows();

    let precioNormal = 0;
    let itemsCount = 0;

    for (const row of rows) {
      const sel = qs(row, 'select[name="item_producto_id[]"]');
      const inp = qs(row, 'input[name="item_cantidad[]"]');
      const opt = sel?.selectedOptions?.[0];

      const precio = Number(opt?.dataset?.precio || 0);
      const cant = Number(inp?.value || 0);

      if (precio > 0 && cant > 0) {
        precioNormal += precio * cant;
        itemsCount++;
      }
    }

    // Reset clases
    el.preview.classList.remove("has-savings", "no-savings");

    if (itemsCount === 0) {
      el.preview.textContent = "Agregá productos para ver el ahorro estimado.";
      return;
    }

    const ahorro = precioNormal - precioCombo;
    
    if (ahorro > 0.01) {
      el.preview.classList.add("has-savings");
      el.preview.innerHTML = `<strong>Ahorro: $${ahorro.toFixed(2)}</strong> — Normal: $${precioNormal.toFixed(2)} / Combo: $${precioCombo.toFixed(2)}`;
    } else if (ahorro < -0.01) {
      el.preview.classList.add("no-savings");
      el.preview.innerHTML = `<strong>⚠️ Combo más caro que productos sueltos</strong> — Normal: $${precioNormal.toFixed(2)} / Combo: $${precioCombo.toFixed(2)}`;
    } else {
      el.preview.textContent = `Sin ahorro — Normal: $${precioNormal.toFixed(2)} / Combo: $${precioCombo.toFixed(2)}`;
    }
  }

  function validarFormulario() {
    const errores = [];
    const rows = getRows();

    const nombre = el.nombrePromo?.value.trim();
    if (!nombre) errores.push("Debe ingresar un nombre para la promoción");

    const precio = normalizeMoneyAr(el.comboPrecio?.value || "0");
    if (precio <= 0) errores.push("El precio del combo debe ser mayor a 0");

    let itemsValidos = 0;
    rows.forEach((row, i) => {
      const sel = qs(row, 'select[name="item_producto_id[]"]');
      const inp = qs(row, 'input[name="item_cantidad[]"]');
      if (!sel || !inp) return;

      const pid = Number(sel.value || 0);
      const cant = Number(inp.value || 0);

      if (pid > 0 && cant > 0) {
        itemsValidos++;
        if (cant < CONFIG.minCantidad) errores.push(`Fila ${i + 1}: La cantidad debe ser al menos ${CONFIG.minCantidad}`);
        if (cant > CONFIG.maxCantidad) errores.push(`Fila ${i + 1}: La cantidad no puede superar ${CONFIG.maxCantidad}`);
      } else if (pid > 0 || cant > 0) {
        errores.push(`Fila ${i + 1}: Debe completar producto y cantidad`);
      }
    });

    if (itemsValidos < 1) errores.push("El combo debe tener al menos 1 producto");

    const fi = el.fechaInicio?.value;
    const ff = el.fechaFin?.value;

    if (fi && !isValidYmd(fi)) errores.push("Fecha de inicio inválida");
    if (ff && !isValidYmd(ff)) errores.push("Fecha de fin inválida");
    if (fi && ff && fi > ff) errores.push("La fecha de inicio no puede ser posterior a la fecha de fin");

    return errores;
  }

  function agregarFila() {
    const firstRow = el.tbody.querySelector("tr");
    if (!firstRow) return toast("No se puede agregar una nueva fila", "error");

    const clone = firstRow.cloneNode(true);
    const sel = qs(clone, 'select[name="item_producto_id[]"]');
    const inp = qs(clone, 'input[name="item_cantidad[]"]');

    if (sel) sel.value = "";
    if (inp) inp.value = "1";

    el.tbody.appendChild(clone);
    marcarDuplicados();
    calcularAhorroCombo();
    
    // Focus en el nuevo select
    sel?.focus();
  }

  function eliminarFila(btn) {
    const rows = getRows();
    if (rows.length <= 1) {
      const row = rows[0];
      const sel = qs(row, 'select[name="item_producto_id[]"]');
      const inp = qs(row, 'input[name="item_cantidad[]"]');
      if (sel) sel.value = "";
      if (inp) inp.value = "1";
      marcarDuplicados();
      calcularAhorroCombo();
      return;
    }
    const row = btn.closest("tr");
    if (row) row.remove();
    marcarDuplicados();
    calcularAhorroCombo();
  }

  function attach() {
    el.btnAdd.addEventListener("click", agregarFila);

    el.tbody.addEventListener("click", (e) => {
      const btn = e.target.closest(".btn-remove-item");
      if (!btn) return;
      e.preventDefault();
      eliminarFila(btn);
    });

    el.tbody.addEventListener("change", (e) => {
      if (
        e.target.matches('select[name="item_producto_id[]"]') ||
        e.target.matches('input[name="item_cantidad[]"]')
      ) {
        marcarDuplicados();
        calcularAhorroCombo();
      }
    });

    el.tbody.addEventListener("input", (e) => {
      if (e.target.matches('input[name="item_cantidad[]"]')) {
        calcularAhorroCombo();
      }
    });

    el.comboPrecio?.addEventListener("input", calcularAhorroCombo);

    el.form.addEventListener("submit", (e) => {
      e.preventDefault();

      const fusionados = fusionarDuplicados();
      if (fusionados > 0) toast(`Se fusionaron ${fusionados} producto(s) duplicado(s)`, "info");

      const errores = validarFormulario();
      if (errores.length > 0) {
        toast(errores[0], "error");
        return;
      }

      e.target.submit();
    });
  }

  function init() {
    if (!initElements()) return;

    attach();
    marcarDuplicados();
    calcularAhorroCombo();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
  
})();
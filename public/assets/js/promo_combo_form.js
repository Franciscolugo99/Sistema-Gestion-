(function () {
  "use strict";

  const CONFIG = {
    minCantidad: 0.001,
    maxCantidad: 9999.999,
    decimalesCantidad: 3,
    maxResultados: 8,
  };

  let el = {};
  const pickerStates = new WeakMap();

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
    const d = new Date(`${dateStr}T00:00:00`);
    return !Number.isNaN(d.getTime());
  }

  function escapeHtml(text) {
    return String(text ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function escapeRegExp(text) {
    return String(text ?? "").replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  }

  function highlightMatch(label, query) {
    if (!query) return escapeHtml(label);
    const re = new RegExp(`(${escapeRegExp(query)})`, "ig");
    return escapeHtml(label).replace(re, "<mark>$1</mark>");
  }

  function toast(msg, type = "info") {
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

    if (typeof window.showToast === "function") {
      window.showToast(text, type);
      return;
    }

    if (!el.toast) {
      return;
    }

    el.toast.textContent = text;
    el.toast.className = `form-toast show toast-${type}`;

    setTimeout(() => {
      el.toast.classList.remove("show");
    }, 3500);
  }

  function getOptionEntries(selectEl) {
    return Array.from(selectEl.options)
      .filter((opt) => opt.value)
      .map((opt) => ({
        value: String(opt.value),
        label: (opt.textContent || "").trim(),
        precio: Number(opt.dataset.precio || 0),
        search: ((opt.textContent || "") + " " + (opt.value || "")).toLowerCase(),
      }));
  }

  function closeAllPickers(exceptWrapper = null) {
    getRows().forEach((row) => {
      const wrapper = qs(row, ".product-search-picker");
      if (!wrapper || wrapper === exceptWrapper) return;
      const state = pickerStates.get(qs(row, 'select[name="item_producto_id[]"]'));
      state?.close();
    });
  }

  function ensureProductPicker(row) {
    const selectEl = qs(row, 'select[name="item_producto_id[]"]');
    if (!selectEl) return null;

    let state = pickerStates.get(selectEl);
    if (state) return state;

    let wrapper = qs(row, ".product-search-picker");
    let input = qs(row, ".product-search-input");
    let dropdown = qs(row, ".product-search-suggestions");

    if (!wrapper || !input || !dropdown) {
      const td = selectEl.closest("td");
      if (!td) return null;

      wrapper = document.createElement("div");
      wrapper.className = "product-search-picker product-search-picker--table";

      input = document.createElement("input");
      input.type = "text";
      input.className = "field-input product-search-input";
      input.placeholder = "Buscar por codigo o nombre...";
      input.autocomplete = "off";

      dropdown = document.createElement("div");
      dropdown.className = "product-search-suggestions";

      td.insertBefore(wrapper, selectEl);
      wrapper.appendChild(input);
      wrapper.appendChild(dropdown);
      wrapper.appendChild(selectEl);
    }

    selectEl.classList.add("picker-source-select");
    selectEl.required = false;

    const entries = getOptionEntries(selectEl);
    let filtered = [];
    let activeIndex = -1;

    function close() {
      dropdown.classList.remove("is-open");
      dropdown.innerHTML = "";
      activeIndex = -1;
    }

    function syncFromSelect() {
      const selected = entries.find((entry) => entry.value === String(selectEl.value || ""));
      input.value = selected ? selected.label : "";
      input.dataset.selectedValue = selected ? selected.value : "";
    }

    function choose(entry) {
      selectEl.value = entry.value;
      input.value = entry.label;
      input.dataset.selectedValue = entry.value;
      close();
      marcarDuplicados();
      calcularAhorroCombo();
    }

    function render(query, preserveIndex = false) {
      const q = String(query || "").trim().toLowerCase();
      if (q.length < 1) {
        close();
        return;
      }

      filtered = entries.filter((entry) => entry.search.includes(q)).slice(0, CONFIG.maxResultados);
      if (!filtered.length) {
        activeIndex = -1;
      } else if (!preserveIndex) {
        activeIndex = 0;
      } else {
        activeIndex = Math.min(Math.max(activeIndex, 0), filtered.length - 1);
      }

      if (!filtered.length) {
        dropdown.innerHTML = '<div class="product-search-item product-search-item--empty">Sin resultados</div>';
        dropdown.classList.add("is-open");
        return;
      }

      dropdown.innerHTML = filtered
        .map((entry, index) => `
          <button type="button" class="product-search-item ${index === activeIndex ? "is-active" : ""}" data-value="${escapeHtml(entry.value)}">
            <span class="product-search-main">${highlightMatch(entry.label, q)}</span>
            ${entry.precio > 0 ? `<span class="product-search-price">$${entry.precio.toFixed(2)}</span>` : ""}
          </button>
        `)
        .join("");

      dropdown.classList.add("is-open");
    }

    input.addEventListener("input", () => {
      const selected = entries.find((entry) => entry.value === input.dataset.selectedValue);
      if (!input.value.trim()) {
        selectEl.value = "";
        input.dataset.selectedValue = "";
        close();
        marcarDuplicados();
        calcularAhorroCombo();
        return;
      }

      if (!selected || input.value.trim() !== selected.label) {
        selectEl.value = "";
        input.dataset.selectedValue = "";
      }

      closeAllPickers(wrapper);
      render(input.value);
      marcarDuplicados();
      calcularAhorroCombo();
    });

    input.addEventListener("focus", () => {
      closeAllPickers(wrapper);
      if (input.value.trim()) render(input.value);
    });

    input.addEventListener("keydown", (e) => {
      if (!filtered.length) return;

      if (e.key === "ArrowDown") {
        e.preventDefault();
        activeIndex = Math.min(activeIndex + 1, filtered.length - 1);
        render(input.value, true);
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        activeIndex = Math.max(activeIndex - 1, 0);
        render(input.value, true);
      } else if (e.key === "Enter") {
        e.preventDefault();
        if (filtered[activeIndex]) choose(filtered[activeIndex]);
      } else if (e.key === "Escape") {
        close();
      }
    });

    dropdown.addEventListener("mousedown", (e) => e.preventDefault());
    dropdown.addEventListener("click", (e) => {
      const item = e.target.closest(".product-search-item[data-value]");
      if (!item) return;
      const entry = entries.find((candidate) => candidate.value === item.dataset.value);
      if (entry) choose(entry);
    });

    syncFromSelect();

    state = { close, syncFromSelect, input, selectEl };
    pickerStates.set(selectEl, state);
    return state;
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
        data.filasAEliminar.forEach((row) => row.remove());
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
      const input = qs(row, ".product-search-input");
      if (!sel || !input) return;
      const pid = Number(sel.value || 0);
      const repeated = pid > 0 && (count.get(pid) || 0) > 1;

      input.classList.toggle("is-duplicate", repeated);
      input.title = repeated ? `Producto repetido (${count.get(pid)} veces). Se fusionara al guardar.` : "";
    });
  }

  function calcularAhorroCombo() {
    if (!el.preview) return;

    const precioCombo = normalizeMoneyAr(el.comboPrecio?.value || "0");
    const rows = getRows();

    let precioNormal = 0;
    let itemsCount = 0;

    rows.forEach((row) => {
      const sel = qs(row, 'select[name="item_producto_id[]"]');
      const inp = qs(row, 'input[name="item_cantidad[]"]');
      const opt = sel?.selectedOptions?.[0];
      const precio = Number(opt?.dataset?.precio || 0);
      const cant = Number(inp?.value || 0);

      if (precio > 0 && cant > 0) {
        precioNormal += precio * cant;
        itemsCount++;
      }
    });

    el.preview.classList.remove("has-savings", "no-savings");

    if (itemsCount === 0) {
      el.preview.textContent = "Agrega productos para ver el ahorro estimado.";
      return;
    }

    const ahorro = precioNormal - precioCombo;

    if (ahorro > 0.01) {
      el.preview.classList.add("has-savings");
      el.preview.innerHTML = `<strong>Ahorro: $${ahorro.toFixed(2)}</strong> - Normal: $${precioNormal.toFixed(2)} / Combo: $${precioCombo.toFixed(2)}`;
    } else if (ahorro < -0.01) {
      el.preview.classList.add("no-savings");
      el.preview.innerHTML = `<strong>Combo mas caro que productos sueltos</strong> - Normal: $${precioNormal.toFixed(2)} / Combo: $${precioCombo.toFixed(2)}`;
    } else {
      el.preview.textContent = `Sin ahorro - Normal: $${precioNormal.toFixed(2)} / Combo: $${precioCombo.toFixed(2)}`;
    }
  }

  function validarFormulario() {
    const errores = [];
    const rows = getRows();

    const nombre = el.nombrePromo?.value.trim();
    if (!nombre) errores.push("Debe ingresar un nombre para la promocion");

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

    if (fi && !isValidYmd(fi)) errores.push("Fecha de inicio invalida");
    if (ff && !isValidYmd(ff)) errores.push("Fecha de fin invalida");
    if (fi && ff && fi > ff) errores.push("La fecha de inicio no puede ser posterior a la fecha de fin");

    return errores;
  }

  function agregarFila() {
    const firstRow = el.tbody.querySelector("tr");
    if (!firstRow) {
      toast("No se puede agregar una nueva fila", "error");
      return;
    }

    const clone = firstRow.cloneNode(true);
    const sel = qs(clone, 'select[name="item_producto_id[]"]');
    const inp = qs(clone, 'input[name="item_cantidad[]"]');
    const searchInput = qs(clone, ".product-search-input");
    const dropdown = qs(clone, ".product-search-suggestions");

    if (sel) sel.value = "";
    if (inp) inp.value = "1";
    if (searchInput) {
      searchInput.value = "";
      searchInput.dataset.selectedValue = "";
      searchInput.classList.remove("is-duplicate");
      searchInput.title = "";
    }
    if (dropdown) {
      dropdown.innerHTML = "";
      dropdown.classList.remove("is-open");
    }

    el.tbody.appendChild(clone);
    ensureProductPicker(clone);
    marcarDuplicados();
    calcularAhorroCombo();
    qs(clone, ".product-search-input")?.focus();
  }

  function eliminarFila(btn) {
    const rows = getRows();
    if (rows.length <= 1) {
      const row = rows[0];
      const sel = qs(row, 'select[name="item_producto_id[]"]');
      const inp = qs(row, 'input[name="item_cantidad[]"]');
      const searchInput = qs(row, ".product-search-input");
      if (sel) sel.value = "";
      if (inp) inp.value = "1";
      if (searchInput) {
        searchInput.value = "";
        searchInput.dataset.selectedValue = "";
        searchInput.classList.remove("is-duplicate");
        searchInput.title = "";
      }
      closeAllPickers();
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
      if (e.target.matches('input[name="item_cantidad[]"]')) {
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

    document.addEventListener("click", (e) => {
      if (!e.target.closest(".product-search-picker")) {
        closeAllPickers();
      }
    });

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

    getRows().forEach((row) => ensureProductPicker(row));
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

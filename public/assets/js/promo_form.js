document.addEventListener("DOMContentLoaded", () => {
  const form = document.querySelector("form.promo-form");
  const tipoSel = document.getElementById("tipo");
  const inpNombre = document.getElementById("nombre");
  const selProducto = document.getElementById("producto_id");
  const blockNxM = document.getElementById("block-nxm");
  const blockNth = document.getElementById("block-nth");
  const inpN = document.getElementById("nxm_n");
  const inpM = document.getElementById("nxm_m");
  const inpNthN = document.getElementById("nth_n");
  const inpPct = document.getElementById("porcentaje");
  const previewEl = document.getElementById("preview-promo");

  if (!form || !tipoSel || !blockNxM || !blockNth || !selProducto) return;

  function setDisabled(container, disabled) {
    container.querySelectorAll("input, select, textarea").forEach((el) => {
      el.disabled = disabled;
    });
  }

  function mark(el, msg) {
    if (!el) return;
    el.setCustomValidity(msg || "");
    el.toggleAttribute("aria-invalid", !!msg);
  }

  function asInt(el) {
    const v = el && el.value !== "" ? Number(el.value) : NaN;
    return Number.isFinite(v) ? Math.trunc(v) : NaN;
  }

  function asFloat(el) {
    const v = el && el.value !== "" ? Number(el.value) : NaN;
    return Number.isFinite(v) ? v : NaN;
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

  function buildEntries(selectEl) {
    return Array.from(selectEl.options)
      .filter((opt) => opt.value)
      .map((opt) => ({
        value: String(opt.value),
        label: (opt.textContent || "").trim(),
        search: ((opt.textContent || "") + " " + (opt.value || "")).toLowerCase(),
      }));
  }

  function highlightMatch(label, query) {
    if (!query) return escapeHtml(label);
    const re = new RegExp(`(${escapeRegExp(query)})`, "ig");
    return escapeHtml(label).replace(re, "<mark>$1</mark>");
  }

  function enhanceProductSelect(selectEl) {
    const parent = selectEl.parentElement;
    if (!parent) return { input: null, sync: () => {}, close: () => {} };

    const wrapper = document.createElement("div");
    wrapper.className = "product-search-picker";

    const input = document.createElement("input");
    input.type = "text";
    input.className = "field-input product-search-input";
    input.placeholder = "Buscar por codigo o nombre...";
    input.autocomplete = "off";
    input.id = "producto_buscar";

    const dropdown = document.createElement("div");
    dropdown.className = "product-search-suggestions";

    parent.insertBefore(wrapper, selectEl);
    wrapper.appendChild(input);
    wrapper.appendChild(dropdown);
    wrapper.appendChild(selectEl);

    selectEl.classList.add("picker-source-select");
    selectEl.required = false;

    const entries = buildEntries(selectEl);
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
      validateAll();
      updatePreview();
    }

    function render(query, preserveIndex = false) {
      const q = String(query || "").trim().toLowerCase();
      if (q.length < 1) {
        close();
        return;
      }

      filtered = entries.filter((entry) => entry.search.includes(q)).slice(0, 8);
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
          </button>
        `)
        .join("");

      dropdown.classList.add("is-open");
    }

    input.addEventListener("input", () => {
      const currentSelected = entries.find((entry) => entry.value === input.dataset.selectedValue);
      if (!input.value.trim()) {
        selectEl.value = "";
        input.dataset.selectedValue = "";
        close();
        validateAll();
        return;
      }

      if (!currentSelected || input.value.trim() !== currentSelected.label) {
        selectEl.value = "";
        input.dataset.selectedValue = "";
      }

      render(input.value);
      validateAll();
    });

    input.addEventListener("focus", () => {
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

    document.addEventListener("click", (e) => {
      if (!wrapper.contains(e.target)) close();
    });

    syncFromSelect();
    return { input, sync: syncFromSelect, close };
  }

  const productPicker = enhanceProductSelect(selProducto);

  function toggleBlocks() {
    const t = tipoSel.value;
    const showNxM = t === "N_PAGA_M";
    const showNth = t === "NTH_PCT";

    blockNxM.style.display = showNxM ? "block" : "none";
    blockNth.style.display = showNth ? "block" : "none";

    setDisabled(blockNxM, !showNxM);
    setDisabled(blockNth, !showNth);

    validateAll();
    updatePreview();
  }

  function validateCommon() {
    const nombre = (inpNombre?.value || "").trim();
    mark(inpNombre, nombre ? "" : "El nombre es obligatorio.");

    const pid = Number(selProducto?.value || 0);
    mark(productPicker.input, pid > 0 ? "" : "Debes elegir un producto.");
  }

  function validateNxM() {
    if (tipoSel.value !== "N_PAGA_M") {
      mark(inpN, "");
      mark(inpM, "");
      return;
    }

    const n = asInt(inpN);
    const m = asInt(inpM);

    mark(inpN, Number.isFinite(n) && n >= 2 ? "" : "En NxM, N debe ser >= 2.");
    mark(inpM, Number.isFinite(m) && m >= 1 ? "" : "En NxM, M debe ser >= 1.");

    if (Number.isFinite(n) && Number.isFinite(m) && m >= n) {
      mark(inpM, "En NxM, M debe ser menor que N (ej: 3x2).");
    }
  }

  function validateNth() {
    if (tipoSel.value !== "NTH_PCT") {
      mark(inpNthN, "");
      mark(inpPct, "");
      return;
    }

    const n = asInt(inpNthN);
    const pct = asFloat(inpPct);

    mark(inpNthN, Number.isFinite(n) && n >= 2 ? "" : "En % a la N, N debe ser >= 2.");
    mark(inpPct, Number.isFinite(pct) && pct > 0 && pct <= 100 ? "" : "El porcentaje debe estar entre 1 y 100.");
  }

  function validateAll() {
    validateCommon();
    validateNxM();
    validateNth();
  }

  function updatePreview() {
    if (!previewEl) return;

    const tipo = tipoSel.value;
    previewEl.innerHTML = "";

    if (tipo === "N_PAGA_M") {
      const n = asInt(inpN);
      const m = asInt(inpM);
      if (Number.isFinite(n) && Number.isFinite(m) && m < n) {
        const pct = Math.round((1 - m / n) * 100);
        previewEl.innerHTML = `
          <div class="preview-box preview-box--info">
            <strong>Ejemplo:</strong> Si el cliente lleva ${n} unidades, paga ${m}.<br>
            <span class="preview-discount">Descuento aprox: ${pct}%</span>
          </div>
        `;
      }
    }

    if (tipo === "NTH_PCT") {
      const n = asInt(inpNthN);
      const pct = asFloat(inpPct);
      if (Number.isFinite(n) && Number.isFinite(pct)) {
        previewEl.innerHTML = `
          <div class="preview-box preview-box--info">
            <strong>Ejemplo:</strong> Cada ${n} unidades, la unidad ${n} tiene ${pct}% de descuento.
          </div>
        `;
      }
    }
  }

  tipoSel.addEventListener("change", toggleBlocks);
  [inpNombre, inpN, inpM, inpNthN, inpPct].forEach((el) => {
    if (!el) return;
    el.addEventListener("input", () => {
      validateAll();
      updatePreview();
    });
    el.addEventListener("change", () => {
      validateAll();
      updatePreview();
    });
  });

  form.addEventListener("submit", (e) => {
    validateAll();
    if (!form.checkValidity()) {
      e.preventDefault();
      form.reportValidity();
    }
  });

  productPicker.sync();
  toggleBlocks();
});

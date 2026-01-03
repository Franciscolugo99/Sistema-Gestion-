// public/assets/js/promo_form.js v3
document.addEventListener("DOMContentLoaded", () => {
  const form = document.querySelector("form.promo-form");
  const tipoSel = document.getElementById("tipo");

  const inpNombre = document.getElementById("nombre");
  const selProducto = document.getElementById("producto_id");

  const blockNxM = document.getElementById("block-nxm");
  const blockNth = document.getElementById("block-nth");

  const inpN = document.getElementById("n");
  const inpM = document.getElementById("m");

  const inpNthN = document.getElementById("n_nth");
  const inpPct = document.getElementById("porcentaje");

  const previewEl = document.getElementById("preview-promo");

  if (!form || !tipoSel || !blockNxM || !blockNth) return;

  function setDisabled(container, disabled) {
    container.querySelectorAll("input, select, textarea").forEach((el) => (el.disabled = disabled));
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

  function toggleBlocks() {
    const t = tipoSel.value;
    const showNxM = t === "N_PAGA_M";
    const showNth = t === "NTH_PCT";

    blockNxM.style.display = showNxM ? "block" : "none";
    blockNth.style.display = showNth ? "block" : "none";

    // clave: lo oculto no viaja en POST
    setDisabled(blockNxM, !showNxM);
    setDisabled(blockNth, !showNth);

    validateAll();
    updatePreview();
  }

  function validateCommon() {
    const nombre = (inpNombre?.value || "").trim();
    mark(inpNombre, nombre ? "" : "El nombre es obligatorio.");

    const pid = Number(selProducto?.value || 0);
    mark(selProducto, pid > 0 ? "" : "Debés elegir un producto.");
  }

  function validateNxM() {
    if (tipoSel.value !== "N_PAGA_M") {
      mark(inpN, "");
      mark(inpM, "");
      return;
    }

    const n = asInt(inpN);
    const m = asInt(inpM);

    mark(inpN, Number.isFinite(n) && n >= 2 ? "" : "En NxM, N debe ser ≥ 2.");
    mark(inpM, Number.isFinite(m) && m >= 1 ? "" : "En NxM, M debe ser ≥ 1.");

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

    mark(inpNthN, Number.isFinite(n) && n >= 2 ? "" : "En % a la N°, N debe ser ≥ 2.");
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
            <strong>Ejemplo:</strong> Cada ${n} unidades, la N°${n} tiene ${pct}% de descuento.
          </div>
        `;
      }
    }
  }

  tipoSel.addEventListener("change", toggleBlocks);
  [inpNombre, selProducto, inpN, inpM, inpNthN, inpPct].forEach((el) => {
    if (!el) return;
    el.addEventListener("input", () => { validateAll(); updatePreview(); });
    el.addEventListener("change", () => { validateAll(); updatePreview(); });
  });

  form.addEventListener("submit", (e) => {
    validateAll();
    if (!form.checkValidity()) {
      e.preventDefault();
      form.reportValidity();
    }
  });

  toggleBlocks();
});

// public/assets/js/promo_form.js

document.addEventListener("DOMContentLoaded", () => {
  const form    = document.querySelector("form.promo-form");
  const tipoSel = document.getElementById("tipo");

  const inpNombre   = document.getElementById("nombre");
  const selProducto = document.getElementById("producto_id");

  const blockNxM = document.getElementById("block-nxm");
  const blockNth = document.getElementById("block-nth");

  // Inputs NxM
  const inpN  = document.getElementById("n");
  const inpM  = document.getElementById("m");

  // Inputs Nth%
  const inpNthN = document.getElementById("n_nth");
  const inpPct  = document.getElementById("porcentaje");

  if (!form || !tipoSel || !blockNxM || !blockNth) return;

  // ----------------------------
  // Helpers
  // ----------------------------
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

  // ----------------------------
  // Toggle bloques + disable hidden inputs
  // ----------------------------
  function toggleBlocks() {
    const t = tipoSel.value;

    const showNxM = t === "N_PAGA_M";
    const showNth = t === "NTH_PCT";

    blockNxM.style.display = showNxM ? "block" : "none";
    blockNth.style.display = showNth ? "block" : "none";

    // CLAVE: deshabilitar lo oculto => no se manda en POST
    setDisabled(blockNxM, !showNxM);
    setDisabled(blockNth, !showNth);

    // Al cambiar tipo, revalidamos todo
    validateAll();
  }

  // ----------------------------
  // Validaciones
  // ----------------------------
  function validateCommon() {
    // Nombre
    const nombre = (inpNombre?.value || "").trim();
    if (!nombre) mark(inpNombre, "El nombre es obligatorio.");
    else mark(inpNombre, "");

    // Producto
    const pid = Number(selProducto?.value || 0);
    if (!pid || pid <= 0) mark(selProducto, "Debés elegir un producto.");
    else mark(selProducto, "");
  }

  function validateNxM() {
    // Si el tipo no es NxM, no validamos este bloque
    if (tipoSel.value !== "N_PAGA_M") {
      mark(inpN, "");
      mark(inpM, "");
      return;
    }

    const n = asInt(inpN);
    const m = asInt(inpM);

    if (!Number.isFinite(n) || n < 2) mark(inpN, "En NxM, N debe ser ≥ 2.");
    else mark(inpN, "");

    if (!Number.isFinite(m) || m < 1) mark(inpM, "En NxM, M debe ser ≥ 1.");
    else mark(inpM, "");

    // Regla importante
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

    if (!Number.isFinite(n) || n < 2) mark(inpNthN, "En % a la N°, N debe ser ≥ 2.");
    else mark(inpNthN, "");

    if (!Number.isFinite(pct) || pct <= 0 || pct > 100) {
      mark(inpPct, "El porcentaje debe estar entre 1 y 100.");
    } else {
      mark(inpPct, "");
    }
  }

  function validateAll() {
    validateCommon();
    validateNxM();
    validateNth();
  }

  // ----------------------------
  // Eventos (en vivo)
  // ----------------------------
  tipoSel.addEventListener("change", toggleBlocks);

  // live validation
  [inpNombre, selProducto, inpN, inpM, inpNthN, inpPct].forEach((el) => {
    if (!el) return;
    el.addEventListener("input", validateAll);
    el.addEventListener("change", validateAll);
  });

  // Submit: frena si hay inválidos
  form.addEventListener("submit", (e) => {
    validateAll();

    if (!form.checkValidity()) {
      e.preventDefault();
      // muestra burbuja del navegador y enfoca el primero inválido
      form.reportValidity();
    }
  });

  // init
  toggleBlocks();
});

// public/assets/js/clientes.js
(function () {
  "use strict";

  const overlay = document.getElementById("cliDrawerOverlay");
  const drawer = document.getElementById("cliDrawer");
  const form = document.getElementById("cliForm");
  const btnClose = document.getElementById("cliDrawerClose");
  const btnCancel = document.getElementById("cliCancelBtn");
  const btnSubmit = document.getElementById("cliSubmitBtn") || document.getElementById("cliSaveBtn");

  if (!overlay || !drawer || !form) return;

  let formChanged = false;
  let _closeDrawerOpen = false;
  const originalSubmitText = btnSubmit?.textContent || "Guardar cliente";

  function openDrawer() {
    overlay.classList.add("is-open");
    drawer.classList.add("is-open");
    document.body.classList.add("no-scroll");
    drawer.setAttribute("aria-hidden", "false");

    setTimeout(() => {
      const firstInput = drawer.querySelector('input:not([type="hidden"])');
      if (firstInput) firstInput.focus();
    }, 100);
  }

  async function closeDrawer(e) {
    // Importante: confirm async. Frenar default (anchors/botones) y evitar click-through.
    if (e) { e.preventDefault?.(); e.stopPropagation?.(); }
    // Si hay un SweetAlert visible, no cierres el drawer por detrás.
    if (window.Swal && typeof Swal.isVisible === 'function' && Swal.isVisible()) return;
    if (_closeDrawerOpen) return;
    _closeDrawerOpen = true;
    try {

    if (formChanged) {
      const _ok = await Notif.confirmar(
        "⚠️ Cambios sin guardar",
        "<p>Tenés cambios sin guardar. ¿Querés salir igual?</p>",
        { icon: "warning", confirmText: "✅ Salir igual", cancelText: "❌ Quedarme" }
      );
      if (!_ok) return;
    }

    overlay.classList.remove("is-open");
    drawer.classList.remove("is-open");
    document.body.classList.remove("no-scroll");
    drawer.setAttribute("aria-hidden", "true");

    const url = new URL(window.location.href);
    url.searchParams.delete("editar");
    url.searchParams.delete("new");
    window.history.replaceState({}, "", url.toString());

    formChanged = false;
    } finally {
      _closeDrawerOpen = false;
    }
  }

  overlay.addEventListener("click", (e) => closeDrawer(e));
  btnClose?.addEventListener("click", (e) => closeDrawer(e));
  btnCancel?.addEventListener("click", (e) => closeDrawer(e));

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && drawer.classList.contains("is-open")) {
      if (window.Swal && typeof Swal.isVisible === 'function' && Swal.isVisible()) return;
      closeDrawer(e);
    }
  });

  form.addEventListener("input", () => {
    formChanged = true;
  });

  // ========== VALIDACIÓN DE CUIT ==========

  function limpiarCuit(cuit) {
    return cuit.replace(/[^0-9]/g, "");
  }

  function formatearCuit(cuit) {
    const limpio = limpiarCuit(cuit);
    if (limpio.length !== 11) return cuit;

    return `${limpio.substr(0, 2)}-${limpio.substr(2, 8)}-${limpio.substr(
      10,
      1
    )}`;
  }

  function validarCuit(cuit) {
    const limpio = limpiarCuit(cuit);

    if (limpio.length !== 11) {
      return { valido: false, error: "El CUIT debe tener 11 dígitos" };
    }

    const tipo = parseInt(limpio.substr(0, 2));
    const tiposValidos = [20, 23, 24, 27, 30, 33, 34];

    if (!tiposValidos.includes(tipo)) {
      return { valido: false, error: `Tipo ${tipo} no válido` };
    }

    const multiplicadores = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
    let suma = 0;

    for (let i = 0; i < 10; i++) {
      suma += parseInt(limpio[i]) * multiplicadores[i];
    }

    const resto = suma % 11;
    let digitoEsperado = 11 - resto;

    if (digitoEsperado === 11) digitoEsperado = 0;
    if (digitoEsperado === 10) digitoEsperado = 9;

    const digitoReal = parseInt(limpio[10]);

    if (digitoEsperado !== digitoReal) {
      return {
        valido: false,
        error: `Dígito verificador incorrecto (esperado: ${digitoEsperado}, ingresado: ${digitoReal})`,
      };
    }

    return { valido: true, error: "" };
  }

  // ========== INTEGRAR EN FORM ==========

  const cuitInput = form.querySelector("[data-cuit-input]");
  const cuitError = document.getElementById("cuitError");
  const condIvaSelect = document.getElementById("condIvaSelect");

  if (cuitInput && cuitError) {
    cuitInput.addEventListener("input", (e) => {
      let valor = e.target.value;
      const limpio = limpiarCuit(valor);

      if (limpio.length === 11) {
        e.target.value = formatearCuit(limpio);
      }

      cuitError.textContent = "";
      cuitError.style.display = "none";
    });

    cuitInput.addEventListener("blur", (e) => {
      const valor = e.target.value.trim();

      if (valor === "") {
        cuitError.textContent = "";
        cuitError.style.display = "none";
        return;
      }

      const resultado = validarCuit(valor);

      if (!resultado.valido) {
        cuitError.textContent = resultado.error;
        cuitError.style.display = "block";
        cuitError.style.color = "#dc3545";
      } else {
        cuitError.textContent = "✓ CUIT válido";
        cuitError.style.display = "block";
        cuitError.style.color = "#28a745";

        e.target.value = formatearCuit(valor);
      }
    });
  }

  // ========== RI REQUIERE CUIT ==========
  if (condIvaSelect && cuitInput) {
    condIvaSelect.addEventListener("change", (e) => {
      if (e.target.value === "RI") {
        cuitInput.setAttribute("required", "required");
        cuitInput.closest(".cli-field").querySelector("label").innerHTML =
          'CUIT / CUIL <span class="required">*</span> <span class="helper-text" title="Formato: XX-XXXXXXXX-X">ℹ️</span>';
      } else {
        cuitInput.removeAttribute("required");
        cuitInput.closest(".cli-field").querySelector("label").innerHTML =
          'CUIT / CUIL <span class="helper-text" title="Formato: XX-XXXXXXXX-X">ℹ️</span>';
      }
    });

    condIvaSelect.dispatchEvent(new Event("change"));
  }

  // ========== VALIDACIÓN FRONTEND ==========
  form.addEventListener("submit", (e) => {
    const nombreInput = form.querySelector('[name="nombre"]');
    const emailInput = form.querySelector('[name="email"]');
    const descuentoInput = form.querySelector('[name="descuento_porcentaje"]');

    const nombre = nombreInput?.value.trim() || "";
    const email = emailInput?.value.trim() || "";
    const cuit = cuitInput?.value.trim() || "";
    const condIva = condIvaSelect?.value || "";
    const descuento = parseFloat(descuentoInput?.value || 0);

    if (!nombre) {
      e.preventDefault();
      Notif.advertencia("El nombre es obligatorio.");
      nombreInput?.focus();
      return;
    }

    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      e.preventDefault();
      Notif.advertencia("El email no tiene un formato válido.");
      emailInput?.focus();
      return;
    }

    if (cuit) {
      const resultado = validarCuit(cuit);
      if (!resultado.valido) {
        e.preventDefault();
        Notif.error(`CUIT inválido: ${resultado.error}`);
        cuitInput?.focus();
        return;
      }
    }

    if (condIva === "RI" && !cuit) {
      e.preventDefault();
      Notif.advertencia("Los Responsables Inscriptos deben tener CUIT/CUIL.");
      cuitInput?.focus();
      return;
    }

    if (descuento < 0 || descuento > 100) {
      e.preventDefault();
      Notif.advertencia("El descuento debe estar entre 0% y 100%.");
      descuentoInput?.focus();
      return;
    }

    formChanged = false;
    if (btnSubmit) {
      btnSubmit.disabled = true;
      btnSubmit.textContent = "Guardando...";
    }
  });

  window.addEventListener("beforeunload", (e) => {
    if (formChanged && drawer.classList.contains("is-open")) {
      e.preventDefault();
      e.returnValue = "";
    }
  });

  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get("editar") || urlParams.get("new")) {
    openDrawer();
  }

  // ========== BÚSQUEDA CON DEBOUNCE ==========
  const searchInput = document.querySelector('.filters input[name="q"]');
  if (searchInput) {
    let searchTimeout;
    searchInput.addEventListener("input", (e) => {
      clearTimeout(searchTimeout);
      searchTimeout = setTimeout(() => {
        e.target.form.submit();
      }, 600);
    });
  }

  // ========== AUTOCOMPLETE DESDE AFIP ==========
  // - Se dispara al salir (blur) del campo CUIT.
  // - Solo si el CUIT es válido.
  // - Pide confirmación si ya hay nombre cargado.
  if (cuitInput) {
    const AFIP_ENDPOINT = "api/cliente_consultar_cuit.php";
    let lastAfipCuit = "";
    let afipInFlight = false;

    const toast = (msg, type) => {
      if (!window.showToast) return;
      try {
        window.showToast(msg, type);
      } catch (_) {
        window.showToast(msg);
      }
    };

    cuitInput.addEventListener("blur", (e) => {
      const raw = (e.target.value || "").trim();
      const limpio = limpiarCuit(raw);

      if (!limpio || limpio.length !== 11) return;
      if (afipInFlight) return;
      if (limpio === lastAfipCuit) return; // evita consultas repetidas

      const resultado = validarCuit(limpio);
      if (!resultado.valido) return;

      consultarAfip(limpio);
    });

    function setNombrePlaceholder(text) {
      const nombreInput = form.querySelector('[name="nombre"]');
      if (!nombreInput) return { el: null, old: "" };
      const old = nombreInput.placeholder || "";
      nombreInput.placeholder = text;
      return { el: nombreInput, old };
    }

    function consultarAfip(cuitLimpio) {
      afipInFlight = true;
      lastAfipCuit = cuitLimpio;

      const { el: nombreInput, old: oldPh } = setNombrePlaceholder(
        "🔄 Consultando AFIP..."
      );

      fetch(`${AFIP_ENDPOINT}?cuit=${encodeURIComponent(cuitLimpio)}`)
        .then((response) => response.json())
        .then(async (data) => {
          afipInFlight = false;
          if (nombreInput) nombreInput.placeholder = oldPh;

          if (!data || data.success !== true) {
            // Silencioso: AFIP a veces falla o responde lento
            return;
          }

          const datos = data.datos || {};
          const nombreAfip = (datos.nombre || "").trim();
          if (!nombreAfip) return;

          if (nombreInput && nombreInput.value.trim() !== "") {
            const ok = await Notif.confirmar(
              "🔍 Datos de AFIP",
              `<p>Se encontró: <strong>${nombreAfip}</strong></p><p style='color:var(--muted,#94a3b8);font-size:.88rem'>¿Autocompletar datos desde AFIP?</p>`,
              { icon: "info", confirmText: "✅ Autocompletar", cancelText: "❌ No, gracias" }
            );
            if (!ok) return;
          }

          if (nombreInput) nombreInput.value = nombreAfip;

          const cond = form.querySelector('[name="cond_iva"]');
          if (cond && datos.cond_iva) {
            cond.value = datos.cond_iva;
            cond.dispatchEvent(new Event("change"));
          }

          const tipo = form.querySelector('[name="tipo_cliente"]');
          if (tipo && datos.tipo_cliente) {
            tipo.value = datos.tipo_cliente;
          }

          const dir = form.querySelector('[name="direccion"]');
          if (dir && datos.direccion) {
            dir.value = datos.direccion;
          }

          const notas = form.querySelector('[name="notas"]');
          if (notas && datos.actividad) {
            const notaActual = (notas.value || "").trim();
            const nuevaNota = `Actividad AFIP: ${datos.actividad}`;
            notas.value = notaActual ? `${notaActual}\n${nuevaNota}` : nuevaNota;
          }

          toast(`✓ Datos cargados desde AFIP: ${nombreAfip}`, "success");
          formChanged = true;
        })
        .catch(() => {
          afipInFlight = false;
          if (form.querySelector('[name="nombre"]')) {
            form.querySelector('[name="nombre"]').placeholder = oldPh;
          }
        });
    }
  }
})();

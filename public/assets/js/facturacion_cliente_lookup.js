(() => {
  const endpoint = "api/cliente_consultar_cuit.php";

  function limpiarCuit(valor) {
    return String(valor || "").replace(/\D+/g, "").slice(0, 11);
  }

  function formatearCuit(valor) {
    const limpio = limpiarCuit(valor);
    if (limpio.length <= 2) return limpio;
    if (limpio.length <= 10) return `${limpio.slice(0, 2)}-${limpio.slice(2)}`;
    return `${limpio.slice(0, 2)}-${limpio.slice(2, 10)}-${limpio.slice(10)}`;
  }

  function setValue(el, value) {
    if (!el) return;
    el.value = value || "";
  }

  function setChecked(el, checked) {
    if (!el) return;
    if ("checked" in el) {
      el.checked = !!checked;
    } else {
      el.value = checked ? "1" : "0";
    }
  }

  function isChecked(el) {
    if (!el) return false;
    if ("checked" in el) return !!el.checked;
    return String(el.value || "").trim() === "1";
  }

  function humanizarErrorLookup(message) {
    const raw = String(message || "").trim();
    if (raw === "") {
      return {
        message: "ARCA no devolvio datos para ese CUIT/CUIL. Puedes completar el receptor a mano si lo necesitas.",
        type: "warning",
        allowManual: true,
      };
    }
    if (raw.includes("FLUS_ARCA_CERT_PEM") || raw.includes("FLUS_ARCA_KEY_PEM") || /certificado\/clave/i.test(raw)) {
      return {
        message: "La consulta ARCA no esta disponible todavia en esta instalacion. Falta configurar certificado y clave.",
        type: "warning",
      };
    }
    if (/no tiene autorizado el servicio de padron arca|ws_sr_constancia_inscripcion/i.test(raw)) {
      return {
        message: "La integracion ARCA esta activa, pero el certificado todavia no tiene habilitado el servicio de padron. Hay que autorizar esa relacion en ARCA.",
        type: "warning",
      };
    }
    if (/Parsing WSDL|Couldn't load from|failed to load external entity|No se pudo abrir el servicio de padron ARCA/i.test(raw)) {
      return {
        message: "La integracion ARCA ya esta habilitada, pero esta PC o red no puede llegar al servicio de padron. Revisa DNS, internet o firewall.",
        type: "warning",
      };
    }
    if (/no autenticado/i.test(raw)) {
      return {
        message: "Tu sesion vencio. Actualiza la pagina y vuelve a intentar.",
        type: "error",
      };
    }
    if (/no devolvi[oó] constancia|no se encontraron datos para ese cuit|sin constancia/i.test(raw)) {
      return {
        message: "ARCA no devolvio datos utilizables para ese CUIT/CUIL. Puedes completar el receptor a mano y emitir igual.",
        type: "warning",
        allowManual: true,
      };
    }
    return { message: raw, type: "error", allowManual: false };
  }

  document.querySelectorAll("[data-facturacion-cliente-form]").forEach((form) => {
    const lookup = form.querySelector("[data-facturacion-cliente-lookup]");
    if (!lookup) return;

    const select = form.querySelector("[data-lookup-select]");
    const cuitInput = lookup.querySelector("[data-lookup-cuit]");
    const button = lookup.querySelector("[data-lookup-btn]");
    const activeInput = lookup.querySelector("[data-lookup-activo]");
    const tipoClienteInput = lookup.querySelector("[data-lookup-tipo-cliente]");
    const editorStateInput = lookup.querySelector("[data-lookup-editor-state]");
    const confirmInput = lookup.querySelector("[data-lookup-confirm]");
    const clearButton = lookup.querySelector("[data-lookup-clear]");
    const toggleEditorButton = lookup.querySelector("[data-lookup-toggle-editor]");
    const applyButton = lookup.querySelector("[data-lookup-apply]");
    const result = lookup.querySelector("[data-lookup-result]");
    const status = lookup.querySelector("[data-lookup-status]");
    const nombreInput = lookup.querySelector("[data-lookup-nombre]");
    const condIvaInput = lookup.querySelector("[data-lookup-cond-iva]");
    const estadoInput = lookup.querySelector("[data-lookup-estado]");
    const direccionInput = lookup.querySelector("[data-lookup-direccion]");
    const editor = lookup.querySelector("[data-lookup-editor]");
    const displayNombre = lookup.querySelector("[data-lookup-display-nombre]");
    const displayCuit = lookup.querySelector("[data-lookup-display-cuit]");
    const displayCondIva = lookup.querySelector("[data-lookup-display-cond-iva]");
    const displayEstado = lookup.querySelector("[data-lookup-display-estado]");
    const displayDireccion = lookup.querySelector("[data-lookup-display-direccion]");
    const receptorMode = form.querySelector("[data-receptor-mode]");
    const receptorModeButtons = Array.from(form.querySelectorAll("[data-receptor-mode-btn]"));
    const selectorPanels = Array.from(form.querySelectorAll('[data-receptor-panel="selector"]'));
    const lookupPanels = Array.from(form.querySelectorAll('[data-receptor-panel="lookup"]'));
    const receptorNote = form.querySelector("[data-receptor-note]");
    const receptorSummary = form.querySelector("[data-receptor-summary]");

    if (!cuitInput || !button || !activeInput) return;

    let inFlight = false;
    let ultimoConsultado = limpiarCuit(cuitInput.value);
    let lastSelectedClient = select?.value && select.value !== "0" ? select.value : "0";

    function emitStateChange(reason = "update") {
      form.dispatchEvent(
        new CustomEvent("flus:receptor-state-changed", {
          bubbles: true,
          detail: {
            reason,
            mode: receptorMode?.dataset.mode || "selector",
            active: activeInput.value === "1",
            confirmed: isChecked(confirmInput),
            selectedClientId: select?.value || "0",
            nombre: nombreInput?.value || "",
            cuit: cuitInput.value || "",
            condIva: condIvaInput?.value || "",
            direccion: direccionInput?.value || "",
            estado: estadoInput?.value || "",
          },
        })
      );
    }

    function setStatus(message, type) {
      if (!status) return;
      status.textContent = message || "";
      status.dataset.state = type || "info";
    }

    function setEditorOpen(open) {
      if (editor) {
        editor.classList.toggle("is-visible", !!open);
      }
      if (editorStateInput) {
        editorStateInput.value = open ? "1" : "0";
      }
      if (toggleEditorButton) {
        toggleEditorButton.textContent = open ? "Ocultar edicion manual" : "Editar manualmente";
      }
    }

    function updateReceptorSummary() {
      if (!receptorSummary) return;
      if (activeInput.value === "1" && isChecked(confirmInput)) {
        const nombre = String(nombreInput?.value || "").trim();
        receptorSummary.textContent = nombre || "Receptor por ARCA";
        return;
      }
      if (!select) return;
      const selectedOption = select.options[select.selectedIndex];
      receptorSummary.textContent = String(selectedOption?.text || "").trim() || "Consumidor Final";
    }

    function updateSummaryPreview() {
      const nombre = String(nombreInput?.value || "").trim();
      const cuit = formatearCuit(cuitInput.value || "");
      const condIva = String(condIvaInput?.value || "").trim();
      const estado = String(estadoInput?.value || "").trim();
      const direccion = String(direccionInput?.value || "").trim();

      if (displayNombre) displayNombre.textContent = nombre || "Completa los datos del receptor";
      if (displayCuit) displayCuit.textContent = cuit;
      if (displayCondIva) {
        displayCondIva.textContent = condIva || "Cond. IVA pendiente";
        displayCondIva.classList.toggle("is-empty", condIva === "");
      }
      if (displayEstado) {
        displayEstado.textContent = estado || "Padron sin estado";
        displayEstado.classList.toggle("is-empty", estado === "");
      }
      if (displayDireccion) {
        displayDireccion.textContent = direccion || "Domicilio fiscal pendiente";
        displayDireccion.classList.toggle("is-empty", direccion === "");
      }
    }

    function toggleResult(visible) {
      if (!result) return;
      result.classList.toggle("is-visible", !!visible);
    }

    function setReceptorMode(mode, options = {}) {
      const targetMode = mode === "lookup" ? "lookup" : "selector";

      if (receptorMode) {
        receptorMode.dataset.mode = targetMode;
      }
      receptorModeButtons.forEach((btn) => {
        btn.classList.toggle("is-active", btn.dataset.receptorModeBtn === targetMode);
      });
      selectorPanels.forEach((panel) => {
        panel.hidden = targetMode !== "selector";
      });
      lookupPanels.forEach((panel) => {
        panel.hidden = targetMode !== "lookup";
      });
      if (receptorNote) {
        receptorNote.textContent = targetMode === "lookup"
          ? "ARCA tendra prioridad al emitir. Si faltan datos, puedes completarlos manualmente en este bloque."
          : "Se emitira con el cliente seleccionado en FLUS. Si prefieres usar ARCA, cambia el modo arriba.";
      }

      if (targetMode === "selector") {
        if (!options.preserveLookup) {
          clearLookup(false, false);
        }
        if (select) {
          select.value = lastSelectedClient && lastSelectedClient !== "0" ? lastSelectedClient : "0";
        }
      } else {
        if (select && select.value && select.value !== "0") {
          lastSelectedClient = select.value;
        }
        if (select) {
          select.value = "0";
        }
      }

      updateReceptorSummary();
      emitStateChange("mode");
    }

    function clearLookup(keepCuit, notify = true) {
      activeInput.value = "0";
      if (!keepCuit) {
        cuitInput.value = "";
      }
      setValue(nombreInput, "");
      setValue(condIvaInput, "");
      setValue(estadoInput, "");
      setValue(direccionInput, "");
      setValue(tipoClienteInput, "MINORISTA");
      setChecked(confirmInput, false);
      setEditorOpen(false);
      updateSummaryPreview();
      toggleResult(false);
      setStatus("", "info");
      ultimoConsultado = keepCuit ? limpiarCuit(cuitInput.value) : "";
      updateReceptorSummary();
      emitStateChange("clear");
      if (notify && window.Notif?.info) {
        window.Notif.info("Se descartaron los datos consultados en ARCA.");
      }
    }

    function refreshConfirmedState() {
      if (activeInput.value !== "1") {
        setStatus("", "info");
        toggleResult(false);
        emitStateChange("inactive");
        return;
      }

      updateSummaryPreview();
      toggleResult(true);
      if (isChecked(confirmInput)) {
        setStatus("Se emitira con estos datos de ARCA y FLUS completara o actualizara el cliente al confirmar.", "success");
      } else {
        setStatus("Revisa este bloque y confirma si quieres usar estos datos de ARCA. Si no, descartalos y sigue con el selector.", "warning");
      }
      updateReceptorSummary();
      emitStateChange("confirm");
    }

    function applyData(datos) {
      const nombre = String(datos.nombre || "").trim();
      const cuit = formatearCuit(datos.cuit || cuitInput.value || "");
      cuitInput.value = cuit;
      setValue(nombreInput, nombre);
      setValue(condIvaInput, String(datos.cond_iva || "").trim());
      setValue(estadoInput, String(datos.estado || "").trim());
      setValue(direccionInput, String(datos.direccion || "").trim());
      setValue(tipoClienteInput, String(datos.tipo_cliente || "MINORISTA").trim());
      activeInput.value = "1";
      setChecked(confirmInput, false);
      ultimoConsultado = limpiarCuit(cuit);
      setEditorOpen(!(nombre && datos.cond_iva && datos.direccion));
      updateSummaryPreview();
      setReceptorMode("lookup", { preserveLookup: true });
      toggleResult(true);
      refreshConfirmedState();
    }

    function activarCargaManual(cuit, message) {
      cuitInput.value = formatearCuit(cuit);
      activeInput.value = "1";
      setValue(nombreInput, "");
      setValue(condIvaInput, "");
      setValue(estadoInput, "");
      setValue(direccionInput, "");
      setChecked(confirmInput, false);
      ultimoConsultado = limpiarCuit(cuit);
      setEditorOpen(true);
      updateSummaryPreview();
      setReceptorMode("lookup", { preserveLookup: true });
      toggleResult(true);
      setStatus(message, "warning");
      emitStateChange("manual");
    }

    async function consultar() {
      const cuit = limpiarCuit(cuitInput.value);
      if (cuit.length !== 11 || inFlight) return;

      inFlight = true;
      button.disabled = true;
      setStatus("Consultando ARCA...", "loading");
      toggleResult(true);

      try {
        const response = await fetch(`${endpoint}?cuit=${encodeURIComponent(cuit)}`, {
          headers: { "X-Requested-With": "XMLHttpRequest" },
          credentials: "same-origin",
        });
        const data = await response.json();

        if (!data || data.success !== true) {
          const errorUi = humanizarErrorLookup(data?.error || "No se encontraron datos para ese CUIT/CUIL.");
          if (errorUi.allowManual) {
            activarCargaManual(cuit, errorUi.message);
          } else {
            activeInput.value = "0";
            setStatus(errorUi.message, errorUi.type);
            emitStateChange("error");
          }
          return;
        }

        applyData(data.datos || {});
        if (window.Notif?.exito) {
          window.Notif.exito(`Datos cargados: ${(data.datos?.nombre || "Cliente").trim()}`);
        }
      } catch (_error) {
        activeInput.value = "0";
        setStatus("No se pudo consultar ARCA en este momento.", "error");
        emitStateChange("error");
        if (window.Notif?.error) {
          window.Notif.error("No se pudo consultar ARCA en este momento.");
        }
      } finally {
        inFlight = false;
        button.disabled = false;
      }
    }

    cuitInput.addEventListener("input", () => {
      const formatted = formatearCuit(cuitInput.value);
      if (formatted !== cuitInput.value) {
        cuitInput.value = formatted;
      }

      const actual = limpiarCuit(cuitInput.value);
      if (actual !== "") {
        setReceptorMode("lookup", { preserveLookup: true });
      }
      if (actual !== ultimoConsultado) {
        activeInput.value = "0";
        setValue(nombreInput, "");
        setValue(condIvaInput, "");
        setValue(estadoInput, "");
        setValue(direccionInput, "");
        setChecked(confirmInput, false);
        setEditorOpen(false);
        updateSummaryPreview();
        toggleResult(false);
        setStatus("", "info");
        updateReceptorSummary();
        emitStateChange("typing");
      }
    });

    cuitInput.addEventListener("blur", () => {
      const cuit = limpiarCuit(cuitInput.value);
      if (cuit.length === 11 && cuit !== ultimoConsultado) {
        consultar();
      }
    });

    button.addEventListener("click", consultar);

    if (confirmInput) {
      confirmInput.addEventListener("change", refreshConfirmedState);
    }

    if (toggleEditorButton) {
      toggleEditorButton.addEventListener("click", () => {
        setEditorOpen(!(editorStateInput?.value === "1"));
        emitStateChange("editor");
      });
    }

    if (applyButton) {
      applyButton.addEventListener("click", () => {
        if (activeInput.value !== "1") return;
        setChecked(confirmInput, true);
        refreshConfirmedState();
      });
    }

    if (clearButton) {
      clearButton.addEventListener("click", () => {
        clearLookup(false);
      });
    }

    if (select) {
      select.addEventListener("change", () => {
        if (select.value !== "" && select.value !== "0") {
          lastSelectedClient = select.value;
        }
        if (select.value !== "" && receptorMode?.dataset.mode !== "selector") {
          setReceptorMode("selector", { preserveLookup: false });
        } else {
          updateReceptorSummary();
          emitStateChange("select");
        }
      });
    }

    receptorModeButtons.forEach((btn) => {
      btn.addEventListener("click", () => {
        const mode = btn.dataset.receptorModeBtn === "lookup" ? "lookup" : "selector";
        setReceptorMode(mode);
      });
    });

    [nombreInput, condIvaInput, direccionInput].forEach((field) => {
      if (!field) return;
      field.addEventListener("input", () => {
        updateSummaryPreview();
        emitStateChange("edit");
      });
      field.addEventListener("change", () => {
        updateSummaryPreview();
        emitStateChange("edit");
      });
    });

    form.addEventListener("submit", (event) => {
      if (activeInput.value === "1" && confirmInput && !isChecked(confirmInput)) {
        event.preventDefault();
        refreshConfirmedState();
        if (window.Notif?.info) {
          window.Notif.info("Confirma si quieres emitir con los datos de ARCA o descartalos.");
        }
        return;
      }

      if (activeInput.value === "1" && nombreInput && !String(nombreInput.value || "").trim()) {
        event.preventDefault();
        setEditorOpen(true);
        updateSummaryPreview();
        setStatus("Completa al menos la razon social del receptor para emitir con este CUIT/CUIL.", "warning");
        if (window.Notif?.info) {
          window.Notif.info("Completa la razon social del receptor para emitir.");
        }
      }
    });

    setEditorOpen(editorStateInput?.value === "1");
    updateSummaryPreview();
    if (activeInput.value === "1" || limpiarCuit(cuitInput.value) !== "") {
      setReceptorMode("lookup", { preserveLookup: true });
      refreshConfirmedState();
    } else {
      setReceptorMode("selector", { preserveLookup: true });
    }
    updateReceptorSummary();
    emitStateChange("init");
  });
})();

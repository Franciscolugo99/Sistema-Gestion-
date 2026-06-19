(() => {
  const SEARCH_MIN = 2;
  const SEARCH_LIMIT = 8;
  const CODE_LIMIT = 5;
  const moneyFormat = new Intl.NumberFormat("es-AR", {
    style: "currency",
    currency: "ARS",
    minimumFractionDigits: 2,
  });

  const escapeHtml = (value) =>
    String(value ?? "").replace(/[&<>"']/g, (char) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    })[char]);

  const parseNumber = (value) => {
    const normalized = String(value ?? "").trim().replace(",", ".");
    const number = Number.parseFloat(normalized);
    return Number.isFinite(number) ? number : 0;
  };

  const formatMoney = (value) => moneyFormat.format(Number.isFinite(value) ? value : 0);

  const normalizeCode = (value) => String(value ?? "").trim().toLowerCase();

  const pickClosestIva = (value) => {
    const candidates = [0, 2.5, 5, 10.5, 21, 27];
    const numeric = Number.parseFloat(value);
    if (!Number.isFinite(numeric)) return "21";
    let best = candidates[0];
    let delta = Number.POSITIVE_INFINITY;
    candidates.forEach((candidate) => {
      const currentDelta = Math.abs(candidate - numeric);
      if (currentDelta < delta) {
        best = candidate;
        delta = currentDelta;
      }
    });
    return String(best);
  };

  document.querySelectorAll("[data-factura-manual-form]").forEach((form) => {
    const body = form.querySelector("[data-fm-items-body]");
    const template = document.getElementById("facturaManualRowTemplate");
    const searchInput = form.querySelector("[data-fm-product-search]");
    const searchResults = form.querySelector("[data-fm-search-results]");
    const codeInput = form.querySelector("[data-fm-code-search]");
    const codeButton = form.querySelector("[data-fm-code-btn]");
    const addRowButton = form.querySelector("[data-fm-add-row]");
    const totalNode = form.querySelector("[data-fm-total]");
    const countNodes = Array.from(form.querySelectorAll("[data-fm-count]"));
    const statusNode = form.querySelector("[data-fm-status]");
    const searchUrl = String(form.dataset.productSearchUrl || "").trim();
    const fallbackSearchUrl = "api/index.php?action=buscar_productos";
    const isReadOnly = form.dataset.fmDisabled === "1";
    const receptorEmpty = form.querySelector("[data-receptor-empty]");
    const receptorSelected = form.querySelector("[data-receptor-selected]");
    const receptorMirrorNombre = form.querySelector("[data-receptor-mirror-nombre]");
    const receptorMirrorCuit = form.querySelector("[data-receptor-mirror-cuit]");
    const receptorMirrorCondIva = form.querySelector("[data-receptor-mirror-cond-iva]");
    const receptorMirrorDireccion = form.querySelector("[data-receptor-mirror-direccion]");
    const receptorAppliedNote = form.querySelector("[data-receptor-applied-note]");
    const receptorClear = form.querySelector("[data-receptor-clear]");
    const sourceSelect = form.querySelector("[data-lookup-select]");
    const sourceMode = form.querySelector("[data-receptor-mode]");
    const sourceActive = form.querySelector("[data-lookup-activo]");
    const sourceConfirm = form.querySelector("[data-lookup-confirm]");
    const sourceNombre = form.querySelector("[data-lookup-nombre]");
    const sourceCuit = form.querySelector("[data-lookup-cuit]");
    const sourceCondIva = form.querySelector("[data-lookup-cond-iva]");
    const sourceDireccion = form.querySelector("[data-lookup-direccion]");
    const sourceLookupClear = form.querySelector("[data-lookup-clear]");

    if (!body || !template || !searchUrl || isReadOnly) return;

    let searchAbort = null;
    let rowLookupAbort = null;
    let debounceTimer = null;
    let searchItems = [];

    const setMirrorEditable = (editable) => {
      [receptorMirrorNombre, receptorMirrorCuit, receptorMirrorDireccion].forEach((field) => {
        if (!(field instanceof HTMLInputElement)) return;
        field.readOnly = !editable;
        field.classList.toggle("is-readonly", !editable);
      });
      if (receptorMirrorCondIva instanceof HTMLSelectElement) {
        receptorMirrorCondIva.disabled = !editable;
        receptorMirrorCondIva.classList.toggle("is-readonly", !editable);
      }
    };

    const syncMirrorFromSource = (detail = {}) => {
      const mode = detail.mode || sourceMode?.dataset.mode || "selector";
      const active = detail.active ?? (String(sourceActive?.value || "0") === "1");
      const confirmed = detail.confirmed ?? (sourceConfirm ? !!sourceConfirm.checked : false);
      const selectedOption = sourceSelect?.options?.[sourceSelect.selectedIndex] || null;
      const selectedClient = {
        nombre: selectedOption?.dataset?.clienteNombre || String(selectedOption?.text || "").trim(),
        cuit: selectedOption?.dataset?.clienteCuit || "",
        condIva: selectedOption?.dataset?.clienteCondIva || "",
      };

      const selectorHasValue = sourceSelect && sourceSelect.value !== "";
      const lookupHasDraft =
        String(sourceNombre?.value || "").trim() !== "" ||
        String(sourceCuit?.value || "").trim() !== "" ||
        String(sourceDireccion?.value || "").trim() !== "" ||
        active;

      if (receptorEmpty) {
        receptorEmpty.hidden = mode !== "lookup" || lookupHasDraft;
      }
      if (receptorSelected) {
        receptorSelected.hidden = false;
      }

      if (mode === "lookup") {
        setMirrorEditable(true);
        if (receptorMirrorNombre) receptorMirrorNombre.value = sourceNombre?.value || "";
        if (receptorMirrorCuit) receptorMirrorCuit.value = sourceCuit?.value || "";
        if (receptorMirrorCondIva) receptorMirrorCondIva.value = sourceCondIva?.value || "";
        if (receptorMirrorDireccion) receptorMirrorDireccion.value = sourceDireccion?.value || "";
        if (receptorAppliedNote) {
          receptorAppliedNote.textContent = confirmed
            ? "Datos de ARCA aplicados. Puedes editarlos si hace falta."
            : "Busca el CUIT y aplica los datos para usar ARCA como fuente del receptor.";
        }
        if (receptorClear) {
          receptorClear.hidden = !lookupHasDraft;
        }
        return;
      }

      setMirrorEditable(false);
      if (!selectorHasValue) {
        if (receptorMirrorNombre) receptorMirrorNombre.value = "";
        if (receptorMirrorCuit) receptorMirrorCuit.value = "";
        if (receptorMirrorCondIva) receptorMirrorCondIva.value = "";
        if (receptorMirrorDireccion) receptorMirrorDireccion.value = "";
        if (receptorAppliedNote) {
          receptorAppliedNote.textContent = "Selecciona un cliente de FLUS o cambia a ARCA para completar el receptor.";
        }
        if (receptorClear) {
          receptorClear.hidden = true;
        }
        return;
      }

      if (receptorMirrorNombre) receptorMirrorNombre.value = selectedClient.nombre || (sourceSelect?.value === "0" ? "Consumidor Final" : "");
      if (receptorMirrorCuit) receptorMirrorCuit.value = selectedClient.cuit || "";
      if (receptorMirrorCondIva) receptorMirrorCondIva.value = selectedClient.condIva || (sourceSelect?.value === "0" ? "CF" : "");
      if (receptorMirrorDireccion) receptorMirrorDireccion.value = "";
      if (receptorAppliedNote) {
        receptorAppliedNote.textContent = sourceSelect?.value === "0"
          ? "Se emitira como Consumidor Final."
          : "Se usara el cliente seleccionado en FLUS como base del receptor.";
      }
      if (receptorClear) {
        receptorClear.hidden = true;
      }
    };

    const syncSourceFromMirror = () => {
      if (receptorMirrorNombre && sourceNombre && sourceNombre.value !== receptorMirrorNombre.value) {
        sourceNombre.value = receptorMirrorNombre.value;
        sourceNombre.dispatchEvent(new Event("input", { bubbles: true }));
      }
      if (receptorMirrorCuit && sourceCuit && sourceCuit.value !== receptorMirrorCuit.value) {
        sourceCuit.value = receptorMirrorCuit.value;
        sourceCuit.dispatchEvent(new Event("input", { bubbles: true }));
      }
      if (receptorMirrorCondIva && sourceCondIva && sourceCondIva.value !== receptorMirrorCondIva.value) {
        sourceCondIva.value = receptorMirrorCondIva.value;
        sourceCondIva.dispatchEvent(new Event("input", { bubbles: true }));
      }
      if (receptorMirrorDireccion && sourceDireccion && sourceDireccion.value !== receptorMirrorDireccion.value) {
        sourceDireccion.value = receptorMirrorDireccion.value;
        sourceDireccion.dispatchEvent(new Event("input", { bubbles: true }));
      }
    };

    const notify = (message, type = "info") => {
      const text = String(message || "").trim();
      if (!text) return;

      if (window.Notif) {
        if (type === "success" && typeof window.Notif.exito === "function") {
          window.Notif.exito(text);
          return;
        }
        if (type === "warning" && typeof window.Notif.advertencia === "function") {
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
        const toastType = type === "warning" ? "warn" : type;
        window.showToast(text, toastType, type === "error" ? 3600 : 2800);
      }
    };

    const setStatus = (message, type = "info", options = {}) => {
      if (!statusNode) return;
      const shouldToast = options.toast || ((type === "error" || type === "warning") && options.inline !== true);
      if (shouldToast) {
        statusNode.textContent = "";
        statusNode.dataset.state = "";
        notify(message, type);
        return;
      }
      statusNode.textContent = message;
      statusNode.dataset.state = type;
    };

    const clearStatus = () => {
      if (!statusNode) return;
      statusNode.textContent = "";
      statusNode.dataset.state = "";
    };

    const getRows = () => Array.from(body.querySelectorAll("[data-fm-row]"));

    const syncRow = (row) => {
      const qtyInput = row.querySelector("[data-fm-qty]");
      const priceInput = row.querySelector("[data-fm-price]");
      const subtotalNode = row.querySelector("[data-fm-subtotal]");
      const qty = parseNumber(qtyInput?.value);
      const price = parseNumber(priceInput?.value);
      const subtotal = Math.max(0, qty) * Math.max(0, price);
      if (subtotalNode) {
        subtotalNode.textContent = formatMoney(subtotal);
      }
      return subtotal;
    };

    const syncRemoveButtons = () => {
      const rows = getRows();
      rows.forEach((row) => {
        const button = row.querySelector("[data-fm-remove-row]");
        if (!button) return;
        if (button.dataset.fmRemoveMode === "icon") {
          button.innerHTML = "&times;";
          button.setAttribute("aria-label", rows.length === 1 ? "Vaciar fila" : "Quitar fila");
          return;
        }
        button.textContent = rows.length === 1 ? "Vaciar" : "Quitar";
      });
    };

    const syncSummary = () => {
      let total = 0;
      let printableCount = 0;

      getRows().forEach((row) => {
        total += syncRow(row);
        const description = String(row.querySelector("[data-fm-description]")?.value || "").trim();
        if (description !== "") {
          printableCount += 1;
        }
      });

      if (totalNode) totalNode.textContent = formatMoney(total);
      countNodes.forEach((node) => {
        node.textContent = String(printableCount);
      });
      syncRemoveButtons();
    };

    const hideResults = () => {
      if (!searchResults) return;
      searchResults.hidden = true;
      searchResults.innerHTML = "";
      searchItems = [];
    };

    const createRow = () => {
      const fragment = template.content.cloneNode(true);
      const row = fragment.querySelector("[data-fm-row]");
      if (!row) return null;
      body.appendChild(fragment);
      return body.lastElementChild;
    };

    const clearRowValidation = (row) => {
      row.classList.remove("is-invalid");
      row.querySelectorAll('[aria-invalid="true"]').forEach((field) => {
        field.removeAttribute("aria-invalid");
      });
    };

    const markRowInvalid = (row, field) => {
      row.classList.add("is-invalid");
      if (field instanceof HTMLElement) field.setAttribute("aria-invalid", "true");
      return field instanceof HTMLElement ? field : null;
    };

    const clearRow = (row) => {
      clearRowValidation(row);
      row.querySelectorAll("input").forEach((input) => {
        if (input.matches("[data-fm-qty]")) {
          input.value = "1";
        } else {
          input.value = "";
        }
      });
      const ivaSelect = row.querySelector("[data-fm-iva]");
      if (ivaSelect) ivaSelect.value = "21";
      syncSummary();
    };

    const validateRowsBeforeSubmit = () => {
      const rows = getRows();
      let printableCount = 0;
      let firstError = "";
      let firstInvalidField = null;

      rows.forEach((row) => {
        clearRowValidation(row);

        const codeField = row.querySelector("[data-fm-code]");
        const descriptionField = row.querySelector("[data-fm-description]");
        const qtyField = row.querySelector("[data-fm-qty]");
        const priceField = row.querySelector("[data-fm-price]");
        const code = String(codeField?.value || "").trim();
        const description = String(descriptionField?.value || "").trim();
        const qtyRaw = String(qtyField?.value || "").trim();
        const priceRaw = String(priceField?.value || "").trim();
        const hasAnyContent = code !== "" || description !== "" || qtyRaw !== "" || priceRaw !== "";

        if (!hasAnyContent) {
          return;
        }
        if (description === "") {
          const invalidField = markRowInvalid(row, descriptionField);
          firstInvalidField ||= invalidField;
          firstError ||= "Cada fila cargada debe tener descripción.";
          return;
        }

        const qty = parseNumber(qtyRaw);
        if (!(qty > 0)) {
          const invalidField = markRowInvalid(row, qtyField);
          firstInvalidField ||= invalidField;
          firstError ||= "Cada ítem debe tener una cantidad mayor a 0.";
          return;
        }

        const price = parseNumber(priceRaw);
        if (priceRaw === "" || price < 0) {
          const invalidField = markRowInvalid(row, priceField);
          firstInvalidField ||= invalidField;
          firstError ||= "Cada ítem debe tener un precio válido.";
          return;
        }

        printableCount += 1;
      });

      if (printableCount === 0) {
        const firstRow = rows[0];
        firstInvalidField ||= firstRow
          ? markRowInvalid(firstRow, firstRow.querySelector("[data-fm-description]"))
          : null;
        firstError ||= "Debés cargar al menos un ítem para emitir la factura manual.";
      }

      if (firstError !== "") {
        setStatus(firstError, "warning");
        firstInvalidField?.focus();
        return false;
      }

      clearStatus();
      return true;
    };

    const focusRow = (row) => {
      const qtyInput = row.querySelector("[data-fm-qty]");
      const descriptionInput = row.querySelector("[data-fm-description]");
      if (descriptionInput && String(descriptionInput.value || "").trim() === "") {
        descriptionInput.focus();
        return;
      }
      if (qtyInput) {
        qtyInput.focus();
        qtyInput.select();
      }
    };

    const fillRowFromProduct = (row, product, options = {}) => {
      if (!row || !product) return;
      const code = row.querySelector("[data-fm-code]");
      const description = row.querySelector("[data-fm-description]");
      const qty = row.querySelector("[data-fm-qty]");
      const price = row.querySelector("[data-fm-price]");
      const iva = row.querySelector("[data-fm-iva]");

      if (code) code.value = String(product.codigo || "");
      if (description) description.value = String(product.nombre || "Producto");
      if (qty) {
        qty.value = options.keepQty && String(qty.value || "").trim() !== ""
          ? qty.value
          : (product.es_pesable ? "1.000" : "1");
      }
      if (price) {
        const existing = String(price.value || "").trim();
        if (!(options.keepPrice && existing !== "")) {
          price.value = Number(product.precio || 0).toFixed(2);
        }
      }
      if (iva) {
        iva.value = pickClosestIva(product.iva_porcentaje);
      }

      syncSummary();
      focusRow(row);
    };

    const findBestMatch = (products, query, exactOnly = false) => {
      const normalized = normalizeCode(query);
      if (!normalized) return null;
      const exact = products.find((product) => normalizeCode(product.codigo) === normalized);
      if (exact) return exact;
      if (exactOnly) return null;
      return products[0] || null;
    };

    const buildSearchRequestUrls = (query, limit) => {
      const suffix = `q=${encodeURIComponent(query)}&limit=${limit}`;
      const candidates = [];
      [searchUrl, fallbackSearchUrl].forEach((baseUrl) => {
        const url = String(baseUrl || "").trim();
        if (!url) return;
        const separator = url.includes("?") ? "&" : "?";
        if (!candidates.includes(url + separator + suffix)) {
          candidates.push(url + separator + suffix);
        }
      });
      return candidates;
    };

    const parseProductsResponse = async (response) => {
      const data = await response.json();
      if (!data || data.ok !== true || !Array.isArray(data.productos)) {
        throw new Error(data?.error || "No se pudo buscar productos");
      }
      return data.productos;
    };

    const requestProductsAbortable = async (query, abortController, limit = SEARCH_LIMIT) => {
      const urls = buildSearchRequestUrls(query, limit);
      let lastError = null;
      for (const url of urls) {
        try {
          const response = await fetch(url, {
            credentials: "same-origin",
            headers: { "X-Requested-With": "XMLHttpRequest" },
            signal: abortController.signal,
          });
          if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
          }
          return await parseProductsResponse(response);
        } catch (error) {
          if (error?.name === "AbortError") {
            throw error;
          }
          lastError = error;
        }
      }
      throw lastError || new Error("No se pudo buscar productos");
    };

    const requestProducts = async (query, limit = SEARCH_LIMIT) => {
      const urls = buildSearchRequestUrls(query, limit);
      let lastError = null;
      for (const url of urls) {
        try {
          const response = await fetch(url, {
            credentials: "same-origin",
            headers: { "X-Requested-With": "XMLHttpRequest" },
          });
          if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
          }
          return await parseProductsResponse(response);
        } catch (error) {
          lastError = error;
        }
      }
      throw lastError || new Error("No se pudo buscar productos");
    };

    const addProductAsNewRow = (product) => {
      const row = createRow();
      if (!row) return;
      fillRowFromProduct(row, product);
      setStatus("", "");
      notify(`Producto agregado: ${product.nombre || product.codigo || "Item"}`, "success");
    };

    const renderSearchResults = (items) => {
      if (!searchResults) return;
      searchItems = items;
      if (!items.length) {
        hideResults();
        setStatus("No se encontraron productos para esa búsqueda.", "warning");
        return;
      }

      searchResults.innerHTML = items.map((product, index) => `
        <button type="button" class="fact-manual-suggestion" data-fm-suggestion="${index}">
          <span class="fact-manual-suggestion__name">${escapeHtml(product.nombre || "Producto")}</span>
          <span class="fact-manual-suggestion__meta">${escapeHtml(product.codigo || "Sin codigo")} · ${formatMoney(Number(product.precio || 0))}</span>
        </button>
      `).join("");
      searchResults.hidden = false;
      clearStatus();
    };

    const lookupAndFillRow = async (row, query) => {
      const normalized = String(query || "").trim();
      if (normalized.length < SEARCH_MIN) return;

      if (rowLookupAbort) {
        rowLookupAbort.abort();
      }
      rowLookupAbort = new AbortController();

      try {
        const products = await requestProductsAbortable(normalized, rowLookupAbort, CODE_LIMIT);
        const product = findBestMatch(products, normalized, true) || findBestMatch(products, normalized, false);
        if (!product) {
          setStatus(`No encontre un producto para el codigo "${normalized}".`, "warning", { toast: true });
          return;
        }
        fillRowFromProduct(row, product, { keepQty: true, keepPrice: false });
        clearStatus();
      } catch (error) {
        if (error?.name === "AbortError") return;
        setStatus("No se pudo completar el producto desde el codigo.", "error", { toast: true });
      }
    };

    const handleToolbarCode = async () => {
      const query = String(codeInput?.value || "").trim();
      if (query.length < SEARCH_MIN) {
        setStatus("Escribe al menos 2 caracteres para buscar por codigo.", "warning");
        return;
      }

      try {
        setStatus("Buscando producto por codigo...", "loading");
        const products = await requestProducts(query, CODE_LIMIT);
        const product = findBestMatch(products, query, true) || findBestMatch(products, query, false);
        if (!product) {
          setStatus(`No encontre un producto con el codigo "${query}".`, "warning", { toast: true });
          return;
        }
        addProductAsNewRow(product);
        if (codeInput) codeInput.value = "";
      } catch (_error) {
        setStatus("No se pudo buscar el producto en este momento.", "error", { toast: true });
      }
    };

    if (addRowButton) {
      addRowButton.addEventListener("click", () => {
        const row = createRow();
        if (!row) return;
        syncSummary();
        clearStatus();
        const descriptionInput = row.querySelector("[data-fm-description]");
        if (descriptionInput) descriptionInput.focus();
      });
    }

    if (codeButton) {
      codeButton.addEventListener("click", handleToolbarCode);
    }

    if (codeInput) {
      codeInput.addEventListener("keydown", (event) => {
        if (event.key !== "Enter") return;
        event.preventDefault();
        handleToolbarCode();
      });
    }

    if (searchInput) {
      searchInput.addEventListener("input", () => {
        const query = String(searchInput.value || "").trim();
        clearTimeout(debounceTimer);

        if (searchAbort) {
          searchAbort.abort();
        }
        if (query.length < SEARCH_MIN) {
          hideResults();
          clearStatus();
          return;
        }

        debounceTimer = window.setTimeout(async () => {
          searchAbort = new AbortController();
          try {
            setStatus("Buscando productos...", "loading");
            const products = await requestProductsAbortable(query, searchAbort, SEARCH_LIMIT);
            renderSearchResults(products);
          } catch (error) {
            if (error?.name === "AbortError") return;
            hideResults();
            setStatus("No se pudo buscar productos en este momento.", "error");
          }
        }, 180);
      });
    }

    if (searchResults) {
      searchResults.addEventListener("click", (event) => {
        const button = event.target.closest("[data-fm-suggestion]");
        if (!(button instanceof HTMLElement)) return;
        const index = Number.parseInt(button.dataset.fmSuggestion || "-1", 10);
        const product = searchItems[index];
        if (!product) return;
        addProductAsNewRow(product);
        hideResults();
        if (searchInput) searchInput.value = "";
      });
    }

    body.addEventListener("input", (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
      const row = target.closest("[data-fm-row]");
      if (row instanceof HTMLElement) {
        target.removeAttribute("aria-invalid");
        if (!row.querySelector('[aria-invalid="true"]')) row.classList.remove("is-invalid");
      }
      if (target.matches("[data-fm-qty], [data-fm-price], [data-fm-description], [data-fm-code]")) {
        syncSummary();
      }
    });

    body.addEventListener("change", (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) return;
      if (target.matches("[data-fm-iva], [data-fm-qty], [data-fm-price], [data-fm-description]")) {
        syncSummary();
      }
    });

    body.addEventListener("click", (event) => {
      const button = event.target.closest("[data-fm-remove-row]");
      if (!(button instanceof HTMLElement)) return;
      const row = button.closest("[data-fm-row]");
      if (!(row instanceof HTMLElement)) return;

      const rows = getRows();
      if (rows.length === 1) {
        clearRow(row);
        const descriptionInput = row.querySelector("[data-fm-description]");
        if (descriptionInput) descriptionInput.focus();
        return;
      }

      row.remove();
      syncSummary();
      clearStatus();
    });

    body.addEventListener("keydown", (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement) || !target.matches("[data-fm-code]")) return;
      if (event.key !== "Enter") return;
      event.preventDefault();
      const row = target.closest("[data-fm-row]");
      if (!(row instanceof HTMLElement)) return;
      lookupAndFillRow(row, target.value);
    });

    body.addEventListener("blur", (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement) || !target.matches("[data-fm-code]")) return;
      const row = target.closest("[data-fm-row]");
      if (!(row instanceof HTMLElement)) return;
      lookupAndFillRow(row, target.value);
    }, true);

    document.addEventListener("click", (event) => {
      const target = event.target;
      if (!(target instanceof Node)) return;
      if (searchResults?.contains(target) || searchInput?.contains(target)) return;
      hideResults();
    });

    form.addEventListener("submit", (event) => {
      if (!validateRowsBeforeSubmit()) {
        event.preventDefault();
      }
    });

    form.addEventListener("invalid", (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement) || !target.matches("[data-fm-qty], [data-fm-price]")) return;
      event.preventDefault();
      const row = target.closest("[data-fm-row]");
      if (!(row instanceof HTMLElement)) return;

      clearRowValidation(row);
      markRowInvalid(row, target);
      const message = target.matches("[data-fm-qty]")
        ? "La cantidad debe ser mayor a 0."
        : "El precio debe ser 0 o mayor.";
      setStatus(message, "warning");
      target.focus();
    }, true);

    form.addEventListener("flus:receptor-state-changed", (event) => {
      syncMirrorFromSource(event.detail || {});
    });

    [receptorMirrorNombre, receptorMirrorCuit, receptorMirrorCondIva, receptorMirrorDireccion].forEach((field) => {
      if (!field) return;
      field.addEventListener("input", syncSourceFromMirror);
      field.addEventListener("change", syncSourceFromMirror);
    });

    if (receptorClear) {
      receptorClear.addEventListener("click", () => {
        if (sourceMode?.dataset.mode === "lookup" && sourceLookupClear instanceof HTMLElement) {
          sourceLookupClear.click();
          return;
        }
        if (sourceSelect) {
          sourceSelect.value = "0";
          sourceSelect.dispatchEvent(new Event("change", { bubbles: true }));
        }
      });
    }

    syncSummary();
    syncMirrorFromSource();
  });
})();

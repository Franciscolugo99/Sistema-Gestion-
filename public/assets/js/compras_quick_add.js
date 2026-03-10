document.addEventListener("DOMContentLoaded", () => {
  const api = window.FlusComprasQuick;
  const checkbox = document.getElementById("quickAddCheckbox");
  const toggle = document.querySelector(".quick-add-toggle");
  const frequentNode = document.getElementById("quickAddFrequentData");

  if (!api || !checkbox || !toggle) return;

  const MAX_QTY = 99999;
  const MAX_COST = 9999999;
  let currentTab = "proveedor";
  let currentSearch = "";
  let frequentItems = [];
  let selectedProducts = new Map();

  try {
    const parsed = JSON.parse(frequentNode?.textContent || "[]");
    frequentItems = Array.isArray(parsed) ? parsed : [];
  } catch (error) {
    frequentItems = [];
  }

  const panel = document.createElement("section");
  panel.className = "quick-add-panel";
  panel.hidden = true;
  toggle.insertAdjacentElement("afterend", panel);

  const notify = (message, type = "info") => {
    if (typeof window.showToast === "function") {
      window.showToast(message, type);
      return;
    }
    console.log(`[${type}] ${message}`);
  };

  const escapeHtml = (value) =>
    String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");

  const getProductMap = () => {
    const map = new Map();
    api.getProducts().forEach((product) => map.set(product.id, product));
    return map;
  };

  const getSelectedCount = () => selectedProducts.size;

  const getDefaultQty = (product) =>
    api.isPesable(product) ? "1.000" : "1";

  const getDefaultCost = (product) =>
    Number(product.ultimoCosto || 0).toFixed(2);

  const rememberRowState = (row) => {
    if (!row) return;
    const productId = parseInt(row.dataset.productId || "0", 10);
    if (productId <= 0) return;

    const product = api.getProducts().find((item) => item.id === productId);
    if (!product) return;

    const qty = parseFloat(row.querySelector(".quick-add-qty")?.value || 0);
    const cost = parseFloat(row.querySelector(".quick-add-cost")?.value || 0);

    selectedProducts.set(productId, {
      product,
      qty,
      cost,
    });
  };

  const getProductsForCurrentTab = () => {
    const providerState = api.getProveedorState();
    const allProducts = api.getProducts();
    let products = [];

    if (currentTab === "proveedor") {
      if (providerState.proveedorId > 0) {
        products = allProducts.filter(
          (product) => product.proveedorId === providerState.proveedorId,
        );
      } else if (providerState.proveedorNombreNormalizado) {
        products = allProducts.filter(
          (product) =>
            api.normalizeProviderValue(product.proveedorNombre) ===
            providerState.proveedorNombreNormalizado,
        );
      } else if (currentSearch.trim()) {
        products = allProducts;
      }
    } else if (currentTab === "frecuentes") {
      const productMap = getProductMap();
      products = frequentItems
        .map((item) => {
          const product = productMap.get(parseInt(item.id || 0, 10));
          return product
            ? {
                ...product,
                vecesComprado: parseInt(item.veces || 0, 10),
              }
            : null;
        })
        .filter(Boolean);

      if (!products.length && currentSearch.trim()) {
        products = allProducts;
      }
    } else {
      products = allProducts;
    }

    const normalizedSearch = api.normalizeProviderValue(currentSearch);
    if (normalizedSearch) {
      products = products.filter((product) => {
        const nombre = api.normalizeProviderValue(product.nombre);
        const codigo = api.normalizeProviderValue(product.codigo);
        return (
          nombre.includes(normalizedSearch) ||
          codigo.includes(normalizedSearch)
        );
      });
    }

    return products
      .sort((a, b) => String(a.nombre).localeCompare(String(b.nombre), "es"))
      .slice(0, 36);
  };

  const buildRows = (products) =>
    products
      .map((product) => {
        const pesable = api.isPesable(product);
        const qtyStep = pesable ? "0.001" : "1";
        const qtyMin = pesable ? "0.001" : "1";
        const remembered = selectedProducts.get(product.id);
        const qtyValue = remembered
          ? String(remembered.qty || getDefaultQty(product))
          : getDefaultQty(product);
        const costValue = remembered
          ? String(remembered.cost ?? getDefaultCost(product))
          : getDefaultCost(product);
        const badge = product.vecesComprado
          ? `<span class="quick-add-chip">${product.vecesComprado} compras</span>`
          : "";
        const checked = selectedProducts.has(product.id) ? "checked" : "";

        return `
          <tr data-product-id="${product.id}">
            <td class="center"><input type="checkbox" class="quick-add-check" ${checked}></td>
            <td>
              <button type="button" class="quick-add-product-trigger" data-quick-toggle-row>
                <span class="quick-add-product-name">${escapeHtml(product.nombre)}</span>
                <span class="quick-add-product-meta">${escapeHtml(product.codigo || "Sin codigo")} ${badge}</span>
              </button>
            </td>
            <td><input type="number" class="quick-add-input quick-add-qty" value="${qtyValue}" step="${qtyStep}" min="${qtyMin}"></td>
            <td><input type="number" class="quick-add-input quick-add-cost" value="${costValue}" step="0.01" min="0"></td>
            <td class="center"><button type="button" class="btn btn-secondary btn-compact" data-quick-add-one>Agregar</button></td>
          </tr>
        `;
      })
      .join("");

  const render = () => {
    panel.hidden = !checkbox.checked;
    if (panel.hidden) return;

    const providerState = api.getProveedorState();
    const products = getProductsForCurrentTab();
    const noProvider =
      currentTab === "proveedor" &&
      !providerState.proveedorId &&
      !providerState.proveedorNombreNormalizado &&
      !currentSearch.trim();
    const helpText = noProvider
      ? "Elegi un proveedor para ver sus productos habituales y cargar varios de una sola vez."
      : "No hay productos para este filtro. Podes cambiar de pestana o ampliar la busqueda.";

    panel.innerHTML = `
      <div class="quick-add-panel-head">
        <div>
          <h3 class="quick-add-title">Carga masiva</h3>
          <p class="quick-add-sub">Suma varios productos rapido y ajusta cantidad/costo desde una sola tabla.</p>
        </div>
        <div class="quick-add-tabs">
          <button type="button" class="quick-add-tab ${currentTab === "proveedor" ? "is-active" : ""}" data-tab="proveedor">Del proveedor</button>
          <button type="button" class="quick-add-tab ${currentTab === "frecuentes" ? "is-active" : ""}" data-tab="frecuentes">Frecuentes</button>
          <button type="button" class="quick-add-tab ${currentTab === "todos" ? "is-active" : ""}" data-tab="todos">Todos</button>
        </div>
      </div>
      <div class="quick-add-toolbar">
        <input type="search" class="quick-add-search" placeholder="Buscar por nombre o codigo..." value="${escapeHtml(currentSearch)}">
        <div class="quick-add-toolbar-actions">
          <span class="quick-add-selected-count">Seleccionados: ${getSelectedCount()}</span>
          <button type="button" class="btn btn-secondary btn-compact" data-quick-toggle-select>Seleccionar visibles</button>
          <button type="button" class="btn btn-primary btn-compact" data-quick-add-selected>Agregar seleccionados</button>
        </div>
      </div>
      ${products.length > 0 ? `
        <div class="table-wrapper quick-add-table-wrap">
          <table class="compras-table quick-add-table">
            <thead>
              <tr>
                <th class="center">Ok</th>
                <th>Producto</th>
                <th class="right">Ult. costo</th>
                <th>Cant.</th>
                <th>Costo</th>
                <th class="center">Accion</th>
              </tr>
            </thead>
            <tbody>${buildRows(products)}</tbody>
          </table>
        </div>
      ` : `<div class="quick-add-empty">${helpText}</div>`}
    `;
  };

  const renderPreservingSearchFocus = () => {
    const active = document.activeElement;
    const isSearchFocused =
      active instanceof HTMLElement && active.matches(".quick-add-search");
    const selectionStart = isSearchFocused ? active.selectionStart : null;
    const selectionEnd = isSearchFocused ? active.selectionEnd : null;

    render();

    if (!isSearchFocused) return;

    const nextSearch = panel.querySelector(".quick-add-search");
    if (!nextSearch) return;

    nextSearch.focus();
    if (
      typeof selectionStart === "number" &&
      typeof selectionEnd === "number"
    ) {
      nextSearch.setSelectionRange(selectionStart, selectionEnd);
    }
  };

  const collectEntries = (singleRow = null) => {
    if (singleRow) {
      const productId = parseInt(singleRow.dataset.productId || "0", 10);
      const product = api.getProducts().find((item) => item.id === productId);
      return product
        ? [{
            product,
            qty: parseFloat(singleRow.querySelector(".quick-add-qty")?.value || 0),
            cost: parseFloat(singleRow.querySelector(".quick-add-cost")?.value || 0),
          }]
        : [];
    }

    return Array.from(selectedProducts.values());
  };

  const syncCounter = () => {
    const counter = panel.querySelector(".quick-add-selected-count");
    if (counter) counter.textContent = `Seleccionados: ${getSelectedCount()}`;
  };

  const addEntries = (entries) => {
    if (!entries.length) {
      notify("Marca al menos un producto para agregar", "warning");
      return;
    }

    let added = 0;
    let merged = 0;
    let skipped = 0;

    entries.forEach(({ product, qty, cost }) => {
      if (!(qty > 0) || cost < 0 || qty > MAX_QTY || cost > MAX_COST) {
        skipped += 1;
        return;
      }

      const result = api.addProductToDraft(product, qty, cost);
      if (result.status === "added") added += 1;
      if (result.status === "merged") merged += 1;
      if (result.status === "skipped") skipped += 1;
    });

    entries.forEach(({ product }) => {
      selectedProducts.delete(product.id);
    });

    if (added || merged || skipped) {
      const summary = [];
      if (added) summary.push(`${added} agregados`);
      if (merged) summary.push(`${merged} actualizados`);
      if (skipped) summary.push(`${skipped} omitidos`);
      notify(
        `Carga masiva: ${summary.join(", ")}`,
        skipped ? "warning" : "success",
      );
    }

    render();
  };

  panel.addEventListener("click", (event) => {
    const tabButton = event.target.closest("[data-tab]");
    if (tabButton) {
      currentTab = tabButton.dataset.tab || "proveedor";
      render();
      return;
    }

    if (event.target.closest("[data-quick-toggle-select]")) {
      const checks = Array.from(panel.querySelectorAll(".quick-add-check"));
      const shouldCheck = checks.some((input) => !input.checked);

      checks.forEach((input) => {
        const row = input.closest("tr[data-product-id]");
        const productId = parseInt(row?.dataset.productId || "0", 10);
        input.checked = shouldCheck;
        if (productId > 0) {
          if (shouldCheck) rememberRowState(row);
          else selectedProducts.delete(productId);
        }
      });

      syncCounter();
      return;
    }

    if (event.target.closest("[data-quick-toggle-row]")) {
      const row = event.target.closest("tr[data-product-id]");
      const input = row?.querySelector(".quick-add-check");
      if (input) {
        input.checked = !input.checked;
        input.dispatchEvent(new Event("change", { bubbles: true }));
      }
      return;
    }

    if (event.target.closest("[data-quick-add-selected]")) {
      addEntries(collectEntries());
      return;
    }
    if (event.target.closest("[data-quick-add-one]")) {
      const row = event.target.closest("tr[data-product-id]");
      addEntries(collectEntries(row));
    }
  });

  panel.addEventListener("input", (event) => {
    if (event.target.matches(".quick-add-search")) {
      currentSearch = event.target.value || "";
      renderPreservingSearchFocus();
      return;
    }

    if (!event.target.matches(".quick-add-qty, .quick-add-cost")) return;

    const row = event.target.closest("tr[data-product-id]");
    const checkboxInput = row?.querySelector(".quick-add-check");
    if (checkboxInput?.checked) rememberRowState(row);
  });

  panel.addEventListener("change", (event) => {
    if (!event.target.matches(".quick-add-check")) return;

    const row = event.target.closest("tr[data-product-id]");
    const productId = parseInt(row?.dataset.productId || "0", 10);
    if (productId <= 0) return;

    if (event.target.checked) rememberRowState(row);
    else selectedProducts.delete(productId);

    syncCounter();
  });

  panel.addEventListener("keydown", (event) => {
    if (
      event.key === "Enter" &&
      event.target.matches(
        ".quick-add-search, .quick-add-qty, .quick-add-cost, .quick-add-check",
      )
    ) {
      event.preventDefault();
    }
  });

  checkbox.addEventListener("change", render);
  api.onProviderChange(() => {
    if (checkbox.checked) render();
  });
});

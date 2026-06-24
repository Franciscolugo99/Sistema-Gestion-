(() => {
  let initialized = false;
  let selectorOpen = false;
  let api = null;
  let modal = null;
  let search = null;
  let results = null;

  function normalizeText(value) {
    return String(value ?? "")
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .toLowerCase();
  }

  function isModalVisible() {
    return !!modal && !modal.classList.contains("hidden");
  }

  function promoSearchText(promo) {
    const parts = [promo.nombre, promo.tipo];
    (promo.items || []).forEach((item) => parts.push(item.nombre, item.codigo));
    return normalizeText(parts.join(" "));
  }

  function promoTypeLabel(tipo) {
    if (tipo === "COMBO_FIJO") return "Combo";
    if (tipo === "N_PAGA_M") return "NxM";
    if (tipo === "NTH_PCT") return "%";
    return "Promo";
  }

  function promoDetail(promo) {
    if (promo.tipo === "COMBO_FIJO") {
      return `${(promo.items || []).length} productos · ${api.formatearMoneda(promo.precio_combo)}`;
    }
    const item = promo.items?.[0];
    const cantidad = Number(promo.cantidadSugerida || item?.cantidad || 1);
    const itemEsPesable =
      item?.es_pesable === true ||
      item?.es_pesable === 1 ||
      item?.es_pesable === "1" ||
      item?.esPesable === true;
    return `${api.formatearCantidad({
      cantidad,
      unidadVenta: item?.unidad_venta || item?.unidadVenta || "UNID",
      esPesable: itemEsPesable,
    })} para activar`;
  }

  function renderNotice(type, message) {
    if (results && isModalVisible()) {
      results.innerHTML =
        `<div class="promo-selector-empty promo-selector-empty--${api.escapeHtml(type)}">${api.escapeHtml(message)}</div>`;
      return;
    }
    api.showMessage(type, message);
  }

  function render() {
    if (!results) return;
    const state = api.getPromos();
    const catalog = Array.isArray(state.promos) ? state.promos : [];
    const q = normalizeText(search?.value || "").trim();
    const promos = catalog
      .filter((promo) => !q || promoSearchText(promo).includes(q))
      .slice(0, 40);

    if (!state.disponibles) {
      results.innerHTML =
        '<div class="promo-selector-empty">Las promociones estan pausadas por horario o configuracion.</div>';
      return;
    }
    if (catalog.length === 0) {
      results.innerHTML =
        '<div class="promo-selector-empty">No hay promociones activas para cargar.</div>';
      return;
    }
    if (promos.length === 0) {
      results.innerHTML =
        '<div class="promo-selector-empty">No encontramos promos con ese texto.</div>';
      return;
    }

    results.innerHTML = promos
      .map((promo) => {
        const items = (promo.items || [])
          .slice(0, 3)
          .map((item) => {
            const codigo = item.codigo ? ` · ${api.escapeHtml(item.codigo)}` : "";
            const cant = Number(item.cantidad || promo.cantidadSugerida || 1);
            const qty = api.fmtQty3.format(cant).replace(/,000$/, "");
            return `<span>${api.escapeHtml(item.nombre || "Producto")}${codigo} <strong>x${qty}</strong></span>`;
          })
          .join("");
        const extra = (promo.items || []).length > 3
          ? `<span>+${(promo.items || []).length - 3} mas</span>`
          : "";
        return `
          <button type="button" class="promo-selector-card" data-promo-action="add" data-promo-key="${api.escapeHtml(promo.key)}">
            <span class="promo-selector-card__badge">${api.escapeHtml(promoTypeLabel(promo.tipo))}</span>
            <span class="promo-selector-card__body">
              <strong>${api.escapeHtml(promo.nombre)}</strong>
              <small>${api.escapeHtml(promoDetail(promo))}</small>
              <span class="promo-selector-card__items">${items}${extra}</span>
            </span>
            <span class="promo-selector-card__cta">Cargar</span>
          </button>
        `;
      })
      .join("");
  }

  async function openSelector() {
    if (!modal || selectorOpen) return;
    const state = api.getPromos();
    if (!state.disponibles || !Array.isArray(state.promos) || state.promos.length === 0) {
      await api.reloadPromos();
    }
    selectorOpen = true;
    modal.classList.remove("hidden");
    modal.setAttribute("aria-hidden", "false");
    if (search) search.value = "";
    render();
    setTimeout(() => search?.focus?.(), 30);
  }

  function closeSelector() {
    if (!modal) return;
    selectorOpen = false;
    modal.classList.add("hidden");
    modal.setAttribute("aria-hidden", "true");
    setTimeout(() => api.focusProduct(), 20);
  }

  async function fetchProductByCode(codigo) {
    if (!codigo) throw new Error("La promo no tiene codigo de producto para cargar.");
    const data = await api.fetchJson(
      `${api.apiBase}?action=buscar_producto&codigo=${encodeURIComponent(codigo)}`,
    );
    if (!data?.ok || !data.producto) {
      throw new Error(data?.error || "No se pudo cargar un producto de la promo.");
    }
    return data.producto;
  }

  function validatePromoStock(resolvedItems) {
    const plan = new Map();
    const cart = api.getCarrito();
    for (const item of resolvedItems) {
      const p = item.producto;
      const cantidad = Number(item.cantidad) || 1;
      const id = Number(p.id);
      const esPesable =
        p.es_pesable === true || p.es_pesable === 1 || p.es_pesable === "1";
      const unidadVenta = p.unidad_venta || (esPesable ? "KG" : "UNID");
      const stock = Number(p.stock) || 0;
      const inCart = cart.find((i) => Number(i.id) === id);
      const base = Number(inCart?.cantidad) || 0;
      const current = plan.get(id) || base;
      const tolerance = esPesable ? 0.01 : 0;
      if (current + cantidad > stock + tolerance) {
        const available = Math.max(stock - current, 0);
        return {
          ok: false,
          message: `No hay stock suficiente de "${p.nombre}". Disponible: ${available} ${unidadVenta}.`,
        };
      }
      plan.set(id, current + cantidad);
    }
    return { ok: true };
  }

  async function addSelectedPromo(promoKey) {
    const catalog = api.getPromos().promos || [];
    const promo = catalog.find((p) => p.key === promoKey);
    if (!promo) {
      renderNotice("error", "No se encontro la promo seleccionada");
      return;
    }

    try {
      const resolved = [];
      for (const item of promo.items || []) {
        resolved.push({
          producto: await fetchProductByCode(item.codigo),
          cantidad: Number(item.cantidad || promo.cantidadSugerida || 1),
        });
      }

      const stockCheck = validatePromoStock(resolved);
      if (!stockCheck.ok) {
        renderNotice("warning", stockCheck.message);
        return;
      }

      for (const item of resolved) {
        const added = await api.addResolvedProduct(
          item.producto,
          item.cantidad,
          { actualizarHint: false },
        );
        if (!added) return;
      }

      closeSelector();
      api.refreshAfterPromoAdd();
      api.showMessage("success", `Promo "${promo.nombre}" cargada al ticket`);
    } catch (e) {
      console.error("ERROR agregarPromoSeleccionada():", e);
      renderNotice("error", e?.message || "No se pudo cargar la promo");
    }
  }

  function init() {
    if (initialized || !window.FLUS_CAJA_PROMOS) return;
    api = window.FLUS_CAJA_PROMOS;
    modal = document.getElementById("promoSelectorModal");
    search = document.getElementById("promoSelectorSearch");
    results = document.getElementById("promoSelectorResults");
    if (!modal || !search || !results) return;
    initialized = true;

    window.FLUS_CAJA_PROMOS_SELECTOR = { open: openSelector, close: closeSelector };
    window.addEventListener("flus:caja-promos-open", openSelector);
    document.getElementById("btnPromosCaja")?.addEventListener("click", openSelector);
    search.addEventListener("input", render);
    modal.addEventListener("click", (e) => {
      const target = e.target;
      if (!(target instanceof Element)) return;
      if (target.matches("[data-promo-selector-close]")) {
        closeSelector();
        return;
      }
      const addButton = target.closest('[data-promo-action="add"]');
      if (addButton) addSelectedPromo(addButton.dataset.promoKey || "");
    });
    modal.addEventListener("keydown", (e) => {
      if (e.key === "Escape") {
        e.preventDefault();
        closeSelector();
      }
    });
  }

  window.addEventListener("flus:caja-promos-ready", init);
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();

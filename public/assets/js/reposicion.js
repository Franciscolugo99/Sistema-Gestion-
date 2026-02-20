// FLUS - reposicion.js
// Mejoras UX/performance en vista "Configuración":
// - No cargar productos por defecto (evita listas gigantes)
// - Búsqueda/filtrado con paginado vía API
// - Guardar config sin recargar (POST a system_api)

(() => {
  const onReady = (fn) => {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", fn, { once: true });
    } else {
      fn();
    }
  };

  const qs = (sel, root = document) => root.querySelector(sel);
  const qsa = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  const debounce = (fn, ms = 250) => {
    let t = null;
    return (...args) => {
      if (t) clearTimeout(t);
      t = setTimeout(() => fn(...args), ms);
    };
  };

  const escHtml = (s) =>
    String(s ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");

  onReady(() => {
    // Autofocus en el buscador (vista config)
    const search = qs(".repo-search-input");
    if (search) {
      try {
        search.focus({ preventScroll: true });
      } catch {
        search.focus();
      }
    }

    // Evitar doble submit (fallback server-side)
    qsa("form.config-form").forEach((form) => {
      form.addEventListener("submit", () => {
        const btn = form.querySelector('button[type="submit"]');
        if (!btn) return;
        if (btn.disabled) return;
        btn.disabled = true;
        btn.dataset.originalText = btn.textContent || "";
        btn.textContent = "Guardando…";
      });
    });

    // ========= Vista Config (AJAX) =========
    const app = qs("#repo-config-app");
    if (!app) return;

    const apiUrl = app.dataset.api || "api/system_api.php";
    const csrfToken = app.dataset.csrf || "";

    const form = qs("#repoConfigSearchForm");
    const inputQ = qs("#repoConfigQ");
    const selProv = qs("#repoConfigProv");
    const cbSinProv = qs("#repoConfigSinProv");
    const resultsWrap = qs("#repoConfigResults");
    const meta = qs("#repoConfigMeta");

    const proveedores = Array.isArray(window.FLUS_REPO_PROVEEDORES)
      ? window.FLUS_REPO_PROVEEDORES
      : [];

    let state = {
      q: (inputQ?.value || "").trim(),
      proveedor_id: (selProv?.value || "").trim(),
      sin_proveedor: !!cbSinProv?.checked,
      page: 1,
      limit: 30,
      total: 0,
      loading: false,
      has_more: false,
    };

    const renderMeta = () => {
      if (!meta) return;
      const hasFilters = state.q || state.proveedor_id || state.sin_proveedor;
      if (!hasFilters) {
        meta.textContent = "";
        return;
      }
      if (state.total === 0) {
        meta.textContent = "";
        return;
      }
      const from = (state.page - 1) * state.limit + 1;
      const to = (state.page - 1) * state.limit + (qs("[data-producto-id]", resultsWrap)?.parentElement ? 0 : 0);
      // El 'to' lo ajustamos con DOM real al final del render
    };

    const buildSelectOptions = (selectedId) => {
      const sel = Number(selectedId || 0);
      const opts = [
        `<option value="">Sin proveedor</option>`,
        ...proveedores.map((p) => {
          const id = Number(p.id);
          const name = escHtml(p.nombre);
          const selected = id === sel ? "selected" : "";
          return `<option value="${id}" ${selected}>${name}</option>`;
        }),
      ];
      return opts.join("");
    };

    const emptyStateHtml = (msg) => `
      <div class="repo-empty repo-empty--md" id="repoConfigEmpty">
        <p class="repo-empty-text">${escHtml(msg)}</p>
      </div>
    `;

    const cardHtml = (it) => {
      const id = Number(it.id);
      const codigo = escHtml(it.codigo);
      const nombre = escHtml(it.nombre);
      const stock = Number(it.stock || 0);
      const provName = escHtml(it.proveedor_nombre || "Sin proveedor");

      const stockMin = Number(it.stock_minimo ?? 0);
      const reorden = Number(it.punto_reorden ?? 0);
      const stockMax = Number(it.stock_maximo ?? 0);

      return `
        <div class="config-card" data-producto-id="${id}">
          <div class="config-header">
            <div class="config-product">
              <h4>${codigo} — ${nombre}</h4>
              <p>Stock actual: <strong>${stock.toLocaleString("es-AR")}</strong> • ${provName}</p>
            </div>
          </div>

          <form class="config-form" data-config-form>
            <div class="form-group">
              <label>Stock Mínimo</label>
              <input type="number" name="stock_minimo" value="${stockMin}" min="0" step="1" class="form-control">
            </div>

            <div class="form-group">
              <label>Punto de Reorden</label>
              <input type="number" name="punto_reorden" value="${reorden}" min="0" step="1" class="form-control">
            </div>

            <div class="form-group">
              <label>Stock Máximo</label>
              <input type="number" name="stock_maximo" value="${stockMax}" min="0" step="1" class="form-control">
            </div>

            <div class="form-group">
              <label>Proveedor</label>
              <select name="proveedor_id" class="form-control">${buildSelectOptions(it.proveedor_id)}</select>
              <small class="text-muted" style="display:block;margin-top:.35rem;">Se usa para agrupar la lista y generar órdenes de compra.</small>
            </div>

            <div class="form-group repo-form-actions">
              <button type="button" class="btn btn-sm btn-primary repo-save-btn">Guardar</button>
            </div>
          </form>
        </div>
      `;
    };

    const updateMetaText = () => {
      if (!meta) return;
      const hasFilters = state.q || state.proveedor_id || state.sin_proveedor;
      if (!hasFilters) {
        meta.textContent = "";
        return;
      }
      if (state.total <= 0) {
        meta.textContent = "";
        return;
      }
      const shown = qsa(".config-card", resultsWrap).length;
      const from = 1;
      const to = shown;
      meta.innerHTML = `Mostrando <strong>${from}–${to}</strong> de <strong>${state.total}</strong>`;
    };

    const getParamsFromUi = () => {
      const q = (inputQ?.value || "").trim();
      const prov = (selProv?.value || "").trim();
      const sinProv = !!cbSinProv?.checked;
      return { q, proveedor_id: prov, sin_proveedor: sinProv };
    };

    const buildListUrl = ({ q, proveedor_id, sin_proveedor, page, limit }) => {
      const u = new URL(apiUrl, window.location.href);
      u.searchParams.set("action", "reposicion_config_list");
      if (q) u.searchParams.set("q", q);
      if (proveedor_id) u.searchParams.set("proveedor_id", proveedor_id);
      if (sin_proveedor) u.searchParams.set("sin_proveedor", "1");
      u.searchParams.set("page", String(page));
      u.searchParams.set("limit", String(limit));
      return u.toString();
    };

    const fetchJson = async (url, opts = {}) => {
      const r = await fetch(url, {
        credentials: "same-origin",
        headers: { Accept: "application/json" },
        ...opts,
      });
      const data = await r.json().catch(() => null);
      if (!r.ok) {
        const msg = data?.message || data?.error || "Error";
        throw new Error(msg);
      }
      return data;
    };

    const loadList = async ({ append = false } = {}) => {
      if (state.loading) return;
      state.loading = true;

      const { q, proveedor_id, sin_proveedor } = getParamsFromUi();
      state.q = q;
      state.proveedor_id = proveedor_id;
      state.sin_proveedor = sin_proveedor;

      // Regla: no cargamos todo por defecto
      if (!q && !proveedor_id && !sin_proveedor) {
        resultsWrap.innerHTML = emptyStateHtml(
          "Buscá por código/nombre o filtrá por proveedor para empezar."
        );
        meta.textContent = "";
        state.total = 0;
        state.page = 1;
        state.has_more = false;
        state.loading = false;
        return;
      }

      if (q && q.length < 2) {
        resultsWrap.innerHTML = emptyStateHtml("Escribí al menos 2 caracteres para buscar.");
        meta.textContent = "";
        state.total = 0;
        state.page = 1;
        state.has_more = false;
        state.loading = false;
        return;
      }

      const url = buildListUrl({
        q,
        proveedor_id,
        sin_proveedor,
        page: state.page,
        limit: state.limit,
      });

      try {
        const data = await fetchJson(url);
        const payload = data?.data || data; // compat
        const items = Array.isArray(payload.items) ? payload.items : [];

        state.total = Number(payload.total || 0);
        state.has_more = !!payload.has_more;

        const html = items.map(cardHtml).join("");

        if (append) {
          // Quitar load-more viejo
          const lm = qs(".repo-load-more-row", resultsWrap);
          if (lm) lm.remove();
          resultsWrap.insertAdjacentHTML("beforeend", html);
        } else {
          resultsWrap.innerHTML = html || emptyStateHtml("No se encontraron productos con esos filtros.");
        }

        // Load more
        if (state.has_more) {
          resultsWrap.insertAdjacentHTML(
            "beforeend",
            `<div class="repo-load-more-row"><button type="button" class="btn btn-ghost repo-load-more-btn">Cargar más</button></div>`
          );
        }

        updateMetaText();
      } catch (e) {
        resultsWrap.innerHTML = emptyStateHtml(e.message || "Error al cargar.");
        meta.textContent = "";
      } finally {
        state.loading = false;
      }
    };

    const saveConfig = async (cardEl) => {
      const id = Number(cardEl?.dataset?.productoId || 0);
      if (!id) return;

      const btn = qs(".repo-save-btn", cardEl);
      const form = qs("[data-config-form]", cardEl);
      if (!form) return;

      const getVal = (name) => {
        const el = form.querySelector(`[name="${name}"]`);
        return el ? el.value : "";
      };

      const body = {
        producto_id: id,
        stock_minimo: Number(getVal("stock_minimo") || 0),
        punto_reorden: Number(getVal("punto_reorden") || 0),
        stock_maximo: Number(getVal("stock_maximo") || 0),
      };
      const prov = getVal("proveedor_id");
      if (prov !== "") body.proveedor_id = Number(prov);
      else body.proveedor_id = null;

      try {
        if (btn) {
          btn.disabled = true;
          btn.dataset.originalText = btn.textContent || "";
          btn.textContent = "Guardando…";
        }

        const u = new URL(apiUrl, window.location.href);
        u.searchParams.set("action", "reposicion_config_set");

        await fetchJson(u.toString(), {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": csrfToken,
            Accept: "application/json",
          },
          body: JSON.stringify(body),
        });

        if (btn) {
          btn.textContent = "Guardado";
          setTimeout(() => {
            btn.textContent = btn.dataset.originalText || "Guardar";
            btn.disabled = false;
          }, 900);
        }
      } catch (e) {
        if (btn) {
          btn.textContent = "Error";
          setTimeout(() => {
            btn.textContent = btn.dataset.originalText || "Guardar";
            btn.disabled = false;
          }, 1200);
        }
      }
    };

    // Intercept submit para no recargar
    if (form) {
      form.addEventListener("submit", (ev) => {
        ev.preventDefault();
        state.page = 1;
        loadList({ append: false });
      });
    }

    // Auto-search (debounce)
    const auto = debounce(() => {
      state.page = 1;
      loadList({ append: false });
    }, 300);

    if (inputQ) inputQ.addEventListener("input", auto);
    if (selProv) selProv.addEventListener("change", auto);
    if (cbSinProv) cbSinProv.addEventListener("change", auto);

    // Delegación: guardar + cargar más
    resultsWrap.addEventListener("click", (ev) => {
      const t = ev.target;
      if (!(t instanceof HTMLElement)) return;

      const loadMoreBtn = t.closest?.(".repo-load-more-btn");
      if (loadMoreBtn) {
        state.page += 1;
        loadList({ append: true });
        return;
      }

      const saveBtn = t.closest?.(".repo-save-btn");
      if (saveBtn) {
        const card = t.closest(".config-card");
        if (card) saveConfig(card);
      }
    });

    // Primera carga (si hay filtros o query)
    const initial = getParamsFromUi();
    if (initial.q || initial.proveedor_id || initial.sin_proveedor) {
      state.page = 1;
      loadList({ append: false });
    }
  });
})();

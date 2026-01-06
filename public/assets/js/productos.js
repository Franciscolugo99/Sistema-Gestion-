// public/assets/js/productos.js
(() => {
  // evita doble carga accidental
  if (window.__flus_productos_js_loaded) return;
  window.__flus_productos_js_loaded = true;

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  /* =========================
     TOAST
  ========================= */
  if (!window.showToast) {
    window.showToast = (msg, type = "info", ms = 2800) => {
      const t = document.createElement("div");
      t.className = `toast toast-${type}`;
      t.textContent = msg;
      document.body.appendChild(t);

      requestAnimationFrame(() => t.classList.add("show"));
      setTimeout(() => t.classList.remove("show"), ms);
      setTimeout(() => t.remove(), ms + 350);
    };
  }

  /* ==========================================================
     ✅ Persistir filtros
  ========================================================== */
  const LS_FILTERS_KEY = "flus_productos_filters_v1";

  function saveFiltersState() {
    try {
      const searchInput = document.getElementById("searchInput");
      const estadoSelect = document.getElementById("estadoSelect");
      const sortInp = document.querySelector('input[name="sort"]');
      const dirInp = document.querySelector('input[name="dir"]');

      const data = {
        q: searchInput ? String(searchInput.value || "") : "",
        estado: estadoSelect ? String(estadoSelect.value || "") : "",
        sort: sortInp ? String(sortInp.value || "") : "",
        dir: dirInp ? String(dirInp.value || "") : "",
      };

      localStorage.setItem(LS_FILTERS_KEY, JSON.stringify(data));
    } catch {}
  }

  function restoreFiltersState() {
    try {
      const url = new URL(window.location.href);

      // si la URL ya trae filtros, NO pisar (la URL manda)
      const urlHas =
        url.searchParams.has("q") ||
        url.searchParams.has("estado") ||
        url.searchParams.has("sort") ||
        url.searchParams.has("dir");

      if (urlHas) return;

      const raw = localStorage.getItem(LS_FILTERS_KEY);
      if (!raw) return;
      const data = JSON.parse(raw);
      if (!data || typeof data !== "object") return;

      const searchInput = document.getElementById("searchInput");
      const estadoSelect = document.getElementById("estadoSelect");
      const sortInp = document.querySelector('input[name="sort"]');
      const dirInp = document.querySelector('input[name="dir"]');

      if (searchInput && typeof data.q === "string" && !searchInput.value) searchInput.value = data.q;
      if (estadoSelect && typeof data.estado === "string" && !estadoSelect.value) estadoSelect.value = data.estado;

      if (sortInp && typeof data.sort === "string" && !sortInp.value) sortInp.value = data.sort;
      if (dirInp && typeof data.dir === "string" && !dirInp.value) dirInp.value = data.dir;
    } catch {}
  }

  window.addEventListener("beforeunload", saveFiltersState);

  /* ==========================================================
     ✅ FIX: “Guardar/Agregar” siempre envía el form
  ========================================================== */
  function ensureMainFormSubmits() {
    const mainForm = document.querySelector(".productos-form");
    if (!mainForm) return;

    const candidates = [
      document.getElementById("btnSaveProduct"),
      document.getElementById("btnGuardarProducto"),
      document.getElementById("btnGuardar"),
      mainForm.querySelector('button[type="submit"]'),
      mainForm.querySelector('input[type="submit"]'),
      ...Array.from(mainForm.querySelectorAll("button.btn-primary, .btn.btn-primary")).filter(
        (b) => !(b.id === "btnClearForm" || b.dataset.clearForm === "1" || b.getAttribute("data-clear-form") === "1")
      ),
    ].filter(Boolean);

    const btn = candidates[0];
    if (!btn) return;

    if (btn.tagName === "BUTTON") {
      const t = (btn.getAttribute("type") || "submit").toLowerCase();
      if (t !== "submit") btn.setAttribute("type", "submit");
    }

    if (btn.dataset.boundSubmitFix === "1") return;
    btn.dataset.boundSubmitFix = "1";

    btn.addEventListener("click", (e) => {
      saveFiltersState();

      if (btn.closest("form") !== mainForm) {
        e.preventDefault();
        if (typeof mainForm.requestSubmit === "function") mainForm.requestSubmit();
        else mainForm.submit();
      }
    });
  }

  /* =========================
     CSRF MANAGER
  ========================= */
  const CsrfManager = (() => {
    let cachedToken = null;
    let lastFetch = 0;
    const CACHE_MS = 30000;

    async function fetchToken() {
      try {
        const base = location.pathname.replace(/\/[^\/]*$/, "");
        const url = `${base}/_csrf_token.php?_=${Date.now()}`;
        const res = await fetch(url, {
          method: "GET",
          cache: "no-store",
          credentials: "same-origin",
          headers: { Accept: "application/json" },
        });
        if (!res.ok) return null;
        const data = await res.json().catch(() => null);
        const t = data && typeof data.csrf_token === "string" ? data.csrf_token : null;
        if (!t || t.length < 32) return null;
        return t;
      } catch {
        return null;
      }
    }

    return {
      async get(force = false) {
        const now = Date.now();
        if (!force && cachedToken && now - lastFetch < CACHE_MS) return cachedToken;
        const token = await fetchToken();
        if (token) {
          cachedToken = token;
          lastFetch = now;
        }
        return token;
      },
      invalidate() {
        cachedToken = null;
      },
    };
  })();

  function setCsrfOnForm(form, token) {
    if (!form || !token) return false;
    const inp = form.querySelector('input[name="csrf_token"]') || form.querySelector('input[name="csrf"]');
    if (!inp) return false;
    inp.value = token;
    return true;
  }

  function updateHrefCsrf(href, token) {
    try {
      const u = new URL(href, window.location.href);
      if (u.searchParams.has("csrf_token")) u.searchParams.set("csrf_token", token);
      else if (u.searchParams.has("csrf")) u.searchParams.set("csrf", token);
      else u.searchParams.set("csrf_token", token);

      return u.pathname + (u.searchParams.toString() ? "?" + u.searchParams.toString() : "");
    } catch {
      return href;
    }
  }

  async function refreshCsrfEverywhere() {
    const token = await CsrfManager.get();
    if (!token) return null;

    $$("form").forEach((f) => setCsrfOnForm(f, token));

    $$(".js-product-toggle").forEach((a) => {
      const href = a.getAttribute("href") || "";
      if (!href) return;
      a.setAttribute("href", updateHrefCsrf(href, token));
    });

    return token;
  }

  document.addEventListener(
    "submit",
    async (e) => {
      const form = e.target;
      if (!(form instanceof HTMLFormElement)) return;

      const method = (form.getAttribute("method") || "get").toLowerCase();
      if (method !== "post") return;

      const hasCsrf = !!form.querySelector('input[name="csrf_token"]') || !!form.querySelector('input[name="csrf"]');
      if (!hasCsrf) return;

      if (form.dataset.csrfRefreshing === "1") return;
      e.preventDefault();
      form.dataset.csrfRefreshing = "1";

      form.classList.add("saving");

      const token = await CsrfManager.get(true);
      if (token) setCsrfOnForm(form, token);
      else window.showToast("No pude refrescar el token CSRF. Si falla, recargá la página.", "error", 3200);

      try {
        form.submit(); // submit() no dispara submit event -> no loop
      } finally {
        setTimeout(() => {
          form.classList.remove("saving");
          delete form.dataset.csrfRefreshing;
        }, 150);
      }
    },
    true
  );

  /* =========================
     AUTOCOMPLETE (datalist)
  ========================= */
  function escapeHtml(str) {
    const div = document.createElement("div");
    div.textContent = String(str ?? "");
    return div.innerHTML;
  }

  async function loadAutocomplete(field, datalistId) {
    try {
      const res = await fetch(`productos.php?autocomplete=${encodeURIComponent(field)}`, {
        cache: "no-store",
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      });
      if (!res.ok) return;

      const values = await res.json().catch(() => []);
      const datalist = document.getElementById(datalistId);
      if (!datalist) return;

      datalist.innerHTML = (Array.isArray(values) ? values : [])
        .map((v) => `<option value="${escapeHtml(v)}">`)
        .join("");
    } catch (e) {
      console.error("Error cargando autocomplete:", e);
    }
  }

  function initAutocomplete() {
    loadAutocomplete("categoria", "categorias-list");
    loadAutocomplete("marca", "marcas-list");
    loadAutocomplete("proveedor", "proveedores-list");

    loadAutocomplete("categoria", "categorias-list-edit");
    loadAutocomplete("marca", "marcas-list-edit");
    loadAutocomplete("proveedor", "proveedores-list-edit");
  }

  /* =========================
     LIMPIAR FORM
  ========================= */
  function clearProductForm(focusFirst = true) {
    const mainForm = $(".productos-form");
    if (!mainForm) return;

    mainForm.reset();

    const fileNameSpan = $("#fileName");
    if (fileNameSpan) fileNameSpan.textContent = "Ningún archivo seleccionado";

    const idInput = mainForm.querySelector('input[name="id"]');
    if (!idInput || !idInput.value) {
      const activoCheck = mainForm.querySelector('input[name="activo"]');
      if (activoCheck) activoCheck.checked = true;
    }

    if (focusFirst) {
      const codigoInput = mainForm.querySelector('input[name="codigo"]');
      if (codigoInput) setTimeout(() => codigoInput.focus(), 100);
    }
  }

  function bindClearButton() {
    const btn = document.querySelector('[data-clear-form="1"], #btnClearForm');
    if (!btn) return;
    if (btn.dataset.boundClear === "1") return;
    btn.dataset.boundClear = "1";

    btn.addEventListener("click", (e) => {
      e.preventDefault();
      clearProductForm(true);
      window.showToast("Formulario limpio. Listo para nuevo producto.", "info", 1800);
    });
  }

  /* =========================
     COPIAR PRODUCTO
  ========================= */
  window.copyProductToForm = async (productId) => {
    const mainForm = document.querySelector(".productos-form");
    if (!mainForm) return;

    let product = null;

    try {
      const res = await fetch(`productos.php?editar=${encodeURIComponent(productId)}&ajax=1`, {
        headers: { Accept: "application/json" },
        cache: "no-store",
        credentials: "same-origin",
      });
      product = await res.json().catch(() => null);
    } catch {
      product = null;
    }

    if (!product || !product.id) {
      window.showToast("No se pudo cargar el producto para copiar.", "error");
      return;
    }

    // ✅ abrir formulario sí o sí
    if (typeof window.__flus_openProductForm === "function") {
      window.__flus_openProductForm();
    } else {
      const fb = document.getElementById("productFormBlock");
      if (fb) fb.classList.remove("is-collapsed");
    }

    const hiddenId = mainForm.querySelector('input[name="id"]');
    if (hiddenId) hiddenId.value = "";

    const setVal = (name, val) => {
      const el = mainForm.querySelector(`[name="${name}"]`);
      if (!el) return;
      if (name === "codigo") {
        el.value = "";
        setTimeout(() => el.focus(), 50);
      } else {
        el.value = val ?? "";
      }
    };

    setVal("codigo", "");
    setVal("nombre", product.nombre);
    setVal("categoria", product.categoria);
    setVal("marca", product.marca);
    setVal("proveedor", product.proveedor);
    setVal("iva", product.iva ?? "");
    setVal("precio", product.precio ?? "");
    setVal("costo", product.costo ?? "");
    setVal("stock", "0");
    setVal("stock_minimo", product.stock_minimo ?? "");
    setVal("unidad_venta", product.unidad_venta ?? "UNIDAD");

    const chkPesable = mainForm.querySelector('input[name="es_pesable"]');
    if (chkPesable) chkPesable.checked = Number(product.es_pesable || 0) === 1;

    const chkActivo = mainForm.querySelector('input[name="activo"]');
    if (chkActivo) chkActivo.checked = true;

    const formBlock = document.getElementById("productFormBlock") || mainForm.closest("#productFormBlock") || mainForm;
    formBlock?.scrollIntoView({ behavior: "smooth", block: "start" });
    window.showToast(`Datos copiados de "${product.nombre}". Ingresá un código nuevo.`, "info", 3000);
  };

  // Delegación: Copiar
  document.addEventListener("click", (e) => {
    const a = e.target && e.target.closest ? e.target.closest(".btn-copy[data-copy-id]") : null;
    if (!a) return;
    e.preventDefault();
    window.copyProductToForm(a.dataset.copyId);
  });

  /* =========================
     VALIDACIÓN CÓDIGO
  ========================= */
  function setupRealTimeValidation() {
    const mainForm = $(".productos-form");
    if (!mainForm) return;

    const codigoInput = mainForm.querySelector('input[name="codigo"]');
    if (!codigoInput) return;

    let t = null;
    codigoInput.addEventListener("input", () => {
      clearTimeout(t);

      const codigo = codigoInput.value.trim();
      if (!codigo) {
        codigoInput.classList.remove("input-valid", "input-invalid");
        codigoInput.setCustomValidity("");
        return;
      }

      t = setTimeout(async () => {
        const idInput = mainForm.querySelector('input[name="id"]');
        const currentId = idInput?.value || "";

        try {
          const res = await fetch(
            `productos.php?checkCodigo=${encodeURIComponent(codigo)}&id=${encodeURIComponent(currentId)}`,
            { cache: "no-store", credentials: "same-origin", headers: { Accept: "application/json" } }
          );
          const data = await res.json().catch(() => ({}));

          if (data && data.exists) {
            codigoInput.classList.add("input-invalid");
            codigoInput.classList.remove("input-valid");
            codigoInput.setCustomValidity("Este código ya existe");
          } else {
            codigoInput.classList.add("input-valid");
            codigoInput.classList.remove("input-invalid");
            codigoInput.setCustomValidity("");
          }
        } catch (e) {
          console.error("Error validando código:", e);
        }
      }, 450);
    });
  }

  /* =========================
     FORM VACÍO DESPUÉS DE CREAR
  ========================= */
  (() => {
    const url = new URL(window.location.href);
    if (url.searchParams.get("clearForm") === "1") {
      setTimeout(() => clearProductForm(true), 100);
    }
  })();

  /* =========================
   FORM PLEGABLE (FIX DEFINITIVO)
========================= */
function initToggleForm() {
  // ⚠️ IMPORTANTE: Remover el guard para permitir debug
  console.log('🔧 initToggleForm ejecutándose...');
  
  const formEl = document.querySelector(".productos-form");
  let formBlock =
    document.getElementById("productFormBlock") ||
    document.querySelector("[data-product-form-block]") ||
    document.querySelector(".product-form-block") ||
    (formEl ? (formEl.closest("#productFormBlock") || formEl.closest("[data-product-form-block]") || formEl.parentElement) : null) ||
    formEl;

  if (!formBlock) {
    console.error("❌ No se encontró el formulario (.productos-form o #productFormBlock)");
    window.showToast("No encuentro el formulario de productos.", "error", 3200);
    return;
  }

  console.log('✅ Formulario encontrado:', formBlock);

  const isOfficialBlock = formBlock && formBlock.id === "productFormBlock";

  // Buscar el botón existente en el HTML
  function findToggleBtn() {
    const btn = 
      document.getElementById("toggleFormBtn") ||
      document.getElementById("btnNewProduct") ||
      document.querySelector(".btn-new-product") ||
      document.querySelector('[data-action="toggle-form"]') ||
      document.querySelector('[data-toggle-product-form="1"]');
    
    console.log('🔍 Botón encontrado:', btn);
    return btn;
  }

  let toggleBtn = findToggleBtn();
  
  // Si no existe, créalo (fallback)
  if (!toggleBtn) {
    console.warn('⚠️ Botón no encontrado, creando uno nuevo...');
    
    const btn = document.createElement("button");
    btn.type = "button";
    btn.id = "toggleFormBtn";
    btn.className = "btn btn-primary btn-new-product";
    btn.setAttribute("data-toggle-product-form", "1");
    btn.textContent = "Agregar producto";

    const header =
      document.querySelector(".productos-header") ||
      document.querySelector(".productos-page .panel") ||
      document.body;

    if (header && header !== document.body) {
      const right =
        header.querySelector(".productos-header-right") ||
        header.querySelector(".actions") ||
        header;
      right.appendChild(btn);
    } else {
      btn.style.cssText = "position:fixed;right:18px;bottom:18px;z-index:9999";
      document.body.appendChild(btn);
    }
    
    toggleBtn = btn;
    console.log('✅ Botón creado y añadido');
  }

  // Funciones de control
  function setBtnLabel(collapsed) {
    if (!toggleBtn) return;
    
    const labelOpen = "Ocultar formulario";
    const labelClose = "Agregar producto";
    const label = collapsed ? labelClose : labelOpen;

    toggleBtn.setAttribute("aria-expanded", collapsed ? "false" : "true");

    // Buscar el span.label o actualizar textContent directo
    const labelSpan = toggleBtn.querySelector(".label");
    if (labelSpan) {
      labelSpan.textContent = label;
    } else {
      // Si no hay span, actualizar el texto del botón directamente
      toggleBtn.textContent = label;
    }
    
    console.log(`🏷️ Label actualizado: "${label}"`);
  }

  function isCollapsedNow() {
    const collapsed = formBlock.classList.contains("is-collapsed");
    console.log(`📊 ¿Está colapsado?: ${collapsed}`);
    return collapsed;
  }

  function forceShowBlock() {
    formBlock.hidden = false;
    formBlock.style.removeProperty("display");
    formBlock.style.removeProperty("max-height");
    formBlock.style.removeProperty("opacity");
    formBlock.style.removeProperty("pointer-events");
    formBlock.style.removeProperty("visibility");
    console.log('👁️ Formulario visible (estilos forzados)');
  }

  function forceHideBlock() {
    if (!isOfficialBlock) {
      formBlock.style.display = "none";
      console.log('🙈 Formulario oculto');
    }
  }

  function openForm() {
    console.log('🔓 Abriendo formulario...');
    forceShowBlock();
    formBlock.classList.remove("is-collapsed");
    setBtnLabel(false);

    const codigoInput = document.querySelector(".productos-form input[name='codigo']");
    if (codigoInput) {
      setTimeout(() => {
        codigoInput.focus();
        console.log('🎯 Focus en campo código');
      }, 120);
    }

    formBlock.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  function closeForm() {
    console.log('🔒 Cerrando formulario...');
    formBlock.classList.add("is-collapsed");
    setBtnLabel(true);
    forceHideBlock();
  }

  // Exponer funciones globalmente
  window.__flus_openProductForm = openForm;
  window.__flus_closeProductForm = closeForm;

  // Determinar estado inicial
  const collapsed = isCollapsedNow();
  console.log(`🎬 Estado inicial: ${collapsed ? 'colapsado' : 'expandido'}`);
  setBtnLabel(collapsed);
  if (collapsed && !isOfficialBlock) forceHideBlock();

  // 🔥 BIND DEL EVENTO (LA PARTE MÁS IMPORTANTE)
  // Remover cualquier listener previo y añadir uno nuevo
  if (toggleBtn) {
    // Clonar el botón para remover todos los listeners
    const newBtn = toggleBtn.cloneNode(true);
    toggleBtn.parentNode.replaceChild(newBtn, toggleBtn);
    toggleBtn = newBtn;
    
    console.log('🔗 Vinculando evento click...');
    
    toggleBtn.addEventListener("click", (e) => {
      console.log('🖱️ Click detectado en botón toggle');
      e.preventDefault();
      e.stopPropagation();
      
      const nowCollapsed = isCollapsedNow();
      console.log(`⚡ Acción: ${nowCollapsed ? 'abrir' : 'cerrar'}`);
      
      if (nowCollapsed) {
        openForm();
      } else {
        closeForm();
      }
    });
    
    console.log('✅ Evento click vinculado correctamente');
    
    // Test click después de 1 segundo para verificar
    setTimeout(() => {
      console.log('🧪 Verificando que el botón responde...');
      console.log('   ID:', toggleBtn.id);
      console.log('   Clases:', toggleBtn.className);
      console.log('   Texto:', toggleBtn.textContent);
      console.log('   ¿Visible?:', getComputedStyle(toggleBtn).display !== 'none');
    }, 1000);
  } else {
    console.error('❌ No se pudo vincular el evento: botón no encontrado');
  }
}

  /* =========================
     NOMBRE ARCHIVO
  ========================= */
  function bindFileName() {
    const fileInput = document.getElementById("imagen");
    const fileNameSpan = document.getElementById("fileName");
    if (!fileInput || !fileNameSpan) return;

    fileInput.addEventListener("change", (e) => {
      const files = e.target.files;
      fileNameSpan.textContent = files && files.length > 0 ? files[0].name : "Ningún archivo seleccionado";
    });
  }

  /* =========================
     SORT (click en TH)
  ========================= */
  function bindSortHeaders() {
    const table = $(".productos-table");
    const filtersForm = $("#filtersForm");
    if (!table || !filtersForm) return;

    $$(".productos-table thead th[data-sort]").forEach((th) => {
      if (th.dataset.boundSort === "1") return;
      th.dataset.boundSort = "1";

      th.addEventListener("click", () => {
        const col = th.getAttribute("data-sort");
        const sortInp = $('input[name="sort"]', filtersForm);
        const dirInp = $('input[name="dir"]', filtersForm);

        const currentSort = sortInp?.value || "nombre";
        const currentDir = (dirInp?.value || "ASC").toLowerCase();

        let nextDir = "ASC";
        if (currentSort === col) nextDir = currentDir === "asc" ? "DESC" : "ASC";

        if (sortInp) sortInp.value = col;
        if (dirInp) dirInp.value = nextDir;

        const pageInp = $('input[name="page"]', filtersForm);
        if (pageInp) pageInp.value = "1";

        saveFiltersState();
        filtersForm.submit();
      });
    });
  }

  /* =========================
     BÚSQUEDA EN VIVO (HTML TBODY)
  ========================= */
  function bindLiveSearch() {
    let searchTimeout = null;

    const searchInput = document.getElementById("searchInput");
    const estadoSelect = document.getElementById("estadoSelect");
    const tbody = document.getElementById("productosTbody");
    if (!searchInput || !tbody) return;

    async function perform() {
      const query = searchInput.value.trim();
      const estado = estadoSelect?.value || "";

      const sort = document.querySelector('input[name="sort"]')?.value || "nombre";
      const dir = document.querySelector('input[name="dir"]')?.value || "ASC";

      saveFiltersState();

      if (!query && !estado) {
        window.location.reload();
        return;
      }

      const params = new URLSearchParams({
        ajaxTbody: "1",
        q: query,
        estado,
        sort,
        dir,
      });

      try {
        const res = await fetch(`productos.php?${params.toString()}`, {
          cache: "no-store",
          credentials: "same-origin",
          headers: { Accept: "text/html" },
        });

        if (!res.ok) throw new Error("bad response");
        const html = await res.text();

        tbody.innerHTML = html;

        await refreshCsrfEverywhere();
        bindToggleConfirm();
      } catch (e) {
        console.error("Live search error:", e);
      }
    }

    if (searchInput.dataset.boundLive === "1") return;
    searchInput.dataset.boundLive = "1";

    searchInput.addEventListener("input", () => {
      clearTimeout(searchTimeout);
      searchTimeout = setTimeout(perform, 250);
    });

    if (estadoSelect && estadoSelect.dataset.boundLive !== "1") {
      estadoSelect.dataset.boundLive = "1";
      estadoSelect.addEventListener("change", perform);
    }
  }

  /* =========================
     EXPANDIR DETALLES
  ========================= */
  window.toggleDetailRow = (id) => {
    const detailRow = document.getElementById(`detail-${id}`);
    if (!detailRow) return;

    const isExpanded = detailRow.classList.contains("expanded");
    detailRow.classList.toggle("expanded");

    const btn = document.querySelector(`.producto-row[data-id="${id}"] .btn-expand`);
    if (btn) btn.textContent = isExpanded ? "⊕" : "⊖";
  };

  function bindExpandAll() {
    const btnExpandAll = document.querySelector(".btn-expand-all");
    if (!btnExpandAll) return;
    if (btnExpandAll.dataset.boundExpandAll === "1") return;
    btnExpandAll.dataset.boundExpandAll = "1";

    let allExpanded = false;

    btnExpandAll.addEventListener("click", () => {
      allExpanded = !allExpanded;
      $$(".producto-detail-row").forEach((row) => row.classList.toggle("expanded", allExpanded));
      $$(".btn-expand").forEach((btn) => (btn.textContent = allExpanded ? "⊖" : "⊕"));
      btnExpandAll.textContent = allExpanded ? "⊖" : "⊕";
    });
  }

  /* =========================
     EDIT PANEL
  ========================= */
  const overlay = $("#editOverlay");
  const editForm = $("#editForm");
  const pageWrap = $(".page-wrap");

  function setBlur(on) {
    if (pageWrap) pageWrap.classList.toggle("blurred", !!on);
  }

  function openOverlay() {
    if (!overlay) return;
    overlay.classList.add("open", "active");
    setBlur(true);
  }

  function closeOverlay() {
    if (!overlay) return;
    overlay.classList.remove("open", "active");
    setBlur(false);
  }

  if (overlay) {
    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) closeOverlay();
    });
  }

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") {
      closeOverlay();
      closeConfirm();
    }
  });

  window.openEditPanel = async (id) => {
    id = Number(id || 0);
    if (!id || !editForm) return;

    try {
      const res = await fetch(`productos.php?editar=${encodeURIComponent(id)}&ajax=1`, {
        headers: { Accept: "application/json" },
        cache: "no-store",
        credentials: "same-origin",
      });
      const data = await res.json().catch(() => null);
      if (!data || !data.id) {
        window.showToast("No se encontró el producto.", "error");
        return;
      }

      editForm.querySelector('input[name="id"]').value = data.id ?? "";

      const setVal = (name, val) => {
        const el = editForm.querySelector(`[name="${name}"]`);
        if (!el) return;
        el.value = val ?? "";
      };

      setVal("codigo", data.codigo);
      setVal("nombre", data.nombre);
      setVal("categoria", data.categoria);
      setVal("marca", data.marca);
      setVal("proveedor", data.proveedor);
      setVal("iva", data.iva ?? "");
      setVal("precio", data.precio ?? "");
      setVal("costo", data.costo ?? "");
      setVal("stock", data.stock ?? "");
      setVal("stock_minimo", data.stock_minimo ?? "");
      setVal("unidad_venta", data.unidad_venta ?? "UNIDAD");

      const chkPesable = editForm.querySelector('input[name="es_pesable"]');
      if (chkPesable) chkPesable.checked = Number(data.es_pesable || 0) === 1;

      const chkActivo = editForm.querySelector('input[name="activo"]');
      if (chkActivo) chkActivo.checked = Number(data.activo ?? 1) === 1;

      const file = editForm.querySelector('input[type="file"][name="imagen"]');
      if (file) file.value = "";

      const t = await CsrfManager.get(true);
      if (t) setCsrfOnForm(editForm, t);

      openOverlay();
    } catch (err) {
      console.error(err);
      window.showToast("No se pudo cargar el producto.", "error");
    }
  };

  window.closeEditPanel = closeOverlay;

  /* =========================
     CONFIRM MODAL TOGGLE
  ========================= */
  const confirmOv = $("#confirmToggle");
  const confirmTitle = $("#confirmTitle");
  const confirmText = $("#confirmText");
  const btnCancel = $("#confirmCancel");
  const btnAccept = $("#confirmAccept");

  let pendingHref = null;

  function openConfirm({ action, href }) {
    pendingHref = href;

    const isDesactivar = action === "desactivar";
    if (confirmTitle) confirmTitle.textContent = isDesactivar ? "Desactivar producto" : "Activar producto";
    if (confirmText) {
      confirmText.textContent = isDesactivar
        ? "¿Desactivar producto? No aparecerá en Caja ni en búsquedas."
        : "¿Activar producto? Volverá a aparecer en Caja y búsquedas.";
    }

    if (!confirmOv) return;
    confirmOv.classList.add("open", "active");
    setBlur(true);
  }

  function closeConfirm() {
    if (!confirmOv) return;
    confirmOv.classList.remove("open", "active");
    setBlur(false);
    pendingHref = null;
  }

  window.closeConfirm = closeConfirm;

  if (btnCancel) btnCancel.addEventListener("click", closeConfirm);

  if (confirmOv) {
    confirmOv.addEventListener("click", (e) => {
      if (e.target === confirmOv) closeConfirm();
    });
  }

  if (btnAccept) {
    btnAccept.addEventListener("click", async () => {
      if (!pendingHref) return;
      const token = await CsrfManager.get(true);
      const hrefFinal = token ? updateHrefCsrf(pendingHref, token) : pendingHref;
      window.location.href = hrefFinal;
    });
  }

  function bindToggleConfirm() {
    $$(".js-product-toggle").forEach((a) => {
      if (a.dataset.boundConfirm === "1") return;
      a.dataset.boundConfirm = "1";

      a.addEventListener("click", (e) => {
        e.preventDefault();
        openConfirm({ action: a.dataset.action || "", href: a.getAttribute("href") });
      });
    });
  }

  /* =========================
     QUICK STATS
  ========================= */
  async function loadQuickStats() {
    try {
      const res = await fetch("productos.php?stats=1", {
        cache: "no-store",
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      });
      if (!res.ok) return;

      const stats = await res.json().catch(() => ({}));

      document.querySelector(".quick-stats")?.remove();

      const statsDiv = document.createElement("div");
      statsDiv.className = "quick-stats";
      statsDiv.innerHTML = `
        <div class="stat-item"><span class="stat-value">${stats.total || 0}</span><span class="stat-label">Total</span></div>
        <div class="stat-item"><span class="stat-value">${stats.activos || 0}</span><span class="stat-label">Activos</span></div>
        <div class="stat-item"><span class="stat-value">${stats.sin_stock || 0}</span><span class="stat-label">Sin stock</span></div>
        <div class="stat-item"><span class="stat-value">${stats.stock_bajo || 0}</span><span class="stat-label">Stock bajo</span></div>
      `;

      const panel = document.querySelector(".productos-page .panel");
      const header = panel?.querySelector(".productos-header");
      if (panel && header) panel.insertBefore(statsDiv, header.nextSibling);
    } catch (e) {
      console.error("Error cargando estadísticas:", e);
    }
  }

  /* =========================
     INIT
  ========================= */
  async function init() {
    restoreFiltersState();

    initAutocomplete();
    bindClearButton();
    setupRealTimeValidation();

    // ✅ clave: crea/enlaza botón y toggle
    initToggleForm();

    bindFileName();
    bindSortHeaders();
    bindLiveSearch();
    bindExpandAll();

    ensureMainFormSubmits();

    document.getElementById("searchInput")?.addEventListener("input", saveFiltersState);
    document.getElementById("estadoSelect")?.addEventListener("change", saveFiltersState);

    bindToggleConfirm();
    await refreshCsrfEverywhere();

    loadQuickStats();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();

// public/assets/js/productos.js - VERSIÓN HÍBRIDA DEFINITIVA (2026) 🚀
// Combina: robustez + bfcache + tracking inteligente
(() => {
  "use strict";

  if (window.__flus_productos_js_loaded) return;
  window.__flus_productos_js_loaded = true;

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  /* =========================
     ✅ VALIDACIÓN: Solo ejecutar en página de productos
  ========================= */
  function esProductosPage() {
    return document.querySelector('.page-wrap.productos-page') !== null;
  }

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

  /* =========================
     PERSISTIR FILTROS
  ========================= */
  const LS_FILTERS_KEY = "flus_productos_filters_v1";

  function saveFiltersState() {
    if (!esProductosPage()) return;
    
    try {
      const searchInput = $("#searchInput");
      const estadoSelect = $("#estadoSelect");
      const limitSel = document.querySelector('select[name="limit"]');
      const sortInp = document.querySelector('input[name="sort"]');
      const dirInp = document.querySelector('input[name="dir"]');

      const data = {
        q: (searchInput?.value || "").trim(),
        estado: estadoSelect?.value || "",
        limit: limitSel?.value || "",
        sort: sortInp?.value || "",
        dir: dirInp?.value || "",
      };

      localStorage.setItem(LS_FILTERS_KEY, JSON.stringify(data));
    } catch (e) {
      console.warn("[productos.js] Error guardando filtros:", e);
    }
  }

  function restoreFiltersState() {
    if (!esProductosPage()) return false;
    
    try {
      const url = new URL(window.location.href);

      const urlHas =
        url.searchParams.has("q") ||
        url.searchParams.has("estado") ||
        url.searchParams.has("sort") ||
        url.searchParams.has("dir") ||
        url.searchParams.has("limit") ||
        url.searchParams.has("page") ||
        url.searchParams.has("editar") ||
        url.searchParams.has("action") ||
        url.searchParams.has("toggle") ||
        url.searchParams.has("id") ||
        url.searchParams.has("saved") ||
        url.searchParams.has("created") ||
        url.searchParams.has("updated") ||
        url.searchParams.has("clearForm") ||
        url.searchParams.has("csrf_token");

      if (urlHas) return false;

      const raw = localStorage.getItem(LS_FILTERS_KEY);
      if (!raw) return false;

      const data = JSON.parse(raw);
      if (!data || typeof data !== "object") return false;

      const searchInput = $("#searchInput");
      const estadoSelect = $("#estadoSelect");
      const limitSel = document.querySelector('select[name="limit"]');
      const sortInp = document.querySelector('input[name="sort"]');
      const dirInp = document.querySelector('input[name="dir"]');

      let changed = false;

      if (searchInput && typeof data.q === "string" && searchInput.value !== data.q) {
        searchInput.value = data.q;
        changed = true;
      }
      if (estadoSelect && typeof data.estado === "string" && data.estado && estadoSelect.value !== data.estado) {
        estadoSelect.value = data.estado;
        changed = true;
      }
      if (limitSel && typeof data.limit === "string" && data.limit && limitSel.value !== data.limit) {
        limitSel.value = data.limit;
        changed = true;
      }
      if (sortInp && typeof data.sort === "string" && data.sort && sortInp.value !== data.sort) {
        sortInp.value = data.sort;
        changed = true;
      }
      if (dirInp && typeof data.dir === "string" && data.dir && dirInp.value !== data.dir) {
        dirInp.value = data.dir;
        changed = true;
      }

      if (changed) {
        const form =
          document.getElementById("filtersForm") ||
          searchInput?.closest("form") ||
          estadoSelect?.closest("form") ||
          limitSel?.closest("form");

        if (form) {
          form.submit();
          return true;
        }
      }

      return false;
    } catch (e) {
      console.warn("[productos.js] Error restaurando filtros:", e);
      return false;
    }
  }

  window.addEventListener("beforeunload", saveFiltersState);

  /* =========================
     ✅ TRACKING DE OPERACIONES (sessionStorage)
     Permite saber si venimos de create/edit/toggle
  ========================= */
  const SS_LAST_OP = "flus_productos_last_op_v1";

  function setLastOp(op) {
    try { 
      sessionStorage.setItem(SS_LAST_OP, String(op || "")); 
      console.log(`[productos.js] Operación marcada: ${op}`);
    } catch {}
  }

  function getLastOp() {
    try { return sessionStorage.getItem(SS_LAST_OP) || ""; } catch { return ""; }
  }

  function clearLastOp() {
    try { 
      sessionStorage.removeItem(SS_LAST_OP); 
      console.log("[productos.js] Marca de operación limpiada");
    } catch {}
  }

  /* =========================
     ✅ DETECCIÓN DE ERRORES Y DATOS
  ========================= */
  function hasErrorSignal() {
    const url = new URL(window.location.href);
    if (url.searchParams.has("error") || url.searchParams.has("err")) return true;

    return !!document.querySelector(
      ".alert-danger, .alert-error, .form-errors, .field-error, .error, .is-error"
    );
  }

  function mainFormHasData() {
    const form = document.querySelector(".productos-form");
    if (!form) return false;

    // ✅ MEJORADO: Checkea ID primero (más importante)
    const idInput = form.querySelector('input[name="id"]');
    if (idInput && idInput.value.trim() !== "") return true;

    // ✅ MEJORADO: Solo campos significativos (excluye 0 válidos)
    const significantFields = [
      { sel: 'input[name="codigo"]', allowZero: false },
      { sel: 'input[name="nombre"]', allowZero: false },
      { sel: 'input[name="categoria"]', allowZero: false },
      { sel: 'input[name="marca"]', allowZero: false },
      { sel: 'input[name="proveedor"]', allowZero: false },
    ];

    return significantFields.some(({ sel, allowZero }) => {
      const el = form.querySelector(sel);
      const v = (el?.value ?? "").toString().trim();
      if (v === "") return false;
      if (!allowZero && v === "0") return false;
      return true;
    });
  }

  function cleanSuccessFlagsFromUrl() {
    try {
      const url = new URL(window.location.href);
      const flagsToClean = ["clearForm", "created", "updated", "saved", "ok", "editar", "ajax"];
      
      let cleaned = false;
      flagsToClean.forEach((k) => {
        if (url.searchParams.has(k)) {
          url.searchParams.delete(k);
          cleaned = true;
        }
      });

      if (cleaned) {
        history.replaceState({}, "", url.toString());
        console.log("[productos.js] Flags de URL limpiados:", flagsToClean.filter(k => url.searchParams.has(k)));
      }
    } catch {}
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
        const t = data?.csrf_token;
        if (!t || t.length < 32) return null;
        return t;
      } catch (e) {
        console.warn("[productos.js] Error fetching CSRF:", e);
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
    const inp =
      form.querySelector('input[name="csrf_token"]') ||
      form.querySelector('input[name="csrf"]');
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
      const href = a.getAttribute("href");
      if (href) a.setAttribute("href", updateHrefCsrf(href, token));
    });

    return token;
  }

  // ✅ CSRF auto-refresh + tracking de operaciones
  document.addEventListener(
    "submit",
    async (e) => {
      const form = e.target;
      if (!(form instanceof HTMLFormElement)) return;

      const method = (form.getAttribute("method") || "get").toLowerCase();
      if (method !== "post") return;

      const hasCsrf =
        !!form.querySelector('input[name="csrf_token"]') ||
        !!form.querySelector('input[name="csrf"]');
      if (!hasCsrf) return;

      // ✅ Marcar operación que provoca navegación
      if (form.id === "editForm" || form.closest("#editOverlay")) {
        setLastOp("edit");
      } else if (form.classList.contains("productos-form")) {
        setLastOp("create");
      }

      if (form.dataset.csrfRefreshing === "1") return;

      e.preventDefault();
      form.dataset.csrfRefreshing = "1";
      form.classList.add("saving");

      const token = await CsrfManager.get(true);
      if (token) setCsrfOnForm(form, token);

      try {
        form.submit();
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
     AUTOCOMPLETE
  ========================= */
  async function loadAutocompleteOnce(field, datalistId) {
    const datalist = document.getElementById(datalistId);
    if (!datalist) return;
    if (datalist.dataset.loaded === "1") return;

    try {
      const res = await fetch(`productos.php?autocomplete=${encodeURIComponent(field)}`, {
        cache: "no-store",
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      });
      if (!res.ok) return;

      const values = await res.json().catch(() => []);
      if (!Array.isArray(values)) return;

      datalist.innerHTML = "";
      values.forEach((v) => {
        const opt = document.createElement("option");
        opt.value = String(v);
        datalist.appendChild(opt);
      });

      datalist.dataset.loaded = "1";
    } catch (e) {
      console.warn("[productos.js] Error en autocomplete:", e);
    }
  }

  function initAutocomplete() {
    const fields = [
      { name: "categoria", datalist: "categorias-list" },
      { name: "marca", datalist: "marcas-list" },
      { name: "proveedor", datalist: "proveedores-list" },
    ];

    fields.forEach(({ name, datalist }) => {
      document.querySelectorAll(`input[name="${name}"]`).forEach((inp) => {
        if (inp.dataset.boundAutocomplete === "1") return;
        inp.dataset.boundAutocomplete = "1";

        const listId = datalist + (inp.closest("#editForm") ? "-edit" : "");
        inp.addEventListener("focus", () => loadAutocompleteOnce(name, listId));
      });
    });
  }

  /* =========================
     VALIDACIÓN TIEMPO REAL
  ========================= */
  function setupRealTimeValidation() {
    const forms = [
      document.querySelector(".productos-form"),
      document.getElementById("editForm"),
    ].filter(Boolean);

    forms.forEach((form) => {
      const codigoInp = form.querySelector('input[name="codigo"]');
      const precioInp = form.querySelector('input[name="precio"]');

      if (codigoInp && !codigoInp.dataset.boundValidation) {
        codigoInp.dataset.boundValidation = "1";

        let timer = null;
        let abortController = null;

        codigoInp.addEventListener("input", (e) => {
          clearTimeout(timer);

          if (abortController) abortController.abort();
          abortController = new AbortController();

          timer = setTimeout(async () => {
            const val = e.target.value.trim();
            if (val === "") {
              e.target.classList.remove("valid", "invalid");
              return;
            }

            const formId = form.querySelector('input[name="id"]');
            const id = formId?.value || null;

            const qp = new URLSearchParams({ checkCodigo: val });
            if (id) qp.set("id", id);

            try {
              const res = await fetch(`productos.php?${qp.toString()}`, {
                signal: abortController.signal,
                cache: "no-store",
                credentials: "same-origin",
                headers: { Accept: "application/json" },
              });

              const data = await res.json().catch(() => null);

              if (data?.exists === true) {
                e.target.classList.remove("valid");
                e.target.classList.add("invalid");
              } else {
                e.target.classList.remove("invalid");
                e.target.classList.add("valid");
              }
            } catch (err) {
              if (err?.name !== "AbortError") console.warn("[productos.js] Error validando código:", err);
            }
          }, 600);
        });
      }

      if (precioInp && !precioInp.dataset.boundValidation) {
        precioInp.dataset.boundValidation = "1";
        precioInp.addEventListener("input", (e) => {
          const val = parseFloat(e.target.value);
          if (isNaN(val) || val <= 0) {
            e.target.classList.add("invalid");
            e.target.classList.remove("valid");
          } else {
            e.target.classList.remove("invalid");
            e.target.classList.add("valid");
          }
        });
      }
    });
  }

  /* =========================
     ✅ CLEAR FORM (MEJORADO CON LIMPIEZA EXPLÍCITA)
  ========================= */
  function clearProductoFormMain() {
    const form = document.querySelector(".productos-form");
    if (!form) return false;

    console.log("[clearProductoFormMain] Limpiando formulario principal...");

    form.reset();

    // ✅ CRÍTICO: Limpiar explícitamente el campo ID
    const idInput = form.querySelector('input[name="id"]');
    if (idInput) {
      idInput.value = "";
      console.log("[clearProductoFormMain] ✓ Campo ID limpiado");
    }

    // defaults
    const chkActivo = form.querySelector('input[name="activo"]');
    if (chkActivo) chkActivo.checked = true;

    const setZero = (name) => {
      const el = form.querySelector(`[name="${name}"]`);
      if (el) el.value = "0";
    };
    setZero("stock");
    setZero("stock_minimo");

    const precio = form.querySelector('[name="precio"]');
    if (precio) precio.value = "0";

    const costo = form.querySelector('[name="costo"]');
    if (costo) costo.value = "";

    // file input
    const fileInp =
      document.getElementById("imagen") ||
      form.querySelector('input[type="file"][name="imagen"]');
    if (fileInp) fileInp.value = "";

    const fileName = document.getElementById("fileName");
    if (fileName) fileName.textContent = "Ningún archivo seleccionado";
    form.querySelectorAll(".file-name").forEach((s) => (s.textContent = "Ningún archivo seleccionado"));

    // pesable
    const pesable = document.getElementById("esPesableMain");
    if (pesable) {
      pesable.checked = false;
      pesable.dispatchEvent(new Event("change", { bubbles: true }));
    }

    const unidadReal =
      document.getElementById("unidad_venta_real_main") ||
      form.querySelector('input[name="unidad_venta"]');
    if (unidadReal) unidadReal.value = "UNIDAD";

    form.querySelectorAll('input[name="unidad_venta_visual"]').forEach((r) => (r.checked = false));

    // limpiar validaciones
    form.querySelectorAll("input, select, textarea").forEach((el) => {
      el.classList.remove("valid", "invalid", "error");
    });

    window.updatePesablePreview?.("main");

    // ✅ NUEVO: Limpiar URL si tiene parámetro editar
    const url = new URL(window.location.href);
    if (url.searchParams.has('editar') || url.searchParams.has('ajax')) {
      url.searchParams.delete('editar');
      url.searchParams.delete('ajax');
      window.history.replaceState({}, '', url.toString());
      console.log("[clearProductoFormMain] ✓ URL limpiada de parámetros editar/ajax");
    }

    // foco
    const codigo = form.querySelector('[name="codigo"]');
    if (codigo) {
      codigo.focus();
      codigo.select?.();
    }

    console.log("[clearProductoFormMain] ✓ Limpieza completa");
    return true;
  }

  function bindClearButton() {
    const btn = document.getElementById("btnClearForm");
    if (!btn || btn.dataset.boundClear === "1") return;
    btn.dataset.boundClear = "1";

    btn.addEventListener("click", (e) => {
      e.preventDefault();
      if (clearProductoFormMain()) {
        window.showToast?.("Formulario limpiado", "info", 1800);
      }
    });
  }

  function ensureMainFormVisible() {
    const formBlock = document.getElementById("productFormBlock");
    if (!formBlock) return;

    if (formBlock.classList.contains("is-collapsed")) {
      (
        document.getElementById("toggleFormBtn") ||
        document.getElementById("btnToggleForm") ||
        document.querySelector('[data-toggle-product-form="1"]') ||
        document.querySelector(".btn-new-product")
      )?.click();
    }
  }

  /* =========================
     ✅ DETECCIÓN DE DATOS OBSOLETOS (checkStaleFormData)
     Detecta si el formulario tiene ID sin estar en modo edición
  ========================= */
  function checkStaleFormData() {
    const url = new URL(window.location.href);
    const tieneEditarParam = url.searchParams.has('editar');
    
    if (tieneEditarParam) return; // Si estamos editando legítimamente, no tocar
    
    const form = document.querySelector('.productos-form');
    if (!form) return;
    
    const idInput = form.querySelector('input[name="id"]');
    const idValue = idInput?.value?.trim();
    
    // Si el formulario tiene ID pero NO estamos en modo editar, es data obsoleta
    if (idValue && idValue !== '') {
      console.log('[checkStaleFormData] ⚠️ Detectado ID residual sin parámetro editar:', idValue);
      clearProductoFormMain();
      return true;
    }
    
    return false;
  }

  /* =========================
     ✅ AUTO-LIMPIEZA INTELIGENTE (HÍBRIDO)
     Combina: URL flags + sessionStorage + detección de datos
  ========================= */
  function autoResetMainFormIfNeeded(reason = "init") {
    console.log(`[autoResetMainFormIfNeeded] Verificando (reason: ${reason})...`);

    // Si hay error, NO limpiar
    if (hasErrorSignal()) {
      console.log("[autoResetMainFormIfNeeded] ⚠️ Error detectado, cancelando limpieza");
      clearLastOp();
      return;
    }

    const url = new URL(window.location.href);
    const okByUrl =
      url.searchParams.get("clearForm") === "1" ||
      url.searchParams.get("created") === "1" ||
      url.searchParams.get("updated") === "1" ||
      url.searchParams.get("saved") === "1" ||
      url.searchParams.get("saved") === "created";

    const lastOp = getLastOp();

    // Caso 1: Venimos de una acción (create/edit/toggle)
    if (okByUrl || lastOp) {
      console.log(`[autoResetMainFormIfNeeded] ✓ Limpieza por acción (okByUrl: ${okByUrl}, lastOp: ${lastOp})`);
      
      // ✅ FIX: Solo abrir formulario si venimos de CREAR (no de editar/toggle)
      if (lastOp === 'create') {
        console.log("[autoResetMainFormIfNeeded] → Abriendo formulario (crear otro producto)");
        ensureMainFormVisible();
      } else if (lastOp === 'edit' || lastOp === 'toggle') {
        console.log("[autoResetMainFormIfNeeded] → NO abriendo formulario (solo limpieza)");
      }
      
      clearProductoFormMain();
      clearLastOp();
      cleanSuccessFlagsFromUrl();
      return;
    }

    // Caso 2: bfcache restauró valores
    if (reason === "pageshow-bfcache" && mainFormHasData()) {
      console.log("[autoResetMainFormIfNeeded] ✓ Limpieza por bfcache con datos");
      // ✅ FIX: NO abrir formulario en bfcache (molesto para el usuario)
      clearProductoFormMain();
      clearLastOp();
      return;
    }

    // Caso 3: Datos obsoletos (ID sin editar)
    if (reason === "init") {
      const hadStaleData = checkStaleFormData();
      if (hadStaleData) {
        console.log("[autoResetMainFormIfNeeded] ✓ Limpieza por datos obsoletos");
        return;
      }
    }

    console.log("[autoResetMainFormIfNeeded] ⊘ No se requiere limpieza");
  }

  /* =========================
     TOGGLE FORM
  ========================= */
  function initToggleForm() {
    const btn =
      document.getElementById("toggleFormBtn") ||
      document.getElementById("btnToggleForm") ||
      document.querySelector('[data-toggle-product-form="1"]') ||
      document.querySelector(".btn-new-product");

    const formBlock = document.getElementById("productFormBlock");
    if (!btn || !formBlock) return;

    if (btn.dataset.boundToggle === "1") return;
    btn.dataset.boundToggle = "1";

    const labelEl = btn.querySelector(".label") || btn;
    btn.setAttribute("aria-controls", "productFormBlock");

    const setUi = (collapsed) => {
      if (collapsed) {
        formBlock.classList.add("is-collapsed");
        labelEl.textContent = "Agregar producto";
        btn.setAttribute("aria-expanded", "false");
      } else {
        formBlock.classList.remove("is-collapsed");
        labelEl.textContent = "Ocultar formulario";
        btn.setAttribute("aria-expanded", "true");
      }
    };

    setUi(formBlock.classList.contains("is-collapsed"));

    btn.addEventListener(
      "click",
      (e) => {
        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        const willOpen = formBlock.classList.contains("is-collapsed");
        setUi(!willOpen);

        if (willOpen) {
          setTimeout(() => {
            const codigoInput = formBlock.querySelector('input[name="codigo"]');
            if (codigoInput) codigoInput.focus();
          }, 80);
        } else {
          const active = document.activeElement;
          if (active && formBlock.contains(active)) active.blur();
        }
      },
      true
    );
  }

  /* =========================
     FILE NAME
  ========================= */
  function bindFileName() {
    $$('input[type="file"]').forEach((inp) => {
      if (inp.dataset.boundFileName === "1") return;
      inp.dataset.boundFileName = "1";

      inp.addEventListener("change", (e) => {
        const file = e.target.files?.[0];
        const nameSpan = e.target.parentElement?.querySelector(".file-name");
        if (nameSpan) nameSpan.textContent = file ? file.name : "Ningún archivo seleccionado";
      });
    });
  }

  /* =========================
     SORT HEADERS
  ========================= */
  function bindSortHeaders() {
    const table = $(".productos-table");
    if (!table) return;

    $$("th[data-sort]", table).forEach((th) => {
      if (th.dataset.boundSort === "1") return;
      th.dataset.boundSort = "1";
      th.style.cursor = "pointer";

      th.addEventListener("click", () => {
        const col = th.dataset.sort;

        const url = new URL(window.location.href);
        const currentSort = url.searchParams.get("sort") || table.dataset.sort || "";
        const currentDir = (url.searchParams.get("dir") || table.dataset.dir || "ASC").toUpperCase();

        const newDir = col === currentSort && currentDir === "ASC" ? "DESC" : "ASC";

        url.searchParams.set("sort", col);
        url.searchParams.set("dir", newDir);
        url.searchParams.set("page", "1");
        window.location.href = url.toString();
      });
    });
  }

  /* =========================
     LIVE SEARCH
  ========================= */
  let searchDebounce = null;
  let lastSearchQuery = "";
  const MIN_SEARCH_CHARS = 2;

  function bindLiveSearch() {
    const searchInput = $("#searchInput");
    if (!searchInput || searchInput.dataset.boundLive === "1") return;
    searchInput.dataset.boundLive = "1";

    const searchWrapper = searchInput.parentElement;

    searchInput.addEventListener("input", () => {
      clearTimeout(searchDebounce);

      const val = searchInput.value.trim();
      if (val === lastSearchQuery) return;

      if (val.length > 0 && val.length < MIN_SEARCH_CHARS) {
        searchWrapper?.classList.remove("searching");
        return;
      }

      if (searchWrapper && val.length >= MIN_SEARCH_CHARS) {
        searchWrapper.classList.add("searching");
      }

      const delay = val.length < 4 ? 800 : 400;

      searchDebounce = setTimeout(() => {
        lastSearchQuery = val;
        searchWrapper?.classList.remove("searching");

        const form = searchInput.closest("form");
        const pageInp = form?.querySelector('input[name="page"]');
        if (pageInp) pageInp.value = "1";

        form?.submit();
      }, delay);
    });
  }

  /* =========================
     KEYBOARD SHORTCUTS
  ========================= */
  function initKeyboardShortcuts() {
    document.addEventListener("keydown", (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "k") {
        e.preventDefault();
        const searchInput = $("#searchInput");
        if (searchInput) {
          searchInput.focus();
          searchInput.select();
        }
      }

      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "n") {
        e.preventDefault();
        const btn =
          document.getElementById("toggleFormBtn") ||
          document.getElementById("btnToggleForm") ||
          document.querySelector('[data-toggle-product-form="1"]');

        const formBlock = $("#productFormBlock");

        if (btn && formBlock) {
          if (formBlock.classList.contains("is-collapsed")) btn.click();
          setTimeout(() => {
            const codigoInput = document.querySelector('.productos-form input[name="codigo"]');
            codigoInput?.focus();
          }, 100);
        }
      }

      if (e.key === "Escape") {
        const editOverlay = $("#editOverlay");
        const confirmToggle = $("#confirmToggle");

        if (editOverlay?.classList.contains("open")) {
          window.closeEditPanel?.();
        } else if (confirmToggle?.classList.contains("open")) {
          window.closeConfirm?.();
        }
      }
    });
  }

  /* =========================
     COPY PRODUCT
  ========================= */
  async function copyProduct(id) {
    try {
      const res = await fetch(`productos.php?editar=${encodeURIComponent(id)}&ajax=1`, {
        headers: { Accept: "application/json" },
        cache: "no-store",
        credentials: "same-origin",
      });

      const p = await res.json().catch(() => null);
      if (!p?.id) {
        window.showToast?.("No se encontró el producto", "error");
        return;
      }

      const form = document.querySelector(".productos-form");
      if (!form) return;

      const formBlock = document.getElementById("productFormBlock");
      if (formBlock?.classList.contains("is-collapsed")) {
        document.getElementById("toggleFormBtn")?.click();
      }

      // ✅ CRÍTICO: Limpiar ID primero
      const idInp = form.querySelector('input[name="id"]');
      if (idInp) {
        idInp.value = "";
        console.log("[copyProduct] ✓ Campo ID limpiado para nueva creación");
      }

      const fileInp = form.querySelector('input[type="file"][name="imagen"]');
      if (fileInp) fileInp.value = "";

      const setVal = (name, val) => {
        const el = form.querySelector(`[name="${name}"]`);
        if (!el) return;
        el.value = val ?? "";
        el.dispatchEvent(new Event("input", { bubbles: true }));
        el.dispatchEvent(new Event("change", { bubbles: true }));
      };

      setVal("nombre", p.nombre || "");
      setVal("categoria", p.categoria || "");
      setVal("marca", p.marca || "");
      setVal("proveedor", p.proveedor || "");
      setVal("iva", p.iva ?? "");
      setVal("precio", p.precio ?? "");
      setVal("costo", p.costo ?? "");
      setVal("stock_minimo", p.stock_minimo ?? "");
      setVal("stock", "0");

      const chkActivo = form.querySelector('input[name="activo"]');
      if (chkActivo) {
        chkActivo.checked = true;
        chkActivo.dispatchEvent(new Event("change", { bubbles: true }));
      }

      const chkPesable = form.querySelector('input[name="es_pesable"]');
      const unidad = p.unidad_venta || "UNIDAD";

      if (chkPesable) {
        chkPesable.checked = Number(p.es_pesable || 0) === 1;
        chkPesable.dispatchEvent(new Event("change", { bubbles: true }));
      }

      const unidadHidden = form.querySelector('input[name="unidad_venta"]');
      if (unidadHidden) unidadHidden.value = unidad;

      const radio = form.querySelector(`input[name="unidad_venta_visual"][value="${unidad}"]`);
      if (radio) {
        radio.checked = true;
        radio.dispatchEvent(new Event("change", { bubbles: true }));
      }

      window.updatePesablePreview?.("main");

      setVal("codigo", "");

      const codigoEl = form.querySelector('input[name="codigo"]');
      if (codigoEl) {
        codigoEl.classList.remove("valid");
        codigoEl.classList.add("invalid");
        setTimeout(() => {
          codigoEl.focus();
          codigoEl.select();
        }, 0);
      }

      // ✅ Limpiar URL
      const url = new URL(window.location.href);
      url.searchParams.delete('editar');
      url.searchParams.delete('ajax');
      window.history.replaceState({}, '', url.toString());

      form.scrollIntoView({ behavior: "smooth", block: "start" });
      window.showToast?.("Plantilla copiada. Ingresá un NUEVO código y guardá.", "info", 3200);
    } catch (err) {
      console.error("[copyProduct] Error:", err);
      window.showToast?.("Error al copiar producto", "error");
    }
  }

  function bindCopyButtons() {
    $$("[data-copy-id]").forEach((btn) => {
      if (btn.dataset.boundCopy === "1") return;
      btn.dataset.boundCopy = "1";

      btn.addEventListener("click", (e) => {
        e.preventDefault();
        const id = btn.dataset.copyId;
        if (id) copyProduct(id);
      });
    });
  }

  /* =========================
     EXPAND DETAILS
  ========================= */
  window.toggleDetailRow = (id) => {
    const detailRow = document.getElementById(`detail-${id}`);
    if (!detailRow) return;

    detailRow.classList.toggle("expanded");
    const btn = $(`.producto-row[data-id="${id}"] .btn-expand`);
    if (btn) btn.textContent = detailRow.classList.contains("expanded") ? "⊖" : "⊕";
  };

  function bindExpandAll() {
    const btnExpandAll = $(".btn-expand-all");
    if (!btnExpandAll || btnExpandAll.dataset.boundExpandAll === "1") return;
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
     EDIT PANEL (Overlay)
  ========================= */
  const overlay = $("#editOverlay");
  const editForm = $("#editForm");
  const pageWrap = $(".page-wrap");

  function setBlur(on) {
    pageWrap?.classList.toggle("blurred", !!on);
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

  overlay?.addEventListener("click", (e) => {
    if (e.target === overlay) closeOverlay();
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
      if (!data?.id) {
        window.showToast?.("No se encontró el producto.", "error");
        return;
      }

      const setVal = (name, val) => {
        const el = editForm.querySelector(`[name="${name}"]`);
        if (el) el.value = val ?? "";
      };

      editForm.querySelector('input[name="id"]').value = data.id ?? "";
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

      const chkPesable = editForm.querySelector('input[name="es_pesable"]');
      if (chkPesable) chkPesable.checked = Number(data.es_pesable || 0) === 1;

      const chkActivo = editForm.querySelector('input[name="activo"]');
      if (chkActivo) chkActivo.checked = Number(data.activo ?? 1) === 1;

      const hiddenUnidad = editForm.querySelector('input[type="hidden"][name="unidad_venta"]');
      if (hiddenUnidad) {
        hiddenUnidad.value = data.unidad_venta || "UNIDAD";

        const radio = editForm.querySelector(
          `input[name="unidad_venta_visual_edit"][value="${data.unidad_venta}"]`
        );
        if (radio) radio.checked = true;

        const optionsContainer = editForm.querySelector("#pesableOptionsEdit");
        if (optionsContainer) optionsContainer.style.display = chkPesable?.checked ? "block" : "none";
      }

      const file = editForm.querySelector('input[type="file"][name="imagen"]');
      if (file) file.value = "";

      const t = await CsrfManager.get(true);
      if (t) setCsrfOnForm(editForm, t);

      openOverlay();

      setTimeout(() => {
        window.updatePesablePreview?.("edit");
      }, 100);
    } catch (err) {
      console.error("[openEditPanel] Error:", err);
      window.showToast?.("No se pudo cargar el producto.", "error");
    }
  };

  window.closeEditPanel = closeOverlay;

  /* =========================
     CONFIRM MODAL (toggle activo/inactivo)
  ========================= */
  const confirmOv = $("#confirmToggle");
  let pendingHref = null;

  function openConfirm({ action, href }) {
    if (!confirmOv) return;

    pendingHref = href;
    const isDesactivar = action === "desactivar";

    const title = confirmOv.querySelector("#confirmTitle");
    const text = confirmOv.querySelector("#confirmText");

    if (title) title.textContent = isDesactivar ? "Desactivar producto" : "Activar producto";
    if (text) {
      text.textContent = isDesactivar
        ? "¿Desactivar producto? No aparecerá en Caja ni en búsquedas."
        : "¿Activar producto? Volverá a aparecer en Caja y búsquedas.";
    }

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

  confirmOv?.querySelector("#confirmCancel")?.addEventListener("click", closeConfirm);
  confirmOv?.addEventListener("click", (e) => {
    if (e.target === confirmOv) closeConfirm();
  });

  confirmOv?.querySelector("#confirmAccept")?.addEventListener("click", async () => {
    if (!pendingHref) return;
    const token = await CsrfManager.get(true);
    const hrefFinal = token ? updateHrefCsrf(pendingHref, token) : pendingHref;

    // ✅ Marcar operación toggle
    setLastOp("toggle");

    window.location.href = hrefFinal;
  });

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
      $(".quick-stats")?.remove();

      const statsDiv = document.createElement("div");
      statsDiv.className = "quick-stats";
      statsDiv.innerHTML = `
        <div class="stat-item"><span class="stat-value">${stats.total || 0}</span><span class="stat-label">Total</span></div>
        <div class="stat-item"><span class="stat-value">${stats.activos || 0}</span><span class="stat-label">Activos</span></div>
        <div class="stat-item"><span class="stat-value">${stats.sin_stock || 0}</span><span class="stat-label">Sin stock</span></div>
        <div class="stat-item"><span class="stat-value">${stats.stock_bajo || 0}</span><span class="stat-label">Stock bajo</span></div>
      `;

      const panel = $(".productos-page .panel");
      const header = panel?.querySelector(".productos-header");
      if (panel && header) panel.insertBefore(statsDiv, header.nextSibling);
    } catch (e) {
      console.warn("[productos.js] Error cargando estadísticas:", e);
    }
  }

  /* =========================
     PESABLE CARDS
  ========================= */
  (() => {
    function initPesableCards(config) {
      const { toggleId, optionsId, radioName, hiddenId, previewId } = config;

      const toggle = document.getElementById(toggleId);
      const options = document.getElementById(optionsId);
      const hiddenInput = document.getElementById(hiddenId);
      const previewBox = document.getElementById(previewId);

      if (!toggle || !options || !hiddenInput) return false;

      if (toggle.dataset.boundPesableCards === "1") {
        const type = toggleId.includes("Main") ? "main" : "edit";
        window[`updatePesablePreview_${type}`]?.();
        return true;
      }
      toggle.dataset.boundPesableCards = "1";

      const form = toggle.closest("form") || document;
      const radios = Array.from(form.querySelectorAll(`input[name="${radioName}"]`));

      const UNIT_MAP = {
        KG: { value: "KG", label: "KG" },
        G: { value: "G", label: "100 G" },
        LT: { value: "LT", label: "Litro" },
        ML: { value: "ML", label: "100 ML" },
      };

      function getSelectedRadio() {
        return form.querySelector(`input[name="${radioName}"]:checked`);
      }

      function updatePreview() {
        if (!previewBox) return;

        const precioInput = form.querySelector('[name="precio"]');
        const precio = precioInput ? parseFloat(precioInput.value) || 0 : 0;

        const selectedRadio = getSelectedRadio();
        const valueSpan = previewBox.querySelector(".preview-compact-value");
        if (!valueSpan) return;

        if (!selectedRadio || !toggle.checked) {
          previewBox.classList.add("empty");
          valueSpan.textContent = "—";
          return;
        }

        const unit = UNIT_MAP[selectedRadio.value];
        if (!unit) return;

        previewBox.classList.remove("empty");
        valueSpan.textContent = `$${precio.toFixed(2)} / ${unit.label}`;
      }

      function syncHiddenInput(value) {
        hiddenInput.value = value || "UNIDAD";
      }

      function toggleOptions(show) {
        if (show) {
          options.style.display = "block";

          const hasSelection = !!getSelectedRadio();
          if (!hasSelection && radios.length > 0) {
            radios[0].checked = true;
            syncHiddenInput(radios[0].value);
          }
        } else {
          options.style.display = "none";
          radios.forEach((r) => (r.checked = false));
          syncHiddenInput("UNIDAD");
        }
        updatePreview();
      }

      function initializeState() {
        const isPesable = toggle.checked;
        const currentValue = hiddenInput.value;

        if (isPesable) {
          options.style.display = "block";
          if (currentValue && currentValue !== "UNIDAD") {
            const matchingRadio = form.querySelector(
              `input[name="${radioName}"][value="${currentValue}"]`
            );
            if (matchingRadio) matchingRadio.checked = true;
          }
        } else {
          options.style.display = "none";
        }

        updatePreview();
      }

      toggle.addEventListener("change", () => toggleOptions(toggle.checked));

      radios.forEach((radio) => {
        if (radio.dataset.boundPesableCards === "1") return;
        radio.dataset.boundPesableCards = "1";

        radio.addEventListener("change", () => {
          if (radio.checked) {
            syncHiddenInput(radio.value);
            updatePreview();
          }
        });
      });

      const precioInput = form.querySelector('[name="precio"]');
      if (precioInput && precioInput.dataset.boundPesableCards !== "1") {
        precioInput.dataset.boundPesableCards = "1";
        precioInput.addEventListener("input", updatePreview);
      }

      initializeState();

      const formType = toggleId.includes("Main") ? "main" : "edit";
      window[`updatePesablePreview_${formType}`] = updatePreview;

      return true;
    }

    window.updatePesablePreview = function (type = "main") {
      const fn = window[`updatePesablePreview_${type}`];
      if (fn) fn();
    };

    function initAllPesableCards() {
      initPesableCards({
        toggleId: "esPesableMain",
        optionsId: "pesableOptionsMain",
        radioName: "unidad_venta_visual",
        hiddenId: "unidad_venta_real_main",
        previewId: "pesablePreviewMain",
      });

      initPesableCards({
        toggleId: "esPesableEdit",
        optionsId: "pesableOptionsEdit",
        radioName: "unidad_venta_visual_edit",
        hiddenId: "unidad_venta_real_edit",
        previewId: "pesablePreviewEdit",
      });
    }

    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", initAllPesableCards);
    } else {
      setTimeout(initAllPesableCards, 50);
    }

    const originalOpenEditPanel = window.openEditPanel;
    if (originalOpenEditPanel) {
      window.openEditPanel = async function (...args) {
        await originalOpenEditPanel.apply(this, args);
        setTimeout(initAllPesableCards, 150);
      };
    }
  })();

  /* =========================
     EXPORT CSV
  ========================= */
  function initExportButton() {
    const exportBtn = document.getElementById("btnExportCSV");
    if (!exportBtn || exportBtn.dataset.boundExport === "1") return;
    exportBtn.dataset.boundExport = "1";

    exportBtn.addEventListener("click", (e) => {
      e.preventDefault();

      const rows = $$(".producto-row");
      if (rows.length === 0) {
        window.showToast?.("No hay productos para exportar", "warning");
        return;
      }

      let csv = "Código,Nombre,Precio,Stock,Estado\n";

      rows.forEach((row) => {
        const cells = $$("td", row);
        if (cells.length >= 6) {
          const codigo = cells[1].textContent.trim();
          const nombre = cells[2].textContent.trim().replace(/"/g, '""');
          const precio = cells[3].textContent.trim();
          const stock = cells[4].textContent.trim();
          const estado = cells[5].textContent.trim();

          csv += `"${codigo}","${nombre}","${precio}","${stock}","${estado}"\n`;
        }
      });

      const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      const fecha = new Date().toISOString().split("T")[0];

      link.href = url;
      link.download = `productos_${fecha}.csv`;
      link.style.display = "none";
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(url);

      window.showToast?.(`${rows.length} productos exportados`, "success");
    });
  }

  /* =========================
     ✅ INIT PRINCIPAL
  ========================= */
  async function init() {
    // ✅ CRÍTICO: Validar página
    if (!esProductosPage()) {
      console.log('[productos.js] ⊘ No es la página de productos, saliendo');
      return;
    }

    console.log('[productos.js] ✓ Inicializando módulo productos...');

    document.querySelector('select[name="limit"]')?.addEventListener("change", saveFiltersState);

    if (restoreFiltersState()) return;

    initAutocomplete();
    bindClearButton();
    setupRealTimeValidation();
    initToggleForm();
    bindFileName();
    bindSortHeaders();
    bindLiveSearch();
    bindExpandAll();
    bindToggleConfirm();
    initKeyboardShortcuts();
    initExportButton();

    $("#searchInput")?.addEventListener("input", saveFiltersState);
    $("#estadoSelect")?.addEventListener("change", saveFiltersState);

    await refreshCsrfEverywhere();
    loadQuickStats();

    const tbody = $("#productosTbody");
    if (tbody && tbody.dataset.boundObserver !== "1") {
      tbody.dataset.boundObserver = "1";
      const observer = new MutationObserver(() => {
        bindCopyButtons();
        bindToggleConfirm();
      });
      observer.observe(tbody, { childList: true, subtree: true });
    }

    bindCopyButtons();

    // ✅ Auto-limpieza inteligente
    autoResetMainFormIfNeeded("init");

    console.log('[productos.js] ✓ Inicialización completa');
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }

  // ✅ bfcache: detectar cuando el browser restaura la página
  window.addEventListener("pageshow", (ev) => {
    if (ev.persisted) {
      console.log('[productos.js] ⚠️ Página restaurada desde bfcache');
      autoResetMainFormIfNeeded("pageshow-bfcache");
    }
  });
})();
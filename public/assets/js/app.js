/* ============================================================================
   public/assets/js/app.js  (MONOLÍTICO - mantiene TODO lo que ya tenías)
   Fixes:
   - Guard anti doble-carga
   - Theme: no rompe si body no existe
   - Heartbeat: no crea doble timer + ping siempre boolean
   - showToast: no pisa otras versiones, soporta (msg,type,ms)
   - apiJson: no manda body en GET/HEAD + cache no-store
   - Tabla productos: escape + encode imagen + AbortController
============================================================================ */

(() => {
  "use strict";

  // ✅ Guard anti doble-carga
  if (window.__flus_app_js_loaded) return;
  window.__flus_app_js_loaded = true;

  const onReady = (fn) => {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", fn, { once: true });
    } else {
      fn();
    }
  };

  const esc = (s) =>
    String(s ?? "").replace(/[&<>"']/g, (c) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    }[c]));

  // ============================================
  // CSRF token global para fetch (una sola vez)
  // ============================================
  if (!window.getCsrfToken) {
    window.getCsrfToken = function () {
      return document.querySelector('meta[name="csrf-token"]')?.content || "";
    };
  }

  // ============================================
  // TOAST GLOBAL (no pisa otras versiones)
  // ============================================
  if (!window.showToast) {
    window.showToast = (msg, type = "info", ms = 2800) => {
      const t = document.getElementById("toast");
      if (!t) return;

      t.textContent = msg;

      // opcional si tenés estilos por tipo
      t.classList.remove("toast-info", "toast-warn", "toast-error", "toast-ok", "toast-success");
      t.classList.add(`toast-${type}`);

      t.classList.add("show");
      setTimeout(() => t.classList.remove("show"), ms);
    };
  }

  // ============================================
  // Cliente API estándar FLUS (robusto)
  // ============================================
  if (!window.apiJson) {
    window.apiJson = async function (url, payload = {}, opts = {}) {
      const method = String(opts.method || "POST").toUpperCase();

      const headers = Object.assign(
        { "X-CSRF-Token": getCsrfToken(), Accept: "application/json" },
        opts.headers || {}
      );

      let body = null;

      if (method !== "GET" && method !== "HEAD") {
        headers["Content-Type"] = "application/json; charset=utf-8";
        const b = Object.assign({}, payload);

        if (!("csrf_token" in b) && !("csrf" in b)) b.csrf_token = getCsrfToken();
        body = JSON.stringify(b);
      }

      const res = await fetch(url, {
        method,
        headers,
        body,
        credentials: "same-origin",
        cache: "no-store",
      });

      let data = null;
      try {
        data = await res.json();
      } catch {
        throw new Error("Respuesta no es JSON (posible warning/HTML en backend).");
      }

      if (!res.ok || !data?.ok) {
        const msg = data?.error || `HTTP ${res.status}`;
        const err = new Error(msg);
        err.status = res.status;
        err.data = data;
        throw err;
      }

      return data;
    };
  }

  // ============================================
  // VARIABLES (scoped)
  // ============================================
  let currentEditId = null;

  // ============================================
  // TEMA OSCURO / CLARO (ROBUSTO)
  // ============================================
  (() => {
    const getCookie = (name) => {
      const escName = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
      const m = document.cookie.match(new RegExp("(?:^|;\\s*)" + escName + "=([^;]*)"));
      return m ? decodeURIComponent(m[1]) : "";
    };

    const setCookie = (name, value) => {
      document.cookie =
        name +
        "=" +
        encodeURIComponent(value) +
        "; path=/; max-age=31536000; samesite=lax";
    };

    const applyTheme = (theme) => {
      document.documentElement.setAttribute("data-theme", theme);
      if (document.body) document.body.setAttribute("data-theme", theme);

      localStorage.setItem("kiosco-theme", theme);
      localStorage.setItem("theme", theme);
      setCookie("theme", theme);
    };

    const initTheme = () => {
      const toggle = document.getElementById("toggleTheme");
      if (!toggle) return;

      const prefersDark =
        window.matchMedia &&
        window.matchMedia("(prefers-color-scheme: dark)").matches;

      const saved =
        localStorage.getItem("kiosco-theme") ||
        localStorage.getItem("theme") ||
        getCookie("theme") ||
        "";

      const initialTheme = saved || (prefersDark ? "dark" : "light");

      applyTheme(initialTheme);

      // ✅ tu UI: checked = modo claro
      toggle.checked = initialTheme === "light";

      toggle.addEventListener("change", () => {
        const newTheme = toggle.checked ? "light" : "dark";
        applyTheme(newTheme);
      });
    };

    onReady(initTheme);
  })();

  // ============================================
  // TERMINAL LOCK HEARTBEAT (multi-caja / multi-PC)
  // ============================================
  onReady(() => {
    if (window.__pauseTerminalHeartbeat) return;
    if (window.__flus_terminal_heartbeat_started) return;
    window.__flus_terminal_heartbeat_started = true;

    const p = (window.location.pathname || "").toLowerCase();
    if (p.endsWith("/login.php") || p.endsWith("/terminal_select.php") || p.includes("/login")) return;

    let stopped = false;

    const ping = async () => {
      if (stopped) return false;

      const CSRF = (window.getCsrfToken && window.getCsrfToken()) || "";
      if (!CSRF) return false;

      try {
        const r = await fetch("api/index.php?action=terminal_heartbeat", {
          method: "POST",
          headers: {
            "X-CSRF-Token": CSRF,
            "Content-Type": "application/json; charset=utf-8",
            Accept: "application/json",
          },
          body: JSON.stringify({}),
          cache: "no-store",
          credentials: "same-origin",
        });

        if (r.status === 401) {
          stopped = true;
          return false;
        }

        if (r.status === 403) {
          stopped = true;
          window.location.reload();
          return false;
        }

        if (r.status === 409) {
          stopped = true;

          let j = null;
          try { j = await r.json(); } catch (_) {}

          if (j && (j.error === "NO_TERMINAL" || j.error === "LOCK_NOT_OWNED")) {
            window.location.href = "terminal_select.php?next=caja.php";
            return false;
          }

          if (j && (j.error === "LOCKED" || j.error === "LOCK_LOST")) {
            window.location.href = "logout.php?reason=locked";
            return false;
          }

          return false;
        }

        if (r.ok) {
          try { await r.json(); } catch (_) {}
          return true;
        }

        return false;
      } catch (_) {
        return false;
      }
    };

    let failCount = 0;
    let timer = null;
    let inFlight = false;

    const nextDelay = () => {
      if (failCount <= 0) return 25000;
      if (failCount === 1) return 45000;
      if (failCount === 2) return 90000;
      return 180000;
    };

    const schedule = () => {
      if (timer) clearTimeout(timer);
      timer = setTimeout(pingWrapped, nextDelay());
    };

    const pingWrapped = async () => {
      if (stopped || inFlight) return;
      inFlight = true;

      try {
        const ok = await ping();
        if (ok) {
          failCount = 0;
        } else {
          failCount = Math.min(9, failCount + 1);
          if (window.showToast && failCount === 1) {
            window.showToast("Servidor sin respuesta. Reintentando…", "warn", 2500);
          }
        }
      } finally {
        inFlight = false;
        if (!stopped) schedule();
      }
    };

    // ✅ Arranque (SIN schedule extra)
    pingWrapped();
  });

  // ============================================
  // PANEL LATERAL EDICIÓN + BLUR
  // ============================================

  function fillEditForm(data) {
    const form = document.getElementById("editForm");
    if (!form || !data) return;

    const set = (name, value) => {
      if (!form.elements[name]) return;
      form.elements[name].value = value != null ? value : "";
    };

    set("id", data.id);
    set("codigo", data.codigo);
    set("nombre", data.nombre);
    set("categoria", data.categoria);
    set("marca", data.marca);
    set("proveedor", data.proveedor);

    set("precio", data.precio);
    set("costo", data.costo);

    const isPes = !!Number(data.es_pesable);

    if (form.elements["stock"]) {
      const stockVal = Number(data.stock ?? 0);
      form.elements["stock"].value = isPes ? stockVal.toFixed(3) : stockVal.toFixed(0);
    }

    if (form.elements["stock_minimo"]) {
      const stockMinVal = Number(data.stock_minimo ?? 0);
      form.elements["stock_minimo"].value = isPes ? stockMinVal.toFixed(3) : stockMinVal.toFixed(0);
    }

    if (form.elements["iva"]) {
      form.elements["iva"].value = data.iva != null ? String(data.iva) : "";
    }

    if (form.elements["unidad_venta"]) {
      form.elements["unidad_venta"].value = data.unidad_venta || "UNIDAD";
    }

    if (form.elements["es_pesable"]) {
      form.elements["es_pesable"].checked = !!Number(data.es_pesable);
    }

    if (form.elements["activo"]) {
      form.elements["activo"].checked = Number(data.activo) === 1;
    }
  }

  function openEditPanel(id) {
    const overlay = document.getElementById("editOverlay");
    const root = document.querySelector(".root");
    const draftKeyBase = "kiosco-producto-edit-";

    if (overlay) overlay.classList.add("open");
    if (root) root.classList.add("blurred");

    fetch("productos.php?editar=" + encodeURIComponent(id) + "&ajax=1", {
      cache: "no-store",
      credentials: "same-origin",
    })
      .then((r) => (r.ok ? r.json() : null))
      .then((data) => {
        if (!data) return;

        currentEditId = data.id || id;
        const draftKey = draftKeyBase + currentEditId;

        const draftStr = localStorage.getItem(draftKey);
        let merged = { ...data };

        if (draftStr) {
          try {
            const draft = JSON.parse(draftStr);
            merged = { ...merged, ...draft };
          } catch (e) {
            console.error("Error leyendo borrador de producto", e);
          }
        }

        fillEditForm(merged);
      })
      .catch((err) => console.error("Error cargando producto", err));
  }

  function closeEditPanel() {
    const overlay = document.getElementById("editOverlay");
    const root = document.querySelector(".root");
    if (overlay) overlay.classList.remove("open");
    if (root) root.classList.remove("blurred");
    currentEditId = null;
  }

  window.openEditPanel = openEditPanel;
  window.closeEditPanel = closeEditPanel;

  onReady(() => {
    const overlay = document.getElementById("editOverlay");
    if (!overlay) return;

    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) closeEditPanel();
    });
  });

  // ============================================
  // BÚSQUEDA INSTANTÁNEA + ORDEN – PRODUCTOS
  // ============================================
  onReady(() => {
    const searchInput = document.querySelector("input[name='q']");
    const table = document.querySelector(".productos-table");
    const tableBody = document.querySelector(".productos-table tbody");
    const estadoSel = document.querySelector("select[name='estado']");
    const sortHeaders = document.querySelectorAll(".productos-table thead th[data-sort]");

    if (!searchInput || !table || !tableBody || !estadoSel) return;

    let timer = null;
    let currentSortField = table.dataset.sort || "nombre";
    let currentSortDir = String(table.dataset.dir || "ASC").toUpperCase();

    function formatStock(p) {
      const raw = Number(p.stock ?? 0);
      if (Number.isNaN(raw)) return esc(p.stock ?? "0");

      const esPesable = Number(p.es_pesable ?? 0) === 1;
      return esPesable
        ? raw.toFixed(3).replace(".", ",")
        : raw.toLocaleString("es-AR", { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    function renderTable(data) {
      if (!Array.isArray(data) || !data.length) {
        tableBody.innerHTML = `
          <tr><td colspan="11" class="empty-cell">No se encontraron productos.</td></tr>`;
        return;
      }

      tableBody.innerHTML = data
        .map((p) => {
          let tag = "";
          if (!Number(p.activo)) tag = `<span class="tag tag-inactivo">Inactivo</span>`;
          else if (Number(p.stock) <= 0) tag = `<span class="tag tag-sin">Sin stock</span>`;
          else if (Number(p.stock) <= Number(p.stock_minimo)) tag = `<span class="tag tag-bajo">Stock bajo</span>`;
          else tag = `<span class="tag tag-ok">OK</span>`;

          const precio = Number(p.precio ?? 0).toFixed(2).replace(".", ",");

          const imgName = String(p.imagen ?? "");
          const thumb = imgName
            ? `<img src="img/productos/${encodeURIComponent(imgName)}" alt="img" class="prod-thumb">`
            : `<span class="prod-thumb-placeholder">—</span>`;

          const id = Number(p.id);

          return `
            <tr>
              <td class="center">${thumb}</td>
              <td>${esc(p.codigo ?? "")}</td>
              <td>${esc(p.nombre ?? "")}</td>
              <td>${esc(p.categoria ?? "")}</td>
              <td>${esc(p.marca ?? "")}</td>
              <td>${esc(p.proveedor ?? "")}</td>
              <td>${p.iva != null ? esc(p.iva) + "%" : ""}</td>
              <td class="right">$${precio}</td>
              <td class="right">${formatStock(p)}</td>
              <td class="center">${tag}</td>
              <td class="center">
                <a href="#" class="btn-line btn-edit" onclick="openEditPanel(${id}); return false;">Editar</a>
                ${
                  Number(p.activo)
                    ? `<a class="btn-line btn-toggle js-product-toggle" href="productos.php?eliminar=${id}" data-action="desactivar">Desactivar</a>`
                    : `<a class="btn-line btn-toggle js-product-toggle" href="productos.php?activar=${id}" data-action="activar">Activar</a>`
                }
              </td>
            </tr>`;
        })
        .join("");
    }

    // ✅ evita “saltos” por respuestas fuera de orden
    let ac = null;
    let reqId = 0;

    function doSearch() {
      const q = searchInput.value;
      const estado = estadoSel.value;

      const url =
        `productos.php?ajaxList=1` +
        `&q=${encodeURIComponent(q)}` +
        `&estado=${encodeURIComponent(estado)}` +
        `&sort=${encodeURIComponent(currentSortField)}` +
        `&dir=${encodeURIComponent(currentSortDir)}`;

      if (ac) ac.abort();
      ac = new AbortController();
      const myId = ++reqId;

      fetch(url, { cache: "no-store", signal: ac.signal, credentials: "same-origin" })
        .then((r) => r.json())
        .then((data) => {
          if (myId !== reqId) return;
          renderTable(data);
        })
        .catch((err) => {
          if (err?.name === "AbortError") return;
          console.error("doSearch error:", err);
        });
    }

    searchInput.addEventListener("input", () => {
      clearTimeout(timer);
      timer = setTimeout(doSearch, 500);
    });

    estadoSel.addEventListener("change", doSearch);

    sortHeaders.forEach((th) => {
      th.addEventListener("click", () => {
        const field = th.dataset.sort;
        if (!field) return;

        if (currentSortField === field) currentSortDir = currentSortDir === "ASC" ? "DESC" : "ASC";
        else {
          currentSortField = field;
          currentSortDir = "ASC";
        }

        sortHeaders.forEach((h) => h.classList.remove("sorted-asc", "sorted-desc"));
        th.classList.add(currentSortDir === "ASC" ? "sorted-asc" : "sorted-desc");

        doSearch();
      });
    });
  });

  // ============================================
  // MODAL CONFIRMACIÓN ACTIVAR / DESACTIVAR
  // ============================================
  onReady(() => {
    const overlay = document.getElementById("confirmToggle");
    const titleEl = document.getElementById("confirmTitle");
    const textEl = document.getElementById("confirmText");
    const btnOk = document.getElementById("confirmAccept");
    const btnCancel = document.getElementById("confirmCancel");

    if (!overlay || !btnOk || !btnCancel || !titleEl || !textEl) return;

    let pendingUrl = null;

    document.addEventListener("click", (e) => {
      const link = e.target.closest(".js-product-toggle");
      if (!link) return;

      e.preventDefault();

      pendingUrl = link.href;
      const action = (link.dataset.action || "").toLowerCase();

      if (action === "activar") {
        titleEl.textContent = "Activar producto";
        textEl.textContent = "¿Querés activar este producto? Volverá a aparecer en Caja y en búsquedas de ventas.";
      } else {
        titleEl.textContent = "Desactivar producto";
        textEl.textContent = "¿Desactivar este producto? No aparecerá en Caja ni en búsquedas de ventas.";
      }

      overlay.classList.add("open");
    });

    btnCancel.addEventListener("click", () => {
      overlay.classList.remove("open");
      pendingUrl = null;
    });

    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) {
        overlay.classList.remove("open");
        pendingUrl = null;
      }
    });

    btnOk.addEventListener("click", () => {
      if (pendingUrl) window.location.href = pendingUrl;
    });
  });

  // ============================================
  // AUTO-GUARDADO FORMULARIOS PRODUCTOS
  // ============================================
  onReady(() => {
    const mainForm = document.querySelector(".productos-form");
    const mainDraftKey = "kiosco-producto-draft";

    if (mainForm) {
      const params = new URLSearchParams(window.location.search);
      if (params.has("saved")) localStorage.removeItem(mainDraftKey);

      const isEditingMain = !!mainForm.querySelector("input[name='id']");

      if (!isEditingMain) {
        const saved = localStorage.getItem(mainDraftKey);
        if (saved) {
          try {
            const data = JSON.parse(saved);
            for (const k in data) {
              const field = mainForm.elements[k];
              if (!field) continue;
              if (field.type === "checkbox") field.checked = !!data[k];
              else field.value = data[k];
            }
          } catch (_) {}
        }
      }

      mainForm.addEventListener("input", () => {
        const fd = new FormData(mainForm);
        const plain = {};
        fd.forEach((val, key) => {
          if (key === "id") return;
          const field = mainForm.elements[key];
          if (field && field.type === "checkbox") plain[key] = field.checked ? 1 : 0;
          else plain[key] = val;
        });
        localStorage.setItem(mainDraftKey, JSON.stringify(plain));
      });
    }

    const editForm = document.getElementById("editForm");
    if (editForm) {
      editForm.addEventListener("input", () => {
        if (!currentEditId) return;
        const draftKey = "kiosco-producto-edit-" + currentEditId;

        const fd = new FormData(editForm);
        const plain = {};
        fd.forEach((val, key) => {
          if (key === "id") return;
          const field = editForm.elements[key];
          if (field && field.type === "checkbox") plain[key] = field.checked ? 1 : 0;
          else plain[key] = val;
        });

        localStorage.setItem(draftKey, JSON.stringify(plain));
      });

      editForm.addEventListener("submit", () => {
        if (!currentEditId) return;
        localStorage.removeItem("kiosco-producto-edit-" + currentEditId);
      });
    }
  });

  // ============================================
  // Nombre de archivo en input de imagen
  // ============================================
  onReady(() => {
    const fileInput = document.getElementById("imagen");
    const fileName = document.getElementById("fileName");
    if (!fileInput || !fileName) return;

    fileInput.addEventListener("change", () => {
      fileName.textContent =
        fileInput.files && fileInput.files.length > 0
          ? fileInput.files[0].name
          : "Ningún archivo seleccionado";
    });
  });

  // ============================================
  // TOGGLE FORMULARIO NUEVO PRODUCTO
  // ============================================
  onReady(() => {
    const block = document.getElementById("productFormBlock");
    const btn = document.getElementById("toggleFormBtn");
    const title = document.getElementById("formTitle");

    if (!block || !btn) return;

    function setState(open) {
      block.classList.toggle("is-collapsed", !open);

      if (open) btn.textContent = "Ocultar formulario";
      else {
        btn.textContent = "Agregar producto";
        if (title) title.textContent = "Nuevo producto";
      }
    }

    const isCollapsedInitial = block.classList.contains("is-collapsed");
    setState(!isCollapsedInitial);

    btn.addEventListener("click", () => {
      const isCollapsed = block.classList.contains("is-collapsed");
      setState(isCollapsed);
    });
  });
})();

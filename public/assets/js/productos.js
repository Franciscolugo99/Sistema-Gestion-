// public/assets/js/productos.js
(() => {
  /* ==========================
     HELPERS
  =========================== */
  const qs  = (sel, el = document) => el.querySelector(sel);
  const qsa = (sel, el = document) => Array.from(el.querySelectorAll(sel));

  const state = (window.__kioscoProductosState ||= {
    inited: false,
    toggleFormBound: false,
    toggleModalBound: false,
  });

  const toNum = (v, fallback = 0) => {
    const n = Number(v);
    return Number.isFinite(n) ? n : fallback;
  };

  const setStockInputsByPesable = (form, isPesable) => {
    const stock = form?.elements?.["stock"];
    const min   = form?.elements?.["stock_minimo"];
    if (stock) stock.step = isPesable ? "0.001" : "1";
    if (min)   min.step   = isPesable ? "0.001" : "1";
  };

  const formatStock = (value, isPesable) => {
    const n = toNum(value, 0);
    return isPesable ? n.toFixed(3) : String(Math.round(n));
  };

  /* ==========================
   TOGGLE FORM (ROBUSTO / CAPTURE)
=========================== */
function syncToggleLabel() {
  const btn = document.getElementById("toggleFormBtn");
  const block = document.getElementById("productFormBlock");
  if (!btn || !block) return;

  const collapsed = block.classList.contains("is-collapsed");
  btn.textContent = collapsed ? "Agregar producto" : "Cerrar formulario";
}

function toggleFormBlock() {
  const block = document.getElementById("productFormBlock");
  if (!block) return;

  block.classList.toggle("is-collapsed");
  syncToggleLabel();

  if (!block.classList.contains("is-collapsed")) {
    block.scrollIntoView({ behavior: "smooth", block: "start" });
  }
}

(function bindToggleOnceCapture() {
  // usa state del módulo, sin globales genéricos
  if (state.toggleFormBound) {
    // por si ya estaba bindeado
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", syncToggleLabel);
    } else {
      syncToggleLabel();
    }
    return;
  }
  state.toggleFormBound = true;

  // CAPTURE: se ejecuta aunque otro script frene el click en bubble
  document.addEventListener(
    "click",
    (e) => {
      const btn = e.target.closest("#toggleFormBtn");
      if (!btn) return;

      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();

      toggleFormBlock();
    },
    true
  );

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", syncToggleLabel);
  } else {
    syncToggleLabel();
  }
})();

  /* ==========================
     PANEL LATERAL – EDICIÓN
  =========================== */
  let lastEditRequestId = 0;

  function fillEditForm(data) {
    const form = document.getElementById("editForm");
    if (!form || !data || !data.id) return false;

    const setVal = (name, value) => {
      const el = form.elements[name];
      if (!el) return;
      el.value = value != null ? value : "";
    };

    setVal("id", data.id);
    setVal("codigo", data.codigo);
    setVal("nombre", data.nombre);
    setVal("categoria", data.categoria);
    setVal("marca", data.marca);
    setVal("proveedor", data.proveedor);
    setVal("precio", data.precio);
    setVal("costo", data.costo);

    if (form.elements["iva"]) {
      form.elements["iva"].value = data.iva != null ? String(data.iva) : "";
    }

    if (form.elements["unidad_venta"]) {
      form.elements["unidad_venta"].value = data.unidad_venta || "UNIDAD";
    }

    const isPes = !!toNum(data.es_pesable, 0);
    setStockInputsByPesable(form, isPes);

    if (form.elements["stock"]) {
      form.elements["stock"].value = formatStock(data.stock, isPes);
    }
    if (form.elements["stock_minimo"]) {
      form.elements["stock_minimo"].value = formatStock(data.stock_minimo, isPes);
    }

    if (form.elements["es_pesable"]) form.elements["es_pesable"].checked = isPes;
    if (form.elements["activo"])     form.elements["activo"].checked = toNum(data.activo, 0) === 1;

    const file = form.querySelector('input[type="file"][name="imagen"]');
    if (file) file.value = "";

    return true;
  }

  function openEditPanel(id) {
    const overlay = document.getElementById("editOverlay");
    const root = document.querySelector(".page-wrap") || document.querySelector(".root");

    overlay?.classList.add("open");
    root?.classList.add("blurred");

    const reqId = ++lastEditRequestId;

    fetch(`productos.php?editar=${encodeURIComponent(id)}&ajax=1`, {
      cache: "no-store",
      credentials: "same-origin",
      headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" },
    })
      .then((r) => (r.ok ? r.json() : Promise.reject(new Error("No se pudo cargar el producto"))))
      .then((data) => {
        if (reqId !== lastEditRequestId) return;

        const ok = fillEditForm(data);
        if (!ok) {
          closeEditPanel();
          alert("Producto no encontrado o no se pudo cargar.");
          return;
        }

        const form = document.getElementById("editForm");
        (form?.elements?.["codigo"] || form?.querySelector("input,select,textarea"))?.focus?.();
      })
      .catch((err) => {
        if (reqId !== lastEditRequestId) return;
        console.error(err);
        closeEditPanel();
        alert("No se pudo cargar el producto para editar.");
      });
  }

  function closeEditPanel() {
    const overlay = document.getElementById("editOverlay");
    const root = document.querySelector(".page-wrap") || document.querySelector(".root");
    overlay?.classList.remove("open");
    root?.classList.remove("blurred");
    lastEditRequestId++;
  }

  window.openEditPanel = openEditPanel;
  window.closeEditPanel = closeEditPanel;

  /* ==========================
     MODAL ACTIVAR/DESACTIVAR (DELEGADO)
  =========================== */
  function bindToggleModalOnce() {
    if (state.toggleModalBound) return;
    state.toggleModalBound = true;

    const confirmOverlay = document.getElementById("confirmToggle");
    const confirmTitle = document.getElementById("confirmTitle");
    const confirmText = document.getElementById("confirmText");
    const confirmCancel = document.getElementById("confirmCancel");
    const confirmAccept = document.getElementById("confirmAccept");

    let pendingHref = null;

    const closeConfirm = () => {
      confirmOverlay?.classList.remove("open");
      pendingHref = null;
    };

    confirmOverlay?.addEventListener("click", (e) => {
      if (e.target === confirmOverlay) closeConfirm();
    });

    confirmCancel?.addEventListener("click", (e) => {
      e.preventDefault();
      closeConfirm();
    });

    confirmAccept?.addEventListener("click", (e) => {
      e.preventDefault();
      if (pendingHref) window.location.href = pendingHref;
    });

    document.addEventListener("click", (e) => {
      const link = e.target.closest(".js-product-toggle");
      if (!link) return;

      e.preventDefault();
      pendingHref = link.getAttribute("href");

      const action = (link.dataset.action || "").toLowerCase();

      if (!confirmOverlay || !confirmTitle || !confirmText) {
        if (pendingHref) window.location.href = pendingHref;
        return;
      }

      if (action === "desactivar") {
        confirmTitle.textContent = "Desactivar producto";
        confirmText.textContent =
          "¿Desactivar este producto? No aparecerá en Caja ni en búsquedas de ventas.";
      } else {
        confirmTitle.textContent = "Activar producto";
        confirmText.textContent =
          "¿Activar este producto? Volverá a estar disponible para ventas.";
      }

      confirmOverlay.classList.add("open");
    });
  }

  /* ==========================
     INIT
  =========================== */
  function init() {
    if (state.inited) return;
    state.inited = true;

    bindToggleFormOnce();
    bindToggleModalOnce();

    // Nombre archivo (form principal)
    const fileInput = document.getElementById("imagen");
    const fileNameSpan = document.getElementById("fileName");

    if (fileInput && fileNameSpan) {
      fileInput.addEventListener("change", () => {
        fileNameSpan.textContent =
          fileInput.files && fileInput.files.length > 0
            ? fileInput.files[0].name
            : "Ningún archivo seleccionado";
      });
    }

    // Overlay: nombre archivo + pesable step
    const editForm = document.getElementById("editForm");
    if (editForm) {
      const editFile = editForm.querySelector('input[type="file"][name="imagen"]');

      let editFileName = editForm.querySelector(".edit-file-name");
      if (!editFileName && editFile) {
        editFileName = document.createElement("div");
        editFileName.className = "edit-file-name";
        editFileName.textContent = "Ningún archivo seleccionado";
        editFile.insertAdjacentElement("afterend", editFileName);
      }

      if (editFile && editFileName) {
        editFile.addEventListener("change", () => {
          editFileName.textContent =
            editFile.files && editFile.files.length > 0
              ? editFile.files[0].name
              : "Ningún archivo seleccionado";
        });
      }

      const chkPes = editForm.elements["es_pesable"];
      if (chkPes) {
        chkPes.addEventListener("change", () => {
          const isPes = !!chkPes.checked;
          setStockInputsByPesable(editForm, isPes);

          const s = editForm.elements["stock"];
          const m = editForm.elements["stock_minimo"];
          if (s && String(s.value).trim() !== "") s.value = formatStock(s.value, isPes);
          if (m && String(m.value).trim() !== "") m.value = formatStock(m.value, isPes);
        });
      }
    }

    // Ordenar por columnas (ASC/DESC)
    const table = document.querySelector(".productos-table");
    const filtersForm = document.querySelector("form.filters");

    if (table && filtersForm) {
      const inputSort = qs('input[name="sort"]', filtersForm);
      const inputDir  = qs('input[name="dir"]',  filtersForm);
      const inputPage = qs('input[name="page"]', filtersForm);

      table.querySelectorAll("thead th[data-sort]").forEach((th) => {
        th.addEventListener("click", () => {
          const sortField = th.dataset.sort;
          if (!sortField || !inputSort || !inputDir) return;

          const currentSort = (table.dataset.sort || "").trim();
          const currentDir  = (table.dataset.dir || "ASC").toUpperCase().trim();

          let newDir = "ASC";
          if (sortField === currentSort) newDir = currentDir === "ASC" ? "DESC" : "ASC";

          inputSort.value = sortField;
          inputDir.value  = newDir;
          if (inputPage) inputPage.value = "1";

          filtersForm.submit();
        });
      });
    }

    // ESC closes + click afuera (edit)
    document.addEventListener("keydown", (e) => {
      if (e.key !== "Escape") return;

      const confirmOverlay = document.getElementById("confirmToggle");
      if (confirmOverlay?.classList.contains("open")) confirmOverlay.classList.remove("open");

      const editOverlay = document.getElementById("editOverlay");
      if (editOverlay?.classList.contains("open")) closeEditPanel();
    });

    const editOverlay = document.getElementById("editOverlay");
    editOverlay?.addEventListener("click", (e) => {
      if (e.target === editOverlay) closeEditPanel();
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();

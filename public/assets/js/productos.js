// public/assets/js/productos.js
(() => {
  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  /* =========================
     TOAST (fallback)
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
   FORM PLEGABLE (Agregar producto) ✅ FIX DEFINITIVO
========================= */
  (() => {
    const init = () => {
      const toggleBtn = document.getElementById("toggleFormBtn");
      const formBlock = document.getElementById("productFormBlock");
      if (!toggleBtn || !formBlock) return;

      // Asegurar render (por si algún CSS viejo lo escondía)
      formBlock.style.display = "block";

      const syncBtn = () => {
        const open = !formBlock.classList.contains("is-collapsed");
        toggleBtn.setAttribute("aria-expanded", open ? "true" : "false");
        toggleBtn.textContent = open
          ? "OCULTAR FORMULARIO"
          : "AGREGAR PRODUCTO";
      };

      const openForm = () => {
        formBlock.classList.remove("is-collapsed");
        formBlock.style.display = "block";

        // Estado inicial para animar
        formBlock.style.maxHeight = "0px";
        formBlock.style.opacity = "0";
        formBlock.style.transform = "translateY(-6px)";

        // forzar reflow
        formBlock.offsetHeight;

        const h = Math.max(formBlock.scrollHeight || 0, 1);
        formBlock.style.maxHeight = h + "px";
        formBlock.style.opacity = "1";
        formBlock.style.transform = "translateY(0)";

        const done = (e) => {
          if (e && e.target !== formBlock) return;
          formBlock.style.maxHeight = "none";
          formBlock.removeEventListener("transitionend", done);
        };
        formBlock.addEventListener("transitionend", done);
        setTimeout(() => done(), 550); // fallback

        syncBtn();
        formBlock.scrollIntoView({ behavior: "smooth", block: "start" });
      };

      const closeForm = () => {
        const h = Math.max(formBlock.scrollHeight || 0, 1);
        formBlock.style.maxHeight = h + "px";

        // forzar reflow
        formBlock.offsetHeight;

        formBlock.style.maxHeight = "0px";
        formBlock.style.opacity = "0";
        formBlock.style.transform = "translateY(-6px)";

        const done = (e) => {
          if (e && e.target !== formBlock) return;
          formBlock.classList.add("is-collapsed");
          formBlock.removeEventListener("transitionend", done);
          syncBtn();
        };
        formBlock.addEventListener("transitionend", done);
        setTimeout(() => done(), 550); // fallback
      };

      syncBtn();

      // Capture + stopImmediatePropagation: evita que otro JS te lo pise
      toggleBtn.addEventListener(
        "click",
        (e) => {
          e.preventDefault();
          e.stopImmediatePropagation();

          const collapsed = formBlock.classList.contains("is-collapsed");
          collapsed ? openForm() : closeForm();
        },
        true
      );
    };

    // funciona si el script cargó tarde o temprano
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", init);
    } else {
      init();
    }
  })();

  /* =========================
     SORT TH CLICK
  ========================= */
  const table = $(".productos-table");
  const filtersForm = $(".filters");
  if (table && filtersForm) {
    $$(".productos-table thead th[data-sort]").forEach((th) => {
      th.addEventListener("click", () => {
        const col = th.getAttribute("data-sort");
        const sortInp = $('input[name="sort"]', filtersForm);
        const dirInp = $('input[name="dir"]', filtersForm);

        const currentSort = sortInp?.value || "nombre";
        const currentDir = (dirInp?.value || "ASC").toLowerCase();

        let nextDir = "ASC";
        if (currentSort === col)
          nextDir = currentDir === "asc" ? "DESC" : "ASC";

        if (sortInp) sortInp.value = col;
        if (dirInp) dirInp.value = nextDir;

        const pageInp = $('input[name="page"]', filtersForm);
        if (pageInp) pageInp.value = "1";

        filtersForm.submit();
      });
    });
  }

  /* =========================
     EDIT PANEL (overlay lateral)
  ========================= */
  const overlay = $("#editOverlay");
  const panel = $("#editPanel");
  const editForm = $("#editForm");
  const pageWrap = $(".page-wrap");

  function setBlur(on) {
    if (pageWrap) pageWrap.classList.toggle("blurred", !!on);
    // NO tocar .root porque contiene overlays
  }

  function openOverlay() {
    if (!overlay) return;
    overlay.classList.add("open");
    overlay.classList.add("active"); // compat
    setBlur(true);
  }

  function closeOverlay() {
    if (!overlay) return;
    overlay.classList.remove("open");
    overlay.classList.remove("active");
    setBlur(false);
  }

  if (overlay && panel) {
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
      const res = await fetch(
        `productos.php?editar=${encodeURIComponent(id)}&ajax=1`,
        {
          headers: { Accept: "application/json" },
          cache: "no-store",
        }
      );

      const data = await res.json();

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

      openOverlay();
    } catch (err) {
      console.error(err);
      window.showToast("No se pudo cargar el producto para editar.", "error");
    }
  };

  window.closeEditPanel = closeOverlay;

  /* =========================
     CONFIRM MODAL (activar/desactivar)
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
    if (confirmTitle)
      confirmTitle.textContent = isDesactivar
        ? "Desactivar producto"
        : "Activar producto";
    if (confirmText)
      confirmText.textContent = isDesactivar
        ? "¿Desactivar producto? No aparecerá en Caja ni en búsquedas de ventas."
        : "¿Activar producto? Volverá a aparecer en Caja y búsquedas.";

    if (!confirmOv) return;
    confirmOv.classList.add("open");
    confirmOv.classList.add("active");
    setBlur(true);
  }

  function closeConfirm() {
    if (!confirmOv) return;
    confirmOv.classList.remove("open");
    confirmOv.classList.remove("active");
    setBlur(false);
    pendingHref = null;
  }

  if (btnCancel) btnCancel.addEventListener("click", closeConfirm);

  if (confirmOv) {
    confirmOv.addEventListener("click", (e) => {
      if (e.target === confirmOv) closeConfirm();
    });
  }

  if (btnAccept) {
    btnAccept.addEventListener("click", () => {
      if (pendingHref) window.location.href = pendingHref;
    });
  }

  $$(".js-product-toggle").forEach((a) => {
    a.addEventListener("click", (e) => {
      e.preventDefault();
      const action = a.dataset.action || "";
      const href = a.getAttribute("href");
      if (!href) return;
      openConfirm({ action, href });
    });
  });

  window.closeConfirm = closeConfirm;
})();

// VARIABLES GLOBALES
// ============================================

// ID del producto que se está editando en el panel lateral
let currentEditId = null;

// ============================================
// TEMA OSCURO / CLARO
// ============================================

document.addEventListener("DOMContentLoaded", () => {
  const body = document.body;
  const toggle = document.getElementById("toggleTheme");

  if (!toggle) return;

  // Leer preferencia guardada o del sistema
  const saved = localStorage.getItem("kiosco-theme");
  const prefersDark =
    window.matchMedia &&
    window.matchMedia("(prefers-color-scheme: dark)").matches;

  const initialTheme = saved || (prefersDark ? "dark" : "light");

  body.setAttribute("data-theme", initialTheme);
  toggle.checked = initialTheme === "light"; // checked = modo claro

  // Cambiar tema
  toggle.addEventListener("change", () => {
    const newTheme = toggle.checked ? "light" : "dark";
    body.setAttribute("data-theme", newTheme);
    localStorage.setItem("kiosco-theme", newTheme);
  });
});

// ============================================
// PANEL LATERAL EDICIÓN + BLUR
// ============================================


/* ==========================
   RELLENAR FORM EDIT
========================== */
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

  // precio / costo tal cual
  set("precio", data.precio);
  set("costo", data.costo);

  // 🔹 STOCK y STOCK MÍNIMO con formato según pesable / unidad
  const isPes = !!Number(data.es_pesable);

  if (form.elements["stock"]) {
    const stockVal = Number(data.stock ?? 0);
    form.elements["stock"].value = isPes
      ? stockVal.toFixed(3)   // pesable: 3 decimales (1.250 → "1.250")
      : stockVal.toFixed(0);  // unidad: sin decimales (40 → "40")
  }

  if (form.elements["stock_minimo"]) {
    const stockMinVal = Number(data.stock_minimo ?? 0);
    form.elements["stock_minimo"].value = isPes
      ? stockMinVal.toFixed(3)
      : stockMinVal.toFixed(0);
  }

  // IVA
  if (form.elements["iva"]) {
    const iva = data.iva != null ? String(data.iva) : "";
    form.elements["iva"].value = iva;
  }

  // Unidad de venta
  if (form.elements["unidad_venta"]) {
    form.elements["unidad_venta"].value = data.unidad_venta || "UNIDAD";
  }

  // Pesable
  if (form.elements["es_pesable"]) {
    form.elements["es_pesable"].checked = !!Number(data.es_pesable);
  }

  // Activo
  if (form.elements["activo"]) {
    form.elements["activo"].checked = data.activo == 1;
  }
}

/* ==========================
   ABRIR / CERRAR PANEL
========================== */
function openEditPanel(id) {
  const overlay = document.getElementById("editOverlay");
  const root = document.querySelector(".root");
  const draftKeyBase = "kiosco-producto-edit-";

  if (overlay) overlay.classList.add("open");
  if (root) root.classList.add("blurred");

  fetch("productos.php?editar=" + encodeURIComponent(id) + "&ajax=1", {
    cache: "no-store",
  })
    .then((r) => (r.ok ? r.json() : null))
    .then((data) => {
      if (!data) return;

      currentEditId = data.id || id;
      const draftKey = draftKeyBase + currentEditId;

      // mezclar borrador (si existe)
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
    .catch((err) => {
      console.error("Error cargando producto", err);
    });
}

function closeEditPanel() {
  const overlay = document.getElementById("editOverlay");
  const root = document.querySelector(".root");
  if (overlay) overlay.classList.remove("open");
  if (root) root.classList.remove("blurred");
  currentEditId = null;
}

// Exponer para el HTML
window.openEditPanel = openEditPanel;
window.closeEditPanel = closeEditPanel;

// Cerrar al hacer click en el fondo (no dentro del panel)
document.addEventListener("DOMContentLoaded", () => {
  const overlay = document.getElementById("editOverlay");
  if (!overlay) return;

  overlay.addEventListener("click", (e) => {
    if (e.target === overlay) {
      closeEditPanel();
    }
  });
});

// ============================================
// TOAST GLOBAL
// ============================================

function showToast(msg) {
  const t = document.getElementById("toast");
  if (!t) return;

  t.textContent = msg;
  t.classList.add("show");

  setTimeout(() => {
    t.classList.remove("show");
  }, 2800);
}

// ============================================
// BÚSQUEDA INSTANTÁNEA + ORDEN – PRODUCTOS
// ============================================

document.addEventListener("DOMContentLoaded", () => {
  const searchInput = document.querySelector("input[name='q']");
  const table = document.querySelector(".productos-table");
  const tableBody = document.querySelector(".productos-table tbody");
  const estadoSel = document.querySelector("select[name='estado']");
  const sortHeaders = document.querySelectorAll(
    ".productos-table thead th[data-sort]"
  );

  // Si no estamos en productos.php, salimos
  if (!searchInput || !table || !tableBody || !estadoSel) return;

  let timer = null;
  let currentSortField = table.dataset.sort || "nombre";
  let currentSortDir = (table.dataset.dir || "ASC").toUpperCase();

  function formatStock(p) {
    const raw = Number(p.stock ?? 0);
    if (Number.isNaN(raw)) return p.stock ?? "0";

    const esPesable = Number(p.es_pesable ?? 0) === 1;

    if (esPesable) {
      // pesables: 3 decimales
      return raw.toFixed(3).replace(".", ",");
    } else {
      // por unidad: sin decimales
      return raw.toLocaleString("es-AR", {
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
      });
    }
  }
  function renderTable(data) {
    if (!data.length) {
      tableBody.innerHTML = `
      <tr><td colspan="11" class="empty-cell">
        No se encontraron productos.
      </td></tr>`;
      return;
    }

    tableBody.innerHTML = data
      .map((p) => {
        let tag = "";
        if (!Number(p.activo))
          tag = `<span class="tag tag-inactivo">Inactivo</span>`;
        else if (Number(p.stock) <= 0)
          tag = `<span class="tag tag-sin">Sin stock</span>`;
        else if (Number(p.stock) <= Number(p.stock_minimo))
          tag = `<span class="tag tag-bajo">Stock bajo</span>`;
        else tag = `<span class="tag tag-ok">OK</span>`;

        const precio = Number(p.precio ?? 0)
          .toFixed(2)
          .replace(".", ",");

        const thumb = p.imagen
          ? `<img src="img/productos/${p.imagen}" alt="img" class="prod-thumb">`
          : `<span class="prod-thumb-placeholder">—</span>`;

        return `
      <tr>
        <td class="center">${thumb}</td>
        <td>${p.codigo ?? ""}</td>
        <td>${p.nombre ?? ""}</td>
        <td>${p.categoria ?? ""}</td>
        <td>${p.marca ?? ""}</td>
        <td>${p.proveedor ?? ""}</td>
        <td>${p.iva !== null && p.iva !== undefined ? p.iva + "%" : ""}</td>
        <td class="right">$${precio}</td>
        <td class="right">${formatStock(p)}</td>
        <td class="center">${tag}</td>
        <td class="center">
          <a href="#"
             class="btn-line btn-edit"
             onclick="openEditPanel(${p.id}); return false;">
            Editar
          </a>
          ${
            Number(p.activo)
              ? `<a class="btn-line btn-toggle js-product-toggle"
                  href="productos.php?eliminar=${p.id}"
                  data-action="desactivar">
                 Desactivar
               </a>`
              : `<a class="btn-line btn-toggle js-product-toggle"
                  href="productos.php?activar=${p.id}"
                  data-action="activar">
                 Activar
               </a>`
          }
        </td>
      </tr>
    `;
      })
      .join("");
  }

  function doSearch() {
    const q = searchInput.value;
    const estado = estadoSel.value;

    const url =
      `productos.php?ajaxList=1` +
      `&q=${encodeURIComponent(q)}` +
      `&estado=${encodeURIComponent(estado)}` +
      `&sort=${encodeURIComponent(currentSortField)}` +
      `&dir=${encodeURIComponent(currentSortDir)}`;

    fetch(url)
      .then((r) => r.json())
      .then(renderTable);
  }

  // Debounce para la búsqueda
  searchInput.addEventListener("input", () => {
    clearTimeout(timer);
    timer = setTimeout(doSearch, 500);
  });

  estadoSel.addEventListener("change", doSearch);

  // Click en cabeceras para ordenar
  sortHeaders.forEach((th) => {
    th.addEventListener("click", () => {
      const field = th.dataset.sort;
      if (!field) return;

      if (currentSortField === field) {
        currentSortDir = currentSortDir === "ASC" ? "DESC" : "ASC";
      } else {
        currentSortField = field;
        currentSortDir = "ASC";
      }

      // Visual: resaltar qué columna está ordenando
      sortHeaders.forEach((h) =>
        h.classList.remove("sorted-asc", "sorted-desc")
      );
      th.classList.add(currentSortDir === "ASC" ? "sorted-asc" : "sorted-desc");

      doSearch();
    });
  });
});
// ============================================
// MODAL CONFIRMACIÓN ACTIVAR / DESACTIVAR
// ============================================
document.addEventListener("DOMContentLoaded", () => {
  const overlay = document.getElementById("confirmToggle");
  const titleEl = document.getElementById("confirmTitle");
  const textEl = document.getElementById("confirmText");
  const btnOk = document.getElementById("confirmAccept");
  const btnCancel = document.getElementById("confirmCancel");

  if (!overlay || !btnOk || !btnCancel) return;

  let pendingUrl = null;

  // Captura clic en los links de activar / desactivar
  document.addEventListener("click", (e) => {
    const link = e.target.closest(".js-product-toggle");
    if (!link) return;

    e.preventDefault();

    pendingUrl = link.href;
    const action = (link.dataset.action || "").toLowerCase();

    if (action === "activar") {
      titleEl.textContent = "Activar producto";
      textEl.textContent =
        "¿Querés activar este producto? Volverá a aparecer en Caja y en búsquedas de ventas.";
    } else {
      titleEl.textContent = "Desactivar producto";
      textEl.textContent =
        "¿Desactivar este producto? No aparecerá en Caja ni en búsquedas de ventas.";
    }

    overlay.classList.add("open");
  });

  // Cancelar
  btnCancel.addEventListener("click", () => {
    overlay.classList.remove("open");
    pendingUrl = null;
  });

  // Click sobre fondo para cerrar
  overlay.addEventListener("click", (e) => {
    if (e.target === overlay) {
      overlay.classList.remove("open");
      pendingUrl = null;
    }
  });

  // Confirmar
  btnOk.addEventListener("click", () => {
    if (pendingUrl) {
      window.location.href = pendingUrl;
    }
  });
});

// ============================================
// AUTO-GUARDADO FORMULARIOS PRODUCTOS
// ============================================

document.addEventListener("DOMContentLoaded", () => {
  // ---- Form principal (nuevo producto) ----
  const mainForm = document.querySelector(".productos-form");
  const mainDraftKey = "kiosco-producto-draft";

  if (mainForm) {
    // Si venimos de guardar correctamente, limpio el borrador
    const params = new URLSearchParams(window.location.search);
    if (params.has("saved")) {
      localStorage.removeItem(mainDraftKey);
    }

    const isEditingMain = !!mainForm.querySelector("input[name='id']");

    // Cargar borrador solo cuando es "Nuevo producto"
    if (!isEditingMain) {
      const saved = localStorage.getItem(mainDraftKey);
      if (saved) {
        try {
          const data = JSON.parse(saved);
          for (const k in data) {
            const field = mainForm.elements[k];
            if (!field) continue;

            if (field.type === "checkbox") {
              field.checked = !!data[k];
            } else {
              field.value = data[k];
            }
          }
        } catch (e) {}
      }
    }

    // Guardar borrador al tipear
    mainForm.addEventListener("input", () => {
      const fd = new FormData(mainForm);
      const plain = {};
      fd.forEach((val, key) => {
        if (key === "id") return;
        const field = mainForm.elements[key];
        if (field && field.type === "checkbox") {
          plain[key] = field.checked ? 1 : 0;
        } else {
          plain[key] = val;
        }
      });
      localStorage.setItem(mainDraftKey, JSON.stringify(plain));
    });
  }

  // ---- Panel lateral de edición ----
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
        if (field && field.type === "checkbox") {
          plain[key] = field.checked ? 1 : 0;
        } else {
          plain[key] = val;
        }
      });

      localStorage.setItem(draftKey, JSON.stringify(plain));
    });

    // Al enviar, borramos el borrador de ese producto
    editForm.addEventListener("submit", () => {
      if (currentEditId) {
        const draftKey = "kiosco-producto-edit-" + currentEditId;
        localStorage.removeItem(draftKey);
      }
    });
  }
});
document.addEventListener("DOMContentLoaded", () => {
  const fileInput = document.getElementById("imagen");
  const fileName = document.getElementById("fileName");

  if (!fileInput || !fileName) return;

  fileInput.addEventListener("change", () => {
    if (fileInput.files && fileInput.files.length > 0) {
      fileName.textContent = fileInput.files[0].name;
    } else {
      fileName.textContent = "Ningún archivo seleccionado";
    }
  });
});
// ============================================
// TOGGLE FORMULARIO NUEVO PRODUCTO
// ============================================
document.addEventListener("DOMContentLoaded", () => {
  const block = document.getElementById("productFormBlock");
  const btn = document.getElementById("toggleFormBtn");
  const title = document.getElementById("formTitle");

  if (!block || !btn || !title) return;

  btn.addEventListener("click", () => {
    const collapsed = block.classList.toggle("is-collapsed");

    if (collapsed) {
      btn.textContent = "Nuevo producto";
      title.textContent = "Nuevo producto";
    } else {
      btn.textContent = "Ocultar formulario";
      // si no estás editando, el título queda en "Nuevo producto"
      // si hay edición, PHP ya habrá puesto "Editar producto"
    }
  });
});
// ============================================
// TOGGLE FORMULARIO NUEVO PRODUCTO
// ============================================
document.addEventListener("DOMContentLoaded", () => {
  const block = document.getElementById("productFormBlock");
  const btn = document.getElementById("toggleFormBtn");
  const title = document.getElementById("formTitle");

  if (!block || !btn) return;

  // función que abre/cierra y actualiza textos
  function setState(open) {
    // open = true  -> formulario visible
    block.classList.toggle("is-collapsed", !open);

    if (open) {
      btn.textContent = "Ocultar formulario";
    } else {
      btn.textContent = " Agregar producto";
      if (title) {
        title.textContent = "Nuevo producto";
      }
    }
  }

  // estado inicial: lo que vino de PHP (.is-collapsed o no)
  const isCollapsedInitial = block.classList.contains("is-collapsed");
  setState(!isCollapsedInitial);

  // click en el botón
  btn.addEventListener("click", () => {
    const isCollapsed = block.classList.contains("is-collapsed");
    setState(isCollapsed); // si estaba colapsado, abrimos; si no, cerramos
  });
});
 // dropdown de ajustes 
document.addEventListener("click", (e) => {
  const menu = document.getElementById("adminMenu");
  if (!menu) return;

  const btn = menu.querySelector(".nav-menu-btn");
  const isBtn = btn && (btn === e.target || btn.contains(e.target));

  if (isBtn) {
    const open = menu.classList.toggle("open");
    btn.setAttribute("aria-expanded", open ? "true" : "false");
    return;
  }

  if (!menu.contains(e.target)) {
    menu.classList.remove("open");
    if (btn) btn.setAttribute("aria-expanded", "false");
  }
});

document.addEventListener("keydown", (e) => {
  if (e.key !== "Escape") return;
  const menu = document.getElementById("adminMenu");
  if (!menu) return;
  const btn = menu.querySelector(".nav-menu-btn");
  menu.classList.remove("open");
  if (btn) btn.setAttribute("aria-expanded", "false");
});

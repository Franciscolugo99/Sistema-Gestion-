// public/assets/js/caja.js
document.addEventListener("DOMContentLoaded", () => {
  const API_BASE = "api/index.php";
  const API_VENTA = "api/index.php";
  const API_TIMEOUT_MS = 8000;

  // =========================================================================
  // ✅ AUTOCOMPLETADO VISUAL (dropdown con sugerencias)
  // =========================================================================
  (function initAutocompletadoProductos() {
    const input = document.getElementById("codigo");
    if (!input) return;

    // Crear dropdown de sugerencias
    let dropdown = document.getElementById("sugerencias-dropdown");
    if (!dropdown) {
      dropdown = document.createElement("div");
      dropdown.id = "sugerencias-dropdown";
      dropdown.className = "autocomplete-dropdown";

      // ✅ NO estilos visuales inline (los maneja caja.css)
      dropdown.style.position = "absolute";
      dropdown.style.display = "none";
      dropdown.style.zIndex = "99999";

      input.parentElement.style.position = "relative";
      input.parentElement.appendChild(dropdown);
    }

    // Remover datalist viejo si existe
    const oldDatalist = document.getElementById("sugerencias");
    if (oldDatalist) oldDatalist.remove();
    input.removeAttribute("list");

    const esc = (s) =>
      String(s ?? "").replace(
        /[&<>"']/g,
        (c) =>
          ({
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#039;",
          })[c],
      );

    let productos = [];
    let selectedIndex = -1;
    let abort = null;

    function posicionarDropdown() {
      const rect = input.getBoundingClientRect();
      const parentRect = input.parentElement.getBoundingClientRect();
      dropdown.style.top = rect.bottom - parentRect.top + 4 + "px";
      dropdown.style.left = rect.left - parentRect.left + "px";
      dropdown.style.width = rect.width + "px";
    }

    function renderDropdown() {
      if (productos.length === 0) {
        dropdown.style.display = "none";
        return;
      }

      posicionarDropdown();

      dropdown.innerHTML = productos
        .map(
          (p, i) => `
        <div class="autocomplete-item ${i === selectedIndex ? "selected" : ""}" data-index="${i}">
          <div class="ac-title">${esc(p.nombre)}</div>
          <div class="ac-meta">
            Código: ${esc(p.codigo)} · Stock: ${p.stock} · $${Number(p.precio).toFixed(2)}
          </div>
        </div>
      `,
        )
        .join("");

      dropdown.style.display = "block";
    }

    function actualizarSeleccionUI() {
      const items = dropdown.querySelectorAll(".autocomplete-item");
      items.forEach((el) => el.classList.remove("selected"));
      if (selectedIndex >= 0 && items[selectedIndex]) {
        items[selectedIndex].classList.add("selected");
        // opcional: asegurar que el seleccionado se vea
        items[selectedIndex].scrollIntoView({ block: "nearest" });
      }
    }

    function ocultarDropdown() {
      dropdown.style.display = "none";
      productos = [];
      selectedIndex = -1;
    }

    function seleccionarProducto(index) {
      const p = productos[index];
      if (!p) return;
      input.value = p.codigo;
      ocultarDropdown();
      // Disparar evento para que se agregue al ticket
      const btnAgregar = document.getElementById("btnAgregar");
      if (btnAgregar) btnAgregar.click();
    }

    async function buscarProductos(query) {
      query = (query || "").trim();
      if (query.length < 2) {
        ocultarDropdown();
        return;
      }

      if (abort) abort.abort();
      abort = new AbortController();

      try {
        const res = await fetch(
          `${API_BASE}?action=buscar_productos&q=${encodeURIComponent(query)}&limit=8`,
          { signal: abort.signal, credentials: "same-origin" },
        );
        const data = await res.json();

        if (data.ok && Array.isArray(data.productos)) {
          productos = data.productos;
          selectedIndex = -1;
          renderDropdown();
        } else {
          ocultarDropdown();
        }
      } catch (err) {
        if (err?.name !== "AbortError") {
          console.warn("Error buscando productos:", err);
          ocultarDropdown();
        }
      }
    }

    // Heurística: si parece código de barras (lector) evitamos autocompletar por API
    const esBarcode = (q) => /^\d{6,}$/.test(String(q || "").trim());
    const tieneLetras = (q) => /[a-záéíóúñü]/i.test(String(q || ""));

    // Debounce
    let debounceTimer = null;
    function debouncedBuscar(query) {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => buscarProductos(query), 150);
    }

    // Eventos del input
    input.addEventListener("input", () => {
      const v = (input.value || "").trim();

      // ✅ FIX lector: cancelar debounce y cualquier fetch en vuelo, y evitar lista vieja
      clearTimeout(debounceTimer);
      if (abort) {
        try {
          abort.abort();
        } catch (_) {}
        abort = null;
      }
      ocultarDropdown();

      // Si viene corto, no sugerimos
      if (v.length < 2) return;

      // Si parece código de barras (lector), NO autocompletamos
      if (esBarcode(v)) return;

      debouncedBuscar(v);
    });

    input.addEventListener("keydown", (e) => {
      if (dropdown.style.display === "none" || productos.length === 0) return;

      if (e.key === "ArrowDown") {
        e.preventDefault();
        selectedIndex = Math.min(selectedIndex + 1, productos.length - 1);
        actualizarSeleccionUI();
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        selectedIndex = Math.max(selectedIndex - 1, 0);
        actualizarSeleccionUI();
      } else if (e.key === "Enter") {
        const v = (input.value || "").trim();

        // ✅ FIX lector: si parece barcode, NO dejamos que el dropdown capture Enter
        if (esBarcode(v)) {
          ocultarDropdown();
          return;
        }

        // Si no hay selección explícita, solo autoseleccionamos si el usuario escribió texto (nombre)
        if (selectedIndex < 0) {
          if (tieneLetras(v)) selectedIndex = 0;
          else return;
        }

        e.preventDefault();
        seleccionarProducto(selectedIndex);
      } else if (e.key === "Escape") {
        ocultarDropdown();
      }
    });

    // Click en sugerencia
    dropdown.addEventListener("mousedown", (e) => {
      const item = e.target.closest(".autocomplete-item");
      if (!item) return;

      e.preventDefault(); // evita blur del input antes de seleccionar
      e.stopPropagation();

      const index = parseInt(item.dataset.index, 10);
      seleccionarProducto(index);
    });

    // Hover en sugerencias
    dropdown.addEventListener("mousemove", (e) => {
      const item = e.target.closest(".autocomplete-item");
      if (!item) return;
      const index = parseInt(item.dataset.index, 10);
      if (Number.isFinite(index) && index !== selectedIndex) {
        selectedIndex = index;
        actualizarSeleccionUI();
      }
    });

    // Ocultar al hacer click fuera
    document.addEventListener("click", (e) => {
      if (!input.contains(e.target) && !dropdown.contains(e.target)) {
        ocultarDropdown();
      }
    });

    // Ocultar al perder foco (con delay para permitir click)
    input.addEventListener("blur", () => {
      setTimeout(() => {
        if (!dropdown.matches(":hover")) {
          ocultarDropdown();
        }
      }, 150);
    });
  })();

  // Papel del ticket
  const PAPER_KEY = "kiosco-ticket-paper";
  const PRINT_MODE_KEY = "kiosco-ticket-print-mode";
  const PRINT_DEFAULTS = window.FLUS_PRINT_DEFAULTS || {};
  const PRINT_GLOBAL_DEFAULTS = PRINT_DEFAULTS.global || {};
  const PRINT_TERMINAL_DEFAULTS = PRINT_DEFAULTS.terminal || {};
  function getPaper() {
    const fallbackPaper =
      (PRINT_TERMINAL_DEFAULTS.ticket_paper &&
      PRINT_TERMINAL_DEFAULTS.ticket_paper !== "inherit"
        ? PRINT_TERMINAL_DEFAULTS.ticket_paper
        : PRINT_GLOBAL_DEFAULTS.ticket_paper) || "80";
    const v = (localStorage.getItem(PAPER_KEY) || fallbackPaper).trim();
    return v === "58" ? "58" : "80";
  }

  function getPrintModeStorageKey() {
    return `${PRINT_MODE_KEY}:${FLUS_TERMINAL_ID || 0}`;
  }

  function getPrintMode() {
    const fallbackMode =
      (PRINT_TERMINAL_DEFAULTS.ticket_mode &&
      PRINT_TERMINAL_DEFAULTS.ticket_mode !== "inherit"
        ? PRINT_TERMINAL_DEFAULTS.ticket_mode
        : PRINT_GLOBAL_DEFAULTS.ticket_mode) || "autoprint";
    const scoped = (localStorage.getItem(getPrintModeStorageKey()) || "").trim();
    const legacy = (localStorage.getItem(PRINT_MODE_KEY) || "").trim();
    const value = scoped || legacy || fallbackMode;
    return ["autoprint", "preview", "none"].includes(value)
      ? value
      : "autoprint";
  }

  function setPrintMode(value) {
    const next = ["autoprint", "preview", "none"].includes(value)
      ? value
      : "autoprint";
    localStorage.setItem(getPrintModeStorageKey(), next);
    localStorage.setItem(PRINT_MODE_KEY, next);
    return next;
  }

  // =========================
  // HELPERS GLOBALES
  // =========================

  // ✅ Debounce para optimizar renders
  const debounce = (fn, ms) => {
    let timer;
    return (...args) => {
      clearTimeout(timer);
      timer = setTimeout(() => fn(...args), ms);
    };
  };

  // =========================
  // (A) BOTÓN CERRAR CAJA
  // =========================
  const btnCerrar = document.getElementById("btnCerrarCaja");
  if (btnCerrar) {
    btnCerrar.addEventListener("click", () => {
      const id = btnCerrar.dataset.cajaId;
      if (!id) return;
      window.location.href = `caja_cerrar.php?id=${encodeURIComponent(id)}`;
    });
  }

  // =========================
  // (B) APERTURA (si está el form)
  // =========================
  const formApertura = document.getElementById("formAperturaCaja");
  const inputSaldo = document.getElementById("saldo_inicial");
  const aviso = document.getElementById("aperturaAviso");

  if (formApertura && inputSaldo) {
    const MIN_SALDO_SUG = 5000;

    function parseSaldo(v) {
      const s = String(v ?? "").trim();
      const norm = s.replace(/\./g, "").replace(",", ".");
      const n = parseFloat(norm);
      return Number.isFinite(n) ? n : 0;
    }

    function actualizarAviso() {
      if (!aviso) return;
      const valor = parseSaldo(inputSaldo.value);
      if (valor > 0 && valor < MIN_SALDO_SUG) {
        aviso.textContent = `Saldo inicial bajo: $${valor.toFixed(
          2,
        )}. Revisá si es suficiente para el turno.`;
        aviso.classList.remove("hidden");
      } else {
        aviso.textContent = "";
        aviso.classList.add("hidden");
      }
    }

    inputSaldo.addEventListener("input", actualizarAviso);
    actualizarAviso();

    let _confirmandoApertura = false;
    formApertura.addEventListener("submit", async (e) => {
      // Si ya fue confirmado, dejar pasar el submit nativo
      if (_confirmandoApertura) return;
      e.preventDefault();

      const valor = parseSaldo(inputSaldo.value);
      const ok = await Notif.confirmar(
        "🏦 Abrir caja",
        `<p>Saldo inicial: <strong style="color:var(--accent-green,#22c55e)">$${valor.toFixed(2)}</strong></p>`,
        { icon: "info", confirmText: "✅ Abrir caja", cancelText: "Cancelar" },
      );
      if (ok) {
        _confirmandoApertura = true;
        // requestSubmit() respeta required/min y dispara validación HTML5
        formApertura.requestSubmit();
      }
    });
  }

  // =========================
  // (C) CAJA ABIERTA (si existe tabla)
  // =========================
  const tabla = document.getElementById("tabla");
  if (!tabla) return;

  // Caja id (si existe en el botón cerrar)
  const CAJA_ID = Number(btnCerrar?.dataset?.cajaId || 0);

  // Storage por caja/terminal.
  // Persistimos por terminal + apertura para poder recuperar un ticket si se cierra la pestaña.
  const STORAGE_PREFIX = "kiosco-caja-v3";
  const FLUS_TERMINAL_ID =
    window.TERMINAL_ID ??
    window.terminalId ??
    document.body?.dataset?.terminalId ??
    0;
  const STORAGE_SCOPE = CAJA_ID > 0 ? `caja:${CAJA_ID}` : `terminal:${FLUS_TERMINAL_ID}:sin-caja`;
  const STORAGE_KEY = `${STORAGE_PREFIX}:${STORAGE_SCOPE}`;
  const LEGACY_STORAGE_PREFIX = "kiosco-caja-v2";
  const LEGACY_SESSION_KEY = sessionStorage.getItem("kiosco-caja-session-id");
  const LEGACY_STORAGE_KEY = LEGACY_SESSION_KEY
    ? `${LEGACY_STORAGE_PREFIX}:${FLUS_TERMINAL_ID}:${LEGACY_SESSION_KEY}`
    : "";

  let promosPorProducto = {};
  let promosCombos = [];
  let carrito = [];
  let totalNetoActual = 0;
  let estadoRecuperado = false;

  // Descuento global (aplica al total final)
  // { tipo: "porcentaje"|"monto", valor: number }
  let descGlobal = null;
  const msgBox = document.getElementById("msg");
  const tbodyTicket = document.querySelector("#tabla tbody");
  const inputCodigo = document.getElementById("codigo");
  const inputCant = document.getElementById("cantidad");
  const inputPagado = document.getElementById("montoPagado");
  const lblTotal = document.getElementById("lblTotal");
  const lblVuelto = document.getElementById("lblVuelto");
  const selMedio = document.getElementById("medioPago");
  const pago1Wrap = document.getElementById("pago1Wrap");
  // Split payment (Pago con 2 medios)
  const pagosRow = document.querySelector(".pagos-row");
  const pago2Wrap = document.getElementById("pago2Wrap");
  const selMedio2 = document.getElementById("medioPago2");
  const inputPagado2 = document.getElementById("montoPagado2");
  const btnAgregarPago = document.getElementById("btnAgregarPago");
  const btnQuitarPago2 = document.getElementById("btnQuitarPago2");
  const lblTotalPagado = document.getElementById("lblTotalPagado");
  const restaWrap = document.getElementById("restaWrap");
  const lblRestaPagar = document.getElementById("lblRestaPagar");

  const lblTotalBruto = document.getElementById("lblTotalBruto");
  const lblDescGlobal = document.getElementById("lblDescGlobal");
  const btnDescGlobal = document.getElementById("btnDescGlobal");
  const kpiVentasSesion = document.getElementById("kpiVentasSesion");
  const kpiTotalSesion = document.getElementById("kpiTotalSesion");
  const kpiEfectivoSesion = document.getElementById("kpiEfectivoSesion");
  const kpiMpSesion = document.getElementById("kpiMpSesion");
  const kpiTicketActual = document.getElementById("kpiTicketActual");
  const kpiPagadoActual = document.getElementById("kpiPagadoActual");
  const ticketStatusLabel = document.getElementById("ticketStatusLabel");
  const btnCobrarExacto = document.getElementById("btnCobrarExacto");

  // ✅ Permiso frontend (inyectado por caja.php)
  const CAN_MOD_PRECIO = !!(
    window.FLUS_PERMS && window.FLUS_PERMS.caja_modificar_precio
  );

  // Si no tiene permiso, deshabilitar UI de descuento global para evitar confusión
  if (btnDescGlobal && !CAN_MOD_PRECIO) {
    btnDescGlobal.style.opacity = "0.5";
    btnDescGlobal.style.pointerEvents = "none";
    btnDescGlobal.title = "Sin permisos para descuento / cambio de precio";
  }

  // Modal
  const modal = document.getElementById("modal");
  const modalTitulo = document.getElementById("modal-titulo");
  const modalTexto = document.getElementById("modal-texto");
  const modalInputArea = document.getElementById("modal-input-container");
  const modalLabel = document.getElementById("modal-label");
  const modalInput = document.getElementById("modal-input");
  const modalDescTipo = document.getElementById("modal-desc-tipo");
  const modalStockAlert = document.getElementById("modal-stock-alert");
  const btnConfirm = document.getElementById("modal-confirm");
  const btnCancel = document.getElementById("modal-cancel");
  const ticketPrintModeSelect = document.getElementById("ticketPrintMode");
  const ticketPreviewModal = document.getElementById("ticketPreviewModal");
  const ticketPreviewFrame = document.getElementById("ticketPreviewFrame");
  const ticketPreviewVentaId = document.getElementById("ticketPreviewVentaId");
  const ticketPreviewOpen = document.getElementById("ticketPreviewOpen");
  const ticketPreviewPrint = document.getElementById("ticketPreviewPrint");

  let modalResolver = null;
  let modalIsInput = false;
  let modalCurrentItem = null; // Item actual para validación de stock

  const optPrecio = modalDescTipo?.querySelector('option[value="precio"]');

  function buildTicketUrl(ventaId, opts = {}) {
    const params = new URLSearchParams({
      venta_id: String(ventaId),
      paper: getPaper(),
    });
    if (opts.autoprint) params.set("autoprint", "1");
    return `ticket.php?${params.toString()}`;
  }

  function closeTicketPreview() {
    if (!ticketPreviewModal) return;
    ticketPreviewModal.classList.add("hidden");
    ticketPreviewModal.setAttribute("aria-hidden", "true");
    if (ticketPreviewFrame) ticketPreviewFrame.src = "about:blank";
    if (ticketPreviewOpen) ticketPreviewOpen.href = "#";
    if (ticketPreviewVentaId) ticketPreviewVentaId.textContent = "0";
  }

  function openTicketPreview(ventaId) {
    if (!ticketPreviewModal || !ticketPreviewFrame) {
      window.open(buildTicketUrl(ventaId), "_blank", "noopener");
      return;
    }

    const url = buildTicketUrl(ventaId);
    ticketPreviewModal.classList.remove("hidden");
    ticketPreviewModal.setAttribute("aria-hidden", "false");
    if (ticketPreviewVentaId) ticketPreviewVentaId.textContent = String(ventaId);
    if (ticketPreviewOpen) ticketPreviewOpen.href = url;
    ticketPreviewFrame.src = url;
  }

  function printTicketSilently(ventaId) {
    const iframe = document.createElement("iframe");
    iframe.style.display = "none";
    iframe.src = buildTicketUrl(ventaId, { autoprint: true });
    iframe.onload = () => {
      window.setTimeout(() => {
        iframe.remove();
      }, 4000);
    };
    document.body.appendChild(iframe);
  }

  function dispatchTicketOutput(ventaId) {
    const mode = getPrintMode();
    if (mode === "none") return;
    if (mode === "preview") {
      openTicketPreview(ventaId);
      return;
    }
    printTicketSilently(ventaId);
  }

  function initTicketOutputControls() {
    if (ticketPrintModeSelect) {
      ticketPrintModeSelect.value = getPrintMode();
      ticketPrintModeSelect.addEventListener("change", () => {
        const next = setPrintMode(ticketPrintModeSelect.value);
        ticketPrintModeSelect.value = next;

        if (next === "autoprint") {
          mostrarMensaje(
            "info",
            "Auto imprimir activado. El navegador puede seguir mostrando su dialogo de impresion.",
          );
        } else if (next === "preview") {
          mostrarMensaje(
            "info",
            "Vista previa activada. El ticket se abrira dentro de FLUS despues de cobrar.",
          );
        } else {
          mostrarMensaje(
            "info",
            "No abrir ticket activado. La venta no disparara impresion automatica.",
          );
        }
      });
    }

    if (ticketPreviewModal) {
      ticketPreviewModal
        .querySelectorAll("[data-ticket-preview-close]")
        .forEach((el) => {
          el.addEventListener("click", closeTicketPreview);
        });
    }

    ticketPreviewPrint?.addEventListener("click", () => {
      const win = ticketPreviewFrame?.contentWindow;
      if (!win) return;
      win.focus();
      win.print();
    });

    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && ticketPreviewModal && !ticketPreviewModal.classList.contains("hidden")) {
        closeTicketPreview();
      }
    });
  }

  // =========================
  // HELPERS
  // =========================
  const fmt = new Intl.NumberFormat("es-AR", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
  const fmtQty3 = new Intl.NumberFormat("es-AR", {
    minimumFractionDigits: 3,
    maximumFractionDigits: 3,
  });

  const formatearMoneda = (n) => "$" + fmt.format(Number(n) || 0);

  function getNumericDataValue(el) {
    const raw = el?.dataset?.value ?? "0";
    const n = Number(raw);
    return Number.isFinite(n) ? n : 0;
  }

  function setNumericDataValue(el, value) {
    if (!el) return;
    el.dataset.value = String(Number(value) || 0);
  }

  function actualizarKpisLive(pagadoTotal = null) {
    if (kpiTicketActual) {
      kpiTicketActual.textContent = formatearMoneda(totalNetoActual);
    }

    if (kpiPagadoActual) {
      const montoPagado =
        pagadoTotal === null ? totalPagado(pagosDesdeUI()) : pagadoTotal;
      kpiPagadoActual.textContent = formatearMoneda(montoPagado);
    }

    if (btnCobrarExacto) {
      btnCobrarExacto.disabled = splitActivo() || totalNetoActual <= 0;
    }
  }

  let paymentActiveSlot = "1";

  function reubicarPanelCC() {
    if (!ccWrap || !pagosRow) return;

    const medio1 = String(selMedio?.value || "EFECTIVO").toUpperCase();
    const medio2 = String(selMedio2?.value || "EFECTIVO").toUpperCase();
    let anchor = null;

    if (medio1 === "CC" && pago1Wrap) {
      anchor = pago1Wrap;
    } else if (splitActivo() && medio2 === "CC" && pago2Wrap) {
      anchor = pago2Wrap;
    }

    if (anchor) {
      anchor.insertAdjacentElement("afterend", ccWrap);
    }
  }

  function actualizarEstadoPagosUI(nextSlot = null) {
    if (nextSlot === "1" || nextSlot === "2") {
      paymentActiveSlot = nextSlot;
    } else {
      const activeEl = document.activeElement;
      if (
        splitActivo() &&
        activeEl &&
        (activeEl === selMedio2 ||
          activeEl === inputPagado2 ||
          activeEl === btnQuitarPago2)
      ) {
        paymentActiveSlot = "2";
      } else {
        paymentActiveSlot = "1";
      }
    }

    const medio1 = String(selMedio?.value || "EFECTIVO").toUpperCase();
    const medio2 = String(selMedio2?.value || "EFECTIVO").toUpperCase();

    pagosRow?.classList.toggle("pagos-row--split", splitActivo());
    btnAgregarPago?.classList.toggle("is-hidden", splitActivo());

    [
      [pago1Wrap, "1", medio1, true],
      [pago2Wrap, "2", medio2, splitActivo()],
    ].forEach(([wrap, slot, medio, visible]) => {
      if (!wrap) return;
      wrap.dataset.medio = String(medio || "").toLowerCase();
      wrap.classList.toggle("is-active", visible && paymentActiveSlot === slot);
      wrap.classList.toggle("is-cash", visible && medio === "EFECTIVO");
      wrap.classList.toggle("is-cc", visible && medio === "CC");
      wrap.classList.toggle(
        "is-digital",
        visible && medio !== "EFECTIVO" && medio !== "CC",
      );
    });

    reubicarPanelCC();
  }

  function sumarKpisSesion(pagos, totalVenta) {
    if (kpiVentasSesion) {
      const nextCount = getNumericDataValue(kpiVentasSesion) + 1;
      setNumericDataValue(kpiVentasSesion, nextCount);
      kpiVentasSesion.textContent = new Intl.NumberFormat("es-AR", {
        maximumFractionDigits: 0,
      }).format(nextCount);
    }

    if (kpiTotalSesion) {
      const nextTotal = getNumericDataValue(kpiTotalSesion) + (Number(totalVenta) || 0);
      setNumericDataValue(kpiTotalSesion, nextTotal);
      kpiTotalSesion.textContent = formatearMoneda(nextTotal);
    }

    if (kpiEfectivoSesion) {
      const nextEfectivo =
        getNumericDataValue(kpiEfectivoSesion) + efectivoPagado(pagos);
      setNumericDataValue(kpiEfectivoSesion, nextEfectivo);
      kpiEfectivoSesion.textContent = formatearMoneda(nextEfectivo);
    }

    if (kpiMpSesion) {
      const incrementoMp = (pagos || []).reduce((sum, pago) => {
        const medio = String(pago?.medio || "").toUpperCase();
        const monto = Number(pago?.monto) || 0;
        return sum + (medio === "MP" ? monto : 0);
      }, 0);
      const nextMp = getNumericDataValue(kpiMpSesion) + incrementoMp;
      setNumericDataValue(kpiMpSesion, nextMp);
      kpiMpSesion.textContent = formatearMoneda(nextMp);
    }
  }
  // =========================
  // ✅ PESABLE UX (G/ML sin confusión)
  // - G  => unidad interna = 100 g
  // - ML => unidad interna = 100 ml
  // Heurística compat:
  //   * si ingresa <= 20 => se interpreta como "packs" (modo viejo)
  //   * si ingresa > 20  => se interpreta como gramos/ml (modo cajero)
  // KG/LT:
  //   * si ingresa >= 50 => se interpreta como gramos/ml y se convierte a kg/lt
  // =========================
  const fmtInt0 = new Intl.NumberFormat("es-AR", { maximumFractionDigits: 0 });

  function parseNumeroLocale(v) {
    const s = String(v ?? "")
      .trim()
      .replace(/\./g, "")
      .replace(",", ".");
    const n = parseFloat(s);
    return Number.isFinite(n) ? n : NaN;
  }

  function unidadPrecioHint(unidadVenta) {
    const u = String(unidadVenta || "").toUpperCase();
    if (u === "G") return "100 g";
    if (u === "ML") return "100 ml";
    if (u === "KG") return "kg";
    if (u === "LT") return "lt";
    return "";
  }

  function cantidadInternaDesdeInput(raw, unidadVenta, esPesable) {
    if (!esPesable) {
      const n = parseInt(String(raw ?? "1"), 10);
      return Number.isFinite(n) && n > 0 ? n : 1;
    }

    const u = String(unidadVenta || "KG").toUpperCase();
    const n = parseNumeroLocale(raw);
    if (!Number.isFinite(n) || n <= 0) return NaN;

    // G/ML (packs de 100)
    if (u === "G" || u === "ML") {
      // compat: si es chico, interpretamos "packs" (3 => 3x100)
      if (n <= 20) return n;
      // modo cajero: 300 => 3
      return n / 100;
    }

    // KG/LT (permitimos escribir gramos/ml directo)
    if (u === "KG") {
      if (n >= 50) return n / 1000; // 3560 => 3.560 kg
      return n; // 3.56 => 3.56 kg
    }
    if (u === "LT") {
      if (n >= 50) return n / 1000; // 700 => 0.700 lt
      return n; // 0.7 => 0.7 lt
    }

    return n;
  }

  function cantidadHumanaDesdeInterna(cantInterna, unidadVenta, esPesable) {
    const c = Number(cantInterna) || 0;
    if (!esPesable) return Math.round(c);

    const u = String(unidadVenta || "KG").toUpperCase();
    if (u === "G") return c * 100; // packs -> gramos
    if (u === "ML") return c * 100; // packs -> ml
    return c; // KG/LT ya están en unidad humana
  }

  function formatearCantidadHumana(item) {
    const cant = Number(item?.cantidad) || 0;
    const esPesable = !!item?.esPesable;
    const u = String(
      item?.unidadVenta || (esPesable ? "KG" : "UNID"),
    ).toUpperCase();

    if (!esPesable) {
      return `${Math.round(cant)} UNID`;
    }

    if (u === "G") return `${fmtInt0.format(cant * 100)} g`;
    if (u === "ML") return `${fmtInt0.format(cant * 100)} ml`;

    // KG / LT: mantenemos 3 decimales si hace falta
    const entero = Math.round(cant);
    const esEntero = Math.abs(cant - entero) < 0.0005;

    if (esEntero) return `${entero} ${u}`;
    return `${fmtQty3.format(cant)} ${u}`;
  }

  function aplicarHintCantidadInput(unidadVenta) {
    if (!inputCant) return;
    const u = String(unidadVenta || "").toUpperCase();

    if (u === "G") {
      inputCant.placeholder = "Ej: 300 (g) o 3 (x100g)";
      inputCant.title = "Podés escribir gramos (300) o packs (3×100g).";
      inputCant.step = "1";
    } else if (u === "ML") {
      inputCant.placeholder = "Ej: 800 (ml) o 8 (x100ml)";
      inputCant.title = "Podés escribir ml (800) o packs (8×100ml).";
      inputCant.step = "1";
    } else if (u === "KG") {
      inputCant.placeholder = "Ej: 3.560 (kg) o 3560 (g)";
      inputCant.title = "Podés escribir kg (3.560) o gramos (3560).";
      inputCant.step = "0.001";
    } else if (u === "LT") {
      inputCant.placeholder = "Ej: 0.700 (lt) o 700 (ml)";
      inputCant.title = "Podés escribir litros (0.700) o ml (700).";
      inputCant.step = "0.001";
    } else {
      inputCant.placeholder = "";
      inputCant.title = "";
    }
  }

  function getCsrf() {
    return (
      (window.getCsrfToken && window.getCsrfToken()) ||
      document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute("content") ||
      ""
    );
  }

  // Ocultamos el pill de mensajes (ya no lo usamos)
  if (msgBox) msgBox.style.display = "none";

  // Anti-spam de toasts repetidos
  let _lastToast = { t: 0, text: "" };

  function mostrarMensaje(tipo, texto) {
    const msg = String(texto ?? "");

    // Preferimos SweetAlert/Notif (global)
    if (window.Notif) {
      const now = Date.now();
      if (msg === _lastToast.text && now - _lastToast.t < 1200) return;
      _lastToast = { t: now, text: msg };

      if (tipo === "error" || tipo === "danger") return Notif.error(msg);
      if (tipo === "warning" || tipo === "warn") return Notif.advertencia(msg);
      if (tipo === "success" || tipo === "ok") return Notif.exito(msg);

      return Notif.advertencia(msg);
    }

    // Fallback (si Notif no existe por algún motivo)
    if (!msgBox) return;
    msgBox.style.display = "";
    msgBox.textContent = msg;
    msgBox.className = "msg msg-visible msg-" + tipo;
  }

  function limpiarMensaje() {
    if (msgBox) {
      msgBox.textContent = "";
      msgBox.className = "msg";
      msgBox.style.display = "none";
    }
  }

  function formatearCantidad(item) {
    return formatearCantidadHumana(item);
  }

  // =========================
  // SPLIT PAYMENT (2 medios)
  // =========================
  function splitActivo() {
    return !!pago2Wrap && !pago2Wrap.classList.contains("is-hidden");
  }

  function setSplitActivo(on) {
    if (!pago2Wrap) return;
    if (on) {
      pago2Wrap.classList.remove("is-hidden");
      // En split, ambos montos son editables
      if (inputPagado) inputPagado.disabled = false;
      if (inputPagado2) inputPagado2.disabled = false;
    } else {
      pago2Wrap.classList.add("is-hidden");
      if (selMedio2) selMedio2.value = "EFECTIVO";
      if (inputPagado2) inputPagado2.value = "";
    }
    actualizarEstadoPagosUI(on ? "2" : "1");
  }

  function parseMonto(v) {
    const n = parseFloat(String(v ?? "0").replace(",", "."));
    return Number.isFinite(n) ? n : 0;
  }

  function pagosDesdeUI() {
    const pagos = [];
    const m1 = String(selMedio?.value || "EFECTIVO").toUpperCase();
    const a1 = parseMonto(inputPagado?.value || "0");
    if (a1 > 0) pagos.push({ medio: m1, monto: a1 });

    if (splitActivo()) {
      const m2 = String(selMedio2?.value || "EFECTIVO").toUpperCase();
      const a2 = parseMonto(inputPagado2?.value || "0");
      if (a2 > 0) pagos.push({ medio: m2, monto: a2 });
    }
    return pagos;
  }

  function totalPagado(pagos) {
    return (pagos || []).reduce((sum, p) => sum + (Number(p?.monto) || 0), 0);
  }

  function efectivoPagado(pagos) {
    return (pagos || []).reduce((sum, p) => {
      const medio = String(p?.medio || "").toUpperCase();
      const monto = Number(p?.monto) || 0;
      return sum + (medio === "EFECTIVO" ? monto : 0);
    }, 0);
  }
  function medioEsEfectivo() {
    return (selMedio?.value || "EFECTIVO") === "EFECTIVO";
  }

  function ajustarPagoSegunMedio() {
    if (!inputPagado) return;

    // En split (2 medios) no “forzamos” el monto. El usuario reparte.
    if (splitActivo()) {
      inputPagado.disabled = false;
      if (inputPagado2) inputPagado2.disabled = false;
      actualizarEstadoPagosUI();
      return;
    }

    // Modo legacy (1 medio)
    if (!medioEsEfectivo()) {
      inputPagado.value = String(Number(totalNetoActual || 0).toFixed(2));
      inputPagado.disabled = true;
    } else {
      inputPagado.disabled = false;
    }
    actualizarEstadoPagosUI();
  }

  function recalcularVuelto() {
    const total = Number(totalNetoActual) || 0;

    // ✅ Si está activo el 2º pago y NO fue tocado manualmente,
    // mantener "Monto 2" como lo que falta para completar el total.
    if (splitActivo() && inputPagado2) {
      const auto = inputPagado2.dataset.auto !== "0"; // por defecto auto
      if (auto) {
        const a1 = parseMonto(inputPagado?.value || "0");
        const falta2 = Math.max(total - a1, 0);
        inputPagado2.value = falta2 > 0 ? String(falta2.toFixed(2)) : "";
        inputPagado2.dataset.auto = "1";
      }
    }

    const pagos = pagosDesdeUI();
    const pagadoTotal = totalPagado(pagos);
    const vuelto = Math.max(pagadoTotal - total, 0);
    const resta = Math.max(total - pagadoTotal, 0);

    if (lblTotalPagado)
      lblTotalPagado.textContent = formatearMoneda(pagadoTotal);
    if (lblVuelto) lblVuelto.textContent = formatearMoneda(vuelto);
    actualizarKpisLive(pagadoTotal);

    // ✅ Mostrar "Resta pagar" solo cuando hay 2 medios y falta dinero
    if (restaWrap && lblRestaPagar) {
      if (splitActivo() && resta > 0.009) {
        restaWrap.classList.remove("is-hidden");
        lblRestaPagar.textContent = formatearMoneda(resta);
      } else {
        restaWrap.classList.add("is-hidden");
        lblRestaPagar.textContent = formatearMoneda(0);
      }
    }
  }

  // =========================
  // CUENTA CORRIENTE (CC)
  // =========================
  const ccWrap = document.getElementById("ccWrap");
  const ccInputBuscar = document.getElementById("ccClienteBuscar");
  const ccInputId = document.getElementById("ccClienteId");
  const ccInfo = document.getElementById("ccClienteInfo");

  let ccDropdown = null;
  let ccResults = [];
  let ccSelectedIdx = -1;
  let ccAbort = null;
  let ccClienteSeleccionado = null;

  // Verificar si algún pago usa CC
  function tieneCC() {
    const m1 = String(selMedio?.value || "").toUpperCase();
    if (m1 === "CC") return true;
    if (splitActivo()) {
      const m2 = String(selMedio2?.value || "").toUpperCase();
      if (m2 === "CC") return true;
    }
    return false;
  }

  // Calcular monto total en CC
  function montoEnCC() {
    let total = 0;
    const m1 = String(selMedio?.value || "").toUpperCase();
    const a1 = parseMonto(inputPagado?.value || "0");
    if (m1 === "CC" && a1 > 0) total += a1;

    if (splitActivo()) {
      const m2 = String(selMedio2?.value || "").toUpperCase();
      const a2 = parseMonto(inputPagado2?.value || "0");
      if (m2 === "CC" && a2 > 0) total += a2;
    }
    return total;
  }

  // Mostrar/ocultar panel CC
  function actualizarVisibilidadCC() {
    if (!ccWrap) return;
    if (tieneCC()) {
      ccWrap.classList.remove("is-hidden");
      if (!ccClienteSeleccionado) {
        setTimeout(() => ccInputBuscar?.focus?.(), 50);
      }
    } else {
      ccWrap.classList.add("is-hidden");
      // Limpiar cliente al ocultar
      limpiarClienteCC();
    }
  }

  function limpiarClienteCC() {
    if (ccInputBuscar) ccInputBuscar.value = "";
    if (ccInputId) ccInputId.value = "";
    if (ccInfo) ccInfo.innerHTML = "";
    ccClienteSeleccionado = null;
    ocultarDropdownCC();
  }

  function renderClienteCCSeleccionado(c) {
    if (!c || !ccInfo) return;
    const disp = Number(c.cc_disponible || 0).toFixed(2);
    const saldo = Number(c.cc_saldo || 0).toFixed(2);
    const limite = Number(c.cc_limite || 0).toFixed(2);

    ccInfo.innerHTML = `
      <div class="cc-cliente-info">
        <span class="cc-cliente-nombre">${escHtml(c.nombre)}</span>
        <span class="cc-cliente-detalle">
          Disponible: <strong>$${disp}</strong> · Saldo actual: $${saldo} · Límite: $${limite}
        </span>
      </div>`;
  }

  // Dropdown autocompletado CC
  function crearDropdownCC() {
    if (ccDropdown || !ccInputBuscar) return;
    ccDropdown = document.createElement("div");
    ccDropdown.className = "autocomplete-dropdown cc-dropdown";
    ccDropdown.style.cssText =
      "position:absolute;display:none;z-index:99999;max-height:250px;overflow-y:auto;";
    ccInputBuscar.parentElement.style.position = "relative";
    ccInputBuscar.parentElement.appendChild(ccDropdown);
  }

  function ocultarDropdownCC() {
    if (ccDropdown) ccDropdown.style.display = "none";
    ccResults = [];
    ccSelectedIdx = -1;
  }

  function escHtml(s) {
    return String(s || "").replace(
      /[&<>"']/g,
      (c) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#039;",
        })[c],
    );
  }

  function renderDropdownCC() {
    if (!ccDropdown || !ccResults.length) return ocultarDropdownCC();

    ccDropdown.innerHTML = ccResults
      .map((c, i) => {
        const sel = i === ccSelectedIdx ? "selected" : "";
        const saldo = Number(c.cc_saldo || 0).toFixed(2);
        const limite = Number(c.cc_limite || 0).toFixed(2);
        const disp = Number(c.cc_disponible || 0).toFixed(2);
        return `
        <div class="autocomplete-item ${sel}" data-idx="${i}">
          <div class="ac-title">${escHtml(c.nombre)}</div>
          <div class="ac-meta">
            ${c.cuit ? "CUIT: " + escHtml(c.cuit) + " · " : ""}
            Tel: ${escHtml(c.telefono || "-")} · 
            Disponible: <strong>$${disp}</strong> (Saldo: $${saldo} / Límite: $${limite})
          </div>
        </div>`;
      })
      .join("");

    // Posicionar
    const rect = ccInputBuscar.getBoundingClientRect();
    const parentRect = ccInputBuscar.parentElement.getBoundingClientRect();
    ccDropdown.style.top = rect.bottom - parentRect.top + 2 + "px";
    ccDropdown.style.left = "0";
    ccDropdown.style.width = "100%";
    ccDropdown.style.display = "block";
  }

  function seleccionarClienteCC(idx) {
    const c = ccResults[idx];
    if (!c) return;

    ccClienteSeleccionado = c;
    if (ccInputBuscar) ccInputBuscar.value = c.nombre || "";
    if (ccInputId) ccInputId.value = String(c.id || "");

    renderClienteCCSeleccionado(c);

    ocultarDropdownCC();

    // Validar disponibilidad
    validarDisponibilidadCC();
  }

  async function buscarClientesCC(q) {
    q = String(q || "").trim();
    if (q.length < 2) return ocultarDropdownCC();

    crearDropdownCC();
    if (ccAbort) ccAbort.abort();
    ccAbort = new AbortController();

    try {
      const res = await fetch(
        `api/index.php?action=buscar_clientes_cc&q=${encodeURIComponent(q)}`,
        {
          signal: ccAbort.signal,
          credentials: "same-origin",
          headers: { Accept: "application/json" },
        },
      );
      const data = await res.json();

      if (data?.ok && Array.isArray(data.clientes)) {
        ccResults = data.clientes;
        ccSelectedIdx = -1;
        renderDropdownCC();
      } else {
        ocultarDropdownCC();
      }
    } catch (e) {
      if (e?.name !== "AbortError")
        console.warn("Error buscando clientes CC:", e);
      ocultarDropdownCC();
    }
  }

  async function validarDisponibilidadCC() {
    if (!ccClienteSeleccionado) return { ok: true };

    const monto = montoEnCC();
    if (monto <= 0) return { ok: true };

    try {
      const res = await fetch(
        `api/index.php?action=verificar_cc&cliente_id=${ccClienteSeleccionado.id}&monto=${monto}`,
        {
          credentials: "same-origin",
          headers: { Accept: "application/json" },
        },
      );
      const data = await res.json();

      if (!data?.ok) {
        return { ok: false, error: data?.error || "Error verificando CC" };
      }

      if (!data.habilitado) {
        return { ok: false, error: "Cliente sin CC habilitada" };
      }

      if (!data.puede_comprar) {
        return {
          ok: false,
          error: data.mensaje || "Excede límite de crédito",
          excede: true,
        };
      }

      // Si excede pero puede autorizar, mostrar advertencia
      if (data.excede && data.puede_autorizar) {
        if (ccInfo) {
          ccInfo.innerHTML += `<div class="cc-advertencia">⚠️ ${escHtml(data.mensaje)}</div>`;
        }
      }

      return { ok: true, data };
    } catch (e) {
      console.error("Error validando CC:", e);
      return { ok: false, error: "Error de conexión al validar CC" };
    }
  }

  // Eventos CC
  if (ccInputBuscar) {
    let ccDebounce = null;

    ccInputBuscar.addEventListener("input", () => {
      clearTimeout(ccDebounce);
      // Si cambia el texto, limpiar cliente seleccionado
      if (
        ccClienteSeleccionado &&
        ccInputBuscar.value !== ccClienteSeleccionado.nombre
      ) {
        ccClienteSeleccionado = null;
        if (ccInputId) ccInputId.value = "";
        if (ccInfo) ccInfo.innerHTML = "";
      }
      ccDebounce = setTimeout(() => buscarClientesCC(ccInputBuscar.value), 200);
    });

    ccInputBuscar.addEventListener("keydown", (e) => {
      if (!ccDropdown || ccDropdown.style.display === "none") return;

      if (e.key === "ArrowDown") {
        e.preventDefault();
        ccSelectedIdx = Math.min(ccSelectedIdx + 1, ccResults.length - 1);
        renderDropdownCC();
      } else if (e.key === "ArrowUp") {
        e.preventDefault();
        ccSelectedIdx = Math.max(ccSelectedIdx - 1, 0);
        renderDropdownCC();
      } else if (e.key === "Enter") {
        if (ccSelectedIdx >= 0) {
          e.preventDefault();
          seleccionarClienteCC(ccSelectedIdx);
        }
      } else if (e.key === "Escape") {
        ocultarDropdownCC();
      }
    });

    ccInputBuscar.addEventListener("blur", () => {
      setTimeout(() => {
        if (!ccDropdown?.matches(":hover")) ocultarDropdownCC();
      }, 150);
    });
  }

  // Click en dropdown CC
  document.addEventListener("mousedown", (e) => {
    if (!ccDropdown) return;
    const item = e.target.closest(".autocomplete-item");
    if (item && ccDropdown.contains(item)) {
      e.preventDefault();
      const idx = parseInt(item.dataset.idx, 10);
      if (Number.isFinite(idx)) seleccionarClienteCC(idx);
    }
  });

  // Función helper para obtener cliente CC actual
  function getClienteCC() {
    if (!tieneCC()) return null;
    if (!ccClienteSeleccionado) return null;
    return {
      id: ccClienteSeleccionado.id,
      nombre: ccClienteSeleccionado.nombre,
    };
  }

  // Inicializar visibilidad CC
  setTimeout(() => actualizarVisibilidadCC(), 0);

  // =========================
  // STORAGE
  // =========================
  function limpiarEstadoPersistido() {
    localStorage.removeItem(STORAGE_KEY);
    if (LEGACY_STORAGE_KEY) localStorage.removeItem(LEGACY_STORAGE_KEY);
  }

  function migrarEstadoLegacy() {
    if (localStorage.getItem(STORAGE_KEY) || !LEGACY_STORAGE_KEY) return;
    const legacyRaw = localStorage.getItem(LEGACY_STORAGE_KEY);
    if (!legacyRaw) return;
    localStorage.setItem(STORAGE_KEY, legacyRaw);
  }

  function guardarEstado() {
    const pagosRaw = [];

    // Guardamos valores “crudos” para no perder lo tipeado (ej: "500,50")
    pagosRaw.push({
      medio: String(selMedio?.value || "EFECTIVO").toUpperCase(),
      monto: String(inputPagado?.value || ""),
    });

    if (splitActivo()) {
      pagosRaw.push({
        medio: String(selMedio2?.value || "EFECTIVO").toUpperCase(),
        monto: String(inputPagado2?.value || ""),
      });
    }

    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        carrito,
        pagos: pagosRaw,
        split: splitActivo(),
        descGlobal,
        ccCliente: ccClienteSeleccionado
          ? {
              id: ccClienteSeleccionado.id,
              nombre: ccClienteSeleccionado.nombre,
              cc_disponible: ccClienteSeleccionado.cc_disponible || 0,
              cc_saldo: ccClienteSeleccionado.cc_saldo || 0,
              cc_limite: ccClienteSeleccionado.cc_limite || 0,
            }
          : null,
        caja_id: CAJA_ID || 0,
      }),
    );
  }

  function cargarEstado() {
    migrarEstadoLegacy();

    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return;
    try {
      const data = JSON.parse(raw);
      carrito = data.carrito || [];
      descGlobal = data.descGlobal || null;

      // Pagos (nuevo)
      const pagosRaw = Array.isArray(data.pagos) ? data.pagos : null;

      if (pagosRaw && pagosRaw.length) {
        const p1 = pagosRaw[0] || {};
        if (selMedio && p1.medio)
          selMedio.value = String(p1.medio).toUpperCase();
        if (inputPagado && p1.monto != null)
          inputPagado.value = String(p1.monto);

        const split = !!data.split || pagosRaw.length > 1;
        setSplitActivo(split);

        if (split && pagosRaw.length > 1) {
          const p2 = pagosRaw[1] || {};
          if (selMedio2 && p2.medio)
            selMedio2.value = String(p2.medio).toUpperCase();
          if (inputPagado2 && p2.monto != null)
            inputPagado2.value = String(p2.monto);
        }
      } else {
        // Legacy (por compat): medio + pagado
        if (selMedio && data.medio) selMedio.value = data.medio;
        if (inputPagado && data.pagado != null) inputPagado.value = data.pagado;
      }

      if (data.ccCliente && data.ccCliente.id) {
        ccClienteSeleccionado = data.ccCliente;
        if (ccInputBuscar) ccInputBuscar.value = String(data.ccCliente.nombre || "");
        if (ccInputId) ccInputId.value = String(data.ccCliente.id || "");
        renderClienteCCSeleccionado(data.ccCliente);
      }

      estadoRecuperado = !!(
        (Array.isArray(carrito) && carrito.length > 0) ||
        (Array.isArray(pagosRaw) && pagosRaw.some((p) => Number(parseMonto(p?.monto || 0)) > 0)) ||
        descGlobal
      );

      // ✅ Si no tiene permiso, limpiar cualquier descuento/precio “guardado”
      if (!CAN_MOD_PRECIO) {
        descGlobal = null;
        carrito = (carrito || []).map((it) => {
          const lista = Number(it?.precioLista ?? it?.precio ?? 0);
          return { ...it, precioLista: lista, precio: lista };
        });
      }
    } catch (e) {
      console.error("Error parseando estado de caja:", e);
    }
  }

  // =========================
  // FETCH JSON SEGURO
  // =========================
  async function fetchJson(url, opt = {}) {
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), API_TIMEOUT_MS);

    const csrf = getCsrf();

    const headers = new Headers(opt.headers || {});
    if (!headers.has("Accept")) headers.set("Accept", "application/json");
    const isFormData =
      typeof FormData !== "undefined" && opt.body instanceof FormData;

    if (opt.body && !isFormData && !headers.has("Content-Type")) {
      headers.set("Content-Type", "application/json; charset=utf-8");
    }

    if (csrf) {
      if (!headers.has("X-CSRF-Token")) headers.set("X-CSRF-Token", csrf);
      if (!headers.has("X-CSRF-TOKEN")) headers.set("X-CSRF-TOKEN", csrf); // compat
      if (!headers.has("X-CSRF")) headers.set("X-CSRF", csrf); // compat
    }

    try {
      const res = await fetch(url, {
        ...opt,
        headers,
        signal: ctrl.signal,
        credentials: "same-origin",
      });

      const text = await res.text();

      let data = null;
      try {
        data = text ? JSON.parse(text) : null;
      } catch {
        console.error("Respuesta NO JSON desde API:", {
          url,
          status: res.status,
          text: text.slice(0, 500),
        });
        throw new Error(`La API no devolvió JSON válido (HTTP ${res.status})`);
      }
      // ✅ casos especiales muy comunes en FLUS
      if (res.status === 409 && data?.error === "LOCK_NOT_OWNED") {
        throw new Error("LOCK_NOT_OWNED");
      }
      if (
        !res.ok &&
        String(data?.error || "")
          .toUpperCase()
          .includes("CSRF")
      ) {
        throw new Error("CSRF");
      }

      if (!res.ok) {
        const msg = data?.error || data?.message || `HTTP ${res.status}`;
        if (res.status === 401) throw new Error(`No autenticado (401). ${msg}`);
        if (res.status === 403) throw new Error(`No autorizado (403). ${msg}`);
        throw new Error(msg);
      }

      return data;
    } catch (err) {
      // ✅ autorecuperación
      if (err?.message === "CSRF") {
        window.location.reload();
        throw err;
      }
      if (err?.message === "LOCK_NOT_OWNED") {
        window.location.href = "terminal_select.php?next=caja.php";
        throw err;
      }

      if (err?.name === "AbortError")
        throw new Error("Tiempo de espera agotado al llamar a la API");
      throw err;
    } finally {
      clearTimeout(t);
    }
  }

  // =========================
  // PROMOS
  // =========================
  async function cargarPromos() {
    try {
      const data = await fetchJson(`${API_BASE}?action=listar_promos_activas`);
      if (!data.ok) return;

      promosPorProducto = {};
      promosCombos = [];

      if (Array.isArray(data.simples)) {
        data.simples.forEach((p) => {
          promosPorProducto[String(p.producto_id)] = {
            promoId: p.promo_id,
            nombre: p.nombre,
            tipo: p.tipo,
            n: Number(p.n),
            m: p.m !== null ? Number(p.m) : null,
            porcentaje: p.porcentaje !== null ? Number(p.porcentaje) : null,
          };
        });
      }

      if (Array.isArray(data.combos)) {
        data.combos.forEach((c) => {
          promosCombos.push({
            promoId: c.promo_id,
            nombre: c.nombre,
            tipo: "COMBO_FIJO",
            precio_combo: Number(c.precio_combo),
            items: (c.items || []).map((it) => ({
              producto_id: Number(it.producto_id),
              cantidad: Number(it.cantidad),
            })),
          });
        });
      }
    } catch (err) {
      console.error("Error cargando promos:", err);
    }
  }

  function aplicarPromoNPagaM(item, promo) {
    const cant = Number(item.cantidad);
    if (cant < promo.n) return null;

    const packs = Math.floor(cant / promo.n);
    const resto = cant - packs * promo.n; // ✅ igual al backend
    const pagar = packs * promo.m + resto;

    const precio = Number(item.precioLista) || 0;

    const subtotalPromo = pagar * precio;
    const subtotalNormal = cant * precio;

    return {
      descuento: subtotalNormal - subtotalPromo,
      subtotalFinal: subtotalPromo,
      descripcion: promo.nombre,
    };
  }

  function aplicarPromoNthPct(item, promo) {
    const cant = Number(item.cantidad);
    if (cant < promo.n) return null;

    const precio = Number(item.precioLista) || 0;
    const unidadesDesc = Math.floor(cant / promo.n);
    const descuento = (unidadesDesc * precio * promo.porcentaje) / 100;

    return {
      descuento,
      subtotalFinal: cant * precio - descuento,
      descripcion: promo.nombre,
    };
  }

  function aplicarPromosItem(item) {
    const promo = promosPorProducto[String(item.id)];
    if (!promo) return null;

    // ✅ Ya no rechazamos pesables
    if (promo.tipo === "N_PAGA_M") return aplicarPromoNPagaM(item, promo);
    if (promo.tipo === "NTH_PCT") return aplicarPromoNthPct(item, promo);
    return null;
  }

  function aplicarCombos(carrito) {
    const combosAplicados = [];

    promosCombos.forEach((combo) => {
      let maxCombos = Infinity;

      combo.items.forEach((req) => {
        const it = carrito.find((c) => Number(c.id) === req.producto_id);
        if (!it) {
          maxCombos = 0;
          return;
        }

        // ✅ Tolerancia para pesables
        const tolerance = it.esPesable ? 0.01 : 0;
        maxCombos = Math.min(
          maxCombos,
          Math.floor((it.cantidad + tolerance) / req.cantidad),
        );
      });

      if (maxCombos > 0 && maxCombos !== Infinity) {
        combosAplicados.push({ combo, cantidad: maxCombos, descuento: 0 });
      }
    });

    return combosAplicados;
  }

  // =========================
  // DESCUENTO GLOBAL
  // =========================
  function calcDescGlobal(totalNetoAntes) {
    if (!descGlobal) return 0;
    const tipo = descGlobal.tipo;
    const valor = Number(descGlobal.valor) || 0;
    if (valor <= 0) return 0;

    if (tipo === "porcentaje") {
      return Math.min(totalNetoAntes, (totalNetoAntes * valor) / 100);
    }
    if (tipo === "monto") {
      return Math.min(totalNetoAntes, valor);
    }
    return 0;
  }

  // =========================
  // ✅ SINCRONIZACIÓN CON SERVIDOR (FIX duplicación de lógica)
  // Esta función actualiza el carrito con los precios calculados por el backend
  // =========================
  let sincronizandoConServidor = false;
  let ultimaSincronizacion = 0;
  const SYNC_DEBOUNCE_MS = 300;

  async function sincronizarCarritoConServidor(forzar = false) {
    // ✅ FIX v2.1.2: Si es forzado y hay sync en curso, esperar a que termine
    if (sincronizandoConServidor) {
      if (forzar) {
        // Esperar hasta 2 segundos a que termine el sync actual
        let intentos = 0;
        while (sincronizandoConServidor && intentos < 20) {
          await new Promise((r) => setTimeout(r, 100));
          intentos++;
        }
        // Si sigue ocupado después de esperar, continuar igual
        if (sincronizandoConServidor) {
          console.warn("Sync forzado: timeout esperando sync anterior");
        }
      } else {
        return null;
      }
    }

    const ahora = Date.now();
    if (!forzar && ahora - ultimaSincronizacion < SYNC_DEBOUNCE_MS) {
      return null;
    }

    if (!carrito || carrito.length === 0) {
      totalNetoActual = 0;
      return { total_neto: 0, total_bruto: 0, descuento_total: 0 };
    }

    sincronizandoConServidor = true;
    ultimaSincronizacion = ahora;

    try {
      // ✅ FIX v2.1.2: Incluir precio manual para que el server lo respete
      const itemsParaServidor = carrito.map((i) => ({
        id: Number(i.id),
        cantidad: Number(i.cantidad),
        precio: Number(i.precio) || Number(i.precioLista) || 0, // precio manual si existe
      }));

      const fd = new FormData();
      fd.append("csrf_token", getCsrf());
      fd.append("items", JSON.stringify(itemsParaServidor));

      // ✅ FIX v2.1.3: Solo enviar desc_global si tiene permiso
      // Esto es doble validación (el backend también rechaza)
      if (descGlobal && CAN_MOD_PRECIO) {
        fd.append("desc_global", JSON.stringify(descGlobal));
      }

      const response = await fetchJson(`${API_BASE}?action=calcular_carrito`, {
        method: "POST",
        body: fd,
      });

      if (response.ok && Array.isArray(response.items)) {
        // Actualizar carrito con precios del servidor
        response.items.forEach((serverItem, idx) => {
          const localItem = carrito.find(
            (c) => Number(c.id) === Number(serverItem.producto_id),
          );
          if (localItem) {
            // Actualizar con datos del servidor
            localItem.subtotalServidor = serverItem.subtotal || serverItem.neto;
            localItem.descuentoServidor = serverItem.descuento || 0;
            localItem.promoServidor = serverItem.promo || "";
          }
        });

        // Actualizar total con lo que dice el servidor
        totalNetoActual = Number(response.total_neto) || 0;

        return {
          total_neto: response.total_neto,
          total_bruto: response.total_bruto,
          descuento_total: response.descuento_total,
          items: response.items,
        };
      }
    } catch (e) {
      console.warn(
        "sincronizarCarritoConServidor error (usando cálculo local):",
        e,
      );
      // En caso de error, el cálculo local sigue funcionando como fallback
    } finally {
      sincronizandoConServidor = false;
    }

    return null;
  }

  // Sincronización en background cuando el carrito cambia
  const sincronizarEnBackground = debounce(async () => {
    if (carrito.length > 0) {
      // ✅ FIX v2.1.1: Guardar el total local ANTES de sincronizar
      const totalLocalAntes = Number(totalNetoActual) || 0;

      const result = await sincronizarCarritoConServidor();
      if (result && result.total_neto !== undefined) {
        // Comparar con el total local PREVIO (no el actual, que ya fue actualizado)
        const diff = Math.abs(result.total_neto - totalLocalAntes);
        if (diff > 0.01) {
          console.log(
            `Precios sincronizados: local=${totalLocalAntes} → servidor=${result.total_neto}`,
          );
          // Actualizar labels sin recalcular
          if (lblTotal)
            lblTotal.textContent = formatearMoneda(result.total_neto);
          if (lblTotalBruto)
            lblTotalBruto.textContent = formatearMoneda(
              result.total_bruto || 0,
            );
          recalcularVuelto();
        }
      }
    }
  }, 500);

  // =========================
  // RENDER (con debounce)
  // =========================
  function _actualizarVista() {
    if (!tbodyTicket) return;
    tbodyTicket.innerHTML = "";

    const combos = aplicarCombos(carrito);

    let totalBruto = 0;
    let totalNeto = 0;
    let totalDescCombos = 0;

    // ✅ Helpers UI (solo para mostrar, NO cambia cálculos internos)
    function unidadPrecioSuffix(u, esPesable) {
      u = String(u || "").toUpperCase();
      if (!esPesable) return "";
      if (u === "G") return " / 100 g";
      if (u === "ML") return " / 100 ml";
      if (u === "KG") return " / kg";
      if (u === "LT") return " / lt";
      return ` / ${u}`;
    }

    function formatearCantidadUI(item) {
      const cant = Number(item.cantidad) || 0;
      const esPesable = !!item.esPesable;
      const u = String(
        item.unidadVenta || (esPesable ? "KG" : "UNID"),
      ).toUpperCase();
      let texto = "";

      if (!esPesable) {
        const entero = Math.max(0, Math.round(cant));
        texto = `${entero} UNID`;
      } else if (u === "G") {
        const gramos = Math.round(cant * 100);
        texto = `${fmtInt0.format(gramos)} g`;
      } else if (u === "ML") {
        const ml = Math.round(cant * 100);
        texto = `${fmtInt0.format(ml)} ml`;
      } else if (u === "KG") {
        const kg = cant;
        const kgInt = Math.floor(kg + 1e-9);
        let g = Math.round((kg - kgInt) * 1000);

        if (g >= 1000) {
          g -= 1000;
        } // ajuste por redondeo

        if (kgInt <= 0) texto = `${fmtInt0.format(Math.max(g, 0))} g`;
        else if (g <= 0) texto = `${kgInt} kg`;
        else texto = `${kgInt} kg ${fmtInt0.format(g)} g`;
      } else if (u === "LT") {
        const lt = cant;
        const ltInt = Math.floor(lt + 1e-9);
        let ml = Math.round((lt - ltInt) * 1000);

        if (ml >= 1000) {
          ml -= 1000;
        } // ajuste por redondeo

        if (ltInt <= 0) texto = `${fmtInt0.format(Math.max(ml, 0))} ml`;
        else if (ml <= 0) texto = `${ltInt} l`;
        else texto = `${ltInt} l ${fmtInt0.format(ml)} ml`;
      } else {
        texto = `${fmtQty3.format(cant)} ${u}`;
      }

      return `<span data-editable="1" title="Doble click para editar cantidad">${texto}</span>`;
    }

    // descuento combos (como antes): sumaLista - precio_combo
    combos.forEach((cb) => {
      const sumaLista = cb.combo.items.reduce((acc, it) => {
        const prod = carrito.find((p) => Number(p.id) === it.producto_id);
        if (!prod) return acc;
        return acc + (Number(prod.precioLista) || 0) * it.cantidad;
      }, 0);

      const descuentoUnit = sumaLista - cb.combo.precio_combo;
      cb.descuento = descuentoUnit * cb.cantidad;
      totalDescCombos += cb.descuento;
    });

    carrito.forEach((item, idx) => {
      const cant = Number(item.cantidad);
      const lista = Number(item.precioLista) || 0;
      const base = Number(item.precio) || 0;

      const subtotalOriginal = cant * lista;
      let subtotalConPromo = cant * base;

      const promo = aplicarPromosItem(item);
      let descuentoPromo = 0;
      let descNombre = null;

      // si hay promo: ignora descuento manual (como backend)
      if (promo) {
        subtotalConPromo = promo.subtotalFinal;
        descuentoPromo = promo.descuento;
        descNombre = promo.descripcion;
      }

      totalBruto += subtotalOriginal;
      totalNeto += subtotalConPromo;

      const tieneDescManual = !promo && Math.abs(base - lista) > 0.009;

      const u = String(
        item.unidadVenta || (item.esPesable ? "KG" : "UNID"),
      ).toUpperCase();
      const suf = unidadPrecioSuffix(u, !!item.esPesable);

      const precioHtml = tieneDescManual
        ? `<div>${formatearMoneda(base)}${suf}</div>
            <div class="precio-lista">Lista: ${formatearMoneda(lista)}${suf}</div>`
        : `${formatearMoneda(promo ? lista : base)}${suf}`;

      // ✅ Si no tiene permiso, no mostrar botón Desc.
      const btnDescHtml = CAN_MOD_PRECIO
        ? `<button class="btn-accion btn-desc" data-idx="${idx}">Desc.</button>`
        : "";

      const tr = document.createElement("tr");
      tr.dataset.idx = String(idx);
      tr.innerHTML = `
          <td>${idx + 1}</td>
          <td>${item.codigo}</td>
          <td>${item.nombre}</td>
          <td class="center col-cant">${formatearCantidadUI(item)}</td>
          <td class="right col-precio">${precioHtml}</td>
          <td class="right col-subtotal">${formatearMoneda(subtotalConPromo)}</td>
          <td class="acciones">
            ${btnDescHtml}
            <button class="btn-accion btn-quitar" data-idx="${idx}">Quitar</button>
          </td>
        `;
      tbodyTicket.appendChild(tr);

      if (descNombre) {
        const trPromo = document.createElement("tr");
        trPromo.innerHTML = `
            <td colspan="7" class="promo-aplicada">
              Promo: ${descNombre} → -${formatearMoneda(descuentoPromo)}
            </td>`;
        tbodyTicket.appendChild(trPromo);
      }
    });

    if (combos.length > 0) {
      combos.forEach((cb) => {
        const tr = document.createElement("tr");
        tr.innerHTML = `
            <td colspan="7" class="promo-aplicada">
              Combo aplicado: ${cb.combo.nombre} x${cb.cantidad}
              → -${formatearMoneda(cb.descuento)}
            </td>`;
        tbodyTicket.appendChild(tr);
      });
      totalNeto -= totalDescCombos;
    }

    // normalizar
    totalNeto = Math.max(0, Number(totalNeto.toFixed(2)));

    // descuento global al final
    const descG = Number(calcDescGlobal(totalNeto).toFixed(2));
    if (descG > 0) {
      const tr = document.createElement("tr");
      tr.innerHTML = `
          <td colspan="7" class="promo-aplicada">
            Descuento global → -${formatearMoneda(descG)}
          </td>`;
      tbodyTicket.appendChild(tr);

      totalNeto = Math.max(0, Number((totalNeto - descG).toFixed(2)));
    }

    lblTotalBruto.textContent = formatearMoneda(totalBruto);
    lblTotal.textContent = formatearMoneda(totalNeto);
    lblDescGlobal.textContent = formatearMoneda(
      Math.max(0, totalBruto - totalNeto),
    );

    if (ticketStatusLabel) {
      if (carrito.length === 0) {
        ticketStatusLabel.textContent = "Sin productos cargados";
      } else {
        const items = carrito.length;
        ticketStatusLabel.textContent = `${items} item${items !== 1 ? "s" : ""} · ${formatearMoneda(totalNeto)}`;
      }
    }

    totalNetoActual = totalNeto;

    ajustarPagoSegunMedio();
    recalcularVuelto();
    actualizarKpisLive();
    guardarEstado();
  }

  // ✅ Debounced version para inputs rápidos
  const actualizarVista = debounce(_actualizarVista, 150);

  // Para cambios que necesitan render inmediato (agregar/quitar)
  function actualizarVistaInmediata() {
    _actualizarVista();
  }

  // =========================
  // AGREGAR ITEM
  // =========================
  async function agregarItem() {
    const codigo = (inputCodigo?.value || "").trim();
    if (!codigo) return;

    // ✅ SMART: permitir escribir nombre (o parte) y resolver el producto más relevante
    async function resolverProductoPorTexto(q) {
      // Si viene muy corto, no buscamos
      if (!q || q.trim().length < 2) return null;
      const r = await fetchJson(
        `${API_BASE}?action=buscar_productos&q=${encodeURIComponent(q)}&limit=8`,
      );
      if (!r?.ok || !Array.isArray(r.productos) || r.productos.length === 0)
        return null;
      // Tomamos el primer match (API ya viene ordenada por relevancia)
      return r.productos[0] || null;
    }

    try {
      let data = null;
      try {
        data = await fetchJson(
          `${API_BASE}?action=buscar_producto&codigo=${encodeURIComponent(codigo)}`,
        );
      } catch (e) {
        // Si no encontró por código exacto / nombre exacto, intentamos por autocompletado
        const msg = String(e?.message || "").toLowerCase();
        const pareceNoEncontrado =
          msg.includes("no encontrado") ||
          msg.includes("inactivo") ||
          msg.includes("404");
        const tieneLetras = /[a-záéíóúñü]/i.test(codigo);

        if (pareceNoEncontrado || tieneLetras) {
          const top = await resolverProductoPorTexto(codigo);
          if (top?.codigo) {
            // Reintentar con el código real
            data = await fetchJson(
              `${API_BASE}?action=buscar_producto&codigo=${encodeURIComponent(top.codigo)}`,
            );
          } else {
            throw e;
          }
        } else {
          throw e;
        }
      }

      if (!data || !data.ok)
        return mostrarMensaje(
          "error",
          data?.error || "Error al buscar producto",
        );

      const p = data.producto;

      const precioLista = Number(p.precio) || 0;
      const stock = Number(p.stock) || 0;
      const esPesable =
        p.es_pesable === true || p.es_pesable === 1 || p.es_pesable === "1";
      const unidadVenta = p.unidad_venta || (esPesable ? "KG" : "UNID");

      // ✅ Hint para el cajero (según unidad)
      aplicarHintCantidadInput(unidadVenta);

      let cantidad = cantidadInternaDesdeInput(
        inputCant?.value || "1",
        unidadVenta,
        esPesable,
      );

      if (!Number.isFinite(cantidad) || cantidad <= 0) {
        // defaults “seguros”
        if (esPesable && (unidadVenta === "G" || unidadVenta === "ML"))
          cantidad = 1; // 1 pack = 100g/100ml
        else if (esPesable)
          cantidad = 0.1; // 0.1 kg/lt
        else cantidad = 1;
      }

      const existente = carrito.find((i) => Number(i.id) === Number(p.id));
      const enCarrito = existente ? Number(existente.cantidad) : 0;

      // ✅ FIX STOCK: ya no dependemos de "stock > 0"
      // (si stock=0, no deja agregar)
      const tolStock = esPesable ? 0.01 : 0;
      if (enCarrito + cantidad > stock + tolStock) {
        const disponible = stock - enCarrito;
        if (disponible > 0) {
          const _stockMsg =
            `<p style="margin:6px 0">Pediste <strong style="color:var(--danger,#ef4444)">${cantidad} ${unidadVenta}</strong>, ` +
            `solo hay <strong style="color:#fbbf24">${disponible} ${unidadVenta}</strong>.</p>` +
            `<p style="color:var(--muted,#94a3b8);font-size:.88rem;margin-top:8px">¿Agregamos lo disponible?</p>`;
          const agregar = await Notif.confirmar(
            "⚠️ Stock insuficiente",
            _stockMsg,
            {
              icon: "warning",
              confirmText: `✅ Agregar ${disponible}`,
              cancelText: "❌ Cancelar",
            },
          );
          if (agregar) {
            cantidad = disponible;
          } else {
            return;
          }
        } else {
          return mostrarMensaje(
            "error",
            `No hay stock disponible de "${p.nombre}"`,
          );
        }
      }

      if (existente) {
        existente.cantidad = Number(existente.cantidad) + cantidad;

        // ✅ refrescar stock por si cambió o por si antes no estaba
        existente.stock = Number(stock);
      } else {
        carrito.push({
          id: Number(p.id),
          codigo: String(p.codigo),
          nombre: String(p.nombre),
          cantidad: Number(cantidad),
          precio: Number(precioLista),
          precioLista: Number(precioLista),
          esPesable,
          unidadVenta,

          // ✅ CLAVE para validar al editar
          stock: Number(stock),
        });
      }

      inputCodigo.value = "";

      // ✅ FIX dropdown: al limpiar por JS, disparar input para cancelar debounce/fetch y ocultar sugerencias
      try {
        inputCodigo.dispatchEvent(new Event("input", { bubbles: true }));
      } catch (_) {}

      // ✅ FIX: no dejar clavado 0.100
      if (inputCant) inputCant.value = "1";

      limpiarMensaje();
      actualizarVistaInmediata();
      inputCodigo?.focus?.();
    } catch (e) {
      console.error("ERROR agregarItem():", e);
      mostrarMensaje("error", e?.message || "Error al buscar producto");
    }
  }

  // =========================
  // MODAL (genérico)
  // =========================
  function mostrarModal(opt) {
    return new Promise((resolve) => {
      modalResolver = resolve;
      modalIsInput = !!opt.input;
      modalCurrentItem = opt.item || null; // Guardar item para validación

      modalTitulo.textContent = opt.titulo || "";
      modalTexto.textContent = opt.texto || "";

      // Limpiar alerta de stock
      if (modalStockAlert) {
        modalStockAlert.classList.add("hidden");
        modalStockAlert.innerHTML = "";
      }

      if (modalDescTipo) {
        if (opt.showTipo) modalDescTipo.classList.remove("hidden");
        else modalDescTipo.classList.add("hidden");

        if (typeof opt.tipoDefault === "string")
          modalDescTipo.value = opt.tipoDefault;

        if (optPrecio) {
          optPrecio.hidden = !!opt.hidePrecioOption;
          optPrecio.textContent = opt.precioLabel || "Nuevo precio unitario";
        }
      }

      if (modalIsInput) {
        modalInputArea.classList.remove("hidden");
        modalLabel.textContent = opt.label || "";

        modalInput.type = opt.inputType || "number";
        if (opt.min != null) modalInput.min = String(opt.min);
        if (opt.step != null) modalInput.step = String(opt.step);

        modalInput.value = opt.valorDefault ?? "";

        // Mostrar info de stock si es edición de cantidad
        if (modalCurrentItem && opt.showStockInfo) {
          const stock = Number(modalCurrentItem.stock) || 0;
          const unidad =
            modalCurrentItem.unidadVenta ||
            (modalCurrentItem.esPesable ? "KG" : "UNID");
          const stockTxt = modalCurrentItem.esPesable
            ? fmtQty3.format(stock)
            : String(Math.round(stock));

          // Crear info de stock debajo del input
          let stockInfoEl = document.getElementById("modal-stock-info-temp");
          if (!stockInfoEl) {
            stockInfoEl = document.createElement("div");
            stockInfoEl.id = "modal-stock-info-temp";
            stockInfoEl.className = "modal-stock-info";
            modalInput.parentNode.insertBefore(
              stockInfoEl,
              modalInput.nextSibling,
            );
          }
          stockInfoEl.innerHTML = `Stock disponible: <strong>${stockTxt} ${unidad}</strong>`;
          stockInfoEl.style.display = "";
        } else {
          const stockInfoEl = document.getElementById("modal-stock-info-temp");
          if (stockInfoEl) stockInfoEl.style.display = "none";
        }

        setTimeout(() => modalInput.focus(), 20);
      } else {
        modalInputArea.classList.add("hidden");
      }

      modal.classList.remove("hidden");
    });
  }

  // Validar stock en tiempo real dentro del modal
  function validarStockEnModal() {
    if (!modalCurrentItem || !modalStockAlert) return;

    const hasStock =
      modalCurrentItem.stock != null && modalCurrentItem.stock !== "";
    if (!hasStock) return;

    const stock = Number(modalCurrentItem.stock) || 0;
    const unidad =
      modalCurrentItem.unidadVenta ||
      (modalCurrentItem.esPesable ? "KG" : "UNID");
    const tol = modalCurrentItem.esPesable ? 0.01 : 0;

    let num;
    const val = modalInput.value;

    if (modalCurrentItem.esPesable) {
      num = parseCantPesableFlex(val, unidad);
    } else {
      num = parseFloat(String(val).replace(",", "."));
      if (Number.isFinite(num)) num = Math.round(num);
    }

    if (!Number.isFinite(num) || num <= 0) {
      modalStockAlert.classList.add("hidden");
      return;
    }

    const stockTxt = modalCurrentItem.esPesable
      ? fmtQty3.format(stock)
      : String(Math.round(stock));

    if (num > stock + tol) {
      // Stock insuficiente - mostrar error
      modalStockAlert.className = "modal-stock-alert modal-stock-alert--error";
      modalStockAlert.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
          <line x1="12" y1="9" x2="12" y2="13"/>
          <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        <span>¡Stock insuficiente! Máximo disponible: <strong>${stockTxt} ${unidad}</strong></span>
      `;
      modalStockAlert.classList.remove("hidden");

      // Deshabilitar botón confirmar
      if (btnConfirm) {
        btnConfirm.disabled = true;
        btnConfirm.style.opacity = "0.5";
      }
    } else if (num === stock) {
      // Usando todo el stock - warning
      modalStockAlert.className =
        "modal-stock-alert modal-stock-alert--warning";
      modalStockAlert.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/>
          <line x1="12" y1="8" x2="12" y2="12"/>
          <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <span>Usarás todo el stock disponible (${stockTxt} ${unidad})</span>
      `;
      modalStockAlert.classList.remove("hidden");

      // Habilitar botón
      if (btnConfirm) {
        btnConfirm.disabled = false;
        btnConfirm.style.opacity = "";
      }
    } else {
      // Stock OK
      modalStockAlert.classList.add("hidden");
      if (btnConfirm) {
        btnConfirm.disabled = false;
        btnConfirm.style.opacity = "";
      }
    }
  }

  // Event listener para validación en tiempo real
  modalInput?.addEventListener("input", validarStockEnModal);

  function cerrarModal(v) {
    modal.classList.add("hidden");
    if (modalResolver) modalResolver(v);
    modalResolver = null;
    modalIsInput = false;
    modalCurrentItem = null;

    // Limpiar alerta y restaurar botón
    if (modalStockAlert) {
      modalStockAlert.classList.add("hidden");
    }
    if (btnConfirm) {
      btnConfirm.disabled = false;
      btnConfirm.style.opacity = "";
    }
  }

  btnConfirm?.addEventListener("click", () => {
    if (modalIsInput) cerrarModal(modalInput.value);
    else cerrarModal(true);
  });
  btnCancel?.addEventListener("click", () => cerrarModal(false));

  document.addEventListener("keydown", (e) => {
    if (!modal.classList.contains("hidden") && e.key === "Escape")
      cerrarModal(false);
  });
  // ✅ Parse decimal tolerante: "3,373" | "3.373" | "1.234,567"
  function parseDecimalFlex(raw) {
    let s = String(raw ?? "").trim();
    if (!s) return NaN;

    s = s.replace(/\s+/g, "");

    const lastComma = s.lastIndexOf(",");
    const lastDot = s.lastIndexOf(".");

    // si hay ambos, el último es decimal y el otro miles
    if (lastComma > -1 && lastDot > -1) {
      const dec = lastComma > lastDot ? "," : ".";
      const parts = s.split(dec);
      const intPart = parts.slice(0, -1).join(dec).replace(/[.,]/g, "");
      const fracPart = parts[parts.length - 1].replace(/[.,]/g, "");
      return parseFloat(intPart + "." + fracPart);
    }

    // si hay uno solo, lo tratamos como decimal
    if (lastComma > -1)
      return parseFloat(s.replace(/\./g, "").replace(",", "."));
    if (lastDot > -1) return parseFloat(s.replace(/,/g, "")); // deja el punto como decimal

    return parseFloat(s);
  }

  // ✅ Cantidad pesable flexible según unidad (KG/LT/G(100g)/ML(100ml))
  // - admite sufijos: g, kg, ml, l/lt
  // - si unidad es G o ML y no hay sufijo: 3 = "3x100" ; 300 = "300g/ml" (heurística)
  function parseCantPesableFlex(raw, unidadVenta) {
    let s = String(raw ?? "")
      .trim()
      .toLowerCase();
    if (!s) return NaN;
    s = s.replace(/\s+/g, "");

    const m = s.match(/^([0-9.,]+)(kg|g|lt|l|ml)?$/i);
    if (!m) return NaN;

    const n = parseDecimalFlex(m[1]);
    if (!Number.isFinite(n)) return NaN;

    const suf = (m[2] || "").toLowerCase();
    const u = String(unidadVenta || "KG").toUpperCase();

    // Sin sufijo: interpretar según unidad
    // Sin sufijo: interpretar según unidad
    if (!suf) {
      if (u === "G") return n >= 20 ? n / 100 : n; // 300 => 3 (x100g)
      if (u === "ML") return n >= 20 ? n / 100 : n; // 800 => 8 (x100ml)

      if (u === "KG") return n >= 50 ? n / 1000 : n; // 3373 => 3.373 kg
      if (u === "LT") return n >= 50 ? n / 1000 : n; // 700  => 0.700 lt

      return n;
    }

    // Con sufijo: convertir a tu unidad interna
    if (suf === "g") {
      if (u === "KG") return n / 1000;
      if (u === "G") return n / 100;
      return n;
    }
    if (suf === "kg") {
      if (u === "G") return (n * 1000) / 100; // kg -> (100g)
      return n;
    }
    if (suf === "ml") {
      if (u === "LT") return n / 1000;
      if (u === "ML") return n / 100;
      return n;
    }
    if (suf === "l" || suf === "lt") {
      if (u === "ML") return (n * 1000) / 100; // litros -> (100ml)
      return n;
    }

    return n;
  }

  // =========================
  // EDITAR / QUITAR / DESCUENTO ITEM
  // =========================
  function abrirEditorCantidad(idx) {
    const item = carrito[idx];
    if (!item) return;

    const unidad = item.unidadVenta || (item.esPesable ? "KG" : "UNID");
    const step = item.esPesable ? "0.001" : "1";
    const min = item.esPesable ? "0.001" : "1";

    const hint = item.esPesable
      ? unidad === "KG"
        ? "Ej: 3,373  |  3.373  |  3373g"
        : unidad === "LT"
          ? "Ej: 0,700  |  0.7  |  700ml"
          : unidad === "G"
            ? "Ej: 3 (x100g)  |  300  |  300g"
            : unidad === "ML"
              ? "Ej: 8 (x100ml) | 800  | 800ml"
              : ""
      : "";

    mostrarModal({
      titulo: "Editar cantidad",
      texto: hint ? `${item.nombre}\n${hint}` : item.nombre,
      input: true,
      valorDefault: item.esPesable
        ? String(cantidadHumanaDesdeInterna(item.cantidad, unidad, true))
        : item.cantidad,

      inputType: item.esPesable ? "text" : "number",
      min,
      step,

      item: item,
      showStockInfo: true,
    }).then((val) => {
      if (val === false) return;

      let num;

      if (item.esPesable) {
        num = parseCantPesableFlex(val, unidad);
      } else {
        num = parseFloat(String(val).replace(",", "."));
        if (Number.isFinite(num)) num = Math.round(num);
      }

      if (!Number.isFinite(num)) return;

      if (num <= 0) {
        carrito.splice(idx, 1);
        actualizarVistaInmediata();
        return;
      }

      const hasStock = item.stock != null && item.stock !== "";
      if (hasStock) {
        const stock = Number(item.stock) || 0;
        const tol = item.esPesable ? 0.01 : 0;

        if (num > stock + tol) {
          const maxTxt = item.esPesable
            ? fmtQty3.format(stock)
            : String(Math.round(stock));
          mostrarMensaje(
            "error",
            `Stock insuficiente. Máximo: ${maxTxt} ${unidad}`,
          );
          num = stock;
        }

        if (num <= 0) {
          carrito.splice(idx, 1);
          actualizarVistaInmediata();
          return;
        }
      }

      item.cantidad = num;
      actualizarVistaInmediata();
    });
  }

  tbodyTicket?.addEventListener("click", (e) => {
    const btnEditar = e.target.closest(".btn-editar");
    const btnQuitar = e.target.closest(".btn-quitar");
    const btnDesc = e.target.closest(".btn-desc");

    // -------------------------
    // EDITAR CANTIDAD
    // -------------------------
    if (btnEditar) {
      const idx = Number(btnEditar.dataset.idx);
      abrirEditorCantidad(idx);
      return;
    }

    // -------------------------
    // DESCUENTO / CAMBIAR PRECIO
    // -------------------------
    if (btnDesc) {
      if (!CAN_MOD_PRECIO) {
        mostrarMensaje(
          "error",
          "No tenés permisos para aplicar descuento manual / cambiar precio.",
        );
        return;
      }

      const idx = Number(btnDesc.dataset.idx);
      const item = carrito[idx];
      if (!item) return;

      // Aviso si hay promo activa (igual el backend pisa el precio manual)
      if (promosPorProducto[String(item.id)]) {
        mostrarMensaje(
          "warning",
          "⚠️ Este producto tiene promoción activa. El descuento manual no aplicará.",
        );
      }

      if (modalDescTipo) {
        modalDescTipo.onchange = () => {
          const t = modalDescTipo.value;
          if (t === "porcentaje") modalLabel.textContent = "% de descuento";
          else if (t === "monto") modalLabel.textContent = "Descuento en $";
          else modalLabel.textContent = "Nuevo precio unitario";
        };
      }

      mostrarModal({
        titulo: "Descuento manual",
        texto: item.nombre,
        input: true,
        valorDefault: item.precio,
        label: "Nuevo precio unitario",
        showTipo: true,
        tipoDefault: "precio",
        hidePrecioOption: false,
        precioLabel: "Nuevo precio unitario",
        inputType: "number",
        min: 0,
        step: 0.01,
      }).then((val) => {
        if (val === false) return;

        const tipo = modalDescTipo?.value || "precio";
        let num = parseFloat(String(val).replace(",", "."));
        if (!Number.isFinite(num)) {
          mostrarMensaje("error", "Valor inválido.");
          return;
        }

        const lista = Number(item.precioLista) || 0;
        let nuevo = Number(item.precio) || lista;

        if (tipo === "precio") {
          nuevo = num;
        } else if (tipo === "porcentaje") {
          if (num < 0 || num > 100) {
            mostrarMensaje("error", "Porcentaje inválido (0-100).");
            return;
          }
          nuevo = lista * (1 - num / 100);
        } else if (tipo === "monto") {
          if (num < 0) {
            mostrarMensaje("error", "Monto inválido.");
            return;
          }
          nuevo = lista - num;
        }

        if (!Number.isFinite(nuevo) || nuevo <= 0) {
          mostrarMensaje("error", "El precio final queda inválido o negativo.");
          return;
        }

        item.precio = Number(nuevo.toFixed(2));
        actualizarVistaInmediata();
      });

      return;
    }

    // -------------------------
    // QUITAR ITEM
    // -------------------------
    if (btnQuitar) {
      const idx = Number(btnQuitar.dataset.idx);
      if (!Number.isFinite(idx)) return;
      carrito.splice(idx, 1);
      actualizarVistaInmediata();
      return;
    }
  });

  // =========================
  // DESCUENTO GLOBAL (botón "Cambiar")
  // =========================
  btnDescGlobal?.addEventListener("click", () => {
    // ✅ Permiso: bloquear
    if (!CAN_MOD_PRECIO) {
      mostrarMensaje(
        "error",
        "No tenés permisos para aplicar descuento global.",
      );
      return;
    }

    // Global: solo porcentaje / monto (ocultamos "precio")
    if (modalDescTipo) {
      modalDescTipo.onchange = () => {
        const t = modalDescTipo.value;
        if (t === "porcentaje") modalLabel.textContent = "% de descuento";
        else modalLabel.textContent = "Descuento en $";
      };
    }

    const tipoInit = descGlobal?.tipo || "monto";
    const valorInit = descGlobal?.valor || "";

    mostrarModal({
      titulo: "Descuento total",
      texto: "Aplicar descuento al total final (después de promos/combos).",
      input: true,
      valorDefault: valorInit,
      label: tipoInit === "porcentaje" ? "% de descuento" : "Descuento en $",
      showTipo: true,
      tipoDefault: tipoInit,
      hidePrecioOption: true,
      inputType: "number",
      min: 0,
      step: 0.01,
    }).then((val) => {
      if (val === false) return;

      const tipo =
        modalDescTipo?.value === "porcentaje" ? "porcentaje" : "monto";
      let num = parseFloat(String(val).replace(",", "."));
      if (!Number.isFinite(num) || num < 0) num = 0;

      if (tipo === "porcentaje" && num > 100) {
        return mostrarMensaje("error", "Máximo 100%.");
      }

      if (tipo === "monto" && num > totalNetoActual) {
        return mostrarMensaje(
          "error",
          `El descuento no puede superar el total (${formatearMoneda(
            totalNetoActual,
          )})`,
        );
      }

      if (num <= 0.0001) {
        descGlobal = null; // limpiar
      } else {
        descGlobal = { tipo, valor: Number(num.toFixed(2)) };
      }

      actualizarVistaInmediata();
    });
  });

  // =========================
  // COBRAR
  // =========================
  let cobrando = false;

  async function cobrar() {
    limpiarMensaje();

    if (cobrando) return;
    if (!carrito || carrito.length === 0)
      return mostrarMensaje("error", "Ticket vacío");

    cobrando = true;
    const btn = document.getElementById("btnCobrar");
    if (btn) {
      btn.disabled = true;
      btn.textContent = "Procesando...";
    }

    try {
      // ✅ FIX v2.1.1: Sincronizar con servidor ANTES de cobrar
      // El servidor es la FUENTE DE VERDAD para los precios
      mostrarMensaje("info", "Verificando precios...");
      const syncResult = await sincronizarCarritoConServidor(true);

      // ✅ IMPORTANTE: Guardamos el total del servidor en variable separada
      // para evitar que se pise con cálculos locales
      let totalConfirmado = Number(totalNetoActual) || 0;

      if (syncResult && syncResult.total_neto !== undefined) {
        totalConfirmado = Number(syncResult.total_neto) || 0;

        // Actualizar UI sin recalcular (solo mostrar)
        if (lblTotal) lblTotal.textContent = formatearMoneda(totalConfirmado);
        if (lblTotalBruto)
          lblTotalBruto.textContent = formatearMoneda(
            Number(syncResult.total_bruto) || 0,
          );
        if (lblDescGlobal)
          lblDescGlobal.textContent = formatearMoneda(
            Number(syncResult.descuento_total) || 0,
          );

        // Guardar el total confirmado
        totalNetoActual = totalConfirmado;
      }

      limpiarMensaje();

      // ✅ Usar totalConfirmado (no totalNetoActual que podría pisarse)
      const totalUI = totalConfirmado;

      // Ayuda UX: si es 1 solo medio y NO es efectivo, el monto debe ser exacto
      if (!splitActivo() && !medioEsEfectivo() && inputPagado) {
        inputPagado.value = String(Number(totalUI).toFixed(2));
      }

      const pagos = pagosDesdeUI();
      const totalPag = totalPagado(pagos);

      if (!pagos || pagos.length === 0) {
        return mostrarMensaje("error", "Ingresá el pago");
      }

      if (totalPag + 0.01 < totalUI) {
        return mostrarMensaje("error", "El pago no alcanza");
      }

      // ✅ VALIDACIÓN CUENTA CORRIENTE
      if (tieneCC()) {
        const clienteCC = getClienteCC();
        if (!clienteCC || !clienteCC.id) {
          ccWrap?.classList.remove("is-hidden");
          ccInputBuscar?.focus?.();
          return mostrarMensaje(
            "error",
            "Seleccioná un cliente para la cuenta corriente",
          );
        }

        // Validar disponibilidad
        mostrarMensaje("info", "Verificando crédito disponible...");
        const validacionCC = await validarDisponibilidadCC();
        limpiarMensaje();

        if (!validacionCC.ok) {
          return mostrarMensaje(
            "error",
            validacionCC.error || "Error validando cuenta corriente",
          );
        }
      }

      const vuelto = Math.max(totalPag - totalUI, 0);
      const efectivo = efectivoPagado(pagos);

      // Si hay vuelto, tiene que salir de EFECTIVO
      if (vuelto > 0.009 && efectivo + 0.0001 < vuelto) {
        return mostrarMensaje(
          "error",
          "El vuelto supera el efectivo ingresado (agregá/ajustá EFECTIVO)",
        );
      }

      const itemsLimpios = carrito.map((i) => ({
        id: Number(i.id),
        cantidad: Number(i.cantidad),
        precio: Number(i.precio), // precio unitario actual (manual si aplicaste)
      }));

      const token = getCsrf();

      // Compat legacy: para no romper backend viejo, mandamos como medio_pago el del "Pago 1".
      const medioCompat = String(selMedio?.value || "EFECTIVO").toUpperCase();

      const payload = {
        csrf_token: token, // ✅ estándar
        csrf: token, // ✅ compat si algún endpoint viejo lee "csrf"
        caja_id: CAJA_ID,
        items: itemsLimpios,
        desc_global: descGlobal || null,

        // ✅ NUEVO: pagos múltiples
        pagos,

        // ✅ Compat para backend legacy / reportes
        medio_pago: medioCompat,
        monto_pagado: Number(totalPag.toFixed(2)),
      };

      const fd = new FormData();
      fd.append("csrf_token", token);
      fd.append("csrf", token); // compat
      fd.append("caja_id", String(CAJA_ID));
      fd.append("items", JSON.stringify(itemsLimpios));
      fd.append("desc_global", JSON.stringify(descGlobal || null));
      fd.append("pagos", JSON.stringify(pagos));
      fd.append("medio_pago", medioCompat);
      fd.append("monto_pagado", String(Number(totalPag.toFixed(2))));

      // ✅ Cliente para Cuenta Corriente
      if (tieneCC()) {
        const clienteCC = getClienteCC();
        if (clienteCC && clienteCC.id) {
          fd.append("cc_cliente_id", String(clienteCC.id));
        }
      }

      const data = await fetchJson(`${API_VENTA}?action=registrar_venta`, {
        method: "POST",
        body: fd,
      });

      if (!data?.ok)
        return mostrarMensaje("error", data?.error || "Error en la API");

      const ventaId = data.venta_id || data.id || data.ventaId;
      sumarKpisSesion(pagos, totalUI);

      // Limpiar estado UI
      carrito = [];
      descGlobal = null;
      limpiarEstadoPersistido();

      if (selMedio) selMedio.value = "EFECTIVO";
      if (inputPagado) inputPagado.value = "";
      setSplitActivo(false);

      // ✅ Limpiar cliente CC
      limpiarClienteCC();
      actualizarVisibilidadCC();

      ajustarPagoSegunMedio();
      actualizarVistaInmediata();
      recalcularVuelto();
      inputCodigo?.focus?.();

      dispatchTicketOutput(ventaId);

      // ✅ Mostrar mensaje con info de CC si corresponde
      let msgExtra = "";
      if (data.cc && data.cc.cliente_nombre) {
        msgExtra = ` · Cargado a CC de ${data.cc.cliente_nombre}`;
      }
      mostrarMensaje(
        "success",
        `✓ Venta #${ventaId} registrada correctamente${msgExtra}`,
      );
    } catch (e) {
      console.error("Error registrar venta:", e);
      mostrarMensaje("error", e?.message || "Error al registrar la venta");
    } finally {
      cobrando = false;
      if (btn) {
        btn.disabled = false;
        btn.textContent = "Cobrar";
      }
    }
  }

  // =========================
  // CANCELAR
  // =========================
  async function cancelarVenta() {
    if (carrito.length > 0) {
      const _n = carrito.length;
      const ok = await Notif.confirmar(
        "🗑️ Cancelar venta",
        `<p>Se eliminarán los <strong>${_n} producto${_n > 1 ? "s" : ""}</strong> del ticket.</p>` +
          `<p style="color:var(--muted,#94a3b8);font-size:.88rem;margin-top:6px">Esta acción no se puede deshacer.</p>`,
        {
          icon: "warning",
          confirmText: "🗑️ Sí, cancelar",
          cancelText: "Volver",
        },
      );
      if (!ok) return;
    }
    carrito = [];
    descGlobal = null;
    limpiarEstadoPersistido();
    if (inputPagado) inputPagado.value = "";

    // ✅ Limpiar cliente CC
    limpiarClienteCC();
    actualizarVisibilidadCC();

    actualizarVistaInmediata();
    inputCodigo?.focus?.();
  }

  // =========================
  // EVENTOS
  // =========================
  document.getElementById("btnAgregar")?.addEventListener("click", agregarItem);
  document.getElementById("btnCobrar")?.addEventListener("click", cobrar);
  btnCobrarExacto?.addEventListener("click", () => {
    if (splitActivo() || totalNetoActual <= 0) return;
    if (selMedio) selMedio.value = "EFECTIVO";
    if (inputPagado) inputPagado.value = String(Number(totalNetoActual).toFixed(2));
    actualizarVisibilidadCC();
    ajustarPagoSegunMedio();
    recalcularVuelto();
    cobrar();
  });
  document
    .getElementById("btnCancelar")
    ?.addEventListener("click", cancelarVenta);

  inputCodigo?.addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      // ✅ FIX: si el autocompletado manejó Enter, ya se agregó el item (evita doble carga)
      if (e.defaultPrevented) return;
      e.preventDefault();
      agregarItem();
    } else {
      limpiarMensaje();
    }
  });

  inputPagado?.addEventListener("input", () => {
    actualizarEstadoPagosUI("1");
    recalcularVuelto();
    guardarEstado();
  });

  inputPagado2?.addEventListener("input", () => {
    if (inputPagado2) inputPagado2.dataset.auto = "0"; // ✅ ya no autocompletar
    actualizarEstadoPagosUI("2");
    recalcularVuelto();
    guardarEstado();
  });

  selMedio?.addEventListener("change", () => {
    actualizarEstadoPagosUI("1");
    actualizarVisibilidadCC();
    ajustarPagoSegunMedio();
    recalcularVuelto();
    guardarEstado();
  });

  selMedio2?.addEventListener("change", () => {
    actualizarEstadoPagosUI("2");
    actualizarVisibilidadCC();
    ajustarPagoSegunMedio();
    recalcularVuelto();
    guardarEstado();
  });

  [selMedio, inputPagado].forEach((el) => {
    el?.addEventListener("focus", () => actualizarEstadoPagosUI("1"));
    el?.addEventListener("click", () => actualizarEstadoPagosUI("1"));
  });

  [selMedio2, inputPagado2, btnQuitarPago2].forEach((el) => {
    el?.addEventListener("focus", () => actualizarEstadoPagosUI("2"));
    el?.addEventListener("click", () => actualizarEstadoPagosUI("2"));
  });

  btnAgregarPago?.addEventListener("click", () => {
    setSplitActivo(true);

    // ✅ Si el medio 2 está en EFECTIVO (default), sugerimos MP (opcional pero re útil)
    if (selMedio2 && selMedio2.value === "EFECTIVO") selMedio2.value = "MP";

    // ✅ Autocompletar lo que falta en el pago 2
    if (inputPagado2) {
      const total = Number(totalNetoActual) || 0;
      const a1 = parseMonto(inputPagado?.value || "0");
      const falta2 = Math.max(total - a1, 0);
      inputPagado2.value = falta2 > 0 ? String(falta2.toFixed(2)) : "";
      inputPagado2.dataset.auto = "1";
    }

    inputPagado2?.focus?.();
    ajustarPagoSegunMedio();
    recalcularVuelto();
    guardarEstado();
  });

  btnQuitarPago2?.addEventListener("click", () => {
    setSplitActivo(false);
    ajustarPagoSegunMedio();
    recalcularVuelto();
    guardarEstado();
  });

  // Atajos
  document.addEventListener("keydown", (e) => {
    if (e.key === "F2") {
      e.preventDefault();
      cobrar();
    }
    if (e.key === "F4") {
      e.preventDefault();
      cancelarVenta();
    }
    if (e.key === "F5") {
      e.preventDefault();
      btnCobrarExacto?.click();
    }
  });

  window.addEventListener("beforeunload", (e) => {
    if (!Array.isArray(carrito) || carrito.length === 0) return;
    guardarEstado();
    e.preventDefault();
    e.returnValue = "";
    return "";
  });

  // =========================
  // INIT
  // =========================
  (async () => {
    initTicketOutputControls();
    cargarEstado();
    await cargarPromos();
    actualizarVistaInmediata();
    ajustarPagoSegunMedio();
    recalcularVuelto();
    actualizarEstadoPagosUI();
    if (estadoRecuperado) {
      mostrarMensaje(
        "info",
        "Se recupero un ticket pendiente de esta caja. Revisalo antes de cobrar.",
      );
    }
  })();

  // =========================================================================
  // ✅ MEJORAS UX v3.6 — bloque aditivo, no modifica funciones existentes
  // Solo se engancha a elementos DOM y eventos ya existentes.
  // Sin cambios a API, CSRF, lógica de negocio ni backend.
  // =========================================================================
  (function initMejorasUX() {

    // ── 1. CHIPS DE DENOMINACIÓN ────────────────────────────────────────────
    // Muestra billetes rápidos ($500/$1000/etc.) cuando el medio es EFECTIVO.
    // Al clickear, escribe en el pago efectivo activo y recalcula.
    (function initDenomChips() {
      const chips       = document.getElementById("denomChips");
      const pago1El     = document.getElementById("pago1Wrap");
      const selMedioEl  = document.getElementById("medioPago");
      const montoEl     = document.getElementById("montoPagado");
      const selMedio2El = document.getElementById("medioPago2");
      const monto2El    = document.getElementById("montoPagado2");
      const pago2El     = document.getElementById("pago2Wrap");
      const btnAddPago  = document.getElementById("btnAgregarPago");
      const btnRmPago2  = document.getElementById("btnQuitarPago2");
      if (!chips || !pago1El || !selMedioEl || !montoEl) return;

      let activeSlot = "1";

      function splitVisible() {
        return !!pago2El && !pago2El.classList.contains("is-hidden");
      }

      function medioEsEfectivoEl(el) {
        return String(el?.value || "").toUpperCase() === "EFECTIVO";
      }

      function cashTargets() {
        const targets = [];
        if (medioEsEfectivoEl(selMedioEl)) {
          targets.push({ slot: "1", input: montoEl, wrap: pago1El });
        }
        if (splitVisible() && medioEsEfectivoEl(selMedio2El) && monto2El) {
          targets.push({ slot: "2", input: monto2El, wrap: pago2El });
        }
        return targets;
      }

      function resolveTarget() {
        const targets = cashTargets();
        if (!targets.length) return null;
        return targets.find((t) => t.slot === activeSlot) || targets[0];
      }

      function syncVisibility() {
        const targets = cashTargets();
        chips.style.display = targets.length ? "flex" : "none";
        const target = resolveTarget();
        chips.dataset.targetSlot = target?.slot || "";
        if (target?.wrap && chips.parentElement !== target.wrap) {
          target.wrap.appendChild(chips);
        }
        actualizarEstadoPagosUI(activeSlot);
      }

      function markActive(slot) {
        activeSlot = slot === "2" ? "2" : "1";
        syncVisibility();
      }

      syncVisibility();
      selMedioEl.addEventListener("change", syncVisibility);
      selMedio2El?.addEventListener("change", syncVisibility);
      montoEl.addEventListener("focus", () => markActive("1"));
      monto2El?.addEventListener("focus", () => markActive("2"));
      selMedioEl.addEventListener("focus", () => markActive("1"));
      selMedio2El?.addEventListener("focus", () => markActive("2"));
      montoEl.addEventListener("click", () => markActive("1"));
      monto2El?.addEventListener("click", () => markActive("2"));
      btnAddPago?.addEventListener("click", () => setTimeout(syncVisibility, 0));
      btnRmPago2?.addEventListener("click", () => setTimeout(syncVisibility, 0));

      chips.addEventListener("click", (e) => {
        const chip = e.target.closest(".denom-chip");
        if (!chip) return;
        const monto = Number(chip.dataset.monto) || 0;
        if (!monto) return;
        const target = resolveTarget();
        if (!target?.input) return;
        target.input.value = String(monto.toFixed(2));
        if (target.slot === "2" && monto2El) {
          monto2El.dataset.auto = "0";
        }
        target.input.dispatchEvent(new Event("input", { bubbles: true }));
        target.input.focus();
      });
    })();

    // ── 2. OVERLAY DE VUELTO GRANDE ─────────────────────────────────────────
    // Captura el vuelto ANTES de que cobrar() lo resetee, luego lo muestra
    // en overlay de pantalla completa por unos segundos.
    // Usa phase=capture en btnCobrar para correr ANTES del listener existente.
    (function initVueltoOverlay() {
      const overlay   = document.getElementById("vueltoOverlayFlus");
      const amountEl  = document.getElementById("vueltoOverlayAmount");
      const subEl     = document.getElementById("vueltoOverlaySub");
      const closeBtn  = document.getElementById("vueltoOverlayClose");
      const msgEl     = document.getElementById("msg");
      if (!overlay || !amountEl || !msgEl) return;

      let vueltoCapturado = "";
      let autoCloseTimer  = null;

      function captureVuelto() {
        const lblV = document.getElementById("lblVuelto");
        const txt  = lblV ? lblV.textContent.trim() : "";
        // Solo guardar si hay vuelto real (no $0,00)
        vueltoCapturado = (txt && txt !== "$0,00" && txt !== "$0") ? txt : "";
      }

      function showOverlay(ventaLine) {
        if (!vueltoCapturado) return;
        amountEl.textContent = vueltoCapturado;
        if (subEl) subEl.textContent = ventaLine || "";
        overlay.classList.add("is-visible");
        overlay.setAttribute("aria-hidden", "false");
        clearTimeout(autoCloseTimer);
        autoCloseTimer = setTimeout(closeOverlay, 7000);
      }

      function closeOverlay() {
        clearTimeout(autoCloseTimer);
        overlay.classList.remove("is-visible");
        overlay.setAttribute("aria-hidden", "true");
        vueltoCapturado = "";
        // Devolver foco al input de código
        document.getElementById("codigo")?.focus();
      }

      // Capturar vuelto ANTES de que cobrar() lo resetee — fase capture
      ["btnCobrar", "btnCobrarExacto"].forEach((id) => {
        document.getElementById(id)?.addEventListener("click", captureVuelto, true);
      });

      // Detectar éxito de venta via MutationObserver en #msg
      new MutationObserver(() => {
        if (
          msgEl.classList.contains("msg-success") &&
          msgEl.classList.contains("msg-visible") &&
          msgEl.textContent.includes("Venta #")
        ) {
          showOverlay(msgEl.textContent.trim());
        }
      }).observe(msgEl, { attributes: true, attributeFilter: ["class"], childList: true });

      // Cerrar con botón, click en backdrop y Esc
      closeBtn?.addEventListener("click", closeOverlay);
      overlay.addEventListener("click", (e) => { if (e.target === overlay) closeOverlay(); });
      document.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && overlay.classList.contains("is-visible")) {
          e.preventDefault();
          closeOverlay();
        }
      });
    })();

    // ── 3. DOBLE CLICK EN CANTIDAD = EDITAR (sin nuevo modal) ──────────────
    // En vez de replicar lógica: simula click en .btn-editar de la misma fila.
    // Así reutiliza exactamente el mismo modal y validaciones de stock existentes.
    (function initDblClickCant() {
      const tbody = document.querySelector("#tabla tbody");
      if (!tbody) return;

      tbody.addEventListener("dblclick", (e) => {
        const td = e.target.closest(".col-cant");
        if (!td) return;
        const row = td.closest("tr");
        if (!row) return;
        const idx = Number(row.dataset.idx);
        if (Number.isFinite(idx)) {
          e.preventDefault();
          abrirEditorCantidad(idx);
        }
      });
    })();

    // ── 4. SNACK "DESHACER QUITAR" ──────────────────────────────────────────
    // Intercepta click en .btn-quitar via capture para guardar el ítem,
    // luego muestra un snack durante 4s con opción de deshacer.
    // Si el usuario no deshace, el ítem queda eliminado (comportamiento actual).
    (function initUndoQuitar() {
      const tbody        = document.querySelector("#tabla tbody");
      const snackCont    = document.getElementById("undoSnackContainer");
      if (!tbody || !snackCont) return;

      // Acceder al carrito vía closure del scope superior de caja.js
      // No es posible directamente (carrito es var local), así que usamos
      // un approach alternativo: interceptar click ANTES con capture, copiar
      // el texto de la fila para el snack, y dejar que el quitar ocurra.
      // El "deshacer" recarga via el API si el item ya tiene precio confirmado,
      // o simplemente re-agrega el producto al código de búsqueda.

      // Approach simple: mostrar el nombre del producto quitado + su codigo
      // para que el cajero pueda re-escanearlo si se equivocó.
      tbody.addEventListener("click", (e) => {
        const btnQuitar = e.target.closest(".btn-quitar");
        if (!btnQuitar) return;

        const row = btnQuitar.closest("tr");
        if (!row) return;

        // Leer nombre y código de la fila antes de que se elimine
        const cells = row.querySelectorAll("td");
        if (cells.length < 3) return;
        const nombre  = cells[2]?.textContent?.trim() || "Producto";
        const codigo  = cells[1]?.textContent?.trim() || "";

        // Esperar al siguiente tick para que el quitar ya ocurrió
        setTimeout(() => {
          mostrarUndoSnack(nombre, codigo);
        }, 50);
      });

      function mostrarUndoSnack(nombre, codigo) {
        const snack = document.createElement("div");
        snack.className = "undo-snack";

        const nombreCorto = nombre.length > 28
          ? nombre.slice(0, 27) + "…"
          : nombre;

        snack.innerHTML = `
          <span>Se quitó <strong>${nombreCorto}</strong></span>
          ${codigo ? `<button class="undo-snack__btn" data-codigo="${codigo}" title="Volver a agregar ${nombre}">Re-agregar</button>` : ""}
        `;

        snackCont.appendChild(snack);

        const btn = snack.querySelector(".undo-snack__btn");
        btn?.addEventListener("click", () => {
          // Poner el código en el input y disparar la búsqueda/agregar
          const codigoInput = document.getElementById("codigo");
          if (codigoInput && btn.dataset.codigo) {
            codigoInput.value = btn.dataset.codigo;
            document.getElementById("btnAgregar")?.click();
          }
          quitarSnack(snack);
        });

        // Auto-dismiss a los 4 segundos
        const timer = setTimeout(() => quitarSnack(snack), 4000);
        snack._timer = timer;
      }

      function quitarSnack(snack) {
        clearTimeout(snack._timer);
        snack.classList.add("is-leaving");
        setTimeout(() => snack.remove(), 220);
      }
    })();

    // ── 5. SONIDO DE FEEDBACK (AudioContext, sin assets externos) ───────────
    // beep breve al agregar producto, diferente al cobrar y al error.
    // Se activa solo tras interacción del usuario (requerimiento del browser).
    (function initSonidos() {
      let ctx = null;

      function getCtx() {
        if (!ctx) {
          try { ctx = new (window.AudioContext || window.webkitAudioContext)(); } catch(_) {}
        }
        return ctx;
      }

      function beep(freq, durMs, gainVal) {
        const c = getCtx();
        if (!c) return;
        try {
          const osc = c.createOscillator();
          const g   = c.createGain();
          osc.connect(g);
          g.connect(c.destination);
          osc.frequency.value = freq;
          osc.type = "sine";
          g.gain.setValueAtTime(gainVal || 0.12, c.currentTime);
          g.gain.exponentialRampToValueAtTime(0.001, c.currentTime + durMs / 1000);
          osc.start();
          osc.stop(c.currentTime + durMs / 1000);
        } catch(_) {}
      }

      // Desbloquear AudioContext en primer click de usuario
      document.addEventListener("click", () => getCtx(), { once: true });

      // Sonido al agregar producto: observar filas del tbody
      const tbody = document.querySelector("#tabla tbody");
      let prevRows = 0;
      new MutationObserver(() => {
        const curr = tbody?.querySelectorAll("tr:not(.promo-aplicada)").length || 0;
        if (curr > prevRows) beep(660, 80, 0.1); // item agregado
        prevRows = curr;
      }).observe(tbody || document.body, { childList: true });

      // Sonido al cobrar: mismo observer en #msg
      const msgEl = document.getElementById("msg");
      new MutationObserver(() => {
        if (msgEl?.classList.contains("msg-success") && msgEl?.textContent.includes("Venta #")) {
          beep(880, 90, 0.12);
          setTimeout(() => beep(1100, 80, 0.1), 100);
        }
        if (msgEl?.classList.contains("msg-error")) {
          beep(220, 180, 0.1);
        }
      }).observe(msgEl || document.body, { attributes: true, attributeFilter: ["class"] });
    })();

  })(); // fin initMejorasUX
});

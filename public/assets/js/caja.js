// public/assets/js/caja.js
document.addEventListener("DOMContentLoaded", () => {
  const API_BASE = "api/index.php";
  const API_VENTA = "api/index.php";
  const API_TIMEOUT_MS = 8000;  
  // FLUS: Sugerencias (buscar_productos) - no requiere tocar HTML
  (function initSugerenciasProductos() {
    const input = document.getElementById("codigo");
    if (!input) return;

    // datalist auto-creado
    let dl = document.getElementById("sugerencias");
    if (!dl) {
      dl = document.createElement("datalist");
      dl.id = "sugerencias";
      document.body.appendChild(dl);
    }
    input.setAttribute("list", "sugerencias");

    // escape para inyectar options seguro
    const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;"
    }[c]));

    // fallback fetchJson (si tu caja.js ya tiene fetchJson, no lo pisa)
    if (!window.fetchJson) {
      window.fetchJson = async (url, opts = {}) => {
        const r = await fetch(url, opts);
        const ct = r.headers.get("content-type") || "";
        const data = ct.includes("application/json") ? await r.json() : { ok: false, error: "NON_JSON" };
        if (!r.ok || data?.ok === false) throw data;
        return data;
      };
    }

    let abort = null;

    const doSuggest = (query) => {
      query = (query || "").trim();
      if (query.length < 2) { dl.innerHTML = ""; return; }

      if (abort) abort.abort();
      abort = new AbortController();

      window.fetchJson(
        `${API_BASE}?action=buscar_productos&q=${encodeURIComponent(query)}&limit=5`,
        { signal: abort.signal }
      ).then((data) => {
        const productos = data?.productos || data?.data?.productos || [];
        dl.innerHTML = productos.map(p =>
          `<option value="${esc(p.codigo)}" label="${esc(p.nombre)}"></option>`
        ).join("");
      }).catch((err) => {
        if (err?.name === "AbortError") return;
        dl.innerHTML = "";
      });
    };

    // Debounce (usa tu debounce global si existe)
    const debounced = (window.debounce)
      ? window.debounce(doSuggest, 120)
      : (() => { let t; return (v) => { clearTimeout(t); t = setTimeout(() => doSuggest(v), 120); }; })();

    input.addEventListener("input", () => debounced(input.value));
  })();

  // Papel del ticket
  const PAPER_KEY = "kiosco-ticket-paper";
  function getPaper() {
    const v = (localStorage.getItem(PAPER_KEY) || "80").trim();
    return v === "58" ? "58" : "80";
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
          2
        )}. Revisá si es suficiente para el turno.`;
        aviso.classList.remove("hidden");
      } else {
        aviso.textContent = "";
        aviso.classList.add("hidden");
      }
    }

    inputSaldo.addEventListener("input", actualizarAviso);
    actualizarAviso();

    formApertura.addEventListener("submit", (e) => {
      const valor = parseSaldo(inputSaldo.value);
      if (
        !window.confirm(
          `¿Abrir caja con saldo inicial de $${valor.toFixed(2)}?`
        )
      ) {
        e.preventDefault();
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

  // Storage por caja (evita mezclar tickets entre aperturas)
  const STORAGE_PREFIX = "kiosco-caja-estado-v1";
    // FLUS: Storage key estable por terminal + sesiÃ³n (evita colisiones y CAJA_ID=0)
  const FLUS_TERMINAL_ID =
    (window.TERMINAL_ID ?? window.terminalId ?? document.body?.dataset?.terminalId ?? 0);

  const __flusSidKey = "kiosco-caja-session-id";
  let __flusSid = sessionStorage.getItem(__flusSidKey);
  if (!__flusSid) {
    __flusSid = (crypto?.randomUUID?.() || (Date.now() + "-" + Math.random().toString(16).slice(2)));
    sessionStorage.setItem(__flusSidKey, __flusSid);
  }

  const STORAGE_KEY = `kiosco-caja-v2:${FLUS_TERMINAL_ID}:${__flusSid}`;

  let promosPorProducto = {};
  let promosCombos = [];
  let carrito = [];
  let totalNetoActual = 0;

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
  // Split payment (Pago con 2 medios)
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
  const btnConfirm = document.getElementById("modal-confirm");
  const btnCancel = document.getElementById("modal-cancel");

  let modalResolver = null;
  let modalIsInput = false;

  const optPrecio = modalDescTipo?.querySelector('option[value="precio"]');

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
    const s = String(v ?? "").trim().replace(/\./g, "").replace(",", ".");
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
      return n;                     // 3.56 => 3.56 kg
    }
    if (u === "LT") {
      if (n >= 50) return n / 1000; // 700 => 0.700 lt
      return n;                     // 0.7 => 0.7 lt
    }

    return n;
  }

  function cantidadHumanaDesdeInterna(cantInterna, unidadVenta, esPesable) {
    const c = Number(cantInterna) || 0;
    if (!esPesable) return Math.round(c);

    const u = String(unidadVenta || "KG").toUpperCase();
    if (u === "G")  return c * 100;   // packs -> gramos
    if (u === "ML") return c * 100;   // packs -> ml
    return c; // KG/LT ya están en unidad humana
  }

  function formatearCantidadHumana(item) {
    const cant = Number(item?.cantidad) || 0;
    const esPesable = !!item?.esPesable;
    const u = String(item?.unidadVenta || (esPesable ? "KG" : "UNID")).toUpperCase();

    if (!esPesable) {
      return `${Math.round(cant)} UNID`;
    }

    if (u === "G")  return `${fmtInt0.format(cant * 100)} g`;
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

  function mostrarMensaje(tipo, texto) {
    if (!msgBox) return;
    msgBox.textContent = texto;
    msgBox.className = "msg msg-visible msg-" + tipo;
  }

  function limpiarMensaje() {
    if (!msgBox) return;
    msgBox.textContent = "";
    msgBox.className = "msg";
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
      return;
    }

    // Modo legacy (1 medio)
    if (!medioEsEfectivo()) {
      inputPagado.value = String(Number(totalNetoActual || 0).toFixed(2));
      inputPagado.disabled = true;
    } else {
      inputPagado.disabled = false;
    }
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

  if (lblTotalPagado) lblTotalPagado.textContent = formatearMoneda(pagadoTotal);
  if (lblVuelto) lblVuelto.textContent = formatearMoneda(vuelto);

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
  // STORAGE
  // =========================
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
        caja_id: CAJA_ID || 0,
      })
    );
  }

  function cargarEstado() {
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
        if (selMedio && p1.medio) selMedio.value = String(p1.medio).toUpperCase();
        if (inputPagado && p1.monto != null) inputPagado.value = String(p1.monto);

        const split = !!data.split || pagosRaw.length > 1;
        setSplitActivo(split);

        if (split && pagosRaw.length > 1) {
          const p2 = pagosRaw[1] || {};
          if (selMedio2 && p2.medio) selMedio2.value = String(p2.medio).toUpperCase();
          if (inputPagado2 && p2.monto != null) inputPagado2.value = String(p2.monto);
        }
      } else {
        // Legacy (por compat): medio + pagado
        if (selMedio && data.medio) selMedio.value = data.medio;
        if (inputPagado && data.pagado != null) inputPagado.value = data.pagado;
      }

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
      if (!headers.has("X-CSRF")) headers.set("X-CSRF", csrf);             // compat
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
      if (!res.ok && String(data?.error || "").toUpperCase().includes("CSRF")) {
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
          Math.floor((it.cantidad + tolerance) / req.cantidad)
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
          await new Promise(r => setTimeout(r, 100));
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
    if (!forzar && (ahora - ultimaSincronizacion) < SYNC_DEBOUNCE_MS) {
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
      const itemsParaServidor = carrito.map(i => ({
        id: Number(i.id),
        cantidad: Number(i.cantidad),
        precio: Number(i.precio) || Number(i.precioLista) || 0  // precio manual si existe
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
        body: fd
      });

      if (response.ok && Array.isArray(response.items)) {
        // Actualizar carrito con precios del servidor
        response.items.forEach((serverItem, idx) => {
          const localItem = carrito.find(c => Number(c.id) === Number(serverItem.producto_id));
          if (localItem) {
            // Actualizar con datos del servidor
            localItem.subtotalServidor = serverItem.subtotal || serverItem.neto;
            localItem.descuentoServidor = serverItem.descuento || 0;
            localItem.promoServidor = serverItem.promo || '';
          }
        });

        // Actualizar total con lo que dice el servidor
        totalNetoActual = Number(response.total_neto) || 0;
        
        return {
          total_neto: response.total_neto,
          total_bruto: response.total_bruto,
          descuento_total: response.descuento_total,
          items: response.items
        };
      }
    } catch (e) {
      console.warn("sincronizarCarritoConServidor error (usando cálculo local):", e);
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
          console.log(`Precios sincronizados: local=${totalLocalAntes} → servidor=${result.total_neto}`);
          // Actualizar labels sin recalcular
          if (lblTotal) lblTotal.textContent = formatearMoneda(result.total_neto);
          if (lblTotalBruto) lblTotalBruto.textContent = formatearMoneda(result.total_bruto || 0);
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
        const u = String(item.unidadVenta || (esPesable ? "KG" : "UNID")).toUpperCase();

        if (!esPesable) {
          const entero = Math.max(0, Math.round(cant));
          return `${entero} UNID`;
        }

        // G / ML (interno = “unidad de 100”)
        if (u === "G") {
          const gramos = Math.round(cant * 100);
          return `${fmtInt0.format(gramos)} g`;
        }
        if (u === "ML") {
          const ml = Math.round(cant * 100);
          return `${fmtInt0.format(ml)} ml`;
        }

        // KG (interno en kg)
        if (u === "KG") {
          const kg = cant;
          const kgInt = Math.floor(kg + 1e-9);
          let g = Math.round((kg - kgInt) * 1000);

          if (g >= 1000) { g -= 1000; } // ajuste por redondeo

          if (kgInt <= 0) return `${fmtInt0.format(Math.max(g, 0))} g`;
          if (g <= 0) return `${kgInt} kg`;
          return `${kgInt} kg ${fmtInt0.format(g)} g`;
        }

        // LT (interno en litros)
        if (u === "LT") {
          const lt = cant;
          const ltInt = Math.floor(lt + 1e-9);
          let ml = Math.round((lt - ltInt) * 1000);

          if (ml >= 1000) { ml -= 1000; } // ajuste por redondeo

          if (ltInt <= 0) return `${fmtInt0.format(Math.max(ml, 0))} ml`;
          if (ml <= 0) return `${ltInt} l`;
          return `${ltInt} l ${fmtInt0.format(ml)} ml`;
        }

        // fallback
        return `${fmtQty3.format(cant)} ${u}`;
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

        const u = String(item.unidadVenta || (item.esPesable ? "KG" : "UNID")).toUpperCase();
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
        tr.innerHTML = `
          <td>${idx + 1}</td>
          <td>${item.codigo}</td>
          <td>${item.nombre}</td>
          <td class="center col-cant">${formatearCantidadUI(item)}</td>
          <td class="right">${precioHtml}</td>
          <td class="right">${formatearMoneda(subtotalConPromo)}</td>
          <td class="acciones">
            <button class="btn-accion btn-editar" data-idx="${idx}">Editar</button>
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
      lblDescGlobal.textContent = formatearMoneda(Math.max(0, totalBruto - totalNeto));

      totalNetoActual = totalNeto;

      ajustarPagoSegunMedio();
      recalcularVuelto();
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

    try {
      const data = await fetchJson(
        `${API_BASE}?action=buscar_producto&codigo=${encodeURIComponent(
          codigo
        )}`
      );

      if (!data.ok) return mostrarMensaje("error", data.error);

      const p = data.producto;

      const precioLista = Number(p.precio) || 0;
      const stock = Number(p.stock) || 0;
      const esPesable =
        p.es_pesable === true || p.es_pesable === 1 || p.es_pesable === "1";
      const unidadVenta = p.unidad_venta || (esPesable ? "KG" : "UNID");

      // ✅ Hint para el cajero (según unidad)
      aplicarHintCantidadInput(unidadVenta);

      let cantidad = cantidadInternaDesdeInput(inputCant?.value || "1", unidadVenta, esPesable);

      if (!Number.isFinite(cantidad) || cantidad <= 0) {
        // defaults “seguros”
        if (esPesable && (unidadVenta === "G" || unidadVenta === "ML")) cantidad = 1; // 1 pack = 100g/100ml
        else if (esPesable) cantidad = 0.1; // 0.1 kg/lt
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
          const agregar = window.confirm(
            `Stock insuficiente. Disponible: ${disponible} ${unidadVenta}.\n¿Agregar ${disponible} en su lugar?`
          );
          if (agregar) {
            cantidad = disponible;
          } else {
            return;
          }
        } else {
          return mostrarMensaje(
            "error",
            `No hay stock disponible de "${p.nombre}"`
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

      modalTitulo.textContent = opt.titulo || "";
      modalTexto.textContent = opt.texto || "";

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
        setTimeout(() => modalInput.focus(), 20);
      } else {
        modalInputArea.classList.add("hidden");
      }

      modal.classList.remove("hidden");
    });
  }

  function cerrarModal(v) {
    modal.classList.add("hidden");
    if (modalResolver) modalResolver(v);
    modalResolver = null;
    modalIsInput = false;
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
      if (lastComma > -1) return parseFloat(s.replace(/\./g, "").replace(",", "."));
      if (lastDot > -1) return parseFloat(s.replace(/,/g, "")); // deja el punto como decimal

      return parseFloat(s);
    }

    // ✅ Cantidad pesable flexible según unidad (KG/LT/G(100g)/ML(100ml))
    // - admite sufijos: g, kg, ml, l/lt
    // - si unidad es G o ML y no hay sufijo: 3 = "3x100" ; 300 = "300g/ml" (heurística)
    function parseCantPesableFlex(raw, unidadVenta) {
      let s = String(raw ?? "").trim().toLowerCase();
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
        if (u === "G")  return n >= 20 ? (n / 100) : n;    // 300 => 3 (x100g)
        if (u === "ML") return n >= 20 ? (n / 100) : n;    // 800 => 8 (x100ml)

        if (u === "KG") return n >= 50 ? (n / 1000) : n;   // 3373 => 3.373 kg
        if (u === "LT") return n >= 50 ? (n / 1000) : n;   // 700  => 0.700 lt

        return n;
      }


      // Con sufijo: convertir a tu unidad interna
      if (suf === "g") {
        if (u === "KG") return n / 1000;
        if (u === "G")  return n / 100;
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
  tbodyTicket?.addEventListener("click", (e) => {
    const btnEditar = e.target.closest(".btn-editar");
    const btnQuitar = e.target.closest(".btn-quitar");
    const btnDesc = e.target.closest(".btn-desc");

    // -------------------------
    // EDITAR CANTIDAD
    // -------------------------
    if (btnEditar) {
      const idx = Number(btnEditar.dataset.idx);
      const item = carrito[idx];
      if (!item) return;

      const unidad = item.unidadVenta || (item.esPesable ? "KG" : "UNID");
      const step = item.esPesable ? "0.001" : "1";
      const min = item.esPesable ? "0.001" : "1";

      // ✅ Hints para el cajero
      const hint =
        item.esPesable
          ? (unidad === "KG" ? "Ej: 3,373  |  3.373  |  3373g"
            : unidad === "LT" ? "Ej: 0,700  |  0.7  |  700ml"
            : unidad === "G"  ? "Ej: 3 (x100g)  |  300  |  300g"
            : unidad === "ML" ? "Ej: 8 (x100ml) | 800  | 800ml"
            : "")
          : "";

      mostrarModal({
        titulo: "Editar cantidad",
        texto: hint ? `${item.nombre}\n${hint}` : item.nombre,
        input: true,
        valorDefault: item.esPesable
        ? String(cantidadHumanaDesdeInterna(item.cantidad, unidad, true))
        : item.cantidad,

        // ✅ CLAVE: para pesables usamos TEXT para que NO te “coma” el punto
        inputType: item.esPesable ? "text" : "number",
        min,
        step,
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

        // ✅ 0 o menor => eliminar
        if (num <= 0) {
          carrito.splice(idx, 1);
          actualizarVistaInmediata();
          return;
        }

        // ✅ Validar stock (si existe)
        const hasStock = item.stock != null && item.stock !== "";
        if (hasStock) {
          const stock = Number(item.stock) || 0;
          const tol = item.esPesable ? 0.01 : 0;

          if (num > stock + tol) {
            const maxTxt = item.esPesable ? fmtQty3.format(stock) : String(Math.round(stock));
            mostrarMensaje("error", `Stock insuficiente. Máximo: ${maxTxt} ${unidad}`);
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

      return;
    }


    // -------------------------
    // DESCUENTO / CAMBIAR PRECIO
    // -------------------------
    if (btnDesc) {
      if (!CAN_MOD_PRECIO) {
        mostrarMensaje(
          "error",
          "No tenés permisos para aplicar descuento manual / cambiar precio."
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
          "⚠️ Este producto tiene promoción activa. El descuento manual no aplicará."
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
        "No tenés permisos para aplicar descuento global."
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
            totalNetoActual
          )})`
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
        if (lblTotalBruto) lblTotalBruto.textContent = formatearMoneda(Number(syncResult.total_bruto) || 0);
        if (lblDescGlobal) lblDescGlobal.textContent = formatearMoneda(Number(syncResult.descuento_total) || 0);
        
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

      const vuelto = Math.max(totalPag - totalUI, 0);
      const efectivo = efectivoPagado(pagos);

      // Si hay vuelto, tiene que salir de EFECTIVO
      if (vuelto > 0.009 && efectivo + 0.0001 < vuelto) {
        return mostrarMensaje(
          "error",
          "El vuelto supera el efectivo ingresado (agregá/ajustá EFECTIVO)"
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

      const data = await fetchJson(`${API_VENTA}?action=registrar_venta`, {
        method: "POST",
        body: fd,
      });


      if (!data?.ok)
        return mostrarMensaje("error", data?.error || "Error en la API");

      const ventaId = data.venta_id || data.id || data.ventaId;

      // Limpiar estado UI
      carrito = [];
      descGlobal = null;
      localStorage.removeItem(STORAGE_KEY);

      if (selMedio) selMedio.value = "EFECTIVO";
      if (inputPagado) inputPagado.value = "";
      setSplitActivo(false);

      ajustarPagoSegunMedio();
      actualizarVistaInmediata();
      recalcularVuelto();
      inputCodigo?.focus?.();

      // Imprimir ticket
      const iframe = document.createElement("iframe");
      iframe.style.display = "none";
      iframe.src = `ticket.php?venta_id=${encodeURIComponent(
        ventaId
      )}&paper=${getPaper()}&autoprint=1`;
      document.body.appendChild(iframe);

      mostrarMensaje("success", `✓ Venta #${ventaId} registrada correctamente`);
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
  function cancelarVenta() {
    if (carrito.length > 0) {
      const ok = window.confirm("¿Cancelar la venta y vaciar el ticket?");
      if (!ok) return;
    }
    carrito = [];
    descGlobal = null;
    localStorage.removeItem(STORAGE_KEY);
    if (inputPagado) inputPagado.value = "";
    actualizarVistaInmediata();
    inputCodigo?.focus?.();
  }

  // =========================
  // EVENTOS
  // =========================
  document.getElementById("btnAgregar")?.addEventListener("click", agregarItem);
  document.getElementById("btnCobrar")?.addEventListener("click", cobrar);
  document
    .getElementById("btnCancelar")
    ?.addEventListener("click", cancelarVenta);

  inputCodigo?.addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      agregarItem();
    } else {
      limpiarMensaje();
    }
  });

  inputPagado?.addEventListener("input", () => {
    recalcularVuelto();
    guardarEstado();
  });

  inputPagado2?.addEventListener("input", () => {
  if (inputPagado2) inputPagado2.dataset.auto = "0"; // ✅ ya no autocompletar
  recalcularVuelto();
  guardarEstado();
  });


  selMedio?.addEventListener("change", () => {
    ajustarPagoSegunMedio();
    recalcularVuelto();
    guardarEstado();
  });

  selMedio2?.addEventListener("change", () => {
    ajustarPagoSegunMedio();
    recalcularVuelto();
    guardarEstado();
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
      inputCodigo?.focus();
    }
  });

  // =========================
  // INIT
  // =========================
  (async () => {
    cargarEstado();
    await cargarPromos();
    actualizarVistaInmediata();
    ajustarPagoSegunMedio();
    recalcularVuelto();
  })();
});

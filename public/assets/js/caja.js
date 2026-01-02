// public/assets/js/caja.js
document.addEventListener("DOMContentLoaded", () => {
  const API_BASE = "/kiosco/public/api/api.php";     // promos + buscar producto
  const API_VENTA = "/kiosco/public/api/index.php";  // registrar_venta (CSRF)
  const API_TIMEOUT_MS = 8000;

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
      if (!window.confirm(`¿Abrir caja con saldo inicial de $${valor.toFixed(2)}?`)) {
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
  const STORAGE_KEY = `${STORAGE_PREFIX}:${CAJA_ID || "0"}`;

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
  const lblTotalBruto = document.getElementById("lblTotalBruto");
  const lblDescGlobal = document.getElementById("lblDescGlobal");
  const btnDescGlobal = document.getElementById("btnDescGlobal");

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

function getCsrf() {
  return (window.getCsrfToken && window.getCsrfToken())
    || document.querySelector('meta[name="csrf-token"]')?.getAttribute("content")
    || "";
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
    const cant = Number(item.cantidad);
    const entero = Math.round(cant);
    const esEntero = Math.abs(cant - entero) < 0.0005;
    const unidad = item.unidadVenta || (item.esPesable ? "KG" : "UNID");

    if (item.esPesable) {
      return esEntero ? `${entero} ${unidad}` : `${fmtQty3.format(cant)} ${unidad}`;
    }
    return `${entero} ${unidad}`;
  }

  function medioEsEfectivo() {
    return (selMedio?.value || "EFECTIVO") === "EFECTIVO";
  }

  function ajustarPagoSegunMedio() {
    if (!inputPagado) return;
    if (!medioEsEfectivo()) {
      inputPagado.value = String(Number(totalNetoActual || 0).toFixed(2));
      inputPagado.disabled = true;
    } else {
      inputPagado.disabled = false;
    }
  }

  function recalcularVuelto() {
    const total = Number(totalNetoActual) || 0;

    if (!medioEsEfectivo()) {
      lblVuelto.textContent = formatearMoneda(0);
      return;
    }

    const pagado = parseFloat(String(inputPagado?.value || "0").replace(",", "."));
    const vuelto = Math.max((Number.isFinite(pagado) ? pagado : 0) - total, 0);
    lblVuelto.textContent = formatearMoneda(vuelto);
  }

  // =========================
  // STORAGE
  // =========================
  function guardarEstado() {
    localStorage.setItem(
      STORAGE_KEY,
      JSON.stringify({
        carrito,
        medio: selMedio?.value || "EFECTIVO",
        pagado: inputPagado?.value || "",
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
      if (selMedio && data.medio) selMedio.value = data.medio;
      if (inputPagado && data.pagado != null) inputPagado.value = data.pagado;
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
    if (opt.body && !headers.has("Content-Type")) {
      headers.set("Content-Type", "application/json; charset=utf-8");
    }
    if (csrf && !headers.has("X-CSRF-Token")) {
      headers.set("X-CSRF-Token", csrf);
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

      if (!res.ok) {
        const msg = data?.error || data?.message || `HTTP ${res.status}`;
        if (res.status === 401) throw new Error(`No autenticado (401). ${msg}`);
        if (res.status === 403) throw new Error(`No autorizado (403). ${msg}`);
        throw new Error(msg);
      }

      return data;
    } catch (err) {
      if (err?.name === "AbortError") throw new Error("Tiempo de espera agotado al llamar a la API");
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
    const pagar = packs * promo.m + (cant % promo.n);

    // promo SIEMPRE sobre lista (como backend)
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
        maxCombos = Math.min(maxCombos, Math.floor((it.cantidad + tolerance) / req.cantidad));
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
  // RENDER (con debounce)
  // =========================
  function _actualizarVista() {
    if (!tbodyTicket) return;
    tbodyTicket.innerHTML = "";

    const combos = aplicarCombos(carrito);

    let totalBruto = 0;
    let totalNeto = 0;
    let totalDescCombos = 0;

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

      const precioHtml = tieneDescManual
        ? `<div>${formatearMoneda(base)}</div>
           <div class="precio-lista">Lista: ${formatearMoneda(lista)}</div>`
        : formatearMoneda(promo ? lista : base);

      const tr = document.createElement("tr");
      tr.innerHTML = `
        <td>${idx + 1}</td>
        <td>${item.codigo}</td>
        <td>${item.nombre}</td>
        <td class="center col-cant">${formatearCantidad(item)}</td>
        <td class="right">${precioHtml}</td>
        <td class="right">${formatearMoneda(subtotalConPromo)}</td>
        <td class="acciones">
          <button class="btn-accion btn-editar" data-idx="${idx}">Editar</button>
          <button class="btn-accion btn-desc" data-idx="${idx}">Desc.</button>
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
        `${API_BASE}?action=buscar_producto&codigo=${encodeURIComponent(codigo)}`
      );

      if (!data.ok) return mostrarMensaje("error", data.error);

      const p = data.producto;

      const precioLista = Number(p.precio) || 0;
      const stock = Number(p.stock) || 0;
      const esPesable = p.es_pesable === true || p.es_pesable === 1 || p.es_pesable === "1";
      const unidadVenta = p.unidad_venta || (esPesable ? "KG" : "UNID");

      let cantidad = esPesable
        ? parseFloat(String(inputCant?.value || "0").replace(",", "."))
        : parseInt(String(inputCant?.value || "1"), 10);

      if (isNaN(cantidad) || cantidad <= 0) cantidad = esPesable ? 0.1 : 1;

      const existente = carrito.find((i) => Number(i.id) === Number(p.id));
      const enCarrito = existente ? Number(existente.cantidad) : 0;

      // ✅ MEJORADO: Sugerir cantidad disponible
      if (stock > 0 && enCarrito + cantidad > stock) {
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
          return mostrarMensaje("error", `Ya no hay stock disponible de "${p.nombre}"`);
        }
      }

      if (existente) {
        existente.cantidad = Number(existente.cantidad) + cantidad;
      } else {
        carrito.push({
          id: Number(p.id),
          codigo: String(p.codigo),
          nombre: String(p.nombre),
          cantidad: Number(cantidad),
          precio: Number(precioLista),       // precio actual (modificable)
          precioLista: Number(precioLista),  // lista
          esPesable,
          unidadVenta,
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
      // ✅ MEJORADO: Mostrar mensaje real de error
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

        if (typeof opt.tipoDefault === "string") modalDescTipo.value = opt.tipoDefault;

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
    if (!modal.classList.contains("hidden") && e.key === "Escape") cerrarModal(false);
  });

  // =========================
  // EDITAR / QUITAR / DESCUENTO ITEM
  // =========================
  tbodyTicket?.addEventListener("click", (e) => {
    const btnEditar = e.target.closest(".btn-editar");
    const btnQuitar = e.target.closest(".btn-quitar");
    const btnDesc = e.target.closest(".btn-desc");

    if (btnEditar) {
      const idx = Number(btnEditar.dataset.idx);
      const item = carrito[idx];
      if (!item) return;

      const step = item.esPesable ? "0.001" : "1";
      const min = item.esPesable ? "0.001" : "1";

      mostrarModal({
        titulo: "Editar cantidad",
        texto: item.nombre,
        input: true,
        valorDefault: item.cantidad,
        label: "Cantidad",
        showTipo: false,
        inputType: "number",
        min,
        step,
      }).then((val) => {
        if (val === false) return;

        let num = parseFloat(String(val).replace(",", "."));
        if (!item.esPesable) num = Math.round(num);
        if (!Number.isFinite(num) || num <= 0) num = item.esPesable ? 0.1 : 1;

        item.cantidad = num;
        actualizarVistaInmediata();
      });
      return;
    }

    if (btnDesc) {
      const idx = Number(btnDesc.dataset.idx);
      const item = carrito[idx];
      if (!item) return;

      // ✅ MEJORADO: Advertir si hay promo activa
      if (promosPorProducto[String(item.id)]) {
        mostrarMensaje("warning", 
          `⚠️ Este producto tiene promoción activa. El descuento manual no aplicará.`
        );
      }

      // Item: permitir precio / % / monto
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
        if (!Number.isFinite(num)) return mostrarMensaje("error", "Valor inválido.");

        const lista = Number(item.precioLista) || 0;
        let nuevo = Number(item.precio) || lista;

        if (tipo === "precio") {
          nuevo = num;
        } else if (tipo === "porcentaje") {
          if (num < 0 || num > 100) return mostrarMensaje("error", "Porcentaje inválido (0-100).");
          nuevo = lista * (1 - num / 100);
        } else if (tipo === "monto") {
          if (num < 0) return mostrarMensaje("error", "Monto inválido.");
          nuevo = lista - num;
        }

        if (!Number.isFinite(nuevo) || nuevo <= 0) {
          return mostrarMensaje("error", "El precio final queda inválido o negativo.");
        }

        item.precio = Number(nuevo.toFixed(2));
        actualizarVistaInmediata();
      });
      return;
    }

    if (btnQuitar) {
      const idx = Number(btnQuitar.dataset.idx);
      if (!Number.isFinite(idx)) return;
      carrito.splice(idx, 1);
      actualizarVistaInmediata();
    }
  });

  // =========================
  // DESCUENTO GLOBAL (botón "Cambiar")
  // =========================
  btnDescGlobal?.addEventListener("click", () => {
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

      const tipo = (modalDescTipo?.value === "porcentaje") ? "porcentaje" : "monto";
      let num = parseFloat(String(val).replace(",", "."));
      if (!Number.isFinite(num) || num < 0) num = 0;

      if (tipo === "porcentaje" && num > 100) {
        return mostrarMensaje("error", "Máximo 100%.");
      }

      // ✅ MEJORADO: Validar monto vs total
      if (tipo === "monto" && num > totalNetoActual) {
        return mostrarMensaje("error", 
          `El descuento no puede superar el total (${formatearMoneda(totalNetoActual)})`
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
    if (!carrito || carrito.length === 0) return mostrarMensaje("error", "Ticket vacío");

    const totalUI = Number(totalNetoActual) || 0;

    let pagado = parseFloat(String(inputPagado?.value || "0").replace(",", "."));
    if (!medioEsEfectivo()) {
      pagado = totalUI;
    } else {
      if (!Number.isFinite(pagado) || pagado <= 0) pagado = 0;
      if (pagado + 0.0001 < totalUI) return mostrarMensaje("error", "El pago no alcanza.");
    }

    cobrando = true;
    const btn = document.getElementById("btnCobrar");
    if (btn) btn.disabled = true;

    try {
      const itemsLimpios = carrito.map((i) => ({
        id: Number(i.id),
        cantidad: Number(i.cantidad),
        precio: Number(i.precio), // precio unitario actual (manual si aplicaste)
      }));
      const token = getCsrf();
      const payload = {
        csrf_token: token,   // ✅ estándar
        csrf: token,         // ✅ compat si algún endpoint viejo lee "csrf"
        caja_id: CAJA_ID,
        items: itemsLimpios,
        medio_pago: (selMedio?.value || "EFECTIVO").toUpperCase(),
        monto_pagado: Number(pagado),
        desc_global: descGlobal || null,
      };

      const data = await fetchJson(`${API_VENTA}?action=registrar_venta`, {
        method: "POST",
        body: JSON.stringify(payload),
      });

      if (!data?.ok) return mostrarMensaje("error", data?.error || "Error en la API");

      const ventaId = data.venta_id ?? data.ventaId;
      if (!ventaId) return mostrarMensaje("error", "Venta registrada, pero no llegó el ID.");

      // Limpieza
      carrito = [];
      descGlobal = null;
      localStorage.removeItem(STORAGE_KEY);
      if (inputPagado) inputPagado.value = "";
      actualizarVistaInmediata();
      inputCodigo?.focus?.();

      // Imprimir ticket
      const iframe = document.createElement("iframe");
      iframe.style.display = "none";
      iframe.src = `ticket.php?venta_id=${encodeURIComponent(ventaId)}&paper=${getPaper()}&autoprint=1`;
      document.body.appendChild(iframe);
      
      mostrarMensaje("success", `✓ Venta #${ventaId} registrada correctamente`);
      
    } catch (e) {
      console.error("Error registrar venta:", e);
      // ✅ MEJORADO: Mostrar mensaje real de error
      mostrarMensaje("error", e?.message || "Error al registrar la venta");
    } finally {
      cobrando = false;
      if (btn) btn.disabled = false;
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
  document.getElementById("btnCancelar")?.addEventListener("click", cancelarVenta);

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

  selMedio?.addEventListener("change", () => {
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
  })();
});
// public/assets/js/caja.js
document.addEventListener("DOMContentLoaded", () => {
  const apiBase = "/kiosco/public/api/index.php";
  const STORAGE_KEY = "kiosco-caja-estado-v1";
  const API_TIMEOUT_MS = 8000;

  // Papel del ticket
  const PAPER_KEY = "kiosco-ticket-paper";
  function getPaper() {
    const v = (localStorage.getItem(PAPER_KEY) || "80").trim();
    return v === "58" ? "58" : "80";
  }

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
  if (!tabla) return; // si no hay ticket, no seguimos

  let promosPorProducto = {};
  let promosCombos = [];
  let carrito = [];
  let totalNetoActual = 0;

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

  const modal = document.getElementById("modal");
  const modalTitulo = document.getElementById("modal-titulo");
  const modalTexto = document.getElementById("modal-texto");
  const modalInputArea = document.getElementById("modal-input-container");
  const modalLabel = document.getElementById("modal-label");
  const modalInput = document.getElementById("modal-input");
  const btnConfirm = document.getElementById("modal-confirm");
  const btnCancel = document.getElementById("modal-cancel");

  let modalResolver = null;
  let modalIsInput = false;

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
    return (
      document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute("content") || ""
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
    const cant = Number(item.cantidad);
    const entero = Math.round(cant);
    const esEntero = Math.abs(cant - entero) < 0.0005;
    const unidad = item.unidadVenta || (item.esPesable ? "KG" : "UNID");

    if (item.esPesable) {
      return esEntero
        ? `${entero} ${unidad}`
        : `${fmtQty3.format(cant)} ${unidad}`;
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
      // no pisamos lo que el user escribió
    }
  }

  function recalcularVuelto() {
    const total = Number(totalNetoActual) || 0;

    if (!medioEsEfectivo()) {
      lblVuelto.textContent = formatearMoneda(0);
      return;
    }

    const pagado = parseFloat(inputPagado?.value || "0");
    const vuelto = Math.max(pagado - total, 0);
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
      })
    );
  }

  function cargarEstado() {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return;
    try {
      const data = JSON.parse(raw);
      carrito = data.carrito || [];
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

    // Headers merge
    const headers = new Headers(opt.headers || {});
    if (!headers.has("Content-Type") && opt.body) {
      headers.set("Content-Type", "application/json");
    }
    // Mandar CSRF siempre (si existe)
    if (csrf) headers.set("X-CSRF-Token", csrf);

    try {
      const res = await fetch(url, { ...opt, headers, signal: ctrl.signal });
      const text = await res.text();

      let data;
      try {
        data = JSON.parse(text);
      } catch {
        console.error("Respuesta no-JSON desde API:", text);
        throw new Error("La API no devolvió JSON válido");
      }

      if (!res.ok) throw new Error(data?.error || `HTTP ${res.status}`);
      return data;
    } finally {
      clearTimeout(t);
    }
  }

  // =========================
  // PROMOS
  // =========================
  async function cargarPromos() {
    try {
      const data = await fetchJson(`${apiBase}?action=listar_promos_activas`);
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

    const precio = item.precioLista;
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

    const precio = item.precioLista;
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
        maxCombos = Math.min(maxCombos, Math.floor(it.cantidad / req.cantidad));
      });

      if (maxCombos > 0 && maxCombos !== Infinity) {
        combosAplicados.push({ combo, cantidad: maxCombos, descuento: 0 });
      }
    });

    return combosAplicados;
  }

  // =========================
  // RENDER
  // =========================
  function actualizarVista() {
    if (!tbodyTicket) return;
    tbodyTicket.innerHTML = "";

    const combos = aplicarCombos(carrito);

    let totalBruto = 0;
    let totalNeto = 0;
    let totalDescCombos = 0;

    combos.forEach((cb) => {
      const sumaLista = cb.combo.items.reduce((acc, it) => {
        const prod = carrito.find((p) => Number(p.id) === it.producto_id);
        if (!prod) return acc;
        return acc + prod.precioLista * it.cantidad;
      }, 0);

      const descuentoUnit = sumaLista - cb.combo.precio_combo;
      cb.descuento = descuentoUnit * cb.cantidad;
      totalDescCombos += cb.descuento;
    });

    carrito.forEach((item, idx) => {
      const cant = Number(item.cantidad);
      const lista = Number(item.precioLista);
      const base = Number(item.precio);

      const subtotalOriginal = cant * lista;
      let subtotalConPromo = cant * base;

      const promo = aplicarPromosItem(item);
      let descuentoPromo = 0;
      let descNombre = null;

      if (promo) {
        subtotalConPromo = promo.subtotalFinal;
        descuentoPromo = promo.descuento;
        descNombre = promo.descripcion;
      }

      totalBruto += subtotalOriginal;
      totalNeto += subtotalConPromo;

      const tieneDescManual = Math.abs(base - lista) > 0.009;

      const precioHtml = tieneDescManual
        ? `<div>${formatearMoneda(base)}</div>
           <div class="precio-lista">Lista: ${formatearMoneda(lista)}</div>`
        : formatearMoneda(base);

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

    // normalizar y redondear
    totalNeto = Math.max(0, Number(totalNeto.toFixed(2)));

    lblTotalBruto.textContent = formatearMoneda(totalBruto);
    lblDescGlobal.textContent = formatearMoneda(totalBruto - totalNeto);
    lblTotal.textContent = formatearMoneda(totalNeto);

    totalNetoActual = totalNeto;

    ajustarPagoSegunMedio();
    recalcularVuelto();
    guardarEstado();
  }

  // =========================
  // AGREGAR ITEM
  // =========================
  async function agregarItem() {
    const codigo = (inputCodigo?.value || "").trim();
    if (!codigo) return;

    try {
      const data = await fetchJson(
        `${apiBase}?action=buscar_producto&codigo=${encodeURIComponent(codigo)}`
      );

      if (!data.ok) return mostrarMensaje("error", data.error);

      const p = data.producto;

      const precioLista = Number(p.precio) || 0;
      const stock = Number(p.stock) || 0;
      const esPesable =
        p.es_pesable === true || p.es_pesable === 1 || p.es_pesable === "1";
      const unidadVenta = p.unidad_venta || (esPesable ? "KG" : "UNID");

      let cantidad = esPesable
        ? parseFloat(String(inputCant?.value || "0").replace(",", "."))
        : parseInt(String(inputCant?.value || "1"), 10);

      if (isNaN(cantidad) || cantidad <= 0) cantidad = esPesable ? 0.1 : 1;

      const existente = carrito.find((i) => Number(i.id) === Number(p.id));
      const enCarrito = existente ? Number(existente.cantidad) : 0;

      if (stock > 0 && enCarrito + cantidad > stock) {
        return mostrarMensaje(
          "error",
          `Stock insuficiente. Disponible: ${stock}`
        );
      }

      if (existente) {
        existente.cantidad = Number(existente.cantidad) + cantidad;
      } else {
        carrito.push({
          id: Number(p.id),
          codigo: String(p.codigo),
          nombre: String(p.nombre),
          cantidad: Number(cantidad),
          precio: Number(precioLista), // precio “actual”
          precioLista: Number(precioLista), // precio lista
          esPesable,
          unidadVenta,
        });
      }

      inputCodigo.value = "";
      inputCant.value = esPesable ? "0.100" : "1";

      limpiarMensaje();
      actualizarVista();
    } catch (e) {
      console.error("ERROR agregarItem():", e);
      mostrarMensaje("error", "Error al buscar producto.");
    }
  }

  // =========================
  // MODAL
  // =========================
  function mostrarModal(opt) {
    return new Promise((resolve) => {
      modalResolver = resolve;
      modalIsInput = !!opt.input;

      modalTitulo.textContent = opt.titulo || "";
      modalTexto.textContent = opt.texto || "";

      if (modalIsInput) {
        modalInputArea.classList.remove("hidden");
        modalLabel.textContent = opt.label || "";
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

  // =========================
  // EDITAR / QUITAR / DESCUENTO
  // =========================
  tbodyTicket?.addEventListener("click", (e) => {
    const btnEditar = e.target.closest(".btn-editar");
    const btnQuitar = e.target.closest(".btn-quitar");
    const btnDesc = e.target.closest(".btn-desc");

    if (btnEditar) {
      const idx = Number(btnEditar.dataset.idx);
      const item = carrito[idx];
      if (!item) return;

      mostrarModal({
        titulo: "Editar cantidad",
        texto: item.nombre,
        input: true,
        valorDefault: item.cantidad,
        label: "Cantidad",
      }).then((val) => {
        if (val === false) return;

        let num = parseFloat(String(val).replace(",", "."));
        if (!item.esPesable) num = Math.round(num);
        if (!Number.isFinite(num) || num <= 0) num = item.esPesable ? 0.1 : 1;

        item.cantidad = num;
        actualizarVista();
      });
      return;
    }

    if (btnDesc) {
      const idx = Number(btnDesc.dataset.idx);
      const item = carrito[idx];
      if (!item) return;

      mostrarModal({
        titulo: "Descuento manual",
        texto: item.nombre,
        input: true,
        valorDefault: item.precio,
        label: "Nuevo precio unitario",
      }).then((val) => {
        if (val === false) return;
        let num = parseFloat(String(val).replace(",", "."));
        if (!Number.isFinite(num) || num <= 0) {
          return mostrarMensaje("error", "Precio inválido.");
        }
        item.precio = num;
        actualizarVista();
      });
      return;
    }

    if (btnQuitar) {
      const idx = Number(btnQuitar.dataset.idx);
      if (!Number.isFinite(idx)) return;
      carrito.splice(idx, 1);
      actualizarVista();
    }
  });

  // =========================
  // COBRAR
  // =========================
  async function cobrar() {
    limpiarMensaje();

    if (carrito.length === 0) return mostrarMensaje("error", "Ticket vacío");

    const total = Number(totalNetoActual) || 0;

    // si no es efectivo, forzamos pagado = total
    let pagado = parseFloat(inputPagado?.value || "0");
    if (!medioEsEfectivo()) {
      pagado = total;
    } else {
      if (pagado + 0.0001 < total) {
        return mostrarMensaje("error", "El pago no alcanza.");
      }
    }

    try {
      const itemsLimpios = carrito.map((i) => ({
        id: Number(i.id),
        cantidad: Number(i.cantidad),
        // La API usa "precio"
        precio: Number(i.precio),
      }));

      const payload = {
        csrf: getCsrf(),
        items: itemsLimpios,
        medio_pago: selMedio?.value || "EFECTIVO",
        monto_pagado: pagado,
      };

      const data = await fetchJson(`${apiBase}?action=registrar_venta`, {
        method: "POST",
        body: JSON.stringify(payload),
      });

      if (!data?.ok)
        return mostrarMensaje("error", data?.error || "Error en la API");

      const ventaId = data.venta_id || data.ventaId;
      if (!ventaId) {
        console.warn("Respuesta API sin venta_id:", data);
        return mostrarMensaje(
          "error",
          "Venta registrada, pero no llegó el ID."
        );
      }

      // OK
      carrito = [];
      localStorage.removeItem(STORAGE_KEY);
      if (inputPagado) inputPagado.value = "";
      actualizarVista();

      // imprimir ticket
      const iframe = document.createElement("iframe");
      iframe.style.display = "none";
      iframe.src = `ticket.php?venta_id=${encodeURIComponent(
        ventaId
      )}&paper=${getPaper()}&autoprint=1`;
      document.body.appendChild(iframe);
    } catch (e) {
      console.error("Error registrar venta:", e);
      mostrarMensaje("error", e?.message || "Error al registrar la venta");
    }
  }

  // =========================
  // CANCELAR
  // =========================
  function cancelarVenta() {
    carrito = [];
    localStorage.removeItem(STORAGE_KEY);
    inputPagado.value = "";
    actualizarVista();
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
      // cuando escanean y hay basura, limpiamos error rápido
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

  // Atajos (ya que los mostrás en UI)
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
    actualizarVista();
    ajustarPagoSegunMedio();
  })();
});

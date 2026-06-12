// public/assets/js/caja_mp_qr.js
(function () {
  const API_VENTA = "api/index.php";

  function getCsrf() {
    return (
      (window.getCsrfToken && window.getCsrfToken()) ||
      document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ||
      ""
    );
  }

  function money(value) {
    return new Intl.NumberFormat("es-AR", {
      style: "currency",
      currency: "ARS",
      minimumFractionDigits: 2,
    }).format(Number(value) || 0);
  }

  function refs() {
    return {
      modal: document.getElementById("mpQrModal"),
      title: document.getElementById("mpQrTitle"),
      amount: document.getElementById("mpQrAmount"),
      imageBox: document.getElementById("mpQrImageBox"),
      image: document.getElementById("mpQrImage"),
      hint: document.getElementById("mpQrHint"),
      orderId: document.getElementById("mpQrOrderId"),
      paymentId: document.getElementById("mpQrPaymentId"),
      statusText: document.getElementById("mpQrStatusText"),
      cancelBtn: document.getElementById("mpQrCancelBtn"),
    };
  }

  async function apiJson(action, payload = null, method = "POST") {
    const headers = new Headers({ Accept: "application/json" });
    const csrf = getCsrf();
    if (csrf) {
      headers.set("X-CSRF-Token", csrf);
      headers.set("X-CSRF", csrf);
    }

    const options = { method, credentials: "same-origin", headers };
    if (payload !== null) {
      headers.set("Content-Type", "application/json; charset=utf-8");
      options.body = JSON.stringify(payload);
    }

    const actionQuery = String(action).includes("&")
      ? String(action)
      : encodeURIComponent(String(action));
    const response = await fetch(`${API_VENTA}?action=${actionQuery}`, options);
    const text = await response.text();
    let data = null;
    try {
      data = text ? JSON.parse(text) : null;
    } catch {
      throw new Error(`La API no devolvio JSON valido (HTTP ${response.status})`);
    }
    if (!response.ok || !data?.ok) {
      throw new Error(data?.error || `Error HTTP ${response.status}`);
    }
    return data;
  }

  function setOpen(open) {
    const ui = refs();
    if (!ui.modal) return;
    ui.modal.classList.toggle("hidden", !open);
    ui.modal.setAttribute("aria-hidden", open ? "false" : "true");
  }

  function setStatus(title, detail = "", kind = "waiting") {
    const ui = refs();
    if (ui.title) {
      ui.title.textContent = title;
      ui.title.dataset.kind = kind;
    }
    if (ui.statusText) ui.statusText.textContent = detail || title;
  }

  function setQrEmptyText(text) {
    const ui = refs();
    if (ui.imageBox) ui.imageBox.dataset.empty = text || "Preparando QR...";
  }

  function renderOrder(order, options = {}) {
    const ui = refs();
    const status = String(order?.status || "unknown");
    const detail = String(order?.status_detail || order?.payment_status_detail || "");

    if (ui.orderId) ui.orderId.textContent = String(order?.id || "-");
    if (ui.paymentId) ui.paymentId.textContent = String(order?.payment_id || "-");
    if (ui.statusText) ui.statusText.textContent = `${status}${detail ? " / " + detail : ""}`;

    if (options.hideQr) {
      if (ui.image) ui.image.hidden = true;
      if (ui.imageBox) ui.imageBox.classList.add("is-empty");
    } else if (order?.qr_data && ui.image && ui.imageBox) {
      ui.image.src =
        "https://api.qrserver.com/v1/create-qr-code/?size=320x320&margin=12&data=" +
        encodeURIComponent(String(order.qr_data));
      ui.imageBox.classList.remove("is-empty");
      ui.image.hidden = false;
    } else if (ui.image && ui.imageBox) {
      ui.image.hidden = true;
      ui.imageBox.classList.add("is-empty");
    }
  }

  function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }

  async function askManualFallback(kind, error) {
    if (window.FLUS_MP_MANUAL_FALLBACK !== true) return false;
    if (!window.Notif || typeof window.Notif.confirmar !== "function") return false;

    const detail = String(error?.message || "No se pudo confirmar con Mercado Pago.");
    const label = kind === "point" ? "Point" : "QR";
    return window.Notif.confirmar(
      "Mercado Pago manual",
      `<p>No se pudo confirmar el cobro ${label} desde esta PC.</p><p class="muted">Si el posnet, la app o el comprobante muestran el pago aprobado, podes registrar la venta como Mercado Pago manual.</p><p><strong>${detail}</strong></p>`,
      {
        icon: "warning",
        confirmText: "Registrar manual",
        cancelText: "No registrar",
      },
    );
  }

  async function confirmar(total, options = {}) {
    const type = options.type || "qr";
    if (type === "point" && window.FLUS_MP_POINT_ENABLED !== true) return null;
    if (type !== "point" && window.FLUS_MP_QR_ENABLED !== true) return null;
    const ui = refs();
    if (!ui.modal) return null;

    let cancelRequested = false;
    let currentOrderId = "";
    const amount = Number(total || 0);

    if (ui.amount) ui.amount.textContent = money(amount);
    if (ui.hint) {
      ui.hint.textContent =
        type === "point"
          ? "El importe se envio al Point. Operen la tarjeta en el posnet y FLUS seguira al acreditarse."
          : "Mostrale este QR al cliente o usa el QR estatico impreso. FLUS sigue cuando Mercado Pago confirma.";
    }
    if (ui.cancelBtn) ui.cancelBtn.disabled = true;
    if (ui.image) {
      ui.image.hidden = true;
      ui.image.removeAttribute("src");
    }
    if (ui.imageBox) ui.imageBox.classList.add("is-empty");
    if (ui.orderId) ui.orderId.textContent = "-";
    if (ui.paymentId) ui.paymentId.textContent = "-";

    setQrEmptyText(type === "point" ? "Esperando Point..." : "Preparando QR...");
    setStatus(type === "point" ? "Enviando al Point" : "Creando QR", "Preparando order...", "waiting");
    setOpen(true);

    const cancelHandler = async () => {
      cancelRequested = true;
      const latest = refs();
      if (latest.cancelBtn) latest.cancelBtn.disabled = true;
      setStatus(type === "point" ? "Cancelando Point" : "Cancelando QR", "Anulando order...", "waiting");
      if (currentOrderId) {
        try {
          await apiJson("mp_qr_cancel", { order_id: currentOrderId });
        } catch (error) {
          console.warn("No se pudo cancelar order MP QR:", error);
        }
      }
    };

    ui.cancelBtn?.addEventListener("click", cancelHandler);

    try {
      const created =
        type === "point"
          ? await apiJson("mp_point_create", {
              amount,
              payment_type: options.paymentType || "credit_card",
              ticket_number: options.ticketNumber || "FLUS",
            })
          : await apiJson("mp_qr_create", {
              amount,
              mode: "hybrid",
              description: "Venta FLUS Caja",
            });

      let order = created?.order || null;
      currentOrderId = String(order?.id || "");
      if (!currentOrderId) throw new Error("Mercado Pago no devolvio order_id");

      refs().cancelBtn?.removeAttribute("disabled");
      setStatus("Esperando pago", "Order creada. Esperando acreditacion...", "waiting");
      renderOrder(order, { hideQr: type === "point" });

      for (;;) {
        if (cancelRequested) throw new Error("Cobro QR cancelado");

        if (order?.approved) {
          setStatus("Pago aprobado", "processed / accredited", "ok");
          renderOrder(order, { hideQr: type === "point" });
          await sleep(600);
          return order;
        }

        if (order?.terminal) {
          throw new Error(`La order QR termino como ${order.status || "no aprobada"}`);
        }

        await sleep(2000);
        const statusData = await apiJson(
          `mp_qr_status&order_id=${encodeURIComponent(currentOrderId)}`,
          null,
          "GET",
        );
        order = statusData?.order || order;
        renderOrder(order, { hideQr: type === "point" });
      }
    } finally {
      refs().cancelBtn?.removeEventListener("click", cancelHandler);
      setOpen(false);
    }
  }

  window.FLUS_MP_QR = { confirmar };
  window.FLUS_MP_POINT = {
    confirmar(total, paymentType = "credit_card", ticketNumber = "FLUS") {
      return confirmar(total, { type: "point", paymentType, ticketNumber });
    },
  };
  window.FLUS_MP_COBRO = {
    async confirmar(pagos, total, ui = {}) {
      const paymentRows = Array.isArray(pagos) ? pagos : [];
      const amountFor = (medios) => paymentRows.reduce((sum, pago) => {
        const medio = String(pago?.medio || "").toUpperCase();
        return medios.includes(medio) ? sum + (Number(pago?.monto) || 0) : sum;
      }, 0);
      const mpAmount = amountFor(["MP"]);
      const pointRows = paymentRows.filter((pago) =>
        ["DEBITO", "CREDITO"].includes(String(pago?.medio || "").toUpperCase()),
      );
      const pointAmount = amountFor(["DEBITO", "CREDITO"]);
      const pointMethods = [...new Set(pointRows.map((pago) =>
        String(pago?.medio || "").toUpperCase(),
      ))];
      const result = { qr: null, point: null, manualQr: null, manualPoint: null };

      if (mpAmount > 0.009 && window.FLUS_MP_QR_ENABLED === true) {
        ui.mostrarMensaje?.("info", "Esperando pago con Mercado Pago QR...");
        try {
          result.qr = await confirmar(mpAmount);
          ui.limpiarMensaje?.();
        } catch (error) {
          ui.limpiarMensaje?.();
          if (await askManualFallback("qr", error)) {
            result.manualQr = { reason: String(error?.message || "") };
          } else {
            throw error;
          }
        }
      } else if (mpAmount > 0.009 && window.FLUS_MP_CASHIER_MODE === "manual") {
        result.manualQr = { reason: "Modo manual de caja" };
      }

      if (pointAmount > 0.009 && window.FLUS_MP_POINT_ENABLED === true) {
        if (pointMethods.length !== 1) {
          throw new Error("Point requiere usar solo Debito o solo Credito dentro de la misma venta.");
        }
        const pointMethod = pointMethods[0];
        ui.mostrarMensaje?.("info", "Esperando pago con Mercado Pago Point...");
        try {
          result.point = await window.FLUS_MP_POINT.confirmar(
            pointAmount,
            pointMethod === "DEBITO" ? "debit_card" : "credit_card",
            "FLUS",
          );
          ui.limpiarMensaje?.();
        } catch (error) {
          ui.limpiarMensaje?.();
          if (await askManualFallback("point", error)) {
            result.manualPoint = { reason: String(error?.message || "") };
          } else {
            throw error;
          }
        }
      }

      return result;
    },
    appendFormData(fd, order) {
      if (order?.qr) {
        fd.append("mp_order_id", String(order.qr.id || ""));
        fd.append("mp_payment_id", String(order.qr.payment_id || ""));
        fd.append("mp_external_reference", String(order.qr.external_reference || ""));
      }
      if (order?.point) {
        fd.append("mp_point_order_id", String(order.point.id || ""));
        fd.append("mp_point_payment_id", String(order.point.payment_id || ""));
        fd.append("mp_point_external_reference", String(order.point.external_reference || ""));
      }
      if (order?.manualQr) {
        fd.append("mp_qr_manual_fallback", "1");
        fd.append("mp_qr_manual_reason", String(order.manualQr.reason || "").slice(0, 240));
      }
      if (order?.manualPoint) {
        fd.append("mp_point_manual_fallback", "1");
        fd.append("mp_point_manual_reason", String(order.manualPoint.reason || "").slice(0, 240));
      }
      if (order?.manual) {
        fd.append("mp_manual_fallback", "1");
        fd.append("mp_manual_kind", String(order.manual.kind || "qr"));
        fd.append("mp_manual_reason", String(order.manual.reason || "").slice(0, 240));
      }
    },
  };
})();

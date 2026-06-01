document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("mpQrForm");
  if (!form) return;

  const amount = document.getElementById("mpQrAmount");
  const mode = document.getElementById("mpQrMode");
  const description = document.getElementById("mpQrDescription");
  const cancelBtn = document.getElementById("mpQrCancel");
  const qrBox = document.getElementById("mpQrQrBox");
  const qrImage = document.getElementById("mpQrImage");
  const noQr = document.getElementById("mpQrNoQr");
  const statusTitle = document.getElementById("mpQrStatusTitle");
  const orderIdEl = document.getElementById("mpQrOrderId");
  const referenceEl = document.getElementById("mpQrReference");
  const paymentIdEl = document.getElementById("mpQrPaymentId");
  const detailEl = document.getElementById("mpQrDetail");

  const csrf = String(form.dataset.csrf || "");
  let pollTimer = null;
  let currentOrderId = "";

  function setStatus(text, kind = "idle") {
    statusTitle.textContent = text;
    statusTitle.dataset.kind = kind;
  }

  function setOrder(order) {
    currentOrderId = String(order?.id || "");
    const status = String(order?.status || "unknown");
    const detail = String(order?.status_detail || order?.payment_status_detail || "-");

    orderIdEl.textContent = currentOrderId || "-";
    referenceEl.textContent = String(order?.external_reference || "-");
    paymentIdEl.textContent = String(order?.payment_id || "-");
    detailEl.textContent = `${status}${detail && detail !== "-" ? " / " + detail : ""}`;

    if (order?.approved) {
      setStatus("Pago aprobado", "ok");
      stopPolling();
    } else if (["expired", "canceled", "refunded"].includes(status)) {
      setStatus(`Order ${status}`, "bad");
      stopPolling();
    } else {
      setStatus("Esperando pago", "waiting");
    }

    if (order?.qr_data) {
      const encoded = encodeURIComponent(String(order.qr_data));
      qrImage.src = `https://api.qrserver.com/v1/create-qr-code/?size=320x320&margin=12&data=${encoded}`;
      qrBox.hidden = false;
      noQr.hidden = true;
    } else {
      qrBox.hidden = true;
      noQr.hidden = false;
      noQr.textContent = "Esta order no devolvio QR dinamico. Si usaste modo estatico/hibrido, escanea el QR impreso de la caja.";
    }

    cancelBtn.disabled = !currentOrderId || Boolean(order?.terminal);
  }

  function stopPolling() {
    if (pollTimer) window.clearInterval(pollTimer);
    pollTimer = null;
  }

  async function api(action, payload = null, method = "POST") {
    const options = {
      method,
      credentials: "same-origin",
      headers: { "Accept": "application/json" },
    };

    if (payload) {
      options.headers["Content-Type"] = "application/json";
      options.headers["X-CSRF-Token"] = csrf;
      options.body = JSON.stringify(payload);
    }

    const response = await fetch(`api/index.php?action=${encodeURIComponent(action)}`, options);
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) {
      throw new Error(data.error || `Error HTTP ${response.status}`);
    }
    return data;
  }

  async function poll() {
    if (!currentOrderId) return;
    try {
      const params = new URLSearchParams({ action: "mp_qr_status", order_id: currentOrderId });
      const response = await fetch(`api/index.php?${params.toString()}`, {
        credentials: "same-origin",
        headers: { "Accept": "application/json" },
      });
      const data = await response.json();
      if (!response.ok || !data.ok) throw new Error(data.error || "No se pudo consultar estado");
      setOrder(data.order);
    } catch (error) {
      setStatus(error.message || "Error consultando estado", "bad");
    }
  }

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (form.dataset.configured !== "1") return;

    stopPolling();
    setStatus("Creando order...", "waiting");
    cancelBtn.disabled = true;
    qrBox.hidden = true;
    noQr.hidden = false;
    noQr.textContent = "Preparando QR...";

    try {
      const rawAmount = Number(amount.value || 0);
      const minAmount = Number(amount.min || 15);
      if (rawAmount < minAmount) {
        throw new Error(`Mercado Pago requiere un importe minimo de $${minAmount.toFixed(2).replace(".", ",")}.`);
      }
      const data = await api("mp_qr_create", {
        amount: rawAmount,
        mode: mode.value,
        description: description.value,
      });
      setOrder(data.order);
      if (!data.order?.terminal) {
        pollTimer = window.setInterval(poll, 2000);
      }
    } catch (error) {
      setStatus(error.message || "No se pudo crear la order", "bad");
      noQr.textContent = "No se pudo generar el QR.";
    }
  });

  cancelBtn.addEventListener("click", async () => {
    if (!currentOrderId) return;
    try {
      setStatus("Cancelando order...", "waiting");
      await api("mp_qr_cancel", { order_id: currentOrderId });
      await poll();
    } catch (error) {
      setStatus(error.message || "No se pudo cancelar", "bad");
    }
  });
});

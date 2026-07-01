// public/assets/js/caja_movimientos_modal.js
document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("btnMovimientosCaja");
  const modal = document.getElementById("cajaMovimientoModal");
  const form = document.getElementById("cajaMovimientoForm");
  const status = document.getElementById("cajaMovimientoStatus");
  const requestUid = document.getElementById("cajaMovimientoRequestUid");
  const concepto = document.getElementById("cajaMovimientoConcepto");
  const monto = document.getElementById("cajaMovimientoMonto");
  const submit = document.getElementById("cajaMovimientoSubmit");
  const historyToggle = document.getElementById("cajaMovimientoHistoryToggle");
  const historyStatus = document.getElementById("cajaMovimientoHistoryStatus");
  const historyList = document.getElementById("cajaMovimientoHistoryList");
  const productInput = document.getElementById("codigo");

  if (!btn || !modal || !form) return;

  let submitting = false;

  function newUid() {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID();
    return `mov_${Date.now()}_${Math.random().toString(36).slice(2, 12)}`;
  }

  function notify(kind, text) {
    const msg = String(text || "");
    if (window.Notif) {
      if (kind === "success" || kind === "ok") return window.Notif.exito(msg);
      if (kind === "error" || kind === "danger") return window.Notif.error(msg);
      return window.Notif.advertencia(msg);
    }
  }

  function setStatus(text, kind = "info") {
    if (!status) return;
    const msg = String(text || "");
    status.textContent = msg;
    status.dataset.kind = kind;
    status.classList.toggle("hidden", msg === "");
  }

  function setHistoryStatus(text, kind = "info") {
    if (!historyStatus) return;
    const msg = String(text || "");
    historyStatus.textContent = msg;
    historyStatus.dataset.kind = kind;
    historyStatus.classList.toggle("hidden", msg === "");
  }

  function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, (char) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#039;",
    })[char]);
  }

  function renderHistory(items) {
    if (!historyList) return;
    if (!Array.isArray(items) || items.length === 0) {
      historyList.innerHTML = '<div class="caja-movimiento-history__empty">Todavia no hay movimientos.</div>';
      return;
    }

    historyList.innerHTML = items.map((item) => {
      const tipo = item.tipo === "egreso" ? "egreso" : "ingreso";
      const sign = tipo === "egreso" ? "-" : "+";
      return `
        <div class="caja-movimiento-history__item" data-tipo="${tipo}">
          <div>
            <strong>${escapeHtml(item.concepto || "-")}</strong>
            <span>${escapeHtml(item.fecha || "-")} · ${escapeHtml(item.usuario || "-")}</span>
          </div>
          <b>${sign} ${escapeHtml(item.monto_fmt || "")}</b>
        </div>
      `;
    }).join("");
  }

  async function loadHistory() {
    if (!historyToggle || !historyList) return;

    const isOpen = !historyList.classList.contains("hidden");
    if (isOpen) {
      historyList.classList.add("hidden");
      setHistoryStatus("");
      historyToggle.textContent = "Mostrar";
      return;
    }

    historyToggle.disabled = true;
    historyToggle.textContent = "Cargando...";
    setHistoryStatus("Cargando ultimos movimientos...");

    try {
      const url = new URL("caja_movimientos.php", window.location.href);
      url.searchParams.set("response", "json");
      url.searchParams.set("limit", "6");
      const res = await fetch(url.toString(), {
        method: "GET",
        headers: {
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
      });
      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        throw new Error(data?.message || "No se pudieron cargar los movimientos.");
      }
      renderHistory(data.items || []);
      historyList.classList.remove("hidden");
      setHistoryStatus("");
      historyToggle.textContent = "Ocultar";
    } catch (err) {
      setHistoryStatus(err?.message || "No se pudieron cargar los movimientos.", "error");
      historyToggle.textContent = "Mostrar";
    } finally {
      historyToggle.disabled = false;
    }
  }

  function resetForm() {
    form.reset();
    if (requestUid) requestUid.value = newUid();
    setStatus("");
  }

  function openModal(event) {
    event.preventDefault();
    resetForm();
    modal.classList.remove("hidden");
    modal.setAttribute("aria-hidden", "false");
    setTimeout(() => concepto?.focus?.(), 30);
  }

  function closeModal() {
    modal.classList.add("hidden");
    modal.setAttribute("aria-hidden", "true");
    setStatus("");
    setTimeout(() => productInput?.focus?.(), 0);
  }

  btn.addEventListener("click", openModal);

  modal.querySelectorAll("[data-caja-movimiento-close]").forEach((el) => {
    el.addEventListener("click", closeModal);
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (submitting) return;

    const conceptoText = String(concepto?.value || "").trim();
    const montoText = String(monto?.value || "").trim();

    if (!conceptoText) {
      setStatus("Ingresa un concepto.", "error");
      concepto?.focus?.();
      return;
    }

    if (!montoText) {
      setStatus("Ingresa un monto.", "error");
      monto?.focus?.();
      return;
    }

    submitting = true;
    if (submit) {
      submit.disabled = true;
      submit.textContent = "Guardando...";
    }
    setStatus("Registrando movimiento...");

    try {
      const res = await fetch(form.action || "caja_movimientos.php", {
        method: "POST",
        body: new FormData(form),
        headers: {
          Accept: "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
      });

      const data = await res.json().catch(() => null);
      if (!res.ok || !data?.ok) {
        throw new Error(data?.message || "No se pudo registrar el movimiento.");
      }

      closeModal();
      resetForm();
      if (historyList && !historyList.classList.contains("hidden")) {
        historyList.classList.add("hidden");
        historyToggle.textContent = "Mostrar";
      }
      notify("success", data.message || "Movimiento registrado.");
    } catch (err) {
      setStatus(err?.message || "No se pudo registrar el movimiento.", "error");
    } finally {
      submitting = false;
      if (submit) {
        submit.disabled = false;
        submit.textContent = "Guardar movimiento";
      }
    }
  });

  historyToggle?.addEventListener("click", loadHistory);

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !modal.classList.contains("hidden")) {
      closeModal();
    }
  });
});

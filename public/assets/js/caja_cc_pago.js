// public/assets/js/caja_cc_pago.js
// Cobro de Cuenta Corriente desde Caja (solo EFECTIVO)
// No toca el flujo de venta: agrega un modal independiente.

document.addEventListener("DOMContentLoaded", () => {
  const btn = document.getElementById("btnCobroCC");
  const modal = document.getElementById("modalCcPago");
  if (!btn || !modal) return;

  const inputBuscar = document.getElementById("ccPagoBuscar");
  const inputClienteId = document.getElementById("ccPagoClienteId");
  const info = document.getElementById("ccPagoInfo");
  const inMonto = document.getElementById("ccPagoMonto");
  const inRef = document.getElementById("ccPagoRef");
  const btnCancel = document.getElementById("ccPagoCancel");
  const btnConfirm = document.getElementById("ccPagoConfirm");

  const msgEl = document.getElementById("msg");

  function getCsrf() {
    const m = document.querySelector('meta[name="csrf-token"]');
    return m ? (m.getAttribute("content") || "") : "";
  }

  function showMsg(type, text) {
    if (!msgEl) return alert(text);
    msgEl.classList.remove("msg-ok","msg-error","msg-success","msg-warning","msg-visible");
    const cls = (type === "success" || type === "ok") ? "msg-success" :
                (type === "warning") ? "msg-warning" : "msg-error";
    msgEl.classList.add(cls, "msg-visible");
    msgEl.textContent = text;
    setTimeout(() => {
      msgEl.classList.remove("msg-visible");
    }, 4500);
  }

  function openModal() {
    modal.classList.remove("hidden");
    modal.setAttribute("aria-hidden","false");
    // reset
    if (inputBuscar) inputBuscar.value = "";
    if (inputClienteId) inputClienteId.value = "";
    if (info) info.textContent = "";
    if (inMonto) inMonto.value = "";
    if (inRef) inRef.value = "";
    hideDropdown();
    setTimeout(() => inputBuscar?.focus?.(), 0);
  }

  function closeModal() {
    modal.classList.add("hidden");
    modal.setAttribute("aria-hidden","true");
    hideDropdown();
  }

  // --- Autocomplete clientes CC (reusa API index buscar_clientes_cc) ---
  let dropdown = null;
  let results = [];
  let sel = -1;
  let abortCtrl = null;

  function ensureDropdown() {
    if (!inputBuscar) return;
    if (dropdown) return;
    dropdown = document.createElement("div");
    dropdown.className = "autocomplete-dropdown";
    dropdown.style.position = "absolute";
    dropdown.style.display = "none";
    dropdown.style.zIndex = "99999";
    inputBuscar.parentElement.style.position = "relative";
    inputBuscar.parentElement.appendChild(dropdown);
  }

  function hideDropdown() {
    if (!dropdown) return;
    dropdown.style.display = "none";
    results = [];
    sel = -1;
  }

  function esc(s) {
    return String(s || "").replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
  }

  function renderDropdown() {
    if (!dropdown) return;
    if (!results.length) return hideDropdown();

    dropdown.innerHTML = results.map((c, i) => {
      const selected = (i === sel) ? "selected" : "";
      const saldo = Number(c.cc_saldo || 0).toFixed(2);
      const lim = Number(c.cc_limite || 0).toFixed(2);
      return `
        <div class="autocomplete-item ${selected}" data-index="${i}">
          <div class="ac-title">${esc(c.nombre)}</div>
          <div class="ac-meta">CUIT: ${esc(c.cuit || '-')} · Tel: ${esc(c.telefono || '-')} · Saldo: $${saldo} · Límite: $${lim}</div>
        </div>`;
    }).join("");

    dropdown.style.display = "block";
  }

  function selectClient(index) {
    const c = results[index];
    if (!c) return;
    if (inputBuscar) inputBuscar.value = c.nombre || "";
    if (inputClienteId) inputClienteId.value = String(c.id || "");
    if (info) {
      const saldo = Number(c.cc_saldo || 0).toFixed(2);
      const lim = Number(c.cc_limite || 0).toFixed(2);
      info.textContent = `Saldo actual: $${saldo} · Límite: $${lim}`;
    }
    hideDropdown();
  }

  async function searchClients(q) {
    q = String(q || "").trim();
    if (q.length < 2) return hideDropdown();

    ensureDropdown();
    if (abortCtrl) abortCtrl.abort();
    abortCtrl = new AbortController();

    try {
      const res = await fetch(`api/index.php?action=buscar_clientes_cc&q=${encodeURIComponent(q)}`, {
        signal: abortCtrl.signal,
        credentials: "same-origin",
        headers: { "Accept": "application/json" }
      });
      const data = await res.json();
      if (data?.ok && Array.isArray(data.clientes)) {
        results = data.clientes;
        sel = -1;
        renderDropdown();
      } else hideDropdown();
    } catch (e) {
      if (e?.name !== "AbortError") console.warn("CC search error", e);
      hideDropdown();
    }
  }

  // events autocomplete
  ensureDropdown();

  inputBuscar?.addEventListener("input", () => searchClients(inputBuscar.value));

  inputBuscar?.addEventListener("keydown", (e) => {
    if (!dropdown || dropdown.style.display === "none") return;

    if (e.key === "ArrowDown") {
      e.preventDefault();
      sel = Math.min(sel + 1, results.length - 1);
      renderDropdown();
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      sel = Math.max(sel - 1, 0);
      renderDropdown();
    } else if (e.key === "Enter") {
      if (sel >= 0) {
        e.preventDefault();
        selectClient(sel);
      }
    } else if (e.key === "Escape") {
      hideDropdown();
    }
  });

  dropdown?.addEventListener("mousedown", (e) => {
    const el = e.target.closest(".autocomplete-item");
    if (!el) return;
    const idx = Number(el.getAttribute("data-index"));
    if (Number.isFinite(idx)) selectClient(idx);
  });

  document.addEventListener("click", (e) => {
    if (!dropdown) return;
    if (e.target === inputBuscar || dropdown.contains(e.target)) return;
    hideDropdown();
  });

  // --- Registrar pago ---
  async function registrarPago() {
    const clienteId = Number(inputClienteId?.value || 0);
    const monto = Number(String(inMonto?.value || "0").replace(",", ".")) || 0;

    if (!clienteId) return showMsg("error", "Seleccioná un cliente.");
    if (!(monto > 0)) return showMsg("error", "Ingresá un monto mayor a 0.");

    btnConfirm.disabled = true;
    btnConfirm.textContent = "Procesando...";

    try {
      const fd = new FormData();
      fd.append("action", "registrar_pago"); // compat
      fd.append("from_caja", "1");
      fd.append("cliente_id", String(clienteId));
      fd.append("monto", String(monto.toFixed(2)));
      fd.append("medio_pago", "EFECTIVO");
      const ref = String(inRef?.value || "").trim();
      if (ref) fd.append("referencia", ref);
      fd.append("concepto", "Cobro CC en Caja");

      const csrf = getCsrf();
      if (csrf) fd.append("csrf_token", csrf);

      const res = await fetch("api/cuenta_corriente_api.php?action=registrar_pago", {
        method: "POST",
        body: fd,
        credentials: "same-origin",
        headers: { "Accept": "application/json" }
      });

      const data = await res.json().catch(() => null);

      if (!res.ok || !data?.success) {
        const msg = data?.error || data?.message || `Error (HTTP ${res.status})`;
        throw new Error(msg);
      }

      const saldoPost = Number(data?.saldo_posterior ?? NaN);
      const txtSaldo = Number.isFinite(saldoPost) ? ` · Nuevo saldo: $${saldoPost.toFixed(2)}` : "";
      showMsg("success", `✓ Pago CC registrado${txtSaldo}`);
      closeModal();

    } catch (e) {
      console.error(e);
      showMsg("error", e?.message || "No se pudo registrar el pago CC");
    } finally {
      btnConfirm.disabled = false;
      btnConfirm.textContent = "Registrar pago";
    }
  }

  // --- UI binds ---
  btn.addEventListener("click", openModal);
  btnCancel?.addEventListener("click", closeModal);
  btnConfirm?.addEventListener("click", registrarPago);

  // cerrar al click afuera
  modal.addEventListener("mousedown", (e) => {
    if (e.target === modal) closeModal();
  });

  document.addEventListener("keydown", (e) => {
    if (modal.classList.contains("hidden")) return;
    if (e.key === "Escape") closeModal();
  });
});

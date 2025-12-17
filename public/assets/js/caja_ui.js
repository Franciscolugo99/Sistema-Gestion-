document.addEventListener("DOMContentLoaded", () => {
  // =========================
  // CERRAR CAJA (solo si existe el botón)
  // =========================
  const btnCerrar = document.getElementById("btnCerrarCaja");
  if (btnCerrar) {
    btnCerrar.addEventListener("click", () => {
      const id = btnCerrar.dataset.cajaId;

      // Si por algún motivo no hay ID, no hacemos nada (no alert, no parche)
      if (!id) return;

      window.location.href = `caja_cerrar.php?id=${encodeURIComponent(id)}`;
    });
  }

  // =========================
  // APERTURA (solo si existe el form)
  // =========================
  const formApertura = document.getElementById("formAperturaCaja");
  const inputSaldo   = document.getElementById("saldo_inicial");
  const aviso        = document.getElementById("aperturaAviso");

  if (!formApertura || !inputSaldo) return;

  const MIN_SALDO_SUG = 5000; // ajustable

  function parseSaldo(v) {
    // Soporta "10.000,50" o "10000.50"
    const s = String(v ?? "").trim();
    const norm = s.replace(/\./g, "").replace(",", ".");
    const n = parseFloat(norm);
    return Number.isFinite(n) ? n : 0;
  }

  function actualizarAviso() {
    if (!aviso) return;

    const valor = parseSaldo(inputSaldo.value);
    if (valor > 0 && valor < MIN_SALDO_SUG) {
      aviso.textContent =
        `Saldo inicial bajo: $${valor.toFixed(2)}. Revisá si es suficiente para el turno.`;
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
});

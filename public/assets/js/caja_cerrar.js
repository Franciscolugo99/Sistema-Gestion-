// assets/js/caja_cerrar.js

document.addEventListener("DOMContentLoaded", () => {
  // Autofocus en el input de saldo cuando la caja está abierta
  const saldoInput = document.getElementById("saldo_declarado");
  if (saldoInput) {
    saldoInput.focus();
    saldoInput.select();
  }
});

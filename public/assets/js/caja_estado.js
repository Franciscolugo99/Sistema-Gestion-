// assets/js/caja_estado.js
document.addEventListener("DOMContentLoaded", () => {
  const btnCerrar = document.getElementById("btnCerrarCaja");
  if (btnCerrar) {
    btnCerrar.addEventListener("click", () => {
      window.location.href = "caja_cerrar.php";
    });
  }
});

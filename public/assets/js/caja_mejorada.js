// public/assets/js/caja_mejorada.js
document.addEventListener("DOMContentLoaded", () => {
  const section = document.getElementById("movimientosSection");
  if (!section) return;

  const header = section.querySelector(".movimientos-header");
  if (!header) return;

  header.addEventListener("click", (e) => {
    // Si clickean un link/botón dentro del header, no colapsar
    if (e.target.closest("a,button,input,select")) return;
    section.classList.toggle("collapsed");
  });
});

/* ============================================================================
   FLUS - STOCK.JS
   Control de:
   - Mostrar/Ocultar sección “A Reponer”
   - Persistir estado en localStorage
   - Manejo de paginación “reponer_page”
   - Cambio dinámico del selector “limit”
   - Scroll suave al navegar con hashes
============================================================================ */

document.addEventListener("DOMContentLoaded", () => {
  /* ==========================================================================
     [01] ELEMENTOS
  ========================================================================== */
  const toggleBtn = document.getElementById("btnToggleReponer");
  const section = document.getElementById("reponerSection");
  const limitSel = document.getElementById("limitSel");
  const filtersForm = document.getElementById("stockFilters");

  // Clave de persistencia de estado
  const STORAGE_KEY = "flus-stock-reponer-open";

  /* ==========================================================================
     [02] RESTAURAR ESTADO GUARDADO ("A REPONER")
  ========================================================================== */
  const saved = localStorage.getItem(STORAGE_KEY);

  if (saved === "1" && section) {
    section.classList.add("show");
  }

  /* ==========================================================================
     [03] BOTÓN: MOSTRAR / OCULTAR SECCIÓN
  ========================================================================== */
  toggleBtn?.addEventListener("click", () => {
    if (!section) return;

    const isOpen = section.classList.toggle("show");
    localStorage.setItem(STORAGE_KEY, isOpen ? "1" : "0");

    if (isOpen) {
      // desplazamiento suave al abrir
      setTimeout(() => {
        section.scrollIntoView({ behavior: "smooth", block: "start" });
      }, 200);
    }
  });

  /* ==========================================================================
     [04] AUTO-ABRIR SECCIÓN SI EXISTE "reponer_page" EN LA URL
  ========================================================================== */
  const params = new URLSearchParams(window.location.search);

  if (params.has("reponer_page") && section) {
    section.classList.add("show");
    localStorage.setItem(STORAGE_KEY, "1");
  }

  /* ==========================================================================
     [05] SELECTOR "limit" → cambia cantidad por página
  ========================================================================== */
  limitSel?.addEventListener("change", () => {
    if (!filtersForm) return;

    const pageInput = filtersForm.querySelector('input[name="page"]');
    if (pageInput) pageInput.value = "1";

    filtersForm.submit();
  });

  /* ==========================================================================
     [06] SCROLL SUAVE CUANDO SE USA #HASH (paginación)
  ========================================================================== */
  window.addEventListener("load", () => {
    const hash = window.location.hash;
    if (!hash) return;

    const target = document.querySelector(hash);
    if (!target) return;

    history.scrollRestoration = "manual";

    setTimeout(() => {
      target.scrollIntoView({ behavior: "smooth", block: "start" });
    }, 180);
  });
});

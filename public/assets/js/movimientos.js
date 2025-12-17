// public/assets/js/movimientos.js
document.addEventListener("DOMContentLoaded", () => {
  const form =
    document.getElementById("mov-filters") ||
    document.getElementById("movFilters");

  if (!form) return;

  const STORAGE_KEY = "kiosco-mov-filtros-v2";

  const params = new URLSearchParams(window.location.search);

  // Si venimos de limpiar: borrar storage y recargar sin params
  if (params.get("clear") === "1") {
    localStorage.removeItem(STORAGE_KEY);
    window.location.href = "movimientos.php";
    return;
  }

  const selProducto = form.querySelector('[name="producto_id"]');
  const selTipo = form.querySelector('[name="tipo"]');
  const inputDesde = form.querySelector('[name="desde"]');
  const inputHasta = form.querySelector('[name="hasta"]');

  const tieneFiltrosUrl =
    params.has("producto_id") ||
    params.has("tipo") ||
    params.has("desde") ||
    params.has("hasta");

  if (!tieneFiltrosUrl) {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (raw) {
        const data = JSON.parse(raw);
        if (selProducto) selProducto.value = data.producto_id || "";
        if (selTipo) selTipo.value = data.tipo || "";
        if (inputDesde) inputDesde.value = data.desde || "";
        if (inputHasta) inputHasta.value = data.hasta || "";
      }
    } catch (e) {
      console.warn("No se pudieron cargar filtros", e);
    }
  }

  const guardar = () => {
    const data = {
      producto_id: selProducto?.value || "",
      tipo: selTipo?.value || "",
      desde: inputDesde?.value || "",
      hasta: inputHasta?.value || "",
    };
    localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
  };

  [selProducto, selTipo, inputDesde, inputHasta].forEach((el) =>
    el?.addEventListener("change", guardar)
  );
  form.addEventListener("submit", guardar);

  // Limpiar: borrar storage y navegar limpio
  const clearBtn = document.getElementById("movClearBtn");
  clearBtn?.addEventListener("click", () => {
    localStorage.removeItem(STORAGE_KEY);
  });

  // Quick ranges
  const chips = document.querySelectorAll(".filters-quick .chip[data-range]");

  chips.forEach((chip) => {
    chip.addEventListener("click", () => {
      if (!inputDesde || !inputHasta) return;

      const range = chip.dataset.range;
      const today = new Date();
      let desde = new Date(today);

      if (range === "today") {
        // desde = hoy
      }

      if (range === "7d") {
        desde.setDate(today.getDate() - 6);
      }

      if (range === "30d") {
        desde.setDate(today.getDate() - 29);
      }

      const toStr = today.toISOString().split("T")[0];
      const fromStr = desde.toISOString().split("T")[0];

      inputHasta.value = toStr;
      inputDesde.value = fromStr;

      guardar();
      form.submit();
    });
  });
});

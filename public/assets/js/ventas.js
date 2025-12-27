// public/assets/js/ventas.js
document.addEventListener("DOMContentLoaded", () => {
  const form =
    document.getElementById("ventasFilters") ||
    document.getElementById("ventasForm");

  // ---------------------------------------------
  // Scroll arriba
  // ---------------------------------------------
  const btnScrollTop = document.getElementById("btnScrollTop");
  if (btnScrollTop) {
    btnScrollTop.addEventListener("click", () => {
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
  }

  // ---------------------------------------------
  // PAPEL (80 / 58) – localStorage + aplicar a links
  // ---------------------------------------------
  const PAPER_KEY = "flus-paper-size";
  const paperSel = document.getElementById("paperSel");

  function normalizePaper(v) {
    return v === "58" ? "58" : "80";
  }

  function getPaper() {
    try {
      return normalizePaper(localStorage.getItem(PAPER_KEY) || "80");
    } catch {
      return "80";
    }
  }

  function setPaper(v) {
    const paper = normalizePaper(v);
    try {
      localStorage.setItem(PAPER_KEY, paper);
    } catch {}
    if (paperSel) paperSel.value = paper;
    applyPaperToLinks(paper);
    return paper;
  }

  function setUrlParam(href, key, value) {
    try {
      const u = new URL(href, window.location.href);
      u.searchParams.set(key, value);
      return u.pathname + "?" + u.searchParams.toString();
    } catch {
      const hasQ = href.includes("?");
      const re = new RegExp("([?&])" + key + "=([^&]*)");
      if (re.test(href))
        return href.replace(re, `$1${key}=${encodeURIComponent(value)}`);
      return href + (hasQ ? "&" : "?") + `${key}=${encodeURIComponent(value)}`;
    }
  }

  function applyPaperToLinks(paper) {
    const links = document.querySelectorAll('a[href^="ticket.php"]');
    links.forEach((a) => {
      const href = a.getAttribute("href") || "";
      a.setAttribute("href", setUrlParam(href, "paper", paper));
    });
  }

  const initPaper = getPaper();
  if (paperSel) paperSel.value = initPaper;
  applyPaperToLinks(initPaper);

  if (paperSel) {
    paperSel.addEventListener("change", () => setPaper(paperSel.value));
  }

  // ---------------------------------------------
  // PERSISTENCIA FILTROS VENTAS (localStorage)
  // + FIX botón "Limpiar"
  // ---------------------------------------------
  const FILTERS_KEY = "flus-ventas-filters-v1";

  function clearFiltersStorage() {
    try {
      localStorage.removeItem(FILTERS_KEY);
    } catch {}
  }

  // Botón limpiar (tu HTML: <a id="ventasClear" href="ventas.php" ...>Limpiar</a>)
  const clearLink = document.getElementById("ventasClear");
  if (clearLink) {
    clearLink.addEventListener("click", (e) => {
      e.preventDefault();
      clearFiltersStorage();

      // navega a ventas.php?clear=1 (robusto si estás en subcarpetas)
      const u = new URL(
        clearLink.getAttribute("href") || "ventas.php",
        window.location.href
      );
      u.searchParams.set("clear", "1");
      window.location.href = u.pathname + "?" + u.searchParams.toString();
    });
  }

  function qsHasMeaningfulFilters(url) {
    const u = new URL(url, window.location.href);
    const keys = [
      "medio",
      "estado",
      "desde",
      "hasta",
      "venta_id",
      "min_total",
      "max_total",
      "per_page",
      "page",
    ];
    return keys.some((k) => (u.searchParams.get(k) || "").trim() !== "");
  }

  function readFiltersFromForm() {
    if (!form) return null;
    const get = (id) => (document.getElementById(id)?.value || "").trim();

    return {
      medio: get("medio"),
      estado: get("estado"),
      desde: get("desde"),
      hasta: get("hasta"),
      per_page: get("per_page"),
      venta_id: get("venta_id"),
      min_total: get("min_total"),
      max_total: get("max_total"),
      page: get("page") || "1",
    };
  }

  function saveFilters(obj) {
    try {
      localStorage.setItem(FILTERS_KEY, JSON.stringify(obj));
    } catch {}
  }

  function loadFilters() {
    try {
      const raw = localStorage.getItem(FILTERS_KEY);
      if (!raw) return null;
      const obj = JSON.parse(raw);
      return obj && typeof obj === "object" ? obj : null;
    } catch {
      return null;
    }
  }

  function buildUrlFromFilters(obj) {
    const u = new URL(window.location.href);
    u.search = "";
    Object.entries(obj || {}).forEach(([k, v]) => {
      const val = (v ?? "").toString().trim();
      if (val !== "") u.searchParams.set(k, val);
    });
    return (
      u.pathname +
      (u.searchParams.toString() ? "?" + u.searchParams.toString() : "")
    );
  }

  // Si viene ?clear=1 => ya borramos storage en el click, pero por si entran directo:
  const urlNow = new URL(window.location.href);
  if (urlNow.searchParams.has("clear")) {
    clearFiltersStorage();
    urlNow.searchParams.delete("clear");
    window.location.replace(
      urlNow.pathname +
        (urlNow.searchParams.toString()
          ? "?" + urlNow.searchParams.toString()
          : "")
    );
    return;
  }

  // Caso A: entré a ventas.php SIN filtros en la URL => restaurar desde localStorage
  if (!qsHasMeaningfulFilters(window.location.href)) {
    const saved = loadFilters();
    if (saved) {
      const target = buildUrlFromFilters(saved);
      if (target !== window.location.pathname + window.location.search) {
        window.location.replace(target);
        return;
      }
    }
  } else {
    // Caso B: URL ya tiene filtros => guardarlos como “último estado”
    const fromForm = readFiltersFromForm();
    if (fromForm) saveFilters(fromForm);
  }

  // Guardar al submit
  if (form) {
    form.addEventListener("submit", () => {
      const obj = readFiltersFromForm();
      if (obj) saveFilters(obj);
    });
  }

  // ---------------------------------------------
  // RANGOS RÁPIDOS (chips)
  // ---------------------------------------------
  const desde = document.getElementById("desde");
  const hasta = document.getElementById("hasta");
  const chips = document.querySelectorAll(".chip[data-range]");

  function fmt(d) {
    const m = String(d.getMonth() + 1).padStart(2, "0");
    const day = String(d.getDate()).padStart(2, "0");
    return `${d.getFullYear()}-${m}-${day}`;
  }

  chips.forEach((chip) => {
    chip.addEventListener("click", () => {
      const now = new Date();
      const r = chip.getAttribute("data-range");
      const d1 = new Date(now);
      const d2 = new Date(now);

      if (r === "7d") d1.setDate(d1.getDate() - 6);
      else if (r === "30d") d1.setDate(d1.getDate() - 29);
      // today => mismo día

      if (desde) desde.value = fmt(d1);
      if (hasta) hasta.value = fmt(d2);

      const page = document.getElementById("page");
      if (page) page.value = "1";

      if (form) form.submit();
    });
  });

  // ---------------------------------------------
  // CAMBIO per_page
  // ---------------------------------------------
  const perPageSel = document.getElementById("per_page");
  if (perPageSel) {
    perPageSel.addEventListener("change", () => {
      const page = document.getElementById("page");
      if (page) page.value = "1";
      if (form) form.submit();
    });
  }
});

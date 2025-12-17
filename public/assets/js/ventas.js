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
      // fallback simple
      const hasQ = href.includes("?");
      const re = new RegExp("([?&])" + key + "=([^&]*)");
      if (re.test(href)) return href.replace(re, `$1${key}=${encodeURIComponent(value)}`);
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

  // Init papel: si hay localStorage, manda eso (no el default del select)
  const initPaper = getPaper();
  if (paperSel) paperSel.value = initPaper;
  applyPaperToLinks(initPaper);

  if (paperSel) {
    paperSel.addEventListener("change", () => setPaper(paperSel.value));
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

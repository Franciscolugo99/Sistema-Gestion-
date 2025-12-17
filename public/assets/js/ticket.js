document.addEventListener("DOMContentLoaded", () => {
  const auto = document.body?.dataset?.autoprint === "1";

  const btn = document.getElementById("btnPrint");
  btn?.addEventListener("click", () => {
    window.focus();
    window.print();
  });

  if (!auto) return;

  window.addEventListener("load", () => {
    setTimeout(() => {
      window.focus();
      window.print();
    }, 250);
  });
});

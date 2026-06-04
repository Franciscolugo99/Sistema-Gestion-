(function () {
  function ready(fn) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", fn, { once: true });
      return;
    }
    fn();
  }

  ready(function () {
    document.querySelectorAll("form.js-fiscal-confirm").forEach(function (form) {
      form.addEventListener("submit", async function (event) {
        if (form.dataset.confirmed === "1") return;
        event.preventDefault();

        if (!window.Notif || typeof window.Notif.confirmar !== "function") {
          form.dataset.confirmed = "1";
          form.submit();
          return;
        }

        var title = form.dataset.confirmTitle || "Confirmar accion fiscal";
        var body = "<p>" + (form.dataset.confirmBody || "Esta accion modifica el estado fiscal local.") + "</p>";
        var ok = await window.Notif.confirmar(title, body, {
          icon: "warning",
          confirmText: "Continuar",
          cancelText: "Cancelar",
        });

        if (!ok) return;
        form.dataset.confirmed = "1";
        form.submit();
      });
    });
  });
})();

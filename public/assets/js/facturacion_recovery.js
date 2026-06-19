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

        if (form.dataset.confirmPending === "1") return;
        if (!window.Notif || typeof window.Notif.confirmar !== "function") return;

        form.dataset.confirmPending = "1";
        var submitter = event.submitter;
        if (submitter) submitter.disabled = true;

        try {
          var title = form.dataset.confirmTitle || "Confirmar acción fiscal";
          var body = form.dataset.confirmBody || "Esta acción modifica el estado fiscal local.";
          var ok = await window.Notif.confirmar(title, body, {
            icon: "warning",
            confirmText: form.dataset.confirmText || "Continuar",
            cancelText: "Cancelar",
            useText: true,
            danger: form.dataset.confirmDanger === "true",
          });

          if (!ok) return;
          form.dataset.confirmed = "1";
          HTMLFormElement.prototype.submit.call(form);
        } finally {
          form.dataset.confirmPending = "0";
          if (submitter) submitter.disabled = false;
        }
      });
    });
  });
})();

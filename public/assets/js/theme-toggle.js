// public/assets/js/theme-toggle.js
(() => {
  if (window.__flusThemeBound) return;
  window.__flusThemeBound = true;

  const getCookie = (name) => {
    const escaped = name.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    const m = document.cookie.match(
      new RegExp("(?:^|; )" + escaped + "=([^;]*)")
    );
    return m ? decodeURIComponent(m[1]) : "";
  };

  const setCookie = (name, value) => {
    document.cookie =
      encodeURIComponent(name) +
      "=" +
      encodeURIComponent(value) +
      "; path=/; max-age=31536000; samesite=lax";
  };

  const applyTheme = (theme) => {
    // compat: por si CSS usa body o html
    document.body.dataset.theme = theme;
    document.documentElement.dataset.theme = theme;
    localStorage.setItem("theme", theme);
    setCookie("theme", theme);
  };

  document.addEventListener("DOMContentLoaded", () => {
    const toggle = document.getElementById("toggleTheme");
    if (!toggle) return;

    // tu sistema usa cookie "theme" y default dark
    const current =
      document.body.dataset.theme ||
      getCookie("theme") ||
      localStorage.getItem("theme") ||
      "dark";

    applyTheme(current);

    // ✅ mapping típico: checked = DARK
    toggle.checked = current === "dark";

    toggle.addEventListener("change", () => {
      const next = toggle.checked ? "dark" : "light";
      applyTheme(next);
    });
  });
})();

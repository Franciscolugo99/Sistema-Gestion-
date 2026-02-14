// public/assets/js/nav-dropdown.js
(function () {
  function closeAll(exceptMenu) {
    document.querySelectorAll('.nav-dropdown-menu.open').forEach((m) => {
      if (exceptMenu && m === exceptMenu) return;
      m.classList.remove('open');
      const wrap = m.closest('.nav-dropdown');
      const btn = wrap ? wrap.querySelector('.nav-dropdown-btn') : null;
      if (btn) btn.setAttribute('aria-expanded', 'false');
    });
  }

  function init() {
    document.querySelectorAll('.nav-dropdown').forEach((wrap) => {
      const btn = wrap.querySelector('.nav-dropdown-btn');
      const menu = wrap.querySelector('.nav-dropdown-menu');
      if (!btn || !menu) return;

      // evita doble bind si el script se carga 2 veces
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';

      btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();

        const isOpen = menu.classList.contains('open');
        closeAll(menu);

        if (!isOpen) {
          menu.classList.add('open');
          btn.setAttribute('aria-expanded', 'true');
        } else {
          menu.classList.remove('open');
          btn.setAttribute('aria-expanded', 'false');
        }
      });

      // click dentro del menú no debe cerrarlo (a menos que sea link)
      menu.addEventListener('click', (e) => e.stopPropagation());
    });

    // cerrar al click afuera
    document.addEventListener('click', () => closeAll());

    // cerrar con ESC
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeAll();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

// public/assets/js/clientes.js
// Toggle del formulario de clientes (Agregar/Cerrar)

document.addEventListener('DOMContentLoaded', () => {
  const btn = document.getElementById('toggleCliFormBtn');
  const block = document.querySelector('.cli-form-block');
  if (!btn || !block) return;

  const isCollapsed = () => block.classList.contains('is-collapsed');

  const sync = () => {
    btn.textContent = isCollapsed() ? 'Agregar cliente' : 'Cerrar formulario';
  };

  sync();

  btn.addEventListener('click', () => {
    block.classList.toggle('is-collapsed');
    sync();

    // Si lo abrimos, llevamos el foco al primer input
    if (!isCollapsed()) {
      const first = block.querySelector('input[name="nombre"]');
      if (first) {
        first.focus({ preventScroll: true });
        // scroll suave al panel
        try {
          block.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (_) {}
      }
    }
  });
});

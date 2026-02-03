/* FLUS - Inventario Físico (inventario_fisico.js)
   - Búsqueda de productos + selección
   - Separado de inventario_fisico.php
*/

(function () {
  'use strict';

  let searchTimeout = null;

  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = String(text ?? '');
    return div.innerHTML;
  }

  function $(id) {
    return document.getElementById(id);
  }

  function initBusqueda() {
    const searchInput = $('buscarProducto');
    const searchResults = $('searchResults');
    const formConteo = $('formConteo');

    if (!searchInput || !searchResults || !formConteo) return;

    function hideResults() {
      searchResults.classList.remove('show');
      searchResults.innerHTML = '';
    }

    searchInput.addEventListener('input', function () {
      clearTimeout(searchTimeout);
      const q = this.value.trim();

      if (q.length < 2) {
        hideResults();
        return;
      }

      searchTimeout = setTimeout(() => {
        fetch('api/system_api.php?action=inventario_buscar_producto&q=' + encodeURIComponent(q), {
          headers: { 'Accept': 'application/json' },
        })
          .then((r) => r.json())
          .then((data) => {
            const ok = data && (data.ok === true || data.success === true);
            const arr = (data && (data.productos || data.data)) || [];

            if (ok && Array.isArray(arr) && arr.length > 0) {
              searchResults.innerHTML = arr
                .map((p) => {
                  const id = Number(p.id || 0);
                  const codigo = escapeHtml(p.codigo || '');
                  const nombre = escapeHtml(p.nombre || '');
                  const stock = Number(p.stock || 0);
                  return (
                    '<div class="search-result-item" data-id="' +
                    id +
                    '" data-codigo="' +
                    codigo +
                    '" data-nombre="' +
                    nombre +
                    '" data-stock="' +
                    stock +
                    '">' +
                    '<div class="producto-codigo">' +
                    codigo +
                    '</div>' +
                    '<div class="producto-nombre">' +
                    nombre +
                    '</div>' +
                    '<div class="producto-stock">Stock: ' +
                    stock +
                    '</div>' +
                    '</div>'
                  );
                })
                .join('');
              searchResults.classList.add('show');
            } else {
              searchResults.innerHTML = '<div class="search-result-item">No se encontraron productos</div>';
              searchResults.classList.add('show');
            }
          })
          .catch(() => {
            searchResults.innerHTML = '<div class="search-result-item">Error al buscar productos</div>';
            searchResults.classList.add('show');
          });
      }, 250);
    });

    searchResults.addEventListener('click', function (e) {
      const item = e.target && e.target.closest ? e.target.closest('.search-result-item') : null;
      if (!item || item.textContent.includes('No se encontraron')) return;

      const id = Number(item.getAttribute('data-id') || 0);
      const codigo = item.getAttribute('data-codigo') || '';
      const nombre = item.getAttribute('data-nombre') || '';
      const stock = Number(item.getAttribute('data-stock') || 0);

      window.seleccionarProducto(id, codigo, nombre, stock);
    });

    document.addEventListener('click', function (e) {
      if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
        hideResults();
      }
    });
  }

  // Exponer helpers globales usados por el HTML
  window.seleccionarProducto = function (id, codigo, nombre, stock) {
    const productoId = $('productoId');
    const selCodigo = $('selCodigo');
    const selNombre = $('selNombre');
    const selStock = $('selStock');
    const searchInput = $('buscarProducto');
    const searchResults = $('searchResults');
    const formConteo = $('formConteo');
    const cantidad = $('cantidadContada');

    if (!productoId || !selCodigo || !selNombre || !selStock || !searchInput || !searchResults || !formConteo) return;

    productoId.value = String(id);
    selCodigo.textContent = String(codigo);
    selNombre.textContent = String(nombre);
    selStock.textContent = String(stock);

    searchInput.classList.add('is-hidden');
    searchResults.classList.remove('show');
    formConteo.classList.remove('is-hidden');

    if (cantidad) cantidad.focus();
  };

  window.cancelarSeleccion = function () {
    const searchInput = $('buscarProducto');
    const searchResults = $('searchResults');
    const formConteo = $('formConteo');

    if (!searchInput || !formConteo) return;

    formConteo.classList.add('is-hidden');
    searchInput.classList.remove('is-hidden');
    searchInput.value = '';

    if (searchResults) {
      searchResults.classList.remove('show');
      searchResults.innerHTML = '';
    }

    searchInput.focus();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBusqueda);
  } else {
    initBusqueda();
  }
})();

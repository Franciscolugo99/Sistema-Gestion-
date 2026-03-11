// public/assets/js/movimientos.js
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('mov-filters') || document.getElementById('movFilters');
  if (!form) return;

  const STORAGE_KEY = 'kiosco-mov-filtros-v4';
  const params = new URLSearchParams(window.location.search);

  const selProducto = form.querySelector('[name="producto_id"]');
  const selTipo = form.querySelector('[name="tipo"]');
  const inputSearch = form.querySelector('[name="q"]');
  const inputDesde = form.querySelector('[name="desde"]');
  const inputHasta = form.querySelector('[name="hasta"]');
  const selPerPage = form.querySelector('[name="per_page"]');
  const clearBtn = document.getElementById('movClearBtn');
  const chips = document.querySelectorAll('.filters-quick .chip[data-range]');
  const clickableRows = document.querySelectorAll('.mov-row-link[data-href]');

  const formatLocalDate = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  };

  const guardar = () => {
    const data = {
      q: inputSearch?.value || '',
      producto_id: selProducto?.value || '',
      tipo: selTipo?.value || '',
      desde: inputDesde?.value || '',
      hasta: inputHasta?.value || '',
      per_page: selPerPage?.value || '50',
    };

    localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
  };

  const tieneFiltrosUrl = ['q', 'producto_id', 'tipo', 'desde', 'hasta', 'per_page'].some((key) => {
    const value = params.get(key);
    return value !== null && value !== '';
  });

  if (!tieneFiltrosUrl) {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (raw) {
        const data = JSON.parse(raw);
        if (inputSearch) inputSearch.value = data.q || '';
        if (selProducto) selProducto.value = data.producto_id || '';
        if (selTipo) selTipo.value = data.tipo || '';
        if (inputDesde) inputDesde.value = data.desde || '';
        if (inputHasta) inputHasta.value = data.hasta || '';
        if (selPerPage) selPerPage.value = data.per_page || '50';
      }
    } catch (error) {
      console.warn('No se pudieron cargar filtros de movimientos', error);
    }
  }

  const getRangeStart = (range, today) => {
    const fromDate = new Date(today);

    if (range === '7d') {
      fromDate.setDate(today.getDate() - 6);
    }
    if (range === '30d') {
      fromDate.setDate(today.getDate() - 29);
    }
    if (range === 'month') {
      fromDate.setDate(1);
    }

    return fromDate;
  };

  const setActiveChip = () => {
    if (!inputDesde || !inputHasta) return;

    const today = new Date();
    const todayStr = formatLocalDate(today);

    chips.forEach((chip) => {
      const from = formatLocalDate(getRangeStart(chip.dataset.range || 'today', today));
      const isActive = inputDesde.value === from && inputHasta.value === todayStr;
      chip.classList.toggle('is-active', isActive);
      chip.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
  };

  const shouldIgnoreRowClick = (target) => {
    return Boolean(target.closest('a, button, input, select, textarea, label'));
  };

  clickableRows.forEach((row) => {
    row.addEventListener('click', (event) => {
      if (shouldIgnoreRowClick(event.target)) return;
      const href = row.dataset.href;
      if (href) window.location.href = href;
    });

    row.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      event.preventDefault();
      const href = row.dataset.href;
      if (href) window.location.href = href;
    });
  });

  [inputSearch, selProducto, selTipo, inputDesde, inputHasta, selPerPage].forEach((element) => {
    element?.addEventListener('change', () => {
      guardar();
      setActiveChip();
    });
  });

  inputSearch?.addEventListener('input', guardar);
  form.addEventListener('submit', guardar);

  clearBtn?.addEventListener('click', () => {
    localStorage.removeItem(STORAGE_KEY);
  });

  chips.forEach((chip) => {
    chip.addEventListener('click', () => {
      if (!inputDesde || !inputHasta) return;

      const today = new Date();
      const fromDate = getRangeStart(chip.dataset.range || 'today', today);

      inputDesde.value = formatLocalDate(fromDate);
      inputHasta.value = formatLocalDate(today);

      guardar();
      setActiveChip();
      form.requestSubmit ? form.requestSubmit() : form.submit();
    });
  });

  setActiveChip();
});
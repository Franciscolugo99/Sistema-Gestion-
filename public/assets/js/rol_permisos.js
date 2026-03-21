(() => {
    'use strict';

    const LEVELS = ['consulta', 'operativo', 'sensible', 'admin'];
    let activeLevelFilter = 'all';

    const qs = (selector, root = document) => root.querySelector(selector);
    const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    const getSelectedSlugs = () => qsa('.permiso-check:checked').map((input) => input.dataset.slug || '');

    function saveCollapseState(category, collapsed) {
        const key = category.dataset.categoria;
        if (key) {
            localStorage.setItem(`flus_permisos_${key}`, collapsed ? '1' : '0');
        }
    }

    function loadCollapseState(category) {
        const key = category.dataset.categoria;
        return key ? localStorage.getItem(`flus_permisos_${key}`) === '1' : false;
    }

    function updateItemState(checkbox) {
        checkbox.closest('.permiso-item')?.classList.toggle('is-active', checkbox.checked);
    }

    function matchesFilters(item, term) {
        const text = (item.dataset.permiso || '').toLowerCase();
        const level = item.dataset.level || '';
        return (term === '' || text.includes(term)) && (activeLevelFilter === 'all' || level === activeLevelFilter);
    }

    function applyFilters() {
        const term = (qs('#permisosSearch')?.value || '').toLowerCase().trim();

        qsa('.permisos-category').forEach((category) => {
            let visibleCount = 0;

            qsa('.permiso-item', category).forEach((item) => {
                const visible = matchesFilters(item, term);
                item.classList.toggle('is-hidden', !visible);
                if (visible) visibleCount++;
            });

            category.style.display = visibleCount > 0 ? '' : 'none';

            if (term !== '' || activeLevelFilter !== 'all') {
                category.classList.remove('is-collapsed');
            } else if (loadCollapseState(category)) {
                category.classList.add('is-collapsed');
            }
        });
    }

    function renderPreview() {
        const container = qs('#accessPreview');
        const rulesNode = qs('#rolePermissionPreviewRules');
        if (!container || !rulesNode) return;

        let rules = [];
        try {
            rules = JSON.parse(rulesNode.textContent || '[]');
        } catch (_error) {
            rules = [];
        }

        const selected = new Set(getSelectedSlugs());
        const visible = rules.filter((rule) => Array.isArray(rule.any) && rule.any.some((slug) => selected.has(slug)));

        if (visible.length === 0) {
            container.innerHTML = '<span class="preview-empty">Este rol no habilita ningun modulo visible todavia.</span>';
            return;
        }

        container.innerHTML = visible
            .map((rule) => `<span class="preview-chip preview-chip--${rule.tone || 'slate'}">${rule.label || ''}</span>`)
            .join('');
    }

    function renderLiveNotes() {
        const notesEl = qs('#livePermissionNotes');
        if (!notesEl) return;

        const selected = new Set(getSelectedSlugs());
        const notes = [];

        if (selected.has('abrir_caja') && !selected.has('realizar_ventas')) {
            notes.push('Este rol puede abrir caja, pero no vender. Eso sirve para separar apertura de operacion comercial.');
        }
        if (selected.has('cerrar_caja') && !selected.has('realizar_ventas')) {
            notes.push('cerrar_caja esta marcado, pero el usuario no podria usar Caja si no tiene realizar_ventas.');
        }

        if (notes.length === 0) {
            notesEl.innerHTML = '';
            notesEl.classList.remove('is-visible');
            return;
        }

        notesEl.innerHTML = notes.map((note) => `<div class="live-note">${note}</div>`).join('');
        notesEl.classList.add('is-visible');
    }

    function updateCounts() {
        qsa('.permisos-category').forEach((category) => {
            const counter = qs('.count-selected', category);
            if (counter) {
                counter.textContent = String(qsa('.permiso-check:checked', category).length);
            }
        });

        const total = qsa('.permiso-check').length;
        const checked = qsa('.permiso-check:checked').length;
        const percentage = total > 0 ? Math.round((checked / total) * 100) : 0;

        if (qs('#totalSelected')) qs('#totalSelected').textContent = String(checked);
        if (qs('#footerSelectedLabel')) qs('#footerSelectedLabel').textContent = String(checked);
        if (qs('#porcentajeText')) qs('#porcentajeText').textContent = `${percentage}% del total`;

        LEVELS.forEach((level) => {
            const pill = qs(`[data-level-count="${level}"] strong`);
            if (pill) {
                pill.textContent = String(qsa(`.permiso-check:checked[data-level="${level}"]`).length);
            }
        });

        renderPreview();
        renderLiveNotes();
    }

    function getFormState() {
        return qsa('.permiso-check').map((input) => `${input.value}:${input.checked ? '1' : '0'}`).join('|');
    }

    window.selectAll = function selectAll() {
        qsa('.permiso-item:not(.is-hidden) .permiso-check:not(:checked)').forEach((checkbox) => {
            checkbox.checked = true;
            updateItemState(checkbox);
        });
        updateCounts();
    };

    window.deselectAll = function deselectAll() {
        qsa('.permiso-item:not(.is-hidden) .permiso-check:checked').forEach((checkbox) => {
            checkbox.checked = false;
            updateItemState(checkbox);
        });
        updateCounts();
    };

    window.expandAll = function expandAll() {
        qsa('.permisos-category').forEach((category) => {
            category.classList.remove('is-collapsed');
            saveCollapseState(category, false);
        });
    };

    window.collapseAll = function collapseAll() {
        qsa('.permisos-category').forEach((category) => {
            category.classList.add('is-collapsed');
            saveCollapseState(category, true);
        });
    };

    window.toggleCategory = function toggleCategory(header) {
        const category = header.closest('.permisos-category');
        if (!category) return;
        category.classList.toggle('is-collapsed');
        saveCollapseState(category, category.classList.contains('is-collapsed'));
    };

    window.filterLevel = function filterLevel(level) {
        activeLevelFilter = level;

        qsa('.toolbar-right .btn-sm').forEach((button) => {
            const text = button.textContent.trim();
            const matches =
                (level === 'all' && text === 'Todos') ||
                (level === 'operativo' && text === 'Operativos') ||
                (level === 'consulta' && text === 'Consulta') ||
                (level === 'sensible' && text === 'Sensibles') ||
                (level === 'admin' && text === 'Admin');
            button.classList.toggle('is-active', matches);
        });

        applyFilters();
    };

    document.addEventListener('DOMContentLoaded', () => {
        qsa('.permisos-category').forEach((category) => {
            category.classList.add('is-collapsed');
            saveCollapseState(category, true);
        });

        const originalState = getFormState();
        let hasChanges = false;

        qsa('.permiso-check').forEach((checkbox) => {
            checkbox.addEventListener('change', () => {
                updateItemState(checkbox);
                hasChanges = getFormState() !== originalState;
                updateCounts();
            });
        });

        qs('#permisosSearch')?.addEventListener('input', applyFilters);

        window.addEventListener('beforeunload', (event) => {
            if (!hasChanges) return;
            event.preventDefault();
            event.returnValue = 'Hay cambios sin guardar.';
        });

        qs('#permisosForm')?.addEventListener('submit', (event) => {
            const total = qsa('.permiso-check').length;
            const checked = qsa('.permiso-check:checked').length;

            if (checked === 0 && !window.confirm('Este rol quedara sin permisos. Queres guardar igual?')) {
                event.preventDefault();
                return;
            }

            if (checked === total && !window.confirm('Este rol quedara con acceso total. Queres guardar igual?')) {
                event.preventDefault();
                return;
            }

            hasChanges = false;
        });

        document.addEventListener('keydown', (event) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
                event.preventDefault();
                qs('#permisosForm')?.requestSubmit();
            }
            if (event.key === '/' && !(event.ctrlKey || event.metaKey)) {
                event.preventDefault();
                qs('#permisosSearch')?.focus();
            }
        });

        filterLevel('all');
        updateCounts();
    });
})();

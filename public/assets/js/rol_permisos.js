/**
 * FLUS - Rol Permisos v3.0
 * JavaScript para gestión de permisos de rol
 */

(function() {
    'use strict';

    // =========================================================================
    // GLOBAL FUNCTIONS
    // =========================================================================

    window.selectAll = function() {
        const items = document.querySelectorAll('.permiso-item:not(.is-hidden) .permiso-check:not(:checked)');
        items.forEach(checkbox => {
            checkbox.checked = true;
            updateItemState(checkbox);
        });
        updateCounts();
    };

    window.deselectAll = function() {
        const items = document.querySelectorAll('.permiso-item:not(.is-hidden) .permiso-check:checked');
        items.forEach(checkbox => {
            checkbox.checked = false;
            updateItemState(checkbox);
        });
        updateCounts();
    };

    window.expandAll = function() {
        const categories = document.querySelectorAll('.permisos-category');
        categories.forEach(cat => {
            cat.classList.remove('is-collapsed');
            saveCollapseState(cat, false);
        });
    };

    window.collapseAll = function() {
        const categories = document.querySelectorAll('.permisos-category');
        categories.forEach(cat => {
            cat.classList.add('is-collapsed');
            saveCollapseState(cat, true);
        });
    };

    window.toggleCategory = function(header) {
        const category = header.closest('.permisos-category');
        if (!category) return;
        
        category.classList.toggle('is-collapsed');
        saveCollapseState(category, category.classList.contains('is-collapsed'));
    };

    function saveCollapseState(category, collapsed) {
        const id = category.dataset.categoria;
        if (id) {
            localStorage.setItem(`flus_permisos_${id}`, collapsed ? '1' : '0');
        }
    }

    function loadCollapseState(category) {
        const id = category.dataset.categoria;
        if (id) {
            return localStorage.getItem(`flus_permisos_${id}`) === '1';
        }
        return false;
    }

    // =========================================================================
    // UPDATE FUNCTIONS
    // =========================================================================

    function updateItemState(checkbox) {
        const item = checkbox.closest('.permiso-item');
        if (item) {
            item.classList.toggle('is-active', checkbox.checked);
        }
    }

    window.updateCounts = function() {
        // Update category counts
        const categories = document.querySelectorAll('.permisos-category');
        categories.forEach(cat => {
            const total = cat.querySelectorAll('.permiso-check').length;
            const checked = cat.querySelectorAll('.permiso-check:checked').length;
            const countEl = cat.querySelector('.count-selected');
            if (countEl) {
                countEl.textContent = checked;
            }
        });

        // Update total count
        const totalChecked = document.querySelectorAll('.permiso-check:checked').length;
        const totalAll = document.querySelectorAll('.permiso-check').length;
        
        const totalEl = document.getElementById('totalSelected');
        if (totalEl) {
            totalEl.textContent = totalChecked;
        }

        const porcentajeEl = document.getElementById('porcentajeText');
        if (porcentajeEl && totalAll > 0) {
            const porcentaje = Math.round((totalChecked / totalAll) * 100);
            porcentajeEl.textContent = `${porcentaje}% del total`;
        }
    };

    // =========================================================================
    // SEARCH FUNCTIONALITY
    // =========================================================================

    function initSearch() {
        const searchInput = document.getElementById('permisosSearch');
        if (!searchInput) return;

        searchInput.addEventListener('input', function() {
            const term = this.value.toLowerCase().trim();
            const categories = document.querySelectorAll('.permisos-category');

            categories.forEach(cat => {
                const items = cat.querySelectorAll('.permiso-item');
                let visibleCount = 0;

                items.forEach(item => {
                    const text = item.dataset.permiso || '';
                    if (term === '' || text.includes(term)) {
                        item.classList.remove('is-hidden');
                        visibleCount++;
                    } else {
                        item.classList.add('is-hidden');
                    }
                });

                // Show/hide category based on visible items
                if (term !== '') {
                    if (visibleCount > 0) {
                        cat.classList.remove('is-collapsed');
                        cat.style.display = '';
                    } else {
                        cat.style.display = 'none';
                    }
                } else {
                    cat.style.display = '';
                    // Restore collapse state
                    if (loadCollapseState(cat)) {
                        cat.classList.add('is-collapsed');
                    }
                }
            });
        });
    }

    // =========================================================================
    // FORM PROTECTION
    // =========================================================================

    function initFormProtection() {
        const form = document.getElementById('permisosForm');
        if (!form) return;

        let originalState = getFormState();
        let hasChanges = false;

        form.addEventListener('change', function() {
            const currentState = getFormState();
            hasChanges = originalState !== currentState;
        });

        window.addEventListener('beforeunload', function(e) {
            if (hasChanges) {
                e.preventDefault();
                e.returnValue = '¿Salir sin guardar? Los cambios se perderán.';
                return e.returnValue;
            }
        });

        form.addEventListener('submit', function(e) {
            hasChanges = false;
            
            const totalChecked = document.querySelectorAll('.permiso-check:checked').length;
            const totalAll = document.querySelectorAll('.permiso-check').length;
            
            if (totalChecked === 0) {
                Notif.confirmar(
                  '⛔ Quitar todos los permisos',
                  '<p>Los usuarios con este rol <strong>no tendrán acceso a ninguna función</strong>.</p>',
                  { icon: 'warning', confirmText: '✅ Sí, quitar todos', cancelText: '❌ Cancelar' }
                ).then(ok => { if (ok) checkAll(false); });
            } else if (totalChecked === totalAll) {
                Notif.confirmar(
                  '✅ Asignar todos los permisos',
                  '<p>Esto dará <strong>acceso completo al sistema</strong> a los usuarios con este rol.</p>',
                  { icon: 'warning', confirmText: '✅ Sí, asignar todos', cancelText: '❌ Cancelar' }
                ).then(ok => { if (ok) checkAll(true); });
                e.preventDefault(); return false;
            }
        });
    }

    function getFormState() {
        const checkboxes = Array.from(document.querySelectorAll('.permiso-check'));
        return checkboxes.map(cb => cb.checked ? cb.value : '').join(',');
    }

    // =========================================================================
    // KEYBOARD SHORTCUTS
    // =========================================================================

    function initKeyboardShortcuts() {
        document.addEventListener('keydown', function(e) {
            // Don't trigger if typing in search
            if (e.target.id === 'permisosSearch') {
                if (e.key === 'Escape') {
                    e.target.value = '';
                    e.target.dispatchEvent(new Event('input'));
                }
                return;
            }

            // Ctrl/Cmd + S to save
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                const form = document.getElementById('permisosForm');
                if (form) form.submit();
            }

            // Ctrl/Cmd + A to select all
            if ((e.ctrlKey || e.metaKey) && e.key === 'a') {
                e.preventDefault();
                selectAll();
            }

            // Ctrl/Cmd + E to expand all
            if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
                e.preventDefault();
                expandAll();
            }

            // Ctrl/Cmd + Q to collapse all
            if ((e.ctrlKey || e.metaKey) && e.key === 'q') {
                e.preventDefault();
                collapseAll();
            }

            // / to focus search
            if (e.key === '/' && !e.ctrlKey && !e.metaKey) {
                e.preventDefault();
                document.getElementById('permisosSearch')?.focus();
            }
        });
    }

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    document.addEventListener('DOMContentLoaded', function() {
        // Restore collapse states
        const categories = document.querySelectorAll('.permisos-category');
        categories.forEach(cat => {
            if (loadCollapseState(cat)) {
                cat.classList.add('is-collapsed');
            }
        });

        // Initialize checkbox change handlers
        const checkboxes = document.querySelectorAll('.permiso-check');
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateItemState(this);
                updateCounts();
            });
        });

        // Initialize counts
        updateCounts();

        // Initialize search
        initSearch();

        // Initialize form protection
        initFormProtection();

        // Initialize keyboard shortcuts
        initKeyboardShortcuts();

        // Auto-dismiss alerts
        const alerts = document.querySelectorAll('.alert-success, .alert-error');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.animation = 'fadeOut 0.3s ease forwards';
                setTimeout(() => alert.remove(), 300);
            }, 5000);
        });

        // Add animation for checkbox toggle
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const item = this.closest('.permiso-item');
                if (item) {
                    item.style.animation = 'pulse 0.2s ease';
                    setTimeout(() => {
                        item.style.animation = '';
                    }, 200);
                }
            });
        });
    });

    // Additional styles
    const style = document.createElement('style');
    style.textContent = `
        @keyframes fadeOut {
            to {
                opacity: 0;
                transform: translateY(-8px);
            }
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.02); }
        }
        
        .permisos-category {
            transition: opacity 0.2s;
        }
        
        .permiso-item {
            transition: all 0.15s ease, transform 0.2s ease;
        }
        
        /* Focus visible for accessibility */
        .permiso-check:focus-visible ~ .permiso-indicator {
            outline: 2px solid var(--primary-color);
            outline-offset: 2px;
        }
    `;
    document.head.appendChild(style);

})();

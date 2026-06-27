/**
 * FLUS - Roles v3.1
 * JavaScript para gestión de roles
 */

(function() {
    'use strict';

    // Estado del formulario
    var slugDirty = false;
    var isEditingCritical = false;
    var roleToDelete = null;
    var deleteModalTrigger = null;

    function getCsrf() {
        if (typeof window.getCsrfToken === 'function') {
            return window.getCsrfToken() || '';
        }
        var csrfEl = document.querySelector('input[name="csrf_token"]');
        return csrfEl ? (csrfEl.value || '') : '';
    }

    function notify(type, message) {
        if (!window.Notif) {
            return;
        }
        if ((type === 'success' || type === 'ok') && typeof window.Notif.exito === 'function') {
            window.Notif.exito(message);
            return;
        }
        if ((type === 'warning' || type === 'warn') && typeof window.Notif.advertencia === 'function') {
            window.Notif.advertencia(message);
            return;
        }
        if (typeof window.Notif.error === 'function') {
            window.Notif.error(message);
        }
    }

    function setCriticalRoleAlertVisible(visible) {
        var alert = document.getElementById('criticalRoleAlert');
        if (alert) {
            alert.classList.toggle('is-hidden', !visible);
        }
    }

    function initRoleProgress() {
        document.querySelectorAll('[data-role-progress]').forEach(function(progress) {
            var percentage = Math.max(0, Math.min(100, parseInt(progress.dataset.roleProgress || '0', 10) || 0));
            progress.style.width = percentage + '%';
        });
    }

    function bindStaticControls() {
        document.querySelectorAll('[data-role-drawer-open]').forEach(function(button) {
            button.addEventListener('click', window.openNewRoleDrawer);
        });

        document.querySelectorAll('[data-role-drawer-close]').forEach(function(button) {
            button.addEventListener('click', window.closeRoleDrawer);
        });

        document.querySelectorAll('[data-role-delete-close]').forEach(function(button) {
            button.addEventListener('click', window.closeDeleteModal);
        });

        var search = document.querySelector('[data-role-search]');
        if (search) {
            search.addEventListener('input', function() {
                window.filterRoles(search.value);
            });
        }

        var roleNameInput = document.querySelector('[data-role-name-input]');
        if (roleNameInput) {
            roleNameInput.addEventListener('input', function() {
                window.handleNameInput(roleNameInput.value);
            });
        }

        var roleSlugInput = document.querySelector('[data-role-slug-input]');
        if (roleSlugInput) {
            roleSlugInput.addEventListener('input', window.markSlugDirty);
        }
    }

    // =========================================================================
    // DRAWER FUNCTIONS
    // =========================================================================

    window.openNewRoleDrawer = function() {
        document.getElementById('drawerTitle').textContent = 'Nuevo Rol';
        document.getElementById('roleId').value = '';
        document.getElementById('isCritical').value = '0';
        document.getElementById('roleName').value = '';
        document.getElementById('roleSlug').value = '';
        document.getElementById('roleSlug').readOnly = false;
        setCriticalRoleAlertVisible(false);
        document.getElementById('slugHelp').textContent = 'Identificador único (sin espacios ni acentos)';
        slugDirty = false;
        isEditingCritical = false;
        
        document.getElementById('saveRoleBtn').innerHTML = '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Crear rol';
        openDrawer();
    };

    function openEditRoleDrawer(id, nombre, slug, isCritical) {
        document.getElementById('drawerTitle').textContent = 'Editar Rol';
        document.getElementById('roleId').value = id;
        document.getElementById('isCritical').value = isCritical ? '1' : '0';
        document.getElementById('roleName').value = nombre;
        document.getElementById('roleSlug').value = slug;
        slugDirty = true;
        isEditingCritical = isCritical;
        
        if (isCritical) {
            document.getElementById('roleSlug').readOnly = true;
            setCriticalRoleAlertVisible(true);
            document.getElementById('slugHelp').textContent = 'No modificable en roles del sistema';
        } else {
            document.getElementById('roleSlug').readOnly = false;
            setCriticalRoleAlertVisible(false);
            document.getElementById('slugHelp').textContent = 'Identificador único (sin espacios ni acentos)';
        }
        
        document.getElementById('saveRoleBtn').innerHTML = '<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Guardar cambios';
        openDrawer();
    }

    function openDrawer() {
        document.getElementById('roleDrawer').classList.add('is-open');
        document.getElementById('roleDrawerOverlay').classList.add('is-open');
        document.body.classList.add('no-scroll');
        
        setTimeout(function() {
            document.getElementById('roleName').focus();
        }, 200);
    }

    window.closeRoleDrawer = function() {
        document.getElementById('roleDrawer').classList.remove('is-open');
        document.getElementById('roleDrawerOverlay').classList.remove('is-open');
        document.body.classList.remove('no-scroll');
    };

    // =========================================================================
    // SLUG GENERATION
    // =========================================================================

    window.handleNameInput = function(value) {
        if (!slugDirty && !isEditingCritical) {
            generateSlug(value);
        }
    };

    window.markSlugDirty = function() {
        slugDirty = true;
    };

    function generateSlug(value) {
        var slug = value
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9\s_]/g, '')
            .replace(/\s+/g, '_')
            .replace(/_+/g, '_')
            .replace(/^_|_$/g, '');

        document.getElementById('roleSlug').value = slug;
    }

    // =========================================================================
    // FILTER ROLES
    // =========================================================================

    window.filterRoles = function(searchTerm) {
        var term = searchTerm.toLowerCase().trim();
        var cards = document.querySelectorAll('.role-card');
        var visibleCount = 0;

        cards.forEach(function(card) {
            var name = (card.dataset.roleName || '').toLowerCase();
            var slug = (card.dataset.roleSlug || '').toLowerCase();
            
            if (name.indexOf(term) !== -1 || slug.indexOf(term) !== -1) {
                visibleCount++;
                card.classList.remove('is-hidden');
            } else {
                card.classList.add('is-hidden');
            }
        });

        var countEl = document.getElementById('rolesVisibleCount');
        if (countEl) countEl.textContent = visibleCount;
    };

    // =========================================================================
    // DELETE MODAL
    // =========================================================================

    function confirmDelete(roleId, roleName, userCount, trigger) {
        roleToDelete = roleId;
        deleteModalTrigger = trigger || document.activeElement;
        
        var modal = document.getElementById('deleteModal');
        var message = document.getElementById('deleteModalMessage');
        var warning = document.getElementById('deleteModalWarning');
        
        message.textContent = '¿Estás seguro de eliminar el rol "' + roleName + '"?';
        
        if (userCount > 0) {
            warning.textContent = 'Advertencia: Este rol tiene ' + userCount + ' usuario(s) asignado(s). Deberás reasignarlos antes de eliminar.';
            warning.style.display = 'block';
        } else {
            warning.textContent = 'Esta acción no se puede deshacer.';
            warning.style.display = 'block';
        }

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('no-scroll');
        modal.querySelector('.modal-footer .btn-ghost')?.focus();
    }

    window.closeDeleteModal = function() {
        var modal = document.getElementById('deleteModal');
        if (!modal || !modal.classList.contains('is-open')) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('no-scroll');
        roleToDelete = null;
        if (deleteModalTrigger && document.contains(deleteModalTrigger)) {
            deleteModalTrigger.focus();
        }
        deleteModalTrigger = null;
    };

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    document.addEventListener('DOMContentLoaded', function() {
        initRoleProgress();
        bindStaticControls();
        
        // Event delegation para botones de editar y eliminar
        document.addEventListener('click', function(e) {
            var editBtn = e.target.closest('.btn-edit-role');
            if (editBtn) {
                var card = editBtn.closest('.role-card');
                if (card) {
                    var id = parseInt(card.dataset.roleId, 10);
                    var nombre = card.dataset.roleName || '';
                    var slug = card.dataset.roleSlug || '';
                    var isCritical = card.dataset.roleCritical === '1';
                    openEditRoleDrawer(id, nombre, slug, isCritical);
                }
                return;
            }
            
            var deleteBtn = e.target.closest('.btn-delete-role');
            if (deleteBtn) {
                var card = deleteBtn.closest('.role-card');
                if (card) {
                    var id = parseInt(card.dataset.roleId, 10);
                    var nombre = card.dataset.roleName || '';
                    var users = parseInt(card.dataset.roleUsers, 10) || 0;
                    confirmDelete(id, nombre, users, deleteBtn);
                }
                return;
            }
        });
        
        // Confirm delete button
        var confirmBtn = document.getElementById('confirmDeleteBtn');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', async function() {
                if (!roleToDelete) return;

                confirmBtn.disabled = true;

                try {
                    var resp = await fetch('api/rol_eliminar.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            role_id: roleToDelete,
                            csrf_token: getCsrf()
                        }),
                        credentials: 'same-origin'
                    });

                    var data = null;
                    try {
                        data = await resp.json();
                    } catch (_) {
                        data = null;
                    }

                    if (!resp.ok || !(data && (data.ok || data.success))) {
                        throw new Error((data && (data.message || data.error)) || ('HTTP ' + resp.status));
                    }

                    closeDeleteModal();
                    notify('success', data.message || 'Rol eliminado correctamente');

                    window.setTimeout(function() {
                        window.location.reload();
                    }, 350);
                } catch (err) {
                    notify('error', (err && err.message) ? err.message : 'No se pudo eliminar el rol');
                } finally {
                    confirmBtn.disabled = false;
                }
            });
        }

        // Close drawer on escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeRoleDrawer();
                closeDeleteModal();
            }
        });

        // Close drawer when clicking overlay
        var overlay = document.getElementById('roleDrawerOverlay');
        if (overlay) {
            overlay.addEventListener('click', function() {
                closeRoleDrawer();
            });
        }

        // Form submission validation
        var roleForm = document.getElementById('roleForm');
        if (roleForm) {
            roleForm.addEventListener('submit', function(e) {
                var name = document.getElementById('roleName').value.trim();
                var slug = document.getElementById('roleSlug').value.trim();
                
                if (!name || !slug) {
                    e.preventDefault();
                    Notif.advertencia('Por favor completá todos los campos requeridos.');
                    return false;
                }

                if (!/^[a-z0-9_]+$/.test(slug)) {
                    e.preventDefault();
                    Notif.error('El slug solo puede contener letras minúsculas, números y guiones bajos.');
                    return false;
                }
            });
        }

        // Auto-dismiss alerts after 5 seconds
        var alerts = document.querySelectorAll('.alert-success, .alert-error');
        alerts.forEach(function(alert) {
            setTimeout(function() {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(function() { 
                    if (alert.parentNode) alert.parentNode.removeChild(alert); 
                }, 300);
            }, 5000);
        });
    });

})();

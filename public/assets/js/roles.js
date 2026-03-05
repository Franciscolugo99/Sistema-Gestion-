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
        document.getElementById('criticalRoleAlert').style.display = 'none';
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
            document.getElementById('criticalRoleAlert').style.display = 'flex';
            document.getElementById('slugHelp').textContent = 'No modificable en roles del sistema';
        } else {
            document.getElementById('roleSlug').readOnly = false;
            document.getElementById('criticalRoleAlert').style.display = 'none';
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
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        var countEl = document.getElementById('rolesVisibleCount');
        if (countEl) countEl.textContent = visibleCount;
    };

    // =========================================================================
    // DELETE MODAL
    // =========================================================================

    function confirmDelete(roleId, roleName, userCount) {
        roleToDelete = roleId;
        
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
        document.body.classList.add('no-scroll');
    }

    window.closeDeleteModal = function() {
        var modal = document.getElementById('deleteModal');
        modal.classList.remove('is-open');
        document.body.classList.remove('no-scroll');
        roleToDelete = null;
    };

    // =========================================================================
    // INITIALIZATION
    // =========================================================================

    document.addEventListener('DOMContentLoaded', function() {
        
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
                    confirmDelete(id, nombre, users);
                }
                return;
            }
        });
        
        // Confirm delete button
        var confirmBtn = document.getElementById('confirmDeleteBtn');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function() {
                if (roleToDelete) {
                    var form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'api/rol_eliminar.php';
                    
                    var csrfInput = document.createElement('input');
                    csrfInput.type = 'hidden';
                    csrfInput.name = 'csrf_token';
                    var csrfEl = document.querySelector('input[name="csrf_token"]');
                    csrfInput.value = csrfEl ? csrfEl.value : '';
                    
                    var idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'role_id';
                    idInput.value = roleToDelete;
                    
                    form.appendChild(csrfInput);
                    form.appendChild(idInput);
                    document.body.appendChild(form);
                    form.submit();
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

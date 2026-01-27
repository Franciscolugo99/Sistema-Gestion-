/* ============================================================================
   FLUS - PRODUCTOS.JS (V2 Refactorizado)
   - Objeto único ProductosManager
   - Actualización inline sin reload
   - Export CSV desde servidor
   - Loading states
   - Validación al submit
   - Confirmación de cambios sin guardar
============================================================================ */

const ProductosManager = {
    // Estado
    state: {
        formDirty: false,
        editFormDirty: false,
        currentEditId: null,
        pendingConfirmCallback: null,
        allDetailsExpanded: false,
        csrfToken: null,
        csrfLastFetch: 0,
        // Listado AJAX
        listController: null,
        listLoading: false,
        searchTimer: null,
        lastListSig: '',
    },

    // Configuración
    config: {
        CSRF_CACHE_MS: 30000,
        DEBOUNCE_SEARCH_MS: 500,
        DEBOUNCE_VALIDATE_MS: 400,
    },

    // ============================================
    // INICIALIZACIÓN
    // ============================================
    init() {
        if (!this.isProductosPage()) return;

        console.log('[ProductosManager] Inicializando...');

        this.bindFormToggle();
        this.bindMainForm();
        this.bindEditForm();
        this.bindFilters();
        this.bindActiveFilters();
        this.renderActiveFilters();
        this.bindTableSort();
        this.bindPagination();
        this.bindHistory();
        this.bindKeyboardShortcuts();
        this.bindFileInputs();
        this.bindPesables();
        this.bindAutocomplete();
        this.bindExportButton();
        this.bindRefreshButton();
        this.bindBeforeUnload();

        this.loadStats();
        this.refreshCsrf();

        console.log('[ProductosManager] ✓ Inicialización completa');
    },

    isProductosPage() {
        return document.querySelector('.page-wrap.productos-page') !== null;
    },

    // ============================================
    // CSRF MANAGEMENT
    // ============================================
    async refreshCsrf(force = false) {
        const now = Date.now();
        if (!force && this.state.csrfToken && (now - this.state.csrfLastFetch) < this.config.CSRF_CACHE_MS) {
            return this.state.csrfToken;
        }

        // Fallback: leer token existente del DOM (meta o input hidden)
        const readDomToken = () => {
            const inp = document.querySelector('input[name="csrf_token"]');
            if (inp && inp.value) return inp.value;
            const meta = document.querySelector('meta[name="csrf-token"]');
            const m = meta ? meta.getAttribute('content') : '';
            return m || null;
        };

        try {
            const res = await fetch('_csrf_token.php?_=' + now, {
                method: 'GET',
                cache: 'no-store',
                credentials: 'same-origin',
            });

            if (res.ok) {
                const data = await res.json();
                if (data?.csrf_token) {
                    this.state.csrfToken = data.csrf_token;
                    this.state.csrfLastFetch = now;

                    // Actualizar todos los formularios
                    document.querySelectorAll('input[name="csrf_token"]').forEach(inp => {
                        inp.value = data.csrf_token;
                    });

                    return data.csrf_token;
                }
            }
        } catch (e) {
            console.warn('[ProductosManager] Error refreshing CSRF:', e);
        }

        // Si falló el endpoint, no romper: usar el token ya renderizado
        const fallback = readDomToken();
        if (fallback) {
            this.state.csrfToken = fallback;
            this.state.csrfLastFetch = now;
            document.querySelectorAll('input[name="csrf_token"]').forEach(inp => {
                inp.value = fallback;
            });
            return fallback;
        }

        return null;
    },

    // ============================================
    // TOAST
    // ============================================
    showToast(message, type = 'success', duration = 3000) {
        const container = document.getElementById('toastContainer') || this.createToastContainer();
        
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        const icons = { success: '✓', error: '✕', warning: '⚠', info: 'ℹ' };
        toast.innerHTML = `
            <span class="toast-icon">${icons[type] || icons.info}</span>
            <span class="toast-message">${this.escapeHtml(message)}</span>
        `;
        
        container.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('show'));

        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, duration);
    },

    createToastContainer() {
        const container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container';
        document.body.appendChild(container);
        return container;
    },

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    },

    // ============================================
    // STATS (con KPIs clickeables)
    // ============================================
    async loadStats() {
        try {
            const res = await fetch('productos.php?stats=1', {
                cache: 'no-store',
                credentials: 'same-origin',
            });
            if (!res.ok) return;

            const data = await res.json();
            
            const setVal = (id, val) => {
                const el = document.getElementById(id);
                if (el) el.textContent = val ?? '—';
            };

            setVal('statTotal', data.total);
            setVal('statActivos', data.activos);
            setVal('statSinStock', data.sin_stock);
            setVal('statBajoStock', data.stock_bajo);

            // Hacer KPIs clickeables
            this.bindKpiClicks();
        } catch (e) {
            console.warn('[ProductosManager] Error loading stats:', e);
        }
    },

    bindKpiClicks() {
        const kpis = document.querySelectorAll('.stat-item');
        if (!kpis.length) return;

        const estadoSelect = document.getElementById('estadoSelect');
        const stockFilterInput = document.getElementById('stockFilterInput');
        const pageInput = document.getElementById('pageInput');

        kpis.forEach((kpi, index) => {
            if (kpi.dataset.bound) return;
            kpi.dataset.bound = '1';
            kpi.style.cursor = 'pointer';

            kpi.addEventListener('click', () => {
                if (index === 0) {
                    estadoSelect && (estadoSelect.value = '');
                    stockFilterInput && (stockFilterInput.value = '');
                } else if (index === 1) {
                    estadoSelect && (estadoSelect.value = 'activos');
                    stockFilterInput && (stockFilterInput.value = '');
                } else if (index === 2) {
                    estadoSelect && (estadoSelect.value = 'activos');
                    stockFilterInput && (stockFilterInput.value = 'sin');
                } else if (index === 3) {
                    estadoSelect && (estadoSelect.value = 'activos');
                    stockFilterInput && (stockFilterInput.value = 'bajo');
                }

                if (pageInput) pageInput.value = '1';
                this.renderActiveFilters();
                this.updateList({ history: 'push', force: true });
            });
        });
    },

    // ============================================
    // TOGGLE FORMULARIO PRINCIPAL (robusto)
    // - evita dobles listeners
    // - mantiene el texto del botón sincronizado aunque otro script toque el DOM
    // ============================================
    bindFormToggle() {
        const btn0 = document.getElementById('toggleFormBtn');
        const formBlock = document.getElementById('productFormBlock');

        if (!btn0 || !formBlock) {
            console.warn('[ProductosManager] No se encontró toggleFormBtn o productFormBlock');
            return;
        }

        // Si el JS se carga 2 veces, evitamos duplicar binds
        if (btn0.dataset.boundToggle === '1') {
            // igual sincronizamos el label por si cambió el estado
            this.syncToggleFormLabel();
            return;
        }

        // Aislamos el botón de listeners externos (core/theme) clonándolo
        const btn = btn0.cloneNode(true);
        btn0.parentNode.replaceChild(btn, btn0);

        const labelEl = btn.querySelector('.label') || btn;

        const setLabel = () => {
            const collapsed = formBlock.classList.contains('is-collapsed');
            if (labelEl) labelEl.textContent = collapsed ? 'Agregar producto' : 'Ocultar formulario';
            btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        };

        // Exponemos para poder re-sincronizar desde otros lugares si hace falta
        this._toggleForm_setLabel = setLabel;

        setLabel();

        const handler = (e) => {
            e.preventDefault();
            e.stopPropagation();
            if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();

            formBlock.classList.toggle('is-collapsed');
            setLabel();

            // Focus al abrir
            if (!formBlock.classList.contains('is-collapsed')) {
                setTimeout(() => {
                    const codigoInput = formBlock.querySelector('input[name="codigo"]');
                    if (codigoInput) codigoInput.focus();
                }, 80);
            }
        };

        // Capture para bloquear handlers en bubble (muy común en core)
        btn.addEventListener('click', handler, true);
        btn.dataset.boundToggle = '1';

        // Si cualquier cosa cambia la clase, mantenemos el label sincronizado
        const mo = new MutationObserver(() => setLabel());
        mo.observe(formBlock, { attributes: true, attributeFilter: ['class'] });

        console.log('[ProductosManager] ✓ Toggle form bindeado (robusto)');
    },

    syncToggleFormLabel() {
        const formBlock = document.getElementById('productFormBlock');
        const btn = document.getElementById('toggleFormBtn');
        if (!formBlock || !btn) return;

        const labelEl = btn.querySelector('.label') || btn;
        const collapsed = formBlock.classList.contains('is-collapsed');
        if (labelEl) labelEl.textContent = collapsed ? 'Agregar producto' : 'Ocultar formulario';
        btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    },

    // ============================================
    // FORMULARIO PRINCIPAL
    // ============================================
    bindMainForm() {
        const form = document.getElementById('mainProductForm');
        if (!form) return;

        // Track dirty state
        form.querySelectorAll('input, select, textarea').forEach(el => {
            el.addEventListener('input', () => { this.state.formDirty = true; });
            el.addEventListener('change', () => { this.state.formDirty = true; });
        });

        // Validación de código en tiempo real
        const codigoInput = form.querySelector('input[name="codigo"]');
        if (codigoInput) {
            let timer = null;
            codigoInput.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(() => this.validateCodigo(codigoInput, form), this.config.DEBOUNCE_VALIDATE_MS);
            });
        }

        // Submit con validación
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            // Validar código antes de enviar
            const codigo = form.querySelector('input[name="codigo"]')?.value.trim();
            if (!codigo) {
                this.showToast('El código es obligatorio', 'error');
                return;
            }

            // Verificar código duplicado sincrónicamente
            const isValid = await this.checkCodigoSync(codigo, form.querySelector('input[name="id"]')?.value);
            if (!isValid) {
                this.showToast('Ya existe un producto con ese código', 'error');
                return;
            }

            this.submitMainForm(form);
        });

        // Botón limpiar
        const btnClear = document.getElementById('btnClearForm');
        btnClear?.addEventListener('click', () => this.clearMainForm());
    },

    async validateCodigo(input, form) {
        const val = input.value.trim();
        const statusEl = input.parentElement?.querySelector('.field-status');

        if (!val) {
            input.classList.remove('valid', 'invalid');
            if (statusEl) statusEl.textContent = '';
            return;
        }

        const idInput = form?.querySelector('input[name="id"]');
        const id = idInput?.value || null;

        try {
            const params = new URLSearchParams({ checkCodigo: val });
            if (id) params.set('id', id);

            const res = await fetch(`productos.php?${params}`, {
                cache: 'no-store',
                credentials: 'same-origin',
            });
            const data = await res.json();

            if (data?.exists) {
                input.classList.remove('valid');
                input.classList.add('invalid');
                if (statusEl) statusEl.textContent = '⚠ Código en uso';
            } else {
                input.classList.remove('invalid');
                input.classList.add('valid');
                if (statusEl) statusEl.textContent = '✓';
            }
        } catch (e) {
            console.warn('[ProductosManager] Error validando código:', e);
        }
    },

    async checkCodigoSync(codigo, excludeId = null) {
        try {
            const params = new URLSearchParams({ checkCodigo: codigo });
            if (excludeId) params.set('id', excludeId);

            const res = await fetch(`productos.php?${params}`, { cache: 'no-store' });
            const data = await res.json();
            return !data?.exists;
        } catch {
            return true; // Asumir válido si falla
        }
    },

    async submitMainForm(form) {
        const btn = form.querySelector('button[type="submit"]');
        const btnText = btn?.querySelector('.btn-text');
        const btnSpinner = btn?.querySelector('.btn-spinner');

        if (btn) btn.disabled = true;
        if (btnText) btnText.style.display = 'none';
        if (btnSpinner) btnSpinner.style.display = 'inline-flex';

        try {
            const fd = new FormData(form);
            fd.append('ajax', '1');

            const res = await fetch('productos.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });

            if (!res.ok) throw new Error('HTTP_' + res.status);

            const data = await res.json();
            if (!data || data.success !== true) throw new Error(data?.error || 'SAVE_FAIL');

            this.showToast(data.message || 'Guardado', 'success');
            this.state.formDirty = false;

            this.clearMainForm();
            this.loadStats();

            if (data.action === 'created') {
                const pageInput = document.getElementById('pageInput');
                if (pageInput) pageInput.value = '1';
                await this.updateList({ history: false, force: true, highlightId: data.data?.id || null });
            } else {
                if (data.data) this.updateRowInTable(data.data);
            }

        } catch (err) {
            console.error('[ProductosManager] submitMainForm error:', err);
            this.showToast('Error al guardar', 'error');
        } finally {
            if (btn) btn.disabled = false;
            if (btnText) btnText.style.display = 'inline';
            if (btnSpinner) btnSpinner.style.display = 'none';
        }
    },

    clearMainForm() {
        const form = document.getElementById('mainProductForm');
        if (!form) return;

        form.reset();

        // Limpiar ID
        const idInput = form.querySelector('input[name="id"]');
        if (idInput) idInput.value = '';

        // Defaults
        const activo = form.querySelector('input[name="activo"]');
        if (activo) activo.checked = true;

        ['stock', 'stock_minimo'].forEach(name => {
            const el = form.querySelector(`[name="${name}"]`);
            if (el) el.value = '0';
        });

        const precio = form.querySelector('[name="precio"]');
        if (precio) precio.value = '0';

        // Pesable
        const pesable = form.querySelector('#esPesableMain');
        if (pesable) {
            pesable.checked = false;
            pesable.dispatchEvent(new Event('change', { bubbles: true }));
        }

        const unidadReal = form.querySelector('#unidad_venta_real_main');
        if (unidadReal) unidadReal.value = 'UNIDAD';

        // File
        const fileInput = form.querySelector('input[type="file"]');
        if (fileInput) fileInput.value = '';
        const fileName = document.getElementById('fileName');
        if (fileName) fileName.textContent = 'Ningún archivo seleccionado';

        // Limpiar validaciones
        form.querySelectorAll('.valid, .invalid').forEach(el => {
            el.classList.remove('valid', 'invalid');
        });
        form.querySelectorAll('.field-status').forEach(el => {
            el.textContent = '';
        });

        this.state.formDirty = false;

        // Actualizar botón
        const btn = document.getElementById('toggleFormBtn');
        const label = btn?.querySelector('.label');
        if (label) label.textContent = 'Agregar producto';

        // Actualizar botón submit
        const submitBtn = form.querySelector('#btnSubmitMain .btn-text');
        if (submitBtn) submitBtn.textContent = 'Guardar';
    },

    // ============================================
    // PANEL DE EDICIÓN
    // ============================================
    bindEditForm() {
        const form = document.getElementById('editForm');
        if (!form) return;

        // Track dirty
        form.querySelectorAll('input, select, textarea').forEach(el => {
            el.addEventListener('input', () => { this.state.editFormDirty = true; });
            el.addEventListener('change', () => { this.state.editFormDirty = true; });
        });

        // Validación código
        const codigoInput = form.querySelector('input[name="codigo"]');
        if (codigoInput) {
            let timer = null;
            codigoInput.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(() => this.validateCodigo(codigoInput, form), this.config.DEBOUNCE_VALIDATE_MS);
            });
        }

        // Submit
        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const codigo = form.querySelector('input[name="codigo"]')?.value.trim();
            const isValid = await this.checkCodigoSync(codigo, form.querySelector('input[name="id"]')?.value);
            if (!isValid) {
                this.showToast('Ya existe un producto con ese código', 'error');
                return;
            }

            this.submitEditForm(form);
        });

        // Click fuera cierra
        const overlay = document.getElementById('editOverlay');
        overlay?.addEventListener('click', (e) => {
            if (e.target === overlay) this.closeEdit();
        });
    },

    async openEdit(id) {
        const overlay = document.getElementById('editOverlay');
        const loading = document.getElementById('editLoading');
        const form = document.getElementById('editForm');

        if (!overlay || !form) return;

        this.state.currentEditId = id;
        this.state.editFormDirty = false;

        // Mostrar overlay con loading
        overlay.classList.add('open');
        if (loading) loading.style.display = 'flex';
        form.style.display = 'none';

        try {
            const res = await fetch(`productos.php?editar=${id}&ajax=1`, {
                cache: 'no-store',
                credentials: 'same-origin',
            });

            if (!res.ok) throw new Error('Producto no encontrado');

            const producto = await res.json();
            if (!producto?.id) throw new Error('Datos inválidos');

            this.populateEditForm(producto);

            if (loading) loading.style.display = 'none';
            form.style.display = 'block';

            // Focus primer campo
            setTimeout(() => {
                form.querySelector('input[name="codigo"]')?.focus();
            }, 100);

        } catch (err) {
            console.error('[ProductosManager] Error loading product:', err);
            this.showToast(err.message || 'Error al cargar producto', 'error');
            this.closeEdit();
        }
    },

    populateEditForm(producto) {
        const form = document.getElementById('editForm');
        if (!form) return;

        form.reset();

        const setVal = (name, val) => {
            const el = form.querySelector(`[name="${name}"]`);
            if (!el) return;
            if (el.type === 'checkbox') {
                el.checked = !!val && val !== '0';
            } else {
                el.value = val ?? '';
            }
        };

        setVal('id', producto.id);
        setVal('codigo', producto.codigo);
        setVal('nombre', producto.nombre);
        setVal('categoria', producto.categoria);
        setVal('marca', producto.marca);
        setVal('proveedor', producto.proveedor);
        setVal('proveedor_id', producto.proveedor_id);
        setVal('iva', producto.iva);
        setVal('precio', producto.precio);
        setVal('costo', producto.costo);
        setVal('stock', producto.stock);
        setVal('stock_minimo', producto.stock_minimo);
        setVal('activo', producto.activo);

        // Pesable
        const esPesable = parseInt(producto.es_pesable) === 1;
        const pesableChk = form.querySelector('#esPesableEdit');
        if (pesableChk) {
            pesableChk.checked = esPesable;
            pesableChk.dispatchEvent(new Event('change', { bubbles: true }));
        }

        const unidadHidden = form.querySelector('#unidad_venta_real_edit');
        if (unidadHidden) unidadHidden.value = producto.unidad_venta || 'UNIDAD';

        if (esPesable && producto.unidad_venta) {
            const radio = form.querySelector(`input[name="unidad_venta_visual_edit"][value="${producto.unidad_venta}"]`);
            if (radio) {
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        // Limpiar validaciones previas
        form.querySelectorAll('.valid, .invalid').forEach(el => {
            el.classList.remove('valid', 'invalid');
        });
    },

    async submitEditForm(form) {
        const btn = form.querySelector('#btnSubmitEdit');
        const btnText = btn?.querySelector('.btn-text');
        const btnLoading = btn?.querySelector('.btn-loading');

        if (btn) btn.disabled = true;
        if (btnText) btnText.style.display = 'none';
        if (btnLoading) btnLoading.style.display = 'inline';

        try {
            await this.refreshCsrf(true);

            const formData = new FormData(form);
            formData.append('ajax', '1');

            const res = await fetch('productos.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            });

            const data = await res.json().catch(() => null);

            if (!res.ok || !data?.success) {
                throw new Error(data?.message || 'Error al guardar');
            }

            this.showToast(data.message || 'Producto actualizado', 'success');
            this.state.editFormDirty = false;

            // Actualizar fila
            this.updateRowInTable(data.data);

            this.closeEdit();
            this.loadStats();

        } catch (err) {
            console.error('[ProductosManager] Edit submit error:', err);
            this.showToast(err.message || 'Error al guardar', 'error');
        } finally {
            if (btn) btn.disabled = false;
            if (btnText) btnText.style.display = 'inline';
            if (btnLoading) btnLoading.style.display = 'none';
        }
    },

    closeEdit() {
        if (this.state.editFormDirty) {
            if (!confirm('Hay cambios sin guardar. ¿Cerrar de todos modos?')) {
                return;
            }
        }

        const overlay = document.getElementById('editOverlay');
        overlay?.classList.remove('open');
        this.state.currentEditId = null;
        this.state.editFormDirty = false;
    },

    // ============================================
    // ACTUALIZACIÓN INLINE DE FILA
    // ============================================
    updateRowInTable(data) {
        const row = document.querySelector(`tr.producto-row[data-id="${data.id}"]`);
        if (!row) return;

        // Solo actualizar lo que viene en data (toggle solo manda id, activo, estado)
        if (data.codigo !== undefined) {
            row.dataset.codigo = data.codigo;
            const celdaCodigo = row.querySelector('td:nth-child(2) code');
            if (celdaCodigo) celdaCodigo.textContent = data.codigo;
        }

        if (data.nombre !== undefined) {
            row.dataset.nombre = data.nombre;
            const celdaNombre = row.querySelector('td.td-nombre strong');
            if (celdaNombre) celdaNombre.textContent = data.nombre;
        }

        if (data.precio !== undefined) {
            row.dataset.precio = data.precio;
            const celdaPrecio = row.querySelector('td:nth-child(4)');
            if (celdaPrecio) {
                const precioNum = parseFloat(data.precio);
                if (!isNaN(precioNum)) {
                    celdaPrecio.textContent = '$' + precioNum.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
            }
        }

        if (data.stock !== undefined) {
            row.dataset.stock = data.stock;
            const celdaStock = row.querySelector('td:nth-child(5)');
            if (celdaStock) celdaStock.textContent = data.stock;
        }

        if (data.activo !== undefined) {
            row.dataset.activo = data.activo;
        }

        // Actualizar estado
        if (data.estado !== undefined) {
            row.dataset.estado = data.estado;
            const celdaEstado = row.querySelector('td.td-estado');
            if (celdaEstado) {
                const tagMap = {
                    'ok': '<span class="tag tag-ok">OK</span>',
                    'bajo': '<span class="tag tag-bajo">Stock bajo</span>',
                    'sin': '<span class="tag tag-sin">Sin stock</span>',
                    'inactivo': '<span class="tag tag-inactivo">Inactivo</span>',
                };
                celdaEstado.innerHTML = tagMap[data.estado] || tagMap['ok'];
            }
        }

        // Actualizar botón toggle si viene info de activo
        if (data.activo !== undefined) {
            const btnToggle = row.querySelector('.btn-toggle');
            if (btnToggle) {
                const isActive = parseInt(data.activo) === 1;
                const action = isActive ? 'desactivar' : 'activar';
                btnToggle.textContent = isActive ? 'Desactivar' : 'Activar';
                btnToggle.onclick = () => this.confirmToggle(data.id, action);
            }
        }

        // Highlight
        row.classList.add('row-updated');
        setTimeout(() => row.classList.remove('row-updated'), 2500);
    },

    // ============================================
    // COPIAR PRODUCTO
    // ============================================
    async copyProduct(id) {
        try {
            const res = await fetch(`productos.php?editar=${id}&ajax=1`, {
                cache: 'no-store',
                credentials: 'same-origin',
            });

            const producto = await res.json();
            if (!producto?.id) {
                this.showToast('No se encontró el producto', 'error');
                return;
            }

            // Abrir formulario principal
            const formBlock = document.getElementById('productFormBlock');
            const toggleBtn = document.getElementById('toggleFormBtn');
            if (formBlock?.classList.contains('is-collapsed')) {
                toggleBtn?.click();
            }

            const form = document.getElementById('mainProductForm');
            if (!form) return;

            // Limpiar ID (es una copia, no edición)
            const idInput = form.querySelector('input[name="id"]');
            if (idInput) idInput.value = '';

            // Poblar campos
            const setVal = (name, val) => {
                const el = form.querySelector(`[name="${name}"]`);
                if (!el) return;
                el.value = val ?? '';
                el.dispatchEvent(new Event('input', { bubbles: true }));
            };

            setVal('nombre', producto.nombre);
            setVal('categoria', producto.categoria);
            setVal('marca', producto.marca);
            setVal('proveedor', producto.proveedor);
        setVal('proveedor_id', producto.proveedor_id);
            setVal('iva', producto.iva);
            setVal('precio', producto.precio);
            setVal('costo', producto.costo);
            setVal('stock_minimo', producto.stock_minimo);
            setVal('stock', '0'); // Stock en 0 para copia
            setVal('codigo', ''); // Código vacío para que ingrese uno nuevo

            // Activo
            const activo = form.querySelector('input[name="activo"]');
            if (activo) activo.checked = true;

            // Pesable
            const esPesable = parseInt(producto.es_pesable) === 1;
            const pesableChk = form.querySelector('#esPesableMain');
            if (pesableChk) {
                pesableChk.checked = esPesable;
                pesableChk.dispatchEvent(new Event('change', { bubbles: true }));
            }

            if (esPesable && producto.unidad_venta) {
                const unidadHidden = form.querySelector('#unidad_venta_real_main');
                if (unidadHidden) unidadHidden.value = producto.unidad_venta;

                const radio = form.querySelector(`input[name="unidad_venta_visual"][value="${producto.unidad_venta}"]`);
                if (radio) {
                    radio.checked = true;
                    radio.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }

            // Actualizar botón y label
            const label = toggleBtn?.querySelector('.label');
            if (label) label.textContent = 'Agregar producto (copia)';

            const submitText = form.querySelector('#btnSubmitMain .btn-text');
            if (submitText) submitText.textContent = 'Guardar';

            // Focus en código
            setTimeout(() => {
                form.querySelector('input[name="codigo"]')?.focus();
            }, 150);

            this.showToast('Producto copiado. Ingresá un nuevo código.', 'info');

        } catch (err) {
            console.error('[ProductosManager] Copy error:', err);
            this.showToast('Error al copiar producto', 'error');
        }
    },

    // ============================================
    // TOGGLE ACTIVAR/DESACTIVAR
    // ============================================
    confirmToggle(id, action) {
        const modal = document.getElementById('confirmModal');
        const title = document.getElementById('confirmTitle');
        const text = document.getElementById('confirmText');
        const acceptBtn = document.getElementById('confirmAccept');

        if (!modal) return;

        title.textContent = action === 'desactivar' ? 'Desactivar producto' : 'Activar producto';
        text.textContent = action === 'desactivar' 
            ? '¿Desactivar este producto? No aparecerá en Caja ni búsquedas.'
            : '¿Activar este producto?';

        this.state.pendingConfirmCallback = () => this.executeToggle(id, action);

        acceptBtn.onclick = () => {
            this.state.pendingConfirmCallback?.();
            this.closeConfirm();
        };

        modal.classList.add('open');
    },

    closeConfirm() {
        const modal = document.getElementById('confirmModal');
        modal?.classList.remove('open');
        this.state.pendingConfirmCallback = null;
    },

    async executeToggle(id, action) {
        try {
            await this.refreshCsrf(true);

            const formData = new FormData();
            formData.append('action', 'toggle');
            formData.append('id', id);
            formData.append('toggle_action', action === 'desactivar' ? 'deactivate' : 'activate');
            formData.append('csrf_token', this.state.csrfToken);

            const res = await fetch('productos.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            });

            const data = await res.json().catch(() => null);

            if (!res.ok || !data?.success) {
                throw new Error(data?.message || 'Error al cambiar estado');
            }

            this.showToast(data.message, 'success');
            this.updateRowInTable(data.data);
            this.loadStats();

        } catch (err) {
            console.error('[ProductosManager] Toggle error:', err);
            this.showToast(err.message || 'Error al cambiar estado', 'error');
        }
    },

    // ============================================
    // DETALLES EXPANDIBLES
    // ============================================
    toggleDetail(id) {
        const detailRow = document.getElementById(`detail-${id}`);
        if (!detailRow) return;

        detailRow.classList.toggle('open');

        const btn = document.querySelector(`tr[data-id="${id}"] .btn-expand`);
        if (btn) {
            btn.textContent = detailRow.classList.contains('open') ? '⊖' : '⊕';
        }
    },

    toggleAllDetails() {
        this.state.allDetailsExpanded = !this.state.allDetailsExpanded;

        document.querySelectorAll('.producto-detail-row').forEach(row => {
            if (this.state.allDetailsExpanded) {
                row.classList.add('open');
            } else {
                row.classList.remove('open');
            }
        });

        document.querySelectorAll('.producto-row .btn-expand').forEach(btn => {
            btn.textContent = this.state.allDetailsExpanded ? '⊖' : '⊕';
        });

        const expandAllBtn = document.querySelector('.btn-expand-all');
        if (expandAllBtn) {
            expandAllBtn.textContent = this.state.allDetailsExpanded ? '⊖' : '⊕';
        }
    },

    // ============================================
    // FILTROS Y BÚSQUEDA
    // ============================================
    bindFilters() {
        const form = document.getElementById('filtersForm');
        if (!form) return;

        const searchInput = document.getElementById('searchInput');
        const estadoSelect = document.getElementById('estadoSelect');
        const limitSelect  = document.getElementById('limitSelect');
        const pageInput    = document.getElementById('pageInput');
        const clearLink    = form.querySelector('a[href="productos.php"]');

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                const val = searchInput.value.trim();

                if (this.state.searchTimer) clearTimeout(this.state.searchTimer);
                this.state.searchTimer = setTimeout(() => {

                    if (pageInput) pageInput.value = '1';
                    this.renderActiveFilters();
                    this.updateList({ history: 'replace', force: true });
                }, this.config.DEBOUNCE_SEARCH_MS);
            });
        }

        estadoSelect?.addEventListener('change', () => {
            if (pageInput) pageInput.value = '1';
            // Si el usuario cambia a INACTIVOS, stock_filter deja de tener sentido => lo limpiamos
            const sf = document.getElementById('stockFilterInput');
            if (estadoSelect && estadoSelect.value === 'inactivos' && sf && sf.value) {
                sf.value = '';
                this.showToast('Se quitó el filtro de stock (solo aplica a activos)', 'info', 2200);
            }
            this.renderActiveFilters();
            this.updateList({ history: 'push', force: true });
        });

        limitSelect?.addEventListener('change', () => {
            if (pageInput) pageInput.value = '1';
            this.renderActiveFilters();
            this.updateList({ history: 'push', force: true });
        });

        form.addEventListener('submit', (e) => {
            e.preventDefault();
            if (pageInput) pageInput.value = '1';
            this.renderActiveFilters();
            this.updateList({ history: 'push', force: true });
        });

        clearLink?.addEventListener('click', (e) => {
            e.preventDefault();

            if (searchInput) searchInput.value = '';
            if (estadoSelect) estadoSelect.value = '';
            const sf = document.getElementById('stockFilterInput');
            if (sf) sf.value = '';
            if (pageInput) pageInput.value = '1';

            this.renderActiveFilters();
            this.updateList({ history: 'push', force: true });
        });
    },


    // ============================================
    // FILTROS ACTIVOS (chips con ×) - como Ventas
    // ============================================
    bindActiveFilters() {
        const container = document.getElementById('filtrosActivos');
        if (!container) return;

        if (container.dataset.bound === '1') return;
        container.dataset.bound = '1';

        container.addEventListener('click', (e) => {
            const btn = e.target.closest('button.filtro-remove');
            if (!btn) return;

            const key = btn.dataset.filter || '';
            if (!key) return;

            e.preventDefault();
            this.clearFilter(key);
            this.renderActiveFilters();
            this.updateList({ history: 'push', force: true });
        });
    },

    clearFilter(key) {
        const searchInput = document.getElementById('searchInput');
        const estadoSelect = document.getElementById('estadoSelect');
        const stockFilterInput = document.getElementById('stockFilterInput');
        const pageInput = document.getElementById('pageInput');

        const k = String(key || '').toLowerCase();

        if (k === 'q' || k === 'all') {
            if (searchInput) searchInput.value = '';
        }
        if (k === 'estado' || k === 'all') {
            if (estadoSelect) estadoSelect.value = '';
        }
        if (k === 'stock_filter' || k === 'stockfilter' || k === 'all') {
            if (stockFilterInput) stockFilterInput.value = '';
        }

        if (pageInput) pageInput.value = '1';
    },

    renderActiveFilters() {
        const container = document.getElementById('filtrosActivos');
        if (!container) return;

        const q = (document.getElementById('searchInput')?.value || '').trim();
        const estado = (document.getElementById('estadoSelect')?.value || '').trim();
        const sf = (document.getElementById('stockFilterInput')?.value || '').trim();

        const items = [];

        if (q) items.push({ key: 'q', label: `Buscar: ${q}` });

        if (estado === 'activos') items.push({ key: 'estado', label: 'Estado: Solo activos' });
        else if (estado === 'inactivos') items.push({ key: 'estado', label: 'Estado: Solo inactivos' });

        if (sf === 'sin') items.push({ key: 'stock_filter', label: 'Stock: Sin stock' });
        else if (sf === 'bajo') items.push({ key: 'stock_filter', label: 'Stock: Stock bajo' });

        container.innerHTML = '';

        if (items.length === 0) {
            container.style.display = 'none';
            return;
        }

        // Chip de "limpiar todo" (soluciona el caso de que el link PHP no exista en AJAX)
        items.push({ key: 'all', label: 'Limpiar filtros', clearAll: true });

        items.forEach((it) => {
            const tag = document.createElement('span');
            tag.className = 'filtro-tag' + (it.clearAll ? ' is-clearall' : '');
            tag.appendChild(document.createTextNode(it.label));

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'filtro-remove';
            btn.dataset.filter = it.key;
            btn.setAttribute('aria-label', it.clearAll ? 'Limpiar todos los filtros' : `Quitar ${it.label}`);
            btn.textContent = '×';

            tag.appendChild(btn);
            container.appendChild(tag);
        });

        container.style.display = 'flex';
    },

    bindTableSort() {
        const table = document.querySelector('.productos-table');
        if (!table) return;

        const sortInput = document.getElementById('sortInput');
        const dirInput  = document.getElementById('dirInput');
        const pageInput = document.getElementById('pageInput');

        const thead = table.querySelector('thead');
        if (!thead) return;

        thead.addEventListener('click', (e) => {
            const th = e.target.closest('th[data-sort]');
            if (!th) return;

            const col = th.dataset.sort;
            const currentSort = sortInput?.value || table.dataset.sort || 'nombre';
            let currentDir = (dirInput?.value || table.dataset.dir || 'ASC').toUpperCase();

            let nextDir = 'ASC';
            if (currentSort === col) nextDir = currentDir === 'ASC' ? 'DESC' : 'ASC';

            if (sortInput) sortInput.value = col;
            if (dirInput) dirInput.value = nextDir;
            if (pageInput) pageInput.value = '1';

            this.updateSortIndicators(col, nextDir);
            this.updateList({ history: 'push', force: true });
        });
    },

    // ============================================
    // EXPORT CSV
    // ============================================
    bindExportButton() {
        const btn = document.getElementById('btnExportCSV');
        if (!btn) return;

        btn.addEventListener('click', (e) => {
            e.preventDefault();

            const params = new URLSearchParams();

            // Mantener mismos filtros/orden/limit que la lista
            const searchInput = document.getElementById('searchInput');
            const estadoSelect = document.getElementById('estadoSelect');
            const stockFilterInput = document.getElementById('stockFilterInput');
            const sortInput = document.getElementById('sortInput');
            const dirInput = document.getElementById('dirInput');
            const limitSelect = document.getElementById('limitSelect');

            const q = (searchInput?.value || '').trim();
            const estado = (estadoSelect?.value || '').trim();
            const stockFilter = (stockFilterInput?.value || '').trim();
            const sort = (sortInput?.value || '').trim();
            const dir = (dirInput?.value || '').trim();
            const limit = (limitSelect?.value || '').trim();

            if (q) params.set('q', q);
            if (estado) params.set('estado', estado);
            if (stockFilter) params.set('stock_filter', stockFilter);
            if (sort) params.set('sort', sort);
            if (dir) params.set('dir', dir);
            if (limit) params.set('limit', limit);

            params.set('exportCSV', '1');

            window.location.href = 'productos.php?' + params.toString();
        });
    },

    // ============================================
    // REFRESH
    // ============================================
    bindRefreshButton() {
        const btn = document.getElementById('btnRefresh');
        if (!btn) return;

        btn.addEventListener('click', () => {
            this.updateList({ history: false, force: true });
            this.loadStats();
        });
    },

    // ============================================
    // FILE INPUTS
    // ============================================
    bindFileInputs() {
        document.querySelectorAll('input[type="file"]').forEach(input => {
            input.addEventListener('change', (e) => {
                const file = e.target.files?.[0];
                const nameSpan = e.target.closest('.file-input')?.querySelector('.file-name') ||
                                 document.getElementById('fileName');
                if (nameSpan) {
                    nameSpan.textContent = file ? file.name : 'Ningún archivo seleccionado';
                }
            });
        });
    },

    // ============================================
    // PESABLES
    // ============================================
    bindPesables() {
        this.initPesableBlock('esPesableMain', 'pesableOptionsMain', 'unidad_venta_visual', 'unidad_venta_real_main', 'pesablePreviewMain');
        this.initPesableBlock('esPesableEdit', 'pesableOptionsEdit', 'unidad_venta_visual_edit', 'unidad_venta_real_edit', 'pesablePreviewEdit');
    },

    initPesableBlock(toggleId, optionsId, radioName, hiddenId, previewId) {
        const toggle = document.getElementById(toggleId);
        const options = document.getElementById(optionsId);
        const hidden = document.getElementById(hiddenId);
        const preview = document.getElementById(previewId);

        if (!toggle || !options || !hidden) return;

        const form = toggle.closest('form');
        const radios = form?.querySelectorAll(`input[name="${radioName}"]`) || [];

        const UNIT_LABELS = { KG: '1 KG', G: '100 G', LT: '1 Litro', ML: '100 ML' };

        const updatePreview = () => {
            if (!preview) return;
            const valueSpan = preview.querySelector('.preview-compact-value');
            if (!valueSpan) return;

            const precioInput = form?.querySelector('[name="precio"]');
            const precio = parseFloat(precioInput?.value) || 0;
            const selected = form?.querySelector(`input[name="${radioName}"]:checked`);

            if (!toggle.checked || !selected) {
                preview.classList.add('empty');
                valueSpan.textContent = '—';
                return;
            }

            const label = UNIT_LABELS[selected.value] || selected.value;
            preview.classList.remove('empty');
            valueSpan.textContent = `$${precio.toFixed(2)} / ${label}`;
        };

        const syncHidden = (val) => {
            hidden.value = val || 'UNIDAD';
        };

        toggle.addEventListener('change', () => {
            if (toggle.checked) {
                options.style.display = 'block';
                if (!form?.querySelector(`input[name="${radioName}"]:checked`) && radios.length) {
                    radios[0].checked = true;
                    syncHidden(radios[0].value);
                }
            } else {
                options.style.display = 'none';
                radios.forEach(r => r.checked = false);
                syncHidden('UNIDAD');
            }
            updatePreview();
        });

        radios.forEach(radio => {
            radio.addEventListener('change', () => {
                if (radio.checked) {
                    syncHidden(radio.value);
                    updatePreview();
                }
            });
        });

        const precioInput = form?.querySelector('[name="precio"]');
        precioInput?.addEventListener('input', updatePreview);

        // Init
        updatePreview();
    },

    // ============================================
    // AUTOCOMPLETE
    // ============================================
    bindAutocomplete() {
        const fields = [
            { name: 'categoria', list: 'categorias-list', listEdit: 'categorias-list-edit' },
            { name: 'marca', list: 'marcas-list', listEdit: 'marcas-list-edit' },
            ];

        fields.forEach(({ name, list, listEdit }) => {
            document.querySelectorAll(`input[name="${name}"]`).forEach(input => {
                const datalistId = input.closest('#editForm') ? listEdit : list;
                input.addEventListener('focus', () => this.loadAutocomplete(name, datalistId), { once: true });
            });
        });

        // Proveedores: autocomplete con dropdown (no datalist)
        this.bindProveedorAutocomplete();
    },

    async loadAutocomplete(field, datalistId) {
        const datalist = document.getElementById(datalistId);
        if (!datalist || datalist.dataset.loaded) return;

        try {
            const res = await fetch(`productos.php?autocomplete=${field}`, { cache: 'no-store' });
            const values = await res.json();

            if (Array.isArray(values)) {
                datalist.innerHTML = values.map(v => `<option value="${this.escapeHtml(v)}">`).join('');
                datalist.dataset.loaded = '1';
            }
        } catch (e) {
            console.warn('[ProductosManager] Autocomplete error:', e);
        }
    },


    bindProveedorAutocomplete() {
        const select = document.getElementById('proveedoresData');
        if (!select) return;

        const proveedores = Array.from(select.options)
            .filter(o => o.value && o.textContent)
            .map(o => {
                const nombre = (o.textContent || '').trim();
                return {
                    id: parseInt(o.value, 10),
                    nombre,
                    norm: nombre.toLowerCase(),
                };
            })
            .filter(p => p.id > 0 && p.nombre);

        const setup = (input, hidden, box) => {
            if (!input || !hidden || !box) return;

            let idx = -1;
            let filtered = [];

            const close = () => {
                box.classList.remove('active');
                box.innerHTML = '';
                idx = -1;
                filtered = [];
            };

            const render = () => {
                if (!filtered.length) {
                    close();
                    return;
                }
                box.innerHTML = filtered.map(p =>
                    `<div class="suggestion-item" data-id="${p.id}">${this.escapeHtml(p.nombre)}</div>`
                ).join('');
                box.classList.add('active');
                idx = -1;
            };

            const clearActive = () => {
                box.querySelectorAll('.suggestion-item').forEach(el => el.classList.remove('keyboard-active'));
            };

            const setActive = (i) => {
                const items = Array.from(box.querySelectorAll('.suggestion-item'));
                if (!items.length) return;
                const next = Math.max(0, Math.min(i, items.length - 1));
                clearActive();
                items[next].classList.add('keyboard-active');
                items[next].scrollIntoView({ block: 'nearest' });
                idx = next;
            };

            const choose = (p) => {
                input.value = p.nombre;
                hidden.value = String(p.id);
                close();
            };

            const syncExact = () => {
                const q = (input.value || '').trim().toLowerCase();
                if (!q) return;
                const hit = proveedores.find(p => p.norm === q);
                if (hit) choose(hit);
            };

            input.addEventListener('input', () => {
                hidden.value = '0';
                const q = (input.value || '').trim().toLowerCase();
                if (q.length < 2) {
                    close();
                    return;
                }
                filtered = proveedores.filter(p => p.norm.includes(q)).slice(0, 10);
                render();
            });

            input.addEventListener('keydown', (e) => {
                if (!box.classList.contains('active')) return;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    setActive(idx + 1);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    setActive(idx - 1);
                } else if (e.key === 'Enter') {
                    if (idx >= 0 && filtered[idx]) {
                        e.preventDefault();
                        choose(filtered[idx]);
                    } else {
                        // si escribió exacto, enganchar
                        syncExact();
                    }
                } else if (e.key === 'Escape') {
                    close();
                }
            });

            box.addEventListener('mousedown', (e) => {
                const item = e.target.closest('.suggestion-item');
                if (!item) return;
                const id = parseInt(item.getAttribute('data-id') || '0', 10);
                const p = proveedores.find(x => x.id === id);
                if (p) choose(p);
            });

            input.addEventListener('blur', () => {
                setTimeout(() => {
                    syncExact();
                    close();
                }, 120);
            });

            document.addEventListener('click', (e) => {
                const wrap = input.closest('.search-wrapper') || input.parentElement;
                if (wrap && !wrap.contains(e.target)) close();
            });
        };

        setup(document.getElementById('proveedorBuscar'), document.getElementById('proveedorId'), document.getElementById('proveedorSuggestions'));
        setup(document.getElementById('proveedorBuscarEdit'), document.getElementById('proveedorIdEdit'), document.getElementById('proveedorSuggestionsEdit'));
    },

    // ============================================
    // KEYBOARD SHORTCUTS
    // ============================================
    
    // ============================================
    // LISTADO AJAX (sin recargar)
    // ============================================
    setListLoading(on) {
        this.state.listLoading = !!on;

        const wrap = document.getElementById('tableWrapper');
        const loader = document.getElementById('listLoading');
        if (wrap) wrap.classList.toggle('is-loading', !!on);
        if (loader) loader.style.display = on ? 'flex' : 'none';
    },

    applySearchParamsToForm(sp) {
        const q = document.getElementById('searchInput');
        const estado = document.getElementById('estadoSelect');
        const limit = document.getElementById('limitSelect');
        const sort = document.getElementById('sortInput');
        const dir = document.getElementById('dirInput');
        const page = document.getElementById('pageInput');
        const stockFilter = document.getElementById('stockFilterInput');

        if (q) q.value = sp.get('q') || '';
        if (estado) estado.value = sp.get('estado') || '';
        if (limit) limit.value = sp.get('limit') || (limit.value || '20');
        if (sort) sort.value = sp.get('sort') || (sort.value || 'nombre');
        if (dir) dir.value = (sp.get('dir') || (dir.value || 'ASC')).toUpperCase();
        if (page) page.value = sp.get('page') || '1';
        if (stockFilter) stockFilter.value = sp.get('stock_filter') || '';
    },

    getListParams(overrides = {}) {
        const q = document.getElementById('searchInput')?.value ?? '';
        const estado = document.getElementById('estadoSelect')?.value ?? '';
        const limit = document.getElementById('limitSelect')?.value ?? '20';
        const sort = document.getElementById('sortInput')?.value ?? 'nombre';
        const dir = document.getElementById('dirInput')?.value ?? 'ASC';
        const page = document.getElementById('pageInput')?.value ?? '1';
        const stockFilter = document.getElementById('stockFilterInput')?.value ?? '';

        const params = new URLSearchParams();

        const qv = (overrides.q ?? q).trim();
        const ev = String(overrides.estado ?? estado).trim();
        const lv = String(overrides.limit ?? limit).trim();
        const sv = String(overrides.sort ?? sort).trim();
        const dv = String(overrides.dir ?? dir).trim();
        const pv = String(overrides.page ?? page).trim();
        const sfv = String(overrides.stock_filter ?? stockFilter).trim();

        if (qv !== '') params.set('q', qv);
        if (ev !== '') params.set('estado', ev);
        if (sfv !== '') params.set('stock_filter', sfv);

        if (lv !== '') params.set('limit', lv);
        if (sv !== '') params.set('sort', sv);
        if (dv !== '') params.set('dir', dv.toUpperCase());

        if (pv !== '' && pv !== '1') params.set('page', pv);

        return params;
    },

    syncUrl(params, mode = 'replace') {
        const qs = params.toString();
        const url = qs ? `productos.php?${qs}` : 'productos.php';
        if (mode === 'push') history.pushState(null, '', url);
        else if (mode === 'replace') history.replaceState(null, '', url);
    },

    updateSortIndicators(sort, dir) {
        const table = document.querySelector('.productos-table');
        if (!table) return;

        table.dataset.sort = sort;
        table.dataset.dir = dir;

        const ths = table.querySelectorAll('thead th[data-sort]');
        ths.forEach(th => {
            th.classList.remove('sorted-asc', 'sorted-desc');
            if (th.dataset.sort === sort) {
                th.classList.add('sorted-' + String(dir).toLowerCase());
            }
        });
    },

    async updateList(options = {}) {
        const historyMode = options.history ?? false; // 'push' | 'replace' | false
        const highlightId = options.highlightId ?? null;

        const params = this.getListParams(options.overrides || {});
        const metaSig = params.toString();

        if (!options.force && this.state.lastListSig === metaSig && !options.overrides) return;

        const fetchParams = new URLSearchParams(params);
        if (!fetchParams.has('page')) fetchParams.set('page', document.getElementById('pageInput')?.value || '1');
        fetchParams.set('ajaxList', '1');
        fetchParams.set('_ts', String(Date.now()));

        try { this.state.listController?.abort(); } catch (_) {}
        const controller = new AbortController();
        this.state.listController = controller;

        this.setListLoading(true);

        try {
            const res = await fetch(`productos.php?${fetchParams.toString()}`, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                signal: controller.signal
            });
            if (!res.ok) throw new Error('HTTP_' + res.status);

            const ct = (res.headers.get('content-type') || '').toLowerCase();
            if (!ct.includes('application/json')) {
                const txt = await res.text();
                const head = String(txt || '').slice(0, 80).replace(/\s+/g, ' ');
                throw new Error('NOT_JSON: ' + head);
            }

            const data = await res.json();
            if (!data || data.success !== true) throw new Error(data?.error || 'AJAX_LIST_FAIL');

            const tbody = document.getElementById('productosTbody');
            if (tbody) tbody.innerHTML = data.tbody_html || '';

            const pag = document.getElementById('paginationContainer');
            if (pag) pag.innerHTML = data.pagination_html || '';
            const pagTop = document.getElementById('paginationContainerTop');
            if (pagTop) pagTop.innerHTML = data.pagination_html || '';

            const meta = data.meta || {};

            const sortInput = document.getElementById('sortInput');
            const dirInput = document.getElementById('dirInput');
            const pageInput = document.getElementById('pageInput');
            const limitSelect = document.getElementById('limitSelect');
            const estadoSelect = document.getElementById('estadoSelect');
            const stockFilterInput = document.getElementById('stockFilterInput');

            if (sortInput && meta.sort) sortInput.value = meta.sort;
            if (dirInput && meta.dir) dirInput.value = String(meta.dir).toUpperCase();
            if (pageInput) pageInput.value = String(meta.page || 1);
            if (limitSelect) limitSelect.value = String(meta.limit || limitSelect.value || '20');
            if (estadoSelect) estadoSelect.value = String(meta.estado || '');
            if (stockFilterInput) stockFilterInput.value = String(meta.stock_filter || '');

            this.renderActiveFilters();

            this.updateSortIndicators(sortInput?.value || meta.sort || 'nombre', dirInput?.value || meta.dir || 'ASC');

            if (historyMode) {
                const urlParams = this.getListParams();
                if ((pageInput?.value || '1') !== '1') urlParams.set('page', pageInput.value);
                this.syncUrl(urlParams, historyMode);
            }

            this.state.lastListSig = metaSig;
            this.state.allDetailsExpanded = false;

            if (highlightId) {
                const row = document.querySelector(`tr[data-id="${highlightId}"]`);
                if (row) {
                    row.classList.add('row-updated');
                    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    setTimeout(() => row.classList.remove('row-updated'), 1500);
                }
            }

        } catch (err) {
            if (err?.name !== 'AbortError') {
                console.error('[ProductosManager] updateList error:', err);
                this.showToast('Error al cargar productos', 'error');
            }
        } finally {
            if (this.state.listController === controller) this.state.listController = null;
            this.setListLoading(false);
        }
    },

    bindPagination() {
        const containers = [
            document.getElementById('paginationContainerTop'),
            document.getElementById('paginationContainer')
        ].filter(Boolean);

        if (containers.length === 0) return;

        const onClick = (e) => {
            const a = e.target.closest('a.page-btn');
            if (!a) return;

            e.preventDefault();
            const u = new URL(a.href, window.location.origin);
            this.applySearchParamsToForm(u.searchParams);
            this.renderActiveFilters();
            this.updateList({ history: 'push', force: true });
        };

        containers.forEach((c) => c.addEventListener('click', onClick));
    },

    bindHistory() {
        window.addEventListener('popstate', () => {
            const sp = new URLSearchParams(window.location.search);
            this.applySearchParamsToForm(sp);
            this.renderActiveFilters();
            this.updateList({ history: false, force: true });
        });
    },

bindKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Ctrl+K: Focus búsqueda
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                const search = document.getElementById('searchInput');
                search?.focus();
                search?.select();
            }

            // Ctrl+N: Nuevo producto
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'n') {
                e.preventDefault();
                const formBlock = document.getElementById('productFormBlock');
                const btn = document.getElementById('toggleFormBtn');
                
                if (formBlock?.classList.contains('is-collapsed')) {
                    btn?.click();
                }
                setTimeout(() => {
                    document.querySelector('#mainProductForm input[name="codigo"]')?.focus();
                }, 150);
            }

            // Escape: Cerrar modales
            if (e.key === 'Escape') {
                const editOverlay = document.getElementById('editOverlay');
                const confirmModal = document.getElementById('confirmModal');

                if (confirmModal?.classList.contains('open')) {
                    this.closeConfirm();
                } else if (editOverlay?.classList.contains('open')) {
                    this.closeEdit();
                }
            }
        });
    },

    // ============================================
    // BEFORE UNLOAD (cambios sin guardar)
    // ============================================
    bindBeforeUnload() {
        window.addEventListener('beforeunload', (e) => {
            if (this.state.formDirty || this.state.editFormDirty) {
                e.preventDefault();
                e.returnValue = '';
                return '';
            }
        });
    },
};

// Funciones globales para compatibilidad con onclick=""
window.ProductosManager = ProductosManager;
window.openEditPanel = (id) => ProductosManager.openEdit(id);
window.closeEditPanel = () => ProductosManager.closeEdit();
window.toggleDetailRow = (id) => ProductosManager.toggleDetail(id);
window.showToast = (msg, type) => ProductosManager.showToast(msg, type);

// Inicializar
document.addEventListener('DOMContentLoaded', () => ProductosManager.init());

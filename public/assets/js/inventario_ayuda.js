/**
 * inventario_ayuda.js
 * Sistema de ayuda interactivo para el módulo de Análisis de Inventario
 * 
 * Funcionalidades:
 * - Drawer de ayuda contextual
 * - Botones de ayuda (?) en cada métrica
 * - Botón flotante de ayuda general
 * - Tour guiado (opcional)
 * 
 * @version 1.0.0
 */

(function() {
    'use strict';

    const HelpSystem = {
        modal: null,
        content: null,
        data: null,

        /**
         * Inicializa el sistema de ayuda
         */
        init() {
            // Obtener datos de ayuda
            this.data = window.FLUS_AYUDA_METRICAS || {};
            
            // Referencias DOM
            this.modal = document.getElementById('inv-help-modal');
            this.content = document.getElementById('inv-help-content');
            
            if (!this.modal) {
                this.createModal();
            }
            
            // Event listeners
            this.bindEvents();
            
            // Crear botón flotante si no existe
            this.createFloatingButton();
            
        },

        /**
         * Crea el modal si no existe en el DOM
         */
        createModal() {
            const modalHTML = `
                <div id="inv-help-modal" class="inv-help-modal" aria-hidden="true">
                    <div class="inv-help-backdrop"></div>
                    <div class="inv-help-drawer">
                        <button type="button" class="inv-help-close" aria-label="Cerrar">&times;</button>
                        <div class="inv-help-content" id="inv-help-content"></div>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            this.modal = document.getElementById('inv-help-modal');
            this.content = document.getElementById('inv-help-content');
        },

        /**
         * Crea el botón flotante de ayuda
         */
        createFloatingButton() {
            if (document.querySelector('.inv-help-global-btn')) return;
            
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'inv-help-global-btn';
            btn.setAttribute('aria-label', 'Abrir ayuda');
            btn.innerHTML = '<span>?</span>';
            btn.addEventListener('click', () => this.showGlosario());
            
            document.body.appendChild(btn);
        },

        /**
         * Bindea todos los eventos
         */
        bindEvents() {
            // Botones de ayuda individual
            document.addEventListener('click', (e) => {
                const helpBtn = e.target.closest('.inv-help-btn');
                if (helpBtn) {
                    e.preventDefault();
                    const key = helpBtn.dataset.help;
                    this.showHelp(key);
                }
            });
            
            // Cerrar modal
            document.addEventListener('click', (e) => {
                if (e.target.classList.contains('inv-help-close') ||
                    e.target.classList.contains('inv-help-backdrop')) {
                    this.closeModal();
                }
            });
            
            // Cerrar con Escape
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.modal?.classList.contains('active')) {
                    this.closeModal();
                }
            });
        },

        /**
         * Muestra la ayuda para una métrica específica
         */
        showHelp(key) {
            const ayuda = this.data[key];
            if (!ayuda) {
                console.warn('[FLUS] Ayuda no encontrada para:', key);
                return;
            }
            
            let html = `
                <div class="inv-help-header">
                    <span class="inv-help-header-icon">${this.escapeHtml(ayuda.icono || 'Info')}</span>
                    <h3>${this.escapeHtml(ayuda.titulo)}</h3>
                </div>
                
                <div class="inv-help-section">
                    <h4>¿Qué significa?</h4>
                    <p>${this.escapeHtml(ayuda.descripcion)}</p>
                </div>
            `;
            
            // Detalle ABC especial
            if (ayuda.detalle && typeof ayuda.detalle === 'object') {
                html += `<div class="inv-help-section">
                    <h4>Clasificación</h4>
                    <ul class="inv-help-abc-list">`;
                
                for (const [letra, desc] of Object.entries(ayuda.detalle)) {
                    html += `
                        <li class="inv-help-abc-item">
                            <span class="inv-help-abc-badge abc-${letra.toLowerCase()}">${letra}</span>
                            <span>${this.escapeHtml(desc)}</span>
                        </li>`;
                }
                
                html += `</ul></div>`;
            }
            
            // Ejemplo
            if (ayuda.ejemplo) {
                html += `
                    <div class="inv-help-section">
                        <h4>Ejemplo practico</h4>
                        <div class="inv-help-example">${this.escapeHtml(ayuda.ejemplo)}</div>
                    </div>
                `;
            }
            
            // Qué hacer (acción)
            if (ayuda.accion) {
                html += `
                    <div class="inv-help-section">
                        <div class="inv-help-action">${this.escapeHtml(ayuda.accion)}</div>
                    </div>
                `;
            }
            
            // Advertencia
            if (ayuda.advertencia) {
                html += `
                    <div class="inv-help-section">
                        <div class="inv-help-warning">${this.escapeHtml(ayuda.advertencia)}</div>
                    </div>
                `;
            }
            
            this.content.innerHTML = html;
            this.openModal();
        },

        /**
         * Muestra el glosario completo
         */
        showGlosario() {
            let html = `
                <div class="inv-help-header">
                    <span class="inv-help-header-icon">Guia</span>
                    <h3>Glosario: todas las metricas</h3>
                </div>
                <p class="inv-help-intro">
                    Tocá cualquier concepto para ver la explicación completa.
                </p>
            `;
            
            for (const [key, ayuda] of Object.entries(this.data)) {
                html += `
                    <div class="inv-glosario-item-inline"
                         role="button" 
                         tabindex="0"
                        data-help-key="${key}">
                        <div class="inv-glosario-inline-row">
                            <span class="inv-help-metric-chip">${this.escapeHtml(ayuda.icono || 'Info')}</span>
                            <div>
                                <strong>${this.escapeHtml(ayuda.titulo)}</strong>
                                <p>
                                    ${this.truncate(ayuda.descripcion, 80)}
                                </p>
                            </div>
                            <span class="inv-glosario-inline-action">Ver</span>
                        </div>
                    </div>
                `;
            }
            
            this.content.innerHTML = html;
            
            // Bind clicks en items del glosario
            this.content.querySelectorAll('.inv-glosario-item-inline').forEach(item => {
                item.addEventListener('click', () => {
                    this.showHelp(item.dataset.helpKey);
                });
                item.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter') this.showHelp(item.dataset.helpKey);
                });
            });
            
            this.openModal();
        },

        /**
         * Abre el modal
         */
        openModal() {
            if (!this.modal) return;
            this.modal.classList.add('active');
            this.modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        },

        /**
         * Cierra el modal
         */
        closeModal() {
            if (!this.modal) return;
            this.modal.classList.remove('active');
            this.modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        },

        /**
         * Escapa HTML
         */
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        },

        /**
         * Trunca texto
         */
        truncate(text, maxLength) {
            if (!text) return '';
            if (text.length <= maxLength) return text;
            return text.substring(0, maxLength) + '...';
        }
    };

    /**
     * Tour guiado (onboarding) - Opcional
     */
    const GuidedTour = {
        steps: [
            {
                target: '.inv-card-primary',
                title: 'Capital Invertido',
                content: 'Acá ves cuánto dinero tenés "parado" en mercadería. Es la suma de (costo × stock) de todos tus productos.',
                position: 'bottom'
            },
            {
                target: '.inv-card-success',
                title: 'Valor de Venta',
                content: 'Si vendieras todo tu stock al precio actual, este sería el monto total.',
                position: 'bottom'
            },
            {
                target: '.inv-tabs',
                title: 'Pestañas de análisis',
                content: 'Cada pestaña te muestra un análisis diferente: inversión, rotación, productos parados, alertas y ventas.',
                position: 'bottom'
            },
            {
                target: '[href="?tab=alertas"]',
                title: 'Alertas importantes',
                content: '¡Mirá esta pestaña seguido! Te avisa cuando hay productos con stock bajo o próximos a agotarse.',
                position: 'bottom'
            }
        ],
        currentStep: 0,

        /**
         * Inicia el tour
         */
        start() {
            // Verificar si ya se hizo el tour
            if (localStorage.getItem('flus_inv_tour_done') === '1') {
                return;
            }
            
            this.currentStep = 0;
            this.showStep();
        },

        /**
         * Muestra el paso actual
         */
        showStep() {
            // Limpiar paso anterior
            document.querySelectorAll('.inv-tour-highlight').forEach(el => {
                el.classList.remove('inv-tour-highlight');
            });
            document.querySelectorAll('.inv-tour-tooltip').forEach(el => el.remove());
            
            const step = this.steps[this.currentStep];
            if (!step) {
                this.end();
                return;
            }
            
            const target = document.querySelector(step.target);
            if (!target) {
                this.next();
                return;
            }
            
            // Highlight
            target.classList.add('inv-tour-highlight');
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // Crear tooltip
            setTimeout(() => {
                const tooltip = document.createElement('div');
                tooltip.className = 'inv-tour-tooltip';
                tooltip.innerHTML = `
                    <h4>${step.title}</h4>
                    <p>${step.content}</p>
                    <div class="inv-tour-actions">
                        <button type="button" class="btn btn-secondary btn-sm" data-inv-tour-skip>Saltar</button>
                        <button type="button" class="btn btn-primary btn-sm" data-inv-tour-next>
                            ${this.currentStep < this.steps.length - 1 ? 'Siguiente' : 'Finalizar'}
                        </button>
                    </div>
                    <div class="inv-tour-progress">
                        ${this.currentStep + 1} de ${this.steps.length}
                    </div>
                `;
                
                // Posicionar
                const rect = target.getBoundingClientRect();
                tooltip.style.top = (rect.bottom + window.scrollY + 12) + 'px';
                tooltip.style.left = rect.left + 'px';
                
                tooltip.querySelector('[data-inv-tour-skip]')?.addEventListener('click', () => this.skip());
                tooltip.querySelector('[data-inv-tour-next]')?.addEventListener('click', () => this.next());
                document.body.appendChild(tooltip);
            }, 300);
        },

        /**
         * Siguiente paso
         */
        next() {
            this.currentStep++;
            if (this.currentStep >= this.steps.length) {
                this.end();
            } else {
                this.showStep();
            }
        },

        /**
         * Saltar tour
         */
        skip() {
            this.end();
        },

        /**
         * Finaliza el tour
         */
        end() {
            document.querySelectorAll('.inv-tour-highlight').forEach(el => {
                el.classList.remove('inv-tour-highlight');
            });
            document.querySelectorAll('.inv-tour-tooltip').forEach(el => el.remove());
            localStorage.setItem('flus_inv_tour_done', '1');
        },

        /**
         * Resetea el tour (para volver a mostrarlo)
         */
        reset() {
            localStorage.removeItem('flus_inv_tour_done');
        }
    };

    // Exponer globalmente para el tour
    window.GuidedTour = GuidedTour;

    /**
     * Añadir botones de ayuda automáticamente a elementos con data-help-key
     */
    function injectHelpButtons() {
        document.querySelectorAll('[data-help-key]').forEach(el => {
            // Evitar duplicados
            if (el.querySelector('.inv-help-btn')) return;
            
            const key = el.dataset.helpKey;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'inv-help-btn';
            btn.dataset.help = key;
            btn.setAttribute('aria-label', 'Ver ayuda');
            btn.innerHTML = '<span class="inv-help-icon">?</span>';
            
            el.appendChild(btn);
        });
    }

    /**
     * Inicialización al cargar el DOM
     */
    document.addEventListener('DOMContentLoaded', () => {
        HelpSystem.init();
        injectHelpButtons();
        
        // Iniciar tour si es la primera vez (descomentá para activar)
        // setTimeout(() => GuidedTour.start(), 1000);
    });

    // Exponer para uso externo
    window.FLUSHelp = HelpSystem;

})();

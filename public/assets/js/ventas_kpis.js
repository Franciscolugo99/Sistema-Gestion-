/* public/assets/js/ventas_kpis.js - FLUS (2026) ✅
   KPIs operativos en Ventas: estilos FLUS + clickeables + animaciones.
   Endpoint: /api/ventas_kpis.php

   - Actualiza nodos con [data-kpi="..."]
   - Si no existe #ventas-kpis, lo inserta arriba (modo fallback)
   - KPIs clickeables:
       * Tickets/Total confirmado/Ticket Prom/Descuentos => Estado=EMITIDA
       * Anuladas => Estado=ANULADA
       * Chips de pago => setea Medio (si existe en select) y filtra
*/
(function(){
  'use strict';

  const REL_API = 'api/ventas_kpis.php';

  function buildApiUrl(){
    const base = (window.FLUS_BASE_API || window.BASE_URL || '').toString().trim();
    if (base) return base.replace(/\/+$/,'') + '/' + REL_API;
    return new URL(REL_API, window.location.href).toString();
  }

  function $(sel, root=document){ return root.querySelector(sel); }
  function $all(sel, root=document){ return Array.from(root.querySelectorAll(sel)); }

  function fmtMoney(n){
    const v = Number(n || 0);
    try {
      return new Intl.NumberFormat('es-AR', { style:'currency', currency:'ARS', maximumFractionDigits: 2 }).format(v);
    } catch(e) { return '$' + v.toFixed(2); }
  }

  function fmtNum(n){
    const v = Number(n || 0);
    try { return new Intl.NumberFormat('es-AR').format(v); } catch(e){ return String(v); }
  }

  function pickValue(selectors){
    for (const s of selectors){
      const el = $(s);
      if (el && (el.value ?? '').toString().trim() !== '') return (el.value ?? '').toString().trim();
    }
    return '';
  }

  function readFilters(){
    const u = new URL(window.location.href);
    const qp = u.searchParams;

    const desde = qp.get('desde') || qp.get('fecha_desde') || pickValue([
      '#desde','#fecha_desde','#fecha_inicio','#f_desde','input[name="desde"]','input[name="fecha_desde"]','input[name="fecha_inicio"]'
    ]);
    const hasta = qp.get('hasta') || qp.get('fecha_hasta') || pickValue([
      '#hasta','#fecha_hasta','#fecha_fin','#f_hasta','input[name="hasta"]','input[name="fecha_hasta"]','input[name="fecha_fin"]'
    ]);
    const hora_desde = qp.get('hora_desde') || pickValue(['#hora_desde','#hora_inicio','input[name="hora_desde"]','input[name="hora_inicio"]']);
    const hora_hasta = qp.get('hora_hasta') || pickValue(['#hora_hasta','#hora_fin','input[name="hora_hasta"]','input[name="hora_fin"]']);

    const medio = (qp.get('medio') || qp.get('medio_pago') || pickValue([
      'select[name="medio"]','select[name="medio_pago"]','#medio','#medio_pago'
    ])).toString().trim();

    const estado = (qp.get('estado') || pickValue(['select[name="estado"]','#estado'])).toString().trim();
    const venta_id = (qp.get('venta_id') || pickValue(['input[name="venta_id"]','#venta_id'])).toString().trim();
    const cliente_id = (qp.get('cliente_id') || pickValue(['#clienteIdHidden','input[name="cliente_id"]','#cliente_id'])).toString().trim();

    return { desde, hasta, hora_desde, hora_hasta, medio, estado, venta_id, cliente_id };
  }

  function ensureContainer(){
    let container = document.getElementById('ventas-kpis');
    if (container) return container;

    const main = $('main') || $('.content') || $('.container') || $('.ventas-page') || document.body;

    container = document.createElement('section');
    container.id = 'ventas-kpis';
    container.className = 'ventas-kpis';
    container.innerHTML = `
      <div class="vkpi-grid" role="list">
        <div class="vkpi-card" role="listitem">
          <div class="vkpi-label">Tickets confirmados</div>
          <div class="vkpi-value" data-kpi="tickets">0</div>
        </div>
        <div class="vkpi-card" role="listitem">
          <div class="vkpi-label">Total confirmado</div>
          <div class="vkpi-value" data-kpi="facturacion">$0,00</div>
        </div>
        <div class="vkpi-card" role="listitem">
          <div class="vkpi-label">Ticket promedio</div>
          <div class="vkpi-value" data-kpi="ticket_promedio">$0,00</div>
        </div>
        <div class="vkpi-card" role="listitem">
          <div class="vkpi-label">Descuentos</div>
          <div class="vkpi-value" data-kpi="descuentos">$0,00</div>
          <div class="vkpi-sub" data-kpi="desc_promos">Promos: $0,00</div>
        </div>
        <div class="vkpi-card" role="listitem">
          <div class="vkpi-label">Anuladas</div>
          <div class="vkpi-value" data-kpi="anuladas">0</div>
          <div class="vkpi-sub" data-kpi="monto_anulado">$0,00</div>
        </div>
      </div>
      <div class="vkpi-pagos">
        <div class="vkpi-pagos-title">Pagos por medio</div>
        <div class="vkpi-chips" data-kpi="pagos"></div>
      </div>
    `;

    if (main.firstChild) main.insertBefore(container, main.firstChild);
    else main.appendChild(container);

    return container;
  }

  function raf(cb){ return window.requestAnimationFrame(cb); }

  function animateValue(el, to, fmt, {prefix='', duration=380} = {}){
    const from = Number(el.dataset.raw ?? '0') || 0;
    const target = Number(to || 0);
    el.dataset.raw = String(target);

    // Primer render o cambios mínimos => set directo
    if (!el.dataset._init || Math.abs(target - from) < 0.01){
      el.textContent = prefix + fmt(target);
      el.dataset._init = '1';
      el.classList.remove('vkpi-bump');
      void el.offsetWidth; // reflow
      el.classList.add('vkpi-bump');
      return;
    }

    const start = performance.now();
    const easeOutCubic = (t) => 1 - Math.pow(1 - t, 3);

    const step = (now) => {
      const p = Math.min(1, (now - start) / duration);
      const v = from + (target - from) * easeOutCubic(p);
      el.textContent = prefix + fmt(v);
      if (p < 1) raf(step);
      else {
        el.classList.remove('vkpi-bump');
        void el.offsetWidth;
        el.classList.add('vkpi-bump');
      }
    };
    raf(step);
  }

  function decorateCards(container){
    const cards = $all('.vkpi-card', container);
    for (const card of cards){
      if (card.hasAttribute('data-filter')) continue;
      // Accesibilidad + foco
      card.setAttribute('tabindex','0');
      card.setAttribute('role','button');

      // Inferir tipo por key que contiene
      const has = (k) => !!card.querySelector(`[data-kpi="${k}"]`);

      // Iconos + acciones
      if (has('tickets')){
        card.dataset.ico = '🛒';
        card.dataset.action = 'emitidas';
      } else if (has('facturacion')){
        card.dataset.ico = '💰';
        card.dataset.action = 'emitidas';
        card.dataset.accent = 'primary';
      } else if (has('ticket_promedio')){
        card.dataset.ico = '🎫';
        card.dataset.action = 'emitidas';
      } else if (has('descuentos')){
        card.dataset.ico = '🏷️';
        card.dataset.action = 'emitidas';
      } else if (has('anuladas')){
        card.dataset.ico = '⛔';
        card.dataset.action = 'anuladas';
        card.dataset.accent = 'danger';
      }
    }
  }

  function findVentasForm(){
    return $('#ventasForm') || $('form.filtros-form') || $('form');
  }

  function setSelectValue(sel, val){
    if (!sel) return false;
    const v = (val ?? '').toString();
    // Si existe opción exacta, setear
    const opt = Array.from(sel.options || []).find(o => (o.value ?? '').toString().toUpperCase() === v.toUpperCase());
    if (opt){
      sel.value = opt.value;
      return true;
    }
    // Intentar set directo
    sel.value = v;
    return true;
  }

  function normalizeMedio(m){
    const s = (m || '').toString().trim().toUpperCase();
    if (!s) return '';
    if (s === 'MERCADOPAGO' || s === 'MPAGO' || s === 'MERCADO PAGO') return 'MP';
    if (s.includes('DEB')) return 'DEBITO';
    if (s.includes('CRED')) return 'CREDITO';
    if (s.includes('EFE')) return 'EFECTIVO';
    if (s === 'QR' || s.includes('QR')) return 'QR';
    return s;
  }

  function applyFilter({estado=null, medio=null}){
    const form = findVentasForm();
    if (!form) return;

    const selEstado = form.querySelector('select[name="estado"]') || $('#estado');
    const selMedio  = form.querySelector('select[name="medio"]') || form.querySelector('select[name="medio_pago"]') || $('#medio') || $('#medio_pago');

    if (estado !== null) setSelectValue(selEstado, estado);
    if (medio !== null) setSelectValue(selMedio, medio);

    // reset page
    const pageHidden = form.querySelector('input[name="page"]') || $('#hiddenPage');
    if (pageHidden) pageHidden.value = '1';

    // Disparar submit (mismo comportamiento que el usuario al filtrar)
    form.submit();
  }

  function bindClicks(container){
    // KPIs clickeables
    const cards = $all('.vkpi-card', container);
    for (const card of cards){
      if (card.hasAttribute('data-filter')) continue;
      const act = (card.dataset.action || '').toString();
      const handler = () => {
        if (act === 'emitidas') applyFilter({estado:'EMITIDA'});
        else if (act === 'anuladas') applyFilter({estado:'ANULADA'});
      };
      card.addEventListener('click', handler);
      card.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' '){
          e.preventDefault();
          handler();
        }
      });
    }
  }

  function updateKPIs(payload){
    if (!payload || payload.ok !== true) return;
    const k = payload.kpis || {};
    const pagos = Array.isArray(payload.pagos) ? payload.pagos : [];

    const setAnimated = (key, rawValue, kind='num', opts={}) => {
      const nodes = $all(`[data-kpi="${key}"]`);
      if (!nodes.length) return;
      for (const n of nodes){
        if (kind === 'money') animateValue(n, rawValue, (v)=>fmtMoney(v).replace(/\s/g,''), opts);
        else animateValue(n, rawValue, (v)=>fmtNum(Math.round(v)), opts);
      }
    };

    setAnimated('tickets', k.tickets ?? 0, 'num');
    setAnimated('facturacion', k.facturacion ?? 0, 'money');
    setAnimated('ticket_promedio', k.ticket_promedio ?? 0, 'money');
    setAnimated('descuentos', k.descuentos ?? 0, 'money');
    setAnimated('anuladas', k.anuladas ?? 0, 'num');
    setAnimated('monto_anulado', k.monto_anulado ?? 0, 'money');

    // desc_promos con prefijo
    const nodesProm = $all('[data-kpi="desc_promos"]');
    for (const n of nodesProm){
      animateValue(n, (k.desc_promos ?? 0), (v)=>fmtMoney(v).replace(/\s/g,''), {prefix:'Promos: '});
    }

    // Chips pagos
    const chipsWrap = $('[data-kpi="pagos"]');
    if (chipsWrap){
      chipsWrap.innerHTML = '';
      const totalAll = pagos.reduce((acc, p) => acc + Number(p.total || 0), 0);

      for (const p of pagos){
        const medioRaw = (p.medio_pago || '').toString().trim() || 'N/A';
        const total = Number(p.total || 0);
        const pct = totalAll > 0 ? Math.round((total / totalAll) * 100) : 0;

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'vkpi-chip';
        btn.title = `${medioRaw}: ${fmtMoney(total)} (${pct}%)`;
        btn.dataset.medio = normalizeMedio(medioRaw);

        const name = document.createElement('span');
        name.className = 'vkpi-chip-name';
        name.textContent = medioRaw;
        const value = document.createElement('span');
        value.className = 'vkpi-chip-val';
        value.textContent = fmtMoney(total);
        const percentage = document.createElement('span');
        percentage.className = 'vkpi-chip-pct';
        percentage.textContent = `${pct}%`;
        btn.append(name, value, percentage);

        btn.addEventListener('click', () => {
          // Si el medio no existe en tu select, igual setea (no rompe).
          applyFilter({medio: btn.dataset.medio || ''});
        });

        chipsWrap.appendChild(btn);
      }

      if (!pagos.length){
        const empty = document.createElement('div');
        empty.className = 'vkpi-chip vkpi-chip--empty';
        empty.textContent = 'Sin pagos para el filtro';
        chipsWrap.appendChild(empty);
      }
    }
  }

  async function fetchKPIs(container){
    const f = readFilters();
    const url = new URL(buildApiUrl());

    if (f.desde) url.searchParams.set('desde', f.desde);
    if (f.hasta) url.searchParams.set('hasta', f.hasta);
    if (f.hora_desde) url.searchParams.set('hora_desde', f.hora_desde);
    if (f.hora_hasta) url.searchParams.set('hora_hasta', f.hora_hasta);
    if (f.medio) url.searchParams.set('medio', f.medio);
    if (f.estado) url.searchParams.set('estado', f.estado);
    if (f.venta_id) url.searchParams.set('venta_id', f.venta_id);
    if (f.cliente_id) url.searchParams.set('cliente_id', f.cliente_id);

    try {
      if (container) container.classList.add('is-loading');
      const r = await fetch(url.toString(), { credentials:'same-origin', headers:{'Accept':'application/json'} });
      const j = await r.json();
      updateKPIs(j);
    } catch(e) {
      // No romper UI
    } finally {
      if (container) container.classList.remove('is-loading');
    }
  }

  window.FLUS_refreshVentasKPIs = () => {
    const c = document.getElementById('ventas-kpis');
    fetchKPIs(c);
  };

  function bindAutoRefresh(container){
    const filterSelectors = [
      '#desde','#hasta','#fecha_desde','#fecha_hasta','#fecha_inicio','#fecha_fin','#hora_desde','#hora_hasta',
      'input[name="desde"]','input[name="hasta"]','input[name="fecha_desde"]','input[name="fecha_hasta"]',
      'input[name="hora_desde"]','input[name="hora_hasta"]',
      'select[name="medio"]','select[name="medio_pago"]','#medio','#medio_pago',
      'select[name="estado"]','#estado',
      'input[name="venta_id"]','#venta_id',
      'input[name="cliente_id"]','#cliente_id','#clienteIdHidden'
    ];
    const els = filterSelectors.map(s => $(s)).filter(Boolean);
    for (const el of els){
      el.addEventListener('change', () => fetchKPIs(container), { passive:true });
    }

    // Botón filtrar (si existe)
    const btn = $('#btnFiltrar') || $('#ventasForm button[type="submit"]') || $('button[type="submit"]');
    if (btn) btn.addEventListener('click', () => setTimeout(() => fetchKPIs(container), 60), { passive:true });
  }

  function init(){
    const container = ensureContainer();
    decorateCards(container);
    bindClicks(container);
    fetchKPIs(container);
    bindAutoRefresh(container);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

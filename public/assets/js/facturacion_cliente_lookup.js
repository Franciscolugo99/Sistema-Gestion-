(() => {
  const endpoint = 'api/cliente_consultar_cuit.php';

  function limpiarCuit(valor) {
    return String(valor || '').replace(/\D+/g, '').slice(0, 11);
  }

  function formatearCuit(valor) {
    const limpio = limpiarCuit(valor);
    if (limpio.length <= 2) return limpio;
    if (limpio.length <= 10) return `${limpio.slice(0, 2)}-${limpio.slice(2)}`;
    return `${limpio.slice(0, 2)}-${limpio.slice(2, 10)}-${limpio.slice(10)}`;
  }

  function setValue(el, value) {
    if (el) el.value = value || '';
  }

  function toggleResult(result, visible) {
    if (!result) return;
    result.classList.toggle('is-visible', visible);
  }

  document.querySelectorAll('[data-facturacion-cliente-form]').forEach((form) => {
    const lookup = form.querySelector('[data-facturacion-cliente-lookup]');
    if (!lookup) return;

    const select = form.querySelector('[data-lookup-select]');
    const cuitInput = lookup.querySelector('[data-lookup-cuit]');
    const button = lookup.querySelector('[data-lookup-btn]');
    const activeInput = lookup.querySelector('[data-lookup-activo]');
    const tipoClienteInput = lookup.querySelector('[data-lookup-tipo-cliente]');
    const result = lookup.querySelector('[data-lookup-result]');
    const status = lookup.querySelector('[data-lookup-status]');
    const nombreInput = lookup.querySelector('[data-lookup-nombre]');
    const condIvaInput = lookup.querySelector('[data-lookup-cond-iva]');
    const estadoInput = lookup.querySelector('[data-lookup-estado]');
    const direccionInput = lookup.querySelector('[data-lookup-direccion]');

    if (!cuitInput || !button || !activeInput) return;

    let inFlight = false;
    let ultimoConsultado = limpiarCuit(cuitInput.value);

    function setStatus(message, type) {
      if (!status) return;
      status.textContent = message;
      status.dataset.state = type || 'info';
    }

    function clearLookup(keepCuit) {
      activeInput.value = '0';
      if (!keepCuit) {
        cuitInput.value = '';
      }
      setValue(nombreInput, '');
      setValue(condIvaInput, '');
      setValue(estadoInput, '');
      setValue(direccionInput, '');
      setValue(tipoClienteInput, 'MINORISTA');
      toggleResult(result, false);
      setStatus('');
      ultimoConsultado = keepCuit ? limpiarCuit(cuitInput.value) : '';
    }

    function applyData(datos) {
      const nombre = (datos.nombre || '').trim();
      const cuit = formatearCuit(datos.cuit || cuitInput.value || '');
      cuitInput.value = cuit;
      setValue(nombreInput, nombre);
      setValue(condIvaInput, (datos.cond_iva || '').trim());
      setValue(estadoInput, (datos.estado || '').trim());
      setValue(direccionInput, (datos.direccion || '').trim());
      setValue(tipoClienteInput, (datos.tipo_cliente || 'MINORISTA').trim());
      activeInput.value = '1';
      ultimoConsultado = limpiarCuit(cuit);
      toggleResult(result, true);
      setStatus('Se usaran estos datos al emitir la factura.', 'success');
      if (select && !select.value) {
        select.value = '0';
      }
    }

    async function consultar() {
      const cuit = limpiarCuit(cuitInput.value);
      if (cuit.length !== 11 || inFlight) {
        return;
      }

      inFlight = true;
      button.disabled = true;
      setStatus('Consultando ARCA...', 'loading');
      toggleResult(result, true);

      try {
        const response = await fetch(`${endpoint}?cuit=${encodeURIComponent(cuit)}`, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin',
        });
        const data = await response.json();

        if (!data || data.success !== true) {
          activeInput.value = '0';
          setStatus(data?.error || 'No se encontraron datos para ese CUIT/CUIL.', 'error');
          if (window.Notif?.error) {
            window.Notif.error(data?.error || 'No se encontraron datos para ese CUIT/CUIL.');
          }
          return;
        }

        applyData(data.datos || {});
        if (window.Notif?.exito) {
          window.Notif.exito(`Datos cargados: ${(data.datos?.nombre || 'Cliente').trim()}`);
        }
      } catch (error) {
        activeInput.value = '0';
        setStatus('No se pudo consultar ARCA en este momento.', 'error');
        if (window.Notif?.error) {
          window.Notif.error('No se pudo consultar ARCA en este momento.');
        }
      } finally {
        inFlight = false;
        button.disabled = false;
      }
    }

    cuitInput.addEventListener('input', () => {
      const formatted = formatearCuit(cuitInput.value);
      if (formatted !== cuitInput.value) {
        cuitInput.value = formatted;
      }

      const actual = limpiarCuit(cuitInput.value);
      if (actual !== ultimoConsultado) {
        activeInput.value = '0';
        setValue(nombreInput, '');
        setValue(condIvaInput, '');
        setValue(estadoInput, '');
        setValue(direccionInput, '');
        toggleResult(result, false);
        setStatus('');
      }
    });

    cuitInput.addEventListener('blur', () => {
      const cuit = limpiarCuit(cuitInput.value);
      if (cuit.length === 11 && cuit !== ultimoConsultado) {
        consultar();
      }
    });

    button.addEventListener('click', consultar);

    if (select) {
      select.addEventListener('change', () => {
        if (select.value !== '' && activeInput.value === '1') {
          setStatus('Se usaran los datos consultados por CUIT/CUIL al emitir.', 'info');
          toggleResult(result, true);
        }
      });
    }

    if (activeInput.value === '1' && (nombreInput?.value || '').trim() !== '') {
      toggleResult(result, true);
      setStatus('Se usaran estos datos al emitir la factura.', 'success');
    }
  });
})();

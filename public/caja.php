<?php
// public/caja.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/caja_lib.php';

require_pos();
require_permission('realizar_ventas');





// Terminal actual (UX)
$terminalId   = (int)($_SESSION['terminal_id'] ?? 0);
$terminal     = $terminalId > 0 ? terminal_get($pdo, $terminalId) : null;
$terminalName = $terminal ? (string)($terminal['nombre'] ?? ('Caja #' . $terminalId)) : 'Sin terminal';

$aperturaError = null;

/* --------------------------------------------------------
   APERTURA DE CAJA (POST)
-------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion_caja'] ?? '') === 'abrir') {

  if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    $aperturaError = 'Token inválido. Recargá la página e intentá de nuevo.';
  } else {
    $saldoIni = parse_money_ar($_POST['saldo_inicial'] ?? '0');

    if ($saldoIni < 0) {
      $aperturaError = 'El saldo inicial no puede ser negativo.';
    } else {
      $terminalId = (int)($_SESSION['terminal_id'] ?? current_terminal_id());
      $tmp = caja_get_abierta($pdo, $terminalId);

      if (!$tmp || !is_array($tmp) || empty($tmp['id'])) {
        caja_abrir($pdo, $terminalId, (int)($user['id'] ?? 0), $saldoIni);
      }

      header('Location: caja.php');
      exit;
    }
  }
}

/* --------------------------------------------------------
   HEADER GLOBAL
-------------------------------------------------------- */
$pageTitle      = 'Caja';
$currentSection = 'caja';

$extraCss = [
  'assets/css/caja.css',
];

$extraJs = [
  'assets/js/caja.js',
  'assets/js/caja_terminal_modal.js',
  'assets/js/caja_cc_pago.js',
];

// Permisos para frontend (JS espera true/false)
$canModPrecio = (function_exists('user_has_permission') && user_has_permission('caja_modificar_precio'))
  ? 'true'
  : 'false';

$canCC = (function_exists('user_has_permission') && user_has_permission('registrar_cargo_cc'))
  ? 'true'
  : 'false';
$csrf = csrf_token(); // ✅ usa tu helper central

// 🔴 IMPORTANTE: el modal/API suele leer CSRF desde <meta>
// + inyectamos permisos en window.FLUS_PERMS
  $extraHead =
    '<meta name="csrf-token" content="' . h($csrf) . '">' .
    '<script>' .
      'window.getCsrfToken = function(){ return ' . json_encode($csrf) . '; };' .
      'window.FLUS_PERMS = window.FLUS_PERMS || {};' .
      'window.FLUS_PERMS.caja_modificar_precio = ' . $canModPrecio . ';' .
      'window.FLUS_PERMS.registrar_cargo_cc = ' . $canCC . ';' .
    '</script>';


require __DIR__ . '/partials/header.php';

/* --------------------------------------------------------
   RE-CONSULTA DE CAJA ABIERTA
-------------------------------------------------------- */
$terminalId   = (int)($_SESSION['terminal_id'] ?? current_terminal_id());
$cajaSesion   = caja_get_abierta($pdo, $terminalId);
$terminal     = $terminalId > 0 ? terminal_get($pdo, $terminalId) : null;
$terminalName = $terminal ? (string)($terminal['nombre'] ?? ('Caja #' . $terminalId)) : 'Sin terminal';

if (!$cajaSesion || !is_array($cajaSesion) || empty($cajaSesion['id'])) {
  $cajaSesion = null;
}

/* --------------------------------------------------------
   MOVIMIENTOS DE CAJA (efectivo)
-------------------------------------------------------- */
$movCaja = [];
$movIngresos = 0.0;
$movEgresos  = 0.0;

$flashOk  = trim((string)($_GET['ok']  ?? ''));
$flashErr = trim((string)($_GET['err'] ?? ''));

if ($cajaSesion) {
  $cajaIdTmp = (int)$cajaSesion['id'];

  // últimos movimientos
  $stM = $pdo->prepare("
    SELECT id, tipo, concepto, monto, fecha, usuario_registro
    FROM caja_movimientos
    WHERE caja_id = ?
    ORDER BY fecha DESC, id DESC
    LIMIT 15
  ");
  $stM->execute([$cajaIdTmp]);
  $movCaja = $stM->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // totales (case-insensitive)
  $stS = $pdo->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN UPPER(tipo)='INGRESO' THEN monto ELSE 0 END),0) AS ingresos,
      COALESCE(SUM(CASE WHEN UPPER(tipo)='EGRESO'  THEN monto ELSE 0 END),0) AS egresos
    FROM caja_movimientos
    WHERE caja_id = ?
  ");
  $stS->execute([$cajaIdTmp]);
  $rowS = $stS->fetch(PDO::FETCH_ASSOC) ?: [];
  $movIngresos = (float)($rowS['ingresos'] ?? 0);
  $movEgresos  = (float)($rowS['egresos']  ?? 0);
}
?>

<div class="panel caja-panel">

  <?php if ($flashOk !== ''): ?>
    <div class="alert alert-success" style="margin-bottom:12px;"><?= h($flashOk) ?></div>
  <?php endif; ?>
  <?php if ($flashErr !== ''): ?>
    <div class="alert alert-error" style="margin-bottom:12px;"><?= h($flashErr) ?></div>
  <?php endif; ?>

  <?php if ($cajaSesion === null): ?>

    <h1 class="caja-title">CAJA</h1>

    <div class="pos-terminal-bar">
      <div class="pos-terminal-left">
        <span class="pos-terminal-label">Terminal:</span>
        <b class="pos-terminal-name"><?= h($terminalName) ?></b>
      </div>

      <!--  terminal SIEMPRE permitido -->
      <button type="button" class="btn-line btn-line--sm" id="btnCambiarTerminal">
        Cambiar
      </button>
    </div>


    <div class="apertura-wrapper">
      <p class="apertura-text">
        No hay ninguna caja abierta. Ingresá el saldo inicial para comenzar.
      </p>

      <div class="apertura-card">
        <form method="post" class="form-apertura" id="formAperturaCaja">
          <input type="hidden" name="accion_caja" value="abrir">
          <?= csrf_field() ?>

          <label class="form-label" for="saldo_inicial">Saldo inicial en caja</label>
          <input
            type="number"
            step="0.01"
            min="0"
            id="saldo_inicial"
            name="saldo_inicial"
            value="0.00"
            class="apertura-input"
            required
          >

          <div id="aperturaAviso" class="alert alert-warn hidden"></div>

          <?php if ($aperturaError): ?>
            <div class="alert alert-error"><?= h($aperturaError) ?></div>
          <?php endif; ?>

          <button type="submit" class="btn btn-primary apertura-btn">
            Abrir caja
          </button>
        </form>
      </div>
    </div>

  <?php else: ?>

<div class="caja-topbar">
  <div class="caja-topbar__left">
    <div class="caja-topbar__badge">Caja abierta</div>

    <div class="caja-topbar__meta">
      <div class="caja-topbar__item">
        <div class="caja-topbar__label">Terminal</div>
        <div class="caja-topbar__value">
          <span class="caja-topbar__strong"><?= h($terminalName) ?></span>
          <button type="button" class="btn-line btn-line--sm" id="btnCambiarTerminal">Cambiar</button>
        </div>
      </div>

      <div class="caja-topbar__item">
        <div class="caja-topbar__label">Apertura</div>
        <div class="caja-topbar__value mono">
          #<?= (int)$cajaSesion['id'] ?> ·
          <?= h($cajaSesion['username'] ?? '') ?> ·
          <?= h(format_datetime_ar($cajaSesion['fecha_apertura'] ?? null)) ?>
        </div>
      </div>
    </div>
  </div>

  <div class="caja-topbar__actions">
    <a class="btn btn-secondary btn-sm" href="caja_movimientos.php">Movimientos</a>

    <?php if (function_exists('user_has_permission') && user_has_permission('registrar_pago_cc')): ?>
    <button
      type="button"
      id="btnCobroCC"
      class="btn btn-info btn-sm"
      title="Cobrar deuda de Cuenta Corriente">
      Cobrar CC
    </button>
    <?php endif; ?>

    <button
      type="button"
      id="btnCerrarCaja"
      class="btn btn-danger btn-sm"
      data-caja-id="<?= (int)$cajaSesion['id'] ?>">
      Cerrar caja
    </button>
  </div>
</div>


    <h1 class="caja-title">CAJA</h1>

    <!-- =========================
         MOVIMIENTOS COLAPSABLES (nativo, sin look “pegote”)
    ========================== -->
    <details class="caja-mov" id="cajaMov" open>
      <summary class="caja-mov__sum">
        <span class="caja-mov__toggle" aria-hidden="true"></span>

        <div class="caja-mov__left">
          <div class="caja-mov__title">Movimientos de caja</div>
          <div class="caja-mov__sub">Ingresos y egresos de efectivo en esta apertura</div>
        </div>

        <div class="caja-mov__right">
          <span class="pill pill-success">+ <?= money_ar($movIngresos) ?></span>
          <span class="pill pill-danger">− <?= money_ar($movEgresos) ?></span>

          <a class="btn btn-primary btn-sm"
             href="caja_movimientos.php"
             onclick="event.preventDefault(); event.stopPropagation(); window.location='caja_movimientos.php';">
            Registrar
          </a>
        </div>
      </summary>

      <div class="caja-mov__body">
        <?php if (!$movCaja): ?>
          <div class="muted">Todavía no hay movimientos en esta apertura.</div>
        <?php else: ?>
          <div class="table-wrapper">
            <table class="table">
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Tipo</th>
                  <th>Concepto</th>
                  <th class="t-right">Monto</th>
                  <th>Usuario</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($movCaja as $m): ?>
                  <?php
                    $t = strtoupper((string)($m['tipo'] ?? 'INGRESO'));
                    $pill = ($t === 'EGRESO') ? 'pill-danger' : 'pill-success';
                    $lbl  = ($t === 'EGRESO') ? '− Egreso' : '+ Ingreso';
                  ?>
                  <tr>
                    <td class="mono"><?= h(format_datetime_ar($m['fecha'] ?? null)) ?></td>
                    <td><span class="pill <?= $pill ?>"><?= h($lbl) ?></span></td>
                    <td><?= h((string)($m['concepto'] ?? '—')) ?></td>
                    <td class="t-right"><?= money_ar($m['monto'] ?? 0) ?></td>
                    <td><?= h((string)($m['usuario_registro'] ?? '—')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </details>

    <!-- =========================
         SCAN / INPUTS (mismo layout FLUS, pero más “POS”)
    ========================== -->
    <div class="caja-scan-card">
      <div class="row caja-row-inputs">
        <div class="field">
          <label for="codigo">Escanear código</label>
          <input type="text" id="codigo" autocomplete="off" autofocus placeholder="Escaneá o escribí el código…">
        </div>

        <div class="field field-narrow">
          <label for="cantidad">Cant.</label>
          <input type="text" id="cantidad" value="1" autocomplete="off">
        </div>

        <div class="field field-narrow field-add-btn">
          <button class="btn btn-add" id="btnAgregar" type="button">
            Agregar al ticket
          </button>
        </div>
      </div>
    </div>

    <!-- Tabla del ticket -->
    <div class="ticket-wrapper">
      <table id="tabla">
        <thead>
          <tr>
            <th>#</th>
            <th>Código</th>
            <th>Producto</th>
            <th class="center col-cant">Cant.</th>
            <th class="right">Precio</th>
            <th class="right">Subtotal</th>
            <th class="center">Acciones</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>

    <!-- Totales -->
    <div class="total-panel">
      <div class="total-row">
        <span class="total-label">Total bruto</span>
        <span class="total-value" id="lblTotalBruto">$0,00</span>
      </div>

      <div class="total-row">
        <span class="total-label">
          Descuento total
          <button type="button" id="btnDescGlobal" class="btn-link-total">
            Cambiar
          </button>
        </span>
        <span class="total-value" id="lblDescGlobal">$0,00</span>
      </div>

      <div class="total-row total-row-strong">
        <span class="total-label">Total a cobrar</span>
        <span class="total-value" id="lblTotal">$0,00</span>
      </div>
    </div>

    <!-- Pagos (1 o 2 medios) -->
    <div class="total-row total-row-bottom pagos-row">
      <div class="field-small">
        <div class="total-label-inline">Pago 1</div>
        <select id="medioPago">
          <option value="EFECTIVO">Efectivo</option>
          <option value="MP">Mercado Pago</option>
          <option value="DEBITO">Débito</option>
          <option value="CREDITO">Crédito</option>
          <?php if (function_exists('user_has_permission') && user_has_permission('registrar_cargo_cc')): ?>
            <option value="CC">Cuenta Corriente</option>
          <?php endif; ?>
        </select>

        <div class="total-label-inline">Monto</div>
        <input type="number" id="montoPagado" min="0" step="0.01" placeholder="0,00">
      </div>

      <div class="field-small is-hidden" id="pago2Wrap">
        <div class="total-label-inline">Pago 2</div>
        <select id="medioPago2">
          <option value="EFECTIVO">Efectivo</option>
          <option value="MP">Mercado Pago</option>
          <option value="DEBITO">Débito</option>
          <option value="CREDITO">Crédito</option>
          <?php if (function_exists('user_has_permission') && user_has_permission('registrar_cargo_cc')): ?>
            <option value="CC">Cuenta Corriente</option>
          <?php endif; ?>
        </select>

        <div class="total-label-inline">Monto</div>
        <div class="pago2-monto-row">
          <input type="number" id="montoPagado2" min="0" step="0.01" placeholder="0,00">
          <button type="button" id="btnQuitarPago2" class="btn-mini btn-mini-danger" title="Quitar 2º pago">✕</button>
        </div>
      </div>

      <div class="field-small pagos-resumen">
        <button type="button" id="btnAgregarPago" class="btn-mini" title="Agregar 2º medio de pago">+ Otro medio</button>
        <div class="total-label-inline">Total pagado</div>
            <div class="total-vuelto" id="lblTotalPagado">$0,00</div>
            <div id="restaWrap" class="is-hidden">
              <div class="total-label-inline">Resta pagar</div>
              <div class="total-vuelto" id="lblRestaPagar">$0,00</div>
            </div>
            <div class="total-label-inline">Vuelto</div>
            <div class="total-vuelto" id="lblVuelto">$0,00</div>
            </div>
    
    <!-- Cuenta Corriente (solo si se elige CC en algún pago) -->
    <div id="ccWrap" class="cc-wrap is-hidden">
      <div class="total-label-inline">Cliente (Cuenta Corriente)</div>
      <div class="cc-row">
        <input type="text" id="ccClienteBuscar" placeholder="Buscar cliente (nombre / teléfono / CUIT)" autocomplete="off">
        <input type="hidden" id="ccClienteId" value="">
      </div>
      <div id="ccClienteInfo" class="cc-info"></div>
    </div>
</div>


    <!-- Botones principales -->
    <div class="buttons-row">
      <button id="btnCancelar" type="button" class="btn-cancelar">
        Cancelar venta
      </button>
      <button id="btnCobrar" type="button" class="btn-cobrar">
        Cobrar
      </button>
    </div>

    <!-- Atajos -->
    <div class="shortcuts-box">
      <div class="shortcuts-card">
        <div class="shortcuts-title">Atajos de teclado</div>
        <div class="shortcuts-list">
          <span><kbd>F2</kbd> Cobrar</span>
          <span><kbd>F4</kbd> Cancelar venta</span>
          <span><kbd>F5</kbd> Foco en código</span>
          <span><kbd>Esc</kbd> Cerrar ventana / modal</span>
        </div>
      </div>
    </div>

    <div id="msg" class="msg"></div>

    <!-- Modal custom caja -->
    <div id="modal" class="modal hidden">
      <div class="modal-content">
        <h3 id="modal-titulo">Título</h3>
        <p id="modal-texto"></p>

        <div id="modal-input-container" class="input-area">
          <label id="modal-label" for="modal-input">Cantidad</label>

          <select id="modal-desc-tipo" class="modal-desc-tipo hidden">
            <option value="precio">Nuevo precio unitario</option>
            <option value="porcentaje">% de descuento</option>
            <option value="monto">Descuento en $</option>
          </select>

          <input id="modal-input" type="number" min="1" step="1">
        </div>

        <div class="modal-buttons">
          <button id="modal-cancel" class="btn-cancel">Cancelar</button>
          <button id="modal-confirm" class="btn-confirm">Aceptar</button>
        </div>
      </div>
    </div>

  <?php endif; ?>

</div><!-- /.panel -->

<?php
$autoShowTerminalModal = 0;
if ((int)($_SESSION['terminal_id'] ?? 0) <= 0) $autoShowTerminalModal = 1;
?>

<!-- Modal: Cambiar Terminal -->
<div id="terminalModal" class="terminal-modal" aria-hidden="true">
  <div class="terminal-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="terminalModalTitle">
    <div class="terminal-modal__head">
      <div>
        <h2 id="terminalModalTitle" class="terminal-modal__title">Elegir caja / terminal</h2>
        <p class="terminal-modal__sub">La selección queda guardada en esta PC.</p>
      </div>

      <button type="button" class="terminal-modal__close" data-close>Cerrar</button>
    </div>

    <form id="terminalModalForm">
      <div id="terminalModalList" class="terminal-modal__list"></div>

      <div id="terminalModalError" class="terminal-modal__error is-hidden" role="alert"></div>

      <div class="terminal-modal__actions">
        <button type="button" class="btn terminal-modal__btn-cancel" data-close>Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar y continuar</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     MODAL: COBRAR CUENTA CORRIENTE
     ═══════════════════════════════════════════════════════════════════════════ -->
<?php if (function_exists('user_has_permission') && user_has_permission('registrar_pago_cc')): ?>
<div id="modalCcPago" class="modal hidden" aria-hidden="true" role="dialog" aria-labelledby="modalCcPagoTitle">
  <div class="modal-content modal-content--md">
    <h3 id="modalCcPagoTitle" class="modal-title">Cobrar Cuenta Corriente</h3>
    
    <div class="field">
      <label for="ccPagoBuscar">Cliente</label>
      <div style="position:relative;">
        <input type="text" id="ccPagoBuscar" placeholder="Buscar cliente (nombre / teléfono / CUIT)" autocomplete="off">
        <input type="hidden" id="ccPagoClienteId" value="">
      </div>
      <div id="ccPagoInfo" class="cc-info" style="margin-top:4px;font-size:0.9em;color:#666;"></div>
    </div>
    
    <div class="field">
      <label for="ccPagoMonto">Monto a cobrar</label>
      <input type="number" id="ccPagoMonto" min="0.01" step="0.01" placeholder="0,00">
    </div>
    
    <div class="field">
      <label for="ccPagoMedio">Medio de pago</label>
      <select id="ccPagoMedio">
        <option value="EFECTIVO">Efectivo</option>
        <option value="TRANSFERENCIA">Transferencia</option>
        <option value="MP">Mercado Pago</option>
        <option value="DEBITO">Débito</option>
        <option value="CREDITO">Crédito</option>
      </select>
    </div>
    
    <div class="field">
      <label for="ccPagoRef">Referencia (opcional)</label>
      <input type="text" id="ccPagoRef" placeholder="Nro. de transferencia, etc.">
    </div>
    
    <div class="modal-buttons">
      <button type="button" id="ccPagoCancel" class="btn-cancel">Cancelar</button>
      <button type="button" id="ccPagoConfirm" class="btn-confirm">Registrar pago</button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php
// public/caja.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/caja_lib.php';
require_once FLUS_ROOT . '/src/recargo_horario.php';
require_once FLUS_ROOT . '/src/mercadopago_qr_lib.php';

$canRealizarVentas = function_exists('user_has_permission') && user_has_permission('realizar_ventas');
$canAbrirCaja = (function_exists('user_has_permission') && user_has_permission('abrir_caja')) || $canRealizarVentas;
require_any_permission(['abrir_caja', 'realizar_ventas']);
require_pos();

$recargoHorarioEstado = flus_recargo_horario_estado($pdo);
$recargoHorarioRedondeoLabel = flus_recargo_horario_redondeo_label((string)($recargoHorarioEstado['redondeo'] ?? 'NINGUNO'));
$recargoHorarioRedondeoSub = ((string)($recargoHorarioEstado['redondeo'] ?? 'NINGUNO') !== 'NINGUNO')
  ? ' - ' . $recargoHorarioRedondeoLabel
  : '';

// Terminal actual (UX)
$terminalId   = (int)($_SESSION['terminal_id'] ?? 0);
$terminal     = $terminalId > 0 ? terminal_get($pdo, $terminalId) : null;
$terminalName = $terminal ? (string)($terminal['nombre'] ?? ('Caja #' . $terminalId)) : 'Sin terminal';

$aperturaError = null;

/* --------------------------------------------------------
   APERTURA DE CAJA (POST)
-------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion_caja'] ?? '') === 'abrir') {
  if (!$canAbrirCaja) {
    $aperturaError = 'No tenes permiso para abrir caja.';
  } elseif (!csrf_verify($_POST['csrf_token'] ?? null)) {
    $aperturaError = 'Token invalido. Recarga la pagina e intenta de nuevo.';
  } else {
    $saldoIni = parse_money_ar($_POST['saldo_inicial'] ?? '0');

    if ($saldoIni < 0) {
      $aperturaError = 'El saldo inicial no puede ser negativo.';
    } else {
      $terminalId = (int)($_SESSION['terminal_id'] ?? current_terminal_id());
      $tmp = caja_get_abierta($pdo, $terminalId);

      if ($tmp && is_array($tmp) && !empty($tmp['id'])) {
        $aperturaError = 'Ya hay un turno abierto en esta terminal por ' . caja_turno_owner_label($tmp) . '.';
      } else {
        caja_abrir($pdo, $terminalId, (int)($user['id'] ?? 0), $saldoIni);
        header('Location: caja.php');
        exit;
      }
    }
  }
}

/* --------------------------------------------------------
   HEADER GLOBAL
-------------------------------------------------------- */
$pageTitle      = 'Caja';
$currentSection = 'caja';
$bodyClass      = trim(($bodyClass ?? '') . ' caja-fullscreen-page');

$extraCss = [
  'assets/css/caja.base.css?v=' . filemtime(__DIR__ . '/assets/css/caja.base.css'),
  'assets/css/caja.pos.css?v=' . filemtime(__DIR__ . '/assets/css/caja.pos.css'),
  'assets/css/caja.neo.css?v=' . filemtime(__DIR__ . '/assets/css/caja.neo.css'),
];

$extraJs = [
  'assets/js/caja_mp_qr.js?v=' . filemtime(__DIR__ . '/assets/js/caja_mp_qr.js'),
  'assets/js/caja.js?v=' . filemtime(__DIR__ . '/assets/js/caja.js'),
  'assets/js/caja_ventas_recientes.js?v=' . filemtime(__DIR__ . '/assets/js/caja_ventas_recientes.js'),
  'assets/js/caja_terminal_modal.js?v=' . filemtime(__DIR__ . '/assets/js/caja_terminal_modal.js'),
  'assets/js/caja_cc_pago.js?v=' . filemtime(__DIR__ . '/assets/js/caja_cc_pago.js'),
];

// Permisos para frontend (JS espera true/false)
$canModPrecio = (function_exists('user_has_permission') && user_has_permission('caja_modificar_precio'))
  ? 'true'
  : 'false';

$canCC = (function_exists('user_has_permission') && user_has_permission('registrar_cargo_cc'))
  ? 'true'
  : 'false';
$canAnularVenta = (function_exists('user_has_permission') && user_has_permission('anular_venta'))
  ? 'true'
  : 'false';
$canAnularItems = (function_exists('user_has_permission') && user_has_permission('anular_items_venta'))
  ? 'true'
  : 'false';
$mpQrEnabled = function_exists('flus_mp_qr_cashier_enabled') && flus_mp_qr_cashier_enabled()
  ? 'true'
  : 'false';
$mpPointEnabled = function_exists('flus_mp_point_cashier_enabled') && flus_mp_point_cashier_enabled()
  ? 'true'
  : 'false';
$mpManualFallback = function_exists('flus_mp_manual_fallback_enabled') && flus_mp_manual_fallback_enabled()
  ? 'true'
  : 'false';
$mpCashierMode = function_exists('flus_mp_cashier_mode') ? flus_mp_cashier_mode() : 'automatic';
$globalPrintDefaults = [
  'ticket_mode' => (string)config_get($pdo, 'print_ticket_mode', 'autoprint'),
  'ticket_paper' => (string)config_get($pdo, 'print_ticket_paper', '80'),
  'comanda_mode' => (string)config_get($pdo, 'print_comanda_mode', 'none'),
  'comanda_paper' => (string)config_get($pdo, 'print_comanda_paper', '80'),
  'factura_mode' => (string)config_get($pdo, 'print_factura_mode', 'preview'),
];
$terminalPrintDefaults = [
  'ticket_mode' => $terminalId > 0 ? (string)config_get($pdo, 'terminal_' . $terminalId . '_ticket_print_mode', 'inherit') : 'inherit',
  'ticket_paper' => $terminalId > 0 ? (string)config_get($pdo, 'terminal_' . $terminalId . '_ticket_paper', 'inherit') : 'inherit',
];
$csrf = csrf_token(); // usa el helper central

// Importante: el modal/API suele leer CSRF desde <meta>
// e inyectamos permisos en window.FLUS_PERMS
$extraHead =
    '<meta name="csrf-token" content="' . h($csrf) . '">' .
    '<script>' .
      'window.getCsrfToken = function(){ return ' . json_encode($csrf) . '; };' .
      'window.FLUS_PERMS = window.FLUS_PERMS || {};' .
      'window.FLUS_PERMS.caja_modificar_precio = ' . $canModPrecio . ';' .
      'window.FLUS_PERMS.registrar_cargo_cc = ' . $canCC . ';' .
      'window.FLUS_PERMS.anular_venta = ' . $canAnularVenta . ';' .
      'window.FLUS_PERMS.anular_items_venta = ' . $canAnularItems . ';' .
      'window.FLUS_MP_QR_ENABLED = ' . $mpQrEnabled . ';' .
      'window.FLUS_MP_POINT_ENABLED = ' . $mpPointEnabled . ';' .
      'window.FLUS_MP_MANUAL_FALLBACK = ' . $mpManualFallback . ';' .
      'window.FLUS_MP_CASHIER_MODE = ' . json_encode($mpCashierMode) . ';' .
      'window.FLUS_PRINT_DEFAULTS = ' . json_encode([
        'global' => $globalPrintDefaults,
        'terminal' => $terminalPrintDefaults,
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';' .
    '</script>';

function caja_render_terminal_modal(): void {
  static $rendered = false;
  if ($rendered) return;
  $rendered = true;
  ?>
  <!-- Modal: Cambiar Terminal -->
  <div id="terminalModal" class="terminal-modal" aria-hidden="true">
    <div class="terminal-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="terminalModalTitle">
      <div class="terminal-modal__head">
        <div>
          <h2 id="terminalModalTitle" class="terminal-modal__title">Elegir caja / terminal</h2>
          <p class="terminal-modal__sub">La seleccion queda guardada en esta PC.</p>
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
  <?php
}


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

$currentUserId = (int)($user['id'] ?? 0);
$canCerrarCaja = function_exists('user_has_permission') && user_has_permission('cerrar_caja');
$cajaSesionBloqueada = $cajaSesion !== null && !caja_user_can_operar_turno($cajaSesion, $currentUserId);
$canCerrarTurnoActual = $cajaSesion !== null && $canCerrarCaja && caja_user_can_cerrar_turno($cajaSesion, $currentUserId);

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

  // Ultimos movimientos
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

$ventasActivasCount = 0;
$sessionTotalVentas = (float)($cajaSesion['total_ventas'] ?? 0);
$sessionTotalEfectivo = (float)($cajaSesion['total_efectivo'] ?? 0);
$sessionTotalMp = (float)($cajaSesion['total_mp'] ?? 0);

if ($cajaSesion) {
  $stVentasCount = $pdo->prepare("
    SELECT COUNT(*)
    FROM ventas
    WHERE caja_id = ?
      AND (estado IS NULL OR UPPER(estado) NOT LIKE '%ANUL%')
  ");
  $stVentasCount->execute([(int)$cajaSesion['id']]);
  $ventasActivasCount = (int)$stVentasCount->fetchColumn();
}

if ($cajaSesionBloqueada) {
  $ownerLabel = caja_turno_owner_label($cajaSesion);
  ?>
  <div class="panel caja-panel">
    <section class="caja-open-shell" aria-labelledby="cajaBlockedTitle">
      <div class="caja-open-card">
        <div class="caja-open-header">
          <div class="caja-open-copy">
            <span class="caja-open-eyebrow">Turno protegido</span>
            <h1 class="caja-title caja-title--open" id="cajaBlockedTitle">CAJA</h1>
            <p class="caja-open-lead">Esta terminal tiene un turno abierto por <?= h($ownerLabel) ?>.</p>
            <p class="caja-open-sub">
              Para mantener el control por cajero, las ventas y movimientos quedan bloqueados para otros usuarios.
              Cerra el turno actual o cambia de terminal para operar.
            </p>
          </div>

          <div class="caja-open-terminal">
            <span class="caja-open-terminal-label">Terminal activa</span>
            <div class="caja-open-terminal-card">
              <div class="caja-open-terminal-main">
                <strong class="caja-open-terminal-name"><?= h($terminalName) ?></strong>
                <span class="caja-open-terminal-hint">Apertura #<?= (int)$cajaSesion['id'] ?> - <?= h(format_datetime_ar($cajaSesion['fecha_apertura'] ?? null)) ?></span>
              </div>
              <button type="button" class="btn-line btn-line--sm" id="btnCambiarTerminal" data-terminal-modal-open>
                Cambiar
              </button>
            </div>
          </div>
        </div>

        <div class="apertura-wrapper">
          <div class="apertura-card">
            <p class="apertura-help">
              <?php if ($canCerrarTurnoActual): ?>
                Tu usuario puede supervisar este turno. Cerra la caja o cambia de terminal para continuar.
              <?php else: ?>
                Solo <?= h($ownerLabel) ?> o un usuario supervisor puede cerrar este turno.
              <?php endif; ?>
            </p>
            <div class="apertura-actions">
              <?php if ($canCerrarTurnoActual): ?>
                <a class="btn btn-primary apertura-action-btn" href="caja_cerrar.php?id=<?= (int)$cajaSesion['id'] ?>">Cerrar caja</a>
              <?php endif; ?>
              <button type="button" class="btn btn-secondary apertura-action-btn" data-terminal-modal-open>Cambiar terminal</button>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
  <?php
  caja_render_terminal_modal();
  require __DIR__ . '/partials/footer.php';
  return;
}

if ($cajaSesion !== null && !$canRealizarVentas) {
  ?>
  <div class="panel caja-panel">
    <section class="caja-open-shell" aria-labelledby="cajaRestrictedTitle">
      <div class="caja-open-card">
        <div class="caja-open-header">
          <div class="caja-open-copy">
            <span class="caja-open-eyebrow">Caja abierta</span>
            <h1 class="caja-title caja-title--open" id="cajaRestrictedTitle">CAJA</h1>
            <p class="caja-open-lead">La caja ya fue abierta para esta terminal.</p>
            <p class="caja-open-sub">
              Este usuario puede abrir caja, pero no tiene permiso para vender. La venta sigue separada en <strong>realizar_ventas</strong>.
            </p>
          </div>

          <div class="caja-open-terminal">
            <span class="caja-open-terminal-label">Terminal activa</span>
            <div class="caja-open-terminal-card">
              <div class="caja-open-terminal-main">
                <strong class="caja-open-terminal-name"><?= h($terminalName) ?></strong>
                <span class="caja-open-terminal-hint">Apertura #<?= (int)$cajaSesion['id'] ?> - <?= h(format_datetime_ar($cajaSesion['fecha_apertura'] ?? null)) ?></span>
              </div>
            </div>
          </div>
        </div>

        <div class="apertura-wrapper">
          <div class="apertura-card">
            <p class="apertura-help">
              Si este usuario tambien necesita cobrar ventas, dale el permiso <strong>realizar_ventas</strong>.
              <?php if (function_exists('user_has_permission') && user_has_permission('cerrar_caja')): ?>
                Si solo debe cerrar el turno, puede continuar con el boton de cierre.
              <?php endif; ?>
            </p>
            <div class="apertura-actions">
              <?php if (function_exists('user_has_permission') && user_has_permission('cerrar_caja')): ?>
                <a class="btn btn-primary apertura-action-btn" href="caja_cerrar.php?id=<?= (int)$cajaSesion['id'] ?>">Cerrar caja</a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </section>
  </div>
  <?php
  caja_render_terminal_modal();
  require __DIR__ . '/partials/footer.php';
  return;
}
?>

<div class="panel caja-panel<?= $cajaSesion !== null && $canRealizarVentas ? ' caja-panel--pos caja-panel--neo' : '' ?>">

  <?php if ($flashOk !== ''): ?>
    <div class="alert alert-success" style="margin-bottom:12px;"><?= h($flashOk) ?></div>
  <?php endif; ?>
  <?php if ($flashErr !== ''): ?>
    <div class="alert alert-error" style="margin-bottom:12px;"><?= h($flashErr) ?></div>
  <?php endif; ?>

  <?php if ($cajaSesion === null): ?>

    <section class="caja-open-shell" aria-labelledby="cajaOpenTitle">
      <div class="caja-open-card">
        <div class="caja-open-header">
          <div class="caja-open-copy">
            <span class="caja-open-eyebrow">Inicio operativo</span>
            <h1 class="caja-title caja-title--open" id="cajaOpenTitle">CAJA</h1>
            <p class="caja-open-lead">No hay ninguna caja abierta.</p>
            <p class="caja-open-sub">
              Defini el efectivo que recibe esta terminal antes de vender.
            </p>
          </div>

          <div class="caja-open-terminal">
            <span class="caja-open-terminal-label">Terminal activa</span>
            <div class="caja-open-terminal-card">
              <div class="caja-open-terminal-main">
                <strong class="caja-open-terminal-name"><?= h($terminalName) ?></strong>
                <span class="caja-open-terminal-hint">La apertura queda asociada a esta caja.</span>
              </div>
              <button type="button" class="btn-line btn-line--sm" id="btnCambiarTerminal" data-terminal-modal-open>
                Cambiar
              </button>
            </div>
          </div>
        </div>

    <div class="apertura-wrapper">
      <div class="apertura-card">
        <form method="post" class="form-apertura" id="formAperturaCaja">
          <input type="hidden" name="accion_caja" value="abrir">
          <?= csrf_field() ?>

          <div class="apertura-field">
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
          </div>

          <div class="alert alert-info apertura-fondo-info">
            Carga el efectivo real que recibe este cajero. Si queda cambio del turno anterior, contalo y declaralo aca como saldo inicial.
          </div>

          <div id="aperturaAviso" class="alert alert-warn hidden"></div>

          <?php if ($aperturaError): ?>
            <div class="alert alert-error"><?= h($aperturaError) ?></div>
          <?php endif; ?>

          <button type="submit" class="btn btn-primary apertura-btn">
            Abrir caja
          </button>

          <p class="apertura-help">
            Al abrir caja vas a poder operar ventas, cobros y movimientos desde esta terminal.
          </p>
        </form>
      </div>
    </div>
      </div>
    </section>

  <?php else: ?>

<div class="caja-topbar">
  <div class="caja-topbar__left">
    <div class="caja-topbar__badge">Caja abierta</div>
    <?php if (!empty($recargoHorarioEstado['active'])): ?>
      <div
        class="caja-topbar__mode24"
        title="Precio activo: <?= h((string)$recargoHorarioEstado['nombre']) ?> +<?= h(number_format((float)$recargoHorarioEstado['porcentaje'], 2, ',', '.')) ?>% hasta <?= h((string)$recargoHorarioEstado['fin']) ?><?= h($recargoHorarioRedondeoSub) ?>"
        aria-label="Precio automatico activo"
      >
        <span class="caja-topbar__mode24-led" aria-hidden="true"></span>
        <span class="caja-topbar__mode24-main">Precio</span>
        <span class="caja-topbar__mode24-sub">+<?= h(number_format((float)$recargoHorarioEstado['porcentaje'], 0, ',', '.')) ?>%<?= h($recargoHorarioRedondeoSub) ?></span>
      </div>
    <?php endif; ?>

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
          #<?= (int)$cajaSesion['id'] ?> &middot;
          <?= h(format_datetime_ar($cajaSesion['fecha_apertura'] ?? null)) ?>
        </div>
      </div>

      <div class="caja-topbar__item">
        <div class="caja-topbar__label">Cajero</div>
        <div class="caja-topbar__value">
          <span class="caja-topbar__strong"><?= h(caja_turno_owner_label($cajaSesion)) ?></span>
        </div>
      </div>
    </div>
  </div>

  <div class="caja-topbar__actions">
    <div class="caja-topbar__print">
      <label class="caja-topbar__print-label" for="ticketPrintMode">Ticket</label>
      <select id="ticketPrintMode" class="caja-topbar__print-select" aria-label="Modo de salida del ticket">
        <option value="autoprint">Auto imprimir</option>
        <option value="preview">Vista previa</option>
        <option value="none">No abrir</option>
      </select>
    </div>

    <a class="btn btn-secondary btn-sm" href="caja_movimientos.php">Movimientos</a>
    <button type="button" id="btnVentasRecientes" class="btn btn-secondary btn-sm">
      Ventas recientes
    </button>

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

    <div class="caja-kpis" aria-label="Resumen rapido del turno">
      <div class="caja-kpi">
        <span class="caja-kpi__label">Ventas</span>
        <strong class="caja-kpi__value" id="kpiVentasSesion" data-value="<?= (int)$ventasActivasCount ?>"><?= number_format($ventasActivasCount, 0, ',', '.') ?></strong>
      </div>
      <div class="caja-kpi caja-kpi--green">
        <span class="caja-kpi__label">Total</span>
        <strong class="caja-kpi__value" id="kpiTotalSesion" data-value="<?= h(number_format($sessionTotalVentas, 2, '.', '')) ?>"><?= money_ar($sessionTotalVentas) ?></strong>
      </div>
      <div class="caja-kpi">
        <span class="caja-kpi__label">Efectivo</span>
        <strong class="caja-kpi__value" id="kpiEfectivoSesion" data-value="<?= h(number_format($sessionTotalEfectivo, 2, '.', '')) ?>"><?= money_ar($sessionTotalEfectivo) ?></strong>
      </div>
      <div class="caja-kpi">
        <span class="caja-kpi__label">MP</span>
        <strong class="caja-kpi__value" id="kpiMpSesion" data-value="<?= h(number_format($sessionTotalMp, 2, '.', '')) ?>"><?= money_ar($sessionTotalMp) ?></strong>
      </div>
      <div class="caja-kpi caja-kpi--live">
        <span class="caja-kpi__label">Ticket</span>
        <strong class="caja-kpi__value" id="kpiTicketActual">$0,00</strong>
      </div>
      <div class="caja-kpi caja-kpi--live">
        <span class="caja-kpi__label">Pagado</span>
        <strong class="caja-kpi__value" id="kpiPagadoActual">$0,00</strong>
      </div>
    </div>



    <!-- MOVIMIENTOS COLAPSABLES -->
    <details class="caja-mov" id="cajaMov">
      <summary class="caja-mov__sum">
        <span class="caja-mov__toggle" aria-hidden="true"></span>

        <div class="caja-mov__left">
          <div class="caja-mov__title">Movimientos de caja</div>
          <div class="caja-mov__sub">Ingresos y egresos de efectivo en esta apertura</div>
        </div>

        <div class="caja-mov__right">
          <span class="pill pill-success">+ <?= money_ar($movIngresos) ?></span>
          <span class="pill pill-danger">- <?= money_ar($movEgresos) ?></span>

          <a class="btn btn-primary btn-sm"
             href="caja_movimientos.php"
             onclick="event.preventDefault(); event.stopPropagation(); window.location='caja_movimientos.php';">
            Registrar
          </a>
        </div>
      </summary>

      <div class="caja-mov__body">
        <?php if (!$movCaja): ?>
          <div class="muted">Todavia no hay movimientos en esta apertura.</div>
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
                    $lbl  = ($t === 'EGRESO') ? '- Egreso' : '+ Ingreso';
                  ?>
                  <tr>
                    <td class="mono"><?= h(format_datetime_ar($m['fecha'] ?? null)) ?></td>
                    <td><span class="pill <?= $pill ?>"><?= h($lbl) ?></span></td>
                    <td><?= h((string)($m['concepto'] ?? '-')) ?></td>
                    <td class="t-right"><?= money_ar($m['monto'] ?? 0) ?></td>
                    <td><?= h((string)($m['usuario_registro'] ?? '-')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </details>

    <div class="caja-neo-shell">
      <section class="caja-neo-main" aria-label="Venta actual">

    <!-- SCAN / INPUTS -->
    <div class="caja-scan-card">
      <div class="row caja-row-inputs">
        <div class="field field-product">
          <label for="codigo">Producto / codigo</label>
          <input type="text" id="codigo" autocomplete="off" autofocus placeholder="Escanea o escribi nombre o codigo...">
        </div>

        <div class="field field-narrow field-qty">
          <label for="cantidad">Cant.</label>
          <input type="text" id="cantidad" value="1" autocomplete="off">
        </div>

        <div class="field field-narrow field-add-btn">
          <button class="btn btn-add" id="btnAgregar" type="button">
            Agregar
          </button>
        </div>
      </div>

      <div class="caja-scan-meta" aria-live="polite">
        <span class="caja-scan-mode" id="scanModeBadge">Producto activo</span>
        <span class="caja-scan-copy" id="scanModeText">Escanea o escribi un producto. Enter agrega &middot; Tab pasa a cantidad &middot; F3 vuelve aca.</span>
      </div>

      <div class="caja-scan-hints">
        <span><kbd>Enter</kbd> agrega el producto</span>
        <span><kbd>Tab</kbd> completa y pasa a cantidad</span>
        <span><kbd>F3</kbd> vuelve a producto</span>
        <span><kbd>F2</kbd> cobra el ticket</span>
        <span><kbd>F4</kbd> cancela la venta</span>
      </div>
    </div>

    <div class="caja-neo-utility">
      <div class="caja-neo-utility__item"><kbd>Enter</kbd> Agregar producto</div>
      <div class="caja-neo-utility__item"><kbd>Tab</kbd> Completar y cantidad</div>
      <div class="caja-neo-utility__item"><kbd>F3</kbd> Volver a producto</div>
      <div class="caja-neo-utility__item"><kbd>F2</kbd> Cobrar ticket</div>
      <div class="caja-neo-utility__item"><kbd>F4</kbd> Cancelar venta</div>
      <div class="caja-neo-utility__item"><kbd>F5</kbd> Efectivo</div>
      <div class="caja-neo-utility__item"><kbd>F6</kbd> Mercado Pago</div>
      <div class="caja-neo-utility__item"><kbd>F7</kbd> Debito</div>
    </div>

    <div class="caja-neo-ticket">
      <div class="ticket-panel-head">
        <div class="ticket-panel-head__copy">
          <span class="ticket-panel-head__eyebrow">Venta actual</span>
          <strong class="ticket-panel-head__title">Ticket</strong>
        </div>
        <div class="ticket-panel-head__status" id="ticketStatusLabel">Sin productos cargados</div>
      </div>

      <!-- Tabla del ticket -->
      <div class="ticket-wrapper">
        <div class="ticket-empty-state" id="ticketEmptyState" aria-live="polite">
          <div class="ticket-empty-state__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="9" cy="20" r="1.5"></circle>
              <circle cx="18" cy="20" r="1.5"></circle>
              <path d="M3 4h2.2l2 10.2a1 1 0 0 0 1 .8h8.9a1 1 0 0 0 1-.76L20 7H6.2"></path>
            </svg>
          </div>
          <strong class="ticket-empty-state__title">Ticket vacio</strong>
          <p class="ticket-empty-state__copy">Escanea o escribi un producto para empezar la venta.</p>
        </div>
        <table id="tabla">
          <colgroup>
            <col class="col-rownum">
            <col class="col-code">
            <col class="col-product">
            <col class="col-qty">
            <col class="col-price">
            <col class="col-subtotal">
            <col class="col-actions">
          </colgroup>
          <thead>
            <tr>
              <th>#</th>
              <th>Codigo</th>
              <th>Producto</th>
              <th class="center col-cant">Cant.</th>
              <th class="right col-precio">Precio</th>
              <th class="right col-subtotal">Subtotal</th>
              <th class="center">Acciones</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>

    </div>

    <div class="caja-neo-footer">
      <div class="caja-neo-footer__bar">
        <div class="caja-neo-summary" aria-label="Resumen del ticket">
          <div class="total-panel">
            <div class="total-row">
              <span class="total-label">Total bruto</span>
              <span class="total-value" id="lblTotalBruto">$0,00</span>
            </div>

            <div class="total-row total-row-strong">
              <span class="total-label">Total a cobrar</span>
              <span class="total-value" id="lblTotal">$0,00</span>
            </div>

            <div class="total-feedback" aria-live="polite">
              <span class="total-feedback__label" id="lblCobroFeedbackLabel">Vuelto</span>
              <strong class="total-feedback__value" id="lblCobroFeedback">$0,00</strong>
            </div>
          </div>
        </div>

        <div class="buttons-row">
          <button id="btnCancelar" type="button" class="btn-cancelar">
            <span class="action-button__label">Cancelar</span>
            <span class="action-button__key">F4</span>
          </button>
          <button id="btnCobrar" type="button" class="btn-cobrar">
            <span class="action-button__label">Cobrar</span>
            <span class="action-button__key">F2</span>
          </button>
        </div>
      </div>

      <div class="shortcuts-box">
        <div class="shortcuts-card">
          <div class="shortcuts-title">Atajos de teclado</div>
          <div class="shortcuts-list">
            <span><kbd>F2</kbd> Cobrar</span>
            <span><kbd>F4</kbd> Cancelar venta</span>
            <span><kbd>F5</kbd> Efectivo</span>
            <span><kbd>F6</kbd> Mercado Pago</span>
            <span><kbd>F7</kbd> Debito</span>
            <span><kbd>Enter</kbd> Agregar producto</span>
            <span><kbd>Tab</kbd> Completar y cantidad</span>
            <span><kbd>F3</kbd> Ir a producto</span>
            <span><kbd>Esc</kbd> Cerrar ventana / modal</span>
          </div>
        </div>
      </div>
    </div>

      </section>
      <aside class="caja-neo-sidebar" aria-label="Cobro y medios de pago">

    <!-- Pagos (1 o 2 medios) -->
    <div class="total-row total-row-bottom pagos-row">
      <div class="pagos-row__head">
        <div class="pagos-row__copy">
          <div class="pagos-row__eyebrow">Medio de pago</div>
          <div class="pagos-row__title">Elegi como cobra el cajero</div>
        </div>
      </div>

      <div class="caja-payment-health" aria-live="polite">
        <div class="caja-payment-health__row">
          <span class="caja-payment-health__label">Ticket</span>
          <strong class="caja-payment-health__value" id="sidebarTicketTotal">$0,00</strong>
        </div>
        <div class="caja-payment-health__row">
          <span class="caja-payment-health__label">Pagado</span>
          <strong class="caja-payment-health__value" id="sidebarTicketPaid">$0,00</strong>
        </div>
        <div class="caja-payment-health__row" id="sidebarPendingWrap">
          <span class="caja-payment-health__label" id="sidebarPendingLabel">Vuelto</span>
          <strong class="caja-payment-health__value caja-payment-health__value--accent" id="sidebarPendingValue">$0,00</strong>
        </div>
      </div>

      <!-- Cuenta Corriente (solo si se elige CC en algun pago) -->
      <div id="ccWrap" class="cc-wrap is-hidden">
        <div class="cc-wrap__head">
          <div class="cc-wrap__copy">
            <div class="total-label-inline">Cuenta Corriente</div>
            <strong class="cc-wrap__title">Cliente requerido</strong>
          </div>
          <span class="cc-wrap__amount" id="ccMontoResumen">$0,00</span>
        </div>
        <div class="cc-row">
          <input type="text" id="ccClienteBuscar" placeholder="Buscar cliente (nombre / telefono / CUIT)" autocomplete="off">
          <input type="hidden" id="ccClienteId" value="">
        </div>
        <div id="ccClienteInfo" class="cc-info"></div>
      </div>

      <div class="field-small payment-card payment-card--primary" id="pago1Wrap" data-payment-wrap="1">
        <div class="total-label-inline">Pago 1</div>
        <div class="payment-methods" data-payment-slot="1" role="group" aria-label="Medio de pago principal">
          <button type="button" class="payment-method" data-slot="1" data-value="EFECTIVO" aria-pressed="true">
            <span class="payment-method__label">Efectivo</span>
            <span class="payment-method__key">F5</span>
          </button>
          <button type="button" class="payment-method" data-slot="1" data-value="MP" aria-pressed="false">
            <span class="payment-method__label">Mercado Pago</span>
            <span class="payment-method__key">F6</span>
          </button>
          <button type="button" class="payment-method" data-slot="1" data-value="DEBITO" aria-pressed="false">
            <span class="payment-method__label">Debito</span>
            <span class="payment-method__key">F7</span>
          </button>
          <button type="button" class="payment-method" data-slot="1" data-value="CREDITO" aria-pressed="false">
            <span class="payment-method__label">Credito</span>
          </button>
          <?php if (function_exists('user_has_permission') && user_has_permission('registrar_cargo_cc')): ?>
            <button type="button" class="payment-method" data-slot="1" data-value="CC" aria-pressed="false">
              <span class="payment-method__label">Cta. Corriente</span>
            </button>
          <?php endif; ?>
        </div>
        <select id="medioPago" class="payment-select-native" data-payment-slot="1" tabindex="-1" aria-hidden="true">
          <option value="EFECTIVO">Efectivo</option>
          <option value="MP">Mercado Pago</option>
          <option value="DEBITO">Debito</option>
          <option value="CREDITO">Credito</option>
          <?php if (function_exists('user_has_permission') && user_has_permission('registrar_cargo_cc')): ?>
            <option value="CC">Cuenta Corriente</option>
          <?php endif; ?>
        </select>

        <div class="total-label-inline">Monto</div>
        <input type="number" id="montoPagado" data-payment-slot="1" min="0" step="0.01" placeholder="0,00">
        <div class="payment-card__summary" aria-live="polite">
          <span class="payment-card__summary-chip" id="paymentSummaryMethod1">Efectivo</span>
          <strong class="payment-card__summary-value" id="paymentSummaryValue1">$0,00</strong>
        </div>

        <!-- UX: chips de billete rapido (solo efectivo) -->
        <div id="denomChips" class="denom-chips" aria-label="Billetes rapidos" style="display:none">
          <button type="button" class="denom-chip" data-monto="500">$500</button>
          <button type="button" class="denom-chip" data-monto="1000">$1.000</button>
          <button type="button" class="denom-chip" data-monto="2000">$2.000</button>
          <button type="button" class="denom-chip" data-monto="5000">$5.000</button>
          <button type="button" class="denom-chip" data-monto="10000">$10.000</button>
        </div>

        <button type="button" id="btnAgregarPago" class="btn-mini pagos-row__add" title="Agregar 2do medio de pago">+ Otro medio</button>
      </div>

      <div class="field-small payment-card is-hidden" id="pago2Wrap" data-payment-wrap="2">
        <div class="total-label-inline">Pago 2</div>
        <div class="pago2-select-row">
          <select id="medioPago2" class="payment-select-native payment-select-compact" data-payment-slot="2">
            <option value="EFECTIVO">Efectivo</option>
            <option value="MP">Mercado Pago</option>
            <option value="DEBITO">Debito</option>
            <option value="CREDITO">Credito</option>
            <?php if (function_exists('user_has_permission') && user_has_permission('registrar_cargo_cc')): ?>
              <option value="CC">Cuenta Corriente</option>
            <?php endif; ?>
          </select>
        </div>

        <div class="total-label-inline">Monto</div>
        <div class="pago2-monto-row">
          <input type="number" id="montoPagado2" data-payment-slot="2" min="0" step="0.01" placeholder="0,00">
          <button type="button" id="btnQuitarPago2" class="btn-mini btn-mini-danger" title="Quitar 2do pago" aria-label="Quitar 2do pago">&times;</button>
        </div>
        <div class="payment-card__summary" aria-live="polite">
          <span class="payment-card__summary-chip" id="paymentSummaryMethod2">Efectivo</span>
          <strong class="payment-card__summary-value" id="paymentSummaryValue2">$0,00</strong>
        </div>
      </div>

</div>
      </aside>
    </div>

    <div id="msg" class="msg"></div>

    <div id="ticketPreviewModal" class="ticket-preview-modal hidden" aria-hidden="true">
      <div class="ticket-preview-modal__backdrop" data-ticket-preview-close></div>
      <div class="ticket-preview-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ticketPreviewTitle">
        <div class="ticket-preview-modal__head">
          <div>
            <div class="ticket-preview-modal__eyebrow">Ticket listo</div>
            <h3 id="ticketPreviewTitle" class="ticket-preview-modal__title">
              Venta #<span id="ticketPreviewVentaId">0</span>
            </h3>
          </div>
          <button type="button" class="ticket-preview-modal__close" data-ticket-preview-close>Cerrar</button>
        </div>

        <div class="ticket-preview-modal__body">
          <iframe id="ticketPreviewFrame" title="Vista previa del ticket"></iframe>
        </div>

        <div class="ticket-preview-modal__actions">
          <a id="ticketPreviewOpen" class="btn btn-secondary btn-sm" href="#" target="_blank" rel="noopener">
            Abrir aparte
          </a>
          <button type="button" id="ticketPreviewPrint" class="btn btn-primary btn-sm">
            Imprimir
          </button>
        </div>
      </div>
    </div>

    <div id="ventasRecientesModal" class="ventas-recientes-modal hidden" aria-hidden="true">
      <div class="ventas-recientes-modal__backdrop" data-ventas-recientes-close></div>
      <div class="ventas-recientes-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ventasRecientesTitle" aria-describedby="ventasRecientesSubtitle">
        <div class="ventas-recientes-modal__head">
          <div>
            <div class="ventas-recientes-modal__eyebrow">Caja actual</div>
            <h3 id="ventasRecientesTitle" class="ventas-recientes-modal__title">Ventas recientes</h3>
            <p id="ventasRecientesSubtitle" class="ventas-recientes-modal__subtitle">Ver y reimprimir tickets de la apertura actual.</p>
          </div>
          <button type="button" class="ventas-recientes-modal__close" data-ventas-recientes-close>Cerrar</button>
        </div>

        <div id="ventasRecientesStatus" class="ventas-recientes-modal__status" aria-live="polite"></div>
        <div id="ventasRecientesList" class="ventas-recientes-list"></div>
      </div>
    </div>

    <div id="mpQrModal" class="mpqr-modal hidden" aria-hidden="true">
      <div class="mpqr-modal__backdrop"></div>
      <div class="mpqr-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="mpQrTitle">
        <div class="mpqr-modal__head">
          <div>
            <div class="mpqr-modal__eyebrow">Mercado Pago QR</div>
            <h3 id="mpQrTitle" class="mpqr-modal__title">Esperando pago</h3>
          </div>
          <strong id="mpQrAmount" class="mpqr-modal__amount">$0,00</strong>
        </div>
        <div class="mpqr-modal__body">
          <div id="mpQrImageBox" class="mpqr-modal__qrbox">
            <img id="mpQrImage" alt="QR de pago Mercado Pago">
          </div>
          <div id="mpQrHint" class="mpqr-modal__hint">Escanea el QR con la cuenta compradora. FLUS confirma automaticamente al acreditarse.</div>
          <dl class="mpqr-modal__details">
            <div><dt>Order</dt><dd id="mpQrOrderId">-</dd></div>
            <div><dt>Pago</dt><dd id="mpQrPaymentId">-</dd></div>
            <div><dt>Estado</dt><dd id="mpQrStatusText">Preparando...</dd></div>
          </dl>
        </div>
        <div class="mpqr-modal__actions">
          <button type="button" id="mpQrCancelBtn" class="btn btn-secondary btn-sm">Cancelar QR</button>
        </div>
      </div>
    </div>

    <!-- Modal custom caja -->
    <div id="modal" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="modal-titulo" aria-describedby="modal-texto">
      <div class="modal-content">
        <h3 id="modal-titulo">Titulo</h3>
        <p id="modal-texto"></p>

        <div id="modal-input-container" class="input-area">
          <label id="modal-label" for="modal-input">Cantidad</label>

          <select id="modal-desc-tipo" class="modal-desc-tipo hidden">
            <option value="precio">Nuevo precio unitario</option>
            <option value="porcentaje">% de descuento</option>
            <option value="monto">Descuento en $</option>
          </select>

          <input id="modal-input" type="number" min="1" step="1">

          <!-- Alerta de stock dentro del modal -->
          <div id="modal-stock-alert" class="modal-stock-alert hidden"></div>
        </div>

        <div class="modal-buttons">
          <button id="modal-cancel" class="btn-cancel">Cancelar</button>
          <button id="modal-confirm" class="btn-confirm">Aceptar</button>
        </div>
      </div>
    </div>

  <?php endif; ?>

</div><!-- /.panel -->

<!-- Overlay de vuelto grande -->
<div id="vueltoOverlayFlus" class="vuelto-overlay-flus" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="vueltoOverlayLabel">
  <div class="vuelto-overlay-flus__inner">
    <div class="vuelto-overlay-flus__eyebrow">Vuelto al cliente</div>
    <div class="vuelto-overlay-flus__amount" id="vueltoOverlayAmount">$0,00</div>
    <div class="vuelto-overlay-flus__sub" id="vueltoOverlaySub"></div>
    <button type="button" class="vuelto-overlay-flus__close" id="vueltoOverlayClose">
      Continuar <kbd>Esc</kbd>
    </button>
  </div>
</div>

<!-- Contenedor para snack "Deshacer" -->
<div id="undoSnackContainer" class="undo-snack-container" aria-live="polite"></div>

<?php caja_render_terminal_modal(); ?>

<!-- MODAL: COBRAR CUENTA CORRIENTE -->
<?php if (function_exists('user_has_permission') && user_has_permission('registrar_pago_cc')): ?>
<div id="modalCcPago" class="modal hidden" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="modalCcPagoTitle">
  <div class="modal-content modal-content--md">
    <h3 id="modalCcPagoTitle" class="modal-title">Cobrar Cuenta Corriente</h3>

    <div class="field">
      <label for="ccPagoBuscar">Cliente</label>
      <div class="cc-buscar-wrap">
        <input type="text" id="ccPagoBuscar" placeholder="Buscar cliente (nombre / telefono / CUIT)" autocomplete="off">
        <input type="hidden" id="ccPagoClienteId" value="">
      </div>
      <div id="ccPagoInfo" class="cc-info cc-info--modal"></div>
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
        <option value="DEBITO">Debito</option>
        <option value="CREDITO">Credito</option>
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

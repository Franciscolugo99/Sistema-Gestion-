<?php
// public/caja.php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/caja_lib.php';
require_once __DIR__ . '/lib/helpers.php';

require_login();

$pdo  = getPDO();
$user = current_user();

// Asegurar sesión (auth.php ya la abre, pero no molesta)
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$aperturaError = null;

/* --------------------------------------------------------
   APERTURA DE CAJA (POST)
   - Lo hacemos ANTES de imprimir HTML
-------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion_caja'] ?? '') === 'abrir') {
  $token = (string)($_POST['csrf_token'] ?? '');

  if (!hash_equals($_SESSION['csrf_token'], $token)) {
    $aperturaError = 'Token inválido. Recargá la página e intentá de nuevo.';
  } else {
    $saldoIni = parse_money_ar($_POST['saldo_inicial'] ?? '0');

    if ($saldoIni < 0) {
      $aperturaError = 'El saldo inicial no puede ser negativo.';
    } else {
      // Si tu caja_abrir ya bloquea y valida "no hay abierta" mejor.
      // Si no, acá evitamos doble apertura por chequeo simple.
      $tmp = caja_get_abierta($pdo);
      if (!$tmp || !is_array($tmp) || empty($tmp['id'])) {
        caja_abrir($pdo, (int)$user['id'], $saldoIni);
        header('Location: caja.php');
        exit;
      } else {
        // Ya hay una abierta
        header('Location: caja.php');
        exit;
      }
    }
  }
}

/* --------------------------------------------------------
   METADATOS PARA HEADER GLOBAL
-------------------------------------------------------- */
$pageTitle      = 'Caja';
$currentSection = 'caja';
$extraCss       = [
  'assets/css/caja.css',
  'assets/css/caja_cerrar.css',
];
$extraJs  = [
  'assets/js/caja_ui.js',
  'assets/js/caja.js',
];

require __DIR__ . '/partials/header.php';

/* --------------------------------------------------------
   IMPORTANTE:
   Re-consultamos DESPUÉS del header/nav para que ninguna
   inclusión pueda “pisar” la variable que usamos para render.
-------------------------------------------------------- */
$cajaSesion = caja_get_abierta($pdo);
if (!$cajaSesion || !is_array($cajaSesion) || empty($cajaSesion['id'])) {
  $cajaSesion = null;
}
?>

<div class="panel caja-panel">

  <?php if ($cajaSesion === null): ?>

    <!-- ====================================================
         CAJA CERRADA – PANEL DE APERTURA
    ===================================================== -->
    <h1 class="caja-title">CAJA</h1>

    <div class="apertura-wrapper">
      <p class="apertura-text">
        No hay ninguna caja abierta. Ingresá el saldo inicial para comenzar.
      </p>

      <div class="apertura-card">
        <form method="post" class="form-apertura" id="formAperturaCaja">
          <input type="hidden" name="accion_caja" value="abrir">
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

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

    <!-- ====================================================
         CAJA ABIERTA – PANTALLA PRINCIPAL
    ===================================================== -->
    <div class="caja-status-header">
      <span class="mono">
        Caja abierta · Apertura #<?= (int)$cajaSesion['id'] ?> ·
        <?= h($cajaSesion['username'] ?? '') ?> ·
        <?= h($cajaSesion['fecha_apertura'] ?? '') ?>
      </span>

      <button
        type="button"
        id="btnCerrarCaja"
        class="btn btn-danger"
        data-caja-id="<?= (int)$cajaSesion['id'] ?>">
        Cerrar caja
      </button>
    </div>

    <h1 class="caja-title">CAJA</h1>

    <!-- Fila código + cantidad + agregar -->
    <div class="row caja-row-inputs">
      <div class="field">
        <label for="codigo">Escanear código</label>
        <input type="text" id="codigo" autocomplete="off" autofocus>
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

    <!-- Medio de pago / Pagado / Vuelto -->
    <div class="total-row total-row-bottom">
      <div class="field-small">
        <div class="total-label-inline">Medio de pago</div>
        <select id="medioPago">
          <option value="EFECTIVO">Efectivo</option>
          <option value="MP">Mercado Pago</option>
          <option value="DEBITO">Débito</option>
          <option value="CREDITO">Crédito</option>
        </select>
      </div>

      <div class="field-small">
        <div class="total-label-inline">Cliente paga</div>
        <input type="number" id="montoPagado" min="0" step="0.01">
      </div>

      <div class="field-small">
        <div class="total-label-inline">Vuelto</div>
        <div class="total-vuelto" id="lblVuelto">$0,00</div>
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

<?php require __DIR__ . '/partials/footer.php'; ?>

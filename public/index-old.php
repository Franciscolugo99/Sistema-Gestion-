<?php
require_once __DIR__ . '/auth.php';
require_login();
// require_permission('realizar_ventas'); // cuando quieras activar permisos

require_once __DIR__ . '/caja_lib.php';

$user = current_user();

// 1) Detectar si hay caja abierta
$cajaAbierta = caja_get_abierta($pdo);

// 2) Manejar apertura de caja (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_caja'])) {
    if ($_POST['accion_caja'] === 'abrir') {
        $saldoIni = (float) str_replace(',', '.', $_POST['saldo_inicial'] ?? '0');

        // si ya hay caja abierta, no abrimos otra
        if (!$cajaAbierta) {
            $idNueva = caja_abrir($pdo, (int)$user['id'], $saldoIni);
            header('Location: caja.php');
            exit;
        }
    }
    // el cierre lo manejamos luego en caja_cerrar.php
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>CAJA - Kiosco</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <!-- theme + estilos globales -->
  <link rel="stylesheet" href="assets/css/theme.css?v=1">
  <link rel="stylesheet" href="assets/css/app.css?v=1">

  <!-- estilos específicos de CAJA -->
  <link rel="stylesheet" href="assets/css/caja.css?v=2">
</head>

<body data-theme="dark">
  <!-- NAV SIEMPRE A 100% -->
  <?php require_once __DIR__ . '/partials/nav.php'; ?>

  <!-- CONTENIDO A 80% -->
  <div class="root container-global">
    <div class="panel">
        <!-- ========== HEADER ESTADO CAJA + POS NORMAL ========== -->
        <div class="caja-status-header" style="margin-bottom: 14px;">
          <span class="pill pill-open">Caja abierta</span>
          <span class="mono">
            Apertura #<?= (int)$cajaAbierta['id'] ?> ·
            <?= htmlspecialchars($cajaAbierta['username'], ENT_QUOTES, 'UTF-8') ?> ·
            <?= htmlspecialchars($cajaAbierta['fecha_apertura'], ENT_QUOTES, 'UTF-8') ?>
          </span>
                    <button id="btnCerrarCaja" type="button" class="btn-cancelar" style="margin-top:15px;">
            Cerrar caja
          </button>
        </div>

        <h1>CAJA</h1>

        <!-- FILA CODIGO + CANTIDAD + AGREGAR -->
        <div class="row">
          <div class="field">
            <label for="codigo">Escanear código</label>
            <input type="text" id="codigo" autocomplete="off" autofocus>
          </div>

          <div class="field field-narrow">
            <label for="cantidad">Cantidad</label>
            <input type="number" id="cantidad" value="1" min="1">
          </div>

          <div class="field field-narrow" style="display:flex;align-items:flex-end;">
            <button class="btn btn-add" id="btnAgregar" type="button">
              Agregar al ticket
            </button>
          </div>
        </div>

        <!-- TABLA DEL TICKET -->
        <div class="ticket-wrapper">
          <table id="tabla">
            <thead>
              <tr>
                <th>#</th>
                <th>Código</th>
                <th>Producto</th>
                <th class="right">Cant.</th>
                <th class="right">Precio</th>
                <th class="right">Subtotal</th>
                <th class="center">Acciones</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>

        <!-- TOTAL -->
        <div class="total-box">

          <div class="total-header">
            <div class="total-title">Total</div>
            <div class="total-amount" id="lblTotal">$0,00</div>
          </div>

          <div class="total-row">
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

          <!-- BOTONES -->
          <div class="buttons-row">
            <button id="btnCancelar" type="button" class="btn-cancelar">Cancelar venta</button>
            <button id="btnCobrar" type="button" class="btn-cobrar">Cobrar</button>
          </div>

          <div id="msg" class="msg"></div>

        </div> <!-- /.total-box -->


      <!-- MODAL CUSTOM CAJA (queda igual) -->
      <div id="modal" class="modal hidden">
        <div class="modal-content">
          <h3 id="modal-titulo">Título</h3>
          <p id="modal-texto"></p>

          <div id="modal-input-container" class="input-area">
            <label id="modal-label" for="modal-input">Cantidad</label>
            <input
              id="modal-input"
              type="number"
              min="1"
              step="1"
            >
          </div>

          <div class="modal-buttons">
            <button id="modal-cancel" class="btn-cancel">Cancelar</button>
            <button id="modal-confirm" class="btn-confirm">Aceptar</button>
          </div>
        </div>
      </div>

    </div>   <!-- /.panel -->
  </div> <!-- /.root -->

  <!-- JS -->
  <script src="assets/js/app.js?v=1"></script>
  <script src="assets/js/caja.js?v=1"></script>

</body>
</html>

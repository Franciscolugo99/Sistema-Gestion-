<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/csrf.php';
require_once FLUS_ROOT . '/src/mercadopago_qr_lib.php';

require_login();
require_permission('administrar_config');

$csrfToken = csrf_token();
$configured = flus_mp_qr_is_configured();
$mode = flus_mp_qr_mode();
$externalPosId = flus_mp_qr_external_pos_id();
$description = flus_mp_qr_description();

$currentSection = 'configuracion';
$pageTitle = 'Prueba Mercado Pago QR';
$extraCss = array_merge($extraCss ?? [], ['assets/css/mp_qr_test.css']);
$extraJs = array_merge($extraJs ?? [], ['assets/js/mp_qr_test.js']);

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap mpqr-page">
  <div class="panel mpqr-shell">
    <header class="module-header mpqr-header">
      <div class="module-header-main">
        <span class="module-eyebrow">Integraciones</span>
        <h1>Mercado Pago QR</h1>
        <p class="module-subtitle">Prueba local para crear una order, mostrar el QR y esperar confirmacion sin tocar ventas reales.</p>
      </div>
      <a class="btn btn-secondary" href="configuracion.php">Volver</a>
    </header>

    <?php if (!$configured): ?>
      <section class="mpqr-alert">
        <strong>Falta configurar Mercado Pago</strong>
        <span>Copia <code>src/config_mp.example.php</code> a <code>src/config_mp.php</code> y completa <code>FLUS_MP_ACCESS_TOKEN</code> y <code>FLUS_MP_QR_EXTERNAL_POS_ID</code>.</span>
      </section>
    <?php endif; ?>

    <div class="mpqr-grid">
      <section class="mpqr-card">
        <div class="mpqr-card-head">
          <span>Crear prueba</span>
          <strong>Order QR</strong>
        </div>

        <form id="mpQrForm" class="mpqr-form" data-csrf="<?= h($csrfToken) ?>" data-configured="<?= $configured ? '1' : '0' ?>">
          <label>
            <span>Importe</span>
            <input id="mpQrAmount" type="number" inputmode="decimal" min="1" step="0.01" value="10.00" required>
          </label>

          <label>
            <span>Modo QR</span>
            <select id="mpQrMode">
              <option value="dynamic" <?= $mode === 'dynamic' ? 'selected' : '' ?>>Dinamico</option>
              <option value="hybrid" <?= $mode === 'hybrid' ? 'selected' : '' ?>>Hibrido</option>
              <option value="static" <?= $mode === 'static' ? 'selected' : '' ?>>Estatico</option>
            </select>
          </label>

          <label class="mpqr-form-wide">
            <span>Descripcion</span>
            <input id="mpQrDescription" type="text" maxlength="150" value="<?= h($description) ?>">
          </label>

          <div class="mpqr-actions">
            <button class="btn btn-primary" type="submit" <?= $configured ? '' : 'disabled' ?>>Generar QR</button>
            <button class="btn btn-secondary" type="button" id="mpQrCancel" disabled>Cancelar order</button>
          </div>
        </form>

        <dl class="mpqr-config">
          <div>
            <dt>POS externo</dt>
            <dd><?= h($externalPosId !== '' ? $externalPosId : 'Sin configurar') ?></dd>
          </div>
          <div>
            <dt>Polling</dt>
            <dd>Cada 2 segundos desde esta PC</dd>
          </div>
        </dl>
      </section>

      <section class="mpqr-card mpqr-result">
        <div class="mpqr-card-head">
          <span>Estado</span>
          <strong id="mpQrStatusTitle">Sin order</strong>
        </div>

        <div id="mpQrQrBox" class="mpqr-qrbox" hidden>
          <img id="mpQrImage" alt="QR de pago Mercado Pago">
        </div>

        <div id="mpQrNoQr" class="mpqr-empty">
          Genera una order para ver el QR o escanear el QR estatico de la caja.
        </div>

        <dl class="mpqr-order">
          <div><dt>Order</dt><dd id="mpQrOrderId">-</dd></div>
          <div><dt>Referencia</dt><dd id="mpQrReference">-</dd></div>
          <div><dt>Pago</dt><dd id="mpQrPaymentId">-</dd></div>
          <div><dt>Detalle</dt><dd id="mpQrDetail">-</dd></div>
        </dl>
      </section>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

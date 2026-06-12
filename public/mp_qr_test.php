<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/csrf.php';
require_once FLUS_ROOT . '/src/mercadopago_qr_lib.php';

require_login();
require_permission('administrar_config');

$csrfToken = csrf_token();
$configured = flus_mp_qr_is_configured();
$tokenPresent = flus_mp_qr_access_token() !== '';
$curlOk = function_exists('curl_init');
$mode = flus_mp_qr_mode();
$externalPosId = flus_mp_qr_external_pos_id();
$description = flus_mp_qr_description();
$environment = flus_mp_qr_environment();
$isProduction = $environment === 'production';
$minAmount = flus_mp_min_amount();
$configIssues = [];
if (!$tokenPresent) {
    $configIssues[] = 'Falta el Access Token de Mercado Pago.';
}
if ($externalPosId === '') {
    $configIssues[] = 'Falta el POS externo QR de esta caja.';
}
if (!$curlOk) {
    $configIssues[] = 'PHP cURL no esta habilitado en esta instalacion.';
}
if ($configIssues === []) {
    $configIssues[] = 'La configuracion base esta lista. Si una prueba falla, revisa la conexion o la respuesta de Mercado Pago.';
}

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
        <p class="module-subtitle">Herramienta de diagnostico para crear una order y verificar su confirmacion. No registra una venta en FLUS.</p>
      </div>
      <a class="btn btn-secondary" href="configuracion.php">Volver</a>
    </header>

    <?php if ($isProduction): ?>
      <section class="mpqr-live-warning" role="alert">
        <strong>Ambiente productivo: este QR cobra dinero real</strong>
        <span>Usalo sólo para una prueba controlada. La operación no crea una venta en FLUS y, si corresponde, debe devolverse desde Mercado Pago.</span>
      </section>
    <?php endif; ?>

    <?php if (!$configured): ?>
      <section class="mpqr-alert">
        <strong>Mercado Pago QR todavia no esta listo</strong>
        <span>Completa estos puntos desde Configuracion &gt; Mercado Pago y volve a probar.</span>
        <ul>
          <?php foreach ($configIssues as $issue): ?>
            <li><?= h($issue) ?></li>
          <?php endforeach; ?>
        </ul>
        <a class="btn btn-secondary" href="mercadopago_config.php">Abrir configuracion Mercado Pago</a>
      </section>
    <?php endif; ?>

    <div class="mpqr-grid">
      <section class="mpqr-card">
        <div class="mpqr-card-head">
          <span><?= $isProduction ? 'Diagnostico productivo' : 'Crear prueba' ?></span>
          <strong>Order QR</strong>
        </div>

        <form id="mpQrForm" class="mpqr-form" data-csrf="<?= h($csrfToken) ?>" data-configured="<?= $configured ? '1' : '0' ?>">
          <label>
            <span>Importe</span>
            <input id="mpQrAmount" type="number" inputmode="decimal" min="<?= h((string)$minAmount) ?>" step="0.01" value="<?= h(number_format($minAmount, 2, '.', '')) ?>" required>
            <small>Mercado Pago exige minimo $<?= h(number_format($minAmount, 2, ',', '.')) ?> para esta prueba.</small>
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
            <dt>Ambiente</dt>
            <dd><?= $isProduction ? 'Produccion, dinero real' : 'Prueba' ?></dd>
          </div>
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

    <section class="mpqr-card mpqr-scenarios">
      <div class="mpqr-card-head">
        <span>Checklist</span>
        <strong>Escenarios antes de produccion</strong>
      </div>
      <div class="mpqr-scenario-grid">
        <div><strong>Aprobado</strong><span>Pagar desde la cuenta compradora y confirmar que FLUS muestra Pago aprobado.</span></div>
        <div><strong>Rechazado</strong><span>Usar el escenario de rechazo de Mercado Pago y confirmar que la order termina sin registrar una venta.</span></div>
        <div><strong>Cancelado</strong><span>Crear una order, cancelarla desde FLUS y comprobar que deja de consultar.</span></div>
        <div><strong>Expirado</strong><span>Dejar vencer una order sin pagar y verificar que el estado terminal se informa correctamente.</span></div>
      </div>
    </section>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/csrf.php';
require_once FLUS_ROOT . '/src/mercadopago_liquidaciones_lib.php';

require_login();
require_any_permission(['ver_tesoreria', 'ver_reportes_tesoreria', 'gestionar_tesoreria', 'administrar_config']);

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
        flus_abort(419, 'Token CSRF invalido.');
    }
    require_any_permission(['gestionar_tesoreria', 'administrar_config']);
    $result = flus_mp_liquidaciones_sync($pdo);
    $message = 'Sincronizadas ' . (int)($result['synced'] ?? 0) . ' operaciones.';
    if (!($result['ok'] ?? false)) {
        $error = (string)($result['error'] ?? implode(' ', (array)($result['errors'] ?? [])));
    }
}

$report = flus_mp_liquidaciones_report($pdo, $_GET);
$summary = $report['summary'];
$rows = $report['rows'];
$canSync = user_has_permission('gestionar_tesoreria') || user_has_permission('administrar_config');

$pageTitle = 'Mercado Pago - Tesoreria';
$currentSection = 'tesoreria';
$breadcrumbs = [
    ['label' => 'Tesoreria', 'url' => 'tesoreria.php'],
    ['label' => 'Mercado Pago', 'url' => null],
];
$extraCss = ['assets/css/facturacion.css?v=10', 'assets/css/tesoreria.css?v=3', 'assets/css/mercadopago_liquidaciones.css'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap facturacion-page tesoreria-page mp-settlements">
  <div class="panel fact-panel">
    <header class="page-header module-header">
      <div class="module-header-main">
        <div class="module-header-copy">
          <span class="module-eyebrow">Tesoreria</span>
          <h1 class="page-title module-title">Mercado Pago</h1>
          <p class="page-sub module-subtitle">Bruto cobrado, costos descontados y dinero neto de las ventas FLUS.</p>
        </div>
      </div>
      <div class="module-header-actions">
        <a href="mercadopago_config.php" class="v-btn v-btn--outline">Configuracion</a>
        <?php if ($canSync): ?>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <button class="v-btn v-btn--primary" type="submit">Actualizar datos</button>
          </form>
        <?php endif; ?>
      </div>
    </header>

    <?php if ($message !== ''): ?><div class="mp-settlement-alert ok"><?= h($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="mp-settlement-alert error"><?= h($error) ?></div><?php endif; ?>
    <?php if (!$report['ready']): ?>
      <div class="mp-settlement-alert warning">Falta aplicar la migracion 042 para habilitar la conciliacion.</div>
    <?php endif; ?>

    <form method="get" class="filters fact-filters">
      <div class="filters-left">
        <input type="date" name="desde" value="<?= h((string)$report['desde']) ?>">
        <input type="date" name="hasta" value="<?= h((string)$report['hasta']) ?>">
      </div>
      <div class="filters-right">
        <button class="btn btn-filter" type="submit">Aplicar</button>
        <a href="mercadopago_liquidaciones.php" class="btn btn-secondary">Este mes</a>
      </div>
    </form>

    <section class="mp-settlement-summary" aria-label="Resumen Mercado Pago">
      <div><span>Ventas brutas</span><strong><?= money_ar((float)($summary['bruto'] ?? 0)) ?></strong></div>
      <div><span>Comisiones</span><strong class="is-cost">-<?= money_ar((float)($summary['comisiones'] ?? 0)) ?></strong></div>
      <div><span>Retenciones e impuestos</span><strong class="is-cost">-<?= money_ar((float)($summary['impuestos'] ?? 0)) ?></strong></div>
      <div><span>Devoluciones</span><strong class="is-cost">-<?= money_ar((float)($summary['devoluciones'] ?? 0)) ?></strong></div>
      <div class="is-net"><span>Neto recibido</span><strong><?= money_ar((float)($summary['neto'] ?? 0)) ?></strong></div>
    </section>

    <div class="mp-settlement-note">
      <strong>Qué estás viendo</strong>
      <span>Importes informados por Mercado Pago para pagos vinculados a ventas FLUS. Las comisiones incluyen los cargos devueltos por la API; las retenciones se muestran por separado.</span>
    </div>

    <div class="table-wrapper mp-settlement-table-wrap">
      <table class="mov-table fact-table mp-settlement-table">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Venta</th>
            <th>Estado</th>
            <th class="t-right">Bruto</th>
            <th class="t-right">Comision</th>
            <th class="t-right">Impuestos</th>
            <th class="t-right">Devuelto</th>
            <th class="t-right">Neto</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td>
                <strong><?= h((string)($row['date_approved'] ?: $row['date_created'] ?: $row['created_at'])) ?></strong>
                <small><?= h((string)$row['payment_id']) ?></small>
              </td>
              <td>
                <?php if ((int)($row['venta_id'] ?? 0) > 0): ?>
                  <a href="venta_detalle.php?id=<?= (int)$row['venta_id'] ?>">#<?= (int)$row['venta_id'] ?></a>
                <?php else: ?>Sin venta vinculada<?php endif; ?>
                <small><?= h((string)($row['external_reference'] ?? '')) ?></small>
              </td>
              <td><span class="mp-settlement-status"><?= h((string)($row['status'] ?? '-')) ?></span></td>
              <td class="t-right"><?= money_ar((float)$row['transaction_amount']) ?></td>
              <td class="t-right is-cost">-<?= money_ar((float)$row['fee_amount']) ?></td>
              <td class="t-right is-cost">-<?= money_ar((float)$row['taxes_amount']) ?></td>
              <td class="t-right is-cost">-<?= money_ar((float)$row['refunded_amount']) ?></td>
              <td class="t-right"><strong><?= money_ar((float)$row['net_received_amount']) ?></strong></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($rows === []): ?>
            <tr><td colspan="8" class="mp-settlement-empty">Todavia no hay pagos conciliados. Usa <strong>Actualizar datos</strong> después de cobrar una venta desde Caja.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

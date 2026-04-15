<?php
// public/tesoreria.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/tesoreria_lib.php';

require_login();
require_any_permission(['ver_tesoreria', 'gestionar_tesoreria', 'ver_reportes_tesoreria']);

$today = new DateTimeImmutable('today');
$report = flus_tesoreria_reportes($pdo, [
    'desde' => $today->modify('first day of this month')->format('Y-m-d'),
    'hasta' => $today->modify('last day of this month')->format('Y-m-d'),
]);
$tablesReady = flus_tesoreria_tables_ready($pdo);
$saldos = $report['saldos'];
$flujo = $report['flujo'];
$saldoTotal = 0.0;
foreach ($saldos as $cuenta) {
    $saldoTotal += (float)($cuenta['saldo_actual'] ?? 0);
}

$pageTitle = 'Tesoreria - FLUS';
$currentSection = 'tesoreria';
$breadcrumbs = [
    ['label' => 'Tesoreria', 'url' => null],
];
$extraCss = ['assets/css/facturacion.css?v=10', 'assets/css/tesoreria.css?v=3'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap facturacion-page tesoreria-page">
  <div class="panel fact-panel">
    <header class="page-header module-header">
      <div class="module-header-main">
        <div class="module-header-hero">
          <span class="module-header-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
              <path d="M3 7h18v11H3z"/>
              <path d="M7 11h5"/>
              <path d="M16 14h2"/>
            </svg>
          </span>
          <div class="module-header-copy">
            <span class="module-eyebrow">Dinero operativo</span>
            <h1 class="page-title module-title">Tesoreria</h1>
            <p class="page-sub module-subtitle">
              Cuentas, movimientos, transferencias y vencimientos sin convertir FLUS en contabilidad pesada.
            </p>
          </div>
        </div>
      </div>

      <div class="promo-actions-top module-header-actions">
        <a href="tesoreria_movimientos.php" class="v-btn v-btn--primary">+ Movimiento</a>
        <a href="tesoreria_obligaciones.php" class="v-btn v-btn--outline">Obligaciones</a>
        <a href="tesoreria_reportes.php" class="v-btn v-btn--outline">Reportes</a>
      </div>
    </header>

    <?php if (!$tablesReady): ?>
      <div class="alert alert-error" style="margin-bottom:12px;">
        Faltan aplicar las migraciones de tesoreria.
      </div>
    <?php endif; ?>

    <section class="fact-kpi-grid" aria-label="Resumen de tesoreria">
      <article class="fact-kpi-card fact-kpi-card--accent">
        <span class="fact-kpi-card__label">Saldo visible</span>
        <strong class="fact-kpi-card__value"><?= money_ar($saldoTotal) ?></strong>
        <span class="fact-kpi-card__help">Saldos iniciales mas movimientos activos</span>
      </article>
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Ingresos del mes</span>
        <strong class="fact-kpi-card__value"><?= money_ar($flujo['ingresos']) ?></strong>
        <span class="fact-kpi-card__help">Sin contar transferencias internas</span>
      </article>
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Egresos del mes</span>
        <strong class="fact-kpi-card__value"><?= money_ar($flujo['egresos']) ?></strong>
        <span class="fact-kpi-card__help">Gastos y pagos operativos</span>
      </article>
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Flujo neto</span>
        <strong class="fact-kpi-card__value"><?= money_ar($flujo['neto']) ?></strong>
        <span class="fact-kpi-card__help">Ingresos menos egresos del mes</span>
      </article>
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Proximos vencimientos</span>
        <strong class="fact-kpi-card__value"><?= number_format(count($report['proximos_vencimientos'])) ?></strong>
        <span class="fact-kpi-card__help">Pendientes dentro de 30 dias</span>
      </article>
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Vencidas</span>
        <strong class="fact-kpi-card__value"><?= number_format(count($report['obligaciones_vencidas'])) ?></strong>
        <span class="fact-kpi-card__help">Obligaciones atrasadas</span>
      </article>
    </section>

    <section class="tesoreria-grid" aria-label="Accesos de tesoreria">
      <a class="tesoreria-action" href="tesoreria_cuentas.php">
        <strong>Cuentas</strong>
        <span>Caja principal, banco, billeteras, fondos fijos y saldos visibles.</span>
      </a>
      <a class="tesoreria-action" href="tesoreria_movimientos.php">
        <strong>Movimientos</strong>
        <span>Ingresos, egresos y transferencias internas entre cuentas.</span>
      </a>
      <a class="tesoreria-action" href="tesoreria_obligaciones.php">
        <strong>Obligaciones</strong>
        <span>Alquiler, impuestos, servicios, sueldos y pagos pendientes.</span>
      </a>
      <a class="tesoreria-action" href="tesoreria_reportes.php">
        <strong>Reportes</strong>
        <span>Saldos, gastos por categoria, vencimientos y flujo simple.</span>
      </a>
      <a class="tesoreria-action" href="tesoreria_categorias.php">
        <strong>Categorias</strong>
        <span>Clasificacion simple para ingresos y gastos operativos.</span>
      </a>
      <a class="tesoreria-action" href="cobranzas.php">
        <strong>Cobranzas</strong>
        <span>Facturas por cobrar y recibos internos ya registrados en FLUS.</span>
      </a>
    </section>

    <div class="table-wrapper">
      <table class="mov-table fact-table">
        <thead>
          <tr>
            <th>Cuenta</th>
            <th>Tipo</th>
            <th>Sucursal</th>
            <th class="t-right">Saldo visible</th>
            <th>Estado</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($saldos === []): ?>
            <tr><td colspan="5" class="muted">Todavia no hay cuentas financieras cargadas.</td></tr>
          <?php else: ?>
            <?php foreach ($saldos as $cuenta): ?>
              <tr>
                <td><strong><?= h((string)$cuenta['nombre']) ?></strong></td>
                <td><?= h((string)$cuenta['tipo']) ?></td>
                <td><?= h((string)($cuenta['sucursal_nombre'] ?? 'General')) ?></td>
                <td class="t-right"><strong><?= money_ar((float)($cuenta['saldo_actual'] ?? 0)) ?></strong></td>
                <td>
                  <span class="tesoreria-status <?= strtoupper((string)$cuenta['estado']) === 'ACTIVA' ? 'tesoreria-status--ok' : '' ?>">
                    <?= h((string)$cuenta['estado']) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

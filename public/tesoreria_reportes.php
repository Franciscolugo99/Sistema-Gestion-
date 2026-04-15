<?php
// public/tesoreria_reportes.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/tesoreria_lib.php';

require_login();
require_any_permission(['ver_tesoreria', 'ver_reportes_tesoreria', 'gestionar_tesoreria']);

$today = new DateTimeImmutable('today');
$desde = (string)($_GET['desde'] ?? $today->modify('first day of this month')->format('Y-m-d'));
$hasta = (string)($_GET['hasta'] ?? $today->modify('last day of this month')->format('Y-m-d'));
$report = flus_tesoreria_reportes($pdo, ['desde' => $desde, 'hasta' => $hasta]);

$pageTitle = 'Reportes de tesoreria - FLUS';
$currentSection = 'tesoreria';
$breadcrumbs = [
    ['label' => 'Tesoreria', 'url' => 'tesoreria.php'],
    ['label' => 'Reportes', 'url' => null],
];
$extraCss = ['assets/css/facturacion.css?v=10', 'assets/css/tesoreria.css?v=3'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap facturacion-page tesoreria-page">
  <div class="panel fact-panel">
    <header class="page-header module-header">
      <div class="module-header-main">
        <div class="module-header-copy">
          <span class="module-eyebrow">Control operativo</span>
          <h1 class="page-title module-title">Reportes</h1>
          <p class="page-sub module-subtitle">Saldos, gastos por categoria, vencimientos y flujo simple.</p>
        </div>
      </div>
      <div class="promo-actions-top module-header-actions">
        <a href="tesoreria.php" class="v-btn v-btn--outline">Resumen</a>
        <a href="tesoreria_movimientos.php" class="v-btn v-btn--primary">Movimientos</a>
      </div>
    </header>

    <form method="get" class="filters fact-filters">
      <div class="filters-left">
        <input type="date" name="desde" value="<?= h((string)$report['desde']) ?>">
        <input type="date" name="hasta" value="<?= h((string)$report['hasta']) ?>">
      </div>
      <div class="filters-right">
        <button class="btn btn-filter" type="submit">Aplicar</button>
        <a href="tesoreria_reportes.php" class="btn btn-secondary">Este mes</a>
      </div>
    </form>

    <section class="fact-kpi-grid" aria-label="Flujo de tesoreria">
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Ingresos</span>
        <strong class="fact-kpi-card__value"><?= money_ar($report['flujo']['ingresos']) ?></strong>
        <span class="fact-kpi-card__help">Periodo seleccionado</span>
      </article>
      <article class="fact-kpi-card fact-kpi-card--accent">
        <span class="fact-kpi-card__label">Egresos</span>
        <strong class="fact-kpi-card__value"><?= money_ar($report['flujo']['egresos']) ?></strong>
        <span class="fact-kpi-card__help">Gastos reales</span>
      </article>
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Neto</span>
        <strong class="fact-kpi-card__value"><?= money_ar($report['flujo']['neto']) ?></strong>
        <span class="fact-kpi-card__help">Ingresos menos egresos</span>
      </article>
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Proximos vencimientos</span>
        <strong class="fact-kpi-card__value"><?= number_format(count($report['proximos_vencimientos'])) ?></strong>
        <span class="fact-kpi-card__help">Dentro de 30 dias</span>
      </article>
    </section>

    <div class="tesoreria-grid">
      <section class="fact-overview">
        <div class="fact-overview__main">
          <h2 class="fact-overview__title">Saldos por cuenta</h2>
        </div>
        <div class="table-wrapper">
          <table class="mov-table fact-table">
            <tbody>
              <?php foreach ($report['saldos'] as $cuenta): ?>
                <tr>
                  <td><?= h((string)$cuenta['nombre']) ?></td>
                  <td class="t-right"><strong><?= money_ar((float)$cuenta['saldo_actual']) ?></strong></td>
                </tr>
              <?php endforeach; ?>
              <?php if ($report['saldos'] === []): ?><tr><td class="muted">Sin cuentas.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="fact-overview">
        <div class="fact-overview__main">
          <h2 class="fact-overview__title">Gastos por categoria</h2>
        </div>
        <div class="table-wrapper">
          <table class="mov-table fact-table">
            <tbody>
              <?php foreach ($report['gastos_categoria'] as $row): ?>
                <tr>
                  <td><?= h((string)$row['categoria']) ?></td>
                  <td class="t-right"><strong><?= money_ar((float)$row['total']) ?></strong></td>
                </tr>
              <?php endforeach; ?>
              <?php if ($report['gastos_categoria'] === []): ?><tr><td class="muted">Sin gastos.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="fact-overview">
        <div class="fact-overview__main">
          <h2 class="fact-overview__title">Gastos por sucursal</h2>
        </div>
        <div class="table-wrapper">
          <table class="mov-table fact-table">
            <tbody>
              <?php foreach ($report['gastos_sucursal'] as $row): ?>
                <tr>
                  <td><?= h((string)$row['sucursal']) ?></td>
                  <td class="t-right"><strong><?= money_ar((float)$row['total']) ?></strong></td>
                </tr>
              <?php endforeach; ?>
              <?php if ($report['gastos_sucursal'] === []): ?><tr><td class="muted">Sin gastos.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="fact-overview">
        <div class="fact-overview__main">
          <h2 class="fact-overview__title">Vencidas</h2>
        </div>
        <div class="table-wrapper">
          <table class="mov-table fact-table">
            <tbody>
              <?php foreach ($report['obligaciones_vencidas'] as $row): ?>
                <tr>
                  <td><?= h((string)$row['descripcion']) ?><div class="fact-cell-sub"><?= h((string)$row['fecha_vencimiento']) ?></div></td>
                  <td class="t-right"><strong><?= money_ar((float)$row['importe_estimado'] - (float)$row['importe_pagado']) ?></strong></td>
                </tr>
              <?php endforeach; ?>
              <?php if ($report['obligaciones_vencidas'] === []): ?><tr><td class="muted">Sin vencidas.</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <section class="fact-overview">
      <div class="fact-overview__main">
        <h2 class="fact-overview__title">Proximos vencimientos</h2>
      </div>
      <div class="table-wrapper">
        <table class="mov-table fact-table">
          <thead>
            <tr>
              <th>Vence</th>
              <th>Descripcion</th>
              <th>Categoria</th>
              <th class="t-right">Saldo</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($report['proximos_vencimientos'] as $row): ?>
              <tr>
                <td class="mono"><?= h(date('d/m/Y', strtotime((string)$row['fecha_vencimiento']))) ?></td>
                <td><?= h((string)$row['descripcion']) ?></td>
                <td><?= h((string)($row['categoria_nombre'] ?? '')) ?></td>
                <td class="t-right"><?= money_ar((float)$row['importe_estimado'] - (float)$row['importe_pagado']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if ($report['proximos_vencimientos'] === []): ?><tr><td colspan="4" class="muted">Sin vencimientos proximos.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

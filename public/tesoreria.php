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
$proximosVencimientos = is_array($report['proximos_vencimientos'] ?? null) ? $report['proximos_vencimientos'] : [];
$obligacionesVencidas = is_array($report['obligaciones_vencidas'] ?? null) ? $report['obligaciones_vencidas'] : [];
$saldoTotal = 0.0;
$cuentasActivas = 0;
$cuentaMenor = null;
foreach ($saldos as $cuenta) {
    $saldoCuenta = (float)($cuenta['saldo_actual'] ?? 0);
    $saldoTotal += $saldoCuenta;
    if (strtoupper((string)($cuenta['estado'] ?? '')) === 'ACTIVA') {
        $cuentasActivas++;
    }
    if ($cuentaMenor === null || $saldoCuenta < (float)($cuentaMenor['saldo_actual'] ?? 0)) {
        $cuentaMenor = $cuenta;
    }
}
$flujoNeto = (float)($flujo['neto'] ?? 0);
$vencidasTotal = 0.0;
foreach ($obligacionesVencidas as $ob) {
    $vencidasTotal += max(0.0, (float)($ob['importe_estimado'] ?? 0) - (float)($ob['importe_pagado'] ?? 0));
}
$proximosTotal = 0.0;
foreach ($proximosVencimientos as $ob) {
    $proximosTotal += max(0.0, (float)($ob['importe_estimado'] ?? 0) - (float)($ob['importe_pagado'] ?? 0));
}
$prioridades = [];
if (count($obligacionesVencidas) > 0) {
    $prioridades[] = [
        'class' => 'tesoreria-priority--danger',
        'label' => 'Vencidas',
        'text' => count($obligacionesVencidas) . ' obligaciones atrasadas por ' . money_ar($vencidasTotal),
        'href' => 'tesoreria_obligaciones.php?estado=VENCIDO',
    ];
}
if (count($proximosVencimientos) > 0) {
    $prioridades[] = [
        'class' => 'tesoreria-priority--warn',
        'label' => 'Proximas',
        'text' => count($proximosVencimientos) . ' vencimientos dentro de 30 dias por ' . money_ar($proximosTotal),
        'href' => 'tesoreria_obligaciones.php?estado=PENDIENTE',
    ];
}
if ($cuentaMenor !== null && (float)($cuentaMenor['saldo_actual'] ?? 0) < 0) {
    $prioridades[] = [
        'class' => 'tesoreria-priority--danger',
        'label' => 'Cuenta negativa',
        'text' => (string)($cuentaMenor['nombre'] ?? 'Cuenta') . ' figura con ' . money_ar((float)($cuentaMenor['saldo_actual'] ?? 0)),
        'href' => 'tesoreria_cuentas.php',
    ];
}
if ($prioridades === []) {
    $prioridades[] = [
        'class' => 'tesoreria-priority--ok',
        'label' => 'Sin urgencias',
        'text' => 'No hay vencidas ni cuentas negativas para atender ahora.',
        'href' => 'tesoreria_reportes.php',
    ];
}

$pageTitle = 'Tesoreria - FLUS';
$currentSection = 'tesoreria';
$breadcrumbs = [
    ['label' => 'Tesoreria', 'url' => null],
];
$extraCss = ['assets/css/facturacion.css?v=10', 'assets/css/tesoreria.css?v=4'];

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
      <div class="alert alert-error tesoreria-alert">
        Faltan aplicar las migraciones de tesoreria.
      </div>
    <?php endif; ?>

    <section class="tesoreria-command" aria-label="Tablero de tesoreria">
      <div class="tesoreria-command__main">
        <span class="tesoreria-command__eyebrow">Situacion del mes</span>
        <strong class="tesoreria-command__balance"><?= money_ar($saldoTotal) ?></strong>
        <p>
          Saldo visible en <?= number_format($cuentasActivas) ?> cuenta<?= $cuentasActivas === 1 ? '' : 's' ?> activa<?= $cuentasActivas === 1 ? '' : 's' ?>.
          El flujo neto del mes es <?= money_ar($flujoNeto) ?>.
        </p>
        <div class="tesoreria-command__metrics" aria-label="Flujo mensual">
          <div>
            <span>Ingresos</span>
            <strong><?= money_ar((float)($flujo['ingresos'] ?? 0)) ?></strong>
          </div>
          <div>
            <span>Egresos</span>
            <strong><?= money_ar((float)($flujo['egresos'] ?? 0)) ?></strong>
          </div>
          <div class="<?= $flujoNeto < 0 ? 'is-negative' : 'is-positive' ?>">
            <span>Neto</span>
            <strong><?= money_ar($flujoNeto) ?></strong>
          </div>
        </div>
      </div>
      <aside class="tesoreria-priorities" aria-label="Prioridad operativa">
        <div class="tesoreria-section-head">
          <span>Prioridad operativa</span>
          <strong>Que mirar ahora</strong>
        </div>
        <div class="tesoreria-priority-list">
          <?php foreach (array_slice($prioridades, 0, 3) as $item): ?>
            <a class="tesoreria-priority <?= h((string)$item['class']) ?>" href="<?= h((string)$item['href']) ?>">
              <span><?= h((string)$item['label']) ?></span>
              <strong><?= h((string)$item['text']) ?></strong>
            </a>
          <?php endforeach; ?>
        </div>
      </aside>
    </section>

    <section class="tesoreria-workbench" aria-label="Panel operativo de tesoreria">
      <article class="tesoreria-panel">
        <div class="tesoreria-section-head">
          <span>Agenda de pagos</span>
          <strong>Proximos vencimientos</strong>
        </div>
        <div class="tesoreria-list">
          <?php if ($proximosVencimientos === []): ?>
            <p class="tesoreria-empty">No hay pagos pendientes dentro de los proximos 30 dias.</p>
          <?php else: ?>
            <?php foreach (array_slice($proximosVencimientos, 0, 4) as $ob): ?>
              <?php $saldoOb = max(0.0, (float)($ob['importe_estimado'] ?? 0) - (float)($ob['importe_pagado'] ?? 0)); ?>
              <a class="tesoreria-list-row" href="tesoreria_obligaciones.php?estado=PENDIENTE">
                <span>
                  <strong><?= h((string)($ob['descripcion'] ?? 'Obligacion')) ?></strong>
                  <small>Vence <?= h(date('d/m/Y', strtotime((string)($ob['fecha_vencimiento'] ?? 'now')))) ?></small>
                </span>
                <b><?= money_ar($saldoOb) ?></b>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </article>

      <article class="tesoreria-panel">
        <div class="tesoreria-section-head">
          <span>Fondos disponibles</span>
          <strong>Cuentas principales</strong>
        </div>
        <div class="tesoreria-list">
          <?php if ($saldos === []): ?>
            <p class="tesoreria-empty">Todavia no hay cuentas financieras cargadas.</p>
          <?php else: ?>
            <?php foreach (array_slice($saldos, 0, 4) as $cuenta): ?>
              <a class="tesoreria-list-row" href="tesoreria_cuentas.php">
                <span>
                  <strong><?= h((string)$cuenta['nombre']) ?></strong>
                  <small><?= h((string)$cuenta['tipo']) ?>, <?= h((string)($cuenta['sucursal_nombre'] ?? 'General')) ?></small>
                </span>
                <b><?= money_ar((float)($cuenta['saldo_actual'] ?? 0)) ?></b>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </article>
    </section>

    <section class="tesoreria-actions" aria-label="Acciones de tesoreria">
      <a class="tesoreria-action tesoreria-action--primary" href="tesoreria_movimientos.php">
        <span>Operar</span>
        <strong>Registrar movimiento</strong>
        <small>Ingresos, egresos o transferencias entre cuentas.</small>
      </a>
      <a class="tesoreria-action" href="tesoreria_obligaciones.php">
        <span>Controlar</span>
        <strong>Obligaciones</strong>
        <small>Pagos pendientes, vencidas y cancelaciones.</small>
      </a>
      <a class="tesoreria-action" href="tesoreria_reportes.php">
        <span>Analizar</span>
        <strong>Reportes</strong>
        <small>Flujo, gastos por categoria y vencimientos.</small>
      </a>
      <a class="tesoreria-action" href="cobranzas.php">
        <span>Cobrar</span>
        <strong>Cobranzas</strong>
        <small>Facturas por cobrar y recibos internos.</small>
      </a>
      <div class="tesoreria-settings">
        <span>Configuracion</span>
        <a href="tesoreria_cuentas.php">Cuentas</a>
        <a href="tesoreria_categorias.php">Categorias</a>
      </div>
    </section>

    <div class="tesoreria-section-head tesoreria-section-head--table">
      <span>Detalle</span>
      <strong>Saldos por cuenta</strong>
    </div>
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

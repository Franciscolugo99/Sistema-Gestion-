<?php
declare(strict_types=1);

// Layout ÚNICO (el de siempre)
require __DIR__ . '/../public/partials/header.php';
?>

<div class="panel ventas-panel">

  <header class="ventas-top">
    <div class="ventas-top-left">
      <h1 class="ventas-title">Ventas</h1>
      <p class="ventas-sub">
        Mostrando <b><?= (int)$fromRow ?>–<?= (int)$toRow ?></b> de <b><?= (int)$totalRows ?></b>
        • Página <b><?= (int)$page ?></b> / <b><?= (int)$totalPages ?></b>
      </p>
    </div>

    <div class="ventas-top-right">
      <div class="paper-box">
        <span class="paper-label">Papel</span>
        <select id="paperSel">
          <option value="80">80 mm</option>
          <option value="58">58 mm</option>
        </select>
      </div>

      <a class="v-btn v-btn--outline"
         href="<?= h(urlWith(['export' => 'csv', 'page' => 1])) ?>">
        Exportar CSV
      </a>

      <button id="btnScrollTop" class="v-btn v-btn--icon" type="button">↑</button>
    </div>
  </header>

  <?php if (($promosActivas ?? 0) > 0): ?>
    <div class="alert alert-promo">
      💡 <?= (int)$promosActivas ?>
      promoción<?= $promosActivas > 1 ? 'es' : '' ?>
      activa<?= $promosActivas > 1 ? 's' : '' ?> hoy
    </div>
  <?php endif; ?>

  <div class="stats-row ventas-kpis">
    <div class="kpi">
      <div class="kpi-label">Ventas</div>
      <div class="kpi-value"><?= (int)($stats['cnt'] ?? 0) ?></div>
    </div>
    <div class="kpi">
      <div class="kpi-label">Facturación</div>
      <div class="kpi-value"><?= money_ar((float)($stats['sum_total'] ?? 0)) ?></div>
    </div>
    <div class="kpi">
      <div class="kpi-label">Ticket promedio</div>
      <div class="kpi-value"><?= money_ar((float)($stats['avg_total'] ?? 0)) ?></div>
    </div>
  </div>

  <?php include __DIR__ . '/../public/partials/ventas_filtros.php'; ?>

  <div class="table-wrapper">
    <table class="ventas-table">
      <thead>
        <tr>
          <th>Fecha</th>
          <th class="t-center">ID</th>
          <th class="t-center">Medio</th>
          <th class="t-center">Estado</th>
          <th class="t-right">Total</th>
          <th class="t-right">Pagado</th>
          <th class="t-right">Vuelto</th>
          <th class="t-center">Ítems</th>
          <th class="t-center">Factura</th>
          <th class="t-right">Acciones</th>
        </tr>
      </thead>

      <tbody>
      <?php if (empty($ventas)): ?>
        <tr>
          <td colspan="10" class="empty-cell">No se encontraron ventas.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($ventas as $v): ?>
          <?php
            $mp = strtoupper((string)($v['medio_pago'] ?? 'SIN_ESPECIFICAR'));
            $medioClass = strtolower(preg_replace('/[^a-z0-9\-_]/i', '', $mp) ?: 'sin_especificar');

            $stt = strtoupper((string)($v['estado'] ?? 'EMITIDA'));
            $estadoClass = ($stt === 'ANULADA') ? 'anulada' : 'emitida';

            $fe = strtoupper((string)($v['factura_estado'] ?? ''));
            if ($fe === '') {
              $factLabel = 'PENDIENTE';
              $factClass = 'pendiente';
            } elseif ($fe === 'ANULADA') {
              $factLabel = 'FACT. ANULADA';
              $factClass = 'anulada';
            } else {
              $factLabel = 'FACTURADA';
              $factClass = 'facturada';
            }
          ?>
          <tr>
            <td class="mono"><?= h((string)$v['fecha']) ?></td>
            <td class="t-center mono">#<?= (int)$v['id'] ?></td>

            <td class="t-center">
              <span class="badge badge-<?= h($medioClass) ?>"><?= h($mp) ?></span>
            </td>

            <td class="t-center">
              <span class="badge badge-estado badge-<?= h($estadoClass) ?>"><?= h($stt) ?></span>
            </td>

            <td class="t-right"><?= money_ar((float)$v['total']) ?></td>
            <td class="t-right"><?= money_ar((float)$v['monto_pagado']) ?></td>
            <td class="t-right"><?= money_ar((float)$v['vuelto']) ?></td>
            <td class="t-center"><?= (int)$v['items_count'] ?></td>

            <td class="t-center">
              <span class="badge badge-fact badge-<?= h($factClass) ?>"><?= h($factLabel) ?></span>
            </td>

            <td class="t-right">
              <div class="row-actions">
                <a class="btn-mini act-view"
                   href="ticket.php?venta_id=<?= (int)$v['id'] ?>&paper=80&preview=1">Ticket</a>

                <a class="btn-mini btn-mini-ok act-print"
                   href="ticket.php?venta_id=<?= (int)$v['id'] ?>&paper=80&autoprint=1"
                   target="_blank" rel="noopener">Imprimir</a>

                <a class="btn-mini btn-mini-ghost"
                   href="venta_detalle.php?id=<?= (int)$v['id'] ?>">Detalle</a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if (($totalPages ?? 1) > 1): ?>
    <div class="pager">
      <a class="pager-btn <?= $page <= 1 ? 'disabled' : '' ?>"
         href="<?= $page <= 1 ? '#' : h(urlWith(['page' => $page - 1])) ?>">← Anterior</a>

      <div class="pager-mid">Página <?= (int)$page ?>/<?= (int)$totalPages ?></div>

      <a class="pager-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"
         href="<?= $page >= $totalPages ? '#' : h(urlWith(['page' => $page + 1])) ?>">Siguiente →</a>
    </div>
  <?php endif; ?>

</div>

<script src="assets/js/ventas.js?v=2"></script>

<?php require __DIR__ . '/../public/partials/footer.php'; ?>

<?php
// Variables esperadas desde dashboard.php:
// $categoriaFiltro, $ventasRango, $ventasDelta, $sparklineVentas, $facturacionRango,
// $factDelta, $sparklineFacturacion, $ticketPromedio, $ticketDelta,
// $unidadesVendidasRango, $gananciaBruta, $margenPorcentaje, $totalCostos,
// $totalDescuentosPromos, $tasaAnulacion, $ventasAnuladas, $montoAnulado,
// $productosDormidos, $capitalDormido, $diasSinMovimiento, $from, $to
?>
<h2 class="section-title">Metricas de ventas <?= $categoriaFiltro ? '<span class="section-filter-badge">' . h($categoriaFiltro) . '</span>' : '' ?></h2>
<div class="dash-kpi-row">
  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-label"><span class="stat-icon"></span> Ventas</span>
      <button type="button" class="kpi-help" data-tooltip="ventas" aria-label="Ayuda sobre Ventas" aria-expanded="false">?</button>
    </div>
    <div class="stat-value"><?= number_format($ventasRango, 0, ',', '.') ?></div>
    <div class="stat-footer">
      <?php if (!empty($ventasDelta['text'])): ?>
      <div class="kpi-delta <?= h($ventasDelta['class']) ?>" title="<?= h($ventasDelta['title'] ?? '') ?>"><?= h($ventasDelta['text']) ?></div>
      <?php endif; ?>
    </div>
    <canvas class="mini-sparkline" role="img" aria-label="Tendencia de ventas ultimos 7 dias" data-values='<?= json_encode($sparklineVentas) ?>'></canvas>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-label"><span class="stat-icon"></span> Facturacion</span>
      <button type="button" class="kpi-help" data-tooltip="facturacion" aria-label="Ayuda sobre Facturacion" aria-expanded="false">?</button>
    </div>
    <div class="stat-value">$ <?= number_format($facturacionRango, 0, ',', '.') ?></div>
    <div class="stat-footer">
      <?php if (!empty($factDelta['text'])): ?>
      <div class="kpi-delta <?= h($factDelta['class']) ?>" title="<?= h($factDelta['title'] ?? '') ?>"><?= h($factDelta['text']) ?></div>
      <?php endif; ?>
    </div>
    <canvas class="mini-sparkline" role="img" aria-label="Tendencia de facturacion ultimos 7 dias" data-values='<?= json_encode($sparklineFacturacion) ?>'></canvas>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-label"><span class="stat-icon"></span> Ticket promedio</span>
      <button type="button" class="kpi-help" data-tooltip="ticket_promedio" aria-label="Ayuda sobre Ticket promedio" aria-expanded="false">?</button>
    </div>
    <div class="stat-value">$ <?= number_format($ticketPromedio, 0, ',', '.') ?></div>
    <div class="stat-footer">
      <?php if (!empty($ticketDelta['text'])): ?>
      <div class="kpi-delta <?= h($ticketDelta['class']) ?>" title="<?= h($ticketDelta['title'] ?? '') ?>"><?= h($ticketDelta['text']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-label"><span class="stat-icon"></span> Unidades vendidas</span>
      <button type="button" class="kpi-help" data-tooltip="unidades" aria-label="Ayuda sobre Unidades" aria-expanded="false">?</button>
    </div>
    <div class="stat-value"><?= h(format_qty_trim($unidadesVendidasRango)) ?></div>
  </div>
</div>

<h2 class="section-title">Analisis de rentabilidad <?= $categoriaFiltro ? '<span class="section-filter-badge">' . h($categoriaFiltro) . '</span>' : '' ?></h2>
<div class="dash-kpi-row">
  <div class="stat-card <?= $gananciaBruta >= 0 ? 'stat-ok' : 'stat-sin' ?>">
    <div class="stat-header">
      <span class="stat-label"><span class="stat-icon"></span> Ganancia bruta</span>
      <button type="button" class="kpi-help" data-tooltip="ganancia" aria-label="Ayuda sobre Ganancia" aria-expanded="false">?</button>
    </div>
    <div class="stat-value">$ <?= number_format($gananciaBruta, 0, ',', '.') ?></div>
    <?php if ($gananciaBruta < 0): ?>
    <span class="kpi-status kpi-status-critical">Perdida</span>
    <?php endif; ?>
  </div>

  <?php $margenStatus = getKpiStatus('margen', $margenPorcentaje); ?>
  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-label"><span class="stat-icon"></span> Margen</span>
      <button type="button" class="kpi-help" data-tooltip="margen" aria-label="Ayuda sobre Margen" aria-expanded="false">?</button>
    </div>
    <div class="stat-value"><?= number_format($margenPorcentaje, 1) ?>%</div>
    <?php if ($margenStatus['text']): ?>
    <span class="kpi-status <?= $margenStatus['class'] ?>"><?= $margenStatus['text'] ?></span>
    <?php endif; ?>
  </div>

  <div class="stat-card stat-bajo">
    <div class="stat-header">
      <span class="stat-label"><span class="stat-icon"></span> Total costos</span>
      <button type="button" class="kpi-help" data-tooltip="costos" aria-label="Ayuda sobre Costos" aria-expanded="false">?</button>
    </div>
    <div class="stat-value">$ <?= number_format($totalCostos, 0, ',', '.') ?></div>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-label"><span class="stat-icon"></span> Descuentos (promos)
        <?php if ($categoriaFiltro): ?>
          <span class="stat-global-badge" title="Este dato es global, no se filtra por categoria">global</span>
        <?php endif; ?>
      </span>
      <button type="button" class="kpi-help" data-tooltip="descuentos" aria-label="Ayuda sobre Descuentos" aria-expanded="false">?</button>
    </div>
    <div class="stat-value">$ <?= number_format($totalDescuentosPromos, 0, ',', '.') ?></div>
  </div>
</div>

<h2 class="section-title">Control de anulaciones <?= $categoriaFiltro ? '<span class="section-filter-badge">' . h($categoriaFiltro) . '</span>' : '' ?></h2>
<div class="dash-kpi-row">
  <?php $anulacionStatus = getKpiStatus('tasa_anulacion', $tasaAnulacion); ?>
  <div class="stat-card <?= $tasaAnulacion > 5 ? 'stat-sin' : 'stat-ok' ?>">
    <div class="stat-header">
      <span class="stat-label"><span class="stat-icon"></span> Ventas anuladas</span>
      <button type="button" class="kpi-help" data-tooltip="anulaciones" aria-label="Ayuda sobre Anulaciones" aria-expanded="false">?</button>
    </div>
    <div class="stat-value"><?= (int)$ventasAnuladas ?></div>
  </div>

  <div class="stat-card">
    <div class="stat-header">
      <span class="stat-label"><span class="stat-icon"></span> Tasa de anulacion</span>
      <button type="button" class="kpi-help" data-tooltip="tasa_anulacion" aria-label="Ayuda sobre Tasa" aria-expanded="false">?</button>
    </div>
    <div class="stat-value"><?= number_format($tasaAnulacion, 1) ?>%</div>
    <?php if ($anulacionStatus['text']): ?>
    <span class="kpi-status <?= $anulacionStatus['class'] ?>"><?= $anulacionStatus['text'] ?></span>
    <?php endif; ?>
  </div>

  <div class="stat-card stat-bajo">
    <div class="stat-header">
      <span class="stat-label"><span class="stat-icon"></span> Monto anulado</span>
      <button type="button" class="kpi-help" data-tooltip="monto_anulado" aria-label="Ayuda sobre Monto anulado" aria-expanded="false">?</button>
    </div>
    <div class="stat-value">$ <?= number_format($montoAnulado, 0, ',', '.') ?></div>
  </div>
</div>

<?php if (!empty($productosDormidos)): ?>
<div class="dormidos-section">
  <div class="dormidos-header">
    <h2 class="section-title">Productos dormidos <span class="dormidos-count"><?= count($productosDormidos) ?> productos</span></h2>
    <div class="dormidos-capital">
      <span class="dormidos-capital-label">Capital parado:</span>
      <span class="dormidos-capital-value">$ <?= number_format($capitalDormido, 0, ',', '.') ?></span>
    </div>
  </div>
  <p class="dormidos-desc">Productos con stock pero sin ventas en los ultimos <?= $diasSinMovimiento ?> dias. Considera promociones o liquidacion.</p>

  <div class="dormidos-table-wrap">
    <table class="dormidos-table">
      <thead>
        <tr>
          <th>Producto</th>
          <th>Stock</th>
          <th>Precio</th>
          <th>Valor en stock</th>
          <th>Ultima venta</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (array_slice($productosDormidos, 0, 10) as $pd): ?>
        <tr>
          <td><?= h((string)($pd['nombre'] ?? '-')) ?></td>
          <td><?= h(format_qty_trim((float)($pd['stock'] ?? 0))) ?></td>
          <td>$ <?= number_format((float)($pd['precio'] ?? 0), 0, ',', '.') ?></td>
          <td>$ <?= number_format((float)($pd['valor_stock'] ?? 0), 0, ',', '.') ?></td>
          <td>
            <?php if (!empty($pd['ultima_venta'])): ?>
              <?= h(date('d/m/Y', strtotime((string)$pd['ultima_venta']))) ?>
            <?php else: ?>
              Nunca
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (count($productosDormidos) > 10): ?>
  <p class="dormidos-more">Y <?= count($productosDormidos) - 10 ?> productos mas... <a href="dashboard_export.php?type=dormidos&from=<?= h($from) ?>&to=<?= h($to) ?>">Exportar lista completa</a></p>
  <?php endif; ?>
</div>
<?php endif; ?>

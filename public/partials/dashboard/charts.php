<?php
// Variables esperadas desde dashboard.php:
// $categoriaFiltro, $ventasLabels, $ventasData, $topProductosLabels, $topProductosData,
// $metodosPago, $categorias, $ventasPorHora, $productosRentables, $stockCritico
?>
<div class="dash-grid">
  <div class="dash-card">
    <div class="dash-card-header">
      <h2>Ventas por dia</h2>
      <span class="dash-card-sub">Evolucion en el rango</span>
    </div>
    <div class="chart-wrap">
      <canvas id="chartVentas" role="img" tabindex="0" aria-label="Ventas por dia (cantidad de tickets)" aria-describedby="chartVentasData"></canvas>
      <div id="noVentasMsg" class="chart-empty" style="display:none;">No hay ventas en el rango</div>
    </div>

    <details class="chart-data" id="chartVentasData">
      <summary>Ver datos</summary>
      <div class="chart-data-inner">
        <table class="chart-data-table">
          <thead><tr><th>Dia</th><th>Ventas</th></tr></thead>
          <tbody>
            <?php foreach ($ventasLabels as $i => $dia): ?>
              <tr>
                <td><?= h((string)$dia) ?></td>
                <td><?= (int)($ventasData[$i] ?? 0) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  </div>

  <div class="dash-card">
    <div class="dash-card-header">
      <h2>Top productos</h2>
      <span class="dash-card-sub">Mas vendidos <?= $categoriaFiltro ? '(' . h($categoriaFiltro) . ')' : '' ?></span>
    </div>
    <div class="chart-wrap">
      <canvas id="chartTopProductos" role="img" tabindex="0" aria-label="Top productos mas vendidos" aria-describedby="chartTopProductosData"></canvas>
      <div id="noTopMsg" class="chart-empty" style="display:none;">Sin datos</div>
    </div>

    <details class="chart-data" id="chartTopProductosData">
      <summary>Ver datos</summary>
      <div class="chart-data-inner">
        <table class="chart-data-table">
          <thead><tr><th>Producto</th><th>Unidades</th></tr></thead>
          <tbody>
            <?php foreach ($topProductosLabels as $i => $nombre): ?>
              <tr>
                <td><?= h((string)$nombre) ?></td>
                <td><?= h(format_qty_trim((float)($topProductosData[$i] ?? 0))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  </div>

  <div class="dash-card">
    <div class="dash-card-header">
      <h2>Metodos de pago</h2>
      <span class="dash-card-sub">Distribucion</span>
    </div>
    <div class="chart-wrap chart-wrap-donut">
      <canvas id="chartMetodosPago" role="img" tabindex="0" aria-label="Distribucion por metodos de pago" aria-describedby="chartMetodosPagoData"></canvas>
    </div>

    <details class="chart-data" id="chartMetodosPagoData">
      <summary>Ver datos</summary>
      <div class="chart-data-inner">
        <table class="chart-data-table">
          <thead><tr><th>Metodo</th><th>Monto</th></tr></thead>
          <tbody>
<?php
  $mp = $metodosPago ?? [];
  $isList = is_array($mp) && isset($mp[0]) && is_array($mp[0]) && array_key_exists('medio_pago', $mp[0]);

  if ($isList) {
    foreach ($mp as $row) {
      $met = (string)($row['medio_pago'] ?? 'N/A');
      $monto = (float)($row['monto'] ?? 0);
      ?>
      <tr>
        <td><?= h($met) ?></td>
        <td>$ <?= number_format($monto, 0, ',', '.') ?></td>
      </tr>
      <?php
    }
  } else {
    foreach ($mp as $met => $monto) {
      ?>
      <tr>
        <td><?= h((string)$met) ?></td>
        <td>$ <?= number_format((float)$monto, 0, ',', '.') ?></td>
      </tr>
      <?php
    }
  }

  if (empty($mp)) {
    ?>
    <tr><td colspan="2" class="muted">Sin datos</td></tr>
    <?php
  }
?>
          </tbody>
        </table>
      </div>
    </details>
  </div>

  <div class="dash-card">
    <div class="dash-card-header">
      <h2>Ventas por categoria</h2>
    </div>
    <div class="chart-wrap">
      <canvas id="chartCategorias" role="img" tabindex="0" aria-label="Ventas por categoria" aria-describedby="chartCategoriasData"></canvas>
    </div>

    <details class="chart-data" id="chartCategoriasData">
      <summary>Ver datos</summary>
      <div class="chart-data-inner">
        <table class="chart-data-table">
          <thead><tr><th>Categoria</th><th>Monto</th></tr></thead>
          <tbody>
<?php
  $cats = $categorias ?? [];
  $isList = is_array($cats) && isset($cats[0]) && is_array($cats[0]) && array_key_exists('categoria', $cats[0]);

  if ($isList) {
    foreach ($cats as $row) {
      $catRaw = (string)($row['categoria'] ?? '');
      $cat = (trim($catRaw) !== '') ? $catRaw : 'Sin categoria';
      $monto = (float)($row['ventas'] ?? 0);
      ?>
      <tr>
        <td><?= h($cat) ?></td>
        <td>$ <?= number_format($monto, 0, ',', '.') ?></td>
      </tr>
      <?php
    }
  } else {
    foreach ($cats as $cat => $monto) {
      ?>
      <tr>
        <td><?= h((string)$cat) ?></td>
        <td>$ <?= number_format((float)$monto, 0, ',', '.') ?></td>
      </tr>
      <?php
    }
  }

  if (empty($cats)) {
    ?>
    <tr><td colspan="2" class="muted">Sin datos</td></tr>
    <?php
  }
?>
          </tbody>
        </table>
      </div>
    </details>
  </div>

  <div class="dash-card dash-card-wide">
    <div class="dash-card-header">
      <h2>Horarios pico</h2>
      <span class="dash-card-sub">Distribucion por hora</span>
    </div>
    <div class="chart-wrap chart-wrap-wide">
      <canvas id="chartHorarios" role="img" tabindex="0" aria-label="Distribucion de ventas por hora" aria-describedby="chartHorariosData"></canvas>
    </div>

    <details class="chart-data" id="chartHorariosData">
      <summary>Ver datos</summary>
      <div class="chart-data-inner">
        <table class="chart-data-table">
          <thead><tr><th>Hora</th><th>Ventas</th><th>Monto</th></tr></thead>
          <tbody>
            <?php foreach (($ventasPorHora ?? []) as $h => $row): ?>
              <tr>
                <td><?= sprintf('%02d:00', (int)($row['hora'] ?? $h)) ?></td>
                <td><?= (int)($row['cantidad'] ?? 0) ?></td>
                <td>$ <?= number_format((float)($row['monto'] ?? 0), 0, ',', '.') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  </div>

  <div class="dash-card dash-card-wide">
    <div class="dash-card-header">
      <h2>Productos mas rentables</h2>
      <span class="dash-card-sub">Top 5 por ganancia</span>
    </div>
    <div class="chart-wrap chart-wrap-wide">
      <canvas id="chartRentables" role="img" tabindex="0" aria-label="Top productos mas rentables por ganancia" aria-describedby="chartRentablesData"></canvas>
    </div>

    <details class="chart-data" id="chartRentablesData">
      <summary>Ver datos</summary>
      <div class="chart-data-inner">
        <table class="chart-data-table">
          <thead><tr><th>Producto</th><th>Ganancia</th></tr></thead>
          <tbody>
            <?php foreach (($productosRentables ?? []) as $r): ?>
              <tr>
                <td><?= h((string)($r['nombre'] ?? '')) ?></td>
                <td>$ <?= number_format((float)($r['ganancia'] ?? 0), 0, ',', '.') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  </div>
</div>

<?php if (!empty($stockCritico)): ?>
  <h2 class="section-title">Stock critico <span class="stock-count"><?= count($stockCritico) ?> productos</span></h2>
  <div class="stock-table-wrap">
    <table class="stock-table">
      <thead>
        <tr>
          <th>Producto</th>
          <th>Stock</th>
          <th>Minimo</th>
          <th>Dias restantes</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($stockCritico as $item): ?>
          <?php $dr = (int)($item['dias_restantes'] ?? 999); ?>
          <tr class="<?= $dr < 3 ? 'urgente' : ($dr < 7 ? 'advertencia' : '') ?>">
            <td><?= h((string)$item['nombre']) ?></td>
            <td><?= h(format_qty_trim((float)$item['stock'])) ?></td>
            <td><?= h(format_qty_trim((float)$item['stock_minimo'])) ?></td>
            <td>
              <?php if ($dr < 999): ?>
                ~<?= $dr ?> dias
              <?php else: ?>
                Sin datos
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

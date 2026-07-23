<?php
// Variables esperadas desde dashboard.php:
// $toastMessage, $toastFrom, $toastTo, $categoriasDisponibles, $categoriaFiltro,
// $from, $to, $horaDesde, $horaHasta, $cierreCajaHoy, $ventasData, $ventasLabels,
// $ventasDelta, $productosRentables, $tasaAnulacion, $ventasAnuladas, $ventasRango,
// $capitalDormido, $productosDormidos, $stockCritico, $margenPorcentaje, $totalVentas
?>
<div id="dashToast" class="flus-toast" style="display:none;" role="status" aria-live="polite" aria-atomic="true"
  data-message="<?= h($toastMessage) ?>"
  data-from="<?= h($toastFrom) ?>"
  data-to="<?= h($toastTo) ?>"></div>

<div class="page-wrap">
  <div class="panel dashboard-panel">
    <header class="dash-header module-header">
      <div class="module-header-main">
        <div class="module-header-hero">
          <span class="module-header-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
              <path d="M4 19h16"/>
              <path d="M7 16V9"/>
              <path d="M12 16V5"/>
              <path d="M17 16v-7"/>
            </svg>
          </span>
          <div class="module-header-copy">
            <span class="module-eyebrow">Vision operativa</span>
            <h1 class="dash-title page-title module-title">Panel de control</h1>
            <p class="dash-sub page-sub module-subtitle">Analisis completo de ventas, rentabilidad y operaciones</p>
          </div>
        </div>
      </div>
      <div class="dash-header-meta module-header-meta">
        <span class="module-meta-pill">Hoy: <?= date('d/m/Y'); ?></span>
      </div>
    </header>

    <form id="dashFilters" class="dash-filters" method="get" action="dashboard.php">
      <div class="dash-presets">
        <button type="button" class="dash-chip" data-preset="today" aria-pressed="false" aria-label="Filtrar: Hoy">Hoy</button>
        <button type="button" class="dash-chip" data-preset="7d" aria-pressed="false" aria-label="Filtrar: Ultimos 7 dias">7d</button>
        <button type="button" class="dash-chip" data-preset="30d" aria-pressed="false" aria-label="Filtrar: Ultimos 30 dias">30d</button>
        <button type="button" class="dash-chip" data-preset="month" aria-pressed="false" aria-label="Filtrar: Este mes">Este mes</button>

        <?php if (!empty($categoriasDisponibles)): ?>
        <div class="dash-cat-filter">
          <select name="categoria" id="dashCategoria" class="dash-select">
            <option value="">Todas las categorias</option>
            <?php foreach ($categoriasDisponibles as $cat): ?>
              <option value="<?= h($cat) ?>" <?= $categoriaFiltro === $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <details id="dashExportDD" class="dash-export-dd">
          <summary aria-label="Abrir opciones de exportacion">Exportar</summary>
          <div class="dash-export-dd-menu">
            <a class="dash-export" data-export-type="kpis" href="dashboard_export.php?type=kpis&from=<?= h($from) ?>&to=<?= h($to) ?>">KPIs</a>
            <a class="dash-export" data-export-type="movimientos" href="dashboard_export.php?type=movimientos&from=<?= h($from) ?>&to=<?= h($to) ?>">Movimientos</a>
            <a class="dash-export" data-export-type="top_productos" href="dashboard_export.php?type=top_productos&from=<?= h($from) ?>&to=<?= h($to) ?>">Top productos</a>
            <a class="dash-export" data-export-type="metodos_pago" href="dashboard_export.php?type=metodos_pago&from=<?= h($from) ?>&to=<?= h($to) ?>">Medios de pago</a>
            <a class="dash-export" data-export-type="categorias" href="dashboard_export.php?type=categorias&from=<?= h($from) ?>&to=<?= h($to) ?>">Categorias</a>
            <a class="dash-export" data-export-type="rentables" href="dashboard_export.php?type=rentables&from=<?= h($from) ?>&to=<?= h($to) ?>">Rentables</a>
            <a class="dash-export" data-export-type="dormidos" href="dashboard_export.php?type=dormidos&from=<?= h($from) ?>&to=<?= h($to) ?>">Productos dormidos</a>
          </div>
        </details>
      </div>

      <div class="dash-range">
        <div class="dash-range-controls">
          <label>
            <span>Desde</span>
            <input type="date" id="dashFrom" name="from" value="<?= h($from) ?>" />
          </label>
          <label>
            <span>Hasta</span>
            <input type="date" id="dashTo" name="to" value="<?= h($to) ?>" />
          </label>
          <label class="dash-hora-label">
            <span>Hora desde</span>
            <div class="dash-hora-row">
              <input type="time" id="dashHoraDesde" name="hora_desde" value="<?= h($horaDesde ?? '') ?>" class="dash-hora-input" />
            </div>
          </label>
          <label class="dash-hora-label">
            <span>Hora hasta</span>
            <div class="dash-hora-row">
              <input type="time" id="dashHoraHasta" name="hora_hasta" value="<?= h($horaHasta ?? '') ?>" class="dash-hora-input" />
            </div>
          </label>
          <?php if ($horaDesde || $horaHasta): ?>
          <button type="button" class="dash-clear-hours" data-dash-clear-hours title="Limpiar filtro de horas">x</button>
          <?php endif; ?>
          <button type="submit" class="dash-apply">Aplicar</button>
        </div>
        <div class="dash-range-hint">
          <?php if ($categoriaFiltro): ?>
            <span class="dash-filter-badge">Categoria: <?= h($categoriaFiltro) ?></span>
          <?php endif; ?>
          <?php if ($horaDesde || $horaHasta): ?>
            <span class="dash-filter-badge">Horario: <?= h($horaDesde ?? '00:00') ?> - <?= h($horaHasta ?? '23:59') ?></span>
          <?php endif; ?>
          Rango: <strong><?= (new DateTime($from))->format('d/m/Y'); ?></strong> -> <strong><?= (new DateTime($to))->format('d/m/Y'); ?></strong>
        </div>
      </div>
    </form>

    <?php if ($categoriaFiltro || $horaDesde || $horaHasta): ?>
    <div class="dash-filter-banner">
      <div class="dash-filter-banner-content">
        <span class="dash-filter-banner-icon">Filtro</span>
        <span class="dash-filter-banner-text">
          <strong>Datos filtrados:</strong>
          <?php if ($categoriaFiltro): ?>
            Categoria <em>"<?= h($categoriaFiltro) ?>"</em>
          <?php endif; ?>
          <?php if ($horaDesde || $horaHasta): ?>
            <?= $categoriaFiltro ? ' | ' : '' ?>
            Horario <?= h($horaDesde ?? '00:00') ?> - <?= h($horaHasta ?? '23:59') ?>
          <?php endif; ?>
        </span>
        <a href="dashboard.php?from=<?= h($from) ?>&to=<?= h($to) ?>" class="dash-filter-banner-clear" title="Quitar filtros">Limpiar filtros</a>
      </div>
    </div>
    <?php endif; ?>

    <div class="cierre-caja-section" aria-labelledby="dashboardCierreTitle">
      <div class="cierre-caja-header">
        <div class="cierre-caja-title-row">
          <h2 id="dashboardCierreTitle" class="section-title">Resumen de hoy</h2>
          <span class="cierre-caja-note" title="Este bloque no usa el filtro de fechas del panel">No usa filtro</span>
        </div>
        <span class="cierre-caja-horario">
          <?php if ($cierreCajaHoy['primera_venta']): ?>
            <?= (new DateTime($cierreCajaHoy['primera_venta']))->format('H:i') ?> - <?= (new DateTime($cierreCajaHoy['ultima_venta']))->format('H:i') ?>
          <?php else: ?>
            Sin ventas
          <?php endif; ?>
        </span>
      </div>

      <div class="cierre-caja-grid">
        <div class="cierre-caja-card cierre-main">
          <div class="cierre-content">
            <span class="cierre-label">Total del dia</span>
            <span class="cierre-value">$ <?= number_format($cierreCajaHoy['monto_total'], 0, ',', '.') ?></span>
            <span class="cierre-sub"><?= $cierreCajaHoy['total_ventas'] ?> ventas, ticket prom. $ <?= number_format($cierreCajaHoy['ticket_promedio'], 0, ',', '.') ?></span>
          </div>
        </div>

        <div class="cierre-caja-card cierre-efectivo">
          <div class="cierre-content">
            <span class="cierre-label">Efectivo en caja</span>
            <span class="cierre-value">$ <?= number_format($cierreCajaHoy['efectivo'], 0, ',', '.') ?></span>
            <span class="cierre-sub">Base para arqueo</span>
          </div>
        </div>

        <div class="cierre-caja-card cierre-otros">
          <div class="cierre-content">
            <span class="cierre-label">Otros medios</span>
            <span class="cierre-value">$ <?= number_format($cierreCajaHoy['otros_medios'], 0, ',', '.') ?></span>
            <span class="cierre-sub">Tarjetas, QR y transferencias</span>
          </div>
        </div>

        <?php if ($cierreCajaHoy['anulaciones'] > 0): ?>
        <div class="cierre-caja-card cierre-anulaciones">
          <div class="cierre-content">
            <span class="cierre-label">Anulaciones</span>
            <span class="cierre-value"><?= $cierreCajaHoy['anulaciones'] ?></span>
            <span class="cierre-sub">$ <?= number_format($cierreCajaHoy['monto_anulado'], 0, ',', '.') ?> anulado</span>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <?php if (!empty($cierreCajaHoy['desglose_medios'])): ?>
      <details class="cierre-desglose">
        <summary>Desglose por medio de pago</summary>
        <div class="cierre-desglose-grid">
          <?php foreach ($cierreCajaHoy['desglose_medios'] as $medio): ?>
          <div class="cierre-desglose-item">
            <span class="cierre-desglose-medio"><?= h($medio['medio_pago']) ?></span>
            <span class="cierre-desglose-monto">$ <?= number_format((float)$medio['monto'], 0, ',', '.') ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </details>
      <?php endif; ?>
    </div>

    <div class="insights-container">
      <div class="dashboard-section-head">
        <div>
          <h2 class="section-title">Lecturas del periodo</h2>
          <p>Se calculan con los filtros activos del panel.</p>
        </div>
      </div>
      <div class="insights-grid">
        <?php
          $insights = [];

          if (!empty($ventasData)) {
            $maxVentas = max($ventasData);
            $maxIdx = array_search($maxVentas, $ventasData, true);
            if ($maxIdx !== false && isset($ventasLabels[$maxIdx])) {
              $mejorDia = (new DateTime($ventasLabels[$maxIdx]))->format('d/m');
              $diaSemana = ['Domingo','Lunes','Martes','Miercoles','Jueves','Viernes','Sabado'][(int)(new DateTime($ventasLabels[$maxIdx]))->format('w')];
              $insights[] = [
                'class' => 'info',
                'label' => 'Pico',
                'html' => 'Tu mejor dia fue el <strong>' . h($mejorDia) . '</strong> (' . $diaSemana . ') con <strong>' . (int)$maxVentas . ' ventas</strong>',
                'tip' => 'Revisa stock y cobertura para ese dia.'
              ];
            }
          }

          if (($ventasDelta['class'] ?? '') === 'kpi-up') {
            $insights[] = ['class' => 'good', 'label' => 'Tendencia', 'html' => 'Ventas crecieron <strong>' . h($ventasDelta['text']) . '</strong> vs periodo anterior', 'tip' => 'Identifica que productos o turnos empujaron la suba.'];
          } elseif (($ventasDelta['class'] ?? '') === 'kpi-down') {
            $insights[] = ['class' => 'warn', 'label' => 'Tendencia', 'html' => 'Ventas bajaron <strong>' . h($ventasDelta['text']) . '</strong> vs periodo anterior', 'tip' => 'Revisa faltantes, precios y horarios flojos.'];
          }

          if (!empty($productosRentables)) {
            $top = $productosRentables[0];
            $nombre = h((string)($top['nombre'] ?? 'Producto'));
            $ganancia = number_format((float)($top['ganancia'] ?? 0), 0, ',', '.');
            $insights[] = ['class' => 'good', 'label' => 'Margen', 'html' => "<strong>{$nombre}</strong> es tu producto mas rentable (<strong>$ {$ganancia}</strong>)", 'tip' => 'Asegura reposicion y exhibicion.'];
          }

          if ($tasaAnulacion > 5) {
            $insights[] = ['class' => 'danger', 'label' => 'Control', 'html' => 'Tasa de anulacion alta: <strong>' . h(number_format($tasaAnulacion, 1)) . '%</strong>', 'tip' => 'Revisa permisos, cajeros y motivos de anulacion.'];
          } elseif ($ventasAnuladas === 0 && $ventasRango > 10) {
            $insights[] = ['class' => 'good', 'label' => 'Control', 'html' => '<strong>0 anulaciones</strong> en el periodo', 'tip' => 'Buen indicador de operacion estable.'];
          }

          if ($capitalDormido > 0) {
            $countDormidos = count($productosDormidos);
            $insights[] = [
              'class' => 'warn',
              'label' => 'Stock',
              'html' => "<strong>{$countDormidos} productos</strong> sin movimiento en 30 dias. Capital parado: <strong>$ " . number_format($capitalDormido, 0, ',', '.') . "</strong>",
              'tip' => 'Evalua promocion, exhibicion o baja de compra.'
            ];
          }

          if (count($stockCritico) > 5) {
            $insights[] = ['class' => 'danger', 'label' => 'Reposicion', 'html' => '<strong>' . count($stockCritico) . ' productos</strong> con stock critico', 'tip' => 'Prioriza los que mas rotan antes de vender sin stock.'];
          }

          if (empty($insights)) {
            echo "<div class='insight-item insight-empty'><span class='insight-label'>Estado</span><strong>Sin alertas relevantes</strong><span class='insight-tip'>El periodo no muestra desvios importantes con los datos actuales.</span></div>";
          } else {
            foreach ($insights as $in) {
              $class = preg_replace('/[^a-z0-9_-]/i', '', (string)($in['class'] ?? 'info'));
              echo "<div class='insight-item insight-{$class}'>";
              echo "<span class='insight-label'>" . h((string)($in['label'] ?? 'Lectura')) . "</span>";
              echo "<div class='insight-main'>{$in['html']}</div>";
              if (!empty($in['tip'])) {
                echo "<span class='insight-tip'>" . h((string)$in['tip']) . "</span>";
              }
              echo "</div>";
            }
          }
        ?>
      </div>
    </div>

    <?php
      $acciones = [];

      if ($margenPorcentaje < 20 && $totalVentas > 0) {
        $acciones[] = [
          'level' => 'warn',
          'area' => 'Margen',
          'title' => 'Revisar margenes',
          'desc' => 'El margen esta por debajo del 20%. Revisar costos y precios de los productos principales.',
          'link' => 'productos.php',
          'linkText' => 'Ver productos'
        ];
      }

      if (count($stockCritico) > 3) {
        $acciones[] = [
          'level' => 'danger',
          'area' => 'Stock',
          'title' => 'Reponer stock',
          'desc' => count($stockCritico) . ' productos estan por debajo del minimo.',
          'link' => 'stock.php',
          'linkText' => 'Ir a stock'
        ];
      }

      if (count($productosDormidos) > 5) {
        $acciones[] = [
          'level' => 'warn',
          'area' => 'Capital',
          'title' => 'Liquidar productos parados',
          'desc' => '$ ' . number_format($capitalDormido, 0, ',', '.') . ' en mercaderia sin movimiento reciente.',
          'link' => 'promos.php',
          'linkText' => 'Crear promocion'
        ];
      }

      if ($tasaAnulacion > 5) {
        $acciones[] = [
          'level' => 'danger',
          'area' => 'Control',
          'title' => 'Investigar anulaciones',
          'desc' => 'Tasa de anulacion del ' . number_format($tasaAnulacion, 1) . '%. Revisar ventas anuladas y permisos.',
          'link' => 'ventas.php?estado=ANULADA',
          'linkText' => 'Ver anuladas'
        ];
      }

      if ($ventasDelta['class'] === 'kpi-down') {
        $acciones[] = [
          'level' => 'info',
          'area' => 'Ventas',
          'title' => 'Ventas en baja',
          'desc' => 'Comparar turnos, categorias y productos contra el periodo anterior.',
          'link' => 'promos.php',
          'linkText' => 'Ver promociones'
        ];
      }
    ?>

    <?php if (!empty($acciones)): ?>
    <div class="dash-actions-section">
      <div class="dash-actions-header">
        <div>
          <h3>Proximas acciones</h3>
          <p>Prioridades operativas segun el periodo filtrado.</p>
        </div>
      </div>
      <div class="dash-actions-grid">
        <?php foreach ($acciones as $accion): ?>
        <?php $level = preg_replace('/[^a-z0-9_-]/i', '', (string)($accion['level'] ?? 'info')); ?>
        <div class="dash-action-item dash-action-<?= h($level) ?>">
          <div class="dash-action-content">
            <span class="dash-action-area"><?= h((string)($accion['area'] ?? 'Operacion')) ?></span>
            <div class="dash-action-title"><?= h($accion['title']) ?></div>
            <div class="dash-action-desc"><?= h($accion['desc']) ?></div>
            <?php if (!empty($accion['link'])): ?>
            <a href="<?= h($accion['link']) ?>" class="dash-action-link">
              <?= h($accion['linkText']) ?>
            </a>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

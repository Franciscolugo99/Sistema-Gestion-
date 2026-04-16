<?php
declare(strict_types=1);
// public/dashboard.php - DASHBOARD AVANZADO v4 (Con ayuda contextual)

require_once __DIR__ . '/../src/db_helpers.php';

require_once __DIR__ . '/bootstrap.php';
require_once FLUS_ROOT . '/src/Dashboard/Support.php';
require_once FLUS_ROOT . '/src/Dashboard/Filters.php';
require_once FLUS_ROOT . '/src/Dashboard/Cache.php';
require_once FLUS_ROOT . '/src/Dashboard/Metrics.php';

require_login();
require_permission('ver_reportes');


/* =========================
   DEFINICIONES DE AYUDA CONTEXTUAL
========================= */
$kpiTooltips = dashboard_kpi_tooltips();

/* =========================
   HELPERS
========================= */
function format_qty_trim(float $n): string {
  $s = number_format($n, 3, ',', '.');
  $s = rtrim($s, '0');
  $s = rtrim($s, ',');
  return $s === '' ? '0' : $s;
}

// Funciï¿½n para determinar el estado del KPI
function getKpiStatus(string $type, float $value): array {
    switch ($type) {
        case 'margen':
            if ($value >= 35) return ['class' => 'kpi-status-excellent', 'text' => 'Excelente'];
            if ($value >= 25) return ['class' => 'kpi-status-good', 'text' => 'Bueno'];
            if ($value >= 15) return ['class' => 'kpi-status-attention', 'text' => 'Revisar'];
            return ['class' => 'kpi-status-critical', 'text' => 'Critico'];
            
        case 'tasa_anulacion':
            if ($value <= 2) return ['class' => 'kpi-status-excellent', 'text' => 'Excelente'];
            if ($value <= 5) return ['class' => 'kpi-status-good', 'text' => 'Normal'];
            if ($value <= 10) return ['class' => 'kpi-status-attention', 'text' => 'Revisar'];
            return ['class' => 'kpi-status-critical', 'text' => 'Critico'];
            
        default:
            return ['class' => '', 'text' => ''];
    }
}

/* =========================
   DETECCIONES (tablas/cols)
========================= */
$hasVentas       = flus_table_exists($pdo, 'ventas');
$hasVentaItems   = flus_table_exists($pdo, 'venta_items');
$hasProductos    = flus_table_exists($pdo, 'productos');
$hasMovimientos  = flus_table_exists($pdo, 'movimientos_stock');
$hasVentaPromos  = flus_table_exists($pdo, 'venta_promos');

$ventasFechaCol  = $hasVentas ? flus_first_existing_column($pdo, 'ventas', ['fecha','created_at','fecha_hora']) : null;
$ventasTotalCol  = $hasVentas ? flus_first_existing_column($pdo, 'ventas', ['total','monto_total','importe_total']) : null;
$ventasEstadoCol = $hasVentas ? flus_first_existing_column($pdo, 'ventas', ['estado','status']) : null;
$ventasMedioCol  = $hasVentas ? flus_first_existing_column($pdo, 'ventas', ['medio_pago','metodo_pago','pago_tipo']) : null;

$viVentaIdCol    = $hasVentaItems ? flus_first_existing_column($pdo, 'venta_items', ['venta_id']) : null;
$viProdIdCol     = $hasVentaItems ? flus_first_existing_column($pdo, 'venta_items', ['producto_id']) : null;
$viQtyCol        = $hasVentaItems ? flus_first_existing_column($pdo, 'venta_items', ['cantidad','qty']) : null;
$viLineCol       = $hasVentaItems ? flus_first_existing_column($pdo, 'venta_items', ['subtotal','total','importe']) : null;
$viPriceCol      = $hasVentaItems ? flus_first_existing_column($pdo, 'venta_items', ['precio_unitario','precio','unit_price']) : null;

$prodNombreCol   = $hasProductos ? flus_first_existing_column($pdo, 'productos', ['nombre','descripcion']) : null;
$prodCostoCol    = $hasProductos ? flus_first_existing_column($pdo, 'productos', ['costo','costo_unitario','cost']) : null;
$prodCatCol      = $hasProductos ? flus_first_existing_column($pdo, 'productos', ['categoria','rubro','familia']) : null;
$prodStockCol    = $hasProductos ? flus_first_existing_column($pdo, 'productos', ['stock']) : null;
$prodMinCol      = $hasProductos ? flus_first_existing_column($pdo, 'productos', ['stock_minimo','minimo','stock_min']) : null;
$prodActivoCol   = $hasProductos ? flus_first_existing_column($pdo, 'productos', ['activo','is_active']) : null;
$prodPrecioCol   = $hasProductos ? flus_first_existing_column($pdo, 'productos', ['precio','precio_venta','price']) : null;

$msFechaCol      = $hasMovimientos ? flus_first_existing_column($pdo, 'movimientos_stock', ['fecha','created_at']) : null;
$msTipoCol       = $hasMovimientos ? flus_first_existing_column($pdo, 'movimientos_stock', ['tipo']) : null;
$msProdIdCol     = $hasMovimientos ? flus_first_existing_column($pdo, 'movimientos_stock', ['producto_id']) : null;
$msCantCol       = $hasMovimientos ? flus_first_existing_column($pdo, 'movimientos_stock', ['cantidad']) : null;

/* Expr "importe de lï¿½nea" para prorratear neto (evita vi.subtotal inexistente) */
$lineExprForAlias = function(string $alias) use ($viLineCol, $viQtyCol, $viPriceCol): ?string {
  if ($viLineCol) return "{$alias}.`{$viLineCol}`";
  if ($viQtyCol && $viPriceCol) return "({$alias}.`{$viQtyCol}` * {$alias}.`{$viPriceCol}`)";
  return null;
};

/* =========================
   OBTENER CATEGORï¿½AS DISPONIBLES
========================= */
$categoriasDisponibles = [];
if ($hasProductos && $prodCatCol) {
  $stmt = $pdo->query("
    SELECT DISTINCT COALESCE(NULLIF(TRIM(`{$prodCatCol}`), ''), 'Sin categoria') AS categoria
    FROM productos
    ORDER BY categoria
  ");
  $categoriasDisponibles = $stmt->fetchAll(PDO::FETCH_COLUMN);
}


/* =========================
   RANGO DE FECHAS + FILTRO CATEGORï¿½A
========================= */
$filterState = dashboard_resolve_filters($_GET);
extract($filterState, EXTR_OVERWRITE);


$ventasDateSQL = ($hasVentas && $ventasFechaCol) ? "`{$ventasFechaCol}`" : "fecha";
$ventasTotalSQL = ($hasVentas && $ventasTotalCol) ? "`{$ventasTotalCol}`" : "total";

$ventasEmitidaCond = ($hasVentas && $ventasEstadoCol)
  ? " AND (`{$ventasEstadoCol}` IS NULL OR `{$ventasEstadoCol}` <> 'ANULADA') "
  : "";

$ventasAnuladaCond = ($hasVentas && $ventasEstadoCol)
  ? " AND `{$ventasEstadoCol}`='ANULADA' "
  : " AND 1=0 ";

$hasVentaPagos = flus_table_exists($pdo, 'venta_pagos');
$ventasVueltoCol = $hasVentas ? flus_first_existing_column($pdo, 'ventas', ['vuelto', 'cambio']) : null;

/* Condiciï¿½n de filtro por categorï¿½a */
$categoriaJoinCond = "";
$categoriaWhereCond = "";
$esSinCategoria = ($categoriaFiltro === 'Sin categoria');

// Genera condiciï¿½n SQL para filtro de categorï¿½a (maneja "Sin Categorï¿½a" como NULL/vacï¿½o)
// Retorna string SQL que puede insertarse directamente (ya incluye el valor escapado)
function buildCatCond(string $alias, string $colName, ?string $filtro, PDO $pdo): string {
  if (!$filtro) return "";
  if ($filtro === 'Sin categoria') {
    return " AND ({$alias}.`{$colName}` IS NULL OR TRIM({$alias}.`{$colName}`) = '') ";
  }
  return " AND {$alias}.`{$colName}` = " . $pdo->quote($filtro) . " ";
}

// Genera condiciï¿½n para subquery IN (retorna condiciï¿½n completa para WHERE de subquery)
// Pre-calcular condiciones que se usan frecuentemente
$catCondP = "";  // Para alias 'p' (productos)
if ($categoriaFiltro && $hasProductos && $prodCatCol) {
  $catCondP = buildCatCond('p', $prodCatCol, $categoriaFiltro, $pdo);
}

if ($categoriaFiltro && $hasProductos && $prodCatCol && $hasVentaItems && $viProdIdCol) {
  $categoriaWhereCond = $catCondP;
}

/* =========================
   CONFIGURACIï¿½N
========================= */
$diasSinMovimiento = 30; // Dï¿½as sin movimiento para considerar producto "dormido"

/* =========================
   CACHE (APCu o File cache opcional - 5 min)
========================= */
$dashCacheState = dashboard_load_cache([
  $from,
  $to,
  (string)($categoriaFiltro ?? ''),
  (string)($horaDesdeSql ?? ''),
  (string)($horaHastaSql ?? ''),
]);
$dashCacheTtl = (int)($dashCacheState['ttl'] ?? 300);
$dashCacheHit = (bool)($dashCacheState['hit'] ?? false);
$dashCached = $dashCacheState['payload'] ?? null;

if ($dashCacheHit && is_array($dashCached)) {
  extract($dashCached, EXTR_SKIP);
}



/* =========================
   KPIs Bï¿½SICOS
========================= */
if (!$dashCacheHit) {
$salesMetrics = dashboard_compute_sales_metrics([
  'pdo' => $pdo,
  'hasMovimientos' => $hasMovimientos,
  'msFechaCol' => $msFechaCol,
  'msTipoCol' => $msTipoCol,
  'msCantCol' => $msCantCol,
  'hasVentas' => $hasVentas,
  'ventasFechaCol' => $ventasFechaCol,
  'hasVentaItems' => $hasVentaItems,
  'viVentaIdCol' => $viVentaIdCol,
  'viProdIdCol' => $viProdIdCol,
  'viQtyCol' => $viQtyCol,
  'viLineCol' => $viLineCol,
  'hasProductos' => $hasProductos,
  'prodCatCol' => $prodCatCol,
  'categoriaFiltro' => $categoriaFiltro,
  'catCondP' => $catCondP,
  'fromStart' => $fromStart,
  'toEnd' => $toEnd,
  'ventasDateSQL' => $ventasDateSQL,
  'ventasEmitidaCond' => $ventasEmitidaCond,
  'ventasTotalCol' => $ventasTotalCol,
  'ventasTotalSQL' => $ventasTotalSQL,
]);
extract($salesMetrics, EXTR_OVERWRITE);
}

/* =========================
   RENTABILIDAD
========================= */
$lineExprVi  = $lineExprForAlias('vi');
$lineExprVi2 = $lineExprForAlias('vi2');
$profitabilityMetrics = dashboard_compute_profitability([
  'pdo' => $pdo,
  'facturacionRango' => $facturacionRango,
  'hasVentas' => $hasVentas,
  'hasVentaItems' => $hasVentaItems,
  'hasProductos' => $hasProductos,
  'ventasFechaCol' => $ventasFechaCol,
  'ventasTotalCol' => $ventasTotalCol,
  'viVentaIdCol' => $viVentaIdCol,
  'viProdIdCol' => $viProdIdCol,
  'viQtyCol' => $viQtyCol,
  'prodCostoCol' => $prodCostoCol,
  'prodNombreCol' => $prodNombreCol,
  'fromStart' => $fromStart,
  'toEnd' => $toEnd,
  'ventasDateSQL' => $ventasDateSQL,
  'ventasEmitidaCond' => $ventasEmitidaCond,
  'catCondP' => $catCondP,
  'ventasTotalSQL' => $ventasTotalSQL,
  'lineExprVi' => $lineExprVi,
  'lineExprVi2' => $lineExprVi2,
]);
extract($profitabilityMetrics, EXTR_OVERWRITE);
$promotionMetrics = dashboard_compute_promotions([
  'pdo' => $pdo,
  'hasVentaPromos' => $hasVentaPromos,
  'hasVentas' => $hasVentas,
  'ventasFechaCol' => $ventasFechaCol,
  'ventasEstadoCol' => $ventasEstadoCol,
  'fromStart' => $fromStart,
  'toEnd' => $toEnd,
  'ventasDateSQL' => $ventasDateSQL,
  'ventasEmitidaCond' => $ventasEmitidaCond,
]);
extract($promotionMetrics, EXTR_OVERWRITE);
$cancellationMetrics = dashboard_compute_cancellations([
  'pdo' => $pdo,
  'hasVentas' => $hasVentas,
  'ventasFechaCol' => $ventasFechaCol,
  'ventasEstadoCol' => $ventasEstadoCol,
  'categoriaFiltro' => $categoriaFiltro,
  'hasVentaItems' => $hasVentaItems,
  'viVentaIdCol' => $viVentaIdCol,
  'viProdIdCol' => $viProdIdCol,
  'hasProductos' => $hasProductos,
  'prodCatCol' => $prodCatCol,
  'fromStart' => $fromStart,
  'toEnd' => $toEnd,
  'ventasDateSQL' => $ventasDateSQL,
  'ventasAnuladaCond' => $ventasAnuladaCond,
  'catCondP' => $catCondP,
  'ventasTotalCol' => $ventasTotalCol,
  'ventasTotalSQL' => $ventasTotalSQL,
  'ventasRango' => $ventasRango,
]);
extract($cancellationMetrics, EXTR_OVERWRITE);

/* =========================
   STOCK CRï¿½TICO
========================= */
$stockCritico = [];
if ($hasProductos && $prodNombreCol && $prodStockCol && $prodMinCol && $prodActivoCol && $hasMovimientos && $msProdIdCol && $msTipoCol && $msCantCol && $msFechaCol) {
  $catCondStock = $catCondP;  // Usa condiciï¿½n pre-calculada con manejo de "Sin Categorï¿½a"
  
  $stmt = $pdo->prepare("
    SELECT
      p.`{$prodNombreCol}` AS nombre,
      p.`{$prodStockCol}` AS stock,
      p.`{$prodMinCol}` AS stock_minimo,
      COALESCE(SUM(ms.`{$msCantCol}`), 0) AS ventas_periodo
    FROM productos p
    LEFT JOIN movimientos_stock ms
      ON ms.`{$msProdIdCol}` = p.id
     AND ms.`{$msTipoCol}` = 'VENTA'
     AND ms.`{$msFechaCol}` >= ?
     AND ms.`{$msFechaCol}` < ?
    WHERE p.`{$prodActivoCol}` = 1
      AND p.`{$prodStockCol}` <= p.`{$prodMinCol}`
      {$catCondStock}
    GROUP BY p.id, p.`{$prodNombreCol}`, p.`{$prodStockCol}`, p.`{$prodMinCol}`
    ORDER BY (p.`{$prodStockCol}` / GREATEST(p.`{$prodMinCol}`, 1)) ASC
    LIMIT 15
  ");
  $stmt->execute([$fromStart, $toEnd]);
  $stockCritico = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $diasPeriodo = max($diffDays + 1, 1);
  foreach ($stockCritico as &$item) {
    $ventasPorDia = ((float)($item['ventas_periodo'] ?? 0)) / $diasPeriodo;
    $stock = (float)($item['stock'] ?? 0);
    $item['dias_restantes'] = ($ventasPorDia > 0) ? (int)ceil($stock / $ventasPorDia) : 999;
  }
  unset($item);
}

/* =========================
   PRODUCTOS DORMIDOS (sin movimiento)
========================= */
$productosDormidos = [];
$capitalDormido = 0.0;

if ($hasProductos && $prodNombreCol && $prodStockCol && $prodActivoCol && $hasMovimientos && $msProdIdCol && $msTipoCol && $msFechaCol) {
  $fechaLimiteDormido = (new DateTime('today'))->modify("-{$diasSinMovimiento} days")->format('Y-m-d H:i:s');
  $catCondDormidos = $catCondP;  // Usa condiciï¿½n pre-calculada con manejo de "Sin Categorï¿½a"
  $valorCol = $prodCostoCol ?: ($prodPrecioCol ?: null);
  $valorExpr = $valorCol ? "p.`{$valorCol}`" : "0";
  
  $stmt = $pdo->prepare("
    SELECT
      p.id,
      p.`{$prodNombreCol}` AS nombre,
      p.`{$prodStockCol}` AS stock,
      {$valorExpr} AS precio,
      (p.`{$prodStockCol}` * {$valorExpr}) AS valor_stock,
      MAX(ms.`{$msFechaCol}`) AS ultima_venta
    FROM productos p
    LEFT JOIN movimientos_stock ms
      ON ms.`{$msProdIdCol}` = p.id
      AND ms.`{$msTipoCol}` = 'VENTA'
    WHERE p.`{$prodActivoCol}` = 1
      AND p.`{$prodStockCol}` > 0
      {$catCondDormidos}
    GROUP BY p.id, p.`{$prodNombreCol}`, p.`{$prodStockCol}`, {$valorExpr}
    HAVING ultima_venta IS NULL OR ultima_venta < ?
    ORDER BY valor_stock DESC
    LIMIT 20
  ");
  $stmt->execute([$fechaLimiteDormido]);
  $productosDormidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
  
  foreach ($productosDormidos as $pd) {
    $capitalDormido += (float)($pd['valor_stock'] ?? 0);
  }
}

/* =========================
   CIERRE DE CAJA - Resumen del dï¿½a (HOY)
========================= */
$cierreCajaHoy = [
  'fecha' => $today,
  'total_ventas' => 0,
  'monto_total' => 0.0,
  'efectivo' => 0.0,
  'otros_medios' => 0.0,
  'anulaciones' => 0,
  'monto_anulado' => 0.0,
  'primera_venta' => null,
  'ultima_venta' => null,
  'ticket_promedio' => 0.0,
  'desglose_medios' => []
];

$todayStart = $today . " 00:00:00";
$todayEnd = (new DateTime($today))->modify('+1 day')->format('Y-m-d') . " 00:00:00";

if ($hasVentas && $ventasFechaCol && $ventasTotalCol) {
  $stmt = $pdo->prepare("
    SELECT 
      COUNT(*) as total_ventas,
      COALESCE(SUM({$ventasTotalSQL}), 0) as monto_total,
      MIN({$ventasDateSQL}) as primera_venta,
      MAX({$ventasDateSQL}) as ultima_venta
    FROM ventas
    WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}
  ");
  $stmt->execute([$todayStart, $todayEnd]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  
  $cierreCajaHoy['total_ventas'] = (int)($row['total_ventas'] ?? 0);
  $cierreCajaHoy['monto_total'] = (float)($row['monto_total'] ?? 0);
  $cierreCajaHoy['primera_venta'] = $row['primera_venta'];
  $cierreCajaHoy['ultima_venta'] = $row['ultima_venta'];
  $cierreCajaHoy['ticket_promedio'] = $cierreCajaHoy['total_ventas'] > 0 
    ? $cierreCajaHoy['monto_total'] / $cierreCajaHoy['total_ventas'] 
    : 0.0;
  
  if ($ventasEstadoCol) {
    $stmt = $pdo->prepare("
      SELECT COUNT(*) as anulaciones, COALESCE(SUM({$ventasTotalSQL}), 0) as monto
      FROM ventas
      WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasAnuladaCond}
    ");
    $stmt->execute([$todayStart, $todayEnd]);
    $rowAnul = $stmt->fetch(PDO::FETCH_ASSOC);
    $cierreCajaHoy['anulaciones'] = (int)($rowAnul['anulaciones'] ?? 0);
    $cierreCajaHoy['monto_anulado'] = (float)($rowAnul['monto'] ?? 0);
  }
  
  if ($hasVentaPagos) {
    $vpVentaId = flus_first_existing_column($pdo, 'venta_pagos', ['venta_id']);
    $vpMedio   = flus_first_existing_column($pdo, 'venta_pagos', ['medio_pago','metodo_pago']);
    $vpMonto   = flus_first_existing_column($pdo, 'venta_pagos', ['monto','importe']);
    
    if ($vpVentaId && $vpMedio && $vpMonto) {
      $vueltoExpr = $ventasVueltoCol ? "COALESCE(MAX(v.`{$ventasVueltoCol}`),0)" : "0";
      
      $stmt = $pdo->prepare("
        SELECT
          x.medio_pago,
          COALESCE(SUM(x.monto_net),0) AS monto
        FROM (
          SELECT
            vp.`{$vpVentaId}` AS venta_id,
            vp.`{$vpMedio}`   AS medio_pago,
            CASE
              WHEN vp.`{$vpMedio}`='EFECTIVO'
                THEN GREATEST(SUM(vp.`{$vpMonto}`) - {$vueltoExpr}, 0)
              ELSE SUM(vp.`{$vpMonto}`)
            END AS monto_net
          FROM venta_pagos vp
          JOIN ventas v ON v.id = vp.`{$vpVentaId}`
          WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
          GROUP BY vp.`{$vpVentaId}`, vp.`{$vpMedio}`
        ) x
        GROUP BY x.medio_pago
        ORDER BY monto DESC
      ");
      $stmt->execute([$todayStart, $todayEnd]);
      $cierreCajaHoy['desglose_medios'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
      
      foreach ($cierreCajaHoy['desglose_medios'] as $medio) {
        if (strtoupper($medio['medio_pago']) === 'EFECTIVO') {
          $cierreCajaHoy['efectivo'] = (float)$medio['monto'];
        } else {
          $cierreCajaHoy['otros_medios'] += (float)$medio['monto'];
        }
      }
    }
  } elseif ($ventasMedioCol) {
    $stmt = $pdo->prepare("
      SELECT `{$ventasMedioCol}` AS medio_pago, COALESCE(SUM({$ventasTotalSQL}),0) AS monto
      FROM ventas
      WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}
      GROUP BY `{$ventasMedioCol}`
      ORDER BY monto DESC
    ");
    $stmt->execute([$todayStart, $todayEnd]);
    $cierreCajaHoy['desglose_medios'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($cierreCajaHoy['desglose_medios'] as $medio) {
      if (strtoupper($medio['medio_pago']) === 'EFECTIVO') {
        $cierreCajaHoy['efectivo'] = (float)$medio['monto'];
      } else {
        $cierreCajaHoy['otros_medios'] += (float)$medio['monto'];
      }
    }
  }
}

/* =========================
   COMPARACIï¿½N vs perï¿½odo anterior
========================= */
function kpiDeltaBadge(float $curr, float $prev): array {
  if ($prev == 0.0) {
    if ($curr == 0.0) return ['class' => 'kpi-flat', 'text' => '0%', 'title' => 'Sin cambios'];
    return ['class' => 'kpi-new', 'text' => 'Nuevo', 'title' => 'Sin datos anteriores'];
  }
  $pct = (($curr - $prev) / $prev) * 100.0;
  if (abs($pct) < 0.05) return ['class' => 'kpi-flat', 'text' => '0%', 'title' => 'Sin cambios'];

  $arrow = ($pct > 0) ? '+' : '-';
  $cls = ($pct > 0) ? 'kpi-up' : 'kpi-down';
  $txt = $arrow . ' ' . number_format(abs($pct), 1, ',', '.') . '%';
  return ['class' => $cls, 'text' => $txt, 'title' => 'Vs periodo anterior'];
}

$comparisonMetrics = dashboard_compute_period_comparison([
  'pdo' => $pdo,
  'from' => $from,
  'diffDays' => $diffDays,
  'hasVentas' => $hasVentas,
  'ventasFechaCol' => $ventasFechaCol,
  'categoriaFiltro' => $categoriaFiltro,
  'hasVentaItems' => $hasVentaItems,
  'viVentaIdCol' => $viVentaIdCol,
  'viProdIdCol' => $viProdIdCol,
  'hasProductos' => $hasProductos,
  'prodCatCol' => $prodCatCol,
  'catCondP' => $catCondP,
  'ventasDateSQL' => $ventasDateSQL,
  'ventasEmitidaCond' => $ventasEmitidaCond,
  'ventasTotalCol' => $ventasTotalCol,
  'ventasTotalSQL' => $ventasTotalSQL,
  'viLineCol' => $viLineCol,
  'ventasRango' => $ventasRango,
  'facturacionRango' => $facturacionRango,
  'ticketPromedio' => $ticketPromedio,
]);
extract($comparisonMetrics, EXTR_OVERWRITE);

/* =========================
   CHARTS DATA (con filtro categorï¿½a)
========================= */

/* =========================
   SPARKLINES (con filtro categorï¿½a)
========================= */
$sparkFromDT = (new DateTime('today'))->modify('-6 days');
$sparkToDT   = new DateTime('today');

$sparklineStart = $sparkFromDT->format('Y-m-d') . " 00:00:00";
$sparklineEnd   = (clone $sparkToDT)->modify('+1 day')->format('Y-m-d') . " 00:00:00";

$sparklineMetrics = dashboard_compute_sparklines([
  'pdo' => $pdo,
  'hasVentas' => $hasVentas,
  'ventasFechaCol' => $ventasFechaCol,
  'categoriaFiltro' => $categoriaFiltro,
  'hasVentaItems' => $hasVentaItems,
  'viVentaIdCol' => $viVentaIdCol,
  'viProdIdCol' => $viProdIdCol,
  'hasProductos' => $hasProductos,
  'prodCatCol' => $prodCatCol,
  'catCondP' => $catCondP,
  'ventasTotalCol' => $ventasTotalCol,
  'viLineCol' => $viLineCol,
  'sparklineStart' => $sparklineStart,
  'sparklineEnd' => $sparklineEnd,
  'ventasDateSQL' => $ventasDateSQL,
  'ventasEmitidaCond' => $ventasEmitidaCond,
  'ventasTotalSQL' => $ventasTotalSQL,
  'sparkFromDT' => $sparkFromDT,
  'sparkToDT' => $sparkToDT,
]);
extract($sparklineMetrics, EXTR_OVERWRITE);

$paymentMethodMetrics = dashboard_compute_payment_methods([
  'pdo' => $pdo,
  'hasVentas' => $hasVentas,
  'ventasFechaCol' => $ventasFechaCol,
  'ventasEstadoCol' => $ventasEstadoCol,
  'ventasMedioCol' => $ventasMedioCol,
  'ventasTotalCol' => $ventasTotalCol,
  'ventasTotalSQL' => $ventasTotalSQL,
  'categoriaFiltro' => $categoriaFiltro,
  'esSinCategoria' => $esSinCategoria,
  'prodCatCol' => $prodCatCol,
  'hasVentaItems' => $hasVentaItems,
  'viVentaIdCol' => $viVentaIdCol,
  'viProdIdCol' => $viProdIdCol,
  'hasProductos' => $hasProductos,
  'fromStart' => $fromStart,
  'toEnd' => $toEnd,
  'ventasDateSQL' => $ventasDateSQL,
  'ventasEmitidaCond' => $ventasEmitidaCond,
]);
extract($paymentMethodMetrics, EXTR_OVERWRITE);

$categoryMetrics = dashboard_compute_categories([
  'pdo' => $pdo,
  'hasVentas' => $hasVentas,
  'hasVentaItems' => $hasVentaItems,
  'hasProductos' => $hasProductos,
  'ventasFechaCol' => $ventasFechaCol,
  'ventasTotalCol' => $ventasTotalCol,
  'viVentaIdCol' => $viVentaIdCol,
  'viProdIdCol' => $viProdIdCol,
  'viQtyCol' => $viQtyCol,
  'prodCatCol' => $prodCatCol,
  'viLineCol' => $viLineCol,
  'viPriceCol' => $viPriceCol,
  'lineExprVi' => $lineExprVi,
  'lineExprVi2' => $lineExprVi2,
  'fromStart' => $fromStart,
  'toEnd' => $toEnd,
  'ventasDateSQL' => $ventasDateSQL,
  'ventasEmitidaCond' => $ventasEmitidaCond,
  'ventasTotalSQL' => $ventasTotalSQL,
]);
extract($categoryMetrics, EXTR_OVERWRITE);

[$ventasLabels, $ventasData, $topProductosLabels, $topProductosData, $ventasPorHora, $ventasPorDiaSemana] = array_values(
  dashboard_compute_visual_datasets([
    'pdo' => $pdo,
    'hasVentas' => $hasVentas,
    'ventasFechaCol' => $ventasFechaCol,
    'ventasTotalCol' => $ventasTotalCol,
    'categoriaFiltro' => $categoriaFiltro,
    'hasVentaItems' => $hasVentaItems,
    'viVentaIdCol' => $viVentaIdCol,
    'viProdIdCol' => $viProdIdCol,
    'viQtyCol' => $viQtyCol,
    'hasProductos' => $hasProductos,
    'prodCatCol' => $prodCatCol,
    'catCondP' => $catCondP,
    'fromStart' => $fromStart,
    'toEnd' => $toEnd,
    'ventasDateSQL' => $ventasDateSQL,
    'ventasEmitidaCond' => $ventasEmitidaCond,
    'fromDT' => $fromDT,
    'toDT' => $toDT,
    'ventasTotalSQL' => $ventasTotalSQL,
    'prodNombreCol' => $prodNombreCol,
  ])
);

$cierreCajaHoy = array_merge([
  'fecha' => $today,
  'total_ventas' => 0,
  'monto_total' => 0.0,
  'efectivo' => 0.0,
  'otros_medios' => 0.0,
  'anulaciones' => 0,
  'monto_anulado' => 0.0,
  'primera_venta' => null,
  'ultima_venta' => null,
  'ticket_promedio' => 0.0,
  'desglose_medios' => [],
], is_array($cierreCajaHoy ?? null) ? $cierreCajaHoy : []);

$ventasAnuladas = (int)($ventasAnuladas ?? 0);
$tasaAnulacion = (float)($tasaAnulacion ?? 0.0);
$montoAnulado = (float)($montoAnulado ?? 0.0);
$totalVentas = (float)($totalVentas ?? ($facturacionRango ?? 0.0));
$gananciaBruta = (float)($gananciaBruta ?? 0.0);
$totalCostos = (float)($totalCostos ?? 0.0);
$totalDescuentosPromos = (float)($totalDescuentosPromos ?? 0.0);
$margenPorcentaje = (float)($margenPorcentaje ?? 0.0);
$capitalDormido = (float)($capitalDormido ?? 0.0);
$stockCritico = is_array($stockCritico ?? null) ? $stockCritico : [];
$productosDormidos = is_array($productosDormidos ?? null) ? $productosDormidos : [];
$productosRentables = is_array($productosRentables ?? null) ? $productosRentables : [];
$metodosPago = is_array($metodosPago ?? null) ? $metodosPago : [];
$categorias = is_array($categorias ?? null) ? $categorias : [];
$ventasLabels = is_array($ventasLabels ?? null) ? $ventasLabels : [];
$ventasData = is_array($ventasData ?? null) ? $ventasData : [];
$topProductosLabels = is_array($topProductosLabels ?? null) ? $topProductosLabels : [];
$topProductosData = is_array($topProductosData ?? null) ? $topProductosData : [];
$ventasPorHora = is_array($ventasPorHora ?? null) ? $ventasPorHora : [];
$ventasPorDiaSemana = is_array($ventasPorDiaSemana ?? null) ? $ventasPorDiaSemana : [];
$ventasDelta = array_merge(['class' => '', 'text' => '', 'title' => ''], is_array($ventasDelta ?? null) ? $ventasDelta : []);
$factDelta = array_merge(['class' => '', 'text' => '', 'title' => ''], is_array($factDelta ?? null) ? $factDelta : []);
$ticketDelta = array_merge(['class' => '', 'text' => '', 'title' => ''], is_array($ticketDelta ?? null) ? $ticketDelta : []);

$dashPayload = [
  'movimientosRango'       => $movimientosRango ?? 0,
  'ventasRango'            => $ventasRango ?? 0,
  'facturacionRango'       => $facturacionRango ?? 0.0,
  'unidadesVendidasRango'  => $unidadesVendidasRango ?? 0.0,
  'ticketPromedio'         => $ticketPromedio ?? 0.0,
  'ventasAnuladas'         => $ventasAnuladas,
  'tasaAnulacion'          => $tasaAnulacion,
  'montoAnulado'           => $montoAnulado,
  'gananciaBruta'          => $gananciaBruta,
  'totalCostos'            => $totalCostos,
  'totalDescuentosPromos'  => $totalDescuentosPromos,
  'margenPorcentaje'       => $margenPorcentaje,
  'ventasDelta'            => $ventasDelta,
  'factDelta'              => $factDelta,
  'ticketDelta'            => $ticketDelta,
  'ventasLabels'           => $ventasLabels,
  'ventasData'             => $ventasData,
  'topProductosLabels'     => $topProductosLabels,
  'topProductosData'       => $topProductosData,
  'metodosPago'            => $metodosPago,
  'categorias'             => $categorias,
  'ventasPorHora'          => $ventasPorHora,
  'ventasPorDiaSemana'     => $ventasPorDiaSemana,
  'productosRentables'     => $productosRentables,
  'stockCritico'           => $stockCritico,
  'productosDormidos'      => $productosDormidos,
  'capitalDormido'         => $capitalDormido,
  'cierreCajaHoy'          => $cierreCajaHoy,
  'sparklineVentas'        => $sparklineVentas ?? [],
  'sparklineFacturacion'   => $sparklineFacturacion ?? [],
];

dashboard_store_cache($dashCacheState, $dashPayload);


/* =========================
   HEADER
========================= */
$pageTitle = 'Panel de Control';
$currentSection = 'dashboard';
$extraCss = [
  'assets/css/dashboard.css?v=4',
  'assets/css/dashboard-advanced.css?v=3',
  'assets/css/dashboard-enhanced.css?v=1'
];

require __DIR__ . '/partials/header.php';
?>

<?php require __DIR__ . '/partials/dashboard/top.php'; ?>

    <?php require __DIR__ . '/partials/dashboard/kpis.php'; ?>
    <?php require __DIR__ . '/partials/dashboard/charts.php'; ?>
<?php require __DIR__ . '/partials/dashboard/boot.php'; ?>

<?php
// public/dashboard.php - DASHBOARD AVANZADO (ROBUSTO)
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('ver_reportes');

/* =========================
   HELPERS
========================= */
function format_qty_trim(float $n): string {
  $s = number_format($n, 3, ',', '.');
  $s = rtrim($s, '0');
  $s = rtrim($s, ',');
  return $s === '' ? '0' : $s;
}

function tableExists(PDO $pdo, string $table): bool {
  try {
    $stmt = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = :t
    ");
    $stmt->execute([':t' => $table]);
    return (int)$stmt->fetchColumn() > 0;
  } catch (Throwable $e) {
    return false;
  }
}

function columnExists(PDO $pdo, string $table, string $column): bool {
  try {
    $pdo->query("SELECT `$column` FROM `$table` LIMIT 0");
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function firstExistingColumn(PDO $pdo, string $table, array $candidates): ?string {
  foreach ($candidates as $c) {
    if (columnExists($pdo, $table, $c)) return $c;
  }
  return null;
}

/* =========================
   DETECCIONES (tablas/cols)
========================= */
$hasVentas       = tableExists($pdo, 'ventas');
$hasVentaItems   = tableExists($pdo, 'venta_items');
$hasProductos    = tableExists($pdo, 'productos');
$hasMovimientos  = tableExists($pdo, 'movimientos_stock');
$hasVentaPromos  = tableExists($pdo, 'venta_promos');

$ventasFechaCol  = $hasVentas ? firstExistingColumn($pdo, 'ventas', ['fecha','created_at','fecha_hora']) : null;
$ventasTotalCol  = $hasVentas ? firstExistingColumn($pdo, 'ventas', ['total','monto_total','importe_total']) : null;
$ventasEstadoCol = $hasVentas ? firstExistingColumn($pdo, 'ventas', ['estado','status']) : null;
$ventasMedioCol  = $hasVentas ? firstExistingColumn($pdo, 'ventas', ['medio_pago','metodo_pago','pago_tipo']) : null;

$viVentaIdCol    = $hasVentaItems ? firstExistingColumn($pdo, 'venta_items', ['venta_id']) : null;
$viProdIdCol     = $hasVentaItems ? firstExistingColumn($pdo, 'venta_items', ['producto_id']) : null;
$viQtyCol        = $hasVentaItems ? firstExistingColumn($pdo, 'venta_items', ['cantidad','qty']) : null;
$viLineCol       = $hasVentaItems ? firstExistingColumn($pdo, 'venta_items', ['subtotal','total','importe']) : null;
$viPriceCol      = $hasVentaItems ? firstExistingColumn($pdo, 'venta_items', ['precio_unitario','precio','unit_price']) : null;

$prodNombreCol   = $hasProductos ? firstExistingColumn($pdo, 'productos', ['nombre','descripcion']) : null;
$prodCostoCol    = $hasProductos ? firstExistingColumn($pdo, 'productos', ['costo','costo_unitario','cost']) : null;
$prodCatCol      = $hasProductos ? firstExistingColumn($pdo, 'productos', ['categoria','rubro','familia']) : null;
$prodStockCol    = $hasProductos ? firstExistingColumn($pdo, 'productos', ['stock']) : null;
$prodMinCol      = $hasProductos ? firstExistingColumn($pdo, 'productos', ['stock_minimo','minimo','stock_min']) : null;
$prodActivoCol   = $hasProductos ? firstExistingColumn($pdo, 'productos', ['activo','is_active']) : null;

$msFechaCol      = $hasMovimientos ? firstExistingColumn($pdo, 'movimientos_stock', ['fecha','created_at']) : null;
$msTipoCol       = $hasMovimientos ? firstExistingColumn($pdo, 'movimientos_stock', ['tipo']) : null;
$msProdIdCol     = $hasMovimientos ? firstExistingColumn($pdo, 'movimientos_stock', ['producto_id']) : null;
$msCantCol       = $hasMovimientos ? firstExistingColumn($pdo, 'movimientos_stock', ['cantidad']) : null;

/* Expr “importe de línea” para prorratear neto (evita vi.subtotal inexistente) */
$lineExprForAlias = function(string $alias) use ($viLineCol, $viQtyCol, $viPriceCol): ?string {
  if ($viLineCol) return "{$alias}.`{$viLineCol}`";
  if ($viQtyCol && $viPriceCol) return "({$alias}.`{$viQtyCol}` * {$alias}.`{$viPriceCol}`)";
  return null;
};

/* =========================
   RANGO DE FECHAS
========================= */
$today       = (new DateTime('today'))->format('Y-m-d');
$defaultFrom = (new DateTime('today'))->modify('-29 days')->format('Y-m-d');
$defaultTo   = $today;

$from = validDateYmd($_GET['from'] ?? null) ?? $defaultFrom;
$to   = validDateYmd($_GET['to'] ?? null) ?? $defaultTo;

if ($from > $to) [$from, $to] = [$to, $from];

/* =========================
   LÍMITE DE RANGO (365 días)
========================= */
$maxDays = 365;
$toastMessage = '';
$toastFrom = '';
$toastTo = '';

$fromDT = new DateTime($from);
$toDT   = new DateTime($to);
$diffDays = (int)$fromDT->diff($toDT)->format('%a');

if ($diffDays > ($maxDays - 1)) {
  $fromDT = (clone $toDT)->modify('-' . ($maxDays - 1) . ' days');
  $from = $fromDT->format('Y-m-d');
  $toastMessage = "Rango máximo: {$maxDays} días. Ajustado automáticamente.";
  $toastFrom = $from;
  $toastTo = $to;
  $diffDays = (int)$fromDT->diff($toDT)->format('%a');
}

$fromStart = $from . " 00:00:00";
$toEnd     = (new DateTime($to))->modify('+1 day')->format('Y-m-d') . " 00:00:00";

/* =========================
   WHERE helpers
========================= */
$ventasDateSQL = ($hasVentas && $ventasFechaCol) ? "`{$ventasFechaCol}`" : "fecha";
$ventasTotalSQL = ($hasVentas && $ventasTotalCol) ? "`{$ventasTotalCol}`" : "total";

$ventasEmitidaCond = ($hasVentas && $ventasEstadoCol)
  ? " AND `{$ventasEstadoCol}`='EMITIDA' "
  : "";

$ventasAnuladaCond = ($hasVentas && $ventasEstadoCol)
  ? " AND `{$ventasEstadoCol}`='ANULADA' "
  : " AND 1=0 ";

/* =========================
   KPIs BÁSICOS
========================= */
$movimientosRango = 0;
$ventasRango = 0;
$facturacionRango = 0.0;
$unidadesVendidasRango = 0.0;

if ($hasMovimientos && $msFechaCol) {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM movimientos_stock WHERE `{$msFechaCol}` >= ? AND `{$msFechaCol}` < ?");
  $stmt->execute([$fromStart, $toEnd]);
  $movimientosRango = (int)$stmt->fetchColumn();
}

if ($hasVentas && $ventasFechaCol) {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM ventas WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}");
  $stmt->execute([$fromStart, $toEnd]);
  $ventasRango = (int)$stmt->fetchColumn();

  if ($ventasTotalCol) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM({$ventasTotalSQL}),0) FROM ventas WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}");
    $stmt->execute([$fromStart, $toEnd]);
    $facturacionRango = (float)$stmt->fetchColumn();
  }
}

if ($hasVentas && $hasVentaItems && $viVentaIdCol && $viQtyCol && $ventasFechaCol) {
  $stmt = $pdo->prepare("
    SELECT COALESCE(SUM(vi.`{$viQtyCol}`),0)
    FROM venta_items vi
    JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
    WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
  ");
  $stmt->execute([$fromStart, $toEnd]);
  $unidadesVendidasRango = (float)$stmt->fetchColumn();
} elseif ($hasMovimientos && $msFechaCol && $msTipoCol && $msCantCol) {
  $stmt = $pdo->prepare("
    SELECT COALESCE(SUM(`{$msCantCol}`),0)
    FROM movimientos_stock
    WHERE `{$msTipoCol}`='VENTA' AND `{$msFechaCol}` >= ? AND `{$msFechaCol}` < ?
  ");
  $stmt->execute([$fromStart, $toEnd]);
  $unidadesVendidasRango = (float)$stmt->fetchColumn();
}

$ticketPromedio = ($ventasRango > 0) ? ($facturacionRango / $ventasRango) : 0.0;

/* =========================
   RENTABILIDAD (NETO REAL, robusto)
========================= */
$totalVentas  = $facturacionRango;
$totalCostos  = 0.0;
$gananciaBruta = 0.0;
$margenPorcentaje = 0.0;

$productosRentables = [];

$canRentabilidad = $hasVentas && $hasVentaItems && $hasProductos
  && $ventasFechaCol && $ventasTotalCol
  && $viVentaIdCol && $viProdIdCol && $viQtyCol
  && $prodCostoCol;

$lineExprVi  = $lineExprForAlias('vi');
$lineExprVi2 = $lineExprForAlias('vi2');

if ($canRentabilidad) {
  // costos
  $stmt = $pdo->prepare("
    SELECT COALESCE(SUM(vi.`{$viQtyCol}` * p.`{$prodCostoCol}`), 0)
    FROM venta_items vi
    JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
    JOIN productos p ON p.id = vi.`{$viProdIdCol}`
    WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
      AND p.`{$prodCostoCol}` IS NOT NULL
  ");
  $stmt->execute([$fromStart, $toEnd]);
  $totalCostos = (float)$stmt->fetchColumn();

  $gananciaBruta = $totalVentas - $totalCostos;
  $margenPorcentaje = ($totalVentas > 0) ? (($gananciaBruta / $totalVentas) * 100) : 0.0;

  // top rentables (si tenemos forma de prorratear)
  if ($lineExprVi && $lineExprVi2) {
    $sqlRentables = "
      SELECT
        p.`{$prodNombreCol}` AS nombre,
        SUM(vi.`{$viQtyCol}`) AS unidades,
        COALESCE(SUM(v.{$ventasTotalSQL} * ({$lineExprVi} / NULLIF(vt.subtotal_total,0))), 0) AS ventas,
        COALESCE(SUM(vi.`{$viQtyCol}` * p.`{$prodCostoCol}`), 0) AS costos,
        COALESCE(SUM((v.{$ventasTotalSQL} * ({$lineExprVi} / NULLIF(vt.subtotal_total,0))) - (vi.`{$viQtyCol}` * p.`{$prodCostoCol}`)), 0) AS ganancia
      FROM venta_items vi
      JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
      JOIN productos p ON p.id = vi.`{$viProdIdCol}`
      JOIN (
        SELECT vi2.`{$viVentaIdCol}` AS venta_id, SUM({$lineExprVi2}) AS subtotal_total
        FROM venta_items vi2
        JOIN ventas v2 ON v2.id = vi2.`{$viVentaIdCol}`
        WHERE v2.{$ventasDateSQL} >= ? AND v2.{$ventasDateSQL} < ? {$ventasEmitidaCond}
        GROUP BY vi2.`{$viVentaIdCol}`
      ) vt ON vt.venta_id = vi.`{$viVentaIdCol}`
      WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
        AND p.`{$prodCostoCol}` IS NOT NULL
        AND vt.subtotal_total > 0
      GROUP BY p.id, p.`{$prodNombreCol}`
      ORDER BY ganancia DESC
      LIMIT 5
    ";
    $stmt = $pdo->prepare($sqlRentables);
    $stmt->execute([$fromStart, $toEnd, $fromStart, $toEnd]);
    $productosRentables = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
}

/* =========================
   MÉTODOS DE PAGO (soporta split con venta_pagos)
========================= */
$metodosPago = [];

$hasVentaPagos = tableExists($pdo, 'venta_pagos');
$ventasVueltoCol = $hasVentas ? firstExistingColumn($pdo, 'ventas', ['vuelto','cambio']) : null;

if ($hasVentaPagos && $hasVentas && $ventasFechaCol && $ventasEstadoCol) {

  $vpVentaId = firstExistingColumn($pdo, 'venta_pagos', ['venta_id']);
  $vpMedio   = firstExistingColumn($pdo, 'venta_pagos', ['medio_pago','metodo_pago']);
  $vpMonto   = firstExistingColumn($pdo, 'venta_pagos', ['monto','importe']);

  if ($vpVentaId && $vpMedio && $vpMonto) {

    $vueltoExpr = $ventasVueltoCol ? "COALESCE(MAX(v.`{$ventasVueltoCol}`),0)" : "0";

    // Net cash: efectivo_recibido - vuelto (solo 1 vez por venta)
    $sql = "
      SELECT
        x.medio_pago,
        COUNT(DISTINCT x.venta_id) AS cantidad,
        COALESCE(SUM(x.monto_net),0) AS monto,
        COALESCE(AVG(x.monto_net),0) AS ticket_promedio
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
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$fromStart, $toEnd]);
    $metodosPago = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }

} elseif ($hasVentas && $ventasFechaCol && $ventasTotalCol && $ventasMedioCol) {

  // fallback legacy (sin venta_pagos)
  $stmt = $pdo->prepare("
    SELECT
      `{$ventasMedioCol}` AS medio_pago,
      COUNT(*) AS cantidad,
      COALESCE(SUM({$ventasTotalSQL}),0) AS monto,
      COALESCE(AVG({$ventasTotalSQL}),0) AS ticket_promedio
    FROM ventas
    WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}
    GROUP BY `{$ventasMedioCol}`
    ORDER BY monto DESC
  ");
  $stmt->execute([$fromStart, $toEnd]);
  $metodosPago = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================
   PROMOS (si existe venta_promos)
========================= */
$promociones = [];
$totalDescuentosPromos = 0.0;

if ($hasVentaPromos && $hasVentas && $ventasFechaCol && $ventasEstadoCol) {
  $vpVentaId = firstExistingColumn($pdo, 'venta_promos', ['venta_id']);
  $vpNombre  = firstExistingColumn($pdo, 'venta_promos', ['promo_nombre','nombre']);
  $vpTipo    = firstExistingColumn($pdo, 'venta_promos', ['promo_tipo','tipo']);
  $vpDesc    = firstExistingColumn($pdo, 'venta_promos', ['descuento_monto','descuento','monto_descuento']);

  if ($vpVentaId && $vpNombre && $vpTipo && $vpDesc) {
    $stmt = $pdo->prepare("
      SELECT
        vp.`{$vpNombre}` AS promo_nombre,
        vp.`{$vpTipo}` AS promo_tipo,
        COUNT(*) AS veces_aplicada,
        COALESCE(SUM(vp.`{$vpDesc}`),0) AS descuento_total
      FROM venta_promos vp
      JOIN ventas v ON v.id = vp.`{$vpVentaId}`
      WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
      GROUP BY vp.`{$vpNombre}`, vp.`{$vpTipo}`
      ORDER BY descuento_total DESC
      LIMIT 5
    ");
    $stmt->execute([$fromStart, $toEnd]);
    $promociones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalDescuentosPromos = (float)array_sum(array_map(
      fn($r) => (float)($r['descuento_total'] ?? 0),
      $promociones
    ));
  }
}

/* =========================
   CATEGORÍAS (neto prorrateado si se puede)
========================= */
$categorias = [];
if ($hasVentas && $hasVentaItems && $hasProductos && $ventasFechaCol && $ventasTotalCol && $viVentaIdCol && $viProdIdCol && $viQtyCol) {
  $catCol = $prodCatCol ?: null;
  $catSelect = $catCol ? "COALESCE(p.`{$catCol}`, 'Sin Categoría')" : "'Sin Categoría'";

  if ($lineExprVi && $lineExprVi2) {
    $sqlCategorias = "
      SELECT
        {$catSelect} AS categoria,
        SUM(vi.`{$viQtyCol}`) AS unidades,
        COALESCE(SUM(v.{$ventasTotalSQL} * ({$lineExprVi} / NULLIF(vt.subtotal_total,0))), 0) AS ventas,
        COUNT(DISTINCT vi.`{$viVentaIdCol}`) AS num_ventas
      FROM venta_items vi
      JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
      JOIN productos p ON p.id = vi.`{$viProdIdCol}`
      JOIN (
        SELECT vi2.`{$viVentaIdCol}` AS venta_id, SUM({$lineExprVi2}) AS subtotal_total
        FROM venta_items vi2
        JOIN ventas v2 ON v2.id = vi2.`{$viVentaIdCol}`
        WHERE v2.{$ventasDateSQL} >= ? AND v2.{$ventasDateSQL} < ? {$ventasEmitidaCond}
        GROUP BY vi2.`{$viVentaIdCol}`
      ) vt ON vt.venta_id = vi.`{$viVentaIdCol}`
      WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
        AND vt.subtotal_total > 0
      GROUP BY categoria
      ORDER BY ventas DESC
      LIMIT 8
    ";
    $stmt = $pdo->prepare($sqlCategorias);
    $stmt->execute([$fromStart, $toEnd, $fromStart, $toEnd]);
    $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } else {
    // fallback: ventas aproximadas por qty*precio (si existe)
    $fallbackLine = $viLineCol ? "vi.`{$viLineCol}`" : (($viQtyCol && $viPriceCol) ? "(vi.`{$viQtyCol}` * vi.`{$viPriceCol}`)" : "0");
    $sqlCategorias = "
      SELECT
        {$catSelect} AS categoria,
        SUM(vi.`{$viQtyCol}`) AS unidades,
        COALESCE(SUM({$fallbackLine}),0) AS ventas,
        COUNT(DISTINCT vi.`{$viVentaIdCol}`) AS num_ventas
      FROM venta_items vi
      JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
      JOIN productos p ON p.id = vi.`{$viProdIdCol}`
      WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
      GROUP BY categoria
      ORDER BY ventas DESC
      LIMIT 8
    ";
    $stmt = $pdo->prepare($sqlCategorias);
    $stmt->execute([$fromStart, $toEnd]);
    $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
}

/* =========================
   ANULACIONES
========================= */
$ventasAnuladas = 0;
$montoAnulado = 0.0;
$tasaAnulacion = 0.0;

if ($hasVentas && $ventasFechaCol && $ventasEstadoCol) {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM ventas WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasAnuladaCond}");
  $stmt->execute([$fromStart, $toEnd]);
  $ventasAnuladas = (int)$stmt->fetchColumn();

  if ($ventasTotalCol) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM({$ventasTotalSQL}),0) FROM ventas WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasAnuladaCond}");
    $stmt->execute([$fromStart, $toEnd]);
    $montoAnulado = (float)$stmt->fetchColumn();
  }

  $totalVentasConAnuladas = $ventasRango + $ventasAnuladas;
  $tasaAnulacion = ($totalVentasConAnuladas > 0) ? (($ventasAnuladas / $totalVentasConAnuladas) * 100) : 0.0;
}

/* =========================
   TEMPORAL: Ventas por hora / día semana
========================= */
$ventasPorHora = [];
$ventasPorDiaSemana = [];

if ($hasVentas && $ventasFechaCol && $ventasTotalCol) {
  $stmt = $pdo->prepare("
    SELECT HOUR({$ventasDateSQL}) AS hora,
           COUNT(*) AS cantidad,
           COALESCE(SUM({$ventasTotalSQL}),0) AS monto
    FROM ventas
    WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}
    GROUP BY HOUR({$ventasDateSQL})
    ORDER BY hora
  ");
  $stmt->execute([$fromStart, $toEnd]);
  $ventasPorHora = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $stmt = $pdo->prepare("
    SELECT DAYOFWEEK({$ventasDateSQL}) AS dia_num,
           COUNT(*) AS cantidad,
           COALESCE(SUM({$ventasTotalSQL}),0) AS monto
    FROM ventas
    WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}
    GROUP BY DAYOFWEEK({$ventasDateSQL})
    ORDER BY dia_num
  ");
  $stmt->execute([$fromStart, $toEnd]);
  $ventasPorDiaSemana = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $diasSemana = ['', 'Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
  foreach ($ventasPorDiaSemana as &$dia) {
    $dia['dia_nombre'] = $diasSemana[(int)($dia['dia_num'] ?? 0)] ?? 'N/A';
  }
  unset($dia);
}

/* =========================
   STOCK CRÍTICO
========================= */
$stockCritico = [];
if ($hasProductos && $prodNombreCol && $prodStockCol && $prodMinCol && $prodActivoCol && $hasMovimientos && $msProdIdCol && $msTipoCol && $msCantCol && $msFechaCol) {
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
    GROUP BY p.id, p.`{$prodNombreCol}`, p.`{$prodStockCol}`, p.`{$prodMinCol}`
    ORDER BY (p.`{$prodStockCol}` / GREATEST(p.`{$prodMinCol}`, 1)) ASC
    LIMIT 10
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
   COMPARACIÓN vs período anterior (ventas, fact, ticket)
========================= */
function kpiDeltaBadge(float $curr, float $prev): array {
  if ($prev == 0.0) {
    if ($curr == 0.0) return ['class' => 'kpi-flat', 'text' => '0%', 'title' => 'Sin cambios'];
    return ['class' => 'kpi-new', 'text' => 'Nuevo', 'title' => 'Sin datos anteriores'];
  }
  $pct = (($curr - $prev) / $prev) * 100.0;
  if (abs($pct) < 0.05) return ['class' => 'kpi-flat', 'text' => '0%', 'title' => 'Sin cambios'];

  $arrow = ($pct > 0) ? '▲' : '▼';
  $cls = ($pct > 0) ? 'kpi-up' : 'kpi-down';
  $txt = $arrow . ' ' . number_format(abs($pct), 1, ',', '.') . '%';
  return ['class' => $cls, 'text' => $txt, 'title' => 'Vs período anterior'];
}

$rangeDays = $diffDays + 1;
$prevToDT = (new DateTime($from))->modify('-1 day');
$prevFromDT = (clone $prevToDT)->modify('-' . ($rangeDays - 1) . ' days');
$prevFrom = $prevFromDT->format('Y-m-d');
$prevTo   = $prevToDT->format('Y-m-d');
$prevFromStart = $prevFrom . " 00:00:00";
$prevToEnd     = (new DateTime($prevTo))->modify('+1 day')->format('Y-m-d') . " 00:00:00";

$ventasPrev = 0;
$facturacionPrev = 0.0;

if ($hasVentas && $ventasFechaCol) {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM ventas WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}");
  $stmt->execute([$prevFromStart, $prevToEnd]);
  $ventasPrev = (int)$stmt->fetchColumn();

  if ($ventasTotalCol) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM({$ventasTotalSQL}),0) FROM ventas WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}");
    $stmt->execute([$prevFromStart, $prevToEnd]);
    $facturacionPrev = (float)$stmt->fetchColumn();
  }
}

$ticketPrev = ($ventasPrev > 0) ? ($facturacionPrev / $ventasPrev) : 0.0;

$ventasDelta = kpiDeltaBadge((float)$ventasRango, (float)$ventasPrev);
$factDelta   = kpiDeltaBadge((float)$facturacionRango, (float)$facturacionPrev);
$ticketDelta = kpiDeltaBadge((float)$ticketPromedio, (float)$ticketPrev);

/* =========================
   CHARTS DATA
========================= */
$ventasLabels = [];
$ventasData = [];
if ($hasVentas && $ventasFechaCol) {
  $stmt = $pdo->prepare("
    SELECT DATE({$ventasDateSQL}) AS dia, COUNT(*) AS total
    FROM ventas
    WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}
    GROUP BY DATE({$ventasDateSQL})
    ORDER BY dia
  ");
  $stmt->execute([$fromStart, $toEnd]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  $ventasMap = [];
  foreach ($rows as $r) {
    $ventasMap[(string)$r['dia']] = (int)$r['total'];
  }

  $periodo = new DatePeriod($fromDT, new DateInterval('P1D'), (clone $toDT)->modify('+1 day'));
  foreach ($periodo as $d) {
    $dia = $d->format('Y-m-d');
    $ventasLabels[] = $dia;
    $ventasData[] = $ventasMap[$dia] ?? 0;
  }
}

$topProductosLabels = [];
$topProductosData = [];
if ($hasVentas && $hasVentaItems && $hasProductos && $ventasFechaCol && $viVentaIdCol && $viProdIdCol && $viQtyCol && $prodNombreCol) {
  $stmt = $pdo->prepare("
    SELECT p.`{$prodNombreCol}` AS nombre, SUM(vi.`{$viQtyCol}`) AS total
    FROM venta_items vi
    JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
    JOIN productos p ON p.id = vi.`{$viProdIdCol}`
    WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
    GROUP BY p.id, p.`{$prodNombreCol}`
    ORDER BY total DESC
    LIMIT 5
  ");
  $stmt->execute([$fromStart, $toEnd]);
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $topProductosLabels[] = (string)$row['nombre'];
    $topProductosData[]   = (float)$row['total'];
  }
}

/* =========================
   SPARKLINES (últimos 7 días)
========================= */
$sparkFromDT = (new DateTime('today'))->modify('-6 days');
$sparkToDT   = new DateTime('today');

$sparklineStart = $sparkFromDT->format('Y-m-d') . " 00:00:00";
$sparklineEnd   = (clone $sparkToDT)->modify('+1 day')->format('Y-m-d') . " 00:00:00";

$sparklineVentas = [];
$sparklineFacturacion = [];

if ($hasVentas && $ventasFechaCol) {
  $stmt = $pdo->prepare("
    SELECT DATE({$ventasDateSQL}) as dia, COUNT(*) as total
    FROM ventas
    WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}
    GROUP BY DATE({$ventasDateSQL})
    ORDER BY dia
  ");
  $stmt->execute([$sparklineStart, $sparklineEnd]);
  $mapVentas = [];
  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $mapVentas[(string)$r['dia']] = (int)$r['total'];

  $mapFact = [];
  if ($ventasTotalCol) {
    $stmt = $pdo->prepare("
      SELECT DATE({$ventasDateSQL}) as dia, COALESCE(SUM({$ventasTotalSQL}),0) as monto
      FROM ventas
      WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}
      GROUP BY DATE({$ventasDateSQL})
      ORDER BY dia
    ");
    $stmt->execute([$sparklineStart, $sparklineEnd]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $mapFact[(string)$r['dia']] = (float)$r['monto'];
  }

  $periodoSpark = new DatePeriod($sparkFromDT, new DateInterval('P1D'), (clone $sparkToDT)->modify('+1 day'));
  foreach ($periodoSpark as $d) {
    $k = $d->format('Y-m-d');
    $sparklineVentas[] = $mapVentas[$k] ?? 0;
    $sparklineFacturacion[] = $mapFact[$k] ?? 0.0;
  }
}

/* =========================
   HEADER
========================= */
$pageTitle = 'Dashboard';
$currentSection = 'dashboard';
$extraCss = ['assets/css/dashboard.css?v=2', 'assets/css/dashboard-advanced.css?v=2'];

require __DIR__ . '/partials/header.php';
?>

<div id="dashToast" class="flus-toast" style="display:none;"
  data-message="<?= h($toastMessage) ?>"
  data-from="<?= h($toastFrom) ?>"
  data-to="<?= h($toastTo) ?>"></div>

<div class="page-wrap">
  <div class="panel dashboard-panel">

    <div class="dash-header">
      <div>
        <h1 class="dash-title">📊 Panel de control</h1>
        <p class="dash-sub">Análisis completo de ventas, rentabilidad y operaciones</p>
      </div>
      <div class="dash-header-meta">
        <span>Hoy: <?= date('d/m/Y'); ?></span>
      </div>
    </div>

    <!-- FILTROS + EXPORT -->
    <form id="dashFilters" class="dash-filters" method="get" action="dashboard.php">
      <div class="dash-presets">
        <button type="button" class="dash-chip" data-preset="today">Hoy</button>
        <button type="button" class="dash-chip" data-preset="7d">7d</button>
        <button type="button" class="dash-chip" data-preset="30d">30d</button>
        <button type="button" class="dash-chip" data-preset="month">Este mes</button>

        <!-- Dropdown export (no molesta visualmente) -->
        <details class="dash-export-dd">
          <summary>Exportar ▾</summary>
          <div class="dash-export-dd-menu">
            <a class="dash-export" data-export-type="kpis" href="dashboard_export.php?type=kpis&from=<?= h($from) ?>&to=<?= h($to) ?>">KPIs</a>
            <a class="dash-export" data-export-type="movimientos" href="dashboard_export.php?type=movimientos&from=<?= h($from) ?>&to=<?= h($to) ?>">Movimientos</a>
            <a class="dash-export" data-export-type="top_productos" href="dashboard_export.php?type=top_productos&from=<?= h($from) ?>&to=<?= h($to) ?>">Top Productos</a>
            <a class="dash-export" data-export-type="metodos_pago" href="dashboard_export.php?type=metodos_pago&from=<?= h($from) ?>&to=<?= h($to) ?>">Medios de pago</a>
            <a class="dash-export" data-export-type="categorias" href="dashboard_export.php?type=categorias&from=<?= h($from) ?>&to=<?= h($to) ?>">Categorías</a>
            <a class="dash-export" data-export-type="rentables" href="dashboard_export.php?type=rentables&from=<?= h($from) ?>&to=<?= h($to) ?>">Rentables</a>
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
          <button type="submit" class="dash-apply">Aplicar</button>
        </div>
        <div class="dash-range-hint">
          Rango: <strong><?= (new DateTime($from))->format('d/m/Y'); ?></strong>
          → <strong><?= (new DateTime($to))->format('d/m/Y'); ?></strong>
        </div>
      </div>
    </form>

    <!-- INSIGHTS -->
    <div class="insights-container">
      <h2 class="section-title">💡 Indicadores clave</h2>
      <div class="insights-grid">
        <?php
          $insights = [];

          if (!empty($ventasData)) {
            $maxVentas = max($ventasData);
            $maxIdx = array_search($maxVentas, $ventasData, true);
            if ($maxIdx !== false && isset($ventasLabels[$maxIdx])) {
              $mejorDia = (new DateTime($ventasLabels[$maxIdx]))->format('d/m');
              $insights[] = [
                'icon' => '📈',
                'html' => 'Tu mejor día fue el <strong>' . h($mejorDia) . '</strong> con <strong>' . (int)$maxVentas . ' ventas</strong>'
              ];
            }
          }

          if (($ventasDelta['class'] ?? '') === 'kpi-up') {
            $insights[] = ['icon' => '🚀', 'html' => 'Ventas crecieron <strong>' . h($ventasDelta['text']) . '</strong> vs período anterior'];
          } elseif (($ventasDelta['class'] ?? '') === 'kpi-down') {
            $insights[] = ['icon' => '⚠️', 'html' => 'Ventas bajaron <strong>' . h($ventasDelta['text']) . '</strong> vs período anterior'];
          }

          if (!empty($productosRentables)) {
            $top = $productosRentables[0];
            $nombre = h((string)($top['nombre'] ?? 'Producto'));
            $ganancia = number_format((float)($top['ganancia'] ?? 0), 0, ',', '.');
            $insights[] = ['icon' => '💰', 'html' => "<strong>{$nombre}</strong> es tu producto más rentable (<strong>$ {$ganancia}</strong>)"];
          }

          if ($tasaAnulacion > 5) {
            $insights[] = ['icon' => '⚠️', 'html' => 'Tasa de anulación alta: <strong>' . h(number_format($tasaAnulacion, 1)) . '%</strong>'];
          } elseif ($ventasAnuladas === 0 && $ventasRango > 10) {
            $insights[] = ['icon' => '✅', 'html' => 'Excelente: <strong>0 anulaciones</strong> en el período'];
          }

          foreach ($insights as $in) {
            echo "<div class='insight-item'>{$in['icon']} {$in['html']}</div>";
          }
        ?>
      </div>
    </div>

    <!-- KPIs -->
    <h2 class="section-title">📊 KPIs Principales</h2>
    <div class="dash-kpi-row">
      <div class="stat-card stat-ok">
        <div class="stat-label">Ventas (rango)</div>
        <div class="stat-value"><?= (int)$ventasRango ?></div>
        <div class="kpi-delta <?= h($ventasDelta['class']) ?>"><?= h($ventasDelta['text']) ?></div>
        <canvas class="mini-sparkline" data-values='<?= json_encode($sparklineVentas) ?>'></canvas>
      </div>

      <div class="stat-card">
        <div class="stat-label">Facturación</div>
        <div class="stat-value">$ <?= number_format($facturacionRango, 0, ',', '.') ?></div>
        <div class="kpi-delta <?= h($factDelta['class']) ?>"><?= h($factDelta['text']) ?></div>
        <canvas class="mini-sparkline" data-values='<?= json_encode($sparklineFacturacion) ?>'></canvas>
      </div>

      <div class="stat-card">
        <div class="stat-label">Ticket Promedio</div>
        <div class="stat-value">$ <?= number_format($ticketPromedio, 0, ',', '.') ?></div>
        <div class="kpi-delta <?= h($ticketDelta['class']) ?>"><?= h($ticketDelta['text']) ?></div>
      </div>

      <div class="stat-card">
        <div class="stat-label">Unidades Vendidas</div>
        <div class="stat-value"><?= h(format_qty_trim($unidadesVendidasRango)) ?></div>
      </div>
    </div>

    <!-- RENTABILIDAD -->
    <h2 class="section-title">💰 Análisis de Rentabilidad</h2>
    <div class="dash-kpi-row">
      <div class="stat-card stat-ok">
        <div class="stat-label">Ganancia Bruta</div>
        <div class="stat-value">$ <?= number_format($gananciaBruta, 0, ',', '.') ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Margen (%)</div>
        <div class="stat-value"><?= number_format($margenPorcentaje, 1) ?>%</div>
      </div>
      <div class="stat-card stat-bajo">
        <div class="stat-label">Total Costos</div>
        <div class="stat-value">$ <?= number_format($totalCostos, 0, ',', '.') ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Descuentos (Promos)</div>
        <div class="stat-value">$ <?= number_format($totalDescuentosPromos, 0, ',', '.') ?></div>
      </div>
    </div>

    <!-- ANULACIONES -->
    <div class="dash-kpi-row">
      <div class="stat-card <?= $tasaAnulacion > 5 ? 'stat-sin' : 'stat-ok' ?>">
        <div class="stat-label">Ventas Anuladas</div>
        <div class="stat-value"><?= (int)$ventasAnuladas ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Tasa Anulación</div>
        <div class="stat-value"><?= number_format($tasaAnulacion, 1) ?>%</div>
      </div>
      <div class="stat-card stat-bajo">
        <div class="stat-label">Monto Anulado</div>
        <div class="stat-value">$ <?= number_format($montoAnulado, 0, ',', '.') ?></div>
      </div>
    </div>

    <!-- GRID DE GRÁFICOS -->
    <div class="dash-grid">
      <div class="dash-card">
        <div class="dash-card-header">
          <h2>Ventas por día</h2>
          <span class="dash-card-sub">Evolución en el rango</span>
        </div>
        <div class="chart-wrap">
          <canvas id="chartVentas"></canvas>
          <div id="noVentasMsg" class="chart-empty" style="display:none;">No hay ventas en el rango</div>
        </div>
      </div>

      <div class="dash-card">
        <div class="dash-card-header">
          <h2>Top Productos</h2>
          <span class="dash-card-sub">Más vendidos</span>
        </div>
        <div class="chart-wrap">
          <canvas id="chartTopProductos"></canvas>
          <div id="noTopMsg" class="chart-empty" style="display:none;">Sin datos</div>
        </div>
      </div>

      <div class="dash-card">
        <div class="dash-card-header">
          <h2>Métodos de Pago</h2>
          <span class="dash-card-sub">Distribución</span>
        </div>
        <div class="chart-wrap">
          <canvas id="chartMetodosPago"></canvas>
        </div>
      </div>

      <div class="dash-card">
        <div class="dash-card-header">
          <h2>Ventas por Categoría</h2>
        </div>
        <div class="chart-wrap">
          <canvas id="chartCategorias"></canvas>
        </div>
      </div>

      <div class="dash-card dash-card-wide">
        <div class="dash-card-header">
          <h2>Horarios Pico</h2>
          <span class="dash-card-sub">Distribución por hora</span>
        </div>
        <div class="chart-wrap chart-wrap-wide">
          <canvas id="chartHorarios"></canvas>
        </div>
      </div>

      <div class="dash-card dash-card-wide">
        <div class="dash-card-header">
          <h2>Productos Más Rentables</h2>
          <span class="dash-card-sub">Top 5 por ganancia</span>
        </div>
        <div class="chart-wrap chart-wrap-wide">
          <canvas id="chartRentables"></canvas>
        </div>
      </div>
    </div>

    <!-- STOCK CRÍTICO -->
    <?php if (!empty($stockCritico)): ?>
      <h2 class="section-title">⚠️ Stock Crítico</h2>
      <div class="stock-table-wrap">
        <table class="stock-table">
          <thead>
            <tr>
              <th>Producto</th>
              <th>Stock</th>
              <th>Mínimo</th>
              <th>Días Restantes</th>
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
                    ~<?= $dr ?> días
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

  </div>
</div>

<script>
  window.dashboardData = {
    from: <?= json_encode($from) ?>,
    to: <?= json_encode($to) ?>,
    ventasLabels: <?= json_encode($ventasLabels) ?>,
    ventasData: <?= json_encode($ventasData) ?>,
    topProdLabels: <?= json_encode($topProductosLabels) ?>,
    topProdData: <?= json_encode($topProductosData) ?>,
    metodosPago: <?= json_encode($metodosPago) ?>,
    categorias: <?= json_encode($categorias) ?>,
    ventasPorHora: <?= json_encode($ventasPorHora) ?>,
    ventasPorDiaSemana: <?= json_encode($ventasPorDiaSemana) ?>,
    productosRentables: <?= json_encode($productosRentables) ?>
  };
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="assets/js/dashboard.js?v=4" defer></script>

<?php require __DIR__ . '/partials/footer.php'; ?>

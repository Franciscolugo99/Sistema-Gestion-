<?php
// public/dashboard_export.php - v3 con productos dormidos
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('ver_reportes');

/* =========================
   HELPERS
========================= */
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
  foreach ($candidates as $c) if (columnExists($pdo, $table, $c)) return $c;
  return null;
}
function csvOut(array $row, $out, string $delimiter=';'): void {
  fputcsv($out, $row, $delimiter);
}

/* =========================
   INPUTS
========================= */
$type = strtolower(trim($_GET['type'] ?? 'kpis'));
$allowed = ['movimientos','kpis','top_productos','metodos_pago','categorias','rentables','dormidos','cierre_caja'];
if (!in_array($type, $allowed, true)) $type = 'kpis';

$today = (new DateTime('today'))->format('Y-m-d');
$defaultFrom = (new DateTime('today'))->modify('-29 days')->format('Y-m-d');
$defaultTo   = $today;

$from = validDateYmd($_GET['from'] ?? null) ?? $defaultFrom;
$to   = validDateYmd($_GET['to'] ?? null) ?? $defaultTo;

if ($from > $to) { $tmp=$from; $from=$to; $to=$tmp; }

// Límite 365 días
$maxDays = 365;
$fromDT = new DateTime($from);
$toDT   = new DateTime($to);
$diffDays = (int)$fromDT->diff($toDT)->format('%a');
if ($diffDays > ($maxDays - 1)) {
  $fromDT = (clone $toDT)->modify("-" . ($maxDays - 1) . " days");
  $from = $fromDT->format('Y-m-d');
}

// Para SQL: [from 00:00:00, to+1day 00:00:00)
$fromStart = $from . " 00:00:00";
$toEnd     = (new DateTime($to))->modify('+1 day')->format('Y-m-d') . " 00:00:00";

/* =========================
   DETECCIONES
========================= */
$hasVentas     = tableExists($pdo, 'ventas');
$hasVentaItems = tableExists($pdo, 'venta_items');
$hasProductos  = tableExists($pdo, 'productos');
$hasMovs       = tableExists($pdo, 'movimientos_stock');

$ventasFechaCol  = $hasVentas ? firstExistingColumn($pdo, 'ventas', ['fecha','created_at','fecha_hora']) : null;
$ventasTotalCol  = $hasVentas ? firstExistingColumn($pdo, 'ventas', ['total','monto_total','importe_total']) : null;
$ventasEstadoCol = $hasVentas ? firstExistingColumn($pdo, 'ventas', ['estado','status']) : null;
$ventasMedioCol  = $hasVentas ? firstExistingColumn($pdo, 'ventas', ['medio_pago','metodo_pago','pago_tipo']) : null;

$viVentaIdCol = $hasVentaItems ? firstExistingColumn($pdo, 'venta_items', ['venta_id']) : null;
$viProdIdCol  = $hasVentaItems ? firstExistingColumn($pdo, 'venta_items', ['producto_id']) : null;
$viQtyCol     = $hasVentaItems ? firstExistingColumn($pdo, 'venta_items', ['cantidad','qty']) : null;
$viLineCol    = $hasVentaItems ? firstExistingColumn($pdo, 'venta_items', ['subtotal','total','importe']) : null;
$viPriceCol   = $hasVentaItems ? firstExistingColumn($pdo, 'venta_items', ['precio_unitario','precio','unit_price']) : null;

$prodNombreCol = $hasProductos ? firstExistingColumn($pdo, 'productos', ['nombre','descripcion']) : null;
$prodCostoCol  = $hasProductos ? firstExistingColumn($pdo, 'productos', ['costo','costo_unitario','cost']) : null;
$prodCatCol    = $hasProductos ? firstExistingColumn($pdo, 'productos', ['categoria','rubro','familia']) : null;
$prodStockCol  = $hasProductos ? firstExistingColumn($pdo, 'productos', ['stock']) : null;
$prodActivoCol = $hasProductos ? firstExistingColumn($pdo, 'productos', ['activo','is_active']) : null;
$prodPrecioCol = $hasProductos ? firstExistingColumn($pdo, 'productos', ['precio','precio_venta','price']) : null;

$msFechaCol = $hasMovs ? firstExistingColumn($pdo, 'movimientos_stock', ['fecha','created_at']) : null;
$msTipoCol  = $hasMovs ? firstExistingColumn($pdo, 'movimientos_stock', ['tipo']) : null;
$msCantCol  = $hasMovs ? firstExistingColumn($pdo, 'movimientos_stock', ['cantidad']) : null;
$msProdCol  = $hasMovs ? firstExistingColumn($pdo, 'movimientos_stock', ['producto_id']) : null;

$lineExprForAlias = function(string $alias) use ($viLineCol, $viQtyCol, $viPriceCol): ?string {
  if ($viLineCol) return "{$alias}.`{$viLineCol}`";
  if ($viQtyCol && $viPriceCol) return "({$alias}.`{$viQtyCol}` * {$alias}.`{$viPriceCol}`)";
  return null;
};

$ventasDateSQL = ($ventasFechaCol) ? "`{$ventasFechaCol}`" : "fecha";
$ventasTotalSQL = ($ventasTotalCol) ? "`{$ventasTotalCol}`" : "total";
$emitidaCond = ($ventasEstadoCol) ? " AND `{$ventasEstadoCol}`='EMITIDA' " : "";

/* =========================
   RESPONSE HEADERS
========================= */
$filename = "dashboard_{$type}_{$from}_al_{$to}.csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
echo "\xEF\xBB\xBF"; // BOM Excel

$out = fopen('php://output', 'w');
$D = ';';

csvOut(['INFO', 'Rango', $from, $to, 'maxDays', (string)$maxDays], $out, $D);

try {

  /* =========================
     MOVIMIENTOS
  ========================= */
  if ($type === 'movimientos') {
    csvOut(['fecha', 'tipo', 'producto_id', 'cantidad'], $out, $D);

    if (!$hasMovs || !$msFechaCol) {
      csvOut(['ERROR', 'No existe movimientos_stock o columna fecha'], $out, $D);
    } else {
      $sql = "SELECT `{$msFechaCol}` AS fecha, `{$msTipoCol}` AS tipo, `{$msProdCol}` AS producto_id, `{$msCantCol}` AS cantidad
              FROM movimientos_stock
              WHERE `{$msFechaCol}` >= ? AND `{$msFechaCol}` < ?
              ORDER BY `{$msFechaCol}` ASC";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$fromStart, $toEnd]);
      while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        csvOut([$r['fecha'], $r['tipo'], $r['producto_id'], $r['cantidad']], $out, $D);
      }
    }
  }

  /* =========================
     KPIS
  ========================= */
  if ($type === 'kpis') {
    $movRango = 0;
    if ($hasMovs && $msFechaCol) {
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM movimientos_stock WHERE `{$msFechaCol}` >= ? AND `{$msFechaCol}` < ?");
      $stmt->execute([$fromStart, $toEnd]);
      $movRango = (int)$stmt->fetchColumn();
    }

    $ventasRango = 0;
    $facturacion = null;
    if ($hasVentas && $ventasFechaCol) {
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM ventas WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$emitidaCond}");
      $stmt->execute([$fromStart, $toEnd]);
      $ventasRango = (int)$stmt->fetchColumn();

      if ($ventasTotalCol) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM({$ventasTotalSQL}),0) FROM ventas WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$emitidaCond}");
        $stmt->execute([$fromStart, $toEnd]);
        $facturacion = (float)$stmt->fetchColumn();
      }
    }

    $unidades = 0;
    if ($hasVentas && $hasVentaItems && $viVentaIdCol && $viQtyCol && $ventasFechaCol) {
      $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(vi.`{$viQtyCol}`),0)
        FROM venta_items vi
        JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
        WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$emitidaCond}
      ");
      $stmt->execute([$fromStart, $toEnd]);
      $unidades = (int)$stmt->fetchColumn();
    } elseif ($hasMovs && $msFechaCol && $msTipoCol && $msCantCol) {
      $stmt = $pdo->prepare("SELECT COALESCE(SUM(`{$msCantCol}`),0) FROM movimientos_stock WHERE `{$msTipoCol}`='VENTA' AND `{$msFechaCol}` >= ? AND `{$msFechaCol}` < ?");
      $stmt->execute([$fromStart, $toEnd]);
      $unidades = (int)$stmt->fetchColumn();
    }

    $ticket = null;
    if ($facturacion !== null && $ventasRango > 0) $ticket = $facturacion / $ventasRango;

    csvOut(['kpi', 'valor'], $out, $D);
    csvOut(['movimientos_rango', $movRango], $out, $D);
    csvOut(['ventas_rango', $ventasRango], $out, $D);
    csvOut(['unidades_vendidas', $unidades], $out, $D);
    csvOut(['facturacion_rango', $facturacion === null ? '' : number_format($facturacion, 2, '.', '')], $out, $D);
    csvOut(['ticket_promedio', $ticket === null ? '' : number_format($ticket, 2, '.', '')], $out, $D);
  }

  /* =========================
     TOP PRODUCTOS
  ========================= */
  if ($type === 'top_productos') {
    csvOut(['producto', 'unidades'], $out, $D);

    if ($hasVentas && $hasVentaItems && $hasProductos && $ventasFechaCol && $viVentaIdCol && $viProdIdCol && $viQtyCol && $prodNombreCol) {
      $sql = "
        SELECT p.`{$prodNombreCol}` AS producto, COALESCE(SUM(vi.`{$viQtyCol}`),0) AS unidades
        FROM venta_items vi
        JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
        JOIN productos p ON p.id = vi.`{$viProdIdCol}`
        WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$emitidaCond}
        GROUP BY p.id
        ORDER BY unidades DESC
        LIMIT 50
      ";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$fromStart, $toEnd]);
      while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        csvOut([$r['producto'], (int)$r['unidades']], $out, $D);
      }
    } else {
      csvOut(['ERROR', 'Faltan tablas/columnas para top_productos'], $out, $D);
    }
  }

  /* =========================
     METODOS DE PAGO
  ========================= */
  if ($type === 'metodos_pago') {
    csvOut(['medio_pago', 'cantidad', 'monto', 'ticket_promedio'], $out, $D);

    if ($hasVentas && $ventasFechaCol && $ventasTotalCol && $ventasMedioCol) {
      $sql = "
        SELECT `{$ventasMedioCol}` AS medio_pago,
               COUNT(*) AS cantidad,
               COALESCE(SUM({$ventasTotalSQL}),0) AS monto,
               COALESCE(AVG({$ventasTotalSQL}),0) AS ticket_promedio
        FROM ventas
        WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$emitidaCond}
        GROUP BY `{$ventasMedioCol}`
        ORDER BY monto DESC
      ";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$fromStart, $toEnd]);
      while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        csvOut([$r['medio_pago'], $r['cantidad'], $r['monto'], $r['ticket_promedio']], $out, $D);
      }
    } else {
      csvOut(['ERROR', 'Faltan columnas medio_pago/total/fecha en ventas'], $out, $D);
    }
  }

  /* =========================
     CATEGORIAS
  ========================= */
  if ($type === 'categorias') {
    csvOut(['categoria', 'unidades', 'ventas', 'num_ventas'], $out, $D);

    if ($hasVentas && $hasVentaItems && $hasProductos && $ventasFechaCol && $ventasTotalCol && $viVentaIdCol && $viProdIdCol && $viQtyCol) {
      $catSelect = $prodCatCol ? "COALESCE(p.`{$prodCatCol}`,'Sin Categoría')" : "'Sin Categoría'";
      $lineVi  = $lineExprForAlias('vi');
      $lineVi2 = $lineExprForAlias('vi2');

      if ($lineVi && $lineVi2) {
        $sql = "
          SELECT
            {$catSelect} AS categoria,
            SUM(vi.`{$viQtyCol}`) AS unidades,
            COALESCE(SUM(v.{$ventasTotalSQL} * ({$lineVi} / NULLIF(vt.subtotal_total,0))), 0) AS ventas,
            COUNT(DISTINCT vi.`{$viVentaIdCol}`) AS num_ventas
          FROM venta_items vi
          JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
          JOIN productos p ON p.id = vi.`{$viProdIdCol}`
          JOIN (
            SELECT vi2.`{$viVentaIdCol}` AS venta_id, SUM({$lineVi2}) AS subtotal_total
            FROM venta_items vi2
            JOIN ventas v2 ON v2.id = vi2.`{$viVentaIdCol}`
            WHERE v2.{$ventasDateSQL} >= ? AND v2.{$ventasDateSQL} < ? {$emitidaCond}
            GROUP BY vi2.`{$viVentaIdCol}`
          ) vt ON vt.venta_id = vi.`{$viVentaIdCol}`
          WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$emitidaCond}
            AND vt.subtotal_total > 0
          GROUP BY categoria
          ORDER BY ventas DESC
          LIMIT 50
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$fromStart, $toEnd, $fromStart, $toEnd]);
      } else {
        $fallbackLine = $viLineCol ? "vi.`{$viLineCol}`" : (($viQtyCol && $viPriceCol) ? "(vi.`{$viQtyCol}` * vi.`{$viPriceCol}`)" : "0");
        $sql = "
          SELECT
            {$catSelect} AS categoria,
            SUM(vi.`{$viQtyCol}`) AS unidades,
            COALESCE(SUM({$fallbackLine}),0) AS ventas,
            COUNT(DISTINCT vi.`{$viVentaIdCol}`) AS num_ventas
          FROM venta_items vi
          JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
          JOIN productos p ON p.id = vi.`{$viProdIdCol}`
          WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$emitidaCond}
          GROUP BY categoria
          ORDER BY ventas DESC
          LIMIT 50
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$fromStart, $toEnd]);
      }

      while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        csvOut([$r['categoria'], $r['unidades'], $r['ventas'], $r['num_ventas']], $out, $D);
      }
    } else {
      csvOut(['ERROR', 'Faltan tablas/columnas para categorias'], $out, $D);
    }
  }

  /* =========================
     RENTABLES
  ========================= */
  if ($type === 'rentables') {
    csvOut(['producto', 'unidades', 'ventas', 'costos', 'ganancia', 'margen_pct'], $out, $D);

    $can = $hasVentas && $hasVentaItems && $hasProductos
      && $ventasFechaCol && $ventasTotalCol
      && $viVentaIdCol && $viProdIdCol && $viQtyCol
      && $prodNombreCol && $prodCostoCol;

    $lineVi  = $lineExprForAlias('vi');
    $lineVi2 = $lineExprForAlias('vi2');

    if ($can && $lineVi && $lineVi2) {
      $sql = "
        SELECT
          p.`{$prodNombreCol}` AS producto,
          SUM(vi.`{$viQtyCol}`) AS unidades,
          COALESCE(SUM(v.{$ventasTotalSQL} * ({$lineVi} / NULLIF(vt.subtotal_total,0))), 0) AS ventas,
          COALESCE(SUM(vi.`{$viQtyCol}` * p.`{$prodCostoCol}`), 0) AS costos,
          COALESCE(SUM((v.{$ventasTotalSQL} * ({$lineVi} / NULLIF(vt.subtotal_total,0))) - (vi.`{$viQtyCol}` * p.`{$prodCostoCol}`)), 0) AS ganancia
        FROM venta_items vi
        JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
        JOIN productos p ON p.id = vi.`{$viProdIdCol}`
        JOIN (
          SELECT vi2.`{$viVentaIdCol}` AS venta_id, SUM({$lineVi2}) AS subtotal_total
          FROM venta_items vi2
          JOIN ventas v2 ON v2.id = vi2.`{$viVentaIdCol}`
          WHERE v2.{$ventasDateSQL} >= ? AND v2.{$ventasDateSQL} < ? {$emitidaCond}
          GROUP BY vi2.`{$viVentaIdCol}`
        ) vt ON vt.venta_id = vi.`{$viVentaIdCol}`
        WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$emitidaCond}
          AND vt.subtotal_total > 0
          AND p.`{$prodCostoCol}` IS NOT NULL
        GROUP BY p.id
        ORDER BY ganancia DESC
        LIMIT 100
      ";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$fromStart, $toEnd, $fromStart, $toEnd]);

      while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ventas = (float)$r['ventas'];
        $gan = (float)$r['ganancia'];
        $margen = ($ventas > 0) ? (($gan / $ventas) * 100) : 0.0;

        csvOut([
          $r['producto'],
          $r['unidades'],
          number_format($ventas, 2, '.', ''),
          number_format((float)$r['costos'], 2, '.', ''),
          number_format($gan, 2, '.', ''),
          number_format($margen, 2, '.', '')
        ], $out, $D);
      }
    } else {
      csvOut(['ERROR', 'Faltan columnas (subtotal/total/precio*cant) o costo para rentables'], $out, $D);
    }
  }

  /* =========================
     🆕 PRODUCTOS DORMIDOS
  ========================= */
  if ($type === 'dormidos') {
    $diasSinMovimiento = (int)($_GET['dias'] ?? 30);
    if ($diasSinMovimiento < 7) $diasSinMovimiento = 7;
    if ($diasSinMovimiento > 180) $diasSinMovimiento = 180;
    
    csvOut(['producto', 'categoria', 'stock', 'precio', 'valor_stock', 'ultima_venta', 'dias_sin_venta'], $out, $D);
    
    $can = $hasProductos && $prodNombreCol && $prodStockCol && $prodActivoCol && $hasMovs && $msProdCol && $msFechaCol;
    
    if ($can) {
      $fechaLimite = (new DateTime('today'))->modify("-{$diasSinMovimiento} days")->format('Y-m-d H:i:s');
      $catCol = $prodCatCol ? "COALESCE(p.`{$prodCatCol}`, 'Sin Categoría')" : "'Sin Categoría'";
      $precioCol = $prodPrecioCol ?: ($prodCostoCol ?: null);
      $valorExpr = $precioCol ? "p.`{$precioCol}`" : "0";
      
      $sql = "
        SELECT
          p.id,
          p.`{$prodNombreCol}` AS nombre,
          {$catCol} AS categoria,
          p.`{$prodStockCol}` AS stock,
          {$valorExpr} AS precio,
          (p.`{$prodStockCol}` * {$valorExpr}) AS valor_stock,
          MAX(ms.`{$msFechaCol}`) AS ultima_venta
        FROM productos p
        LEFT JOIN movimientos_stock ms
          ON ms.`{$msProdCol}` = p.id
          AND ms.`{$msTipoCol}` = 'VENTA'
        WHERE p.`{$prodActivoCol}` = 1
          AND p.`{$prodStockCol}` > 0
        GROUP BY p.id, p.`{$prodNombreCol}`, {$catCol}, p.`{$prodStockCol}`, {$valorExpr}
        HAVING ultima_venta IS NULL OR ultima_venta < ?
        ORDER BY valor_stock DESC
      ";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([$fechaLimite]);
      
      $totalCapital = 0.0;
      $rows = [];
      
      while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ultimaVenta = $r['ultima_venta'];
        $diasSin = $ultimaVenta 
          ? (int)(new DateTime($ultimaVenta))->diff(new DateTime('today'))->format('%a')
          : 999;
        
        $rows[] = [
          $r['nombre'],
          $r['categoria'],
          number_format((float)$r['stock'], 3, '.', ''),
          number_format((float)$r['precio'], 2, '.', ''),
          number_format((float)$r['valor_stock'], 2, '.', ''),
          $ultimaVenta ? (new DateTime($ultimaVenta))->format('Y-m-d') : 'Nunca',
          $diasSin === 999 ? 'Nunca vendido' : $diasSin
        ];
        
        $totalCapital += (float)$r['valor_stock'];
      }
      
      // Resumen al inicio
      csvOut([''], $out, $D);
      csvOut(['RESUMEN'], $out, $D);
      csvOut(['Total productos dormidos', count($rows)], $out, $D);
      csvOut(['Capital total parado', number_format($totalCapital, 2, '.', '')], $out, $D);
      csvOut(['Días sin movimiento', $diasSinMovimiento], $out, $D);
      csvOut([''], $out, $D);
      
      // Datos
      foreach ($rows as $row) {
        csvOut($row, $out, $D);
      }
      
    } else {
      csvOut(['ERROR', 'Faltan tablas/columnas para productos dormidos'], $out, $D);
    }
  }

  /* =========================
     🆕 CIERRE DE CAJA
  ========================= */
  if ($type === 'cierre_caja') {
    $fecha = validDateYmd($_GET['fecha'] ?? null) ?? $today;
    $fechaStart = $fecha . " 00:00:00";
    $fechaEnd = (new DateTime($fecha))->modify('+1 day')->format('Y-m-d') . " 00:00:00";
    
    csvOut(['CIERRE DE CAJA', $fecha], $out, $D);
    csvOut([''], $out, $D);
    
    if ($hasVentas && $ventasFechaCol && $ventasTotalCol) {
      // Total del día
      $stmt = $pdo->prepare("
        SELECT 
          COUNT(*) as total_ventas,
          COALESCE(SUM({$ventasTotalSQL}), 0) as monto_total,
          MIN({$ventasDateSQL}) as primera_venta,
          MAX({$ventasDateSQL}) as ultima_venta
        FROM ventas
        WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$emitidaCond}
      ");
      $stmt->execute([$fechaStart, $fechaEnd]);
      $resumen = $stmt->fetch(PDO::FETCH_ASSOC);
      
      csvOut(['Total ventas', $resumen['total_ventas']], $out, $D);
      csvOut(['Monto total', number_format((float)$resumen['monto_total'], 2, '.', '')], $out, $D);
      csvOut(['Primera venta', $resumen['primera_venta'] ?: 'N/A'], $out, $D);
      csvOut(['Última venta', $resumen['ultima_venta'] ?: 'N/A'], $out, $D);
      
      $ticketProm = $resumen['total_ventas'] > 0 
        ? (float)$resumen['monto_total'] / (int)$resumen['total_ventas'] 
        : 0;
      csvOut(['Ticket promedio', number_format($ticketProm, 2, '.', '')], $out, $D);
      
      // Anulaciones
      if ($ventasEstadoCol) {
        $anuladaCond = " AND `{$ventasEstadoCol}`='ANULADA' ";
        $stmt = $pdo->prepare("
          SELECT COUNT(*) as anulaciones, COALESCE(SUM({$ventasTotalSQL}), 0) as monto
          FROM ventas
          WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$anuladaCond}
        ");
        $stmt->execute([$fechaStart, $fechaEnd]);
        $anul = $stmt->fetch(PDO::FETCH_ASSOC);
        csvOut(['Anulaciones', $anul['anulaciones']], $out, $D);
        csvOut(['Monto anulado', number_format((float)$anul['monto'], 2, '.', '')], $out, $D);
      }
      
      csvOut([''], $out, $D);
      csvOut(['DESGLOSE POR MEDIO DE PAGO'], $out, $D);
      csvOut(['medio_pago', 'cantidad', 'monto'], $out, $D);
      
      // Desglose por método de pago
      if ($ventasMedioCol) {
        $stmt = $pdo->prepare("
          SELECT `{$ventasMedioCol}` AS medio_pago,
                 COUNT(*) AS cantidad,
                 COALESCE(SUM({$ventasTotalSQL}),0) AS monto
          FROM ventas
          WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$emitidaCond}
          GROUP BY `{$ventasMedioCol}`
          ORDER BY monto DESC
        ");
        $stmt->execute([$fechaStart, $fechaEnd]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
          csvOut([$r['medio_pago'], $r['cantidad'], number_format((float)$r['monto'], 2, '.', '')], $out, $D);
        }
      }
      
    } else {
      csvOut(['ERROR', 'Faltan columnas fecha/total en ventas'], $out, $D);
    }
  }

} catch (Throwable $e) {
  csvOut(['ERROR', $e->getMessage()], $out, $D);
}

fclose($out);
exit;
<?php
// dashboard_export.php
require_once __DIR__ . '/auth.php';  // arranca sesión + trae $pdo
require_login();
require_once __DIR__ . '/../src/config.php';
$pdo = getPDO();

/* =========================
   HELPERS
========================= */
function validDateYmd(?string $s): ?string {
  if (!$s) return null;
  $d = DateTime::createFromFormat('Y-m-d', $s);
  return ($d && $d->format('Y-m-d') === $s) ? $s : null;
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
function csvOut(array $row, $out, string $delimiter=';'): void {
  fputcsv($out, $row, $delimiter);
}

/* =========================
   INPUTS
========================= */
$type = strtolower(trim($_GET['type'] ?? 'movimientos'));
$allowed = ['movimientos', 'kpis', 'top_productos'];
if (!in_array($type, $allowed, true)) $type = 'movimientos';

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
if ($diffDays > $maxDays) {
  $fromDT = (clone $toDT)->modify("-" . ($maxDays - 1) . " days");
  $from = $fromDT->format('Y-m-d');
}

// Para SQL: [from 00:00:00, to+1day 00:00:00)
$fromStart = $from . " 00:00:00";
$toEnd     = (new DateTime($to))->modify('+1 day')->format('Y-m-d') . " 00:00:00";

/* =========================
   DETECCIONES
========================= */
$hasVentas = tableExists($pdo, 'ventas');
$hasVentaItems = tableExists($pdo, 'venta_items');
$hasProductos = tableExists($pdo, 'productos');

$movPriceCol = null;
if (tableExists($pdo, 'movimientos_stock')) {
  if (columnExists($pdo, 'movimientos_stock', 'precio_unitario')) $movPriceCol = 'precio_unitario';
  else if (columnExists($pdo, 'movimientos_stock', 'precio')) $movPriceCol = 'precio';
}

// columna para “id de venta” dentro de movimientos_stock (si existe)
$saleIdCol = null;
if (tableExists($pdo, 'movimientos_stock')) {
  if (columnExists($pdo, 'movimientos_stock', 'venta_id')) $saleIdCol = 'venta_id';
  else if (columnExists($pdo, 'movimientos_stock', 'referencia_venta_id')) $saleIdCol = 'referencia_venta_id';
}

// columnas en ventas / venta_items
$ventasHasTotal = $hasVentas && columnExists($pdo, 'ventas', 'total');
$ventasHasFecha = $hasVentas && columnExists($pdo, 'ventas', 'fecha');

$viPriceCol = null;
$viSubtotalCol = null;
if ($hasVentaItems) {
  if (columnExists($pdo, 'venta_items', 'subtotal')) $viSubtotalCol = 'subtotal';
  else if (columnExists($pdo, 'venta_items', 'total')) $viSubtotalCol = 'total';

  if (columnExists($pdo, 'venta_items', 'precio_unitario')) $viPriceCol = 'precio_unitario';
  else if (columnExists($pdo, 'venta_items', 'precio')) $viPriceCol = 'precio';
}

/* =========================
   RESPONSE HEADERS
========================= */
$filename = "dashboard_{$type}_{$from}_al_{$to}.csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');

// BOM para Excel (acentos OK)
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
$D = ';';

// fila info (sirve para auditoría)
csvOut(['INFO', 'Rango', $from, $to, 'maxDays', (string)$maxDays], $out, $D);

/* =========================
   EXPORTS
========================= */
try {

  if ($type === 'movimientos') {
    csvOut(['fecha', 'tipo', 'producto', 'cantidad', 'precio_unitario'], $out, $D);

    $sql = "
      SELECT m.fecha, m.tipo,
             " . ($hasProductos ? "p.nombre" : "NULL") . " AS producto,
             m.cantidad
             " . ($movPriceCol ? ", m.$movPriceCol AS precio_unitario" : ", NULL AS precio_unitario") . "
      FROM movimientos_stock m
      " . ($hasProductos ? "LEFT JOIN productos p ON p.id = m.producto_id" : "") . "
      WHERE m.fecha >= :fromStart AND m.fecha < :toEnd
      ORDER BY m.fecha ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':fromStart' => $fromStart, ':toEnd' => $toEnd]);

    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
      csvOut([
        $r['fecha'],
        $r['tipo'],
        $r['producto'],
        $r['cantidad'],
        $r['precio_unitario'],
      ], $out, $D);
    }
  }

  if ($type === 'kpis') {
    // --- KPIs de Stock ---
    $totalProductos = 0; $stockOk = 0; $stockBajo = 0; $sinStock = 0; $inactivos = 0;

    if ($hasProductos) {
      $totalProductos = (int)$pdo->query("SELECT COUNT(*) FROM productos")->fetchColumn();

      $stockOk = (int)$pdo->query("
        SELECT COUNT(*) FROM productos
        WHERE stock > stock_minimo AND stock > 0 AND activo = 1
      ")->fetchColumn();

      $stockBajo = (int)$pdo->query("
        SELECT COUNT(*) FROM productos
        WHERE stock > 0 AND stock <= stock_minimo AND activo = 1
      ")->fetchColumn();

      $sinStock = (int)$pdo->query("
        SELECT COUNT(*) FROM productos
        WHERE stock <= 0 AND activo = 1
      ")->fetchColumn();

      $inactivos = (int)$pdo->query("SELECT COUNT(*) FROM productos WHERE activo = 0")->fetchColumn();
    }

    // --- KPIs de Rango ---
    // movimientos rango
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM movimientos_stock WHERE fecha >= :fromStart AND fecha < :toEnd");
    $stmt->execute([':fromStart'=>$fromStart, ':toEnd'=>$toEnd]);
    $movRango = (int)$stmt->fetchColumn();

    // ventas rango (mejor fuente: tabla ventas si existe y tiene fecha)
    $ventasRango = 0;
    if ($ventasHasFecha) {
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM ventas WHERE fecha >= :fromStart AND fecha < :toEnd");
      $stmt->execute([':fromStart'=>$fromStart, ':toEnd'=>$toEnd]);
      $ventasRango = (int)$stmt->fetchColumn();
    } else if ($saleIdCol) {
      $sql = "
        SELECT COUNT(DISTINCT $saleIdCol)
        FROM movimientos_stock
        WHERE tipo='VENTA'
          AND $saleIdCol IS NOT NULL
          AND fecha >= :fromStart AND fecha < :toEnd
      ";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([':fromStart'=>$fromStart, ':toEnd'=>$toEnd]);
      $ventasRango = (int)$stmt->fetchColumn();
    } else {
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM movimientos_stock WHERE tipo='VENTA' AND fecha >= :fromStart AND fecha < :toEnd");
      $stmt->execute([':fromStart'=>$fromStart, ':toEnd'=>$toEnd]);
      $ventasRango = (int)$stmt->fetchColumn();
    }

    // unidades vendidas
    $unidades = 0;
    if ($hasVentas && $hasVentaItems && $ventasHasFecha && columnExists($pdo, 'venta_items', 'cantidad') && columnExists($pdo, 'venta_items', 'venta_id')) {
      $sql = "
        SELECT COALESCE(SUM(vi.cantidad),0)
        FROM venta_items vi
        JOIN ventas v ON v.id = vi.venta_id
        WHERE v.fecha >= :fromStart AND v.fecha < :toEnd
      ";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([':fromStart'=>$fromStart, ':toEnd'=>$toEnd]);
      $unidades = (int)$stmt->fetchColumn();
    } else {
      $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(cantidad),0)
        FROM movimientos_stock
        WHERE tipo='VENTA' AND fecha >= :fromStart AND fecha < :toEnd
      ");
      $stmt->execute([':fromStart'=>$fromStart, ':toEnd'=>$toEnd]);
      $unidades = (int)$stmt->fetchColumn();
    }

    // facturación
    $facturacion = null; // numeric
    if ($ventasHasTotal && $ventasHasFecha) {
      $stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM ventas WHERE fecha >= :fromStart AND fecha < :toEnd");
      $stmt->execute([':fromStart'=>$fromStart, ':toEnd'=>$toEnd]);
      $facturacion = (float)$stmt->fetchColumn();
    } else if ($hasVentas && $hasVentaItems && $ventasHasFecha && columnExists($pdo, 'venta_items', 'venta_id') && columnExists($pdo, 'venta_items', 'cantidad') && ($viSubtotalCol || $viPriceCol)) {
      if ($viSubtotalCol) {
        $sql = "
          SELECT COALESCE(SUM(vi.$viSubtotalCol),0)
          FROM venta_items vi
          JOIN ventas v ON v.id = vi.venta_id
          WHERE v.fecha >= :fromStart AND v.fecha < :toEnd
        ";
      } else {
        $sql = "
          SELECT COALESCE(SUM(vi.cantidad * vi.$viPriceCol),0)
          FROM venta_items vi
          JOIN ventas v ON v.id = vi.venta_id
          WHERE v.fecha >= :fromStart AND v.fecha < :toEnd
        ";
      }
      $stmt = $pdo->prepare($sql);
      $stmt->execute([':fromStart'=>$fromStart, ':toEnd'=>$toEnd]);
      $facturacion = (float)$stmt->fetchColumn();
    } else if ($movPriceCol) {
      $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(cantidad * $movPriceCol),0)
        FROM movimientos_stock
        WHERE tipo='VENTA' AND fecha >= :fromStart AND fecha < :toEnd
      ");
      $stmt->execute([':fromStart'=>$fromStart, ':toEnd'=>$toEnd]);
      $facturacion = (float)$stmt->fetchColumn();
    }

    $ticket = null;
    if ($facturacion !== null && $ventasRango > 0) $ticket = $facturacion / $ventasRango;

    // salida
    csvOut(['kpi', 'valor'], $out, $D);

    // stock
    csvOut(['total_productos', $totalProductos], $out, $D);
    csvOut(['stock_ok', $stockOk], $out, $D);
    csvOut(['stock_bajo', $stockBajo], $out, $D);
    csvOut(['sin_stock', $sinStock], $out, $D);
    csvOut(['inactivos', $inactivos], $out, $D);

    // rango
    csvOut(['movimientos_rango', $movRango], $out, $D);
    csvOut(['ventas_rango', $ventasRango], $out, $D);
    csvOut(['unidades_vendidas', $unidades], $out, $D);

    // facturación/ticket: si no pudieron calcularse, lo dejamos vacío (no mentimos)
    csvOut(['facturacion_rango', $facturacion === null ? '' : number_format($facturacion, 2, '.', '')], $out, $D);
    csvOut(['ticket_promedio', $ticket === null ? '' : number_format($ticket, 2, '.', '')], $out, $D);

    // nota para futuro
    csvOut(['nota', 'Si facturacion/ticket están vacíos: falta total en ventas o precio en items/movimientos'], $out, $D);
  }

  if ($type === 'top_productos') {
    csvOut(['producto', 'unidades'], $out, $D);

    // Mejor fuente: venta_items + ventas (si existe)
    if ($hasVentas && $hasVentaItems && $ventasHasFecha
        && $hasProductos
        && columnExists($pdo, 'venta_items', 'venta_id')
        && columnExists($pdo, 'venta_items', 'producto_id')
        && columnExists($pdo, 'venta_items', 'cantidad')) {

      $sql = "
        SELECT p.nombre AS producto, COALESCE(SUM(vi.cantidad),0) AS unidades
        FROM venta_items vi
        JOIN ventas v ON v.id = vi.venta_id
        JOIN productos p ON p.id = vi.producto_id
        WHERE v.fecha >= :fromStart AND v.fecha < :toEnd
        GROUP BY p.id
        ORDER BY unidades DESC
        LIMIT 10
      ";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([':fromStart'=>$fromStart, ':toEnd'=>$toEnd]);

      while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        csvOut([$r['producto'], (int)$r['unidades']], $out, $D);
      }

    } else {
      // Fallback: movimientos_stock
      $sql = "
        SELECT " . ($hasProductos ? "p.nombre" : "m.producto_id") . " AS producto,
               COALESCE(SUM(m.cantidad),0) AS unidades
        FROM movimientos_stock m
        " . ($hasProductos ? "LEFT JOIN productos p ON p.id = m.producto_id" : "") . "
        WHERE m.tipo='VENTA'
          AND m.fecha >= :fromStart AND m.fecha < :toEnd
        GROUP BY m.producto_id
        ORDER BY unidades DESC
        LIMIT 10
      ";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([':fromStart'=>$fromStart, ':toEnd'=>$toEnd]);

      while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        csvOut([$r['producto'], (int)$r['unidades']], $out, $D);
      }
    }
  }

} catch (Throwable $e) {
  // si hay error, lo dejamos en CSV (mejor que pantalla blanca)
  csvOut(['ERROR', $e->getMessage()], $out, $D);
}

fclose($out);
exit;

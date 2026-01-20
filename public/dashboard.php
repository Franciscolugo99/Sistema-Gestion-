<?php
// public/dashboard.php - DASHBOARD AVANZADO v4 (Con ayuda contextual)
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('ver_reportes');

/* =========================
   DEFINICIONES DE AYUDA CONTEXTUAL
========================= */
$kpiTooltips = [
    'ventas' => [
        'title' => '¿Qué son las Ventas?',
        'desc' => 'Número total de tickets/transacciones completadas en el período seleccionado.',
        'calc' => 'Cuenta de ventas con estado EMITIDA',
        'tip' => 'Compara con períodos anteriores para identificar tendencias. Un aumento constante indica crecimiento saludable.'
    ],
    'facturacion' => [
        'title' => '¿Qué es la Facturación?',
        'desc' => 'Suma total del dinero recibido por todas las ventas. Incluye todos los medios de pago.',
        'calc' => 'Σ (total de cada venta emitida)',
        'tip' => 'Este es tu ingreso bruto. Para conocer la ganancia real, revisa el análisis de rentabilidad.'
    ],
    'ticket_promedio' => [
        'title' => '¿Qué es el Ticket Promedio?',
        'desc' => 'Cuánto gasta en promedio cada cliente por compra. Indica el valor típico de una transacción.',
        'calc' => 'Facturación ÷ Número de ventas',
        'tip' => 'Para aumentarlo: ofrece productos complementarios, promociones por monto mínimo o combos.'
    ],
    'unidades' => [
        'title' => '¿Qué son las Unidades Vendidas?',
        'desc' => 'Cantidad total de productos vendidos (suma de cantidades de todos los items).',
        'calc' => 'Σ (cantidad de cada línea de venta)',
        'tip' => 'Útil para planificar reposición de stock y detectar productos estrella.'
    ],
    'ganancia' => [
        'title' => '¿Qué es la Ganancia Bruta?',
        'desc' => 'Diferencia entre lo que vendiste y lo que te costó la mercadería. Es tu utilidad antes de gastos operativos.',
        'calc' => 'Facturación − Costo de mercadería vendida',
        'tip' => 'Si es negativa, estás vendiendo por debajo del costo. Revisa precios urgentemente.'
    ],
    'margen' => [
        'title' => '¿Qué es el Margen?',
        'desc' => 'Porcentaje de cada peso vendido que queda como ganancia. Indica qué tan rentable es tu operación.',
        'calc' => '(Ganancia ÷ Facturación) × 100',
        'tip' => 'Un margen del 30-40% es saludable para retail. Menos del 20% puede ser problemático.'
    ],
    'costos' => [
        'title' => '¿Qué es el Total de Costos?',
        'desc' => 'Suma de lo que pagaste por la mercadería que vendiste (costo de adquisición).',
        'calc' => 'Σ (cantidad × costo unitario) de productos vendidos',
        'tip' => 'Mantén actualizados los costos de tus productos para que este cálculo sea preciso.'
    ],
    'descuentos' => [
        'title' => '¿Qué son los Descuentos por Promos?',
        'desc' => 'Total de dinero descontado a clientes por promociones activas.',
        'calc' => 'Σ (descuentos aplicados por promociones)',
        'tip' => 'Monitorea que las promos generen más ventas de las que cuestan en descuentos.'
    ],
    'anulaciones' => [
        'title' => '¿Qué son las Ventas Anuladas?',
        'desc' => 'Ventas que se cancelaron o revirtieron después de emitirse.',
        'calc' => 'Cuenta de ventas con estado ANULADA',
        'tip' => 'Una tasa mayor al 5% indica problemas. Investiga las causas: errores, devoluciones, etc.'
    ],
    'tasa_anulacion' => [
        'title' => '¿Qué es la Tasa de Anulación?',
        'desc' => 'Porcentaje de ventas que terminaron siendo anuladas respecto al total.',
        'calc' => '(Anuladas ÷ Total de ventas) × 100',
        'tip' => 'Menos del 2% es excelente, 2-5% es aceptable, más del 5% requiere atención.'
    ],
    'monto_anulado' => [
        'title' => '¿Qué es el Monto Anulado?',
        'desc' => 'Suma del valor de todas las ventas que fueron anuladas.',
        'calc' => 'Σ (total de ventas anuladas)',
        'tip' => 'Representa dinero que esperabas recibir pero no se concretó.'
    ],
];

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

// Función para determinar el estado del KPI
function getKpiStatus(string $type, float $value): array {
    switch ($type) {
        case 'margen':
            if ($value >= 35) return ['class' => 'kpi-status-excellent', 'text' => 'Excelente'];
            if ($value >= 25) return ['class' => 'kpi-status-good', 'text' => 'Bueno'];
            if ($value >= 15) return ['class' => 'kpi-status-attention', 'text' => 'Revisar'];
            return ['class' => 'kpi-status-critical', 'text' => 'Crítico'];
            
        case 'tasa_anulacion':
            if ($value <= 2) return ['class' => 'kpi-status-excellent', 'text' => 'Excelente'];
            if ($value <= 5) return ['class' => 'kpi-status-good', 'text' => 'Normal'];
            if ($value <= 10) return ['class' => 'kpi-status-attention', 'text' => 'Revisar'];
            return ['class' => 'kpi-status-critical', 'text' => 'Crítico'];
            
        default:
            return ['class' => '', 'text' => ''];
    }
}

/* =========================
   DETECCIONES (tablas/cols)
========================= */
$hasVentas       = tableExists($pdo, 'ventas');
$hasVentaItems   = tableExists($pdo, 'venta_items');
$hasProductos    = tableExists($pdo, 'productos');
$hasMovimientos  = tableExists($pdo, 'movimientos_stock');
$hasVentaPromos  = tableExists($pdo, 'venta_promos');
$hasCierresCaja  = tableExists($pdo, 'cierres_caja');

$ventasFechaCol  = $hasVentas ? firstExistingColumn($pdo, 'ventas', ['fecha','created_at','fecha_hora']) : null;
$ventasTotalCol  = $hasVentas ? firstExistingColumn($pdo, 'ventas', ['total','monto_total','importe_total']) : null;
$ventasEstadoCol = $hasVentas ? firstExistingColumn($pdo, 'ventas', ['estado','status']) : null;
$ventasMedioCol  = $hasVentas ? firstExistingColumn($pdo, 'ventas', ['medio_pago','metodo_pago','pago_tipo']) : null;
$ventasCajaCol   = $hasVentas ? firstExistingColumn($pdo, 'ventas', ['caja_id','terminal_id']) : null;

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
$prodPrecioCol   = $hasProductos ? firstExistingColumn($pdo, 'productos', ['precio','precio_venta','price']) : null;

$msFechaCol      = $hasMovimientos ? firstExistingColumn($pdo, 'movimientos_stock', ['fecha','created_at']) : null;
$msTipoCol       = $hasMovimientos ? firstExistingColumn($pdo, 'movimientos_stock', ['tipo']) : null;
$msProdIdCol     = $hasMovimientos ? firstExistingColumn($pdo, 'movimientos_stock', ['producto_id']) : null;
$msCantCol       = $hasMovimientos ? firstExistingColumn($pdo, 'movimientos_stock', ['cantidad']) : null;

/* Expr "importe de línea" para prorratear neto (evita vi.subtotal inexistente) */
$lineExprForAlias = function(string $alias) use ($viLineCol, $viQtyCol, $viPriceCol): ?string {
  if ($viLineCol) return "{$alias}.`{$viLineCol}`";
  if ($viQtyCol && $viPriceCol) return "({$alias}.`{$viQtyCol}` * {$alias}.`{$viPriceCol}`)";
  return null;
};

/* =========================
   OBTENER CATEGORÍAS DISPONIBLES
========================= */
$categoriasDisponibles = [];
if ($hasProductos && $prodCatCol) {
  $stmt = $pdo->query("
    SELECT DISTINCT COALESCE(NULLIF(TRIM(`{$prodCatCol}`), ''), 'Sin Categoría') AS categoria
    FROM productos
    ORDER BY categoria
  ");
  $categoriasDisponibles = $stmt->fetchAll(PDO::FETCH_COLUMN);
}


/* =========================
   RANGO DE FECHAS + FILTRO CATEGORÍA
========================= */
$today       = (new DateTime('today'))->format('Y-m-d');
$defaultFrom = (new DateTime('today'))->modify('-29 days')->format('Y-m-d');
$defaultTo   = $today;

$from = validDateYmd($_GET['from'] ?? null) ?? $defaultFrom;
$to   = validDateYmd($_GET['to'] ?? null) ?? $defaultTo;
$categoriaFiltro = isset($_GET['categoria']) && $_GET['categoria'] !== '' ? trim($_GET['categoria']) : null;

// Filtros de hora
$horaDesde = isset($_GET['hora_desde']) && $_GET['hora_desde'] !== '' ? trim($_GET['hora_desde']) : null;
$horaHasta = isset($_GET['hora_hasta']) && $_GET['hora_hasta'] !== '' ? trim($_GET['hora_hasta']) : null;
$horaDesdeAmpm = isset($_GET['hora_desde_ampm']) ? strtoupper(trim((string)$_GET['hora_desde_ampm'])) : 'AUTO';
$horaHastaAmpm = isset($_GET['hora_hasta_ampm']) ? strtoupper(trim((string)$_GET['hora_hasta_ampm'])) : 'AUTO';

if (!in_array($horaDesdeAmpm, ['AUTO','AM','PM'], true)) $horaDesdeAmpm = 'AUTO';
if (!in_array($horaHastaAmpm, ['AUTO','AM','PM'], true)) $horaHastaAmpm = 'AUTO';

function flus_time_to_24h(string $hhmm, string $ampm): string {
  $ampm = strtoupper($ampm);
  if ($ampm !== 'AM' && $ampm !== 'PM') return $hhmm;

  [$h, $m] = array_map('intval', explode(':', $hhmm, 2));
  // Si ya está en 13..23, ignoramos AM/PM
  if ($h >= 13) return $hhmm;

  // Interpretación 12h
  if ($h === 12) {
    $h = ($ampm === 'AM') ? 0 : 12;
  } elseif ($h >= 1 && $h <= 11) {
    if ($ampm === 'PM') $h += 12;
  }
  return sprintf('%02d:%02d', $h, $m);
}

// Validar formato HH:MM
if ($horaDesde && !preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', $horaDesde)) {
  $horaDesde = null;
}
if ($horaHasta && !preg_match('/^([0-1]?[0-9]|2[0-3]):[0-5][0-9]$/', $horaHasta)) {
  $horaHasta = null;
}

if ($from > $to) [$from, $to] = [$to, $from];
$horaDesdeSql = $horaDesde ? flus_time_to_24h($horaDesde, $horaDesdeAmpm) : null;
$horaHastaSql = $horaHasta ? flus_time_to_24h($horaHasta, $horaHastaAmpm) : null;

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

// Aplicar filtro de horas si están definidos (convertidos a 24h para SQL)
if ($horaDesdeSql) {
  $fromStart = $from . " " . $horaDesdeSql . ":00";
}
if ($horaHastaSql) {
  $toEnd = $to . " " . $horaHastaSql . ":59";
}


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

/* Condición de filtro por categoría */
$categoriaJoinCond = "";
$categoriaWhereCond = "";
if ($categoriaFiltro && $hasProductos && $prodCatCol && $hasVentaItems && $viProdIdCol) {
  $categoriaWhereCond = " AND p.`{$prodCatCol}` = " . $pdo->quote($categoriaFiltro) . " ";
}

/* =========================
   CONFIGURACIÓN
========================= */
$diasSinMovimiento = 30; // Días sin movimiento para considerar producto "dormido"

/* =========================
   CACHE (APCu o File cache opcional - 5 min)
========================= */
$dashCacheTtl = 300;
$dashCacheHit = false;
$dashCached   = null;

$sid = (session_status() === PHP_SESSION_ACTIVE) ? session_id() : '';
$dashKeyBase = md5($from . '|' . $to . '|' . ($categoriaFiltro ?? '') . '|' . ($horaDesde ?? '') . '|' . ($horaHasta ?? '') . '|' . json_encode($_GET) . '|' . $sid);

$dashCacheApcuEnabled = function_exists('apcu_fetch') && (bool)ini_get('apc.enabled');
$dashCacheApcuKey = 'flus_dash_v4:' . $dashKeyBase;

$dashCacheFileEnabled = true;
$dashCacheFile = '';
$root = defined('FLUS_ROOT') ? FLUS_ROOT : dirname(__DIR__);
$cacheDir = $root . '/storage/cache';
if (!is_dir($cacheDir)) {
  @mkdir($cacheDir, 0775, true);
}
if (is_dir($cacheDir) && is_writable($cacheDir)) {
  $dashCacheFile = $cacheDir . '/dashboard_' . $dashKeyBase . '.json';
} else {
  $dashCacheFileEnabled = false;
}

if ($dashCacheApcuEnabled) {
  $dashCached = apcu_fetch($dashCacheApcuKey, $dashCacheHit);
  if (!($dashCacheHit && is_array($dashCached))) {
    $dashCacheHit = false;
    $dashCached = null;
  }
}

if (!$dashCacheHit && $dashCacheFileEnabled && $dashCacheFile && is_file($dashCacheFile)) {
  $age = time() - (int)@filemtime($dashCacheFile);
  if ($age >= 0 && $age <= $dashCacheTtl) {
    $json = @file_get_contents($dashCacheFile);
    $arr = is_string($json) ? json_decode($json, true) : null;
    if (is_array($arr)) {
      $dashCached = $arr;
      $dashCacheHit = true;
    }
  }
}

if ($dashCacheHit && is_array($dashCached)) {
  foreach ($dashCached as $k => $v) {
    if (is_string($k) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $k)) {
      ${$k} = $v;
    }
  }
}


/* =========================
   KPIs BÁSICOS
========================= */
if (!$dashCacheHit) {

$movimientosRango = 0;
$ventasRango = 0;
$facturacionRango = 0.0;
$unidadesVendidasRango = 0.0;

if ($hasMovimientos && $msFechaCol) {
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM movimientos_stock WHERE `{$msFechaCol}` >= ? AND `{$msFechaCol}` < ?");
  $stmt->execute([$fromStart, $toEnd]);
  $movimientosRango = (int)$stmt->fetchColumn();
}

// KPIs con o sin filtro de categoría
if ($hasVentas && $ventasFechaCol) {
  if ($categoriaFiltro && $hasVentaItems && $viVentaIdCol && $hasProductos && $prodCatCol) {
    // Con filtro de categoría - contar ventas que incluyen productos de esa categoría
    $stmt = $pdo->prepare("
      SELECT COUNT(DISTINCT v.id)
      FROM ventas v
      JOIN venta_items vi ON v.id = vi.`{$viVentaIdCol}`
      JOIN productos p ON p.id = vi.`{$viProdIdCol}`
      WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
        AND p.`{$prodCatCol}` = ?
    ");
    $stmt->execute([$fromStart, $toEnd, $categoriaFiltro]);
    $ventasRango = (int)$stmt->fetchColumn();
    
    // Facturación filtrada por categoría (solo líneas de esa categoría)
    if ($ventasTotalCol && $viLineCol) {
      $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(vi.`{$viLineCol}`),0)
        FROM venta_items vi
        JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
        JOIN productos p ON p.id = vi.`{$viProdIdCol}`
        WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
          AND p.`{$prodCatCol}` = ?
      ");
      $stmt->execute([$fromStart, $toEnd, $categoriaFiltro]);
      $facturacionRango = (float)$stmt->fetchColumn();
    }
  } else {
    // Sin filtro
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ventas WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}");
    $stmt->execute([$fromStart, $toEnd]);
    $ventasRango = (int)$stmt->fetchColumn();

    if ($ventasTotalCol) {
      $stmt = $pdo->prepare("SELECT COALESCE(SUM({$ventasTotalSQL}),0) FROM ventas WHERE {$ventasDateSQL} >= ? AND {$ventasDateSQL} < ? {$ventasEmitidaCond}");
      $stmt->execute([$fromStart, $toEnd]);
      $facturacionRango = (float)$stmt->fetchColumn();
    }
  }
}

if ($hasVentas && $hasVentaItems && $viVentaIdCol && $viQtyCol && $ventasFechaCol) {
  if ($categoriaFiltro && $hasProductos && $prodCatCol) {
    $stmt = $pdo->prepare("
      SELECT COALESCE(SUM(vi.`{$viQtyCol}`),0)
      FROM venta_items vi
      JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
      JOIN productos p ON p.id = vi.`{$viProdIdCol}`
      WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
        AND p.`{$prodCatCol}` = ?
    ");
    $stmt->execute([$fromStart, $toEnd, $categoriaFiltro]);
  } else {
    $stmt = $pdo->prepare("
      SELECT COALESCE(SUM(vi.`{$viQtyCol}`),0)
      FROM venta_items vi
      JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
      WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
    ");
    $stmt->execute([$fromStart, $toEnd]);
  }
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
   RENTABILIDAD
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
  $catCondCostos = $categoriaFiltro ? " AND p.`{$prodCatCol}` = " . $pdo->quote($categoriaFiltro) : "";
  
  $stmt = $pdo->prepare("
    SELECT COALESCE(SUM(vi.`{$viQtyCol}` * p.`{$prodCostoCol}`), 0)
    FROM venta_items vi
    JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
    JOIN productos p ON p.id = vi.`{$viProdIdCol}`
    WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
      AND p.`{$prodCostoCol}` IS NOT NULL
      {$catCondCostos}
  ");
  $stmt->execute([$fromStart, $toEnd]);
  $totalCostos = (float)$stmt->fetchColumn();

  $gananciaBruta = $totalVentas - $totalCostos;
  $margenPorcentaje = ($totalVentas > 0) ? (($gananciaBruta / $totalVentas) * 100) : 0.0;

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
        {$catCondCostos}
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
   MÉTODOS DE PAGO
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
   PROMOS
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
   CATEGORÍAS
========================= */
$categorias = [];
if ($hasVentas && $hasVentaItems && $hasProductos && $ventasFechaCol && $ventasTotalCol && $viVentaIdCol && $viProdIdCol && $viQtyCol) {
  $catCol = $prodCatCol ?: null;
  $catSelect = $catCol
  ? "COALESCE(NULLIF(TRIM(p.`{$catCol}`), ''), 'Sin Categoría')"
  : "'Sin Categoría'";


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
      LIMIT 10
    ";
    $stmt = $pdo->prepare($sqlCategorias);
    $stmt->execute([$fromStart, $toEnd, $fromStart, $toEnd]);
    $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
  } else {
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
      LIMIT 10
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
  $catCondStock = $categoriaFiltro && $prodCatCol ? " AND p.`{$prodCatCol}` = " . $pdo->quote($categoriaFiltro) : "";
  
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

if ($hasProductos && $prodNombreCol && $prodStockCol && $prodActivoCol && $hasMovimientos && $msProdIdCol && $msFechaCol) {
  $fechaLimiteDormido = (new DateTime('today'))->modify("-{$diasSinMovimiento} days")->format('Y-m-d H:i:s');
  $catCondDormidos = $categoriaFiltro && $prodCatCol ? " AND p.`{$prodCatCol}` = " . $pdo->quote($categoriaFiltro) : "";
  $precioCol = $prodPrecioCol ?: ($prodCostoCol ?: null);
  $valorExpr = $precioCol ? "p.`{$precioCol}`" : "0";
  
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
   CIERRE DE CAJA - Resumen del día (HOY)
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
    $vpVentaId = firstExistingColumn($pdo, 'venta_pagos', ['venta_id']);
    $vpMedio   = firstExistingColumn($pdo, 'venta_pagos', ['medio_pago','metodo_pago']);
    $vpMonto   = firstExistingColumn($pdo, 'venta_pagos', ['monto','importe']);
    
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
   COMPARACIÓN vs período anterior
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
  $catCondTop = $categoriaFiltro && $prodCatCol ? " AND p.`{$prodCatCol}` = " . $pdo->quote($categoriaFiltro) : "";
  
  $stmt = $pdo->prepare("
    SELECT p.`{$prodNombreCol}` AS nombre, SUM(vi.`{$viQtyCol}`) AS total
    FROM venta_items vi
    JOIN ventas v ON v.id = vi.`{$viVentaIdCol}`
    JOIN productos p ON p.id = vi.`{$viProdIdCol}`
    WHERE v.{$ventasDateSQL} >= ? AND v.{$ventasDateSQL} < ? {$ventasEmitidaCond}
      {$catCondTop}
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
   SPARKLINES
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
     CACHE STORE
  ========================= */
  $dashPayload = [
    'movimientosRango'       => $movimientosRango ?? 0,
    'ventasRango'            => $ventasRango ?? 0,
    'facturacionRango'       => $facturacionRango ?? 0.0,
    'unidadesVendidasRango'  => $unidadesVendidasRango ?? 0.0,
    'ticketPromedio'         => $ticketPromedio ?? 0.0,

    'ventasAnuladas'         => $ventasAnuladas ?? 0,
    'tasaAnulacion'          => $tasaAnulacion ?? 0.0,
    'montoAnulado'           => $montoAnulado ?? 0.0,

    'gananciaBruta'          => $gananciaBruta ?? 0.0,
    'totalCostos'            => $totalCostos ?? 0.0,
    'totalDescuentosPromos'  => $totalDescuentosPromos ?? 0.0,
    'margenPorcentaje'       => $margenPorcentaje ?? 0.0,

    'ventasDelta'            => $ventasDelta ?? ['class'=>'','text'=>''],
    'factDelta'              => $factDelta ?? ['class'=>'','text'=>''],
    'ticketDelta'            => $ticketDelta ?? ['class'=>'','text'=>''],

    'ventasLabels'           => $ventasLabels ?? [],
    'ventasData'             => $ventasData ?? [],
    'topProductosLabels'     => $topProductosLabels ?? [],
    'topProductosData'       => $topProductosData ?? [],
    'metodosPago'            => $metodosPago ?? [],
    'categorias'             => $categorias ?? [],
    'ventasPorHora'          => $ventasPorHora ?? [],
    'ventasPorDiaSemana'     => $ventasPorDiaSemana ?? [],
    'productosRentables'     => $productosRentables ?? [],

    'stockCritico'           => $stockCritico ?? [],
    'productosDormidos'      => $productosDormidos ?? [],
    'capitalDormido'         => $capitalDormido ?? 0.0,
    'cierreCajaHoy'          => $cierreCajaHoy ?? [],
    'sparklineVentas'        => $sparklineVentas ?? [],
    'sparklineFacturacion'   => $sparklineFacturacion ?? [],
  ];

  if ($dashCacheApcuEnabled) {
    apcu_store($dashCacheApcuKey, $dashPayload, $dashCacheTtl);
  }

  if ($dashCacheFileEnabled && $dashCacheFile) {
    @file_put_contents($dashCacheFile, json_encode($dashPayload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
  }
}


/* =========================
   HEADER
========================= */
$pageTitle = 'Panel de Control';
$currentSection = 'dashboard';
$extraCss = [
  'assets/css/dashboard.css?v=3', 
  'assets/css/dashboard-advanced.css?v=3',
  'assets/css/dashboard-enhanced.css?v=1'
];

require __DIR__ . '/partials/header.php';
?>

<div id="dashToast" class="flus-toast" style="display:none;" role="status" aria-live="polite" aria-atomic="true"
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
        <button type="button" class="dash-chip" data-preset="today" aria-pressed="false" aria-label="Filtrar: Hoy">Hoy</button>
        <button type="button" class="dash-chip" data-preset="7d" aria-pressed="false" aria-label="Filtrar: Últimos 7 días">7d</button>
        <button type="button" class="dash-chip" data-preset="30d" aria-pressed="false" aria-label="Filtrar: Últimos 30 días">30d</button>
        <button type="button" class="dash-chip" data-preset="month" aria-pressed="false" aria-label="Filtrar: Este mes">Este mes</button>

        <?php if (!empty($categoriasDisponibles)): ?>
        <div class="dash-cat-filter">
          <select name="categoria" id="dashCategoria" class="dash-select" onchange="this.form.submit()">
            <option value="">📁 Todas las categorías</option>
            <?php foreach ($categoriasDisponibles as $cat): ?>
              <option value="<?= h($cat) ?>" <?= $categoriaFiltro === $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <details id="dashExportDD" class="dash-export-dd">
          <summary aria-label="Abrir opciones de exportación">📤 Exportar</summary>
          <div class="dash-export-dd-menu">
            <a class="dash-export" data-export-type="kpis" href="dashboard_export.php?type=kpis&from=<?= h($from) ?>&to=<?= h($to) ?>">📊 KPIs</a>
            <a class="dash-export" data-export-type="movimientos" href="dashboard_export.php?type=movimientos&from=<?= h($from) ?>&to=<?= h($to) ?>">📦 Movimientos</a>
            <a class="dash-export" data-export-type="top_productos" href="dashboard_export.php?type=top_productos&from=<?= h($from) ?>&to=<?= h($to) ?>">🏆 Top Productos</a>
            <a class="dash-export" data-export-type="metodos_pago" href="dashboard_export.php?type=metodos_pago&from=<?= h($from) ?>&to=<?= h($to) ?>">💳 Medios de pago</a>
            <a class="dash-export" data-export-type="categorias" href="dashboard_export.php?type=categorias&from=<?= h($from) ?>&to=<?= h($to) ?>">📁 Categorías</a>
            <a class="dash-export" data-export-type="rentables" href="dashboard_export.php?type=rentables&from=<?= h($from) ?>&to=<?= h($to) ?>">💰 Rentables</a>
            <a class="dash-export" data-export-type="dormidos" href="dashboard_export.php?type=dormidos&from=<?= h($from) ?>&to=<?= h($to) ?>">💤 Productos dormidos</a>
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
          
          <!-- Filtros de hora -->
          <label class="dash-hora-label">
            <span>🕐 Hora desde</span>
            <div class="dash-hora-row">
              <input type="time" id="dashHoraDesde" name="hora_desde" value="<?= h($horaDesde ?? '') ?>" class="dash-hora-input" />
              <select id="dashHoraDesdeAmpm" name="hora_desde_ampm" class="dash-ampm" aria-label="AM/PM desde">
                <option value="AUTO" <?= ($horaDesdeAmpm ?? 'AUTO') === 'AUTO' ? 'selected' : '' ?>>Auto</option>
                <option value="AM"   <?= ($horaDesdeAmpm ?? '') === 'AM' ? 'selected' : '' ?>>AM</option>
                <option value="PM"   <?= ($horaDesdeAmpm ?? '') === 'PM' ? 'selected' : '' ?>>PM</option>
              </select>
            </div>
          </label>

          <label class="dash-hora-label">
            <span>🕐 Hora hasta</span>
            <div class="dash-hora-row">
              <input type="time" id="dashHoraHasta" name="hora_hasta" value="<?= h($horaHasta ?? '') ?>" class="dash-hora-input" />
              <select id="dashHoraHastaAmpm" name="hora_hasta_ampm" class="dash-ampm" aria-label="AM/PM hasta">
                <option value="AUTO" <?= ($horaHastaAmpm ?? 'AUTO') === 'AUTO' ? 'selected' : '' ?>>Auto</option>
                <option value="AM"   <?= ($horaHastaAmpm ?? '') === 'AM' ? 'selected' : '' ?>>AM</option>
                <option value="PM"   <?= ($horaHastaAmpm ?? '') === 'PM' ? 'selected' : '' ?>>PM</option>
              </select>
            </div>
          </label>

          
          <?php if ($horaDesde || $horaHasta): ?>
          <button type="button" class="dash-clear-hours"
            onclick="
              document.getElementById('dashHoraDesde').value='';
              document.getElementById('dashHoraHasta').value='';
              const a=document.getElementById('dashHoraDesdeAmpm'); if(a) a.value='AUTO';
              const b=document.getElementById('dashHoraHastaAmpm'); if(b) b.value='AUTO';
              this.form.submit();
            "
            title="Limpiar filtro de horas">✕</button>
          <?php endif; ?>
          
          <button type="submit" class="dash-apply">Aplicar</button>
        </div>
        <div class="dash-range-hint">
          <?php if ($categoriaFiltro): ?>
            <span class="dash-filter-badge">🏷️ <?= h($categoriaFiltro) ?></span>
          <?php endif; ?>
          <?php if ($horaDesde || $horaHasta): ?>
            <span class="dash-filter-badge">
              🕐 <?= h($horaDesde ?? '00:00') ?> - <?= h($horaHasta ?? '23:59') ?>
            </span>
          <?php endif; ?>
          Rango: <strong><?= (new DateTime($from))->format('d/m/Y'); ?></strong>
          → <strong><?= (new DateTime($to))->format('d/m/Y'); ?></strong>
        </div>
      </div>
    </form>

    <!-- CIERRE DE CAJA HOY -->
    <div class="cierre-caja-section">
      <div class="cierre-caja-header">
        <h2 class="section-title">🧾 Cierre de Caja - Hoy <?= date('d/m/Y') ?></h2>
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
          <div class="cierre-icon">💵</div>
          <div class="cierre-content">
            <span class="cierre-label">Total del día</span>
            <span class="cierre-value">$ <?= number_format($cierreCajaHoy['monto_total'], 0, ',', '.') ?></span>
            <span class="cierre-sub"><?= $cierreCajaHoy['total_ventas'] ?> ventas • Ticket prom: $ <?= number_format($cierreCajaHoy['ticket_promedio'], 0, ',', '.') ?></span>
          </div>
        </div>
        
        <div class="cierre-caja-card cierre-efectivo">
          <div class="cierre-icon">💰</div>
          <div class="cierre-content">
            <span class="cierre-label">Efectivo en caja</span>
            <span class="cierre-value">$ <?= number_format($cierreCajaHoy['efectivo'], 0, ',', '.') ?></span>
            <span class="cierre-sub">Para arqueo</span>
          </div>
        </div>
        
        <div class="cierre-caja-card cierre-otros">
          <div class="cierre-icon">💳</div>
          <div class="cierre-content">
            <span class="cierre-label">Otros medios</span>
            <span class="cierre-value">$ <?= number_format($cierreCajaHoy['otros_medios'], 0, ',', '.') ?></span>
            <span class="cierre-sub">Tarjetas, transferencias, etc.</span>
          </div>
        </div>
        
        <?php if ($cierreCajaHoy['anulaciones'] > 0): ?>
        <div class="cierre-caja-card cierre-anulaciones">
          <div class="cierre-icon">❌</div>
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
        <summary>Ver desglose por método de pago</summary>
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
              $diaSemana = ['Domingo','Lunes','Martes','Miércoles','Jueves','Viernes','Sábado'][(int)(new DateTime($ventasLabels[$maxIdx]))->format('w')];
              $insights[] = [
                'icon' => '📈',
                'html' => 'Tu mejor día fue el <strong>' . h($mejorDia) . '</strong> (' . $diaSemana . ') con <strong>' . (int)$maxVentas . ' ventas</strong>',
                'tip' => 'Considera reforzar personal los ' . $diaSemana . '.'
              ];
            }
          }

          if (($ventasDelta['class'] ?? '') === 'kpi-up') {
            $insights[] = ['icon' => '🚀', 'html' => 'Ventas crecieron <strong>' . h($ventasDelta['text']) . '</strong> vs período anterior', 'tip' => '¡Sigue así! Analiza qué funcionó bien.'];
          } elseif (($ventasDelta['class'] ?? '') === 'kpi-down') {
            $insights[] = ['icon' => '⚠️', 'html' => 'Ventas bajaron <strong>' . h($ventasDelta['text']) . '</strong> vs período anterior', 'tip' => 'Revisa factores externos o considera una promoción.'];
          }

          if (!empty($productosRentables)) {
            $top = $productosRentables[0];
            $nombre = h((string)($top['nombre'] ?? 'Producto'));
            $ganancia = number_format((float)($top['ganancia'] ?? 0), 0, ',', '.');
            $insights[] = ['icon' => '💰', 'html' => "<strong>{$nombre}</strong> es tu producto más rentable (<strong>$ {$ganancia}</strong>)", 'tip' => 'Asegura stock suficiente de este producto.'];
          }

          if ($tasaAnulacion > 5) {
            $insights[] = ['icon' => '⚠️', 'html' => 'Tasa de anulación alta: <strong>' . h(number_format($tasaAnulacion, 1)) . '%</strong>', 'tip' => 'Investiga las causas: errores de caja, devoluciones, etc.'];
          } elseif ($ventasAnuladas === 0 && $ventasRango > 10) {
            $insights[] = ['icon' => '✅', 'html' => 'Excelente: <strong>0 anulaciones</strong> en el período', 'tip' => ''];
          }

          if ($capitalDormido > 0) {
            $countDormidos = count($productosDormidos);
            $insights[] = [
              'icon' => '💤', 
              'html' => "<strong>{$countDormidos} productos</strong> sin movimiento en 30 días. Capital parado: <strong>$ " . number_format($capitalDormido, 0, ',', '.') . "</strong>",
              'tip' => 'Considera promociones o liquidación para liberar capital.'
            ];
          }

          if (count($stockCritico) > 5) {
            $insights[] = ['icon' => '🔴', 'html' => '<strong>' . count($stockCritico) . ' productos</strong> con stock crítico. ¡Revisar reposición!', 'tip' => 'Haz pedido a proveedores urgentemente.'];
          }

          foreach ($insights as $in) {
            echo "<div class='insight-item'><span class='insight-icon'>{$in['icon']}</span> {$in['html']}";
            if (!empty($in['tip'])) {
              echo "<span class='insight-tip'>{$in['tip']}</span>";
            }
            echo "</div>";
          }
        ?>
      </div>
    </div>

    <!-- ========================= -->
    <!-- KPIs PRINCIPALES MEJORADOS -->
    <!-- ========================= -->
    <h2 class="section-title">📊 Métricas de Ventas <?= $categoriaFiltro ? '<span class="section-filter-badge">' . h($categoriaFiltro) . '</span>' : '' ?></h2>
    <div class="dash-kpi-row">
      
      <!-- KPI: Ventas -->
      <div class="stat-card">
        <div class="stat-header">
          <span class="stat-label"><span class="stat-icon">🛒</span> Ventas</span>
          <button type="button" class="kpi-help" data-tooltip="ventas" aria-label="Ayuda sobre Ventas" aria-expanded="false">?</button>
        </div>
        <div class="stat-value"><?= number_format($ventasRango, 0, ',', '.') ?></div>
        <div class="stat-footer">
          <?php if (!empty($ventasDelta['text'])): ?>
          <div class="kpi-delta <?= h($ventasDelta['class']) ?>" title="<?= h($ventasDelta['title'] ?? '') ?>"><?= h($ventasDelta['text']) ?></div>
          <?php endif; ?>
        </div>
        <canvas class="mini-sparkline" role="img" aria-label="Tendencia de ventas últimos 7 días" data-values='<?= json_encode($sparklineVentas) ?>'></canvas>
      </div>

      <!-- KPI: Facturación -->
      <div class="stat-card">
        <div class="stat-header">
          <span class="stat-label"><span class="stat-icon">💰</span> Facturación</span>
          <button type="button" class="kpi-help" data-tooltip="facturacion" aria-label="Ayuda sobre Facturación" aria-expanded="false">?</button>
        </div>
        <div class="stat-value">$ <?= number_format($facturacionRango, 0, ',', '.') ?></div>
        <div class="stat-footer">
          <?php if (!empty($factDelta['text'])): ?>
          <div class="kpi-delta <?= h($factDelta['class']) ?>" title="<?= h($factDelta['title'] ?? '') ?>"><?= h($factDelta['text']) ?></div>
          <?php endif; ?>
        </div>
        <canvas class="mini-sparkline" role="img" aria-label="Tendencia de facturación últimos 7 días" data-values='<?= json_encode($sparklineFacturacion) ?>'></canvas>
      </div>

      <!-- KPI: Ticket Promedio -->
      <div class="stat-card">
        <div class="stat-header">
          <span class="stat-label"><span class="stat-icon">🎫</span> Ticket Promedio</span>
          <button type="button" class="kpi-help" data-tooltip="ticket_promedio" aria-label="Ayuda sobre Ticket Promedio" aria-expanded="false">?</button>
        </div>
        <div class="stat-value">$ <?= number_format($ticketPromedio, 0, ',', '.') ?></div>
        <div class="stat-footer">
          <?php if (!empty($ticketDelta['text'])): ?>
          <div class="kpi-delta <?= h($ticketDelta['class']) ?>" title="<?= h($ticketDelta['title'] ?? '') ?>"><?= h($ticketDelta['text']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- KPI: Unidades Vendidas -->
      <div class="stat-card">
        <div class="stat-header">
          <span class="stat-label"><span class="stat-icon">📦</span> Unidades Vendidas</span>
          <button type="button" class="kpi-help" data-tooltip="unidades" aria-label="Ayuda sobre Unidades" aria-expanded="false">?</button>
        </div>
        <div class="stat-value"><?= h(format_qty_trim($unidadesVendidasRango)) ?></div>
      </div>
      
    </div>

    <!-- ========================= -->
    <!-- RENTABILIDAD MEJORADA -->
    <!-- ========================= -->
    <h2 class="section-title">💰 Análisis de Rentabilidad</h2>
    <div class="dash-kpi-row">
      
      <!-- KPI: Ganancia Bruta -->
      <div class="stat-card <?= $gananciaBruta >= 0 ? 'stat-ok' : 'stat-sin' ?>">
        <div class="stat-header">
          <span class="stat-label"><span class="stat-icon">📊</span> Ganancia Bruta</span>
          <button type="button" class="kpi-help" data-tooltip="ganancia" aria-label="Ayuda sobre Ganancia" aria-expanded="false">?</button>
        </div>
        <div class="stat-value">$ <?= number_format($gananciaBruta, 0, ',', '.') ?></div>
        <?php if ($gananciaBruta < 0): ?>
        <span class="kpi-status kpi-status-critical">¡Pérdida!</span>
        <?php endif; ?>
      </div>

      <!-- KPI: Margen -->
      <?php $margenStatus = getKpiStatus('margen', $margenPorcentaje); ?>
      <div class="stat-card">
        <div class="stat-header">
          <span class="stat-label"><span class="stat-icon">📈</span> Margen</span>
          <button type="button" class="kpi-help" data-tooltip="margen" aria-label="Ayuda sobre Margen" aria-expanded="false">?</button>
        </div>
        <div class="stat-value"><?= number_format($margenPorcentaje, 1) ?>%</div>
        <?php if ($margenStatus['text']): ?>
        <span class="kpi-status <?= $margenStatus['class'] ?>"><?= $margenStatus['text'] ?></span>
        <?php endif; ?>
      </div>

      <!-- KPI: Total Costos -->
      <div class="stat-card stat-bajo">
        <div class="stat-header">
          <span class="stat-label"><span class="stat-icon">💸</span> Total Costos</span>
          <button type="button" class="kpi-help" data-tooltip="costos" aria-label="Ayuda sobre Costos" aria-expanded="false">?</button>
        </div>
        <div class="stat-value">$ <?= number_format($totalCostos, 0, ',', '.') ?></div>
      </div>

      <!-- KPI: Descuentos -->
      <div class="stat-card">
        <div class="stat-header">
          <span class="stat-label"><span class="stat-icon">🏷️</span> Descuentos (Promos)</span>
          <button type="button" class="kpi-help" data-tooltip="descuentos" aria-label="Ayuda sobre Descuentos" aria-expanded="false">?</button>
        </div>
        <div class="stat-value">$ <?= number_format($totalDescuentosPromos, 0, ',', '.') ?></div>
      </div>
      
    </div>

    <!-- ========================= -->
    <!-- ANULACIONES MEJORADA -->
    <!-- ========================= -->
    <h2 class="section-title">❌ Control de Anulaciones</h2>
    <div class="dash-kpi-row">
      
      <?php $anulacionStatus = getKpiStatus('tasa_anulacion', $tasaAnulacion); ?>

      <!-- KPI: Ventas Anuladas -->
      <div class="stat-card <?= $tasaAnulacion > 5 ? 'stat-sin' : 'stat-ok' ?>">
        <div class="stat-header">
          <span class="stat-label"><span class="stat-icon">🚫</span> Ventas Anuladas</span>
          <button type="button" class="kpi-help" data-tooltip="anulaciones" aria-label="Ayuda sobre Anulaciones" aria-expanded="false">?</button>
        </div>
        <div class="stat-value"><?= (int)$ventasAnuladas ?></div>
      </div>

      <!-- KPI: Tasa de Anulación -->
      <div class="stat-card">
        <div class="stat-header">
          <span class="stat-label"><span class="stat-icon">📉</span> Tasa Anulación</span>
          <button type="button" class="kpi-help" data-tooltip="tasa_anulacion" aria-label="Ayuda sobre Tasa" aria-expanded="false">?</button>
        </div>
        <div class="stat-value"><?= number_format($tasaAnulacion, 1) ?>%</div>
        <?php if ($anulacionStatus['text']): ?>
        <span class="kpi-status <?= $anulacionStatus['class'] ?>"><?= $anulacionStatus['text'] ?></span>
        <?php endif; ?>
      </div>

      <!-- KPI: Monto Anulado -->
      <div class="stat-card stat-bajo">
        <div class="stat-header">
          <span class="stat-label"><span class="stat-icon">💔</span> Monto Anulado</span>
          <button type="button" class="kpi-help" data-tooltip="monto_anulado" aria-label="Ayuda sobre Monto Anulado" aria-expanded="false">?</button>
        </div>
        <div class="stat-value">$ <?= number_format($montoAnulado, 0, ',', '.') ?></div>
      </div>
      
    </div>

    <!-- PRODUCTOS DORMIDOS -->
    <?php if (!empty($productosDormidos)): ?>
    <div class="dormidos-section">
      <div class="dormidos-header">
        <h2 class="section-title">💤 Productos Dormidos <span class="dormidos-count"><?= count($productosDormidos) ?> productos</span></h2>
        <div class="dormidos-capital">
          <span class="dormidos-capital-label">Capital parado:</span>
          <span class="dormidos-capital-value">$ <?= number_format($capitalDormido, 0, ',', '.') ?></span>
        </div>
      </div>
      <p class="dormidos-desc">Productos con stock pero sin ventas en los últimos <?= $diasSinMovimiento ?> días. Considera promociones o liquidación.</p>
      
      <div class="dormidos-table-wrap">
        <table class="dormidos-table">
          <thead>
            <tr>
              <th>Producto</th>
              <th>Stock</th>
              <th>Precio</th>
              <th>Valor en stock</th>
              <th>Última venta</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (array_slice($productosDormidos, 0, 10) as $pd): ?>
            <tr>
              <td><?= h($pd['nombre']) ?></td>
              <td><?= format_qty_trim((float)$pd['stock']) ?></td>
              <td>$ <?= number_format((float)($pd['precio'] ?? 0), 0, ',', '.') ?></td>
              <td class="valor-stock">$ <?= number_format((float)($pd['valor_stock'] ?? 0), 0, ',', '.') ?></td>
              <td>
                <?php if ($pd['ultima_venta']): ?>
                  <?= (new DateTime($pd['ultima_venta']))->format('d/m/Y') ?>
                <?php else: ?>
                  <span class="nunca-vendido">Nunca</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($productosDormidos) > 10): ?>
      <p class="dormidos-more">Y <?= count($productosDormidos) - 10 ?> productos más... <a href="dashboard_export.php?type=dormidos&from=<?= h($from) ?>&to=<?= h($to) ?>">Exportar lista completa</a></p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- GRID DE GRÁFICOS -->
    <div class="dash-grid">
      <div class="dash-card">
        <div class="dash-card-header">
          <h2>Ventas por día</h2>
          <span class="dash-card-sub">Evolución en el rango</span>
        </div>
        <div class="chart-wrap">
          <canvas id="chartVentas" role="img" tabindex="0" aria-label="Ventas por día (cantidad de tickets)" aria-describedby="chartVentasData"></canvas>
          <div id="noVentasMsg" class="chart-empty" style="display:none;">No hay ventas en el rango</div>
        </div>
        <div class="chart-context">
          <span class="chart-context-tip">Busca tendencias ascendentes. Los picos indican días de mayor actividad.</span>
        </div>

          <details class="chart-data" id="chartVentasData">
            <summary>Ver datos</summary>
            <div class="chart-data-inner">
              <table class="chart-data-table">
                <thead><tr><th>Día</th><th>Ventas</th></tr></thead>
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
          <h2>Top Productos</h2>
          <span class="dash-card-sub">Más vendidos <?= $categoriaFiltro ? '(' . h($categoriaFiltro) . ')' : '' ?></span>
        </div>
        <div class="chart-wrap">
          <canvas id="chartTopProductos" role="img" tabindex="0" aria-label="Top productos más vendidos" aria-describedby="chartTopProductosData"></canvas>
          <div id="noTopMsg" class="chart-empty" style="display:none;">Sin datos</div>
        </div>
        <div class="chart-context">
          <span class="chart-context-tip">Estos productos son tu motor de ventas. Asegura stock suficiente.</span>
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
          <h2>Métodos de Pago</h2>
          <span class="dash-card-sub">Distribución</span>
        </div>
        <div class="chart-wrap">
          <canvas id="chartMetodosPago" role="img" tabindex="0" aria-label="Distribución por métodos de pago" aria-describedby="chartMetodosPagoData"></canvas>
        </div>

          <details class="chart-data" id="chartMetodosPagoData">
            <summary>Ver datos</summary>
            <div class="chart-data-inner">
              <table class="chart-data-table">
                <thead><tr><th>Método</th><th>Monto</th></tr></thead>
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
          <h2>Ventas por Categoría</h2>
        </div>
        <div class="chart-wrap">
          <canvas id="chartCategorias" role="img" tabindex="0" aria-label="Ventas por categoría" aria-describedby="chartCategoriasData"></canvas>
        </div>

          <details class="chart-data" id="chartCategoriasData">
            <summary>Ver datos</summary>
            <div class="chart-data-inner">
              <table class="chart-data-table">
                <thead><tr><th>Categoría</th><th>Monto</th></tr></thead>
                <tbody>
<?php
  $cats = $categorias ?? [];
  $isList = is_array($cats) && isset($cats[0]) && is_array($cats[0]) && array_key_exists('categoria', $cats[0]);

  if ($isList) {
    foreach ($cats as $row) {
    $catRaw = (string)($row['categoria'] ?? '');
    $cat = (trim($catRaw) !== '') ? $catRaw : 'Sin Categoría';
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
          <h2>Horarios Pico</h2>
          <span class="dash-card-sub">Distribución por hora</span>
        </div>
        <div class="chart-wrap chart-wrap-wide">
          <canvas id="chartHorarios" role="img" tabindex="0" aria-label="Distribución de ventas por hora" aria-describedby="chartHorariosData"></canvas>
        </div>
        <div class="chart-context">
          <span class="chart-context-tip">Los picos indican cuándo necesitas más personal. Considera horarios de apertura/cierre.</span>
        </div>

          <details class="chart-data" id="chartHorariosData">
            <summary>Ver datos</summary>
            <div class="chart-data-inner">
              <table class="chart-data-table">
                <thead><tr><th>Hora</th><th>Ventas</th><th>Monto</th></tr></thead>
                <tbody>
                  <?php foreach (($ventasPorHora ?? []) as $h => $row): ?>
                    <tr>
                      <td><?= h((string)$h) ?>:00</td>
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
          <h2>Productos Más Rentables</h2>
          <span class="dash-card-sub">Top 5 por ganancia</span>
        </div>
        <div class="chart-wrap chart-wrap-wide">
          <canvas id="chartRentables" role="img" tabindex="0" aria-label="Top productos más rentables por ganancia" aria-describedby="chartRentablesData"></canvas>
        </div>
        <div class="chart-context">
          <span class="chart-context-tip">Verde = ganancia, rojo = costos. Prioriza productos con mayor barra verde.</span>
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

    <!-- STOCK CRÍTICO -->
    <?php if (!empty($stockCritico)): ?>
      <h2 class="section-title">⚠️ Stock Crítico <span class="stock-count"><?= count($stockCritico) ?> productos</span></h2>
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
    categoriaFiltro: <?= json_encode($categoriaFiltro) ?>,
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
  
  // Datos de tooltips para el sistema de ayuda
  window.kpiTooltips = <?= json_encode($kpiTooltips, JSON_UNESCAPED_UNICODE) ?>;
</script>

<script>
(function () {
  function load(src, onload, onerror) {
    const s = document.createElement("script");
    s.src = src;
    s.async = false;
    s.onload = onload || null;
    s.onerror = onerror || null;
    document.head.appendChild(s);
  }

  function loadDash() {
    load("assets/js/dashboard.js?v=5", function() {
      // Cargar el script de ayuda después del dashboard
      load("assets/js/dashboard-help.js?v=1");
    });
  }

  load(
    "https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js",
    function () {
      if (window.Chart) loadDash();
      else load("assets/vendor/chartjs/chart.umd.min.js?v=4.4.1", loadDash);
    },
    function () {
      load("assets/vendor/chartjs/chart.umd.min.js?v=4.4.1", loadDash);
    }
  );
})();
</script>


<?php require __DIR__ . '/partials/footer.php'; ?>
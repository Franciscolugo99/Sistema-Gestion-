<?php
// public/ventas.php - Módulo de Ventas FLUS v4.1 (Corregido para BD)
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('ver_reportes');

/* =========================
   Helpers
========================= */
function has_table(PDO $pdo, string $table): bool {
  static $cache = [];
  if (!isset($cache[$table])) {
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    $st->execute([$table]);
    $cache[$table] = (bool)$st->fetchColumn();
  }
  return $cache[$table];
}

function has_column(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $key = "$table.$column";
  if (!isset($cache[$key])) {
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    $st->execute([$table, $column]);
    $cache[$key] = (bool)$st->fetchColumn();
  }
  return $cache[$key];
}

function has_view(PDO $pdo, string $view): bool {
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
  $st->execute([$view]);
  return (bool)$st->fetchColumn();
}

/* Paginación numérica */
function render_pagination(int $page, int $totalPages, array $params, bool $showInfo = true, int $total = 0, int $from = 0, int $to = 0): string {
  if ($totalPages <= 1) return '';
  
  unset($params['page']);
  $html = '<div class="pagination">';
  
  if ($showInfo && $total > 0) {
    $html .= '<span class="pagination-info">' . number_format($from) . '-' . number_format($to) . ' de ' . number_format($total) . '</span>';
  }
  
  $html .= '<div class="pagination-btns">';
  
  // Anterior
  if ($page > 1) {
    $params['page'] = $page - 1;
    $html .= '<a href="?' . http_build_query($params) . '" class="pg-btn">‹</a>';
  } else {
    $html .= '<span class="pg-btn disabled">‹</span>';
  }
  
  // Páginas
  $start = max(1, $page - 2);
  $end = min($totalPages, $page + 2);
  
  if ($start > 1) {
    $params['page'] = 1;
    $html .= '<a href="?' . http_build_query($params) . '" class="pg-btn">1</a>';
    if ($start > 2) $html .= '<span class="pg-ellipsis">…</span>';
  }
  
  for ($i = $start; $i <= $end; $i++) {
    $params['page'] = $i;
    $html .= ($i === $page) 
      ? '<span class="pg-btn active">' . $i . '</span>'
      : '<a href="?' . http_build_query($params) . '" class="pg-btn">' . $i . '</a>';
  }
  
  if ($end < $totalPages) {
    if ($end < $totalPages - 1) $html .= '<span class="pg-ellipsis">…</span>';
    $params['page'] = $totalPages;
    $html .= '<a href="?' . http_build_query($params) . '" class="pg-btn">' . $totalPages . '</a>';
  }
  
  // Siguiente
  if ($page < $totalPages) {
    $params['page'] = $page + 1;
    $html .= '<a href="?' . http_build_query($params) . '" class="pg-btn">›</a>';
  } else {
    $html .= '<span class="pg-btn disabled">›</span>';
  }
  
  $html .= '</div></div>';
  return $html;
}

/* =========================
   Inicialización
========================= */
$stats = ['cnt_hoy' => 0, 'sum_hoy' => 0, 'avg_hoy' => 0, 'cnt_ayer' => 0, 'sum_ayer' => 0, 'diff_ventas' => 0, 'diff_total' => 0, 'top_medio' => 'N/A', 'top_medio_pct' => 0, 'periodo_label' => 'Hoy'];
$ventas = [];
$totalRows = 0;
$totalPages = 1;
$page = 1;
$perPage = 20;
$fromRow = 0;
$toRow = 0;

/* =========================
   Detectar tablas y columnas
========================= */
$hasVentaPagos = has_table($pdo, 'venta_pagos');
$hasVentasCompleto = has_view($pdo, 'v_ventas_completo');
$hasTerminalId = has_column($pdo, 'ventas', 'terminal_id');
$hasDescuentoMonto = has_column($pdo, 'ventas', 'descuento_monto');
$hasDescuentoTotal = has_column($pdo, 'ventas', 'descuento_total');

/* =========================
   Limpiar filtros
========================= */
if (isset($_GET['clear'])) {
  header('Location: ventas.php');
  exit;
}

/* =========================
   Procesar filtros
========================= */
$medio = strtoupper(trim($_GET['medio'] ?? ''));
$estado = strtoupper(trim($_GET['estado'] ?? ''));
$desde = validDateYmd($_GET['desde'] ?? null);
$hasta = validDateYmd($_GET['hasta'] ?? null);
$venta_id = trim($_GET['venta_id'] ?? '');
$cliente_id = isset($_GET['cliente_id']) && ctype_digit($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : 0;

// Filtro horario
$hora_desde = trim($_GET['hora_desde'] ?? '');
$hora_hasta = trim($_GET['hora_hasta'] ?? '');
if ($hora_desde && !preg_match('/^\d{2}:\d{2}$/', $hora_desde)) $hora_desde = '';
if ($hora_hasta && !preg_match('/^\d{2}:\d{2}$/', $hora_hasta)) $hora_hasta = '';

// FIX: Validación segura de per_page
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, [20, 50, 100], true)) {
    $perPage = 20;
}
$page = max(1, (int)($_GET['page'] ?? 1));

/* =========================
   WHERE dinámico
========================= */
$whereParts = ['1=1'];
$params = [];

$allowedMedios = ['EFECTIVO', 'MP', 'DEBITO', 'CREDITO'];
if ($medio && in_array($medio, $allowedMedios)) {
  if ($hasVentaPagos) {
    $whereParts[] = "EXISTS (SELECT 1 FROM venta_pagos vp WHERE vp.venta_id = v.id AND UPPER(vp.medio_pago) = :medio)";
  } else {
    $whereParts[] = "UPPER(v.medio_pago) = :medio";
  }
  $params[':medio'] = $medio;
}

if ($estado === 'EMITIDA') {
  $whereParts[] = "(v.estado IS NULL OR v.estado = 'EMITIDA')";
} elseif ($estado === 'ANULADA') {
  $whereParts[] = "v.estado = 'ANULADA'";
}

if ($desde) {
  $whereParts[] = 'v.fecha >= :desde';
  $params[':desde'] = $desde . ' 00:00:00';
}
if ($hasta) {
  $whereParts[] = 'v.fecha <= :hasta';
  $params[':hasta'] = $hasta . ' 23:59:59';
}

// Filtro horario con soporte para rangos cruzados
if ($hora_desde && $hora_hasta) {
  $minD = intval(substr($hora_desde, 0, 2)) * 60 + intval(substr($hora_desde, 3, 2));
  $minH = intval(substr($hora_hasta, 0, 2)) * 60 + intval(substr($hora_hasta, 3, 2));
  
  if ($minH >= $minD) {
    $whereParts[] = "TIME(v.fecha) BETWEEN :hora_desde AND :hora_hasta";
  } else {
    $whereParts[] = "(TIME(v.fecha) >= :hora_desde OR TIME(v.fecha) <= :hora_hasta)";
  }
  $params[':hora_desde'] = $hora_desde . ':00';
  $params[':hora_hasta'] = $hora_hasta . ':59';
} elseif ($hora_desde) {
  $whereParts[] = "TIME(v.fecha) >= :hora_desde";
  $params[':hora_desde'] = $hora_desde . ':00';
} elseif ($hora_hasta) {
  $whereParts[] = "TIME(v.fecha) <= :hora_hasta";
  $params[':hora_hasta'] = $hora_hasta . ':59';
}

if ($venta_id && ctype_digit($venta_id)) {
  $whereParts[] = 'v.id = :venta_id';
  $params[':venta_id'] = (int)$venta_id;
}

if ($cliente_id > 0) {
  $whereParts[] = 'v.cliente_id = :cliente_id';
  $params[':cliente_id'] = $cliente_id;
}

$whereSQL = implode(' AND ', $whereParts);

/* =========================
   KPIs - Respetan los filtros aplicados
========================= */
$hayFiltros = $medio || $estado || $desde || $hasta || $hora_desde || $hora_hasta || $venta_id || $cliente_id;
$stats['periodo_label'] = 'Hoy';

try {
  if ($hayFiltros) {
    // KPIs del período FILTRADO
    $stFiltrado = $pdo->prepare("
      SELECT COUNT(*) as cnt, COALESCE(SUM(total),0) as sum 
      FROM ventas v 
      WHERE $whereSQL AND (v.estado IS NULL OR v.estado = 'EMITIDA')
    ");
    $stFiltrado->execute($params);
    $rowFiltrado = $stFiltrado->fetch(PDO::FETCH_ASSOC);
    $stats['cnt_hoy'] = (int)$rowFiltrado['cnt'];
    $stats['sum_hoy'] = (float)$rowFiltrado['sum'];
    $stats['avg_hoy'] = $stats['cnt_hoy'] > 0 ? $stats['sum_hoy'] / $stats['cnt_hoy'] : 0;
    
    // Determinar label del período
    if ($desde && $hasta) {
      if ($desde === $hasta) {
        $stats['periodo_label'] = date('d/m', strtotime($desde));
      } else {
        $stats['periodo_label'] = date('d/m', strtotime($desde)) . ' - ' . date('d/m', strtotime($hasta));
      }
    } elseif ($desde) {
      $stats['periodo_label'] = 'Desde ' . date('d/m', strtotime($desde));
    } elseif ($hasta) {
      $stats['periodo_label'] = 'Hasta ' . date('d/m', strtotime($hasta));
    } else {
      $stats['periodo_label'] = 'Filtrado';
    }
    
    // Comparación: calcular período anterior equivalente
    if ($desde && $hasta) {
      $diasRango = (strtotime($hasta) - strtotime($desde)) / 86400 + 1;
      $desdeAnterior = date('Y-m-d', strtotime($desde) - ($diasRango * 86400));
      $hastaAnterior = date('Y-m-d', strtotime($desde) - 86400);
      
      // Construir WHERE para período anterior (sin filtros de fecha pero con otros filtros)
      $wherePartsAnterior = ['1=1'];
      $paramsAnterior = [];
      
      if ($medio && in_array($medio, $allowedMedios)) {
        if ($hasVentaPagos) {
          $wherePartsAnterior[] = "EXISTS (SELECT 1 FROM venta_pagos vp WHERE vp.venta_id = v.id AND UPPER(vp.medio_pago) = :medio)";
        } else {
          $wherePartsAnterior[] = "UPPER(v.medio_pago) = :medio";
        }
        $paramsAnterior[':medio'] = $medio;
      }
      if ($estado === 'EMITIDA') {
        $wherePartsAnterior[] = "(v.estado IS NULL OR v.estado = 'EMITIDA')";
      } elseif ($estado === 'ANULADA') {
        $wherePartsAnterior[] = "v.estado = 'ANULADA'";
      }
      if ($cliente_id > 0) {
        $wherePartsAnterior[] = 'v.cliente_id = :cliente_id';
        $paramsAnterior[':cliente_id'] = $cliente_id;
      }
      
      $wherePartsAnterior[] = 'v.fecha >= :desde_ant';
      $wherePartsAnterior[] = 'v.fecha <= :hasta_ant';
      $paramsAnterior[':desde_ant'] = $desdeAnterior . ' 00:00:00';
      $paramsAnterior[':hasta_ant'] = $hastaAnterior . ' 23:59:59';
      
      $whereAnterior = implode(' AND ', $wherePartsAnterior);
      
      $stAnterior = $pdo->prepare("
        SELECT COUNT(*) as cnt, COALESCE(SUM(total),0) as sum 
        FROM ventas v 
        WHERE $whereAnterior AND (v.estado IS NULL OR v.estado = 'EMITIDA')
      ");
      $stAnterior->execute($paramsAnterior);
      $rowAnterior = $stAnterior->fetch(PDO::FETCH_ASSOC);
      $stats['cnt_ayer'] = (int)$rowAnterior['cnt'];
      $stats['sum_ayer'] = (float)$rowAnterior['sum'];
    }
    
    // Top medio de pago del período filtrado
    if ($hasVentaPagos) {
      $stMedio = $pdo->prepare("
        SELECT UPPER(vp.medio_pago) as medio, COUNT(*) as cnt
        FROM venta_pagos vp
        JOIN ventas v ON v.id = vp.venta_id
        WHERE $whereSQL AND (v.estado IS NULL OR v.estado = 'EMITIDA')
        GROUP BY UPPER(vp.medio_pago)
        ORDER BY cnt DESC
        LIMIT 1
      ");
      $stMedio->execute($params);
    } else {
      $stMedio = $pdo->prepare("
        SELECT UPPER(v.medio_pago) as medio, COUNT(*) as cnt
        FROM ventas v
        WHERE $whereSQL AND (v.estado IS NULL OR v.estado = 'EMITIDA')
        GROUP BY UPPER(v.medio_pago)
        ORDER BY cnt DESC
        LIMIT 1
      ");
      $stMedio->execute($params);
    }
    $topMedio = $stMedio->fetch(PDO::FETCH_ASSOC);
    
  } else {
    // Sin filtros: mostrar datos de HOY
    $hoy = date('Y-m-d');
    $ayer = date('Y-m-d', strtotime('-1 day'));
    
    $stHoy = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total),0) as sum FROM ventas WHERE DATE(fecha) = ? AND (estado IS NULL OR estado = 'EMITIDA')");
    $stHoy->execute([$hoy]);
    $rowHoy = $stHoy->fetch(PDO::FETCH_ASSOC);
    $stats['cnt_hoy'] = (int)$rowHoy['cnt'];
    $stats['sum_hoy'] = (float)$rowHoy['sum'];
    $stats['avg_hoy'] = $stats['cnt_hoy'] > 0 ? $stats['sum_hoy'] / $stats['cnt_hoy'] : 0;
    
    $stAyer = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total),0) as sum FROM ventas WHERE DATE(fecha) = ? AND (estado IS NULL OR estado = 'EMITIDA')");
    $stAyer->execute([$ayer]);
    $rowAyer = $stAyer->fetch(PDO::FETCH_ASSOC);
    $stats['cnt_ayer'] = (int)$rowAyer['cnt'];
    $stats['sum_ayer'] = (float)$rowAyer['sum'];
    
    // Top medio de pago de hoy
    if ($hasVentaPagos) {
      $stMedio = $pdo->query("
        SELECT UPPER(vp.medio_pago) as medio, COUNT(*) as cnt
        FROM venta_pagos vp
        JOIN ventas v ON v.id = vp.venta_id
        WHERE DATE(v.fecha) = CURDATE() AND (v.estado IS NULL OR v.estado = 'EMITIDA')
        GROUP BY UPPER(vp.medio_pago)
        ORDER BY cnt DESC
        LIMIT 1
      ");
    } else {
      $stMedio = $pdo->query("
        SELECT UPPER(medio_pago) as medio, COUNT(*) as cnt
        FROM ventas
        WHERE DATE(fecha) = CURDATE() AND (estado IS NULL OR estado = 'EMITIDA')
        GROUP BY UPPER(medio_pago)
        ORDER BY cnt DESC
        LIMIT 1
      ");
    }
    $topMedio = $stMedio->fetch(PDO::FETCH_ASSOC);
  }
  
  // Calcular diferencias
  if ($stats['cnt_ayer'] > 0) {
    $stats['diff_ventas'] = round((($stats['cnt_hoy'] - $stats['cnt_ayer']) / $stats['cnt_ayer']) * 100);
  }
  if ($stats['sum_ayer'] > 0) {
    $stats['diff_total'] = round((($stats['sum_hoy'] - $stats['sum_ayer']) / $stats['sum_ayer']) * 100);
  }
  
  if ($topMedio && $stats['cnt_hoy'] > 0) {
    $stats['top_medio'] = $topMedio['medio'] ?: 'N/A';
    $stats['top_medio_pct'] = round(($topMedio['cnt'] / $stats['cnt_hoy']) * 100);
  }
} catch (Exception $e) {
  // Silenciar errores de KPIs
}

/* =========================
   Datos para gráficos
========================= */
$chartData = ['labels' => [], 'ventas' => [], 'totales' => []];
$chartMedios = ['labels' => [], 'data' => [], 'colors' => []];

try {
  // Ventas últimos 7 días
  $st = $pdo->query("
    SELECT DATE(fecha) as dia, COUNT(*) as cnt, SUM(total) as sum
    FROM ventas
    WHERE fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND (estado IS NULL OR estado = 'EMITIDA')
    GROUP BY DATE(fecha)
    ORDER BY dia
  ");
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $chartData['labels'][] = date('d/m', strtotime($row['dia']));
    $chartData['ventas'][] = (int)$row['cnt'];
    $chartData['totales'][] = round((float)$row['sum'], 2);
  }
  
  // Distribución por medio
  $colores = ['EFECTIVO' => '#22c55e', 'MP' => '#3b82f6', 'DEBITO' => '#f59e0b', 'CREDITO' => '#8b5cf6', 'MIXTO' => '#ec4899'];
  if ($hasVentaPagos) {
    $st = $pdo->query("
      SELECT UPPER(vp.medio_pago) as medio, COUNT(DISTINCT vp.venta_id) as cnt
      FROM venta_pagos vp
      JOIN ventas v ON v.id = vp.venta_id
      WHERE v.fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND (v.estado IS NULL OR v.estado = 'EMITIDA')
      GROUP BY UPPER(vp.medio_pago)
    ");
  } else {
    $st = $pdo->query("
      SELECT UPPER(medio_pago) as medio, COUNT(*) as cnt
      FROM ventas
      WHERE fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND (estado IS NULL OR estado = 'EMITIDA')
      GROUP BY UPPER(medio_pago)
    ");
  }
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $m = $row['medio'] ?: 'OTRO';
    $chartMedios['labels'][] = $m;
    $chartMedios['data'][] = (int)$row['cnt'];
    $chartMedios['colors'][] = $colores[$m] ?? '#94a3b8';
  }
} catch (Exception $e) {}

/* =========================
   Count total
========================= */
$stCount = $pdo->prepare("SELECT COUNT(*) FROM ventas v WHERE $whereSQL");
$stCount->execute($params);
$totalRows = (int)$stCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$fromRow = $totalRows > 0 ? $offset + 1 : 0;
$toRow = min($offset + $perPage, $totalRows);

/* =========================
   Query principal - Detectar columnas dinámicamente
========================= */
$vendedorCol = 'v.usuario_id';
try {
  $check = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ventas' AND COLUMN_NAME = 'vendedor_id' LIMIT 1");
  if ($check->fetchColumn()) $vendedorCol = 'COALESCE(v.usuario_id, v.vendedor_id)';
} catch (Exception $e) {}

// Determinar columna de descuento
if ($hasDescuentoMonto) {
  $descuentoCol = 'v.descuento_monto';
} elseif ($hasDescuentoTotal) {
  $descuentoCol = 'v.descuento_total';
} else {
  $descuentoCol = '0';
}

// Determinar si tiene terminal
$terminalSelect = $hasTerminalId ? 'v.terminal_id, t.nombre AS terminal_nombre,' : 'NULL AS terminal_id, NULL AS terminal_nombre,';
$terminalJoin = $hasTerminalId ? 'LEFT JOIN terminales t ON t.id = v.terminal_id' : '';

$sql = "
  SELECT v.id, v.fecha, v.total, v.estado, v.medio_pago, v.cliente_id,
         $descuentoCol AS descuento_monto,
         $terminalSelect
         c.nombre AS cliente_nombre,
         u.username AS vendedor_username,
         (SELECT COUNT(*) FROM venta_items vi WHERE vi.venta_id = v.id) AS items_count,
         (SELECT GROUP_CONCAT(p.nombre SEPARATOR ', ') FROM venta_items vi JOIN productos p ON p.id = vi.producto_id WHERE vi.venta_id = v.id LIMIT 3) AS productos_resumen
  FROM ventas v
  LEFT JOIN clientes c ON c.id = v.cliente_id
  LEFT JOIN users u ON u.id = $vendedorCol
  $terminalJoin
  WHERE $whereSQL
  ORDER BY v.id DESC
  LIMIT $perPage OFFSET $offset
";

$st = $pdo->prepare($sql);
$st->execute($params);
$ventas = $st->fetchAll(PDO::FETCH_ASSOC);

// Obtener medios de pago reales si existe venta_pagos
if ($hasVentaPagos && $ventas) {
  $ids = array_column($ventas, 'id');
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $stPagos = $pdo->prepare("
    SELECT venta_id, GROUP_CONCAT(DISTINCT UPPER(medio_pago) SEPARATOR '+') as medios
    FROM venta_pagos
    WHERE venta_id IN ($placeholders)
    GROUP BY venta_id
  ");
  $stPagos->execute($ids);
  $mediosMap = [];
  while ($row = $stPagos->fetch(PDO::FETCH_ASSOC)) {
    $mediosMap[$row['venta_id']] = $row['medios'];
  }
  foreach ($ventas as &$v) {
    $v['medio_real'] = $mediosMap[$v['id']] ?? ($v['medio_pago'] ?: 'N/A');
  }
  unset($v);
}

/* =========================
   Nombre cliente seleccionado
========================= */
$clienteNombre = '';
if ($cliente_id) {
  $st = $pdo->prepare("SELECT nombre FROM clientes WHERE id = ?");
  $st->execute([$cliente_id]);
  $clienteNombre = $st->fetchColumn() ?: '';
}

/* =========================
   Header
========================= */
$pageTitle = 'Ventas';
$currentSection = 'ventas';
$extraCss = ['assets/css/ventas.css?v=4', 'assets/css/ventas-autocomplete.css?v=1'];
$extraJs = [
  'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
  'assets/js/ventas.js?v=4.1'
];

require __DIR__ . '/partials/header.php';

$queryParams = $_GET;
unset($queryParams['page']);
?>

<div class="ventas-page">

  <!-- Header -->
  <div class="ventas-header">
    <div class="ventas-header-left">
      <h1>Ventas</h1>
      <?php if ($hasVentasCompleto): ?>
        <span class="badge-opt">⚡ Optimizado</span>
      <?php endif; ?>
    </div>
    <div class="ventas-header-right">
      <select id="paperSel" class="paper-select">
        <option value="80">80mm</option>
        <option value="58">58mm</option>
      </select>
      <button id="btnCharts" class="btn-icon" title="Gráficos">📊</button>
      <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-primary">💾 Exportar</a>
    </div>
  </div>

  <!-- KPIs -->
  <div class="kpis-grid">
    <div class="kpi-card">
      <div class="kpi-icon">🛒</div>
      <div class="kpi-content">
        <span class="kpi-value"><?= number_format($stats['cnt_hoy']) ?></span>
        <span class="kpi-label">Ventas <?= h($stats['periodo_label']) ?></span>
        <?php if ($stats['diff_ventas'] != 0): ?>
          <span class="kpi-trend <?= $stats['diff_ventas'] > 0 ? 'up' : 'down' ?>" title="vs período anterior">
            <?= $stats['diff_ventas'] > 0 ? '↑' : '↓' ?> <?= abs($stats['diff_ventas']) ?>%
          </span>
        <?php endif; ?>
      </div>
    </div>
    
    <div class="kpi-card highlight">
      <div class="kpi-icon">💰</div>
      <div class="kpi-content">
        <span class="kpi-value"><?= money($stats['sum_hoy']) ?></span>
        <span class="kpi-label">Total <?= h($stats['periodo_label']) ?></span>
        <?php if ($stats['diff_total'] != 0): ?>
          <span class="kpi-trend <?= $stats['diff_total'] > 0 ? 'up' : 'down' ?>" title="vs período anterior">
            <?= $stats['diff_total'] > 0 ? '↑' : '↓' ?> <?= abs($stats['diff_total']) ?>%
          </span>
        <?php endif; ?>
      </div>
    </div>
    
    <div class="kpi-card">
      <div class="kpi-icon">🎫</div>
      <div class="kpi-content">
        <span class="kpi-value"><?= money($stats['avg_hoy']) ?></span>
        <span class="kpi-label">Ticket Promedio</span>
      </div>
    </div>
    
    <div class="kpi-card">
      <div class="kpi-icon">💳</div>
      <div class="kpi-content">
        <span class="kpi-value badge-medio-<?= strtolower($stats['top_medio']) ?>"><?= h($stats['top_medio']) ?></span>
        <span class="kpi-label">Top Medio (<?= $stats['top_medio_pct'] ?>%)</span>
      </div>
    </div>
  </div>

  <!-- Gráficos (oculto por defecto) -->
  <div id="chartsPanel" class="charts-panel hidden">
    <div class="charts-grid">
      <div class="chart-box">
        <h4>📈 Ventas últimos 7 días</h4>
        <canvas id="chartVentas"></canvas>
      </div>
      <div class="chart-box">
        <h4>📊 Por medio de pago</h4>
        <canvas id="chartMedios"></canvas>
      </div>
    </div>
  </div>

  <!-- Filtros -->
  <div class="filtros-panel">
    <form id="ventasForm" method="GET" class="filtros-form">
      <div class="filtros-main">
        <div class="filtro-group">
          <input type="text" name="venta_id" placeholder="🔍 Buscar ID..." value="<?= h($venta_id) ?>" class="input-search">
        </div>
        
        <div class="filtro-group">
          <input type="date" name="desde" value="<?= h($desde) ?>" title="Desde">
        </div>
        
        <div class="filtro-group">
          <input type="date" name="hasta" value="<?= h($hasta) ?>" title="Hasta">
        </div>
        
        <div class="filtro-group">
          <select name="medio">
            <option value="">Medio: Todos</option>
            <option value="EFECTIVO" <?= $medio === 'EFECTIVO' ? 'selected' : '' ?>>💵 Efectivo</option>
            <option value="MP" <?= $medio === 'MP' ? 'selected' : '' ?>>📱 MP</option>
            <option value="DEBITO" <?= $medio === 'DEBITO' ? 'selected' : '' ?>>💳 Débito</option>
            <option value="CREDITO" <?= $medio === 'CREDITO' ? 'selected' : '' ?>>💳 Crédito</option>
          </select>
        </div>
        
        <div class="filtro-group">
          <select name="estado">
            <option value="">Estado: Todos</option>
            <option value="EMITIDA" <?= $estado === 'EMITIDA' ? 'selected' : '' ?>>✅ Emitidas</option>
            <option value="ANULADA" <?= $estado === 'ANULADA' ? 'selected' : '' ?>>❌ Anuladas</option>
          </select>
        </div>
        
        <button type="submit" class="btn btn-primary">Filtrar</button>
        <a href="ventas.php" class="btn btn-ghost">Limpiar</a>
        
        <button type="button" id="btnMoreFilters" class="btn btn-ghost">+ Filtros</button>
      </div>
      
      <!-- Filtros avanzados (ocultos) -->
      <div id="advancedFilters" class="filtros-advanced hidden">
        <div class="filtro-group">
          <label>🕐 Hora desde</label>
          <input type="time" name="hora_desde" value="<?= h($hora_desde) ?>">
        </div>
        <div class="filtro-group">
          <label>🕐 Hora hasta</label>
          <input type="time" name="hora_hasta" value="<?= h($hora_hasta) ?>">
          <small>Soporta rangos nocturnos (22:00-06:00)</small>
        </div>
        <div class="filtro-group filtro-cliente">
          <label>👤 Cliente</label>
          <div class="cliente-autocomplete">
            <input type="text" 
                   id="clienteSearch" 
                   placeholder="Buscar por nombre..." 
                   value="<?= h($clienteNombre) ?>"
                   autocomplete="off">
            <input type="hidden" name="cliente_id" id="clienteIdHidden" value="<?= $cliente_id ?: '' ?>">
            <button type="button" id="btnClearCliente" class="btn-clear" title="Limpiar">&times;</button>
            <div id="clienteDropdown" class="cliente-dropdown"></div>
          </div>
        </div>
        <div class="filtro-group">
          <label>📄 Por página</label>
          <select name="per_page">
            <option value="20" <?= $perPage === 20 ? 'selected' : '' ?>>20</option>
            <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
            <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
          </select>
        </div>
      </div>
      
      <!-- Chips rápidos -->
      <div class="chips-row">
        <span class="chip" data-range="today">Hoy</span>
        <span class="chip" data-range="yesterday">Ayer</span>
        <span class="chip" data-range="7d">7 días</span>
        <span class="chip" data-range="30d">30 días</span>
        <span class="chip-sep">|</span>
        <span class="chip" data-hora="06:00,12:00">🌅 Mañana</span>
        <span class="chip" data-hora="12:00,18:00">☀️ Tarde</span>
        <span class="chip" data-hora="18:00,23:59">🌙 Noche</span>
      </div>
      
      <input type="hidden" name="page" id="hiddenPage" value="1">
    </form>
  </div>

  <!-- Filtros activos -->
  <?php 
  $filtrosActivos = [];
  if ($medio) $filtrosActivos[] = ['key' => 'medio', 'label' => "Medio: $medio"];
  if ($estado) $filtrosActivos[] = ['key' => 'estado', 'label' => "Estado: $estado"];
  if ($desde || $hasta) $filtrosActivos[] = ['key' => 'fecha', 'label' => "Fecha: " . ($desde ?: '...') . " - " . ($hasta ?: '...')];
  if ($hora_desde || $hora_hasta) $filtrosActivos[] = ['key' => 'hora', 'label' => "Horario: " . ($hora_desde ?: '00:00') . " - " . ($hora_hasta ?: '23:59')];
  if ($cliente_id) $filtrosActivos[] = ['key' => 'cliente', 'label' => "Cliente: " . ($clienteNombre ?: "#$cliente_id")];
  ?>
  <?php if ($filtrosActivos): ?>
  <div class="filtros-activos">
    <?php foreach ($filtrosActivos as $f): ?>
      <span class="filtro-tag">
        <?= h($f['label']) ?>
        <button type="button" class="filtro-remove" data-filter="<?= $f['key'] ?>">×</button>
      </span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Paginación superior -->
  <?= render_pagination($page, $totalPages, $queryParams, true, $totalRows, $fromRow, $toRow) ?>

  <!-- Tabla -->
  <div class="tabla-container">
    <table class="ventas-tabla">
      <thead>
        <tr>
          <th>ID</th>
          <th>Fecha</th>
          <th>Cliente</th>
          <th>Productos</th>
          <th>Medio</th>
          <th>Estado</th>
          <th class="text-right">Total</th>
          <th class="text-center">Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($ventas): ?>
        <?php foreach ($ventas as $v): 
          $esAnulada = strtoupper($v['estado'] ?? '') === 'ANULADA';
          $medioMostrar = $hasVentaPagos ? ($v['medio_real'] ?? 'N/A') : ($v['medio_pago'] ?: 'N/A');
          $esMixto = strpos($medioMostrar, '+') !== false;
        ?>
        <tr class="<?= $esAnulada ? 'row-anulada' : '' ?>">
          <td class="col-id"><?= (int)$v['id'] ?></td>
          <td class="col-fecha">
            <span class="fecha-dia"><?= date('d/m/Y', strtotime($v['fecha'])) ?></span>
            <span class="fecha-hora"><?= date('H:i', strtotime($v['fecha'])) ?></span>
          </td>
          <td class="col-cliente">
            <?= h($v['cliente_nombre'] ?: 'Consumidor Final') ?>
          </td>
          <td class="col-productos">
            <span class="productos-count"><?= (int)$v['items_count'] ?></span>
            <span class="productos-preview"><?= h(mb_substr($v['productos_resumen'] ?? '', 0, 30)) ?><?= mb_strlen($v['productos_resumen'] ?? '') > 30 ? '...' : '' ?></span>
          </td>
          <td class="col-medio">
            <span class="badge-medio badge-<?= $esMixto ? 'mixto' : strtolower($medioMostrar) ?>">
              <?= h($medioMostrar) ?>
            </span>
          </td>
          <td class="col-estado">
            <?php if ($esAnulada): ?>
              <span class="badge-estado anulada">Anulada</span>
            <?php else: ?>
              <span class="badge-estado emitida">Emitida</span>
            <?php endif; ?>
          </td>
          <td class="col-total text-right">
            <?php if ((float)($v['descuento_monto'] ?? 0) > 0): ?>
              <span class="descuento-tag">-<?= money($v['descuento_monto']) ?></span>
            <?php endif; ?>
            <?= money($v['total']) ?>
          </td>
          <td class="col-acciones text-center">
            <button class="btn-action" data-preview="<?= (int)$v['id'] ?>" title="Vista previa">👁️</button>
            <button class="btn-action" data-ticket="<?= (int)$v['id'] ?>" title="Ver ticket">🧾</button>
            <a href="venta_detalle.php?id=<?= (int)$v['id'] ?>" class="btn-action" title="Detalle">→</a>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr>
          <td colspan="8" class="empty-state">
            <div class="empty-icon">📭</div>
            <p>No hay ventas con los filtros seleccionados</p>
          </td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginación inferior -->
  <?= render_pagination($page, $totalPages, $queryParams, false) ?>

</div>

<!-- Modal Preview -->
<div id="previewModal" class="modal hidden">
  <div class="modal-backdrop"></div>
  <div class="modal-content">
    <div class="modal-header">
      <h3>Venta #<span id="previewId"></span></h3>
      <button class="modal-close" data-close>×</button>
    </div>
    <div class="modal-body" id="previewBody">
      <div class="loading">Cargando...</div>
    </div>
    <div class="modal-footer">
      <a href="#" id="previewLink" class="btn btn-primary">Ver detalle completo</a>
      <button class="btn btn-ghost" data-close>Cerrar</button>
    </div>
  </div>
</div>

<!-- Modal Ticket -->
<div id="ticketModal" class="modal hidden">
  <div class="modal-backdrop"></div>
  <div class="modal-content modal-ticket">
    <div class="modal-header">
      <h3>Ticket #<span id="ticketId"></span></h3>
      <div class="modal-actions">
        <button id="btnPrintTicket" class="btn btn-primary">🖨️ Imprimir</button>
        <button class="modal-close" data-close>×</button>
      </div>
    </div>
    <div class="modal-body">
      <iframe id="ticketFrame" src="" frameborder="0"></iframe>
    </div>
  </div>
</div>

<!-- Datos para JS -->
<script>
window.VENTAS_DATA = {
  chartVentas: <?= json_encode($chartData) ?>,
  chartMedios: <?= json_encode($chartMedios) ?>
};
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>

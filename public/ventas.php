<?php
// public/ventas.php - MÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â³dulo de Ventas FLUS v5.0 (Refactorizado - Consistente con Productos/Stock)
declare(strict_types=1);

require_once __DIR__ . '/../src/db_helpers.php';

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('ver_reportes');

// TZ: asegurar que "Hoy" coincida con Argentina/Mendoza (evita desfasajes)
if (function_exists('date_default_timezone_set')) {
  @date_default_timezone_set('America/Argentina/Mendoza');
}


/* =========================
   Constantes
========================= */
/* =========================

/* =========================
   InicializaciÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â³n
========================= */
$pdo = getPDO();
$stats = ['cnt_hoy' => 0, 'sum_hoy' => 0, 'avg_hoy' => 0, 'cnt_ayer' => 0, 'sum_ayer' => 0, 'diff_ventas' => 0, 'diff_total' => 0, 'top_medio' => 'N/A', 'top_medio_pct' => 0, 'periodo_label' => 'Hoy', 'cnt_anuladas' => 0, 'sum_anuladas' => 0];
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
$hasTerminalId = has_column($pdo, 'ventas', 'terminal_id');
$hasDescuentoMonto = has_column($pdo, 'ventas', 'descuento_monto');
$hasDescuentoTotal = has_column($pdo, 'ventas', 'descuento_total');
$hasMontoCC = has_column($pdo, 'ventas', 'monto_cc');

// Columna de descuento (compat: descuento_monto / descuento_total / ninguno)
$descuentoCol = $hasDescuentoMonto ? 'v.descuento_monto' : ($hasDescuentoTotal ? 'v.descuento_total' : '0');


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

// FIX: ValidaciÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â³n segura de per_page
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, [20, 50, 100], true)) {
    $perPage = 20;
}
$page = max(1, (int)($_GET['page'] ?? 1));

/* =========================
   WHERE dinÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡mico
========================= */
$whereParts = ['1=1'];
$params = [];

$allowedMedios = ['EFECTIVO','MP','DEBITO','CREDITO','TRANSFERENCIA','MODO','QR'];
if ($medio && in_array($medio, $allowedMedios, true)) {
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

// Filtro de fecha+hora combinado (rango continuo)
// Si hay hora, se combina con la fecha para hacer un rango datetime completo
// Ej: 31/12/2025 8:00 AM hasta 01/01/2026 5:00 AM = rango continuo
if ($desde) {
  $horaInicio = $hora_desde ? $hora_desde . ':00' : '00:00:00';
  $whereParts[] = 'v.fecha >= :desde';
  $params[':desde'] = $desde . ' ' . $horaInicio;
}
if ($hasta) {
  $horaFin = $hora_hasta ? $hora_hasta . ':59' : '23:59:59';
  $whereParts[] = 'v.fecha <= :hasta';
  $params[':hasta'] = $hasta . ' ' . $horaFin;
}

// Si solo hay filtro de hora sin fechas, aplicar a cualquier dÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â­a
if (!$desde && !$hasta && ($hora_desde || $hora_hasta)) {
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
   EXPORT CSV (NUEVO - consistente con productos/stock)
========================= */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Determinar columna de descuento
    if ($hasDescuentoMonto) {
        $descuentoCol = 'v.descuento_monto';
    } elseif ($hasDescuentoTotal) {
        $descuentoCol = 'v.descuento_total';
    } else {
        $descuentoCol = '0';
    }

    $sqlExport = "
        SELECT 
            v.id,
            v.fecha,
            COALESCE(c.nombre, 'Consumidor Final') AS cliente,
            v.total,
            $descuentoCol AS descuento,
            COALESCE(v.estado, 'EMITIDA') AS estado,
            v.medio_pago
        FROM ventas v
        LEFT JOIN clientes c ON c.id = v.cliente_id
        WHERE $whereSQL
        ORDER BY v.id DESC
  LIMIT " . flus_export_limit();

    $stmtExp = $pdo->prepare($sqlExport);
    $stmtExp->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ventas_' . date('Y-m-d_His') . '.csv"');

    $out = fopen('php://output', 'w');
    // BOM UTF-8 para Excel
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    // Header
    fputcsv($out, ['ID', 'Fecha', 'Cliente', 'Total', 'Descuento', 'Estado', 'Medio Pago'], ';');

    // Traer todo (evita N+1 al exportar)
    $rows = $stmtExp->fetchAll(PDO::FETCH_ASSOC);

    // Prefetch de medios reales (venta_pagos) en bloques
    $mediosMap = [];
    if ($hasVentaPagos && $rows) {
        $ids = array_values(array_filter(array_map(static fn($r) => (int)($r['id'] ?? 0), $rows)));
        $chunkSize = 1000;
        for ($i = 0; $i < count($ids); $i += $chunkSize) {
            $chunk = array_slice($ids, $i, $chunkSize);
            if (!$chunk) continue;
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $stPagos = $pdo->prepare("SELECT venta_id, GROUP_CONCAT(DISTINCT UPPER(medio_pago) SEPARATOR '+') AS medios
                                      FROM venta_pagos
                                      WHERE venta_id IN ($placeholders)
                                      GROUP BY venta_id");
            $stPagos->execute($chunk);
            while ($p = $stPagos->fetch(PDO::FETCH_ASSOC)) {
                $vid = (int)($p['venta_id'] ?? 0);
                $med = (string)($p['medios'] ?? '');
                if ($vid > 0 && $med !== '') $mediosMap[$vid] = $med;
            }
        }
    }

    foreach ($rows as $row) {
        // Si tiene venta_pagos, mostrar medios reales (ej: EFECTIVO+MP)
        $medioPago = $row['medio_pago'] ?? 'N/A';
        if ($hasVentaPagos) {
            $vid = (int)($row['id'] ?? 0);
            if ($vid > 0 && isset($mediosMap[$vid])) $medioPago = $mediosMap[$vid];
        }

        fputcsv($out, [
            $row['id'],
            $row['fecha'],
            $row['cliente'],
            number_format((float)($row['total'] ?? 0), 2, ',', ''),
            number_format((float)($row['descuento'] ?? 0), 2, ',', ''),
            $row['estado'],
            $medioPago,
        ], ';');
    }

fclose($out);
    exit;
}

/* =========================
   KPIs - Respetan los filtros aplicados
========================= */
$hayFiltros = $medio || $estado || $desde || $hasta || $hora_desde || $hora_hasta || $venta_id || $cliente_id;
$stats['periodo_label'] = 'Hoy';

try {
  if ($hayFiltros) {
    // KPIs del perÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â­odo FILTRADO
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
    
    // Anuladas en el perÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â­odo
    $stAnuladas = $pdo->prepare("
      SELECT COUNT(*) as cnt, COALESCE(SUM(total),0) as sum 
      FROM ventas v 
      WHERE $whereSQL AND v.estado = 'ANULADA'
    ");
    $stAnuladas->execute($params);
    $rowAnuladas = $stAnuladas->fetch(PDO::FETCH_ASSOC);
    $stats['cnt_anuladas'] = (int)$rowAnuladas['cnt'];
    $stats['sum_anuladas'] = (float)$rowAnuladas['sum'];
    
    // Determinar label del perÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â­odo
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
    
    // ComparaciÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â³n: calcular perÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â­odo anterior equivalente
    if ($desde && $hasta) {
      $diasRango = (strtotime($hasta) - strtotime($desde)) / 86400 + 1;
      $desdeAnterior = date('Y-m-d', strtotime($desde) - ($diasRango * 86400));
      $hastaAnterior = date('Y-m-d', strtotime($desde) - 86400);
      
      // Construir WHERE para perÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â­odo anterior (sin filtros de fecha pero con otros filtros)
      $wherePartsAnterior = ['1=1'];
      $paramsAnterior = [];
      
      if ($medio && in_array($medio, $allowedMedios, true)) {
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
    
    // Top medio de pago del perÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â­odo filtrado
    if ($hasVentaPagos) {
      $stMedio = $pdo->prepare("
        SELECT UPPER(vp.medio_pago) as medio, COUNT(DISTINCT vp.venta_id) as cnt
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
    
    // Ventas de hoy (total facturado y total que entrÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â³ a caja)
    $selectMontoCCHoy = $hasMontoCC ? ", COALESCE(SUM(monto_cc),0) as sum_cc" : ", 0 as sum_cc";
    $stHoy = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total),0) as sum $selectMontoCCHoy FROM ventas WHERE DATE(fecha) = ? AND (estado IS NULL OR estado = 'EMITIDA')");
    $stHoy->execute([$hoy]);
    $rowHoy = $stHoy->fetch(PDO::FETCH_ASSOC);
    $stats['cnt_hoy'] = (int)$rowHoy['cnt'];
    $stats['sum_hoy'] = (float)$rowHoy['sum'];
    $stats['sum_cc_hoy'] = (float)$rowHoy['sum_cc'];
    $stats['sum_caja_hoy'] = $stats['sum_hoy'] - $stats['sum_cc_hoy']; // Lo que entrÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â³ a caja
    $stats['avg_hoy'] = $stats['cnt_hoy'] > 0 ? $stats['sum_hoy'] / $stats['cnt_hoy'] : 0;
    
    // Anuladas hoy
    $stAnuladasHoy = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total),0) as sum FROM ventas WHERE DATE(fecha) = ? AND estado = 'ANULADA'");
    $stAnuladasHoy->execute([$hoy]);
    $rowAnuladasHoy = $stAnuladasHoy->fetch(PDO::FETCH_ASSOC);
    $stats['cnt_anuladas'] = (int)$rowAnuladasHoy['cnt'];
    $stats['sum_anuladas'] = (float)$rowAnuladasHoy['sum'];
    
    // Ventas de ayer
    $selectMontoCCAyer = $hasMontoCC ? ", COALESCE(SUM(monto_cc),0) as sum_cc" : ", 0 as sum_cc";
    $stAyer = $pdo->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(total),0) as sum $selectMontoCCAyer FROM ventas WHERE DATE(fecha) = ? AND (estado IS NULL OR estado = 'EMITIDA')");
    $stAyer->execute([$ayer]);
    $rowAyer = $stAyer->fetch(PDO::FETCH_ASSOC);
    $stats['cnt_ayer'] = (int)$rowAyer['cnt'];
    $stats['sum_ayer'] = (float)$rowAyer['sum'];
    $stats['sum_cc_ayer'] = (float)$rowAyer['sum_cc'];
    $stats['sum_caja_ayer'] = $stats['sum_ayer'] - $stats['sum_cc_ayer'];
    
    // Top medio de pago de hoy
    if ($hasVentaPagos) {
      $stMedio = $pdo->query("
        SELECT UPPER(vp.medio_pago) as medio, COUNT(DISTINCT vp.venta_id) as cnt
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
   Datos para grÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡ficos
========================= */
$chartData = ['labels' => [], 'ventas' => [], 'totales' => []];
$chartMedios = ['labels' => [], 'data' => [], 'colors' => []];
$chartRangeLabel = (!$desde && !$hasta) ? 'Ultimos 7 dias' : $stats['periodo_label'];

try {
  $chartWhereParts = $whereParts;
  $chartParams = $params;

  if ($estado === '') {
    $chartWhereParts[] = "(v.estado IS NULL OR v.estado = 'EMITIDA')";
  }
  if (!$desde && !$hasta) {
    $chartWhereParts[] = 'v.fecha >= :chart_since';
    $chartParams[':chart_since'] = date('Y-m-d 00:00:00', strtotime('-6 days'));
  }

  $chartWhereSQL = implode(' AND ', $chartWhereParts);
  $st = $pdo->prepare("
    SELECT DATE(v.fecha) as dia, COUNT(*) as cnt, COALESCE(SUM(v.total), 0) as sum
    FROM ventas v
    WHERE $chartWhereSQL
    GROUP BY DATE(v.fecha)
    ORDER BY dia
  ");
  $st->execute($chartParams);
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $chartData['labels'][] = date('d/m', strtotime($row['dia']));
    $chartData['ventas'][] = (int)$row['cnt'];
    $chartData['totales'][] = round((float)$row['sum'], 2);
  }

  $colores = ['EFECTIVO' => '#22c55e', 'MP' => '#3b82f6', 'DEBITO' => '#f59e0b', 'CREDITO' => '#8b5cf6', 'MIXTO' => '#ec4899'];
  if ($hasVentaPagos) {
    $st = $pdo->prepare("
      SELECT UPPER(vp.medio_pago) as medio, COUNT(DISTINCT vp.venta_id) as cnt
      FROM venta_pagos vp
      JOIN ventas v ON v.id = vp.venta_id
      WHERE $chartWhereSQL
      GROUP BY UPPER(vp.medio_pago)
    ");
    $st->execute($chartParams);
  } else {
    $st = $pdo->prepare("
      SELECT UPPER(v.medio_pago) as medio, COUNT(*) as cnt
      FROM ventas v
      WHERE $chartWhereSQL
      GROUP BY UPPER(v.medio_pago)
    ");
    $st->execute($chartParams);
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
   Query principal - Detectar columnas dinÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡micamente
========================= */
// Vendedor (compat: usuario_id / user_id / vendedor_id)
$hasUsuarioId  = has_column($pdo, 'ventas', 'usuario_id');
$hasUserId     = has_column($pdo, 'ventas', 'user_id');
$hasVendedorId = has_column($pdo, 'ventas', 'vendedor_id');

$vendedorExpr = 'NULL';
if ($hasUsuarioId && $hasVendedorId) {
  $vendedorExpr = 'COALESCE(v.usuario_id, v.vendedor_id)';
} elseif ($hasUsuarioId) {
  $vendedorExpr = 'v.usuario_id';
} elseif ($hasUserId) {
  $vendedorExpr = 'v.user_id';
} elseif ($hasVendedorId) {
  $vendedorExpr = 'v.vendedor_id';
}

$joinVendedor = (has_table($pdo, 'users') && $vendedorExpr !== 'NULL')
  ? "LEFT JOIN users u ON u.id = {$vendedorExpr}"
  : '';

$vendedorSelect = ($joinVendedor !== '')
  ? 'u.username AS vendedor_username,'
  : 'NULL AS vendedor_username,';

$terminalJoin = $hasTerminalId ? "LEFT JOIN terminales t ON t.id = v.terminal_id" : '';
$terminalSelect = $hasTerminalId ? 'v.terminal_id, t.nombre AS terminal_nombre,' : 'NULL AS terminal_id, NULL AS terminal_nombre,';
$montoCCSelect = $hasMontoCC ? 'COALESCE(v.monto_cc, 0) AS monto_cc,' : '0 AS monto_cc,';


$sql = "
  SELECT v.id, v.fecha, v.total, v.estado, v.medio_pago, v.cliente_id,
         $descuentoCol AS descuento_monto,
         $terminalSelect
         $montoCCSelect
         c.nombre AS cliente_nombre,
         $vendedorSelect
         (SELECT COUNT(*) FROM venta_items vi WHERE vi.venta_id = v.id) AS items_count,
         (SELECT SUBSTRING_INDEX(GROUP_CONCAT(p.nombre SEPARATOR ', '), ', ', 3)
          FROM venta_items vi
          JOIN productos p ON p.id = vi.producto_id
          WHERE vi.venta_id = v.id
         ) AS productos_resumen
  FROM ventas v
  LEFT JOIN clientes c ON c.id = v.cliente_id
  $joinVendedor
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
$extraCss = ['assets/css/ventas.css?v=7','assets/css/ventas_kpis.css?v=3'];
$extraJs = [
  'assets/js/ventas.js?v=5.0',
  'assets/js/ventas_kpis.js?v=2'
];

require __DIR__ . '/partials/header.php';

$queryParams = [
  'venta_id' => $venta_id !== '' ? $venta_id : null,
  'desde' => $desde ?: null,
  'hasta' => $hasta ?: null,
  'medio' => $medio !== '' ? $medio : null,
  'estado' => $estado !== '' ? $estado : null,
  'hora_desde' => $hora_desde !== '' ? $hora_desde : null,
  'hora_hasta' => $hora_hasta !== '' ? $hora_hasta : null,
  'cliente_id' => $cliente_id > 0 ? $cliente_id : null,
  'per_page' => $perPage,
];
$queryParams = array_filter($queryParams, static fn($value) => $value !== null && $value !== '');
?>

<div class="ventas-page">
  <div class="panel ventas-shell">

  <!-- Header -->
  <div class="ventas-header">
    <div class="ventas-header-left">
      <span class="section-kicker">Operacion comercial</span>
      <h1>Ventas</h1>
      <p class="ventas-header-copy">Segui tickets, clientes, medios de pago y rendimiento del periodo desde un solo historial.</p>
      <div class="ventas-header-meta">
        <span class="ventas-meta-pill"><?= number_format($totalRows) ?> registros</span>
        <span class="ventas-meta-pill">Periodo: <?= h($stats['periodo_label']) ?></span>
      </div>
    </div>
    <div class="ventas-header-right">
      <label class="paper-control">
        <span>Ticket</span>
        <select id="paperSel" class="paper-select">
          <option value="80">80mm</option>
          <option value="58">58mm</option>
        </select>
      </label>
      <button id="btnCharts" class="btn btn-secondary btn-compact" type="button" title="Graficos (Ctrl+E)" aria-expanded="false">Ver graficos</button>
      <a href="?<?= http_build_query($queryParams + ['export' => 'csv']) ?>" class="btn btn-primary btn-compact" title="Exportar CSV">Exportar CSV</a>
    </div>
  </div>
  
<!-- KPIs (Operativos) - CLICKEABLES -->
<section id="ventas-kpis" class="ventas-kpis">
  <div class="vkpi-grid">
    <div class="vkpi-card vkpi-clickable" data-filter="fecha" title="Filtrar por fecha">
      <div class="vkpi-label">Tickets (<?= h($stats['periodo_label']) ?>)</div>
      <div class="vkpi-value" data-kpi="tickets"><?= number_format((int)($stats['cnt_hoy'] ?? 0)) ?></div>
      <?php if ($stats['diff_ventas'] != 0): ?>
        <div class="vkpi-diff <?= $stats['diff_ventas'] > 0 ? 'positive' : 'negative' ?>">
          <?= $stats['diff_ventas'] > 0 ? '+' : '' ?><?= $stats['diff_ventas'] ?>% vs anterior
        </div>
      <?php endif; ?>
    </div>

    <div class="vkpi-card vkpi-clickable" data-filter="estado" data-filter-value="EMITIDA" title="Ver solo confirmadas">
      <div class="vkpi-label">Total confirmado</div>
      <div class="vkpi-value" data-kpi="facturacion"><?= money((float)($stats['sum_hoy'] ?? 0)) ?></div>
      <?php if ($stats['diff_total'] != 0): ?>
        <div class="vkpi-diff <?= $stats['diff_total'] > 0 ? 'positive' : 'negative' ?>">
          <?= $stats['diff_total'] > 0 ? '+' : '' ?><?= $stats['diff_total'] ?>% vs anterior
        </div>
      <?php endif; ?>
    </div>

    <div class="vkpi-card">
      <div class="vkpi-label">Ticket promedio</div>
      <div class="vkpi-value" data-kpi="ticket_promedio"><?= money((float)($stats['avg_hoy'] ?? 0)) ?></div>
    </div>

    <div class="vkpi-card vkpi-clickable" data-filter="estado" data-filter-value="ANULADA" title="Ver solo anuladas">
      <div class="vkpi-label">Anuladas</div>
      <div class="vkpi-value vkpi-danger" data-kpi="anuladas"><?= (int)($stats['cnt_anuladas'] ?? 0) ?></div>
      <div class="vkpi-sub" data-kpi="monto_anulado"><?= money((float)($stats['sum_anuladas'] ?? 0)) ?></div>
    </div>

    <?php if ($stats['top_medio'] !== 'N/A'): ?>
    <div class="vkpi-card vkpi-clickable" data-filter="medio" data-filter-value="<?= h($stats['top_medio']) ?>" title="Filtrar por <?= h($stats['top_medio']) ?>">
      <div class="vkpi-label">Top Medio</div>
      <div class="vkpi-value"><?= h($stats['top_medio']) ?></div>
      <div class="vkpi-sub"><?= $stats['top_medio_pct'] ?>% de ventas</div>
    </div>
    <?php endif; ?>
  </div>
</section>

  <!-- Graficos (oculto por defecto) -->
  <div id="chartsPanel" class="charts-panel hidden">
    <div class="charts-grid">
      <div class="chart-box">
        <h4>Ventas por dia</h4>
        <canvas id="chartVentas"></canvas>
      </div>
      <div class="chart-box">
        <h4>Distribucion por medio</h4>
        <canvas id="chartMedios"></canvas>
      </div>
    </div>
  </div>

  <!-- Filtros -->
  <div class="filtros-panel">
    <div class="filtros-head">
      <div>
        <span class="section-kicker">Consulta</span>
        <h2>Filtros y rango</h2>
      </div>
      <p>Combina fecha, hora, cliente y medio de pago para aislar rapido cualquier tramo de ventas.</p>
    </div>
    <form id="ventasForm" method="GET" class="filtros-form">
      <div class="filtros-main">
        <div class="filtro-group">
          <input type="text" name="venta_id" placeholder="Venta #" value="<?= h($venta_id) ?>" class="input-search">
        </div>
        
        <div class="filtro-group">
          <input type="date" name="desde" value="<?= h($desde) ?>" title="Desde">
        </div>
        
        <div class="filtro-group">
          <input type="date" name="hasta" value="<?= h($hasta) ?>" title="Hasta">
        </div>
        
        <div class="filtro-group">
          <select name="estado">
            <option value="">Todos los estados</option>
            <option value="EMITIDA" <?= $estado === 'EMITIDA' ? 'selected' : '' ?>>Confirmadas</option>
            <option value="ANULADA" <?= $estado === 'ANULADA' ? 'selected' : '' ?>>Anuladas</option>
          </select>
        </div>

        <div class="filtros-actions">
          <button type="button" id="btnMoreFilters" class="btn btn-ghost btn-filter-toggle">Filtros avanzados</button>
          <a href="ventas.php" class="btn btn-ghost btn-filter-clear">Limpiar</a>
          <button type="submit" class="btn btn-primary">Aplicar</button>
        </div>
      </div>
      
      <!-- Filtros avanzados (ocultos) -->
      <div id="advancedFilters" class="filtros-advanced hidden">
        <div class="filtro-group">
          <label>Medio</label>
          <select name="medio">
            <option value="">Todos los medios</option>
            <option value="EFECTIVO" <?= $medio === 'EFECTIVO' ? 'selected' : '' ?>>Efectivo</option>
            <option value="MP" <?= $medio === 'MP' ? 'selected' : '' ?>>MP</option>
            <option value="DEBITO" <?= $medio === 'DEBITO' ? 'selected' : '' ?>>Debito</option>
            <option value="CREDITO" <?= $medio === 'CREDITO' ? 'selected' : '' ?>>Credito</option>
            <option value="TRANSFERENCIA" <?= $medio === 'TRANSFERENCIA' ? 'selected' : '' ?>>Transferencia</option>
            <option value="MODO" <?= $medio === 'MODO' ? 'selected' : '' ?>>Modo</option>
            <option value="QR" <?= $medio === 'QR' ? 'selected' : '' ?>>QR</option>
          </select>
        </div>
        <div class="filtro-group">
          <label>Hora desde</label>
          <div class="hora-ampm-group">
            <select id="horaDesdeHora" class="hora-select">
              <option value="">--</option>
              <?php for ($h = 1; $h <= 12; $h++): ?>
                <option value="<?= $h ?>"><?= $h ?></option>
              <?php endfor; ?>
            </select>
            <span class="hora-sep">:</span>
            <select id="horaDesdeMin" class="min-select">
              <option value="00">00</option>
              <option value="15">15</option>
              <option value="30">30</option>
              <option value="45">45</option>
            </select>
            <select id="horaDesdeAmpm" class="ampm-select">
              <option value="AM">AM</option>
              <option value="PM">PM</option>
            </select>
          </div>
          <input type="hidden" name="hora_desde" id="horaDesdeHidden" value="<?= h($hora_desde) ?>">
        </div>
        <div class="filtro-group">
          <label>Hora hasta</label>
          <div class="hora-ampm-group">
            <select id="horaHastaHora" class="hora-select">
              <option value="">--</option>
              <?php for ($h = 1; $h <= 12; $h++): ?>
                <option value="<?= $h ?>"><?= $h ?></option>
              <?php endfor; ?>
            </select>
            <span class="hora-sep">:</span>
            <select id="horaHastaMin" class="min-select">
              <option value="00">00</option>
              <option value="15">15</option>
              <option value="30">30</option>
              <option value="45">45</option>
            </select>
            <select id="horaHastaAmpm" class="ampm-select">
              <option value="AM">AM</option>
              <option value="PM">PM</option>
            </select>
          </div>
          <input type="hidden" name="hora_hasta" id="horaHastaHidden" value="<?= h($hora_hasta) ?>">
          <small>La hora se combina con la fecha para cubrir rangos cruzados.</small>
        </div>
        <div class="filtro-group filtro-cliente">
          <label>Cliente</label>
          <div class="cliente-autocomplete">
            <input type="text" 
                   id="clienteSearch" 
                   placeholder="Buscar cliente..." 
                   value="<?= h($clienteNombre) ?>"
                   autocomplete="off">
            <input type="hidden" name="cliente_id" id="clienteIdHidden" value="<?= $cliente_id ?: '' ?>">
            <button type="button" id="btnClearCliente" class="btn-clear" title="Limpiar">&times;</button>
            <div id="clienteDropdown" class="cliente-dropdown"></div>
          </div>
        </div>

        <div class="chips-row">
          <span class="chips-label">Periodo rapido</span>
          <span class="chip" data-range="today">Hoy</span>
          <span class="chip" data-range="yesterday">Ayer</span>
          <span class="chip" data-range="7d">7 dias</span>
          <span class="chip" data-range="30d">30 dias</span>
          <span class="chip-sep">|</span>
          <span class="chip" data-hora="06:00,12:00">Manana</span>
          <span class="chip" data-hora="12:00,18:00">Tarde</span>
          <span class="chip" data-hora="18:00,23:59">Noche</span>
        </div>
      </div>
      
      <!-- Chips rÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡pidos -->
      <input type="hidden" name="page" id="hiddenPage" value="1">
    </form>
  </div>

  <!-- Filtros activos -->
  <?php 
  $filtrosActivos = [];
  if ($medio) $filtrosActivos[] = ['key' => 'medio', 'label' => "Medio: $medio"];
  if ($estado) $filtrosActivos[] = ['key' => 'estado', 'label' => "Estado: $estado"];
  
  // Mostrar fecha+hora combinados si ambos estÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¡n presentes
  if ($desde || $hasta || $hora_desde || $hora_hasta) {
    $label = 'Rango: ';
    
    if ($desde) {
      $label .= date('d/m/Y', strtotime($desde));
      if ($hora_desde) {
        $label .= ' ' . date('g:i A', strtotime("2000-01-01 $hora_desde"));
      }
    } else {
      $label .= '...';
    }
    
    $label .= ' -> ';
    
    if ($hasta) {
      $label .= date('d/m/Y', strtotime($hasta));
      if ($hora_hasta) {
        $label .= ' ' . date('g:i A', strtotime("2000-01-01 $hora_hasta"));
      }
    } else {
      $label .= '...';
    }
    
    $filtrosActivos[] = ['key' => 'fecha', 'label' => $label];
  }
  
  if ($cliente_id) $filtrosActivos[] = ['key' => 'cliente', 'label' => "Cliente: " . ($clienteNombre ?: "#$cliente_id")];
  ?>
  <?php if ($filtrosActivos): ?>
  <div class="filtros-activos">
    <?php foreach ($filtrosActivos as $f): ?>
      <span class="filtro-tag">
        <?= h($f['label']) ?>
        <button type="button" class="filtro-remove" data-filter="<?= $f['key'] ?>">&times;</button>
      </span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Info de resultados -->
  <div class="results-info">
    <div class="results-info-main">
      <span><strong><?= number_format($fromRow) ?>-<?= number_format($toRow) ?></strong> de <strong><?= number_format($totalRows) ?></strong> ventas</span>
      <span>Pagina <?= number_format($page) ?> de <?= number_format($totalPages) ?></span>
    </div>
    <label class="results-per-page">
      <span>Por pagina</span>
      <select name="per_page" id="perPageSelect" form="ventasForm">
        <option value="20" <?= $perPage === 20 ? 'selected' : '' ?>>20</option>
        <option value="50" <?= $perPage === 50 ? 'selected' : '' ?>>50</option>
        <option value="100" <?= $perPage === 100 ? 'selected' : '' ?>>100</option>
      </select>
    </label>
  </div>

  <!-- PaginaciÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â³n superior -->
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
          <?php if ($hasMontoCC): ?>
          <th class="text-right" title="Monto a Cuenta Corriente">CC</th>
          <?php endif; ?>
          <th class="text-center">Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($ventas): ?>
        <?php foreach ($ventas as $v): 
          $esAnulada = strtoupper($v['estado'] ?? '') === 'ANULADA';
          $medioMostrar = $hasVentaPagos ? ($v['medio_real'] ?? 'N/A') : ($v['medio_pago'] ?: 'N/A');
          $montoCC = (float)($v['monto_cc'] ?? 0);
          $totalVenta = (float)($v['total'] ?? 0);
          // Determinar si es venta mixta (parte efectivo/otro + parte CC)
          $esMixto = strpos($medioMostrar, '+') !== false;
          $esMixtoCC = ($montoCC > 0 && $montoCC < $totalVenta);
          $es100CC = ($montoCC > 0 && abs($montoCC - $totalVenta) < 0.01);
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
            <div class="productos-stack">
              <span class="productos-count"><?= (int)$v['items_count'] ?></span>
              <span class="productos-preview"><?= h(mb_substr($v['productos_resumen'] ?? '', 0, 30)) ?><?= mb_strlen($v['productos_resumen'] ?? '') > 30 ? '...' : '' ?></span>
            </div>
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
              <span class="badge-estado emitida">Confirmada</span>
            <?php endif; ?>
          </td>
          <td class="col-total text-right">
            <?php if ((float)($v['descuento_monto'] ?? 0) > 0): ?>
              <span class="descuento-tag">-<?= money($v['descuento_monto']) ?></span>
            <?php endif; ?>
            <?= money($v['total']) ?>
            <?php if ($esMixtoCC): ?>
              <small class="text-muted d-block" title="Entro a caja">(<?= money($totalVenta - $montoCC) ?> caja)</small>
            <?php endif; ?>
          </td>
          <?php if ($hasMontoCC): ?>
          <td class="col-cc text-right">
            <?php if ($es100CC): ?>
              <span class="badge-cc badge-cc-full" title="100% Cuenta Corriente"><?= money($montoCC) ?></span>
            <?php elseif ($esMixtoCC): ?>
              <span class="badge-cc badge-cc-mixto" title="Venta mixta"><?= money($montoCC) ?></span>
            <?php else: ?>
              <span class="text-muted">-</span>
            <?php endif; ?>
          </td>
          <?php endif; ?>
          <td class="col-acciones text-center">
            <div class="acciones-group">
              <button type="button" class="btn-action" data-preview="<?= (int)$v['id'] ?>" title="Vista previa">Vista</button>
              <button type="button" class="btn-action" data-ticket="<?= (int)$v['id'] ?>" title="Ver ticket">Ticket</button>
              <a href="venta_detalle.php?id=<?= (int)$v['id'] ?>" class="btn-action btn-action-link" title="Detalle">Detalle</a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr>
          <td colspan="<?= $hasMontoCC ? 9 : 8 ?>" class="empty-state">
            <div class="empty-icon">Sin resultados</div>
            <p>No hay ventas con los filtros seleccionados</p>
          </td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- PaginaciÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Â ÃƒÂ¢Ã¢â€šÂ¬Ã¢â€žÂ¢ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â³n inferior -->
  <?= render_pagination($page, $totalPages, $queryParams, false) ?>

  </div>
</div>

<!-- Modal Preview -->
<div id="previewModal" class="modal hidden">
  <div class="modal-backdrop"></div>
  <div class="modal-content">
    <div class="modal-header">
      <h3>Venta #<span id="previewId"></span></h3>
      <button class="modal-close" data-close>&times;</button>
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
        <button id="btnPrintTicket" class="btn btn-primary">Imprimir</button>
        <button class="modal-close" data-close>&times;</button>
      </div>
    </div>
    <div class="modal-body">
      <iframe id="ticketFrame" src="" frameborder="0"></iframe>
    </div>
  </div>
</div>

<!-- Toast Container (NUEVO - consistente con productos/stock) -->
<div id="toastContainer" class="toast-container"></div>

<!-- Keyboard hints -->
<div class="keyboard-hints" id="keyboardHints">
    <div class="keyboard-hints-item">
        <kbd>Ctrl</kbd> + <kbd>K</kbd> = Buscar
    </div>
    <div class="keyboard-hints-item">
        <kbd>Ctrl</kbd> + <kbd>E</kbd> = Graficos
    </div>
    <div class="keyboard-hints-item">
        <kbd>Esc</kbd> = Cerrar
    </div>
</div>

<!-- Datos para JS -->
<script>
window.VENTAS_CONFIG = {
  chartJsUrl: 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'
};
window.VENTAS_DATA = {
  chartRangeLabel: <?= json_encode($chartRangeLabel) ?>,
  chartVentas: <?= json_encode($chartData) ?>,
  chartMedios: <?= json_encode($chartMedios) ?>
};
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>

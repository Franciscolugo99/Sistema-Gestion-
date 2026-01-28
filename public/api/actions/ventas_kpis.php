<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/db_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function json_out(array $payload, int $code = 200): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function safe_date(string $s): ?string {
  $s = trim($s);
  if ($s === '') return null;
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
  return null;
}

function safe_datetime(string $s): ?string {
  $s = trim($s);
  if ($s === '') return null;
  if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $s)) return $s;
  if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $s)) return $s . ':00';
  return null;
}

function safe_time(string $s): ?string {
  $s = trim($s);
  if ($s === '') return null;
  if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $s)) return $s;
  return null;
}

function safe_upper_token(string $s, int $maxLen = 32): ?string {
  $s = strtoupper(trim($s));
  if ($s === '') return null;
  if (strlen($s) > $maxLen) return null;
  if (!preg_match('/^[A-Z0-9_+\- ]+$/', $s)) return null;
  return $s;
}

function get_pdo(): PDO {
  // Intentar bootstrap estándar FLUS
  $bootstrapCandidates = [
    __DIR__ . '/../../bootstrap.php',
    __DIR__ . '/../bootstrap.php',
    __DIR__ . '/../../lib/root.php',
  ];
  foreach ($bootstrapCandidates as $p) {
    if (is_file($p)) { require_once $p; }
  }

  // Cargar config si existe FLUS_ROOT (patrón típico del proyecto)
  if (defined('FLUS_ROOT')) {
    $cfg = FLUS_ROOT . '/src/config.php';
    if (is_file($cfg)) require_once $cfg;
  } else {
    $cfg2 = __DIR__ . '/../../../src/config.php';
    if (is_file($cfg2)) require_once $cfg2;
  }

  if (function_exists('getPDO')) {
    $pdo = getPDO();
    if ($pdo instanceof PDO) return $pdo;
  }

  // Fallback por constantes DB_*
  $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
  $name = defined('DB_NAME') ? DB_NAME : 'kiosco';
  $user = defined('DB_USER') ? DB_USER : 'root';
  $pass = defined('DB_PASS') ? DB_PASS : '';
  $port = defined('DB_PORT') ? DB_PORT : '3306';
  $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';

  $dsn = "mysql:host={$host};dbname={$name};port={$port};charset={$charset}";
  return new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
}


try {
  $desde_raw = (string)($_GET['desde'] ?? '');
  $hasta_raw = (string)($_GET['hasta'] ?? '');

  $hora_desde = safe_time((string)($_GET['hora_desde'] ?? ''));
  $hora_hasta = safe_time((string)($_GET['hora_hasta'] ?? ''));

  $medio = safe_upper_token((string)($_GET['medio'] ?? ''));
  $estado = safe_upper_token((string)($_GET['estado'] ?? ''));
  $venta_id = trim((string)($_GET['venta_id'] ?? ''));
  $cliente_id = trim((string)($_GET['cliente_id'] ?? ''));

  $today = (new DateTimeImmutable('now'))->format('Y-m-d');

  // Armamos límites datetime (por defecto: hoy completo)
  $desde_dt = safe_datetime($desde_raw);
  if ($desde_dt === null) {
    $d = safe_date($desde_raw) ?? $today;
    $desde_dt = $d . ' 00:00:00';
  }

  $hasta_dt = safe_datetime($hasta_raw);
  if ($hasta_dt === null) {
    $d = safe_date($hasta_raw) ?? $today;
    $hasta_dt = $d . ' 23:59:59';
  }

  // Guardrails rango
  $dt1 = new DateTimeImmutable($desde_dt);
  $dt2 = new DateTimeImmutable($hasta_dt);
  if ($dt2 < $dt1) {
    [$desde_dt, $hasta_dt] = [$hasta_dt, $desde_dt];
    $dt1 = new DateTimeImmutable($desde_dt);
    $dt2 = new DateTimeImmutable($hasta_dt);
  }
  $diffDays = (int)$dt1->diff($dt2)->format('%a');
  if ($diffDays > 366) {
    json_out(['ok'=>false, 'error'=>'Rango de fechas demasiado grande (máx 366 días).'], 400);
  }

  $pdo = get_pdo();
  $hasVentaPagos = has_table($pdo, 'venta_pagos');
  $hasVentaPromos = has_table($pdo, 'venta_promos');

  // Columnas dinámicas (compatibilidad)
  $descuentoCol = '0';
  if (has_column($pdo, 'ventas', 'descuento_total')) {
    $descuentoCol = 'v.descuento_total';
  } elseif (has_column($pdo, 'ventas', 'descuento_monto')) {
    $descuentoCol = 'v.descuento_monto';
  }

  $recargoCol = '0';
  if (has_column($pdo, 'ventas', 'recargo_total')) {
    $recargoCol = 'v.recargo_total';
  }

  // WHERE dinámico (similar a ventas.php)
  $whereParts = ['1=1', 'v.fecha BETWEEN :desde_dt AND :hasta_dt'];
  $params = [':desde_dt' => $desde_dt, ':hasta_dt' => $hasta_dt];

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

  // Medio de pago (si hay venta_pagos => filtro por existencia; si no => ventas.medio_pago)
  if ($medio) {
    if ($hasVentaPagos) {
      $whereParts[] = "EXISTS (SELECT 1 FROM venta_pagos vpp WHERE vpp.venta_id = v.id AND UPPER(vpp.medio_pago) = :medio)";
    } else {
      $whereParts[] = "UPPER(v.medio_pago) = :medio";
    }
    $params[':medio'] = $medio;
  }

  // Estado
  if ($estado === 'EMITIDA') {
    $whereParts[] = "(v.estado IS NULL OR v.estado = 'EMITIDA')";
  } elseif ($estado === 'ANULADA') {
    $whereParts[] = "v.estado = 'ANULADA'";
  }

  // Venta ID
  if ($venta_id !== '' && ctype_digit($venta_id)) {
    $whereParts[] = "v.id = :venta_id";
    $params[':venta_id'] = (int)$venta_id;
  }

  // Cliente ID
  if ($cliente_id !== '' && ctype_digit($cliente_id) && (int)$cliente_id > 0) {
    $whereParts[] = "v.cliente_id = :cliente_id";
    $params[':cliente_id'] = (int)$cliente_id;
  }

  $whereSQL = implode(' AND ', $whereParts);

  // KPI base (1 query)
  $sql = "
    SELECT
      SUM(CASE WHEN (v.estado IS NULL OR v.estado='EMITIDA') THEN 1 ELSE 0 END)                                    AS tickets,
      COALESCE(SUM(CASE WHEN (v.estado IS NULL OR v.estado='EMITIDA') THEN v.total ELSE 0 END),0)                   AS facturacion,
      COALESCE(SUM(CASE WHEN (v.estado IS NULL OR v.estado='EMITIDA') THEN {$descuentoCol} ELSE 0 END),0)           AS descuentos,
      COALESCE(SUM(CASE WHEN (v.estado IS NULL OR v.estado='EMITIDA') THEN {$recargoCol} ELSE 0 END),0)             AS recargos,
      SUM(CASE WHEN v.estado='ANULADA' THEN 1 ELSE 0 END)                                                          AS anuladas,
      COALESCE(SUM(CASE WHEN v.estado='ANULADA' THEN v.total ELSE 0 END),0)                                         AS monto_anulado
    FROM ventas v
    WHERE {$whereSQL}
  ";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  $tickets = (int)($row['tickets'] ?? 0);
  $facturacion = (float)($row['facturacion'] ?? 0);
  $descuentos = (float)($row['descuentos'] ?? 0);
  $recargos = (float)($row['recargos'] ?? 0);
  $anuladas = (int)($row['anuladas'] ?? 0);
  $monto_anulado = (float)($row['monto_anulado'] ?? 0);

  $ticket_promedio = $tickets > 0 ? ($facturacion / $tickets) : 0.0;

  // Descuento por promos (opcional)
  $desc_promos = 0.0;
  if ($hasVentaPromos && has_column($pdo, 'venta_promos', 'descuento_monto')) {
    try {
      $sqlP = "
        SELECT COALESCE(SUM(vpr.descuento_monto),0) AS desc_promos
        FROM venta_promos vpr
        JOIN ventas v ON v.id = vpr.venta_id
        WHERE {$whereSQL} AND (v.estado IS NULL OR v.estado='EMITIDA')
      ";
      $stP = $pdo->prepare($sqlP);
      $stP->execute($params);
      $desc_promos = (float)(($stP->fetch(PDO::FETCH_ASSOC)['desc_promos'] ?? 0));
    } catch (Throwable $e) {
      $desc_promos = 0.0;
    }
  }

  // Pagos por medio
  $pagos = [];
  try {
    if ($hasVentaPagos) {
      $sqlPay = "
        SELECT UPPER(p.medio_pago) AS medio_pago, COALESCE(SUM(p.monto),0) AS total
        FROM venta_pagos p
        JOIN ventas v ON v.id = p.venta_id
        WHERE {$whereSQL} AND (v.estado IS NULL OR v.estado='EMITIDA')
        GROUP BY UPPER(p.medio_pago)
        ORDER BY total DESC
      ";
      $stPay = $pdo->prepare($sqlPay);
      $stPay->execute($params);
      $pagos = $stPay->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
      // Fallback: sin tabla venta_pagos => usamos ventas.medio_pago y sumamos total
      $sqlPay2 = "
        SELECT UPPER(COALESCE(v.medio_pago,'N/A')) AS medio_pago, COALESCE(SUM(v.total),0) AS total
        FROM ventas v
        WHERE {$whereSQL} AND (v.estado IS NULL OR v.estado='EMITIDA')
        GROUP BY UPPER(COALESCE(v.medio_pago,'N/A'))
        ORDER BY total DESC
      ";
      $stPay2 = $pdo->prepare($sqlPay2);
      $stPay2->execute($params);
      $pagos = $stPay2->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
  } catch (Throwable $e) {
    $pagos = [];
  }

  json_out([
    'ok' => true,
    'range' => ['desde'=>$desde_dt, 'hasta'=>$hasta_dt],
    'filters' => [
      'medio' => $medio,
      'estado' => $estado,
      'venta_id' => $venta_id !== '' ? $venta_id : null,
      'cliente_id' => $cliente_id !== '' ? $cliente_id : null,
      'hora_desde' => $hora_desde,
      'hora_hasta' => $hora_hasta,
    ],
    'kpis' => [
      'tickets' => $tickets,
      'facturacion' => $facturacion,
      'ticket_promedio' => $ticket_promedio,
      'descuentos' => $descuentos,
      'recargos' => $recargos,
      'desc_promos' => $desc_promos,
      'anuladas' => $anuladas,
      'monto_anulado' => $monto_anulado,
    ],
    'pagos' => $pagos,
  ]);
} catch (Throwable $e) {
  // Nunca romper el módulo: devolver ok:true con ceros si falla algo
  json_out([
    'ok' => true,
    'range' => ['desde'=>null, 'hasta'=>null],
    'kpis' => [
      'tickets' => 0,
      'facturacion' => 0,
      'ticket_promedio' => 0,
      'descuentos' => 0,
      'recargos' => 0,
      'desc_promos' => 0,
      'anuladas' => 0,
      'monto_anulado' => 0,
    ],
    'pagos' => [],
    'warn' => 'ventas_kpis: fallback por error interno',
  ]);
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/db_helpers.php';

/**
 * public/api/ventas_kpis.php - FLUS (2026) ✅
 * KPIs operativos del módulo Ventas (Emitidas/Anuladas) + Pagos por medio.
 *
 * Motivo: en muchas instalaciones /api/actions/* está bloqueado por .htaccess (403),
 * por eso este endpoint vive en /api/ (mismo nivel que ventas_api.php).
 */

require_once __DIR__ . '/../bootstrap.php';
if (function_exists('require_login')) { require_login(); }
if (function_exists('require_permission')) { require_permission('ver_reportes'); }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function json_out(array $payload, int $code = 200): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function safe_date(?string $s): ?string {
  $s = trim((string)$s);
  if ($s === '') return null;
  return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}

function safe_time(?string $s): ?string {
  $s = trim((string)$s);
  if ($s === '') return null;
  return preg_match('/^\d{2}:\d{2}$/', $s) ? $s : null;
}

function _legacy_has_table(PDO $pdo, string $table): bool {
  static $cache = [];
  if (isset($cache[$table])) return $cache[$table];
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
  $st->execute([$table]);
  return $cache[$table] = (bool)$st->fetchColumn();
}

function _legacy_has_column(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $key = $table.'.'.$column;
  if (isset($cache[$key])) return $cache[$key];
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
  $st->execute([$table, $column]);
  return $cache[$key] = (bool)$st->fetchColumn();
}

$pdo = $pdo ?? (function_exists('getPDO') ? getPDO() : null);
if (!$pdo instanceof PDO) {
  json_out(['ok' => false, 'error' => 'PDO no disponible'], 500);
}

/* =========================
   Leer filtros (mismos nombres que ventas.php)
========================= */
$medio      = strtoupper(trim((string)($_GET['medio'] ?? '')));
$estado     = strtoupper(trim((string)($_GET['estado'] ?? '')));
$desde      = safe_date($_GET['desde'] ?? null);
$hasta      = safe_date($_GET['hasta'] ?? null);
$venta_id   = trim((string)($_GET['venta_id'] ?? ''));
$cliente_id = (isset($_GET['cliente_id']) && ctype_digit((string)$_GET['cliente_id'])) ? (int)$_GET['cliente_id'] : 0;
$hora_desde = safe_time($_GET['hora_desde'] ?? null);
$hora_hasta = safe_time($_GET['hora_hasta'] ?? null);

$hasVentaPagos = has_table($pdo, 'venta_pagos');
$hasVentaPromos = has_table($pdo, 'venta_promos');

$allowedMedios = ['EFECTIVO','MP','DEBITO','CREDITO','TRANSFERENCIA','MODO','QR']; // flexible
$allowedEstados = ['EMITIDA','ANULADA'];

/* =========================
   WHERE dinámico (respeta filtros de la tabla)
========================= */
$whereParts = ['1=1'];
$params = [];

// Medio (si no es permitido, se ignora)
if ($medio !== '' && in_array($medio, $allowedMedios, true)) {
  if ($hasVentaPagos) {
    $whereParts[] = "EXISTS (SELECT 1 FROM venta_pagos vp WHERE vp.venta_id = v.id AND UPPER(vp.medio_pago) = :medio)";
  } else {
    $whereParts[] = "UPPER(v.medio_pago) = :medio";
  }
  $params[':medio'] = $medio;
}

// Estado
if ($estado !== '' && in_array($estado, $allowedEstados, true)) {
  if ($estado === 'EMITIDA') {
    $whereParts[] = "(v.estado IS NULL OR v.estado = 'EMITIDA')";
  } else {
    $whereParts[] = "v.estado = 'ANULADA'";
  }
}

// IDs
if ($venta_id !== '' && ctype_digit($venta_id)) {
  $whereParts[] = "v.id = :venta_id";
  $params[':venta_id'] = (int)$venta_id;
}
if ($cliente_id > 0) {
  $whereParts[] = "v.cliente_id = :cliente_id";
  $params[':cliente_id'] = $cliente_id;
}

// Fecha
if ($desde) {
  $whereParts[] = "v.fecha >= :desde";
  $params[':desde'] = $desde . " 00:00:00";
}
if ($hasta) {
  $whereParts[] = "v.fecha <= :hasta";
  $params[':hasta'] = $hasta . " 23:59:59";
}

// Horario (soporta rango cruzado)
if ($hora_desde && $hora_hasta) {
  $minD = ((int)substr($hora_desde, 0, 2)) * 60 + (int)substr($hora_desde, 3, 2);
  $minH = ((int)substr($hora_hasta, 0, 2)) * 60 + (int)substr($hora_hasta, 3, 2);
  if ($minH >= $minD) {
    $whereParts[] = "TIME(v.fecha) BETWEEN :hora_desde AND :hora_hasta";
  } else {
    $whereParts[] = "(TIME(v.fecha) >= :hora_desde OR TIME(v.fecha) <= :hora_hasta)";
  }
  $params[':hora_desde'] = $hora_desde . ":00";
  $params[':hora_hasta'] = $hora_hasta . ":59";
} elseif ($hora_desde) {
  $whereParts[] = "TIME(v.fecha) >= :hora_desde";
  $params[':hora_desde'] = $hora_desde . ":00";
} elseif ($hora_hasta) {
  $whereParts[] = "TIME(v.fecha) <= :hora_hasta";
  $params[':hora_hasta'] = $hora_hasta . ":59";
}

// Si no hay filtros de ningún tipo, default: HOY (para coincidir con tu UI actual)
$hayAlguno = ($medio !== '' || $estado !== '' || $desde || $hasta || $hora_desde || $hora_hasta || ($venta_id !== '' && ctype_digit($venta_id)) || $cliente_id > 0);
if (!$hayAlguno) {
  $whereParts[] = "DATE(v.fecha) = CURDATE()";
}

$whereSQL = implode(' AND ', $whereParts);

/* =========================
   Columnas de descuento (compat)
========================= */
$hasDescuentoTotal = has_column($pdo, 'ventas', 'descuento_total');
$hasDescuentoMonto = has_column($pdo, 'ventas', 'descuento_monto');
$descExpr = '0';
if ($hasDescuentoTotal && $hasDescuentoMonto) {
  $descExpr = 'COALESCE(v.descuento_total, v.descuento_monto, 0)';
} elseif ($hasDescuentoTotal) {
  $descExpr = 'COALESCE(v.descuento_total, 0)';
} elseif ($hasDescuentoMonto) {
  $descExpr = 'COALESCE(v.descuento_monto, 0)';
}

/* =========================
   KPIs
========================= */
try {
  // Emitidas
  $st = $pdo->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN (v.estado IS NULL OR v.estado='EMITIDA') THEN 1 ELSE 0 END),0) AS tickets,
      COALESCE(SUM(CASE WHEN (v.estado IS NULL OR v.estado='EMITIDA') THEN v.total ELSE 0 END),0) AS facturacion,
      COALESCE(SUM(CASE WHEN (v.estado IS NULL OR v.estado='EMITIDA') THEN $descExpr ELSE 0 END),0) AS descuentos
    FROM ventas v
    WHERE $whereSQL
  ");
  $st->execute($params);
  $k = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  $tickets = (int)($k['tickets'] ?? 0);
  $facturacion = (float)($k['facturacion'] ?? 0);
  $descuentos = (float)($k['descuentos'] ?? 0);
  $ticket_prom = $tickets > 0 ? ($facturacion / $tickets) : 0.0;

  // Anuladas
  $stA = $pdo->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN v.estado='ANULADA' THEN 1 ELSE 0 END),0) AS anuladas,
      COALESCE(SUM(CASE WHEN v.estado='ANULADA' THEN v.total ELSE 0 END),0) AS monto_anulado
    FROM ventas v
    WHERE $whereSQL
  ");
  $stA->execute($params);
  $a = $stA->fetch(PDO::FETCH_ASSOC) ?: [];

  $anuladas = (int)($a['anuladas'] ?? 0);
  $monto_anulado = (float)($a['monto_anulado'] ?? 0);

  // Descuento promos
  $desc_promos = 0.0;
  if ($hasVentaPromos) {
    $stP = $pdo->prepare("
      SELECT COALESCE(SUM(vp.descuento_monto),0) AS desc_promos
      FROM venta_promos vp
      JOIN ventas v ON v.id = vp.venta_id
      WHERE $whereSQL AND (v.estado IS NULL OR v.estado='EMITIDA')
    ");
    $stP->execute($params);
    $desc_promos = (float)($stP->fetchColumn() ?: 0);
  }

  // Pagos por medio (solo emitidas)
  $pagos = [];
  if ($hasVentaPagos) {
    $stM = $pdo->prepare("
      SELECT UPPER(p.medio_pago) AS medio, COALESCE(SUM(p.monto),0) AS total
      FROM venta_pagos p
      JOIN ventas v ON v.id = p.venta_id
      WHERE $whereSQL AND (v.estado IS NULL OR v.estado='EMITIDA')
      GROUP BY UPPER(p.medio_pago)
      ORDER BY total DESC
    ");
    $stM->execute($params);
    $pagos = $stM->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } else {
    $stM = $pdo->prepare("
      SELECT UPPER(v.medio_pago) AS medio, COALESCE(SUM(v.total),0) AS total
      FROM ventas v
      WHERE $whereSQL AND (v.estado IS NULL OR v.estado='EMITIDA')
      GROUP BY UPPER(v.medio_pago)
      ORDER BY total DESC
    ");
    $stM->execute($params);
    $pagos = $stM->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  json_out([
    'ok' => true,
    'kpis' => [
      'tickets' => $tickets,
      'facturacion' => $facturacion,
      'ticket_promedio' => $ticket_prom,
      'descuentos' => $descuentos,
      'desc_promos' => $desc_promos,
      'anuladas' => $anuladas,
      'monto_anulado' => $monto_anulado,
    ],
    'pagos' => array_map(static function($r){
      return [
        'medio_pago' => (string)($r['medio'] ?? 'OTRO'),
        'total' => (float)($r['total'] ?? 0),
      ];
    }, $pagos),
  ]);
} catch (Throwable $e) {
  json_out(['ok' => false, 'error' => 'Error KPI', 'detail' => $e->getMessage()], 500);
}

<?php
// public/caja_sesion_export.php
// FLUS v3.2.2 - Exportar detalle de sesión de caja a Excel/CSV
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/caja_session_summary.php';
require_login();
require_permission('ver_historial_caja');

if (function_exists('flus_require_feature')) { flus_require_feature('exports'); }


$sesion_id = sanitize_int($_GET['id'] ?? 0);

if ($sesion_id <= 0) {
  http_response_code(400);
  die('ID de sesión inválido');
}

// ═══════════════════════════════════════════════════════════════════
// FUNCIÓN: Sanitizar valores para evitar CSV Injection
// Excel interpreta como fórmula si empieza con = + - @
// ═══════════════════════════════════════════════════════════════════
function csv_safe(mixed $value): string {
  $s = (string)$value;
  if ($s !== '' && preg_match('/^[=\+\-@]/', $s)) {
    return "'" . $s;
  }
  return $s;
}

// ═══════════════════════════════════════════════════════════════════
// 1. OBTENER DATOS DE LA SESIÓN
// ═══════════════════════════════════════════════════════════════════
try {
  $sqlSesion = "
    SELECT 
      cs.*,
      u.username,
      u.nombre AS usuario_nombre,
      t.nombre AS terminal_nombre
    FROM caja_sesiones cs
    LEFT JOIN users u ON u.id = cs.user_id
    LEFT JOIN terminales t ON t.id = cs.terminal_id
    WHERE cs.id = :id
    LIMIT 1
  ";
  $stSesion = $pdo->prepare($sqlSesion);
  $stSesion->execute([':id' => $sesion_id]);
  $sesion = $stSesion->fetch(PDO::FETCH_ASSOC);

  if (!$sesion) {
    http_response_code(404);
    die('Sesión no encontrada');
  }

  $hasVentaMontoCc = flus_column_exists($pdo, 'ventas', 'monto_cc');
  $hasVentaPagos = flus_table_exists($pdo, 'venta_pagos');
  $ventaMontoCcSelect = $hasVentaMontoCc ? 'v.monto_cc' : '0 AS monto_cc';
  $ventaPagosSelect = $hasVentaPagos ? 'vp.pagos_label' : "NULL AS pagos_label";
  $ventaPagosJoin = $hasVentaPagos ? "
    LEFT JOIN (
      SELECT venta_id, GROUP_CONCAT(UPPER(medio_pago) ORDER BY id SEPARATOR ' + ') AS pagos_label
      FROM venta_pagos
      GROUP BY venta_id
    ) vp ON vp.venta_id = v.id
  " : '';
  $ventaMontoCcGroup = $hasVentaMontoCc ? ', v.monto_cc' : '';
  $ventaPagosGroup = $hasVentaPagos ? ', vp.pagos_label' : '';

  // Ventas
  $sqlVentas = "
    SELECT
      v.id,
      v.fecha,
      v.total,
      {$ventaMontoCcSelect},
      v.medio_pago,
      {$ventaPagosSelect},
      v.estado,
      COALESCE(c.nombre, 'Consumidor Final') AS cliente_nombre,
      COALESCE(SUM(vi.cantidad), 0) AS productos_count
    FROM ventas v
    LEFT JOIN clientes c ON c.id = v.cliente_id
    LEFT JOIN venta_items vi ON vi.venta_id = v.id
    {$ventaPagosJoin}
    WHERE v.caja_id = :sesion_id
    GROUP BY v.id, v.fecha, v.total{$ventaMontoCcGroup}, v.medio_pago, v.estado, c.nombre{$ventaPagosGroup}
    ORDER BY v.fecha DESC
  ";
  $stVentas = $pdo->prepare($sqlVentas);
  $stVentas->execute([':sesion_id' => $sesion_id]);
  $ventas = $stVentas->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Movimientos
  $movMedioPagoSelect = flus_column_exists($pdo, 'caja_movimientos', 'medio_pago') ? 'medio_pago' : 'NULL AS medio_pago';
  $movCcSelect = flus_column_exists($pdo, 'caja_movimientos', 'cc_movimiento_id') ? 'cc_movimiento_id' : 'NULL AS cc_movimiento_id';
  $sqlMovimientos = "
    SELECT id, tipo, concepto, monto, fecha, usuario_registro, {$movMedioPagoSelect}, {$movCcSelect}
    FROM caja_movimientos
    WHERE caja_id = :sesion_id
    ORDER BY fecha DESC
  ";
  $stMovimientos = $pdo->prepare($sqlMovimientos);
  $stMovimientos->execute([':sesion_id' => $sesion_id]);
  $movimientos = $stMovimientos->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $mediosResumen = flus_caja_sesion_medios_resumen($pdo, $sesion_id, $sesion);

} catch (PDOException $e) {
  error_log("Error en caja_sesion_export: " . $e->getMessage());
  http_response_code(500);
  die('Error al obtener datos');
}

// ═══════════════════════════════════════════════════════════════════
// 2. GENERAR ARCHIVO CSV (compatible con Excel)
// ═══════════════════════════════════════════════════════════════════
$filename = 'sesion_caja_' . $sesion_id . '_' . date('Y-m-d');

// Estado de la sesión
$cierre = (string)($sesion['fecha_cierre'] ?? '');
$isOpen = ($cierre === '' || $cierre === '0000-00-00 00:00:00');
$estadoSesion = $isOpen ? 'ABIERTA' : 'CERRADA';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

// BOM para Excel (UTF-8)
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

// ═══════════════════════════════════════════════════════════════════
// SECCIÓN: INFORMACIÓN DE LA SESIÓN
// ═══════════════════════════════════════════════════════════════════
fputcsv($output, ['DETALLE DE SESIÓN DE CAJA #' . $sesion_id], ';');
fputcsv($output, [''], ';');
fputcsv($output, ['INFORMACIÓN GENERAL'], ';');
fputcsv($output, ['Campo', 'Valor'], ';');
fputcsv($output, ['ID Sesión', $sesion_id], ';');
fputcsv($output, ['Estado', $estadoSesion], ';');
fputcsv($output, ['Terminal', csv_safe($sesion['terminal_nombre'] ?? 'N/A')], ';');
fputcsv($output, ['Usuario', csv_safe($sesion['username'] ?? 'N/A')], ';');
fputcsv($output, ['Nombre Usuario', csv_safe($sesion['usuario_nombre'] ?? 'N/A')], ';');
fputcsv($output, ['Fecha Apertura', $sesion['fecha_apertura'] ?? 'N/A'], ';');
fputcsv($output, ['Fecha Cierre', $isOpen ? 'ABIERTA' : ($sesion['fecha_cierre'] ?? 'N/A')], ';');
fputcsv($output, [''], ';');

// ═══════════════════════════════════════════════════════════════════
// SECCIÓN: RESUMEN FINANCIERO
// ═══════════════════════════════════════════════════════════════════
fputcsv($output, ['RESUMEN FINANCIERO'], ';');
fputcsv($output, ['Concepto', 'Monto'], ';');
fputcsv($output, ['Saldo Inicial', number_format((float)($sesion['saldo_inicial'] ?? 0), 2, ',', '.')], ';');
fputcsv($output, ['Total Ventas', number_format((float)($sesion['total_ventas'] ?? 0), 2, ',', '.')], ';');
fputcsv($output, ['Ventas a CC', number_format((float)$mediosResumen['ventas_cc'], 2, ',', '.')], ';');
fputcsv($output, ['Cobros CC', number_format((float)$mediosResumen['cobros_cc'], 2, ',', '.')], ';');
fputcsv($output, ['Base Medios', number_format((float)$mediosResumen['base_medios'], 2, ',', '.')], ';');
fputcsv($output, ['Suma Medios', number_format((float)$mediosResumen['suma_medios'], 2, ',', '.')], ';');
fputcsv($output, ['Diff Medios', number_format((float)$mediosResumen['diff_medios'], 2, ',', '.')], ';');
fputcsv($output, ['Efectivo', number_format((float)($sesion['total_efectivo'] ?? 0), 2, ',', '.')], ';');
fputcsv($output, ['Mercado Pago', number_format((float)($sesion['total_mp'] ?? 0), 2, ',', '.')], ';');
fputcsv($output, ['Débito', number_format((float)($sesion['total_debito'] ?? 0), 2, ',', '.')], ';');
fputcsv($output, ['Crédito', number_format((float)($sesion['total_credito'] ?? 0), 2, ',', '.')], ';');
if (isset($sesion['total_transferencia'])) {
  fputcsv($output, ['Transferencia', number_format((float)($sesion['total_transferencia'] ?? 0), 2, ',', '.')], ';');
}
fputcsv($output, [''], ';');
fputcsv($output, ['Efectivo sistema', number_format((float)($sesion['saldo_sistema'] ?? 0), 2, ',', '.')], ';');
fputcsv($output, ['Efectivo declarado', number_format((float)($sesion['saldo_declarado'] ?? 0), 2, ',', '.')], ';');
fputcsv($output, ['Diferencia', number_format((float)($sesion['diferencia'] ?? 0), 2, ',', '.')], ';');
fputcsv($output, [''], ';');
fputcsv($output, ['Productos Vendidos', (int)($sesion['total_productos'] ?? 0)], ';');
fputcsv($output, ['Anulaciones', (int)($sesion['total_anulaciones'] ?? 0)], ';');
fputcsv($output, [''], ';');

// ═══════════════════════════════════════════════════════════════════
// SECCIÓN: MOVIMIENTOS DE CAJA
// ═══════════════════════════════════════════════════════════════════
fputcsv($output, ['MOVIMIENTOS DE CAJA'], ';');
if (empty($movimientos)) {
  fputcsv($output, ['Sin movimientos registrados'], ';');
} else {
  fputcsv($output, ['ID', 'Fecha', 'Tipo', 'Medio', 'CC Movimiento', 'Concepto', 'Monto', 'Registrado Por'], ';');
  foreach ($movimientos as $mov) {
    fputcsv($output, [
      $mov['id'],
      $mov['fecha'] ?? '',
      strtoupper($mov['tipo'] ?? ''),
      strtoupper((string)($mov['medio_pago'] ?? '')),
      (int)($mov['cc_movimiento_id'] ?? 0) > 0 ? (int)$mov['cc_movimiento_id'] : '',
      csv_safe($mov['concepto'] ?? ''),
      number_format((float)($mov['monto'] ?? 0), 2, ',', '.'),
      csv_safe($mov['usuario_registro'] ?? '')
    ], ';');
  }
}
fputcsv($output, [''], ';');

// ═══════════════════════════════════════════════════════════════════
// SECCIÓN: VENTAS REALIZADAS
// ═══════════════════════════════════════════════════════════════════
fputcsv($output, ['VENTAS REALIZADAS'], ';');
if (empty($ventas)) {
  fputcsv($output, ['Sin ventas registradas'], ';');
} else {
  fputcsv($output, ['ID', 'Fecha', 'Cliente', 'Productos', 'Metodo Pago', 'Monto CC', 'Total', 'Estado'], ';');
  foreach ($ventas as $venta) {
    fputcsv($output, [
      $venta['id'],
      $venta['fecha'] ?? '',
      csv_safe($venta['cliente_nombre'] ?? 'Consumidor Final'),
      (int)($venta['productos_count'] ?? 0),
      flus_caja_sesion_pago_label($venta),
      number_format((float)($venta['monto_cc'] ?? 0), 2, ',', '.'),
      number_format((float)($venta['total'] ?? 0), 2, ',', '.'),
      strtoupper($venta['estado'] ?? 'EMITIDA')
    ], ';');
  }
}
fputcsv($output, [''], ';');

// ═══════════════════════════════════════════════════════════════════
// SECCIÓN: OBSERVACIONES
// ═══════════════════════════════════════════════════════════════════
if (!empty($sesion['notas'])) {
  fputcsv($output, ['OBSERVACIONES DE CIERRE'], ';');
  fputcsv($output, [csv_safe($sesion['notas'])], ';');
  fputcsv($output, [''], ';');
}

// Pie
fputcsv($output, [''], ';');
fputcsv($output, ['Generado el ' . date('d/m/Y H:i:s') . ' - FLUS'], ';');

fclose($output);
exit;

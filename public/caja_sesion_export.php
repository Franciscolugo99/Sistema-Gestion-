<?php
// public/caja_sesion_export.php
// FLUS v3.2.2 - Exportar detalle de sesión de caja a Excel/CSV
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
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

  // Ventas
  $sqlVentas = "
    SELECT
      v.id,
      v.fecha,
      v.total,
      v.medio_pago,
      v.estado,
      COALESCE(c.nombre, 'Consumidor Final') AS cliente_nombre,
      COALESCE(SUM(vi.cantidad), 0) AS productos_count
    FROM ventas v
    LEFT JOIN clientes c ON c.id = v.cliente_id
    LEFT JOIN venta_items vi ON vi.venta_id = v.id
    WHERE v.caja_id = :sesion_id
    GROUP BY v.id, v.fecha, v.total, v.medio_pago, v.estado, c.nombre
    ORDER BY v.fecha DESC
  ";
  $stVentas = $pdo->prepare($sqlVentas);
  $stVentas->execute([':sesion_id' => $sesion_id]);
  $ventas = $stVentas->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Movimientos
  $sqlMovimientos = "
    SELECT id, tipo, concepto, monto, fecha, usuario_registro
    FROM caja_movimientos
    WHERE caja_id = :sesion_id
    ORDER BY fecha DESC
  ";
  $stMovimientos = $pdo->prepare($sqlMovimientos);
  $stMovimientos->execute([':sesion_id' => $sesion_id]);
  $movimientos = $stMovimientos->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
fputcsv($output, ['Efectivo', number_format((float)($sesion['total_efectivo'] ?? 0), 2, ',', '.')], ';');
fputcsv($output, ['Mercado Pago', number_format((float)($sesion['total_mp'] ?? 0), 2, ',', '.')], ';');
fputcsv($output, ['Débito', number_format((float)($sesion['total_debito'] ?? 0), 2, ',', '.')], ';');
fputcsv($output, ['Crédito', number_format((float)($sesion['total_credito'] ?? 0), 2, ',', '.')], ';');
fputcsv($output, [''], ';');
fputcsv($output, ['Total Sistema', number_format((float)($sesion['saldo_sistema'] ?? 0), 2, ',', '.')], ';');
fputcsv($output, ['Total Declarado', number_format((float)($sesion['saldo_declarado'] ?? 0), 2, ',', '.')], ';');
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
  fputcsv($output, ['ID', 'Fecha', 'Tipo', 'Concepto', 'Monto', 'Registrado Por'], ';');
  foreach ($movimientos as $mov) {
    fputcsv($output, [
      $mov['id'],
      $mov['fecha'] ?? '',
      strtoupper($mov['tipo'] ?? ''),
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
  fputcsv($output, ['ID', 'Fecha', 'Cliente', 'Productos', 'Método Pago', 'Total', 'Estado'], ';');
  foreach ($ventas as $venta) {
    fputcsv($output, [
      $venta['id'],
      $venta['fecha'] ?? '',
      csv_safe($venta['cliente_nombre'] ?? 'Consumidor Final'),
      (int)($venta['productos_count'] ?? 0),
      strtoupper($venta['medio_pago'] ?? ''),
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

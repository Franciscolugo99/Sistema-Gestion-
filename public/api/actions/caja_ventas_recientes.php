<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../caja_lib.php';
require_once FLUS_ROOT . '/src/venta_anulaciones_lib.php';

$pdo = $pdo ?? (function_exists('getPDO') ? getPDO() : null);
if (!$pdo instanceof PDO) {
  if (function_exists('json_fail')) {
    json_fail('PDO no disponible', 500);
  }
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'PDO no disponible']);
  exit;
}

$__ok = function (array $payload = []): void {
  if (function_exists('json_ok')) {
    json_ok($payload);
    return;
  }
  echo json_encode(['ok' => true] + $payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
};

if (!function_exists('flus_caja_recientes_has_table')) {
  function flus_caja_recientes_has_table(PDO $pdo, string $table): bool {
    if (function_exists('flus_table_exists')) {
      return (bool)flus_table_exists($pdo, $table);
    }
    if (function_exists('has_table')) {
      return (bool)has_table($pdo, $table);
    }
    return false;
  }
}

if (!function_exists('flus_caja_recientes_has_col')) {
  function flus_caja_recientes_has_col(PDO $pdo, string $table, string $column): bool {
    if (function_exists('flus_column_exists')) {
      return (bool)flus_column_exists($pdo, $table, $column);
    }
    if (function_exists('has_column')) {
      return (bool)has_column($pdo, $table, $column);
    }
    if (function_exists('has_col')) {
      return (bool)has_col($pdo, $table, $column);
    }
    return false;
  }
}

if (!function_exists('flus_caja_recientes_item_precio_expr')) {
  function flus_caja_recientes_item_precio_expr(PDO $pdo): string {
    if (flus_caja_recientes_has_col($pdo, 'venta_items', 'precio_unit_final')) {
      return 'COALESCE(vi.precio_unit_final, vi.precio, 0)';
    }
    if (flus_caja_recientes_has_col($pdo, 'venta_items', 'precio')) {
      return 'COALESCE(vi.precio, 0)';
    }
    if (
      flus_caja_recientes_has_col($pdo, 'venta_items', 'subtotal')
      && flus_caja_recientes_has_col($pdo, 'venta_items', 'cantidad')
    ) {
      return '(CASE WHEN COALESCE(vi.cantidad,0) > 0 THEN vi.subtotal / vi.cantidad ELSE 0 END)';
    }
    return '0';
  }
}

$terminalId = function_exists('current_terminal_id')
  ? (int)current_terminal_id()
  : (int)($_SESSION['terminal_id'] ?? 0);

$caja = $terminalId > 0 ? caja_get_abierta($pdo, $terminalId) : null;
$canAnularVenta = function_exists('user_has_permission') && user_has_permission('anular_venta');
$canAnularItems = function_exists('user_has_permission') && user_has_permission('anular_items_venta');
$limit = max(5, min(30, (int)($_GET['limit'] ?? 12)));

if (!$caja || !is_array($caja) || empty($caja['id'])) {
  $__ok([
    'caja' => null,
    'ventas' => [],
    'permissions' => [
      'can_anular_venta' => $canAnularVenta,
      'can_anular_items' => $canAnularItems,
    ],
  ]);
}

if (!flus_caja_recientes_has_table($pdo, 'ventas') || !flus_caja_recientes_has_col($pdo, 'ventas', 'caja_id')) {
  $__ok([
    'caja' => ['id' => (int)$caja['id'], 'terminal_id' => $terminalId],
    'ventas' => [],
    'permissions' => [
      'can_anular_venta' => $canAnularVenta,
      'can_anular_items' => $canAnularItems,
    ],
  ]);
}

$facturaExistsExpr = '0';
if (
  flus_caja_recientes_has_table($pdo, 'facturas')
  && flus_caja_recientes_has_col($pdo, 'facturas', 'venta_id')
) {
  $facturaWhere = 'f.venta_id = v.id';
  if (flus_caja_recientes_has_col($pdo, 'facturas', 'naturaleza')) {
    $facturaWhere .= " AND f.naturaleza = 'FACTURA'";
  }
  $facturaExistsExpr = "(EXISTS(SELECT 1 FROM facturas f WHERE {$facturaWhere} LIMIT 1))";
}

$facturadaExpr = flus_caja_recientes_has_col($pdo, 'ventas', 'facturada')
  ? "GREATEST(COALESCE(v.facturada,0), {$facturaExistsExpr})"
  : $facturaExistsExpr;

$estadoExpr = flus_caja_recientes_has_col($pdo, 'ventas', 'estado') ? 'COALESCE(v.estado, "EMITIDA")' : '"EMITIDA"';
$medioExpr = flus_caja_recientes_has_col($pdo, 'ventas', 'medio_pago') ? 'COALESCE(v.medio_pago, "")' : '""';
$fechaExpr = flus_caja_recientes_has_col($pdo, 'ventas', 'fecha') ? 'v.fecha' : 'NULL';
$totalExpr = flus_caja_recientes_has_col($pdo, 'ventas', 'total') ? 'COALESCE(v.total, 0)' : '0';
$itemsCountExpr = flus_caja_recientes_has_table($pdo, 'venta_items')
  ? '(SELECT COUNT(*) FROM venta_items vi2 WHERE vi2.venta_id = v.id)'
  : '0';
$productosResumenExpr = (
  flus_caja_recientes_has_table($pdo, 'venta_items')
  && flus_caja_recientes_has_table($pdo, 'productos')
)
  ? "(SELECT SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(p2.nombre, CONCAT('Producto #', vi2.producto_id)) SEPARATOR ', '), ', ', 3)
      FROM venta_items vi2
      LEFT JOIN productos p2 ON p2.id = vi2.producto_id
      WHERE vi2.venta_id = v.id)"
  : 'NULL';

$st = $pdo->prepare("
  SELECT
    v.id,
    {$fechaExpr} AS fecha,
    {$totalExpr} AS total,
    {$estadoExpr} AS estado,
    {$medioExpr} AS medio_pago,
    {$facturadaExpr} AS facturada,
    {$itemsCountExpr} AS items_count,
    {$productosResumenExpr} AS productos_resumen
  FROM ventas v
  WHERE v.caja_id = :caja_id
  ORDER BY v.id DESC
  LIMIT {$limit}
");
$st->execute([':caja_id' => (int)$caja['id']]);
$ventas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$ids = array_map(static fn($row): int => (int)($row['id'] ?? 0), $ventas);
$ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
$itemsByVenta = [];

if ($ids && flus_caja_recientes_has_table($pdo, 'venta_items')) {
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $nombreExpr = flus_caja_recientes_has_table($pdo, 'productos')
    ? "COALESCE(p.nombre, CONCAT('Producto #', vi.producto_id))"
    : "CONCAT('Producto #', vi.producto_id)";
  $codigoExpr = flus_caja_recientes_has_table($pdo, 'productos') ? 'COALESCE(p.codigo, "")' : '""';
  $joinProductos = flus_caja_recientes_has_table($pdo, 'productos')
    ? 'LEFT JOIN productos p ON p.id = vi.producto_id'
    : '';
  $cantidadExpr = flus_caja_recientes_has_col($pdo, 'venta_items', 'cantidad') ? 'COALESCE(vi.cantidad, 0)' : '0';
  $subtotalExpr = flus_caja_recientes_has_col($pdo, 'venta_items', 'subtotal') ? 'COALESCE(vi.subtotal, 0)' : '0';
  $precioExpr = flus_caja_recientes_item_precio_expr($pdo);

  $stItems = $pdo->prepare("
    SELECT
      vi.id,
      vi.venta_id,
      vi.producto_id,
      {$nombreExpr} AS nombre,
      {$codigoExpr} AS codigo,
      {$cantidadExpr} AS cantidad,
      {$precioExpr} AS precio_unitario,
      {$subtotalExpr} AS subtotal
    FROM venta_items vi
    {$joinProductos}
    WHERE vi.venta_id IN ({$placeholders})
    ORDER BY vi.venta_id DESC, vi.id ASC
  ");
  $stItems->execute($ids);

  foreach ($stItems->fetchAll(PDO::FETCH_ASSOC) ?: [] as $item) {
    $ventaId = (int)($item['venta_id'] ?? 0);
    if ($ventaId <= 0) {
      continue;
    }
    $itemsByVenta[$ventaId][] = $item;
  }
}

foreach ($ventas as &$venta) {
  $ventaId = (int)($venta['id'] ?? 0);
  $estado = strtoupper((string)($venta['estado'] ?? ''));
  $facturada = (int)($venta['facturada'] ?? 0) === 1;
  $items = $itemsByVenta[$ventaId] ?? [];
  $anulados = $ventaId > 0 ? flus_venta_items_anulados_map($pdo, $ventaId) : [];
  $ventaItems = [];

  foreach ($items as $item) {
    $itemId = (int)($item['id'] ?? 0);
    $cantidad = round((float)($item['cantidad'] ?? 0), 3);
    $cantidadAnulada = round((float)($anulados[$itemId] ?? 0), 3);
    $cantidadDisponible = max(0.0, round($cantidad - $cantidadAnulada, 3));
    $ventaItems[] = [
      'id' => $itemId,
      'producto_id' => (int)($item['producto_id'] ?? 0),
      'codigo' => (string)($item['codigo'] ?? ''),
      'nombre' => (string)($item['nombre'] ?? ('Item #' . $itemId)),
      'cantidad' => $cantidad,
      'cantidad_anulada' => $cantidadAnulada,
      'cantidad_disponible' => $cantidadDisponible,
      'precio_unitario' => round((float)($item['precio_unitario'] ?? 0), 2),
      'subtotal' => round((float)($item['subtotal'] ?? 0), 2),
    ];
  }

  $venta['id'] = $ventaId;
  $venta['total'] = round((float)($venta['total'] ?? 0), 2);
  $venta['facturada'] = $facturada;
  $venta['items_count'] = (int)($venta['items_count'] ?? count($ventaItems));
  $venta['items'] = $ventaItems;
  $venta['can_anular'] = !$facturada && $estado !== 'ANULADA';
  $venta['can_anular_items'] = $venta['can_anular'] && array_reduce(
    $ventaItems,
    static fn(bool $carry, array $item): bool => $carry || ((float)($item['cantidad_disponible'] ?? 0) > 0),
    false
  );
}
unset($venta);

$__ok([
  'caja' => [
    'id' => (int)$caja['id'],
    'terminal_id' => $terminalId,
  ],
  'ventas' => $ventas,
  'permissions' => [
    'can_anular_venta' => $canAnularVenta,
    'can_anular_items' => $canAnularItems,
  ],
]);

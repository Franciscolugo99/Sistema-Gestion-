<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once FLUS_ROOT . '/src/compras_helpers.php';

require_login_json();
require_perm_json('editar_stock');
require_perm_json('ver_costos');

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
  json_response(['error' => 'ID invalido'], 400);
}

try {
  $st = $pdo->prepare("
    SELECT
      c.*,
      p.nombre AS proveedor_nombre
    FROM compras c
    LEFT JOIN proveedores p ON p.id = c.proveedor_id
    WHERE c.id = ?
  ");
  $st->execute([$id]);
  $compra = $st->fetch(PDO::FETCH_ASSOC);

  if (!$compra) {
    json_response(['error' => 'Compra no encontrada'], 404);
  }

  $stItems = $pdo->prepare("
    SELECT
      ci.*,
      p.nombre,
      p.codigo,
      p.es_pesable,
      p.unidad_venta
    FROM compra_items ci
    JOIN productos p ON p.id = ci.producto_id
    WHERE ci.compra_id = ?
    ORDER BY ci.id
  ");
  $stItems->execute([$id]);
  $items = $stItems->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $itemsMetrics = flus_compra_items_metrics($items);
  $totals = flus_compra_totals((array)$compra, $itemsMetrics);

  $itemsFormatted = array_map(function (array $metrics, array $it): array {
    $qty = (float)($metrics['cantidad'] ?? 0.0);
    $qtyFmt = (bool)($metrics['es_pesable'] ?? false)
      ? number_format($qty, 3, ',', '.')
      : number_format($qty, 0, ',', '.');

    $cu = (float)($metrics['costo_unitario'] ?? 0.0);
    $subtotal = (float)($metrics['subtotal'] ?? 0.0);
    $descItem = (float)($metrics['descuento_monto'] ?? 0.0);

    return [
      'producto_id' => (int)($it['producto_id'] ?? 0),
      'nombre' => (string)($it['nombre'] ?? 'Producto'),
      'codigo' => (string)($it['codigo'] ?? ''),
      'cantidad' => $qty,
      'cantidad_fmt' => $qtyFmt . ' ' . (string)($metrics['unidad'] ?? 'UNIDAD'),
      'costo_unitario' => $cu,
      'costo_fmt' => '$' . number_format($cu, 2, ',', '.'),
      'desc_item' => $descItem,
      'desc_item_fmt' => ($descItem > 0 ? '-$' : '$') . number_format($descItem, 2, ',', '.'),
      'desc_item_tipo' => (string)($metrics['descuento_tipo'] ?? 'MONTO'),
      'desc_item_valor' => (float)($metrics['descuento_valor'] ?? 0.0),
      'subtotal' => $subtotal,
      'subtotal_fmt' => '$' . number_format($subtotal, 2, ',', '.'),
    ];
  }, $itemsMetrics['items'], $items);

  $bruto = (float)($totals['total_bruto'] ?? 0.0);
  $descItemsTotal = (float)($totals['descuento_items_total'] ?? 0.0);
  $descT = (float)($totals['descuento_global'] ?? 0.0);
  $total = (float)($totals['total_compra'] ?? 0.0);
  $descTipo = (string)($totals['descuento_global_tipo'] ?? 'MONTO');
  $descVal = (float)($totals['descuento_global_valor'] ?? 0.0);

  echo json_encode([
    'id' => (int)$compra['id'],
    'fecha' => date('d/m/Y', strtotime((string)$compra['fecha'])),
    'proveedor' => $compra['proveedor_nombre'] ?? 'Sin nombre',
    'tipo_comp' => $compra['tipo_comp'] ?? '',
    'nro_comp' => $compra['nro_comp'] ?? '',
    'obs' => $compra['obs'] ?? '',
    'estado' => $compra['estado'],
    'total_bruto' => $bruto,
    'total_bruto_fmt' => '$' . number_format($bruto, 2, ',', '.'),
    'descuento_items_total' => $descItemsTotal,
    'descuento_items_total_fmt' => '$' . number_format($descItemsTotal, 2, ',', '.'),
    'descuento_total' => $descT,
    'descuento_total_fmt' => '$' . number_format($descT, 2, ',', '.'),
    'descuento_tipo' => $descTipo,
    'descuento_valor' => $descVal,
    'total' => $total,
    'total_fmt' => '$' . number_format($total, 2, ',', '.'),
    'items' => $itemsFormatted,
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  json_response(['error' => 'Error al cargar'], 500);
}

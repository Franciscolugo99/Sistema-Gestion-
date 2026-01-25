<?php
// public/api/compra_detalle.php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';


// FIX: Verificar login Y permisos
require_login();
require_permission('editar_stock');

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
  json_response(['error' => 'ID inválido'], 400);
}

try {
  // Traer compra
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
  
  // Traer items
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
  
  // Formatear items
    // Formatear items (incluye descuento por ítem)
  $itemsFormatted = array_map(function($it) {
    $isPesable = (int)$it['es_pesable'] === 1 || 
                 in_array(strtoupper($it['unidad_venta'] ?? 'UNIDAD'), ['KG','G','LT','ML']);

    $qty = (float)$it['cantidad'];
    $qtyFmt = $isPesable 
      ? number_format($qty, 3, ',', '.') 
      : number_format($qty, 0, ',', '.');

    $cu = (float)($it['costo_unitario'] ?? 0);
    $subtotal = (float)($it['subtotal'] ?? ($qty * $cu));

    // Descuento por ítem (si existe)
    $tipoItem = strtoupper((string)($it['descuento_tipo'] ?? 'MONTO'));
    if (!in_array($tipoItem, ['MONTO','PORC'], true)) $tipoItem = 'MONTO';

    $porc = (float)($it['descuento_porc'] ?? 0);
    $monto = (float)($it['descuento'] ?? 0);

    if ($porc < 0) $porc = 0;
    if ($porc > 100) $porc = 100;
    if ($monto < 0) $monto = 0;

    $descItem = 0.0;
    if ($subtotal > 0) {
      if ($tipoItem === 'PORC') {
        $descItem = $subtotal * ($porc / 100.0);
      } else {
        if ($monto > $subtotal) $monto = $subtotal;
        $descItem = $monto;
      }
    }
    $descItem = round($descItem, 2);

    $descValor = ($tipoItem === 'PORC') ? $porc : $monto;

    return [
      'producto_id' => (int)$it['producto_id'],
      'nombre' => $it['nombre'],
      'codigo' => $it['codigo'],
      'cantidad' => $qty,
      'cantidad_fmt' => $qtyFmt . ' ' . ($it['unidad_venta'] ?? 'UNIDAD'),
      'costo_unitario' => $cu,
      'costo_fmt' => '$' . number_format($cu, 2, ',', '.'),
      'desc_item' => $descItem,
      'desc_item_fmt' => ($descItem > 0 ? '-$' : '$') . number_format($descItem, 2, ',', '.'),
      'desc_item_tipo' => $tipoItem,
      'desc_item_valor' => $descValor,
      'subtotal' => $subtotal,
      'subtotal_fmt' => '$' . number_format($subtotal, 2, ',', '.'),
    ];
  }, $items);
  
  // Respuesta
  $brutoItems = 0.0;
  $descItemsTotal = 0.0;
  foreach ($itemsFormatted as $itf) {
    $brutoItems += (float)($itf['subtotal'] ?? 0.0);
    $descItemsTotal += (float)($itf['desc_item'] ?? 0.0);
  }
  $brutoItems = round($brutoItems, 2);
  $descItemsTotal = round($descItemsTotal, 2);

  // Bruto preferir compras.total_bruto si existe, si no recalcular por items
  $bruto = (float)($compra['total_bruto'] ?? $brutoItems ?? $compra['total'] ?? 0);

  // Descuento global (compras.descuento_total)
  $descT = (float)($compra['descuento_total'] ?? 0);
  $total = (float)($compra['total'] ?? 0);
  $descTipo = (string)($compra['descuento_tipo'] ?? 'MONTO');
  $descVal  = (float)($compra['descuento_valor'] ?? 0);

  echo json_encode([
    'id' => (int)$compra['id'],
    'fecha' => date('d/m/Y', strtotime($compra['fecha'])),
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
    'items' => $itemsFormatted
  ], JSON_UNESCAPED_UNICODE);
  
} catch (Throwable $e) {
  json_response(['error' => 'Error al cargar'], 500);
}
<?php
// public/api/compra_detalle.php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once FLUS_ROOT . '/src/db_helpers.php';
require_login();
require_permission('editar_stock');

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
  json_response(['error' => 'ID inválido'], 400);
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
  
  $itemFields = "ci.*, p.nombre, p.codigo, p.es_pesable, p.unidad_venta";
  
  $hasDescuento = has_column($pdo, 'compra_items', 'descuento');
  $hasDescuentoTipo = has_column($pdo, 'compra_items', 'descuento_tipo');
  $hasDescuentoPorc = has_column($pdo, 'compra_items', 'descuento_porc');
  
  if ($hasDescuento) {
    $itemFields .= ", ci.descuento";
  }
  if ($hasDescuentoTipo) {
    $itemFields .= ", ci.descuento_tipo";
  }
  if ($hasDescuentoPorc) {
    $itemFields .= ", ci.descuento_porc";
  }
  
  $stItems = $pdo->prepare("
    SELECT $itemFields
    FROM compra_items ci
    JOIN productos p ON p.id = ci.producto_id
    WHERE ci.compra_id = ?
    ORDER BY ci.id
  ");
  $stItems->execute([$id]);
  $items = $stItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
  
  $totalDescuentoItems = 0.0;
  $itemsFormatted = array_map(function($it) use (&$totalDescuentoItems, $hasDescuento, $hasDescuentoTipo, $hasDescuentoPorc) {
    $isPesable = (int)$it['es_pesable'] === 1 || 
                 in_array(strtoupper($it['unidad_venta'] ?? 'UNIDAD'), ['KG','G','LT','ML']);
    
    $qty = (float)$it['cantidad'];
    $qtyFmt = $isPesable 
      ? number_format($qty, 3, ',', '.') 
      : number_format($qty, 0, ',', '.');
    
    $itemDesc = 0.0;
    $itemDescFmt = null;
    
    if ($hasDescuento && isset($it['descuento'])) {
      $itemDesc = (float)$it['descuento'];
      $totalDescuentoItems += $itemDesc;
      
      if ($itemDesc > 0) {
        if ($hasDescuentoTipo && isset($it['descuento_tipo']) && $it['descuento_tipo'] === 'PORC') {
          $descPorc = $hasDescuentoPorc && isset($it['descuento_porc']) ? (float)$it['descuento_porc'] : 0;
          $itemDescFmt = number_format($descPorc, 2, ',', '.') . '%';
        } else {
          $itemDescFmt = '$' . number_format($itemDesc, 2, ',', '.');
        }
      }
    }
    
    return [
      'producto_id' => (int)$it['producto_id'],
      'nombre' => $it['nombre'],
      'codigo' => $it['codigo'],
      'cantidad' => $qty,
      'cantidad_fmt' => $qtyFmt . ' ' . ($it['unidad_venta'] ?? 'UNIDAD'),
      'costo_unitario' => (float)$it['costo_unitario'],
      'costo_fmt' => '$' . number_format((float)$it['costo_unitario'], 2, ',', '.'),
      'descuento_item' => $itemDesc,
      'descuento_fmt' => $itemDescFmt,
      'subtotal' => (float)$it['subtotal'],
      'subtotal_fmt' => '$' . number_format((float)$it['subtotal'], 2, ',', '.'),
    ];
  }, $items);
  
  $bruto = (float)($compra['total_bruto'] ?? $compra['total'] ?? 0);
  $descTotal = (float)($compra['descuento_total'] ?? 0);
  $total = (float)($compra['total'] ?? 0);
  $descTipo = (string)($compra['descuento_tipo'] ?? 'MONTO');
  $descVal  = (float)($compra['descuento_valor'] ?? 0);
  
  $descGlobal = $descTotal - $totalDescuentoItems;

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
    
    'descuento_items' => $totalDescuentoItems,
    'descuento_items_fmt' => '$' . number_format($totalDescuentoItems, 2, ',', '.'),
    
    'descuento_global' => $descGlobal,
    'descuento_global_fmt' => '$' . number_format($descGlobal, 2, ',', '.'),
    
    'descuento_total' => $descTotal,
    'descuento_total_fmt' => '$' . number_format($descTotal, 2, ',', '.'),
    
    'descuento_tipo' => $descTipo,
    'descuento_valor' => $descVal,

    'total' => $total,
    'total_fmt' => '$' . number_format($total, 2, ',', '.'),
    'items' => $itemsFormatted
  ], JSON_UNESCAPED_UNICODE);
  
} catch (Throwable $e) {
  json_response(['error' => 'Error al cargar'], 500);
}
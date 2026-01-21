<?php
// public/api/compra_detalle.php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// FIX: Verificar login Y permisos
require_login();
require_permission('editar_stock');


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
  $itemsFormatted = array_map(function($it) {
    $isPesable = (int)$it['es_pesable'] === 1 || 
                 in_array(strtoupper($it['unidad_venta'] ?? 'UNIDAD'), ['KG','G','LT','ML']);
    
    $qty = (float)$it['cantidad'];
    $qtyFmt = $isPesable 
      ? number_format($qty, 3, ',', '.') 
      : number_format($qty, 0, ',', '.');
    
    return [
      'producto_id' => (int)$it['producto_id'],
      'nombre' => $it['nombre'],
      'codigo' => $it['codigo'],
      'cantidad' => $qty,
      'cantidad_fmt' => $qtyFmt . ' ' . ($it['unidad_venta'] ?? 'UNIDAD'),
      'costo_unitario' => (float)$it['costo_unitario'],
      'costo_fmt' => '$' . number_format((float)$it['costo_unitario'], 2, ',', '.'),
      'subtotal' => (float)$it['subtotal'],
      'subtotal_fmt' => '$' . number_format((float)$it['subtotal'], 2, ',', '.'),
    ];
  }, $items);
  
  // Respuesta
  echo json_encode([
    'id' => (int)$compra['id'],
    'fecha' => date('d/m/Y', strtotime($compra['fecha'])),
    'proveedor' => $compra['proveedor_nombre'] ?? 'Sin nombre',
    'tipo_comp' => $compra['tipo_comp'] ?? '',
    'nro_comp' => $compra['nro_comp'] ?? '',
    'obs' => $compra['obs'] ?? '',
    'estado' => $compra['estado'],
    'total' => (float)$compra['total'],
    'total_fmt' => '$' . number_format((float)$compra['total'], 2, ',', '.'),
    'items' => $itemsFormatted
  ], JSON_UNESCAPED_UNICODE);
  
} catch (Throwable $e) {
  json_response(['error' => 'Error al cargar'], 500);
}
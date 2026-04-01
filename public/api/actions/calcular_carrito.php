<?php
declare(strict_types=1);
// public/api/actions/calcular_carrito.php

$pdo = $pdo ?? getPDO();

$itemsRaw = $body['items'] ?? null;
if (is_string($itemsRaw)) {
  $itemsRaw = json_decode($itemsRaw, true);
}

$descGlobalRaw = $body['desc_global'] ?? null;
if (is_string($descGlobalRaw)) {
  $descGlobalRaw = json_decode($descGlobalRaw, true);
}
$descGlobalReq = parse_desc_global($descGlobalRaw);

$canModifyPrice = function_exists('user_has_permission') && user_has_permission('caja_modificar_precio');
if ($descGlobalReq !== null && !$canModifyPrice) {
  json_fail('No tiene permiso para aplicar descuentos', 403);
}

if (!is_array($itemsRaw) || $itemsRaw === []) {
  json_ok([
    'items' => [],
    'total_bruto' => 0,
    'total_neto' => 0,
    'descuento_total' => 0,
    'promos_aplicadas' => [],
  ]);
}

try {
  $promos = obtenerPromosActivas($pdo);
} catch (Throwable $e) {
  $promos = ['simples' => [], 'combos' => []];
}

$srvItems = [];
$productIds = array_filter(array_map(
  static fn($item): int => (int)($item['id'] ?? $item['producto_id'] ?? 0),
  $itemsRaw
));

if ($productIds === []) {
  json_ok([
    'items' => [],
    'total_bruto' => 0,
    'total_neto' => 0,
    'descuento_total' => 0,
    'promos_aplicadas' => [],
  ]);
}

$placeholders = implode(',', array_fill(0, count($productIds), '?'));
$stProds = $pdo->prepare("
  SELECT id, codigo, nombre, precio, stock, es_pesable, unidad_venta
  FROM productos
  WHERE id IN ({$placeholders}) AND activo = 1
");
$stProds->execute(array_values($productIds));
$productsMap = [];
while ($row = $stProds->fetch(PDO::FETCH_ASSOC)) {
  $productsMap[(int)$row['id']] = $row;
}

$itemsMap = [];
foreach ($itemsRaw as $item) {
  $pid = (int)($item['id'] ?? $item['producto_id'] ?? 0);
  $cant = (float)($item['cantidad'] ?? 0);
  $precioManual = isset($item['precio']) ? (float)$item['precio'] : null;

  if ($pid > 0 && $cant > 0) {
    $itemsMap[$pid] = [
      'cantidad' => $cant,
      'precio_manual' => $precioManual,
    ];
  }
}

foreach ($itemsMap as $pid => $itemData) {
  if (!isset($productsMap[$pid])) {
    continue;
  }

  $producto = $productsMap[$pid];
  $precioLista = (float)$producto['precio'];
  $precioActual = $precioLista;

  if ($canModifyPrice && $itemData['precio_manual'] !== null && $itemData['precio_manual'] > 0) {
    $precioActual = $itemData['precio_manual'];
  }

  $srvItems[] = [
    'producto_id' => $pid,
    'codigo' => (string)$producto['codigo'],
    'nombre' => (string)$producto['nombre'],
    'cantidad' => $itemData['cantidad'],
    'precio_lista' => $precioLista,
    'precio_actual' => $precioActual,
    'es_pesable' => (int)$producto['es_pesable'],
    'unidad_venta' => $producto['unidad_venta'] ?: 'UNIDAD',
    'stock' => (float)$producto['stock'],
  ];
}

if ($srvItems === []) {
  json_ok([
    'items' => [],
    'total_bruto' => 0,
    'total_neto' => 0,
    'descuento_total' => 0,
    'promos_aplicadas' => [],
  ]);
}

$calc = calcular_totales_con_promos($srvItems, $promos);
$totalBruto = round((float)($calc['total_bruto'] ?? 0), 2);
$totalNetoSinGlobal = round((float)($calc['total_neto'] ?? 0), 2);
$descPromos = round((float)($calc['descuento_total'] ?? 0), 2);
$descGlobalMonto = calc_desc_global($totalNetoSinGlobal, $descGlobalReq);
$totalNeto = round($totalNetoSinGlobal - $descGlobalMonto, 2);
$descTotal = round($descPromos + $descGlobalMonto, 2);

json_ok([
  'items' => $calc['items'] ?? $srvItems,
  'total_bruto' => $totalBruto,
  'total_neto' => $totalNeto,
  'total_neto_sin_global' => $totalNetoSinGlobal,
  'descuento_total' => $descTotal,
  'descuento_promos' => $descPromos,
  'descuento_global' => round($descGlobalMonto, 2),
  'promos_aplicadas' => $calc['promos_aplicadas'] ?? [],
]);

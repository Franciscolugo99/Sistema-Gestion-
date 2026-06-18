<?php
declare(strict_types=1);

/**
 * Helpers compartidos del dominio de compras.
 */

/**
 * Calcula los importes normalizados de un item de compra.
 *
 * @return array<string,mixed>
 */
function flus_compra_item_metrics(array $item): array {
  $unidad = strtoupper(trim((string)($item['unidad_venta'] ?? 'UNIDAD')));
  $esPesable = (int)($item['es_pesable'] ?? 0) === 1
    || in_array($unidad, ['KG', 'G', 'LT', 'ML'], true);

  $cantidad = (float)($item['cantidad'] ?? 0);
  $costoUnitario = (float)($item['costo_unitario'] ?? 0);
  $subtotal = (float)($item['subtotal'] ?? 0);
  if ($subtotal <= 0 && $cantidad > 0 && $costoUnitario >= 0) {
    $subtotal = $cantidad * $costoUnitario;
  }

  $descuentoTipo = strtoupper(trim((string)($item['descuento_tipo'] ?? 'MONTO')));
  if (!in_array($descuentoTipo, ['MONTO', 'PORC'], true)) {
    $descuentoTipo = 'MONTO';
  }

  $descuentoPorc = max(0.0, (float)($item['descuento_porc'] ?? 0));
  if ($descuentoPorc > 100) {
    $descuentoPorc = 100.0;
  }

  $descuentoValor = max(0.0, (float)($item['descuento'] ?? 0));
  $descuentoMonto = 0.0;

  if ($subtotal > 0) {
    if ($descuentoTipo === 'PORC') {
      $descuentoMonto = round($subtotal * ($descuentoPorc / 100.0), 2);
      $descuentoValor = $descuentoPorc;
    } else {
      if ($descuentoValor > $subtotal) {
        $descuentoValor = $subtotal;
      }
      $descuentoMonto = $descuentoValor;
    }
  }

  if ($descuentoMonto > $subtotal) {
    $descuentoMonto = $subtotal;
  }

  $descuentoMonto = round($descuentoMonto, 2);
  $neto = max(0.0, round($subtotal - $descuentoMonto, 2));

  return [
    'unidad' => $unidad,
    'es_pesable' => $esPesable,
    'cantidad' => $cantidad,
    'costo_unitario' => $costoUnitario,
    'subtotal' => $subtotal,
    'descuento_tipo' => $descuentoTipo,
    'descuento_porc' => $descuentoPorc,
    'descuento_valor' => $descuentoValor,
    'descuento_monto' => $descuentoMonto,
    'neto' => $neto,
  ];
}

/**
 * Resume una lista de items de compra ya persistidos.
 *
 * @param array<int,array<string,mixed>> $items
 * @return array{
 *   items: array<int,array<string,mixed>>,
 *   cantidad_total: float,
 *   bruto_items: float,
 *   descuento_items_total: float
 * }
 */
function flus_compra_items_metrics(array $items): array {
  $out = [];
  $cantidadTotal = 0.0;
  $brutoItems = 0.0;
  $descuentoItemsTotal = 0.0;

  foreach ($items as $item) {
    $metrics = flus_compra_item_metrics((array)$item);
    $out[] = $metrics;
    $cantidadTotal += (float)$metrics['cantidad'];
    $brutoItems += (float)$metrics['subtotal'];
    $descuentoItemsTotal += (float)$metrics['descuento_monto'];
  }

  return [
    'items' => $out,
    'cantidad_total' => round($cantidadTotal, 3),
    'bruto_items' => round($brutoItems, 2),
    'descuento_items_total' => round($descuentoItemsTotal, 2),
  ];
}

/**
 * Normaliza los totales generales de una compra a partir del header y sus items.
 *
 * @param array<string,mixed> $compra
 * @param array{
 *   items: array<int,array<string,mixed>>,
 *   cantidad_total: float,
 *   bruto_items: float,
 *   descuento_items_total: float
 * } $itemsMetrics
 * @return array<string,mixed>
 */
function flus_compra_totals(array $compra, array $itemsMetrics): array {
  $brutoCalculado = round((float)($itemsMetrics['bruto_items'] ?? 0.0), 2);
  $descuentoItemsTotal = round((float)($itemsMetrics['descuento_items_total'] ?? 0.0), 2);

  $totalBruto = round((float)($compra['total_bruto'] ?? 0), 2);
  if ($totalBruto <= 0 && $brutoCalculado > 0) {
    $totalBruto = $brutoCalculado;
  }
  if ($totalBruto <= 0) {
    $totalBruto = round((float)($compra['total'] ?? 0), 2);
  }

  $descuentoGlobal = round((float)($compra['descuento_total'] ?? 0), 2);
  $totalCompra = round((float)($compra['total'] ?? ($totalBruto - $descuentoItemsTotal - $descuentoGlobal)), 2);
  $totalNeto = round((float)($compra['total_neto'] ?? $totalCompra), 2);
  $totalIva = round((float)($compra['total_iva'] ?? 0), 2);

  $descuentoGlobalTipo = strtoupper(trim((string)($compra['descuento_tipo'] ?? 'MONTO')));
  if (!in_array($descuentoGlobalTipo, ['MONTO', 'PORC'], true)) {
    $descuentoGlobalTipo = 'MONTO';
  }

  return [
    'cantidad_total' => (float)($itemsMetrics['cantidad_total'] ?? 0.0),
    'bruto_items' => $brutoCalculado,
    'descuento_items_total' => $descuentoItemsTotal,
    'total_bruto' => $totalBruto,
    'descuento_global' => $descuentoGlobal,
    'descuento_global_tipo' => $descuentoGlobalTipo,
    'descuento_global_valor' => (float)($compra['descuento_valor'] ?? 0),
    'total_compra' => $totalCompra,
    'total_neto' => $totalNeto,
    'total_iva' => $totalIva,
  ];
}

function flus_compras_assert_confirmable_state(string $estado): void {
  if (strtoupper(trim($estado)) !== 'BORRADOR') {
    throw new RuntimeException('Solo se pueden confirmar compras en BORRADOR.');
  }
}

/**
 * @param array<int,array<string,mixed>> $items
 * @return array<int,float>
 */
function flus_compras_group_quantities_by_product(array $items): array {
  $quantities = [];
  foreach ($items as $item) {
    $productoId = (int)($item['producto_id'] ?? 0);
    $cantidad = (float)($item['cantidad'] ?? 0.0);
    if ($productoId <= 0 || $cantidad <= 0) {
      continue;
    }
    $quantities[$productoId] = ($quantities[$productoId] ?? 0.0) + $cantidad;
  }
  return $quantities;
}

/**
 * @param array<int,array<string,mixed>> $items
 * @param array<int,float|int|string> $stockByProduct
 */
function flus_compras_assert_reversible_stock(array $items, array $stockByProduct): void {
  foreach (flus_compras_group_quantities_by_product($items) as $productoId => $cantidad) {
    if (!array_key_exists($productoId, $stockByProduct)) {
      throw new RuntimeException("El producto ID {$productoId} ya no existe para revertir stock.");
    }

    $stockActual = (float)$stockByProduct[$productoId];
    if ($stockActual < $cantidad) {
      throw new RuntimeException(
        "No hay stock suficiente para revertir la compra en el producto ID {$productoId}. "
        . "Stock actual: {$stockActual}, a revertir: {$cantidad}."
      );
    }
  }
}

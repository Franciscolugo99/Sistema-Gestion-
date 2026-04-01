<?php
declare(strict_types=1);
// public/api/actions/buscar_producto.php

$codigo = trim((string)($_GET['codigo'] ?? ''));
if ($codigo === '') {
  json_fail('Codigo vacio', 422);
}

$pdo = $pdo ?? getPDO();

$stmt = $pdo->prepare("
  SELECT id, codigo, nombre, precio, stock, activo, es_pesable, unidad_venta
  FROM productos
  WHERE codigo = :cod AND activo = 1
  LIMIT 1
");
$stmt->execute([':cod' => $codigo]);
$producto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$producto) {
  $stmt = $pdo->prepare("
    SELECT id, codigo, nombre, precio, stock, activo, es_pesable, unidad_venta
    FROM productos
    WHERE nombre = :nom AND activo = 1
    LIMIT 1
  ");
  $stmt->execute([':nom' => $codigo]);
  $producto = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$producto) {
  $like = '%' . $codigo . '%';
  $start = $codigo . '%';

  $sql = "
    SELECT id, codigo, nombre, precio, stock, activo, es_pesable, unidad_venta
    FROM productos
    WHERE activo = 1 AND (codigo LIKE ? OR nombre LIKE ?)
    ORDER BY
      CASE
        WHEN codigo = ? THEN 0
        WHEN nombre = ? THEN 1
        WHEN codigo LIKE ? THEN 2
        WHEN nombre LIKE ? THEN 3
        ELSE 4
      END,
      nombre ASC
    LIMIT 1
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute([$like, $like, $codigo, $codigo, $start, $start]);
  $producto = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$producto) {
  json_fail('Producto no encontrado o inactivo', 404);
}

$producto['precio'] = (float)($producto['precio'] ?? 0);
$producto['stock'] = (float)($producto['stock'] ?? 0);
$producto['es_pesable'] = ((int)($producto['es_pesable'] ?? 0) === 1);
$producto['unidad_venta'] = $producto['unidad_venta'] ?: 'UNIDAD';

json_ok(['producto' => $producto]);

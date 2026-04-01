<?php
declare(strict_types=1);
// public/api/actions/buscar_productos.php

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '' || mb_strlen($q) < 2) {
  json_ok(['productos' => []]);
}

$limit = (int)($_GET['limit'] ?? 10);
$limit = max(1, min($limit, 20));
$like = '%' . $q . '%';
$start = $q . '%';

$pdo = $pdo ?? getPDO();

$sql = "
  SELECT id, codigo, nombre, precio, stock, es_pesable, unidad_venta
  FROM productos
  WHERE activo = 1
    AND (codigo LIKE ? OR nombre LIKE ?)
  ORDER BY
    CASE
      WHEN codigo = ? THEN 0
      WHEN codigo LIKE ? THEN 1
      WHEN nombre LIKE ? THEN 2
      ELSE 3
    END,
    nombre ASC
  LIMIT {$limit}
";

$stmt = $pdo->prepare($sql);
$ok = $stmt->execute([$like, $like, $q, $start, $start]);
if (!$ok) {
  $error = $stmt->errorInfo();
  json_fail('buscar_productos SQL execute fallo: ' . ($error[2] ?? 'sin detalle'), 500);
}

$productos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($productos as &$producto) {
  $producto['precio'] = (float)($producto['precio'] ?? 0);
  $producto['stock'] = (float)($producto['stock'] ?? 0);
  $producto['es_pesable'] = ((int)($producto['es_pesable'] ?? 0) === 1);
  $producto['unidad_venta'] = $producto['unidad_venta'] ?: 'UNIDAD';
}
unset($producto);

json_ok(['productos' => $productos]);

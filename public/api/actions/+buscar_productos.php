<?php
declare(strict_types=1);
// public/api/actions/buscar_productos.php
// Autocompletado de Caja (endpoint rápido)
// - No depende de bootstrap completo
// - Devuelve siempre JSON

require_once __DIR__ . '/../../lib/root.php';
require_once FLUS_ROOT . '/src/config.php';
require_once FLUS_ROOT . '/src/db_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$q = trim((string)($_GET['q'] ?? ''));
$len = function_exists('mb_strlen') ? mb_strlen($q) : strlen($q);
if ($q === '' || $len < 2) {
  echo json_encode(['ok' => true, 'productos' => []], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

$limit = (int)($_GET['limit'] ?? 10);
$limit = max(1, min($limit, 20));

try {
  $pdo = getPDO();

  // Columnas compatibles entre versiones
  $precioCol = has_column($pdo, 'productos', 'precio') ? 'precio'
    : (has_column($pdo, 'productos', 'precio_venta') ? 'precio_venta' : null);

  $stockExpr = has_column($pdo, 'productos', 'stock') ? 'stock' : '0';
  $pesableExpr = has_column($pdo, 'productos', 'es_pesable') ? 'es_pesable' : '0';

  if (has_column($pdo, 'productos', 'unidad_venta')) {
    $unidadExpr = "COALESCE(NULLIF(unidad_venta,''),'UNIDAD')";
  } else {
    $unidadExpr = "'UNIDAD'";
  }

  // Precio: si no existe ninguna columna conocida, devolvemos 0
  $precioExpr = $precioCol ? $precioCol : '0';

  $sql = "SELECT id, codigo, nombre, {$precioExpr} AS precio, {$stockExpr} AS stock, {$pesableExpr} AS es_pesable, {$unidadExpr} AS unidad_venta
          FROM productos
          WHERE activo = 1 AND (codigo LIKE :like OR nombre LIKE :like)
          ORDER BY CASE
            WHEN codigo = :exact THEN 0
            WHEN codigo LIKE :start THEN 1
            WHEN nombre LIKE :start THEN 2
            ELSE 3 END,
            nombre ASC
          LIMIT {$limit}";

  $st = $pdo->prepare($sql);
  $st->execute([
    ':like'  => '%' . $q . '%',
    ':start' => $q . '%',
    ':exact' => $q,
  ]);

  $productos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($productos as &$p) {
    $p['precio'] = (float)($p['precio'] ?? 0);
    $p['stock'] = (float)($p['stock'] ?? 0);
    $p['es_pesable'] = ((int)($p['es_pesable'] ?? 0) === 1);
    $p['unidad_venta'] = ($p['unidad_venta'] ?? '') ?: 'UNIDAD';
  }
  unset($p);

  echo json_encode(['ok' => true, 'productos' => $productos], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => 'SEARCH_FAILED',
    'hint' => 'No se pudo buscar productos',
  ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

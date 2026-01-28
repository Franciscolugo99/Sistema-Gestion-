<?php
declare(strict_types=1);
// public/api/actions/buscar_productos.php
// FIX definitivo autocompletado Caja
// - Búsqueda case-insensitive (funciona incluso si la tabla/campos están en collation *_bin)
// - Busca por: codigo, nombre, marca, categoria, proveedor (si existen)
// - Por defecto filtra activo=1 (si existe). Si no encuentra nada, reintenta SIN filtro activo.
// - Devuelve siempre {ok:true, productos:[...]}. Si debug=1 agrega debug con SQL/errores.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$debug = (($_GET['debug'] ?? '') === '1');

$respond = function(array $payload): void {
  http_response_code(200);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
};

$q = trim((string)($_GET['q'] ?? ''));
$len = function_exists('mb_strlen') ? mb_strlen($q) : strlen($q);
if ($q === '' || $len < 2) {
  $respond(['ok' => true, 'productos' => []]);
}

$limit = (int)($_GET['limit'] ?? 10);
$limit = max(1, min($limit, 20));

try {
  require_once __DIR__ . '/../../lib/root.php';
  require_once FLUS_ROOT . '/src/config.php';
  require_once FLUS_ROOT . '/src/db_helpers.php';

  if (!function_exists('getPDO')) {
    $respond(['ok' => true, 'productos' => [], 'debug' => $debug ? ['error' => 'getPDO_missing'] : null]);
  }

  $pdo = getPDO();

  // Detectar columnas existentes
  $cols = [];
  try {
    $stCols = $pdo->query("SHOW COLUMNS FROM productos");
    $rows = $stCols ? $stCols->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($rows as $r) {
      $f = (string)($r['Field'] ?? '');
      if ($f !== '') $cols[$f] = true;
    }
  } catch (Throwable $e) {
    $respond(['ok' => true, 'productos' => [], 'debug' => $debug ? ['error' => 'show_columns_failed', 'msg' => $e->getMessage()] : null]);
  }

  $has = fn(string $c): bool => isset($cols[$c]);

  $searchCols = [];
  foreach (['codigo','nombre','marca','categoria','proveedor'] as $c) {
    if ($has($c)) $searchCols[] = $c;
  }
  if (!$searchCols) {
    $respond(['ok' => true, 'productos' => [], 'debug' => $debug ? ['error' => 'no_search_cols'] : null]);
  }

  // Normalizar query a minúsculas
  $qLower = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
  $like = '%' . $qLower . '%';

  // Campos de salida (tu esquema los tiene)
  $select = "id, codigo, nombre, precio, stock, es_pesable, unidad_venta";
  if ($has('activo')) $select .= ", activo";

  // WHERE OR con LOWER() para case-insensitive incluso con collation binaria
  $ors = [];
  foreach ($searchCols as $c) {
    // COALESCE por si NULL
    $ors[] = "LOWER(COALESCE(`{$c}`,'')) LIKE :like";
  }
  $orSql = '(' . implode(' OR ', $ors) . ')';

  $order = "ORDER BY CASE
      WHEN `codigo` = :exact THEN 0
      WHEN `codigo` LIKE :start THEN 1
      WHEN `nombre` LIKE :start THEN 2
      ELSE 3 END,
      `nombre` ASC";

  $params = [
    ':like' => $like,
    ':exact' => $q,
    ':start' => $q . '%',
  ];

  $sql1 = "SELECT {$select} FROM productos WHERE {$orSql}";
  if ($has('activo')) $sql1 .= " AND activo = 1";
  $sql1 .= " {$order} LIMIT {$limit}";

  $st = $pdo->prepare($sql1);
  $st->execute($params);
  $productos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Si no hubo resultados y existe activo, reintentar sin filtro activo
  $sql2 = null;
  if (!$productos && $has('activo')) {
    $sql2 = "SELECT {$select} FROM productos WHERE {$orSql} {$order} LIMIT {$limit}";
    $st2 = $pdo->prepare($sql2);
    $st2->execute($params);
    $productos = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  foreach ($productos as &$p) {
    $p['id'] = (int)($p['id'] ?? 0);
    $p['codigo'] = (string)($p['codigo'] ?? '');
    $p['nombre'] = (string)($p['nombre'] ?? '');
    $p['precio'] = (float)($p['precio'] ?? 0);
    $p['stock'] = (float)($p['stock'] ?? 0);
    $p['es_pesable'] = ((int)($p['es_pesable'] ?? 0) === 1);
    $p['unidad_venta'] = ($p['unidad_venta'] ?? '') ?: 'UNIDAD';
    if (isset($p['activo'])) $p['activo'] = ((int)$p['activo'] === 1);
  }
  unset($p);

  $out = ['ok' => true, 'productos' => $productos];
  if ($debug) {
    $out['debug'] = [
      'q' => $q,
      'qLower' => $qLower,
      'searchCols' => $searchCols,
      'sql1' => $sql1,
      'sql2' => $sql2,
      'count' => count($productos),
    ];
  }
  $respond($out);

} catch (Throwable $e) {
  $respond(['ok' => true, 'productos' => [], 'debug' => $debug ? ['error' => 'exception', 'msg' => $e->getMessage()] : null]);
}

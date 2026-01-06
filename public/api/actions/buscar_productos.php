<?php
// public/api/actions/buscar_productos.php
// Endpoint: ?action=buscar_productos&q=...&limit=5
// DiseÃ±ado para ser robusto con distintos nombres de columnas (nombre/descripcion, precio/precio_venta, stock/stock_actual).

if (function_exists('require_login')) {
  // Si tu API ya valida sesiÃ³n arriba, esto no molesta.
  require_login();
}

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') {
  if (function_exists('json_fail')) json_fail('Query vacÃ­a', 422);
  http_response_code(422);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>'Query vacÃ­a'], JSON_UNESCAPED_UNICODE);
  exit;
}

$limit = (int)($_GET['limit'] ?? 5);
$limit = max(1, min($limit, 20));

// Cache estÃ¡tico de columnas (evita SHOW COLUMNS cada request)
static $cols = null;
if ($cols === null) {
  try {
    $cols = $pdo->query("SHOW COLUMNS FROM productos")->fetchAll(PDO::FETCH_COLUMN);
  } catch (Throwable $e) {
    $cols = [];
  }
}

$has = fn(string $c): bool => in_array($c, $cols, true);

// Elegir columnas compatibles
$nameCol  = $has('nombre') ? 'nombre' : ($has('descripcion') ? 'descripcion' : 'nombre');
$codeCol  = $has('codigo') ? 'codigo' : 'codigo';

$priceCol = $has('precio') ? 'precio'
         : ($has('precio_venta') ? 'precio_venta'
         : ($has('precio_unitario') ? 'precio_unitario' : null));

$stockCol = $has('stock') ? 'stock'
        : ($has('stock_actual') ? 'stock_actual'
        : ($has('existencia') ? 'existencia' : null));

$activeCol = $has('activo') ? 'activo'
          : ($has('active') ? 'active' : null);

// Armado de SELECT
$select = "id, {$codeCol} AS codigo, {$nameCol} AS nombre";
if ($priceCol) $select .= ", {$priceCol} AS precio";
if ($stockCol) $select .= ", {$stockCol} AS stock";

// WHERE (activo si existe)
$where = [];
$params = [':q' => '%' . $q . '%'];

$where[] = "{$codeCol} LIKE :q";
$where[] = "{$nameCol} LIKE :q";

if ($has('categoria')) $where[] = "categoria LIKE :q";

$w = '(' . implode(' OR ', $where) . ')';
if ($activeCol) {
  $w = "({$activeCol} = 1) AND " . $w;
}

$sql = "
  SELECT {$select}
  FROM productos
  WHERE {$w}
  ORDER BY nombre
  LIMIT {$limit}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (function_exists('json_ok')) {
  json_ok(['productos' => $rows]);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true,'productos'=>$rows], JSON_UNESCAPED_UNICODE);
exit;
<?php
<<<<<<< Updated upstream
declare(strict_types=1);
=======
// public/api/actions/buscar_productos.php
// Endpoint: ?action=buscar_productos&q=...&limit=5
// DiseÃ±ado para ser robusto con distintos nombres de columnas (nombre/descripcion, precio/precio_venta, stock/stock_actual).
>>>>>>> Stashed changes

// Action: ?action=buscar_productos&q=...&limit=5
// Objetivo: NO romper Caja. Si algo falla, responde ok:true con productos=[]

require_once __DIR__ . '/../../lib/root.php';
require_once FLUS_ROOT . '/src/config.php';

if (!function_exists('json_ok')) {
  header('Content-Type: application/json; charset=utf-8');
  function json_ok(array $data = []): void {
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
  }
  function json_fail(string $error, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') {
  json_ok(['productos' => []]); // no explotar por query vacía
}

$limit = (int)($_GET['limit'] ?? 5);
$limit = max(1, min($limit, 20));
$like  = '%' . $q . '%';

try {
  $pdo = (isset($pdo) && $pdo instanceof PDO) ? $pdo : getPDO();
} catch (Throwable $e) {
  // Si falla DB, no romper caja ni spamear 503
  json_ok(['productos' => []]);
}

/**
 * Intentos de query por si tu esquema usa "descripcion" o "nombre", etc.
 */
$tries = [
  // esquema típico kiosco
  "SELECT id, codigo, descripcion AS nombre, precio AS precio, stock AS stock
   FROM productos
   WHERE activo = 1 AND (codigo LIKE :q OR descripcion LIKE :q OR categoria LIKE :q)
   ORDER BY descripcion
   LIMIT %d",

  // alternativo: nombre
  "SELECT id, codigo, nombre AS nombre, precio AS precio, stock AS stock
   FROM productos
   WHERE activo = 1 AND (codigo LIKE :q OR nombre LIKE :q OR categoria LIKE :q)
   ORDER BY nombre
   LIMIT %d",

  // alternativo: precio_venta / stock_actual
  "SELECT id, codigo, nombre AS nombre, precio_venta AS precio, stock_actual AS stock
   FROM productos
   WHERE activo = 1 AND (codigo LIKE :q OR nombre LIKE :q OR categoria LIKE :q)
   ORDER BY nombre
   LIMIT %d",
];

foreach ($tries as $tpl) {
  try {
    $sql = sprintf($tpl, $limit);
    $st = $pdo->prepare($sql);
    $st->execute([':q' => $like]);
    json_ok(['productos' => $st->fetchAll(PDO::FETCH_ASSOC)]);
  } catch (Throwable $e) {
    // probar siguiente variante
  }
}

// si nada matchea (o columnas no existen), no romper
json_ok(['productos' => []]);
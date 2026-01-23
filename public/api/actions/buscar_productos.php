<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? $_POST['q'] ?? ''));

/** Respuesta segura por defecto (no romper Caja) */
$respond_ok = function(array $productos = []): void {
  http_response_code(200);
  echo json_encode(['ok' => true, 'productos' => $productos], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
};

/** Sin query → 200 y vacío (UX rápida) */
if ($q === '') $respond_ok([]);

/** Intento de bootstrap/DB, pero si falla, devolvemos vacío igual */
$pdo = null;
$boot = __DIR__ . '/../../bootstrap.php';
if (is_file($boot)) { require_once $boot; }

if (!function_exists('getPDO')) {
  $dh = __DIR__ . '/../../../src/db_helpers.php';
  if (is_file($dh)) require_once $dh;
}
if (function_exists('getPDO')) {
  try { $pdo = getPDO(); } catch (Throwable $e) { /* sigue null */ }
}

if (!($pdo instanceof PDO)) {
  $respond_ok([]); // sin DB no rompemos la UI
}

/** Búsqueda simple: por nombre/código, top 20 */
try {
  $sql = "SELECT id, nombre, codigo, precio_venta AS precio
          FROM productos
          WHERE nombre LIKE :q OR codigo = :code
          ORDER BY nombre ASC
          LIMIT 20";
  $st = $pdo->prepare($sql);
  $like = '%'.$q.'%';
  $st->bindValue(':q', $like, PDO::PARAM_STR);
  $st->bindValue(':code', $q, PDO::PARAM_STR);
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $productos = array_map(function(array $r): array {
    return [
      'id'      => (int)($r['id'] ?? 0),
      'codigo'  => (string)($r['codigo'] ?? ''),
      'nombre'  => (string)($r['nombre'] ?? ''),
      'precio'  => (float)($r['precio'] ?? 0),
    ];
  }, $rows);
  $respond_ok($productos);
} catch (Throwable $e) {
  $respond_ok([]); // ante cualquier error: 200 y vacío
}

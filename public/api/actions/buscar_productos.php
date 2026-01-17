<?php
declare(strict_types=1);

// public/api/actions/buscar_productos.php - FLUS (2026) ✅
// Objetivo: NO romper Caja. Ante cualquier falla => ok:true y productos=[]

require_once __DIR__ . '/../../lib/root.php';
require_once FLUS_ROOT . '/src/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ✅ Solo definir si no existe (evita conflicto con api_helpers.php)
if (!function_exists('json_ok')) {
  function json_ok(array $data = []): void {
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
  }
}

/* ========================================================================
   🔒 Rate limit (soft): si se excede NO rompe caja => devuelve productos=[]
======================================================================== */
if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

function checkRateLimitSoft(string $key, int $maxRequests = 30, int $perSeconds = 60): bool {
  $now = time();
  $rk  = 'rl_' . $key;

  if (!isset($_SESSION[$rk]) || !is_array($_SESSION[$rk])) {
    $_SESSION[$rk] = ['count' => 0, 'reset' => $now + $perSeconds];
  }

  if ($now >= (int)($_SESSION[$rk]['reset'] ?? 0)) {
    $_SESSION[$rk] = ['count' => 0, 'reset' => $now + $perSeconds];
  }

  $_SESSION[$rk]['count'] = (int)($_SESSION[$rk]['count'] ?? 0) + 1;
  return $_SESSION[$rk]['count'] <= $maxRequests;
}

if (!checkRateLimitSoft('buscar_productos', 30, 60)) {
  json_ok(['productos' => [], 'rate_limited' => true]);
}

/* ========================================================================
   Validación de entrada
======================================================================== */
$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') {
  json_ok(['productos' => []]);
}

$limit = (int)($_GET['limit'] ?? 5);
$limit = max(1, min($limit, 20));
$like  = '%' . $q . '%';

try {
  $pdo = (isset($pdo) && $pdo instanceof PDO) ? $pdo : getPDO();
} catch (Throwable $e) {
  error_log("[buscar_productos] DB error: " . $e->getMessage());
  json_ok(['productos' => []]);
}

/* ========================================================================
   Schema detection + cache (en storage/cache)
======================================================================== */
$cacheDir = FLUS_ROOT . '/storage/cache';
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);

$SCHEMA_CACHE_FILE = $cacheDir . '/productos_schema_cache.json';
$SCHEMA_LOCK_FILE  = $SCHEMA_CACHE_FILE . '.lock';

function detectProductosSchema(PDO $pdo, string $cacheFile, string $lockFile): array {
  $default = [
    'nombre'        => 'nombre',
    'precio'        => 'precio',
    'stock'         => 'stock',
    'has_categoria' => false,
    'has_activo'    => true,
  ];

  // cache válido 1h
  $readCache = function() use ($cacheFile) {
    if (!is_file($cacheFile)) return null;
    $raw = @file_get_contents($cacheFile);
    if (!$raw) return null;
    $j = @json_decode($raw, true);
    if (!is_array($j) || empty($j['timestamp']) || empty($j['schema'])) return null;
    if (time() - (int)$j['timestamp'] > 3600) return null;
    return $j['schema'];
  };

  $cached = $readCache();
  if (is_array($cached)) return $cached;

  $lock = @fopen($lockFile, 'c');
  if (!$lock) return $default;

  $got = false;
  $start = microtime(true);
  while (!( $got = @flock($lock, LOCK_EX | LOCK_NB) )) {
    if ((microtime(true) - $start) > 2.0) break;
    usleep(80000);
  }

  if (!$got) {
    fclose($lock);
    $cached = $readCache();
    return is_array($cached) ? $cached : $default;
  }

  try {
    // re-check cache con lock
    $cached = $readCache();
    if (is_array($cached)) return $cached;

    // Si la tabla no existe, fallback
    try {
      $stmt = $pdo->query("SHOW COLUMNS FROM productos");
      $cols = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
      return $default;
    }

    if (!$cols) return $default;

    $schema = [
      'nombre'        => in_array('nombre', $cols, true) ? 'nombre' : (in_array('descripcion', $cols, true) ? 'descripcion' : 'nombre'),
      'precio'        => in_array('precio', $cols, true) ? 'precio' : (in_array('precio_venta', $cols, true) ? 'precio_venta' : 'precio'),
      'stock'         => in_array('stock', $cols, true) ? 'stock' : (in_array('stock_actual', $cols, true) ? 'stock_actual' : 'stock'),
      'has_categoria' => in_array('categoria', $cols, true),
      'has_activo'    => in_array('activo', $cols, true),
    ];

    @file_put_contents($cacheFile, json_encode([
      'schema' => $schema,
      'timestamp' => time(),
      'version' => '2026.1'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    return $schema;
  } catch (Throwable $e) {
    error_log("[buscar_productos] schema detect error: " . $e->getMessage());
    return $default;
  } finally {
    @flock($lock, LOCK_UN);
    @fclose($lock);
  }
}

$schema = detectProductosSchema($pdo, $SCHEMA_CACHE_FILE, $SCHEMA_LOCK_FILE);

/* ========================================================================
   Query
======================================================================== */
try {
  $whereActivo = $schema['has_activo'] ? "activo = 1 AND" : "";

  $conds = [
    "codigo LIKE ?",
    "{$schema['nombre']} LIKE ?",
  ];
  $params = [$like, $like];

  if (!empty($schema['has_categoria'])) {
    $conds[] = "categoria LIKE ?";
    $params[] = $like;
  }

  // ⚠️ PDO MySQL (emulación OFF) no permite repetir :param en un mismo statement.
  // Usamos placeholders posicionales + LIMIT inline (int) para evitar SQLSTATE[HY093].
  $sql = "SELECT
            id,
            codigo,
            {$schema['nombre']} AS nombre,
            {$schema['precio']} AS precio,
            {$schema['stock']} AS stock,
            CASE
              WHEN codigo = ? THEN 0
              WHEN codigo LIKE ? THEN 1
              WHEN {$schema['nombre']} LIKE ? THEN 2
              ELSE 3
            END AS relevancia
          FROM productos
          WHERE {$whereActivo} (" . implode(' OR ', $conds) . ")
          ORDER BY relevancia ASC, {$schema['nombre']} ASC
          LIMIT " . (int)$limit;

  // relevancia params (exact + starts)
  $params[] = $q;        // codigo exact
  $params[] = $q . '%';  // codigo starts
  $params[] = $q . '%';  // nombre starts

  $st = $pdo->prepare($sql);
  $st->execute($params);

  $productos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($productos as &$p) unset($p['relevancia']);

  json_ok(['productos' => $productos]);
} catch (Throwable $e) {
  error_log("[buscar_productos] query error: " . $e->getMessage());
  json_ok(['productos' => []]);
}

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
    try {
      echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (Throwable $e) {
      echo '{"ok":true,"productos":[]}';
    }
    exit;
  }
}

// 🔒 Solo vía API router (evita acceso directo a /api/actions/*.php)
if (!defined('FLUS_API_CONTEXT')) {
  http_response_code(404);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode(['ok' => false, 'error' => 'NOT_FOUND'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

// Auth/Permisos: si no está logueado o no tiene permiso => no revelar catálogo
require_once __DIR__ . '/../../auth.php';

if (function_exists('is_logged_in') && !is_logged_in()) {
  json_ok(['productos' => []]);
}

if (function_exists('user_has_permission') && !user_has_permission('realizar_ventas')) {
  json_ok(['productos' => []]);
}


/* ========================================================================
   🔒 Rate limit (soft): si se excede NO rompe caja => devuelve productos=[]
   IMPORTANTE: la sesión PHP bloquea entre pestañas => cerrar ASAP.
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

$rateOk = true;
try {
  $rateOk = checkRateLimitSoft('buscar_productos', 30, 60);
} catch (Throwable $e) {
  $rateOk = true; // fail-open para no romper caja
}

// ✅ Cerrar sesión YA (evita bloqueo entre pestañas/terminales)
if (session_status() === PHP_SESSION_ACTIVE) {
  @session_write_close();
}

if (!$rateOk) {
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

$like = '%' . $q . '%';

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
if (!is_dir($cacheDir)) {
  @mkdir($cacheDir, 0775, true);
}

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

  // TTL normal: 24h | Stale: hasta 6h extra bajo contención
  $TTL_NORMAL = 86400;   // 24h
  $TTL_STALE  = 21600;   // 6h

  $readCache = function(bool $allowStale = false) use ($cacheFile, $TTL_NORMAL, $TTL_STALE) {
    if (!is_file($cacheFile)) return null;
    $raw = @file_get_contents($cacheFile);
    if (!$raw) return null;
    $j = @json_decode($raw, true);
    if (!is_array($j) || empty($j['timestamp']) || empty($j['schema'])) return null;

    $age = time() - (int)$j['timestamp'];
    if ($allowStale) {
      if ($age > ($TTL_NORMAL + $TTL_STALE)) return null; // 30h máx
    } else {
      if ($age > $TTL_NORMAL) return null; // 24h
    }
    return $j['schema'];
  };

  // 1) Cache sin lock
  $cached = $readCache(false);
  if (is_array($cached)) return $cached;

  // 2) Lock
  $lock = @fopen($lockFile, 'c');
  if (!$lock) {
    // si no se puede lockear, usar stale si existe
    $cached = $readCache(true);
    return is_array($cached) ? $cached : $default;
  }

  // Lock máximo 300ms, retry 20ms
  $got = false;
  $start = microtime(true);
  while (!( $got = @flock($lock, LOCK_EX | LOCK_NB) )) {
    if ((microtime(true) - $start) > 0.30) break;
    usleep(20000);
  }

  if (!$got) {
    @fclose($lock);
    $cached = $readCache(true);
    return is_array($cached) ? $cached : $default;
  }

  try {
    // re-check cache con lock (otro proceso pudo escribirlo)
    $cached = $readCache(false);
    if (is_array($cached)) return $cached;

    // Detectar schema desde DB
    try {
      $stmt = $pdo->query("SHOW COLUMNS FROM productos");
      $cols = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
    } catch (Throwable $e) {
      error_log('[buscar_productos] schema detect DB error: ' . $e->getMessage());
      $cached = $readCache(true);
      return is_array($cached) ? $cached : $default;
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
    error_log('[buscar_productos] schema detect error: ' . $e->getMessage());
    $cached = $readCache(true);
    return is_array($cached) ? $cached : $default;
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

  if (!empty($schema['has_categoria'])) {
    $conds[] = "categoria LIKE ?";
  }

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

  // ✅ IMPORTANTE: params en el MISMO ORDEN que los placeholders
  $params = [];

  // CASE params (3 primeros ?)
  $params[] = $q;        // codigo exact
  $params[] = $q . '%';  // codigo starts
  $params[] = $q . '%';  // nombre starts

  // WHERE params
  $params[] = $like;     // codigo LIKE %q%
  $params[] = $like;     // nombre LIKE %q%
  if (!empty($schema['has_categoria'])) {
    $params[] = $like;   // categoria LIKE %q%
  }

  $st = $pdo->prepare($sql);
  $st->execute($params);

  $productos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($productos as &$p) unset($p['relevancia']);

  json_ok(['productos' => $productos]);
} catch (Throwable $e) {
  error_log("[buscar_productos] query error: " . $e->getMessage());
  json_ok(['productos' => []]);
}

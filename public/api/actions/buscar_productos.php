<?php
declare(strict_types=1);

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

/* ============================================================================
   🚀 OPTIMIZACIÓN: DETECCIÓN AUTOMÁTICA DE SCHEMA CON CACHE
   - Detecta una sola vez qué columnas existen
   - Guarda en cache por 1 hora
   - Evita múltiples intentos de queries fallidas
============================================================================ */

$SCHEMA_CACHE_FILE = __DIR__ . '/.productos_schema_cache.json';

function detectProductosSchema(PDO $pdo, string $cacheFile): array {
  // Verificar cache existente
  if (file_exists($cacheFile)) {
    $cache = @json_decode(file_get_contents($cacheFile), true);
    if ($cache && isset($cache['timestamp']) && time() - $cache['timestamp'] < 3600) {
      return $cache['schema'];
    }
  }

  // Detectar estructura de la tabla
  $defaultSchema = [
    'nombre' => 'nombre',
    'precio' => 'precio',
    'stock' => 'stock'
  ];

  try {
    $stmt = $pdo->query("SHOW COLUMNS FROM productos");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (empty($columns)) {
      return $defaultSchema;
    }

    $schema = [
      'nombre' => in_array('nombre', $columns) ? 'nombre' : 'descripcion',
      'precio' => in_array('precio', $columns) ? 'precio' : 'precio_venta',
      'stock' => in_array('stock', $columns) ? 'stock' : 'stock_actual'
    ];

    // Guardar en cache
    $cacheData = [
      'schema' => $schema,
      'timestamp' => time()
    ];
    @file_put_contents($cacheFile, json_encode($cacheData, JSON_PRETTY_PRINT));

    return $schema;
  } catch (Throwable $e) {
    return $defaultSchema;
  }
}

$schema = detectProductosSchema($pdo, $SCHEMA_CACHE_FILE);

/* ============================================================================
   QUERY ÚNICA OPTIMIZADA
   - Una sola consulta usando el schema detectado
   - Búsqueda en código, nombre/descripcion y categoría
   - Ordenado por relevancia (matches en código primero)
============================================================================ */

try {
  $sql = "SELECT 
            id,
            codigo,
            {$schema['nombre']} AS nombre,
            {$schema['precio']} AS precio,
            {$schema['stock']} AS stock,
            CASE 
              WHEN codigo LIKE :qExact THEN 1
              WHEN {$schema['nombre']} LIKE :qStart THEN 2
              ELSE 3
            END AS relevancia
          FROM productos
          WHERE activo = 1 
            AND (
              codigo LIKE :q 
              OR {$schema['nombre']} LIKE :q 
              " . (in_array('categoria', array_keys($schema)) ? "OR categoria LIKE :q" : "") . "
            )
          ORDER BY relevancia ASC, {$schema['nombre']} ASC
          LIMIT :limit";

  $st = $pdo->prepare($sql);
  $st->bindValue(':q', $like, PDO::PARAM_STR);
  $st->bindValue(':qExact', $q, PDO::PARAM_STR);
  $st->bindValue(':qStart', $q . '%', PDO::PARAM_STR);
  $st->bindValue(':limit', $limit, PDO::PARAM_INT);
  $st->execute();
  
  $productos = $st->fetchAll(PDO::FETCH_ASSOC);
  
  // Remover columna de relevancia antes de enviar
  foreach ($productos as &$p) {
    unset($p['relevancia']);
  }
  
  json_ok(['productos' => $productos]);
} catch (Throwable $e) {
  // Último recurso: no romper, devolver vacío
  error_log("Error en buscar_productos.php: " . $e->getMessage());
  json_ok(['productos' => []]);
}
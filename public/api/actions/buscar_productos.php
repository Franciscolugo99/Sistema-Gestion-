<?php
declare(strict_types=1);
// public/api/actions/buscar_productos.php
// VERSIÓN ULTRA-ROBUSTA - Detecta columnas disponibles

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ✅ Unificar comportamiento API + exigir login/permisos (evita exponer catálogo sin sesión)
if (!defined('FLUS_API_CONTEXT')) define('FLUS_API_CONTEXT', true);
require_once __DIR__ . '/../../bootstrap.php';

$fail = function(int $code, string $error, array $extra = []): void {
    http_response_code($code);
    echo json_encode(['ok'=>false,'error'=>$error] + $extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

if (function_exists('require_login_json')) {
    require_login_json();
} else {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $uid = (int)($_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0));
    if ($uid <= 0) $fail(401, 'No autenticado');
}

if (function_exists('user_has_permission') && !user_has_permission('realizar_ventas')) {
    $fail(403, 'No autorizado');
}

$respond = function(array $payload): void {
    http_response_code(200);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '' || strlen($q) < 2) {
    $respond(['ok' => true, 'productos' => []]);
}

$limit = (int)($_GET['limit'] ?? 10);
$limit = max(1, min($limit, 20));

try {
    require_once __DIR__ . '/../../lib/root.php';
    require_once FLUS_ROOT . '/src/config.php';
    require_once FLUS_ROOT . '/src/db_helpers.php';

    if (!function_exists('getPDO')) {
        $respond(['ok' => false, 'error' => 'getPDO_missing', 'productos' => []]);
    }

    $pdo = getPDO();

    // Detectar columnas existentes
    $stCols = $pdo->query("SHOW COLUMNS FROM productos");
    $cols = [];
    while ($row = $stCols->fetch(PDO::FETCH_ASSOC)) {
        $cols[] = $row['Field'];
    }

    // Verificar qué columnas tenemos disponibles
    $hasCol = function($name) use ($cols) {
        return in_array($name, $cols, true);
    };

    // Construir lista de columnas donde buscar
    $searchCols = ['codigo', 'nombre'];
    if ($hasCol('marca')) $searchCols[] = 'marca';
    if ($hasCol('categoria')) $searchCols[] = 'categoria';
    if ($hasCol('proveedor')) $searchCols[] = 'proveedor';

    // Normalizar query a minúsculas
    $qLower = mb_strtolower($q, 'UTF-8');
    $like = '%' . $qLower . '%';
    
    // Construir WHERE con las columnas disponibles
    $whereParts = [];
    foreach ($searchCols as $col) {
        $whereParts[] = "LOWER(COALESCE(`$col`, '')) LIKE ?";
    }
    $whereClause = '(' . implode(' OR ', $whereParts) . ')';

    // Construir SELECT con columnas opcionales
    $selectParts = ['id', 'codigo', 'nombre', 'precio', 'stock'];
    if ($hasCol('es_pesable')) {
        $selectParts[] = 'COALESCE(es_pesable, 0) as es_pesable';
    } else {
        $selectParts[] = '0 as es_pesable';
    }
    if ($hasCol('unidad_venta')) {
        $selectParts[] = "COALESCE(unidad_venta, 'UNIDAD') as unidad_venta";
    } else {
        $selectParts[] = "'UNIDAD' as unidad_venta";
    }
    if ($hasCol('activo')) {
        $selectParts[] = 'COALESCE(activo, 1) as activo';
    } else {
        $selectParts[] = '1 as activo';
    }
    $selectClause = implode(', ', $selectParts);

    // Construir ORDER BY
    $orderClause = "ORDER BY 
        CASE 
            WHEN LOWER(`codigo`) = ? THEN 0
            WHEN LOWER(`codigo`) LIKE ? THEN 1
            WHEN LOWER(`nombre`) LIKE ? THEN 2
            ELSE 3 
        END,
        `nombre` ASC";

    // SQL completo (con filtro activo si la columna existe)
    $sql = "SELECT $selectClause FROM productos WHERE $whereClause";
    if ($hasCol('activo')) {
        $sql .= " AND activo = 1";
    }
    $sql .= " $orderClause LIMIT ?";

    // Preparar parámetros
    $params = [];
    // Primero todos los LIKE (uno por cada columna de búsqueda)
    foreach ($searchCols as $col) {
        $params[] = $like;
    }
    // Luego los del ORDER BY
    $params[] = $qLower;      // codigo exacto
    $params[] = $qLower.'%';  // codigo starts with
    $params[] = $qLower.'%';  // nombre starts with
    $params[] = $limit;       // limit

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $productos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Si no encontró nada y existe columna activo, reintentar sin filtro
    if (empty($productos) && $hasCol('activo')) {
        $sql2 = "SELECT $selectClause FROM productos WHERE $whereClause $orderClause LIMIT ?";
        $st2 = $pdo->prepare($sql2);
        $st2->execute($params); // Mismos parámetros
        $productos = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    // Normalizar tipos de datos
    foreach ($productos as &$p) {
        $p['id'] = (int)($p['id'] ?? 0);
        $p['codigo'] = (string)($p['codigo'] ?? '');
        $p['nombre'] = (string)($p['nombre'] ?? '');
        $p['precio'] = (float)($p['precio'] ?? 0);
        $p['stock'] = (float)($p['stock'] ?? 0);
        $p['es_pesable'] = ((int)($p['es_pesable'] ?? 0) === 1);
        $p['unidad_venta'] = (string)($p['unidad_venta'] ?: 'UNIDAD');
        $p['activo'] = ((int)($p['activo'] ?? 1) === 1);
    }
    unset($p);

    $respond(['ok' => true, 'productos' => $productos]);

} catch (Throwable $e) {
    error_log("Error en buscar_productos: " . $e->getMessage());
    
    $respond([
        'ok' => false,
        'error' => 'exception',
        'mensaje' => $e->getMessage(),
        'productos' => []
    ]);
}
<?php
/**
 * FLUS - API de Gestión de Precios
 * Endpoints para operaciones AJAX del módulo de precios
 *
 * @version 2.0.0
 */
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

// Verificar permiso
if (!user_has_permission('editar_productos')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Sin permisos']);
    exit;
}

// CSRF check
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido']);
    exit;
}

require_once __DIR__ . '/../../src/precio_historial.php';

$pdo = getPDO();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        // ============================================
        // OBTENER PRODUCTOS POR CATEGORÍA
        // ============================================
        case 'get_productos_por_categoria':
            $categorias = obtenerProductosPorCategoria($pdo);
            echo json_encode([
                'success' => true,
                'categorias' => $categorias
            ]);
            break;

        // ============================================
        // BUSCAR PRODUCTOS
        // ============================================
        case 'buscar_productos':
            $query = trim($_POST['q'] ?? '');
            $categoria = trim($_POST['categoria'] ?? '');
            $limit = min(100, max(10, (int)($_POST['limit'] ?? 50)));

            $productos = buscarProductos($pdo, $query, $categoria, $limit);
            echo json_encode([
                'success' => true,
                'productos' => $productos,
                'total' => count($productos)
            ]);
            break;

        // ============================================
        // OBTENER HISTORIAL DE PRODUCTO
        // ============================================
        case 'get_historial_producto':
            $productoId = (int)($_POST['producto_id'] ?? 0);
            $tipo = $_POST['tipo'] ?? null;
            $limit = min(100, max(10, (int)($_POST['limit'] ?? 50)));

            if ($productoId <= 0) {
                throw new InvalidArgumentException('ID de producto inválido');
            }

            $historial = precio_get_historial($productoId, $tipo, $limit);
            echo json_encode([
                'success' => true,
                'historial' => $historial
            ]);
            break;

        // ============================================
        // PREVIEW DE AJUSTE (sin aplicar)
        // ============================================
        case 'preview_ajuste':
            $productoIds = json_decode($_POST['producto_ids'] ?? '[]', true);
            $porcentaje = (float)($_POST['porcentaje'] ?? 0);
            $redondeo = $_POST['redondeo'] ?? 'NINGUNO';
            $tipo = $_POST['tipo'] ?? 'VENTA';

            if (empty($productoIds)) {
                throw new InvalidArgumentException('Seleccioná al menos un producto');
            }

            $preview = generarPreviewAjuste($pdo, $productoIds, $porcentaje, $redondeo, $tipo);
            echo json_encode([
                'success' => true,
                'preview' => $preview
            ]);
            break;

        // ============================================
        // OBTENER ESTADÍSTICAS DE MÁRGENES
        // ============================================
        case 'get_estadisticas_margenes':
            $estadisticas = precio_estadisticas_margenes();
            echo json_encode([
                'success' => true,
                'estadisticas' => $estadisticas
            ]);
            break;

        // ============================================
        // OBTENER PRODUCTOS CON MARGEN BAJO
        // ============================================
        case 'get_margen_bajo':
            $umbral = (float)($_POST['umbral'] ?? 15);
            $limit = min(200, max(10, (int)($_POST['limit'] ?? 100)));

            $productos = precio_productos_margen_bajo($umbral, $limit);
            echo json_encode([
                'success' => true,
                'productos' => $productos,
                'umbral' => $umbral
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Acción no válida']);
    }

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('precios_api error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno del servidor']);
}

// ============================================
// FUNCIONES AUXILIARES
// ============================================

/**
 * Obtener productos agrupados por categoría
 */
function obtenerProductosPorCategoria(PDO $pdo): array {
    // Detectar columna de categoría
    $catCol = detectarColumnaCategoria($pdo);

    $sql = "
        SELECT
            id, codigo, nombre, precio, costo, stock,
            COALESCE(NULLIF(TRIM(`{$catCol}`), ''), 'Sin categoría') as categoria,
            CASE
                WHEN costo > 0 THEN ROUND(((precio - costo) / costo) * 100, 2)
                ELSE NULL
            END as margen_pct
        FROM productos
        WHERE activo = 1
        ORDER BY categoria ASC, nombre ASC
    ";

    $stmt = $pdo->query($sql);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Agrupar por categoría
    $categorias = [];
    foreach ($productos as $p) {
        $cat = $p['categoria'];
        if (!isset($categorias[$cat])) {
            $categorias[$cat] = [
                'nombre' => $cat,
                'productos' => [],
                'count' => 0
            ];
        }
        $categorias[$cat]['productos'][] = [
            'id' => (int)$p['id'],
            'codigo' => $p['codigo'],
            'nombre' => $p['nombre'],
            'precio' => (float)$p['precio'],
            'costo' => (float)$p['costo'],
            'stock' => (float)$p['stock'],
            'margen_pct' => $p['margen_pct'] !== null ? (float)$p['margen_pct'] : null
        ];
        $categorias[$cat]['count']++;
    }

    return array_values($categorias);
}

/**
 * Buscar productos con filtro
 */
function buscarProductos(PDO $pdo, string $query, string $categoria, int $limit): array {
    $catCol = detectarColumnaCategoria($pdo);

    $sql = "
        SELECT
            id, codigo, nombre, precio, costo, stock,
            COALESCE(NULLIF(TRIM(`{$catCol}`), ''), 'Sin categoría') as categoria,
            CASE
                WHEN costo > 0 THEN ROUND(((precio - costo) / costo) * 100, 2)
                ELSE NULL
            END as margen_pct
        FROM productos
        WHERE activo = 1
    ";

    $params = [];

    if ($query !== '') {
        $sql .= " AND (codigo LIKE ? OR nombre LIKE ?)";
        $params[] = "%{$query}%";
        $params[] = "%{$query}%";
    }

    if ($categoria !== '' && $categoria !== 'todas') {
        if ($categoria === 'Sin categoría') {
            $sql .= " AND (TRIM(`{$catCol}`) = '' OR `{$catCol}` IS NULL)";
        } else {
            $sql .= " AND `{$catCol}` = ?";
            $params[] = $categoria;
        }
    }

    $sql .= " ORDER BY nombre ASC LIMIT " . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return array_map(function($p) {
        return [
            'id' => (int)$p['id'],
            'codigo' => $p['codigo'],
            'nombre' => $p['nombre'],
            'precio' => (float)$p['precio'],
            'costo' => (float)$p['costo'],
            'stock' => (float)$p['stock'],
            'categoria' => $p['categoria'],
            'margen_pct' => $p['margen_pct'] !== null ? (float)$p['margen_pct'] : null
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Generar preview de ajuste sin aplicar
 */
function generarPreviewAjuste(PDO $pdo, array $productoIds, float $porcentaje, string $redondeo, string $tipo): array {
    $productoIds = array_filter(array_map('intval', $productoIds));
    if (empty($productoIds)) return [];

    $campo = $tipo === 'COSTO' ? 'costo' : 'precio';
    $placeholders = implode(',', array_fill(0, count($productoIds), '?'));

    $stmt = $pdo->prepare("
        SELECT id, codigo, nombre, {$campo} as precio_actual, costo
        FROM productos
        WHERE id IN ({$placeholders})
    ");
    $stmt->execute($productoIds);

    $preview = [];
    while ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $precioActual = (float)$p['precio_actual'];
        $precioNuevo = $precioActual * (1 + $porcentaje / 100);
        $precioNuevo = precio_aplicar_redondeo($precioNuevo, $redondeo);

        $diferencia = round($precioNuevo - $precioActual, 2);
        $diferenciaPct = $precioActual > 0
            ? round((($precioNuevo - $precioActual) / $precioActual) * 100, 2)
            : 0;

        $preview[] = [
            'id' => (int)$p['id'],
            'codigo' => $p['codigo'],
            'nombre' => $p['nombre'],
            'precio_actual' => $precioActual,
            'precio_nuevo' => $precioNuevo,
            'diferencia' => $diferencia,
            'diferencia_pct' => $diferenciaPct
        ];
    }

    return $preview;
}

/**
 * Detectar qué columna usar para categoría
 */
function detectarColumnaCategoria(PDO $pdo): string {
    $columnas = ['categoria', 'rubro', 'familia'];
    $disponibles = function_exists('flus_table_columns')
        ? array_map('strval', flus_table_columns($pdo, 'productos') ?: [])
        : [];

    foreach ($columnas as $col) {
        if (in_array($col, $disponibles, true)) {
            return $col;
        }
    }

    return 'categoria'; // default
}

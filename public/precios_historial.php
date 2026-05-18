<?php
// public/precios_historial.php - Historial de Precios FLUS
/**
 * FLUS - Gestión de Precios v3.0
 * Historial de cambios, herramientas masivas y análisis de márgenes
 * 
 * Mejoras v3.0:
 * - Filtros de historial visibles con UI intuitiva
 * - Sistema de variables CSS unificado con tema global
 * - Mejor UX en selección de productos
 * - Responsive mejorado
 * - Eliminación de código duplicado
 * 
 * @version 3.0.0
 */
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

if (!user_has_permission('editar_productos')) {
    http_response_code(403);
    echo 'No tenés permisos para acceder a esta sección.';
    exit;
}

require_once __DIR__ . '/../src/precio_historial.php';

$pdo = getPDO();
$error = null;

try {
    precio_ensure_tables($pdo);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

// CSRF
csrf_token();

// ============================================
// CONFIGURACIÓN DE PÁGINA
// ============================================
$pageTitle = 'Gestión de Precios - FLUS';
$currentSection = 'precios_historial';
$bodyClass = 'precios-page';
$extraCss = ['assets/css/precios.css'];
$extraJs = ['assets/js/precios.js'];

$info = null;

// Vista actual
$vista = $_GET['v'] ?? 'historial';
$vistaValida = in_array($vista, ['historial', 'herramientas', 'margenes']);
if (!$vistaValida) {
    $vista = 'historial';
}

// ============================================
// PROCESAR ACCIONES POST
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    
    if (!csrf_verify(is_string($token) ? $token : null)) {
        $error = 'Token CSRF inválido. Recargá la página.';
    } else {
        $accion = $_POST['accion'] ?? '';
        
        // Ajuste masivo por porcentaje
        if ($accion === 'ajuste_masivo') {
            $porcentaje = (float)($_POST['porcentaje'] ?? 0);
            $tipo = in_array($_POST['tipo_precio'] ?? '', ['VENTA', 'COSTO']) ? $_POST['tipo_precio'] : 'VENTA';
            $redondeo = $_POST['redondeo'] ?? 'NINGUNO';
            $motivo = trim($_POST['motivo'] ?? '') ?: "Ajuste masivo {$porcentaje}%";
            $productoIds = array_filter(array_map('intval', explode(',', $_POST['producto_ids'] ?? '')));
            
            if ($porcentaje == 0) {
                $error = 'El porcentaje no puede ser 0.';
            } elseif (empty($productoIds)) {
                $error = 'Seleccioná al menos un producto.';
            } else {
                $result = precio_ajuste_masivo_porcentaje($productoIds, $porcentaje, $tipo, $redondeo, $motivo);
                if ($result['actualizados'] > 0) {
                    $info = "Ajuste aplicado: {$result['actualizados']} producto(s) actualizado(s).";
                    if (!empty($result['errores'])) {
                        $info .= ' Con algunos errores: ' . implode(', ', array_slice($result['errores'], 0, 3));
                    }
                } else {
                    $error = 'No se actualizó ningún producto.' . 
                        (!empty($result['errores']) ? ' Errores: ' . implode(', ', $result['errores']) : '');
                }
            }
        }
        
        // Aplicar margen sobre costo
        elseif ($accion === 'aplicar_margen') {
            $margen = (float)($_POST['margen'] ?? 0);
            $redondeo = $_POST['redondeo'] ?? 'NINGUNO';
            $motivo = trim($_POST['motivo'] ?? '') ?: "Margen sobre costo {$margen}%";
            $productoIds = array_filter(array_map('intval', explode(',', $_POST['producto_ids'] ?? '')));
            
            if ($margen <= 0) {
                $error = 'El margen debe ser mayor a 0.';
            } elseif (empty($productoIds)) {
                $error = 'Seleccioná al menos un producto.';
            } else {
                $result = precio_aplicar_margen($productoIds, $margen, $redondeo, $motivo);
                $actualizados = $result['actualizados'] ?? 0;
                if ($actualizados > 0) {
                    $info = "Margen aplicado: {$actualizados} producto(s) actualizado(s).";
                } else {
                    $error = 'No se actualizó ningún producto.' .
                        (!empty($result['errores']) ? ' Errores: ' . implode(', ', $result['errores']) : '');
                }
            }
        }
    }
}

// ============================================
// CARGAR DATOS SEGÚN VISTA
// ============================================

// Detectar columna de categoria
$catCol = flus_first_existing_column($pdo, 'productos', ['categoria', 'rubro', 'familia']) ?? 'categoria';



// Expresión segura para categoría (alias estable para filtros/agrupación)
$catExpr = "COALESCE(NULLIF(TRIM(p.`{$catCol}`), ''), 'Sin categoría')";

// ============================================
// ENDPOINTS AJAX (Herramientas)
// - Evita renderizar 3000+ productos en DOM
// - Carga por categoría + búsqueda server-side
// ============================================
if ($vista === 'herramientas' && isset($_GET['ajax_categoria'])) {
    header('Content-Type: application/json; charset=utf-8');

    $cat = (string)($_GET['cat'] ?? '');
    $q   = trim((string)($_GET['q'] ?? ''));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit  = (int)($_GET['limit'] ?? 50);
    if ($limit < 10) $limit = 10;
    if ($limit > 200) $limit = 200;

    if ($cat === '') {
        echo json_encode(['success' => false, 'error' => 'Categoría inválida'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $where = "WHERE p.activo = 1 AND {$catExpr} = ?";
        $params = [$cat];

        if ($q !== '') {
            $like = '%' . $q . '%';
            $where .= " AND (p.codigo LIKE ? OR p.nombre LIKE ?)";
            $params[] = $like;
            $params[] = $like;
        }

        // Pedimos LIMIT+1 para saber si hay más
        $sql = "
            SELECT
                p.id, p.codigo, p.nombre, p.precio, p.costo,
                CASE
                    WHEN p.costo > 0 THEN ROUND(((p.precio - p.costo) / p.costo) * 100, 2)
                    ELSE NULL
                END as margen_pct
            FROM productos p
            {$where}
            ORDER BY p.nombre ASC
            LIMIT " . (int)($limit + 1) . " OFFSET " . (int)$offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        // Render HTML de filas
        ob_start();
        foreach ($rows as $p) {
            $margenRaw = $p['margen_pct'] ?? null;
            $margen = ($margenRaw === null || $margenRaw === '') ? null : (float)$margenRaw;
            $margenClass = $margen === null ? '' : ($margen < 0 ? 'negativo' : ($margen < 15 ? 'bajo' : 'ok'));
            ?>
            <label class="producto-row"
                   data-cat="<?= htmlspecialchars($cat) ?>"
                   data-codigo="<?= htmlspecialchars((string)($p['codigo'] ?? '')) ?>"
                   data-nombre="<?= htmlspecialchars((string)($p['nombre'] ?? '')) ?>"
                   data-precio="<?= htmlspecialchars((string)($p['precio'] ?? '0')) ?>"
                   data-costo="<?= htmlspecialchars((string)($p['costo'] ?? '0')) ?>">
                <input type="checkbox" value="<?= (int)$p['id'] ?>">
                <div class="producto-info">
                    <div class="producto-codigo"><?= htmlspecialchars((string)($p['codigo'] ?? '')) ?></div>
                    <div class="producto-nombre"><?= htmlspecialchars((string)($p['nombre'] ?? '')) ?></div>
                </div>
                <div class="producto-precios">
                    <div class="producto-precio">$<?= number_format((float)($p['precio'] ?? 0), 2, ',', '.') ?></div>
                    <?php if ((float)($p['costo'] ?? 0) > 0): ?>
                        <div class="producto-costo">Costo: $<?= number_format((float)($p['costo'] ?? 0), 2, ',', '.') ?></div>
                        <?php if ($margen !== null): ?>
                            <div class="producto-margen <?= $margenClass ?>"><?= number_format((float)$margen, 1, ',', '.') ?>%</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </label>
            <?php
        }
        $html = (string)ob_get_clean();

        echo json_encode([
            'success' => true,
            'html' => $html,
            'offset' => $offset,
            'limit' => $limit,
            'next_offset' => $offset + count($rows),
            'has_more' => $hasMore,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al cargar productos'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($vista === 'herramientas' && isset($_GET['ajax_categoria_ids'])) {
    header('Content-Type: application/json; charset=utf-8');

    $cat = (string)($_GET['cat'] ?? '');
    $q   = trim((string)($_GET['q'] ?? ''));

    if ($cat === '') {
        echo json_encode(['success' => false, 'error' => 'Categoría inválida'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $where = "WHERE p.activo = 1 AND {$catExpr} = ?";
        $params = [$cat];

        if ($q !== '') {
            $like = '%' . $q . '%';
            $where .= " AND (p.codigo LIKE ? OR p.nombre LIKE ?)";
            $params[] = $like;
            $params[] = $like;
        }

        $stmt = $pdo->prepare("\n            SELECT p.id, p.codigo, p.nombre, p.precio, p.costo\n            FROM productos p\n            {$where}\n            ORDER BY p.nombre ASC\n        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        echo json_encode([
            'success' => true,
            'categoria' => $cat,
            'count' => count($rows),
            'products' => array_map(function($r) use ($cat) {
                return [
                    'id' => (int)($r['id'] ?? 0),
                    'categoria' => $cat,
                    'codigo' => (string)($r['codigo'] ?? ''),
                    'nombre' => (string)($r['nombre'] ?? ''),
                    'precio' => (float)($r['precio'] ?? 0),
                    'costo' => (float)($r['costo'] ?? 0),
                ];
            }, $rows)
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al cargar IDs'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($vista === 'herramientas' && isset($_GET['ajax_search'])) {
    header('Content-Type: application/json; charset=utf-8');

    $q = trim((string)($_GET['q'] ?? ''));
    $qMin = 2;
    $limit = 400;

    if (mb_strlen($q) < $qMin) {
        echo json_encode(['success' => true, 'html' => ''], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $like = '%' . $q . '%';

        $stmt = $pdo->prepare("\n            SELECT\n                p.id, p.codigo, p.nombre, p.precio, p.costo,\n                {$catExpr} as categoria,\n                CASE\n                    WHEN p.costo > 0 THEN ROUND(((p.precio - p.costo) / p.costo) * 100, 2)\n                    ELSE NULL\n                END as margen_pct\n            FROM productos p\n            WHERE p.activo = 1\n              AND (p.codigo LIKE ? OR p.nombre LIKE ?)\n            ORDER BY categoria ASC, p.nombre ASC\n            LIMIT {$limit}\n        ");
        $stmt->execute([$like, $like]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $byCat = [];
        foreach ($rows as $p) {
            $cat = (string)($p['categoria'] ?? 'Sin categoría');
            if (!isset($byCat[$cat])) $byCat[$cat] = [];
            $byCat[$cat][] = $p;
        }

        ob_start();
        if (empty($rows)) {
            ?>
            <div class="categorias-empty">
                No se encontraron productos para <strong><?= htmlspecialchars($q) ?></strong>.
            </div>
            <?php
        } else {
            // Aviso si el límite cortó resultados (mejorable con COUNT, pero evita queries extra)
            if (count($rows) >= $limit) {
                ?>
                <div class="categorias-hint">
                    Mostrando los primeros <?= (int)$limit ?> resultados. Refiná la búsqueda para ver menos.
                </div>
                <?php
            }

            foreach ($byCat as $catName => $prods) {
                $count = count($prods);
                ?>
                <div class="categoria-item expanded" data-cat="<?= htmlspecialchars($catName) ?>" data-count="<?= (int)$count ?>" data-search="1">
                    <div class="categoria-header">
                        <span class="categoria-toggle">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="9 18 15 12 9 6"/>
                            </svg>
                        </span>
                        <input type="checkbox" class="categoria-checkbox" title="Seleccionar productos visibles de la categoría">
                        <div class="categoria-info">
                            <span class="categoria-nombre"><?= htmlspecialchars($catName) ?></span>
                        </div>
                        <span class="categoria-count"><?= (int)$count ?></span>
                    </div>
                    <div class="categoria-productos" data-loaded="1" data-offset="<?= (int)$count ?>">
                        <?php foreach ($prods as $p):
                            $margenRaw = $p['margen_pct'] ?? null;
                            $margen = ($margenRaw === null || $margenRaw === '') ? null : (float)$margenRaw;
                            $margenClass = $margen === null ? '' : ($margen < 0 ? 'negativo' : ($margen < 15 ? 'bajo' : 'ok'));
                        ?>
                            <label class="producto-row"
                                   data-cat="<?= htmlspecialchars($catName) ?>"
                                   data-codigo="<?= htmlspecialchars((string)($p['codigo'] ?? '')) ?>"
                                   data-nombre="<?= htmlspecialchars((string)($p['nombre'] ?? '')) ?>"
                                   data-precio="<?= htmlspecialchars((string)($p['precio'] ?? '0')) ?>"
                                   data-costo="<?= htmlspecialchars((string)($p['costo'] ?? '0')) ?>">
                                <input type="checkbox" value="<?= (int)$p['id'] ?>">
                                <div class="producto-info">
                                    <div class="producto-codigo"><?= htmlspecialchars((string)($p['codigo'] ?? '')) ?></div>
                                    <div class="producto-nombre"><?= htmlspecialchars((string)($p['nombre'] ?? '')) ?></div>
                                </div>
                                <div class="producto-precios">
                                    <div class="producto-precio">$<?= number_format((float)($p['precio'] ?? 0), 2, ',', '.') ?></div>
                                    <?php if ((float)($p['costo'] ?? 0) > 0): ?>
                                        <div class="producto-costo">Costo: $<?= number_format((float)($p['costo'] ?? 0), 2, ',', '.') ?></div>
                                        <?php if ($margen !== null): ?>
                                            <div class="producto-margen <?= $margenClass ?>"><?= number_format((float)$margen, 1, ',', '.') ?>%</div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php
            }
        }
        $html = (string)ob_get_clean();

        echo json_encode(['success' => true, 'html' => $html], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Error al buscar'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
// VISTA: HISTORIAL
$historial = [];
$productoFiltro = null;
$productoId = 0;
$historialPage = 1;
$historialPerPage = 25;
$historialPerPageOptions = [10, 25, 50, 100];
$historialTipo = '';
$historialDesde = '';
$historialHasta = '';
$historialTotalRows = 0;
$historialTotalPages = 1;
$historialOffset = 0;
$historialFromRow = 0;
$historialToRow = 0;
$historialQueryParams = ['v' => 'historial'];
$historialClearUrl = '?v=historial';
if ($vista === 'historial') {
    $productoId = (int)($_GET['pid'] ?? 0);
    $historialPage = max(1, (int)($_GET['page'] ?? 1));
    $historialPerPage = (int)($_GET['per_page'] ?? 25);
    if (!in_array($historialPerPage, $historialPerPageOptions, true)) {
        $historialPerPage = 25;
    }

    $historialTipo = strtoupper(trim((string)($_GET['tipo'] ?? '')));
    if (!in_array($historialTipo, ['', 'VENTA', 'COSTO'], true)) {
        $historialTipo = '';
    }

    $historialDesde = trim((string)($_GET['desde'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $historialDesde)) {
        $historialDesde = '';
    }

    $historialHasta = trim((string)($_GET['hasta'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $historialHasta)) {
        $historialHasta = '';
    }

    if ($historialDesde !== '' && $historialHasta !== '' && $historialDesde > $historialHasta) {
        [$historialDesde, $historialHasta] = [$historialHasta, $historialDesde];
    }

    if ($productoId > 0) {
        $stmtProd = $pdo->prepare("SELECT codigo, nombre FROM productos WHERE id = ?");
        $stmtProd->execute([$productoId]);
        $productoFiltro = $stmtProd->fetch(PDO::FETCH_ASSOC) ?: null;
        $historialClearUrl = '?v=historial&pid=' . $productoId;
    }

    $whereParts = [];
    $binds = [];

    if ($productoId > 0) {
        $whereParts[] = 'h.producto_id = :producto_id';
        $binds[':producto_id'] = $productoId;
    }

    if ($historialTipo !== '') {
        $whereParts[] = 'h.tipo = :tipo';
        $binds[':tipo'] = $historialTipo;
    }

    if ($historialDesde !== '') {
        $whereParts[] = 'DATE(h.created_at) >= :desde';
        $binds[':desde'] = $historialDesde;
    }

    if ($historialHasta !== '') {
        $whereParts[] = 'DATE(h.created_at) <= :hasta';
        $binds[':hasta'] = $historialHasta;
    }

    $whereSql = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

    $stmtCount = $pdo->prepare("
        SELECT COUNT(*)
        FROM producto_precios_hist h
        {$whereSql}
    ");
    foreach ($binds as $key => $value) {
        $stmtCount->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmtCount->execute();
    $historialTotalRows = (int)$stmtCount->fetchColumn();

    $historialTotalPages = max(1, (int)ceil(max(1, $historialTotalRows) / $historialPerPage));
    if ($historialPage > $historialTotalPages) {
        $historialPage = $historialTotalPages;
    }
    $historialOffset = ($historialPage - 1) * $historialPerPage;

    $stmt = $pdo->prepare("
        SELECT
            h.*,
            p.codigo,
            p.nombre AS producto_nombre,
            u.nombre AS usuario_nombre
        FROM producto_precios_hist h
        LEFT JOIN productos p ON h.producto_id = p.id
        LEFT JOIN users u ON h.user_id = u.id
        {$whereSql}
        ORDER BY h.created_at DESC
        LIMIT :lim OFFSET :off
    ");
    foreach ($binds as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue(':lim', $historialPerPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $historialOffset, PDO::PARAM_INT);
    $stmt->execute();
    $historial = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($historialTotalRows > 0) {
        $historialFromRow = $historialOffset + 1;
        $historialToRow = min($historialOffset + $historialPerPage, $historialTotalRows);
    }

    $historialQueryParams = [
        'v' => 'historial',
        'pid' => $productoId > 0 ? $productoId : null,
        'tipo' => $historialTipo !== '' ? $historialTipo : null,
        'desde' => $historialDesde !== '' ? $historialDesde : null,
        'hasta' => $historialHasta !== '' ? $historialHasta : null,
        'per_page' => $historialPerPage,
    ];
}
// VISTA: HERRAMIENTAS
$categorias = [];
$preselectedProductos = [];
if ($vista === 'herramientas') {
    try {
        $stmt = $pdo->query("
            SELECT
                {$catExpr} as categoria,
                COUNT(*) as count
            FROM productos p
            WHERE p.activo = 1
            GROUP BY {$catExpr}
            ORDER BY categoria ASC
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $categorias[] = [
                'nombre' => (string)($r['categoria'] ?? 'Sin categoría'),
                'count' => (int)($r['count'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
        $categorias = [];
    }

    $preselectedIdsRaw = trim((string)($_GET['ids'] ?? ''));
    if ($preselectedIdsRaw !== '') {
        $preselectedIds = array_values(array_unique(array_filter(
            array_map('intval', explode(',', $preselectedIdsRaw)),
            static fn(int $id): bool => $id > 0
        )));
        $preselectedIds = array_slice($preselectedIds, 0, 200);

        if ($preselectedIds !== []) {
            try {
                $placeholders = implode(',', array_fill(0, count($preselectedIds), '?'));
                $stmt = $pdo->prepare("
                    SELECT
                        p.id,
                        {$catExpr} as categoria,
                        p.codigo,
                        p.nombre,
                        p.precio,
                        p.costo,
                        CASE
                            WHEN p.costo > 0 THEN ROUND(((p.precio - p.costo) / p.costo) * 100, 2)
                            ELSE NULL
                        END as margen_pct
                    FROM productos p
                    WHERE p.activo = 1
                      AND p.id IN ({$placeholders})
                    ORDER BY p.nombre ASC
                ");
                $stmt->execute($preselectedIds);
                $preselectedProductos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $preselectedProductos = [];
            }
        }
    }
}

// VISTA: MÁRGENES
$estadisticas = null;
$margenBajo = [];
if ($vista === 'margenes') {
    $estadisticas = precio_estadisticas_margenes();
    $umbral = (float)($_GET['umbral'] ?? 15);
    $margenBajo = precio_productos_margen_bajo($umbral, 100);
}

// ============================================
// RENDER
// ============================================
require __DIR__ . '/partials/header.php';
?>

<div class="panel precios-module">
    <header class="panel-head page-header module-header">
        <div class="page-header-main module-header-main">
            <div class="module-header-hero">
                <span class="module-header-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                        <path d="M12 2v20"/>
                        <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                    </svg>
                </span>
                <div class="module-header-copy">
                    <span class="module-eyebrow">Rentabilidad comercial</span>
                    <h1 class="page-title module-title">Gestión de Precios</h1>
                    <p class="page-sub panel-subtitle module-subtitle">Historial de cambios, ajustes masivos y análisis de márgenes</p>
                </div>
            </div>
        </div>
    </header>

    <?php if ($info): ?>
        <div class="alert alert-ok">
            <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
            <span><?= htmlspecialchars($info) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-err">
            <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- TABS -->
    <nav class="precio-tabs" role="tablist">
        <a href="?v=historial" class="precio-tab <?= $vista === 'historial' ? 'active' : '' ?>" role="tab" aria-selected="<?= $vista === 'historial' ? 'true' : 'false' ?>">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12 6 12 12 16 14"/>
            </svg>
            Historial
        </a>
        <a href="?v=herramientas" class="precio-tab <?= $vista === 'herramientas' ? 'active' : '' ?>" role="tab" aria-selected="<?= $vista === 'herramientas' ? 'true' : 'false' ?>">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
            </svg>
            Herramientas
        </a>
        <a href="?v=margenes" class="precio-tab <?= $vista === 'margenes' ? 'active' : '' ?>" role="tab" aria-selected="<?= $vista === 'margenes' ? 'true' : 'false' ?>">
            <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="1" x2="12" y2="23"/>
                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
            </svg>
            Márgenes
        </a>
    </nav>

    <!-- ============================================
         VISTA: HISTORIAL
    ============================================ -->
    <?php if ($vista === 'historial'): ?>

        <?php if ($productoFiltro): ?>
            <div class="alert alert-info historial-context">
                <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="16" x2="12" y2="12"/>
                    <line x1="12" y1="8" x2="12.01" y2="8"/>
                </svg>
                <span>Mostrando historial de: <strong><?= htmlspecialchars($productoFiltro['codigo'] . ' - ' . $productoFiltro['nombre']) ?></strong></span>
                <a href="?v=historial" style="margin-left: auto; color: inherit; text-decoration: underline;">Ver todos</a>
            </div>
        <?php endif; ?>

        <form class="historial-filters" id="historialFiltersForm" method="get">
            <input type="hidden" name="v" value="historial">
            <?php if ($productoId > 0): ?>
                <input type="hidden" name="pid" value="<?= (int)$productoId ?>">
            <?php endif; ?>

            <div class="filter-group">
                <label for="filtroTipo">Tipo</label>
                <select id="filtroTipo" name="tipo" data-autosubmit="1">
                    <option value="" <?= $historialTipo === '' ? 'selected' : '' ?>>Todos</option>
                    <option value="VENTA" <?= $historialTipo === 'VENTA' ? 'selected' : '' ?>>Precio venta</option>
                    <option value="COSTO" <?= $historialTipo === 'COSTO' ? 'selected' : '' ?>>Costo</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="filtroFechaDesde">Desde</label>
                <input type="date" id="filtroFechaDesde" name="desde" value="<?= htmlspecialchars($historialDesde) ?>" data-autosubmit="1">
            </div>

            <div class="filter-group">
                <label for="filtroFechaHasta">Hasta</label>
                <input type="date" id="filtroFechaHasta" name="hasta" value="<?= htmlspecialchars($historialHasta) ?>" data-autosubmit="1">
            </div>

            <div class="filter-group filter-group--compact">
                <label for="historialPerPage">Mostrar</label>
                <select id="historialPerPage" name="per_page" data-autosubmit="1">
                    <?php foreach ($historialPerPageOptions as $pageSize): ?>
                        <option value="<?= (int)$pageSize ?>" <?= $historialPerPage === $pageSize ? 'selected' : '' ?>><?= (int)$pageSize ?> por página</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="historial-filters-actions">
                <button type="submit" class="btn btn-primary btn-sm">Aplicar</button>
                <a href="<?= htmlspecialchars($historialClearUrl) ?>" class="filter-clear" id="clearFiltersBtn">Limpiar</a>
            </div>
        </form>

        <?php if ($historialTotalRows > 0): ?>
            <div class="historial-summary">
                <div class="historial-summary__headline">
                    <strong><?= $historialTotalRows ?></strong> cambio<?= $historialTotalRows === 1 ? '' : 's' ?>
                </div>
                <div class="historial-summary__meta">
                    <?= $historialFromRow ?>-<?= $historialToRow ?> de <?= $historialTotalRows ?> | página <?= $historialPage ?> de <?= $historialTotalPages ?> | <?= $historialPerPage ?> por página
                </div>
            </div>

            <?= render_pagination($historialPage, $historialTotalPages, $historialQueryParams, false) ?>
        <?php endif; ?>

        <div class="historial-list">
            <?php if (empty($historial)): ?>
                <div class="historial-empty">
                    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <p>No hay cambios de precios registrados.</p>
                    <p style="font-size: 0.8125rem;">Los cambios aparecerán acá cuando modifiques precios desde las herramientas o el módulo de productos.</p>
                </div>
            <?php else: ?>
                <?php foreach ($historial as $h):
                    $diferencia = (float)($h['diferencia'] ?? 0);
                    $isUp = $diferencia > 0;
                    $fecha = isset($h['created_at']) ? date('d/m/Y H:i', strtotime($h['created_at'])) : '';
                    $fechaISO = $h['created_at'] ?? '';
                ?>
                <div class="hist-item" data-tipo="<?= htmlspecialchars($h['tipo'] ?? 'VENTA') ?>" data-fecha="<?= htmlspecialchars(substr($fechaISO, 0, 10)) ?>">
                    <div class="hist-icon <?= $isUp ? 'up' : 'down' ?>">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <?php if ($isUp): ?>
                                <polyline points="18 15 12 9 6 15"/>
                            <?php else: ?>
                                <polyline points="6 9 12 15 18 9"/>
                            <?php endif; ?>
                        </svg>
                    </div>
                    <div class="hist-content">
                        <div class="hist-product">
                            <?= htmlspecialchars($h['producto_nombre'] ?? $h['nombre'] ?? 'Producto') ?>
                            <span class="codigo">(<?= htmlspecialchars($h['codigo'] ?? '') ?>)</span>
                        </div>
                        <div class="hist-change">
                            <span class="old-price">$<?= number_format((float)($h['precio_anterior'] ?? 0), 2, ',', '.') ?></span>
                            <span>&rarr;</span>
                            <span class="new-price">$<?= number_format((float)($h['precio_nuevo'] ?? 0), 2, ',', '.') ?></span>
                            <span class="diff <?= $isUp ? 'up' : 'down' ?>">
                                <?= $isUp ? '+' : '' ?><?= number_format($diferencia, 2, ',', '.') ?>
                                <?php if (!empty($h['diferencia_pct'])): ?>
                                    (<?= $isUp ? '+' : '' ?><?= number_format((float)$h['diferencia_pct'], 1) ?>%)
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="hist-meta">
                            <span class="hist-badge <?= strtolower($h['tipo'] ?? 'venta') ?>"><?= htmlspecialchars($h['tipo'] ?? 'VENTA') ?></span>
                            <span><?= $fecha ?></span>
                            <?php if (!empty($h['usuario_nombre'] ?? $h['user_nombre'])): ?>
                                <span>por <?= htmlspecialchars($h['usuario_nombre'] ?? $h['user_nombre']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($h['motivo'])): ?>
                                <span class="motivo">"<?= htmlspecialchars($h['motivo']) ?>"</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($historialTotalRows > 0): ?>
            <?= render_pagination($historialPage, $historialTotalPages, $historialQueryParams, false) ?>
        <?php endif; ?>
    <!-- ============================================
         VISTA: HERRAMIENTAS
    ============================================ -->
    <?php elseif ($vista === 'herramientas'): ?>

        <?php if ($preselectedProductos !== []): ?>
            <div class="alert alert-info">
                <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="16" x2="12" y2="12"/>
                    <line x1="12" y1="8" x2="12.01" y2="8"/>
                </svg>
                <span><?= count($preselectedProductos) ?> producto(s) de la compra cargados para revisar precio de venta.</span>
            </div>
            <script>
            window.FLUS_PRESELECT_PRODUCTOS = <?= json_encode($preselectedProductos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            window.FLUS_PRESELECT_MARGEN = 30;
            </script>
        <?php endif; ?>
        
        <div class="precios-layout">
            <!-- Panel izquierdo: Categorías y productos -->
            <div class="categorias-panel">
                <div class="categorias-header">
                    <h3>
                        <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
                        </svg>
                        Productos por Categoría
                    </h3>
                    <div class="btn-group">
                        <button type="button" id="expandAllBtn" class="btn btn-ghost btn-sm" title="Expandir todo (Ctrl+Shift+E)">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="7 13 12 18 17 13"/>
                                <polyline points="7 6 12 11 17 6"/>
                            </svg>
                        </button>
                        <button type="button" id="collapseAllBtn" class="btn btn-ghost btn-sm" title="Colapsar todo (Ctrl+Shift+C)">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="17 11 12 6 7 11"/>
                                <polyline points="17 18 12 13 7 18"/>
                            </svg>
                        </button>
                    </div>
                </div>
                
                <div class="categorias-search">
                    <input type="text" id="searchProductos" placeholder="Buscar por código o nombre..." autocomplete="off">
                </div>
                
                <div class="categorias-list">
                    <?php if (empty($categorias)): ?>
                        <div style="padding: 2rem; text-align: center; color: var(--pm-muted);">
                            No hay productos activos.
                        </div>
                    <?php else: ?>
                        <?php foreach ($categorias as $cat): ?>
                        <div class="categoria-item" data-cat="<?= htmlspecialchars($cat['nombre']) ?>" data-count="<?= (int)$cat['count'] ?>" data-search="0">
                            <div class="categoria-header">
                                <span class="categoria-toggle">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="9 18 15 12 9 6"/>
                                    </svg>
                                </span>
                                <input type="checkbox" class="categoria-checkbox" title="Seleccionar toda la categoría">
                                <div class="categoria-info">
                                    <span class="categoria-nombre"><?= htmlspecialchars($cat['nombre']) ?></span>
                                </div>
                                <span class="categoria-count"><?= (int)$cat['count'] ?></span>
                            </div>
                            <div class="categoria-productos" data-loaded="0" data-offset="0">
                                <div class="categoria-placeholder">Expandí la categoría para cargar productos</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Panel derecho: Herramientas -->
            <div class="herramientas-panel">
                
                <!-- Contador de selección -->
                <div class="selection-counter" style="display: none;">
                    <span><span id="selectionCount">0</span> producto(s) seleccionado(s)</span>
                    <button type="button" id="clearSelectionBtn" class="clear-btn">Limpiar</button>
                </div>

                <!-- Ajuste Masivo -->
                <div class="tool-card">
                    <div class="tool-card-header">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/>
                            <polyline points="17 6 23 6 23 12"/>
                        </svg>
                        <h3>Ajuste por Porcentaje</h3>
                        <span class="help-tooltip" data-tip="Aumentá o disminuí precios aplicando un porcentaje. Ej: +10% para aumentar, -5% para disminuir." tabindex="0" aria-label="Ayuda">?</span>
                    </div>
                    <div class="tool-card-body">
                        <form method="post" id="formAjusteMasivo">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="accion" value="ajuste_masivo">
                            <input type="hidden" name="producto_ids" id="productoIds">
                            
                            <div class="form-group">
                                <label>Porcentaje de ajuste</label>
                                <div class="input-with-suffix">
                                    <input type="number" name="porcentaje" id="porcentajeInput" step="0.1" class="form-control" placeholder="Ej: 10 o -5" required>
                                    <span>%</span>
                                </div>
                                <p class="form-hint">Usá valores negativos para disminuir</p>
                            </div>
                            
                            <div class="form-group">
                                <label>Aplicar a</label>
                                <select name="tipo_precio" class="form-control">
                                    <option value="VENTA">Precio de Venta</option>
                                    <option value="COSTO">Costo</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>
                                    Redondeo
                                    <span class="help-tooltip" data-tip="El redondeo se aplica después del ajuste. 'Psicológico' redondea a X90 o X990." tabindex="0" aria-label="Ayuda">?</span>
                                </label>
                                <select name="redondeo" id="redondeoSelect" class="form-control">
                                    <option value="NINGUNO">Sin redondeo</option>
                                    <option value="ENTERO">Entero más cercano</option>
                                    <option value="10" selected>Múltiplo de 10</option>
                                    <option value="50">Múltiplo de 50</option>
                                    <option value="100">Múltiplo de 100</option>
                                    <option value="990">Psicológico (X90/X990)</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Motivo (opcional)</label>
                                <input type="text" name="motivo" class="form-control" placeholder="Ej: Ajuste por inflación marzo">
                            </div>
                            
                            <!-- Preview -->
                            <div class="preview-section">
                                <h4>Vista previa</h4>
                                <div class="preview-list" id="previewList">
                                    <p style="text-align: center; padding: 1rem; color: var(--pm-muted); margin: 0;">Seleccioná productos y un porcentaje</p>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn-apply primary" disabled>
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                                Aplicar Ajuste
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Aplicar Margen -->
                <div class="tool-card">
                    <div class="tool-card-header">
                        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="1" x2="12" y2="23"/>
                            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
                        </svg>
                        <h3>Margen sobre Costo</h3>
                        <span class="help-tooltip" data-tip="Calculá el precio de venta basándote en el costo. Precio = Costo × (1 + Margen/100). Solo funciona en productos con costo cargado." tabindex="0" aria-label="Ayuda">?</span>
                    </div>
                    <div class="tool-card-body">
                        <form method="post" id="formAplicarMargen">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="accion" value="aplicar_margen">
                            <input type="hidden" name="producto_ids" id="productoIdsMargen">
                            
                            <div class="form-group">
                                <label>Margen deseado</label>
                                <div class="input-with-suffix">
                                    <input type="number" name="margen" id="margenInput" step="0.1" min="0" class="form-control" placeholder="Ej: 30" required>
                                    <span>%</span>
                                </div>
                                <p class="form-hint">Si el costo es $100 y el margen 30%, el precio será $130</p>
                            </div>
                            
                            <div class="form-group">
                                <label>Redondeo</label>
                                <select name="redondeo" class="form-control">
                                    <option value="NINGUNO">Sin redondeo</option>
                                    <option value="ENTERO">Entero más cercano</option>
                                    <option value="10" selected>Múltiplo de 10</option>
                                    <option value="50">Múltiplo de 50</option>
                                    <option value="100">Múltiplo de 100</option>
                                    <option value="990">Psicológico (X90/X990)</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Motivo (opcional)</label>
                                <input type="text" name="motivo" class="form-control" placeholder="Ej: Normalizar márgenes">
                            </div>
                            
                            <button type="submit" class="btn-apply primary" disabled>
                                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="20 6 9 17 4 12"/>
                                </svg>
                                Aplicar Margen
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Ayuda rápida -->
                <div class="tool-card tool-card-help">
                    <div class="tool-card-body">
                        <p>
                            <strong>Atajos:</strong> Ctrl+A = Seleccionar todo · Esc = Limpiar · Ctrl+Shift+E = Expandir · Ctrl+Shift+C = Colapsar
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <script>
        // Sincronizar producto_ids entre los dos formularios
        document.getElementById('productoIds').addEventListener('change', function() {
            document.getElementById('productoIdsMargen').value = this.value;
        });
        // Observer para mantener sincronizado
        const observer = new MutationObserver(function() {
            document.getElementById('productoIdsMargen').value = document.getElementById('productoIds').value;
        });
        observer.observe(document.getElementById('productoIds'), { attributes: true, attributeFilter: ['value'] });
        </script>

    <!-- ============================================
         VISTA: MÁRGENES
    ============================================ -->
    <?php elseif ($vista === 'margenes'): ?>
        
        <!-- Estadísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= number_format($estadisticas['total_productos'] ?? 0) ?></div>
                <div class="stat-label">Productos Activos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= number_format($estadisticas['con_costo_definido'] ?? 0) ?></div>
                <div class="stat-label">Con Costo Definido</div>
            </div>
            <div class="stat-card">
                <div class="stat-value success"><?= number_format($estadisticas['margen_promedio'] ?? 0, 1) ?>%</div>
                <div class="stat-label">Margen Promedio</div>
            </div>
            <div class="stat-card">
                <div class="stat-value danger"><?= $estadisticas['productos_con_perdida'] ?? 0 ?></div>
                <div class="stat-label">Con Pérdida</div>
            </div>
            <div class="stat-card">
                <div class="stat-value warning"><?= $estadisticas['productos_margen_bajo'] ?? 0 ?></div>
                <div class="stat-label">Margen &lt;20%</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= number_format($estadisticas['margen_minimo'] ?? 0, 1) ?>%</div>
                <div class="stat-label">Margen Mínimo</div>
            </div>
        </div>

        <!-- Productos con margen bajo -->
        <div class="margenes-table">
            <div class="table-header">
                <h3>
                    <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 0.5rem; vertical-align: -3px;">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/>
                        <line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                    Productos con Margen Bajo
                </h3>
                <form method="get" class="margenes-filter">
                    <input type="hidden" name="v" value="margenes">
                    <label>Mostrar con margen menor a</label>
                    <input type="number" name="umbral" id="umbralInput" value="<?= htmlspecialchars($_GET['umbral'] ?? '15') ?>" step="1" min="0" max="100">
                    <span>%</span>
                    <button type="submit" class="btn btn-ghost btn-sm">Filtrar</button>
                </form>
            </div>
            
            <?php if (empty($margenBajo)): ?>
                <div style="padding: 3rem; text-align: center; color: var(--pm-muted);">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-bottom: 1rem; opacity: 0.5;">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                    <p>¡Excelente! No hay productos con margen inferior al <?= htmlspecialchars($_GET['umbral'] ?? '15') ?>%</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="t-right">Costo</th>
                                <th class="t-right">Precio</th>
                                <th class="t-right">Margen</th>
                                <th style="width: 100px;">Indicador</th>
                                <th style="width: 80px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($margenBajo as $p): 
                                $margen = (float)$p['margen_pct'];
                                $clase = $margen < 0 ? 'danger' : ($margen < 10 ? 'warning' : 'success');
                                $barWidth = max(0, min(100, $margen + 10)); // +10 para que negativos tengan algo de barra
                            ?>
                            <tr>
                                <td>
                                    <div class="producto-codigo"><?= htmlspecialchars($p['codigo']) ?></div>
                                    <div class="producto-nombre"><?= htmlspecialchars($p['nombre']) ?></div>
                                </td>
                                <td class="t-right">$<?= number_format((float)$p['costo'], 2, ',', '.') ?></td>
                                <td class="t-right">$<?= number_format((float)$p['precio'], 2, ',', '.') ?></td>
                                <td class="t-right">
                                    <span class="stat-value <?= $clase ?>" style="font-size: 0.875rem;">
                                        <?= number_format((float)$margen, 1, ',', '.') ?>%
                                    </span>
                                </td>
                                <td>
                                    <div class="margen-bar">
                                        <div class="margen-bar-fill <?= $clase ?>" style="width: <?= $barWidth ?>%;"></div>
                                    </div>
                                </td>
                                <td>
                                    <a href="?v=historial&pid=<?= $p['id'] ?>" class="btn btn-ghost btn-sm" title="Ver historial">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <circle cx="12" cy="12" r="10"/>
                                            <polyline points="12 6 12 12 16 14"/>
                                        </svg>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

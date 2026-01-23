<?php
/**
 * inventario_analisis.php
 * Dashboard de Análisis de Inventario - VERSIÓN CON AYUDA INTEGRADA
 *
 * @version 2.1.0
 */

declare(strict_types=1);

/* ============================================================================
   Bootstrap FLUS
============================================================================ */
$bootstrap = null;
$bootstrapCandidates = [
    __DIR__ . '/bootstrap.php',
    __DIR__ . '/../bootstrap.php',
    __DIR__ . '/../public/bootstrap.php',
];
foreach ($bootstrapCandidates as $p) {
    if (is_file($p)) { $bootstrap = $p; break; }
}
if ($bootstrap === null) {
    http_response_code(500);
    echo "FLUS bootstrap.php no encontrado.";
    exit;
}
require_once $bootstrap;
require_once __DIR__ . '/includes/InventarioAnalisis.php';

// Incluir sistema de ayuda
if (is_file(__DIR__ . '/partials/inventario_ayuda.php')) {
    require_once __DIR__ . '/partials/inventario_ayuda.php';
}

/* ============================================================================
   Auth helpers
============================================================================ */
$authCandidates = [
    __DIR__ . '/auth.php',
    __DIR__ . '/../auth.php',
    __DIR__ . '/includes/auth.php',
];
foreach ($authCandidates as $ap) {
    if (is_file($ap)) { require_once $ap; }
}

if (!function_exists('flus__has_perm')) {
    function flus__has_perm(string $perm): bool {
        if (function_exists('user_has_permission')) {
            try { return (bool) user_has_permission($perm); } catch (Throwable $e) {}
        }
        $perms = $_SESSION['permissions'] ?? ($_SESSION['permisos'] ?? []);
        if (is_array($perms) && in_array($perm, $perms, true)) return true;
        if (function_exists('tienePermiso')) {
            try { return (bool) tienePermiso($perm); } catch (Throwable $e) { return false; }
        }
        return false;
    }
}

$canStock = flus__has_perm('editar_stock') || flus__has_perm('ver_stock') || flus__has_perm('stock');
if (!$canStock) {
    http_response_code(403);
    echo "Acceso denegado";
    exit;
}

if (!isset($pdo)) {
    http_response_code(500);
    echo "Error: \$pdo no disponible";
    exit;
}

/* ============================================================================
   Parámetros de filtro y configuración
============================================================================ */
$analisis = new InventarioAnalisis($pdo);

// Límites configurables
$limitTop = min(500, max(10, (int)($_GET['limit_top'] ?? 25)));
$limitParados = min(500, max(10, (int)($_GET['limit_parados'] ?? 25)));
$limitRotacion = min(500, max(10, (int)($_GET['limit_rotacion'] ?? 25)));
$diasParados = max(7, (int)($_GET['dias_parados'] ?? 30));

// Filtros
$filtros = [
    'categoria' => trim($_GET['categoria'] ?? ''),
    'proveedor_id' => (int)($_GET['proveedor_id'] ?? 0) ?: null,
    'busqueda' => trim($_GET['q'] ?? ''),
];

// Tab activo
$tabActivo = $_GET['tab'] ?? 'resumen';

// Obtener datos
$resumen = $analisis->getResumenGeneral();
$categorias = $analisis->getCategorias();
$proveedores = $analisis->getProveedores();
$inversionCategoria = $analisis->getInversionPorCategoria();
$inversionProveedor = $analisis->getInversionPorProveedor();

// Datos según tab
$topInversion = [];
$productosParados = [];
$rotacion = [];
$stockBajo = [];
$proximosAgotarse = [];
$topVendidos = [];
$tendencia = [];

if ($tabActivo === 'resumen' || $tabActivo === 'inversion') {
    $topInversion = $analisis->getTopInversion($limitTop, $filtros);
}
if ($tabActivo === 'resumen' || $tabActivo === 'parados') {
    $productosParados = $analisis->getProductosParados($diasParados, $limitParados, $filtros);
}
if ($tabActivo === 'resumen' || $tabActivo === 'rotacion') {
    $rotacion = $analisis->getRotacion(30, $limitRotacion);
}
if ($tabActivo === 'resumen' || $tabActivo === 'alertas') {
    $stockBajo = $analisis->getStockBajo();
    $proximosAgotarse = $analisis->getProximosAgotarse(7, 20);
}
if ($tabActivo === 'ventas') {
    $topVendidos = $analisis->getTopVendidos(30, 20);
    $tendencia = $analisis->getTendenciaVentas(30);
}

// Totales para contadores
$totalConCosto = $analisis->contarProductosConCosto($filtros);
$totalParados = $analisis->contarProductosParados($diasParados);

// Helper formato
$fmtQty = static function($value, $esPesable = 0): string {
    return number_format((float)$value, ((int)$esPesable === 1) ? 2 : 0, ',', '.');
};
$fmtMoney = static function($value): string {
    return '$' . number_format((float)$value, 0, ',', '.');
};

// Helper para tooltips de ayuda (fallback si no existe el archivo)
if (!function_exists('renderTooltipAyuda')) {
    function renderTooltipAyuda(string $clave): string {
        return '<button type="button" class="inv-help-btn" data-help="' . htmlspecialchars($clave) . '" aria-label="Ayuda"><span class="inv-help-icon">?</span></button>';
    }
}

/* ========== HEADER ========== */
$pageTitle = 'Análisis de Inventario';
$currentSection = 'inventario_analisis';
$extraCss = [
    'assets/css/inventario_analisis.css',
    'assets/css/inventario_ayuda.css'
];
$extraJs = [
    // Chart.js (mismo CDN que Dashboard/Ventas). Necesario para los gráficos de este módulo.
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
    'assets/js/inventario_analisis.js',
    'assets/js/inventario_ayuda.js'
];

require __DIR__ . '/partials/header.php';

?>

<div class="page-wrap inventario-page">

    <!-- Encabezado -->
    <div class="panel inv-header-panel">
        <header class="page-header">
            <div>
                <h1 class="page-title">📊 Análisis de Inventario</h1>
                <p class="page-sub">Inversión, rotación, alertas y métricas de tu stock.</p>
            </div>
            <div class="page-actions">
                <a href="api/inventario_api.php?action=exportar_excel" class="btn btn-secondary" title="Exportar a Excel">
                    📥 Excel
                </a>
                <button type="button" class="btn btn-secondary" onclick="exportarPDF()">
                    📄 PDF
                </button>
                <button type="button" class="btn btn-primary" onclick="location.reload()">
                    🔄 Actualizar
                </button>
            </div>
        </header>
    </div>

    <!-- Tabs de navegación -->
    <div class="inv-tabs">
        <a href="?tab=resumen" class="inv-tab <?= $tabActivo === 'resumen' ? 'active' : '' ?>">📊 Resumen</a>
        <a href="?tab=inversion" class="inv-tab <?= $tabActivo === 'inversion' ? 'active' : '' ?>">💰 Inversión</a>
        <a href="?tab=rotacion" class="inv-tab <?= $tabActivo === 'rotacion' ? 'active' : '' ?>">🔄 Rotación</a>
        <a href="?tab=parados" class="inv-tab <?= $tabActivo === 'parados' ? 'active' : '' ?>">😴 Parados</a>
        <a href="?tab=alertas" class="inv-tab <?= $tabActivo === 'alertas' ? 'active' : '' ?>">⚠️ Alertas</a>
        <a href="?tab=ventas" class="inv-tab <?= $tabActivo === 'ventas' ? 'active' : '' ?>">📈 Ventas</a>
    </div>

    <!-- ========== TARJETAS DE RESUMEN (siempre visibles) ========== -->
    <div class="inv-cards-grid">
        <div class="inv-card inv-card-primary">
            <div class="inv-card-icon">💰</div>
            <div class="inv-card-content">
                <span class="inv-card-value"><?= $fmtMoney($resumen['inversion_total']) ?></span>
                <span class="inv-card-label">
                    Capital Invertido
                    <?= renderTooltipAyuda('capital_invertido') ?>
                </span>
            </div>
            <?php if ((int)$resumen['productos_sin_costo'] > 0): ?>
                <div class="inv-card-footnote" title="Productos sin costo cargado">
                    ⚠️ <?= $resumen['productos_sin_costo'] ?> sin costo
                </div>
            <?php endif; ?>
        </div>

        <div class="inv-card inv-card-success">
            <div class="inv-card-icon">📈</div>
            <div class="inv-card-content">
                <span class="inv-card-value"><?= $fmtMoney($resumen['valor_venta_potencial']) ?></span>
                <span class="inv-card-label">
                    Valor de Venta
                    <?= renderTooltipAyuda('valor_venta') ?>
                </span>
            </div>
            <div class="inv-card-sub">Ventas 30d: <?= $fmtMoney($resumen['ventas_mes']['total_vendido']) ?></div>
        </div>

        <div class="inv-card inv-card-info">
            <div class="inv-card-icon">📊</div>
            <div class="inv-card-content">
                <span class="inv-card-value"><?= $fmtMoney($resumen['margen_teorico']) ?></span>
                <span class="inv-card-label">
                    Margen Teórico
                    <?= renderTooltipAyuda('margen_teorico') ?>
                </span>
            </div>
            <?php $margenPct = $resumen['inversion_total'] > 0 ? round(($resumen['margen_teorico'] / $resumen['inversion_total']) * 100, 1) : 0; ?>
            <div class="inv-card-badge"><?= $margenPct ?>%</div>
        </div>

        <div class="inv-card inv-card-neutral">
            <div class="inv-card-icon">📦</div>
            <div class="inv-card-content">
                <span class="inv-card-value"><?= number_format((float)$resumen['total_unidades'], 0, ',', '.') ?></span>
                <span class="inv-card-label">Unidades en Stock</span>
            </div>
            <div class="inv-card-sub"><?= $resumen['total_productos'] ?> productos activos</div>
        </div>

        <!-- Tarjetas de alerta -->
        <?php if ($resumen['productos_stock_bajo'] > 0): ?>
        <div class="inv-card inv-card-warning">
            <div class="inv-card-icon">⚠️</div>
            <div class="inv-card-content">
                <span class="inv-card-value"><?= $resumen['productos_stock_bajo'] ?></span>
                <span class="inv-card-label">
                    Stock Bajo
                    <?= renderTooltipAyuda('stock_bajo') ?>
                </span>
            </div>
            <a href="?tab=alertas" class="inv-card-link">Ver detalle →</a>
        </div>
        <?php endif; ?>

        <?php if ($resumen['productos_agotados'] > 0): ?>
        <div class="inv-card inv-card-danger">
            <div class="inv-card-icon">🔴</div>
            <div class="inv-card-content">
                <span class="inv-card-value"><?= $resumen['productos_agotados'] ?></span>
                <span class="inv-card-label">Agotados</span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ========== PANEL DE ACCIONES RECOMENDADAS (solo en Resumen) ========== -->
    <?php if ($tabActivo === 'resumen' && function_exists('renderAccionesRecomendadas')): ?>
        <?= renderAccionesRecomendadas($resumen, $stockBajo, $productosParados) ?>
    <?php endif; ?>

    <!-- Filtros (para tabs que lo necesitan) -->
    <?php if (in_array($tabActivo, ['inversion', 'parados', 'rotacion'])): ?>
    <div class="panel inv-filters-panel">
        <form method="get" class="inv-filters">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tabActivo) ?>">
            
            <div class="inv-filter-group">
                <label>🔍 Buscar</label>
                <input type="text" name="q" value="<?= htmlspecialchars($filtros['busqueda']) ?>" 
                       placeholder="Nombre o código..." class="inv-filter-input">
            </div>

            <div class="inv-filter-group">
                <label>📁 Categoría</label>
                <select name="categoria" class="inv-filter-select">
                    <option value="">Todas</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>" <?= $filtros['categoria'] === $cat ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="inv-filter-group">
                <label>🏭 Proveedor</label>
                <select name="proveedor_id" class="inv-filter-select">
                    <option value="">Todos</option>
                    <?php foreach ($proveedores as $prov): ?>
                        <option value="<?= $prov['id'] ?>" <?= $filtros['proveedor_id'] == $prov['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($prov['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="inv-filter-group">
                <label>📊 Mostrar</label>
                <select name="limit_top" class="inv-filter-select" onchange="this.form.submit()">
                    <option value="25" <?= $limitTop == 25 ? 'selected' : '' ?>>25 productos</option>
                    <option value="50" <?= $limitTop == 50 ? 'selected' : '' ?>>50 productos</option>
                    <option value="100" <?= $limitTop == 100 ? 'selected' : '' ?>>100 productos</option>
                    <option value="200" <?= $limitTop == 200 ? 'selected' : '' ?>>200 productos</option>
                    <option value="500" <?= $limitTop == 500 ? 'selected' : '' ?>>Todos (máx 500)</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Filtrar</button>
            <a href="?tab=<?= $tabActivo ?>" class="btn btn-secondary">Limpiar</a>
        </form>
    </div>
    <?php endif; ?>

    <!-- ==================== CONTENIDO SEGÚN TAB ==================== -->
    
    <?php if ($tabActivo === 'resumen'): ?>
    <!-- ==================== TAB RESUMEN ==================== -->
    <div class="inv-main-grid">
        <div class="inv-col-left">
            <!-- Top 10 Inversión -->
            <div class="panel inv-table-panel">
                <div class="panel-header">
                    <h2 class="panel-title">💰 Top 10 Mayor Inversión <?= renderTooltipAyuda('capital_invertido') ?></h2>
                    <a href="?tab=inversion" class="btn btn-sm btn-link">Ver todos →</a>
                </div>
                <div class="inv-table-wrap">
                    <table class="inv-table">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="text-center">Stock</th>
                                <th class="text-right">Invertido</th>
                                <th class="text-right">Margen</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($topInversion, 0, 10) as $prod): ?>
                            <tr>
                                <td>
                                    <div class="inv-prod-name"><?= htmlspecialchars($prod['nombre']) ?></div>
                                    <div class="inv-prod-code"><?= htmlspecialchars($prod['codigo']) ?></div>
                                </td>
                                <td class="text-center">
                                    <span class="inv-stock-badge"><?= $fmtQty($prod['stock'], $prod['es_pesable']) ?></span>
                                </td>
                                <td class="text-right inv-highlight"><?= $fmtMoney($prod['capital_invertido']) ?></td>
                                <td class="text-right">
                                    <?php if (($prod['margen_pct'] ?? null) === null || $prod['margen_pct'] === ''): ?>
                                        <span class="inv-margen inv-margen-na">-</span>
                                    <?php else: $margen = (float)$prod['margen_pct']; ?>
                                        <span class="inv-margen <?= $margen >= 30 ? 'inv-margen-good' : ($margen >= 15 ? 'inv-margen-ok' : 'inv-margen-low') ?>">
                                            <?= number_format($margen, 1, ',', '.') ?>%
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Productos Parados -->
            <div class="panel inv-table-panel">
                <div class="panel-header">
                    <h2 class="panel-title">😴 Productos Parados (30+ días) <?= renderTooltipAyuda('productos_parados') ?></h2>
                    <a href="?tab=parados" class="btn btn-sm btn-link">Ver todos →</a>
                </div>
                <div class="inv-table-wrap">
                    <table class="inv-table">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="text-right">Capital</th>
                                <th class="text-center">Días</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($productosParados, 0, 8) as $prod): ?>
                            <tr>
                                <td>
                                    <div class="inv-prod-name"><?= htmlspecialchars($prod['nombre']) ?></div>
                                </td>
                                <td class="text-right inv-highlight-warning"><?= $fmtMoney($prod['capital_parado']) ?></td>
                                <td class="text-center">
                                    <span class="inv-dias-badge <?= $prod['dias_sin_venta'] > 60 ? 'inv-dias-critical' : 'inv-dias-warning' ?>">
                                        <?= $prod['dias_sin_venta'] ?>d
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($productosParados)): ?>
                            <tr><td colspan="3" class="text-center text-success">✅ Todos los productos tienen ventas recientes</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="inv-col-right">
            <!-- Gráfico Categorías -->
            <div class="panel inv-chart-panel">
                <div class="panel-header">
                    <h2 class="panel-title">📊 Inversión por Categoría <?= renderTooltipAyuda('inversion_categoria') ?></h2>
                </div>
                <div class="inv-chart-container">
                    <canvas id="chartCategorias"></canvas>
                </div>
                <div class="inv-chart-legend" id="legendCategorias"></div>
            </div>

            <!-- Top Vendidos -->
            <?php $topVendidosResumen = $analisis->getTopVendidos(30, 5); ?>
            <div class="panel inv-table-panel">
                <div class="panel-header">
                    <h2 class="panel-title">🏆 Más Vendidos (30 días)</h2>
                    <a href="?tab=ventas" class="btn btn-sm btn-link">Ver más →</a>
                </div>
                <div class="inv-table-wrap inv-table-compact">
                    <table class="inv-table">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="text-center">Vendidos</th>
                                <th class="text-right">Ingresos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topVendidosResumen as $prod): ?>
                            <tr>
                                <td><div class="inv-prod-name-sm"><?= htmlspecialchars($prod['nombre']) ?></div></td>
                                <td class="text-center"><span class="inv-vendidos"><?= number_format((float)$prod['unidades_vendidas'], 0, ',', '.') ?></span></td>
                                <td class="text-right"><?= $fmtMoney($prod['ingresos']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Stock Bajo -->
            <div class="panel inv-table-panel" id="stock-bajo">
                <div class="panel-header">
                    <h2 class="panel-title">🔴 Stock Bajo Mínimo <?= renderTooltipAyuda('stock_bajo') ?></h2>
                    <span class="panel-badge panel-badge-danger"><?= count($stockBajo) ?></span>
                </div>
                <div class="inv-table-wrap inv-table-compact">
                    <table class="inv-table">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th class="text-center">Actual</th>
                                <th class="text-center">Falta</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($stockBajo, 0, 6) as $prod): ?>
                            <tr>
                                <td><div class="inv-prod-name-sm"><?= htmlspecialchars($prod['nombre']) ?></div></td>
                                <td class="text-center"><span class="inv-stock-low"><?= $fmtQty($prod['stock'], $prod['es_pesable'] ?? 0) ?></span></td>
                                <td class="text-center"><span class="inv-faltante">-<?= $fmtQty($prod['faltante'], $prod['es_pesable'] ?? 0) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($stockBajo)): ?>
                            <tr><td colspan="3" class="text-center text-success">✅ Stock OK</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php elseif ($tabActivo === 'inversion'): ?>
    <!-- ==================== TAB INVERSIÓN ==================== -->
    <div class="panel inv-table-panel">
        <div class="panel-header">
            <h2 class="panel-title">💰 Capital Invertido por Producto <?= renderTooltipAyuda('capital_invertido') ?></h2>
            <div class="panel-info">
                Mostrando <?= count($topInversion) ?> de <?= $totalConCosto ?> productos con costo
            </div>
        </div>
        <div class="inv-table-wrap inv-table-full">
            <table class="inv-table inv-table-striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Código</th>
                        <th>Producto</th>
                        <th>Categoría</th>
                        <th>Proveedor</th>
                        <th class="text-center">Stock</th>
                        <th class="text-right">Costo</th>
                        <th class="text-right">Precio</th>
                        <th class="text-right">Invertido</th>
                        <th class="text-right">Valor Venta</th>
                        <th class="text-center">Margen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $totalInvertido = 0; $totalValor = 0; ?>
                    <?php foreach ($topInversion as $i => $prod): ?>
                    <?php $totalInvertido += (float)$prod['capital_invertido']; $totalValor += (float)$prod['valor_venta']; ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td><code><?= htmlspecialchars($prod['codigo']) ?></code></td>
                        <td><strong><?= htmlspecialchars($prod['nombre']) ?></strong></td>
                        <td><?= htmlspecialchars($prod['categoria'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($prod['proveedor']) ?></td>
                        <td class="text-center">
                            <span class="inv-stock-badge <?= $prod['stock'] <= $prod['stock_minimo'] ? 'inv-stock-low-bg' : '' ?>">
                                <?= $fmtQty($prod['stock'], $prod['es_pesable']) ?>
                            </span>
                        </td>
                        <td class="text-right"><?= $fmtMoney($prod['costo']) ?></td>
                        <td class="text-right"><?= $fmtMoney($prod['precio']) ?></td>
                        <td class="text-right inv-highlight"><strong><?= $fmtMoney($prod['capital_invertido']) ?></strong></td>
                        <td class="text-right"><?= $fmtMoney($prod['valor_venta']) ?></td>
                        <td class="text-center">
                            <?php if (($prod['margen_pct'] ?? null) === null || $prod['margen_pct'] === ''): ?>
                                <span class="inv-margen inv-margen-na">-</span>
                            <?php else: $margen = (float)$prod['margen_pct']; ?>
                                <span class="inv-margen <?= $margen >= 30 ? 'inv-margen-good' : ($margen >= 15 ? 'inv-margen-ok' : 'inv-margen-low') ?>">
                                    <?= number_format($margen, 1, ',', '.') ?>%
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="inv-table-footer">
                        <td colspan="8" class="text-right"><strong>TOTALES:</strong></td>
                        <td class="text-right"><strong><?= $fmtMoney($totalInvertido) ?></strong></td>
                        <td class="text-right"><strong><?= $fmtMoney($totalValor) ?></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Gráfico por Proveedor -->
    <div class="inv-grid-2col">
        <div class="panel inv-chart-panel">
            <div class="panel-header">
                <h2 class="panel-title">📊 Inversión por Categoría <?= renderTooltipAyuda('inversion_categoria') ?></h2>
            </div>
            <div class="inv-chart-container-lg">
                <canvas id="chartCategorias"></canvas>
            </div>
        </div>
        <div class="panel inv-chart-panel">
            <div class="panel-header">
                <h2 class="panel-title">🏭 Inversión por Proveedor <?= renderTooltipAyuda('inversion_proveedor') ?></h2>
            </div>
            <div class="inv-chart-container-lg">
                <canvas id="chartProveedores"></canvas>
            </div>
        </div>
    </div>

    <?php elseif ($tabActivo === 'rotacion'): ?>
    <!-- ==================== TAB ROTACIÓN ==================== -->
    
    <!-- Leyenda ABC -->
    <div class="inv-abc-legend-panel">
        <div class="inv-abc-legend-title">
            📊 Clasificación ABC <?= renderTooltipAyuda('clasificacion_abc') ?>
        </div>
        <div class="inv-abc-legend-items">
            <span class="inv-abc-legend-item">
                <span class="inv-abc inv-abc-a">A</span>
                <span>Productos estrella (80% de ingresos)</span>
            </span>
            <span class="inv-abc-legend-item">
                <span class="inv-abc inv-abc-b">B</span>
                <span>Venta media (15% de ingresos)</span>
            </span>
            <span class="inv-abc-legend-item">
                <span class="inv-abc inv-abc-c">C</span>
                <span>Baja rotación (5% de ingresos)</span>
            </span>
        </div>
    </div>
    
    <div class="panel inv-table-panel">
        <div class="panel-header">
            <h2 class="panel-title">🔄 Rotación de Productos (últimos 30 días) <?= renderTooltipAyuda('rotacion') ?></h2>
            <div class="panel-controls">
                <select onchange="location.href='?tab=rotacion&orden='+this.value" class="inv-filter-select">
                    <option value="vendidos" <?= ($_GET['orden'] ?? '') === 'vendidos' ? 'selected' : '' ?>>Más vendidos primero</option>
                    <option value="dias_rest" <?= ($_GET['orden'] ?? '') === 'dias_rest' ? 'selected' : '' ?>>Menor stock restante</option>
                    <option value="stock" <?= ($_GET['orden'] ?? '') === 'stock' ? 'selected' : '' ?>>Mayor stock</option>
                </select>
            </div>
        </div>
        <div class="inv-table-wrap inv-table-full">
            <table class="inv-table inv-table-striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Producto</th>
                        <th>Categoría</th>
                        <th class="text-center">Stock</th>
                        <th class="text-center">Vendidos 30d</th>
                        <th class="text-right">Ingresos 30d</th>
                        <th class="text-center">Prom. Diario</th>
                        <th class="text-center">Días Stock <?= renderTooltipAyuda('dias_stock_restante') ?></th>
                        <th class="text-center">ABC</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rotacion as $i => $prod): ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td>
                            <strong><?= htmlspecialchars($prod['nombre']) ?></strong>
                            <div class="inv-prod-code"><?= htmlspecialchars($prod['codigo']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($prod['categoria'] ?: '-') ?></td>
                        <td class="text-center">
                            <span class="inv-stock-badge <?= $prod['stock'] <= $prod['stock_minimo'] ? 'inv-stock-low-bg' : '' ?>">
                                <?= $fmtQty($prod['stock'], $prod['es_pesable']) ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="inv-vendidos"><?= $fmtQty($prod['vendidos_30d'], $prod['es_pesable']) ?></span>
                        </td>
                        <td class="text-right"><?= $fmtMoney($prod['ingresos_30d']) ?></td>
                        <td class="text-center"><?= number_format((float)$prod['promedio_diario'], 2, ',', '.') ?></td>
                        <td class="text-center">
                            <?php if ($prod['dias_stock_restante'] >= 999): ?>
                                <span class="tag tag-muted">∞</span>
                            <?php else: ?>
                                <span class="inv-dias-rest <?= $prod['dias_stock_restante'] < 7 ? 'inv-dias-urgent' : ($prod['dias_stock_restante'] < 15 ? 'inv-dias-soon' : '') ?>">
                                    <?= number_format((float)$prod['dias_stock_restante'], 0) ?>d
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="inv-abc inv-abc-<?= strtolower($prod['clasificacion_abc']) ?>">
                                <?= $prod['clasificacion_abc'] ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php elseif ($tabActivo === 'parados'): ?>
    <!-- ==================== TAB PARADOS ==================== -->
    <div class="panel inv-filters-panel">
        <form method="get" class="inv-filters">
            <input type="hidden" name="tab" value="parados">
            <div class="inv-filter-group">
                <label>📅 Sin venta hace</label>
                <select name="dias_parados" class="inv-filter-select" onchange="this.form.submit()">
                    <option value="15" <?= $diasParados == 15 ? 'selected' : '' ?>>15+ días</option>
                    <option value="30" <?= $diasParados == 30 ? 'selected' : '' ?>>30+ días</option>
                    <option value="60" <?= $diasParados == 60 ? 'selected' : '' ?>>60+ días</option>
                    <option value="90" <?= $diasParados == 90 ? 'selected' : '' ?>>90+ días</option>
                    <option value="180" <?= $diasParados == 180 ? 'selected' : '' ?>>180+ días</option>
                </select>
            </div>
            <div class="inv-filter-group">
                <label>📊 Mostrar</label>
                <select name="limit_parados" class="inv-filter-select" onchange="this.form.submit()">
                    <option value="25" <?= $limitParados == 25 ? 'selected' : '' ?>>25 productos</option>
                    <option value="50" <?= $limitParados == 50 ? 'selected' : '' ?>>50 productos</option>
                    <option value="100" <?= $limitParados == 100 ? 'selected' : '' ?>>100 productos</option>
                    <option value="500" <?= $limitParados == 500 ? 'selected' : '' ?>>Todos</option>
                </select>
            </div>
        </form>
    </div>

    <div class="panel inv-table-panel">
        <div class="panel-header">
            <h2 class="panel-title">😴 Productos Sin Venta (<?= $diasParados ?>+ días) <?= renderTooltipAyuda('productos_parados') ?></h2>
            <div class="panel-info">
                <?= count($productosParados) ?> productos | Capital parado: 
                <strong><?= $fmtMoney(array_sum(array_column($productosParados, 'capital_parado'))) ?></strong>
            </div>
        </div>
        <div class="inv-table-wrap inv-table-full">
            <table class="inv-table inv-table-striped">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>Categoría</th>
                        <th class="text-center">Stock</th>
                        <th class="text-right">Capital Parado</th>
                        <th class="text-center">Última Venta</th>
                        <th class="text-center">Días</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($productosParados as $prod): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($prod['nombre']) ?></strong>
                            <div class="inv-prod-code"><?= htmlspecialchars($prod['codigo']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($prod['categoria'] ?: '-') ?></td>
                        <td class="text-center">
                            <span class="inv-stock-badge"><?= $fmtQty($prod['stock'], $prod['es_pesable']) ?></span>
                        </td>
                        <td class="text-right inv-highlight-warning"><strong><?= $fmtMoney($prod['capital_parado']) ?></strong></td>
                        <td class="text-center">
                            <?php if ($prod['ultima_venta'] === 'Nunca'): ?>
                                <span class="tag tag-muted">Nunca</span>
                            <?php else: ?>
                                <?= date('d/m/Y', strtotime($prod['ultima_venta'])) ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="inv-dias-badge <?= $prod['dias_sin_venta'] > 90 ? 'inv-dias-critical' : ($prod['dias_sin_venta'] > 60 ? 'inv-dias-warning' : '') ?>">
                                <?= $prod['dias_sin_venta'] ?>d
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($productosParados)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-success">✅ Todos los productos tienen ventas en los últimos <?= $diasParados ?> días</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php elseif ($tabActivo === 'alertas'): ?>
    <!-- ==================== TAB ALERTAS ==================== -->
    <div class="inv-grid-2col">
        <!-- Stock Bajo -->
        <div class="panel inv-table-panel">
            <div class="panel-header">
                <h2 class="panel-title">🔴 Stock Bajo Mínimo <?= renderTooltipAyuda('stock_bajo') ?></h2>
                <span class="panel-badge panel-badge-danger"><?= count($stockBajo) ?></span>
            </div>
            <div class="inv-table-wrap inv-table-scroll">
                <table class="inv-table">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Proveedor</th>
                            <th class="text-center">Actual</th>
                            <th class="text-center">Mínimo</th>
                            <th class="text-center">Faltante</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stockBajo as $prod): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($prod['nombre']) ?></strong>
                                <div class="inv-prod-code"><?= htmlspecialchars($prod['codigo']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($prod['proveedor']) ?></td>
                            <td class="text-center"><span class="inv-stock-low"><?= $fmtQty($prod['stock'], $prod['es_pesable']) ?></span></td>
                            <td class="text-center text-muted"><?= $fmtQty($prod['stock_minimo'], $prod['es_pesable']) ?></td>
                            <td class="text-center"><span class="inv-faltante">-<?= $fmtQty($prod['faltante'], $prod['es_pesable']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($stockBajo)): ?>
                        <tr><td colspan="5" class="text-center text-success">✅ Todo el stock está sobre el mínimo</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Próximos a Agotarse -->
        <div class="panel inv-table-panel">
            <div class="panel-header">
                <h2 class="panel-title">⏰ Se Agotan en 7 días <?= renderTooltipAyuda('proximos_agotarse') ?></h2>
                <span class="panel-badge panel-badge-warning"><?= count($proximosAgotarse) ?></span>
            </div>
            <div class="inv-table-wrap inv-table-scroll">
                <table class="inv-table">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th class="text-center">Stock</th>
                            <th class="text-center">Prom/día</th>
                            <th class="text-center">Días Rest. <?= renderTooltipAyuda('dias_stock_restante') ?></th>
                            <th class="text-center">Reponer <?= renderTooltipAyuda('cantidad_reponer') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proximosAgotarse as $prod): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($prod['nombre']) ?></strong>
                            </td>
                            <td class="text-center"><?= $fmtQty($prod['stock'], $prod['es_pesable']) ?></td>
                            <td class="text-center"><?= number_format((float)$prod['promedio_diario'], 1, ',', '.') ?></td>
                            <td class="text-center">
                                <span class="inv-dias-badge inv-dias-urgent"><?= number_format((float)$prod['dias_restantes'], 0) ?>d</span>
                            </td>
                            <td class="text-center">
                                <?php if ($prod['cantidad_reponer'] > 0): ?>
                                    <span class="inv-reponer">+<?= number_format((float)$prod['cantidad_reponer'], 0, ',', '.') ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($proximosAgotarse)): ?>
                        <tr><td colspan="5" class="text-center text-success">✅ No hay productos críticos</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php elseif ($tabActivo === 'ventas'): ?>
    <!-- ==================== TAB VENTAS ==================== -->
    <div class="inv-grid-2col">
        <!-- Top Vendidos -->
        <div class="panel inv-table-panel">
            <div class="panel-header">
                <h2 class="panel-title">🏆 Más Vendidos (30 días)</h2>
            </div>
            <div class="inv-table-wrap inv-table-scroll">
                <table class="inv-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Producto</th>
                            <th class="text-center">Vendidos</th>
                            <th class="text-right">Ingresos</th>
                            <th class="text-center">Veces</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topVendidos as $i => $prod): ?>
                        <tr>
                            <td class="text-muted"><?= $i + 1 ?></td>
                            <td>
                                <strong><?= htmlspecialchars($prod['nombre']) ?></strong>
                                <div class="inv-prod-code"><?= htmlspecialchars($prod['categoria'] ?: '-') ?></div>
                            </td>
                            <td class="text-center"><span class="inv-vendidos"><?= number_format((float)$prod['unidades_vendidas'], 0, ',', '.') ?></span></td>
                            <td class="text-right"><strong><?= $fmtMoney($prod['ingresos']) ?></strong></td>
                            <td class="text-center"><?= $prod['veces_vendido'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Gráfico tendencia -->
        <div class="panel inv-chart-panel">
            <div class="panel-header">
                <h2 class="panel-title">📈 Tendencia de Ventas (30 días)</h2>
            </div>
            <div class="inv-chart-container-lg">
                <canvas id="chartTendencia"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<!-- Datos para JavaScript -->
<script>
window.FLUS_INV_DATA = {
    categorias: <?= json_encode($inversionCategoria, JSON_UNESCAPED_UNICODE) ?>,
    proveedores: <?= json_encode($inversionProveedor, JSON_UNESCAPED_UNICODE) ?>,
    resumen: <?= json_encode($resumen, JSON_UNESCAPED_UNICODE) ?>,
    tendencia: <?= json_encode($tendencia ?? [], JSON_UNESCAPED_UNICODE) ?>,
    tab: '<?= $tabActivo ?>'
};
</script>

<!-- Modal de Ayuda -->
<?php if (function_exists('renderModalAyuda')): ?>
    <?= renderModalAyuda() ?>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>

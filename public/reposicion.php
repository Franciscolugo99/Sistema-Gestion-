<?php
// public/reposicion.php - Reposicion Sugerida FLUS
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

if (!user_has_permission('ver_reportes') && !user_has_permission('ver_stock') && !user_has_permission('editar_stock')) {
    http_response_code(403);
    echo 'No tenes permisos para acceder a esta seccion.';
    exit;
}

require_once __DIR__ . '/../src/reposicion_sugerida.php';

// Asegurar tablas existen
$pdo = getPDO();
reposicion_ensure_tables($pdo);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle = 'Reposicion Sugerida - FLUS';
$currentSection = 'reposicion';

// Separacion de assets
$extraCss = ['assets/css/reposicion.css', 'assets/css/reposicion_mejoras.css'];
$extraJs  = ['assets/js/reposicion.js'];

$info = null;
$error = null;

// Vista actual
$vista = (string)($_GET['v'] ?? 'alertas'); // alertas | lista | config

// Permiso para configuracion (compat: gestiona_stock en builds viejos)
$canConfig = user_has_permission('editar_stock') || user_has_permission('gestionar_stock');

// Manejo de acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Token CSRF invalido.';
    } else {
        $accion = (string)($_POST['accion'] ?? '');

        if ($accion === 'generar_reposicion') {
            $vista = 'lista';
            $info = 'Reposicion sugerida generada con stock actual, reglas y proveedores vigentes.';
        } elseif ($accion === 'guardar_config' && $canConfig) {
            $vista = 'config';
            $productoId = (int)($_POST['producto_id'] ?? 0);
            $stockMin = (float)($_POST['stock_minimo'] ?? 0);
            $stockMax = (float)($_POST['stock_maximo'] ?? 0);
            $puntoReorden = (float)($_POST['punto_reorden'] ?? 0);

            $proveedorId = (int)($_POST['proveedor_id'] ?? 0);
            $proveedorId = $proveedorId > 0 ? $proveedorId : null;

            if ($productoId > 0) {
                if (reposicion_set_config($productoId, $stockMin, $stockMax, $puntoReorden, $proveedorId)) {
                    $info = 'Configuracion guardada.';
                } else {
                    $error = 'Error al guardar configuracion.';
                }
            }
        } elseif ($accion === 'guardar_config_lote' && $canConfig) {
            $vista = 'config';
            $productoIds = array_values(array_unique(array_filter(array_map(static function ($id): int {
                return (int)$id;
            }, (array)($_POST['producto_ids'] ?? [])), static function (int $id): bool {
                return $id > 0;
            })));

            $changes = [];

            $stockMinRaw = trim((string)($_POST['bulk_stock_minimo'] ?? ''));
            if ($stockMinRaw !== '') {
                $changes['stock_minimo'] = (float)$stockMinRaw;
            }

            $puntoReordenRaw = trim((string)($_POST['bulk_punto_reorden'] ?? ''));
            if ($puntoReordenRaw !== '') {
                $changes['punto_reorden'] = (float)$puntoReordenRaw;
            }

            $stockMaxRaw = trim((string)($_POST['bulk_stock_maximo'] ?? ''));
            if ($stockMaxRaw !== '') {
                $changes['stock_maximo'] = (float)$stockMaxRaw;
            }

            $proveedorRaw = (string)($_POST['bulk_proveedor_id'] ?? '');
            if ($proveedorRaw !== '') {
                $proveedorId = (int)$proveedorRaw;
                $changes['proveedor_id'] = $proveedorId > 0 ? $proveedorId : null;
            }

            if (empty($productoIds)) {
                $error = 'Selecciona al menos un producto para aplicar cambios en lote.';
            } elseif (empty($changes)) {
                $error = 'Completa al menos un campo en la edicion masiva. Deja vacio para no cambiar.';
            } else {
                $resultado = function_exists('reposicion_set_config_lote')
                    ? reposicion_set_config_lote($productoIds, $changes)
                    : ['updated' => 0, 'failed' => count($productoIds)];

                if (($resultado['updated'] ?? 0) > 0) {
                    $info = 'Se actualizaron ' . (int)$resultado['updated'] . ' productos en lote.';
                    if (($resultado['failed'] ?? 0) > 0) {
                        $info .= ' Fallaron ' . (int)$resultado['failed'] . '.';
                    }
                } else {
                    $error = 'No se pudieron guardar los cambios en lote.';
                }
            }
        } elseif ($accion === 'exportar_csv') {
            $proveedorId = (int)($_POST['proveedor_id'] ?? 0) ?: null;

            $csv = reposicion_exportar_csv($proveedorId);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="reposicion_' . date('Y-m-d') . '.csv"');
            echo $csv;
            exit;
        }
    }
}

// Cargar datos
$pdo = getPDO();

// Conteo de estados
$conteoEstados = reposicion_conteo_estados();
$resumenModulo = function_exists('reposicion_resumen_operativo')
    ? reposicion_resumen_operativo()
    : [
        'total_activos' => 0,
        'productos_configurados' => 0,
        'productos_sin_regla' => 0,
        'productos_con_proveedor' => 0,
        'productos_sin_proveedor' => 0,
        'productos_pendientes_config' => 0,
        'cobertura_config' => 0.0,
    ];
$ventasDias = 30;
$leadTimeDias = 7;
$sugerenciasVentas = function_exists('reposicion_sugerir_por_ventas')
    ? reposicion_sugerir_por_ventas($ventasDias, $leadTimeDias, 12)
    : [];
if (!is_array($sugerenciasVentas)) {
    $sugerenciasVentas = [];
}
$sugerenciasVentas = array_values(array_filter($sugerenciasVentas, static function (array $item): bool {
    return (float)($item['cantidad_sugerida'] ?? 0) > 0 && (float)($item['vendidas'] ?? 0) > 0;
}));
usort($sugerenciasVentas, static function (array $a, array $b): int {
    $qtyCmp = (float)($b['cantidad_sugerida'] ?? 0) <=> (float)($a['cantidad_sugerida'] ?? 0);
    if ($qtyCmp !== 0) {
        return $qtyCmp;
    }
    return (float)($b['vendidas'] ?? 0) <=> (float)($a['vendidas'] ?? 0);
});
$sugerenciasVentas = array_slice($sugerenciasVentas, 0, 6);

// Para alertas: productos con stock bajo
$stockBajo = [];
if ($vista === 'alertas') {
    $stockBajo = reposicion_get_stock_bajo();

    // Fallback: si el conteo dice que hay alertas pero la lista vino vacia,
    // armamos la lista desde productos para que no quede inconsistente.
    $hayAlertas = (int)($conteoEstados['total_alertas'] ?? 0) > 0;

    if ($hayAlertas && empty($stockBajo)) {
        $minExpr = _repo_expr_min($pdo);
        $reoExpr = _repo_expr_reorden($pdo);
        $provExpr = _repo_expr_proveedor($pdo);

        $sql = "
            SELECT
                p.id,
                p.codigo,
                p.nombre,
                p.stock,
                p.costo,
                COALESCE($minExpr, 0) AS stock_minimo,
                COALESCE($reoExpr, 0) AS punto_reorden,
                COALESCE(pr.nombre, 'Sin proveedor') AS proveedor_nombre
            FROM productos p
            LEFT JOIN producto_reposicion r ON r.producto_id = p.id
            LEFT JOIN proveedores pr ON pr.id = $provExpr
            WHERE p.activo = 1
              AND (
                    p.stock <= 0
                 OR ($minExpr IS NOT NULL AND p.stock < $minExpr)
                 OR ($reoExpr IS NOT NULL AND p.stock < $reoExpr)
              )
            ORDER BY (p.stock <= 0) DESC, p.stock ASC, p.nombre ASC
            LIMIT 300
        ";

        $stmt = $pdo->query($sql);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $stockBajo = array_map(static function(array $r): array {
            $stock = (float)($r['stock'] ?? 0);
            $min   = (float)($r['stock_minimo'] ?? 0);
            $reo   = (float)($r['punto_reorden'] ?? 0);

            $estado = 'REORDEN';
            if ($stock <= 0) {
                $estado = 'SIN_STOCK';
            } elseif ($min > 0 && $stock < $min) {
                $estado = 'BAJO_MINIMO';
            }

            $cant = 0.0;
            if ($min > 0) {
                $cant = max($min - $stock, 0);
            } elseif ($reo > 0) {
                $cant = max($reo - $stock, 0);
            } elseif ($stock <= 0) {
                $cant = 1;
            }

            $r['estado'] = $estado;
            $r['cantidad_sugerida'] = $cant;

            return $r;
        }, $rows);
    }
}

// Para lista: lista completa por proveedor
$listaPorProveedor = [];
$listaResumen = ['grupos' => 0, 'items' => 0, 'costo_total' => 0.0];
if ($vista === 'lista') {
    $listaPorProveedor = reposicion_lista_por_proveedor();
    $listaResumen['grupos'] = count($listaPorProveedor);
    foreach ($listaPorProveedor as $grupo) {
        $listaResumen['items'] += (int)($grupo['total_items'] ?? 0);
        $listaResumen['costo_total'] += (float)($grupo['costo_total'] ?? 0);
    }
}

// Para config: lista de productos para configurar
$productos = [];
$totalProductos = 0;
$page = 1;
$limit = 30;
$buscar = '';
$proveedorFiltro = null; // null => sin filtro; 0 => sin proveedor; >0 => proveedor
if ($vista === 'config') {
    $buscar = trim((string)($_GET['q'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = 30;

    if (isset($_GET['proveedor_id']) && $_GET['proveedor_id'] !== '') {
        $proveedorFiltro = (int)$_GET['proveedor_id'];
        if ($proveedorFiltro < 0) $proveedorFiltro = null;
    }

    $lenBuscar = function_exists('mb_strlen') ? mb_strlen($buscar) : strlen($buscar);
    $tieneBusquedaValida = ($buscar !== '' && $lenBuscar >= 2);
    $tieneFiltroProveedor = ($proveedorFiltro !== null);

    if ($tieneBusquedaValida || $tieneFiltroProveedor) {
        $configData = reposicion_listar_configuracion($buscar, $proveedorFiltro, $page, $limit);
        $productos = $configData['items'] ?? [];
        $totalProductos = (int)($configData['total'] ?? 0);
    }
}

// Cargar proveedores para filtro
$proveedores = $pdo->query("SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/partials/header.php';
?>

<div class="panel">
    <header class="panel-head page-header module-header">
        <div class="page-header-main module-header-main">
            <div class="module-header-hero">
                <span class="module-header-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                        <path d="M3 7h18"/>
                        <path d="M6 3h12l3 4v13a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7l3-4Z"/>
                        <path d="M9 12h6"/>
                    </svg>
                </span>
                <div class="module-header-copy">
                    <span class="module-eyebrow">Abastecimiento operativo</span>
                    <h1 class="page-title">Reposicion Sugerida</h1>
                    <p class="page-sub panel-subtitle">Alertas de stock bajo y lista de compras</p>
                </div>
            </div>
        </div>

        <?php if ($vista === 'lista'): ?>
        <div class="module-header-actions">
            <form method="post" class="repo-inline-form">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="accion" value="exportar_csv">
                <button type="submit" class="btn btn-primary">
                    <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Exportar CSV
                </button>
            </form>
        </div>
        <?php endif; ?>
    </header>

    <?php if ($info): ?>
        <div class="alert alert-ok">
            <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
            <span><?= h($info) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-err">
            <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <span><?= h($error) ?></span>
        </div>
    <?php endif; ?>
    <?php
        $productosConfigurados = (int)($resumenModulo['productos_configurados'] ?? 0);
        $productosPendientes = (int)($resumenModulo['productos_pendientes_config'] ?? 0);
        $productosSinRegla = (int)($resumenModulo['productos_sin_regla'] ?? 0);
        $productosConProveedor = (int)($resumenModulo['productos_con_proveedor'] ?? 0);
        $productosSinProveedor = (int)($resumenModulo['productos_sin_proveedor'] ?? 0);
        $coberturaConfig = (float)($resumenModulo['cobertura_config'] ?? 0);
    ?>

    <section class="repo-hero">
        <div class="repo-hero-copy">
            <span class="repo-eyebrow">Flujo completo</span>
            <h2>Genera la reposicion sugerida sin adivinar el proceso</h2>
            <p>
                El sistema cruza stock actual, reglas de reposicion y demanda reciente.
                Primero defines minimos o punto de reorden, despues generas la sugerencia y finalmente revisas la compra por proveedor.
            </p>

            <div class="repo-steps">
                <div class="repo-step <?= $productosConfigurados > 0 ? 'is-done' : '' ?>">
                    <strong>1.</strong> Configura minimo, reorden y proveedor.
                </div>
                <div class="repo-step <?= ((int)($conteoEstados['total_alertas'] ?? 0) > 0 || !empty($sugerenciasVentas)) ? 'is-done' : '' ?>">
                    <strong>2.</strong> Genera la sugerencia desde este modulo.
                </div>
                <div class="repo-step <?= ((int)($listaResumen['items'] ?? 0) > 0) ? 'is-done' : '' ?>">
                    <strong>3.</strong> Revisa la lista y exporta la compra.
                </div>
            </div>

            <div class="repo-hero-actions">
                <form method="post" class="repo-inline-form">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="accion" value="generar_reposicion">
                    <button type="submit" class="btn btn-primary">Generar reposicion sugerida</button>
                </form>
                <a href="?v=lista" class="btn btn-ghost">Ver lista de compras</a>
                <?php if ($canConfig): ?>
                    <a href="?v=config" class="btn btn-ghost">Configurar reglas</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="repo-kpi-grid">
            <article class="repo-kpi-card">
                <span class="repo-kpi-label">Cobertura</span>
                <strong class="repo-kpi-value"><?= number_format($coberturaConfig, 1, ',', '.') ?>%</strong>
                <small><?= number_format($productosConfigurados, 0, ',', '.') ?> / <?= number_format((int)($resumenModulo['total_activos'] ?? 0), 0, ',', '.') ?> productos con reglas</small>
            </article>
            <article class="repo-kpi-card">
                <span class="repo-kpi-label">Pendientes</span>
                <strong class="repo-kpi-value"><?= number_format($productosPendientes, 0, ',', '.') ?></strong>
                <small><?= number_format($productosSinRegla, 0, ',', '.') ?> sin minimo ni reorden</small>
            </article>
            <article class="repo-kpi-card">
                <span class="repo-kpi-label">Proveedor</span>
                <strong class="repo-kpi-value"><?= number_format($productosConProveedor, 0, ',', '.') ?></strong>
                <small><?= number_format($productosSinProveedor, 0, ',', '.') ?> aun sin proveedor</small>
            </article>
            <article class="repo-kpi-card">
                <span class="repo-kpi-label">Demanda reciente</span>
                <strong class="repo-kpi-value"><?= number_format(count($sugerenciasVentas), 0, ',', '.') ?></strong>
                <small>Sugerencias por ventas de los ultimos <?= $ventasDias ?> dias</small>
            </article>
        </div>
    </section>

    <?php if ($productosConfigurados === 0 && $canConfig): ?>
        <div class="repo-callout repo-callout-warning">
            Todavia no hay reglas de reposicion configuradas. Empieza por la pestana <strong>Configuracion</strong> para que la sugerencia sea realmente util.
        </div>
    <?php elseif ($productosPendientes > 0 && $canConfig): ?>
        <div class="repo-callout repo-callout-info">
            Hay <strong><?= number_format($productosPendientes, 0, ',', '.') ?> productos</strong> que aun no tienen reglas suficientes. Puedes generar la reposicion igual, pero conviene completarlos para mejorar la sugerencia.
        </div>
    <?php endif; ?>

    <!-- Stats Pills -->
    <div class="stats-row">
        <div class="stat-pill">
            <div class="stat-pill-icon danger">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="15" y1="9" x2="9" y2="15"/>
                    <line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
            </div>
            <div>
                <div class="stat-pill-value"><?= $conteoEstados['sin_stock'] ?? 0 ?></div>
                <div class="stat-pill-label">Sin Stock</div>
            </div>
        </div>

        <div class="stat-pill">
            <div class="stat-pill-icon warning">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            </div>
            <div>
                <div class="stat-pill-value"><?= $conteoEstados['bajo_minimo'] ?? 0 ?></div>
                <div class="stat-pill-label">Bajo Minimo</div>
            </div>
        </div>

        <div class="stat-pill">
            <div class="stat-pill-icon success">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
            </div>
            <div>
                <div class="stat-pill-value"><?= $conteoEstados['stock_ok'] ?? 0 ?></div>
                <div class="stat-pill-label">Stock OK</div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="repo-tabs">
        <a href="?v=alertas" class="repo-tab <?= $vista === 'alertas' ? 'active' : '' ?>">
            <svg class="icon repo-tab-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                <line x1="12" y1="9" x2="12" y2="13"/>
                <line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            Alertas
            <?php
            $totalAlertas = (int)($conteoEstados['total_alertas'] ?? 0);
            if ($totalAlertas > 0):
            ?>
                <span class="badge danger"><?= $totalAlertas ?></span>
            <?php endif; ?>
        </a>

        <a href="?v=lista" class="repo-tab <?= $vista === 'lista' ? 'active' : '' ?>">
            <svg class="icon repo-tab-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="8" y1="6" x2="21" y2="6"/>
                <line x1="8" y1="12" x2="21" y2="12"/>
                <line x1="8" y1="18" x2="21" y2="18"/>
                <line x1="3" y1="6" x2="3.01" y2="6"/>
                <line x1="3" y1="12" x2="3.01" y2="12"/>
                <line x1="3" y1="18" x2="3.01" y2="18"/>
            </svg>
            Lista de Compras
        </a>

        <?php if ($canConfig): ?>
        <a href="?v=config" class="repo-tab <?= $vista === 'config' ? 'active' : '' ?>">
            <svg class="icon repo-tab-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="3"/>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
            </svg>
            Configuracion
        </a>
        <?php endif; ?>
    </div>

    <?php if ($vista === 'alertas'): ?>
        <!-- Alertas de stock -->
        <?php if (empty($stockBajo)): ?>
            <div class="repo-empty repo-empty--lg">
                <svg class="repo-empty-icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                <p class="repo-empty-text">No hay productos con alertas por reglas de stock</p>
                <p class="repo-empty-hint">Si esperabas una sugerencia, usa el boton Generar reposicion sugerida o revisa la configuracion minima y de reorden.</p>
            </div>
        <?php else: ?>
            <?php foreach ($stockBajo as $producto): ?>
            <div class="alert-card">
                <div class="alert-icon <?= strtolower(str_replace('_', '-', $producto['estado'])) ?>">
                    <?php if ($producto['estado'] === 'SIN_STOCK'): ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="15" y1="9" x2="9" y2="15"/>
                            <line x1="9" y1="9" x2="15" y2="15"/>
                        </svg>
                    <?php elseif ($producto['estado'] === 'BAJO_MINIMO'): ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                            <line x1="12" y1="9" x2="12" y2="13"/>
                            <line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                    <?php else: ?>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                    <?php endif; ?>
                </div>

                <div class="alert-info">
                    <h4><?= h($producto['codigo']) ?> - <?= h($producto['nombre']) ?></h4>
                    <p>
                        <?= h($producto['proveedor_nombre'] ?? 'Sin proveedor') ?>
                        | Minimo: <?= number_format((float)($producto['stock_minimo'] ?? 0), 0) ?>
                    </p>
                </div>

                <div class="alert-stock">
                    <div class="alert-stock-value <?= $producto['estado'] === 'SIN_STOCK' ? 'danger' : 'warning' ?>">
                        <?= number_format((float)$producto['stock'], 0) ?>
                    </div>
                    <div class="alert-stock-label">Stock Actual</div>
                </div>

                <div class="alert-action">
                    <div class="sugerido">
                        Pedir: <?= number_format((float)($producto['cantidad_sugerida'] ?? 0), 0) ?>
                    </div>
                    <?php if (!empty($producto['costo'])): ?>
                        <div class="repo-cost-muted">
                            ~$<?= number_format((float)$producto['cantidad_sugerida'] * (float)$producto['costo'], 2) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($sugerenciasVentas)): ?>
            <section class="repo-section">
                <div class="repo-section-head">
                    <div>
                        <h3>Sugerencias por ventas recientes</h3>
                        <p>Productos con salida en los ultimos <?= $ventasDias ?> dias que conviene revisar aunque todavia no esten en alerta dura.</p>
                    </div>
                    <?php if ($canConfig): ?>
                        <a href="?v=config" class="btn btn-ghost">Ajustar reglas</a>
                    <?php endif; ?>
                </div>

                <div class="repo-sales-grid">
                    <?php foreach ($sugerenciasVentas as $sugerencia): ?>
                        <?php
                            $codigoBusqueda = trim((string)($sugerencia['codigo'] ?? ''));
                            if ($codigoBusqueda === '') {
                                $codigoBusqueda = trim((string)($sugerencia['nombre'] ?? ''));
                            }
                        ?>
                        <article class="repo-sales-card">
                            <div class="repo-sales-card-head">
                                <div>
                                    <h4><?= h((string)($sugerencia['codigo'] ?? '')) ?> - <?= h((string)($sugerencia['nombre'] ?? '')) ?></h4>
                                    <p><?= h((string)($sugerencia['proveedor_nombre'] ?? 'Sin proveedor')) ?></p>
                                </div>
                                <span class="badge badge-info">Vendidas: <?= number_format((float)($sugerencia['vendidas'] ?? 0), 0, ',', '.') ?></span>
                            </div>
                            <div class="repo-sales-metrics">
                                <div>
                                    <span>Stock</span>
                                    <strong><?= number_format((float)($sugerencia['stock'] ?? 0), 0, ',', '.') ?></strong>
                                </div>
                                <div>
                                    <span>Sugerida</span>
                                    <strong><?= number_format((float)($sugerencia['cantidad_sugerida'] ?? 0), 0, ',', '.') ?></strong>
                                </div>
                                <div>
                                    <span>Costo estimado</span>
                                    <strong>$<?= number_format((float)($sugerencia['cantidad_sugerida'] ?? 0) * (float)($sugerencia['costo'] ?? 0), 2, ',', '.') ?></strong>
                                </div>
                            </div>
                            <?php if ($canConfig): ?>
                                <div class="repo-sales-actions">
                                    <a href="?v=config&q=<?= urlencode($codigoBusqueda) ?>" class="btn btn-ghost">Configurar producto</a>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

    <?php elseif ($vista === 'lista'): ?>
        <!-- Lista de compras por proveedor -->
        <?php if (empty($listaPorProveedor)): ?>
            <div class="repo-empty repo-empty--lg">
                <p class="repo-empty-text">No hay productos que necesiten reposicion por reglas cargadas</p>
                <p class="repo-empty-hint">Si quieres forzar una revision, usa Generar reposicion sugerida o revisa las sugerencias por ventas recientes.</p>
            </div>
        <?php else: ?>
            <div class="repo-pager-info">
                <strong><?= number_format((int)($listaResumen['grupos'] ?? 0), 0, ',', '.') ?></strong> proveedores,
                <strong><?= number_format((int)($listaResumen['items'] ?? 0), 0, ',', '.') ?></strong> productos sugeridos
                y costo estimado total de <strong>$<?= number_format((float)($listaResumen['costo_total'] ?? 0), 2, ',', '.') ?></strong>.
            </div>
            <?php foreach ($listaPorProveedor as $grupo): ?>
            <?php
                // Asegurar items (por compat: a veces puede venir como 'items' o 'productos')
                $items = $grupo['items'] ?? $grupo['productos'] ?? [];
                if (!is_array($items)) $items = [];

                // Nombre proveedor (varios nombres posibles)
                $proveedorNombre = (string)($grupo['proveedor']
                    ?? $grupo['proveedor_nombre']
                    ?? $grupo['nombre']
                    ?? 'Sin proveedor'
                );

                // Total items: usar el que venga, sino contar
                $totalItems = (int)($grupo['total_items'] ?? $grupo['cantidad'] ?? count($items));

                // Costo total: usar el que venga, sino sumar items
                $costoTotal = $grupo['costo_total'] ?? $grupo['total'] ?? null;
                if ($costoTotal === null) {
                    $sum = 0.0;
                    foreach ($items as $it) {
                        $sum += (float)($it['costo_estimado']
                            ?? ((float)($it['costo'] ?? 0) * (float)($it['cantidad_sugerida'] ?? 0))
                            ?? 0
                        );
                    }
                    $costoTotal = $sum;
                }
                $costoTotal = (float)$costoTotal;
            ?>

            <div class="proveedor-section">
                <div class="proveedor-header">
                    <div class="proveedor-name">
                        <?= h($proveedorNombre) ?>
                    </div>
                    <div class="proveedor-stats">
                        <span><strong><?= $totalItems ?></strong> productos</span>
                        <span>Costo estimado: <strong>$<?= number_format($costoTotal, 2, ',', '.') ?></strong></span>
                    </div>
                </div>

                <div class="proveedor-table">
                    <table class="table repo-table">
                        <thead>
                            <tr>
                                <th>Codigo</th>
                                <th>Producto</th>
                                <th class="t-right">Stock</th>
                                <th class="t-right">Minimo</th>
                                <th class="t-right">Cantidad</th>
                                <th class="t-right">Costo Unit.</th>
                                <th class="t-right">Costo Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                            <?php
                                $stock = (float)($item['stock'] ?? 0);
                                $min   = (float)($item['stock_minimo'] ?? 0);
                                $cant  = (float)($item['cantidad_sugerida'] ?? 0);
                                $costo = (float)($item['costo'] ?? 0);
                                $costoEst = (float)($item['costo_estimado'] ?? ($costo * $cant));
                            ?>
                            <tr>
                                <td><code><?= h((string)($item['codigo'] ?? '')) ?></code></td>
                                <td><?= h((string)($item['nombre'] ?? '')) ?></td>
                                <td class="t-right">
                                    <span class="<?= $stock <= 0 ? 'text-danger' : '' ?>">
                                        <?= number_format($stock, 0, ',', '.') ?>
                                    </span>
                                </td>
                                <td class="t-right"><?= number_format($min, 0, ',', '.') ?></td>
                                <td class="t-right"><strong><?= number_format($cant, 0, ',', '.') ?></strong></td>
                                <td class="t-right">$<?= number_format($costo, 2, ',', '.') ?></td>
                                <td class="t-right">$<?= number_format($costoEst, 2, ',', '.') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ((float)($listaResumen['costo_total'] ?? 0) > 0): ?>
            <div class="repo-global-total">
                <div class="repo-global-total-label">Costo estimado total de la reposicion sugerida</div>
                <div class="repo-global-total-value">$<?= number_format((float)($listaResumen['costo_total'] ?? 0), 2, ',', '.') ?></div>
            </div>
        <?php endif; ?>

    <?php elseif ($vista === 'config'): ?>
        <!-- Configuracion de stock minimo/maximo -->
        <div class="repo-searchbar">
            <form method="get">
                <input type="hidden" name="v" value="config">
                <div class="repo-search-row">
                    <input
                        type="text"
                        name="q"
                        value="<?= h($_GET['q'] ?? '') ?>"
                        class="form-control repo-search-input"
                        placeholder="Buscar por codigo o nombre (min. 2 letras)..."
                    >

                    <select name="proveedor_id" class="form-control repo-search-select">
                        <option value="" <?= (!isset($_GET['proveedor_id']) || $_GET['proveedor_id'] === '') ? 'selected' : '' ?>>Todos los proveedores</option>
                        <option value="0" <?= (isset($_GET['proveedor_id']) && (string)$_GET['proveedor_id'] === '0') ? 'selected' : '' ?>>Sin proveedor</option>
                        <?php foreach ($proveedores as $pr): ?>
                            <option value="<?= (int)$pr['id'] ?>" <?= (isset($_GET['proveedor_id']) && (int)$_GET['proveedor_id'] === (int)$pr['id']) ? 'selected' : '' ?>>
                                <?= h($pr['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="btn btn-ghost">Buscar</button>
                </div>
                <div class="repo-search-hint">
                    Tip: filtra por proveedor o busca por codigo/nombre y despues aplica cambios en lote. Vacio = no cambia, 0 = limpia minimo/reorden/maximo.
                </div>
            </form>
        </div>

        <?php
          $lenBuscar = function_exists('mb_strlen') ? mb_strlen($buscar) : strlen($buscar);
          $tieneBusquedaValida = ($buscar !== '' && $lenBuscar >= 2);
          $tieneFiltroProveedor = ($proveedorFiltro !== null);
          $proveedorFiltroNombre = 'todos los proveedores';
          if ($proveedorFiltro === 0) {
              $proveedorFiltroNombre = 'productos sin proveedor';
          } elseif ($proveedorFiltro !== null) {
              foreach ($proveedores as $proveedorItem) {
                  if ((int)$proveedorItem['id'] === (int)$proveedorFiltro) {
                      $proveedorFiltroNombre = (string)$proveedorItem['nombre'];
                      break;
                  }
              }
          }
        ?>

        <?php if (!$tieneBusquedaValida && !$tieneFiltroProveedor): ?>
            <div class="repo-empty repo-empty--md">
                <p class="repo-empty-text">Busca o filtra para empezar a configurar.</p>
                <p class="repo-empty-hint">Puedes trabajar por proveedor y aplicar las mismas reglas a varios productos de una sola vez.</p>
            </div>
        <?php elseif (empty($productos)): ?>
            <div class="repo-empty repo-empty--md">
                <p class="repo-empty-text">No se encontraron productos con esos filtros.</p>
            </div>
        <?php else: ?>
            <div class="repo-pager-info">
                Mostrando <strong><?= number_format((($page - 1) * $limit) + 1, 0, ',', '.') ?></strong>-<strong><?= number_format(min($page * $limit, $totalProductos), 0, ',', '.') ?></strong>
                de <strong><?= number_format($totalProductos, 0, ',', '.') ?></strong>
                en <strong><?= h($proveedorFiltroNombre) ?></strong>
            </div>

            <div class="repo-bulk-panel">
                <div class="repo-bulk-head">
                    <div>
                        <h3>Edicion masiva</h3>
                        <p>Selecciona varios productos y aplica los mismos parametros sin guardar uno por uno.</p>
                    </div>
                    <div class="repo-bulk-selection">
                        <button type="button" class="btn btn-ghost btn-sm" data-bulk-select-all>Seleccionar pagina</button>
                        <button type="button" class="btn btn-ghost btn-sm" data-bulk-clear>Limpiar</button>
                        <span class="repo-bulk-counter"><strong data-bulk-count>0</strong> seleccionados</span>
                    </div>
                </div>

                <form method="post" id="bulk-config-form" class="repo-bulk-form">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="accion" value="guardar_config_lote">

                    <div class="repo-bulk-grid">
                        <div class="form-group">
                            <label>Stock minimo</label>
                            <input type="number" name="bulk_stock_minimo" min="0" step="1" class="form-control" placeholder="No cambiar">
                        </div>

                        <div class="form-group">
                            <label>Punto de reorden</label>
                            <input type="number" name="bulk_punto_reorden" min="0" step="1" class="form-control" placeholder="No cambiar">
                        </div>

                        <div class="form-group">
                            <label>Stock maximo</label>
                            <input type="number" name="bulk_stock_maximo" min="0" step="1" class="form-control" placeholder="No cambiar">
                        </div>

                        <div class="form-group">
                            <label>Proveedor</label>
                            <select name="bulk_proveedor_id" class="form-control">
                                <option value="">No cambiar</option>
                                <option value="0">Sin proveedor</option>
                                <?php foreach ($proveedores as $pr): ?>
                                    <option value="<?= (int)$pr['id'] ?>"><?= h($pr['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="repo-bulk-foot">
                        <p class="repo-bulk-hint">Deja vacio para no tocar el valor actual. Usa 0 para limpiar una regla numerica o elige "Sin proveedor" para quitarlo.</p>
                        <button type="submit" class="btn btn-primary" data-bulk-submit disabled>Aplicar en lote</button>
                    </div>
                </form>
            </div>

            <?php foreach ($productos as $p): ?>
            <div class="config-card" data-config-card>
                <div class="config-header">
                    <label class="config-select">
                        <input
                            type="checkbox"
                            class="repo-bulk-checkbox"
                            name="producto_ids[]"
                            value="<?= (int)$p['id'] ?>"
                            form="bulk-config-form"
                        >
                        <span>Seleccionar</span>
                    </label>

                    <div class="config-product">
                        <h4><?= h($p['codigo']) ?> - <?= h($p['nombre']) ?></h4>
                        <p>
                            Stock actual: <strong><?= number_format((float)$p['stock'], 0) ?></strong>
                            | <?= h($p['proveedor_nombre']) ?>
                        </p>
                    </div>
                </div>

                <form method="post" class="config-form">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="accion" value="guardar_config">
                    <input type="hidden" name="producto_id" value="<?= (int)$p['id'] ?>">

                    <div class="form-group">
                        <label>Stock minimo</label>
                        <input type="number" name="stock_minimo" value="<?= (float)($p['stock_minimo'] ?? 0) ?>" min="0" step="1" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>Punto de reorden</label>
                        <input type="number" name="punto_reorden" value="<?= (float)($p['punto_reorden'] ?? 0) ?>" min="0" step="1" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>Stock maximo</label>
                        <input type="number" name="stock_maximo" value="<?= (float)($p['stock_maximo'] ?? 0) ?>" min="0" step="1" class="form-control">
                    </div>

                    <div class="form-group">
                        <label>Proveedor</label>
                        <select name="proveedor_id" class="form-control">
                            <option value="0" <?= empty($p['proveedor_id_efectivo']) ? 'selected' : '' ?>>Sin proveedor</option>
                            <?php foreach ($proveedores as $pr): ?>
                                <option value="<?= (int)$pr['id'] ?>" <?= ((int)($p['proveedor_id_efectivo'] ?? 0) === (int)$pr['id']) ? 'selected' : '' ?>>
                                    <?= h($pr['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group repo-form-actions">
                        <button type="submit" class="btn btn-sm btn-primary">Guardar solo este</button>
                    </div>
                </form>
            </div>
            <?php endforeach; ?>

            <div class="repo-pager">
                <?php
                  $qs = $_GET;
                  $qs['v'] = 'config';
                ?>
                <?php if ($page > 1): ?>
                    <?php $qs['page'] = $page - 1; ?>
                    <a class="btn btn-ghost" href="?<?= http_build_query($qs) ?>">Anterior</a>
                <?php endif; ?>
                <?php if (($page * $limit) < $totalProductos): ?>
                    <?php $qs['page'] = $page + 1; ?>
                    <a class="btn btn-ghost" href="?<?= http_build_query($qs) ?>">Siguiente</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

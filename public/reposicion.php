<?php
// public/reposicion.php - Reposición Sugerida FLUS
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

if (!user_has_permission('ver_reportes') && !user_has_permission('ver_stock') && !user_has_permission('editar_stock')) {
    http_response_code(403);
    echo 'No tenés permisos para acceder a esta sección.';
    exit;
}

require_once __DIR__ . '/../src/reposicion_sugerida.php';

// Asegurar tablas existen
$pdo = getPDO();
reposicion_ensure_tables($pdo);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle = 'Reposición Sugerida - FLUS';
$currentSection = 'reposicion';
$extraCss = [];
$extraJs = [];

$info = null;
$error = null;

// Vista actual
$vista = (string)($_GET['v'] ?? 'alertas'); // alertas | lista | config

// Manejo de acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Token CSRF inválido.';
    } else {
        $accion = (string)($_POST['accion'] ?? '');
        
        if ($accion === 'guardar_config' && user_has_permission('gestionar_stock')) {
            $productoId = (int)($_POST['producto_id'] ?? 0);
            $stockMin = (float)($_POST['stock_minimo'] ?? 0);
            $stockMax = (float)($_POST['stock_maximo'] ?? 0);
            $puntoReorden = (float)($_POST['punto_reorden'] ?? 0);
            
            if ($productoId > 0) {
                if (reposicion_set_config($productoId, $stockMin, $stockMax, $puntoReorden)) {
                    $info = 'Configuración guardada.';
                } else {
                    $error = 'Error al guardar configuración.';
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

// Para alertas: productos con stock bajo
$stockBajo = [];
if ($vista === 'alertas') {
    $stockBajo = reposicion_get_stock_bajo();

    // Fallback: si el conteo dice que hay alertas pero la lista vino vacía,
    // armamos la lista desde productos para que no quede inconsistente.
    $hayAlertas = (int)($conteoEstados['sin_stock'] ?? 0) > 0 || (int)($conteoEstados['bajo_minimo'] ?? 0) > 0;

    if ($hayAlertas && empty($stockBajo)) {
        $sql = "
            SELECT
                p.id,
                p.codigo,
                p.nombre,
                p.stock,
                p.stock_minimo,
                p.costo,
                COALESCE(pr.nombre, 'Sin proveedor') AS proveedor_nombre
            FROM productos p
            LEFT JOIN proveedores pr ON p.proveedor_id = pr.id
            WHERE p.activo = 1
              AND (
                    p.stock <= 0
                 OR (p.stock_minimo IS NOT NULL AND p.stock_minimo > 0 AND p.stock < p.stock_minimo)
              )
            ORDER BY (p.stock <= 0) DESC, p.stock ASC, p.nombre ASC
            LIMIT 300
        ";

        $stmt = $pdo->query($sql);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $stockBajo = array_map(static function(array $r): array {
            $stock = (float)($r['stock'] ?? 0);
            $min   = (float)($r['stock_minimo'] ?? 0);

            $estado = 'REORDEN';
            if ($stock <= 0) $estado = 'SIN_STOCK';
            elseif ($min > 0 && $stock < $min) $estado = 'BAJO_MINIMO';

            // Cantidad sugerida simple (para no dejarlo en null)
            $cant = 0.0;
            if ($min > 0) {
                $cant = max($min - $stock, 0);
            } elseif ($stock <= 0) {
                $cant = 1; // mínimo 1 si está en cero y no hay mínimo definido
            }

            $r['estado'] = $estado;
            $r['cantidad_sugerida'] = $cant;

            return $r;
        }, $rows);
    }
}

// Para lista: lista completa por proveedor
$listaPorProveedor = [];
if ($vista === 'lista') {
    $listaPorProveedor = reposicion_lista_por_proveedor();
}

// Para config: lista de productos para configurar
$productos = [];
if ($vista === 'config') {
    $buscar = trim((string)($_GET['q'] ?? ''));
    $query = "SELECT p.id, p.codigo, p.nombre, p.stock, p.stock_minimo, p.costo,
                     COALESCE(pr.nombre, 'Sin proveedor') as proveedor_nombre
              FROM productos p
              LEFT JOIN proveedores pr ON p.proveedor_id = pr.id
              WHERE p.activo = 1";
    $params = [];
    
    if ($buscar) {
        $query .= " AND (p.codigo LIKE :q OR p.nombre LIKE :q)";
        $params['q'] = "%$buscar%";
    }
    
    $query .= " ORDER BY p.nombre LIMIT 100";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Cargar proveedores para filtro
$proveedores = $pdo->query("SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/partials/header.php';
?>

<style>
.repo-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    border-bottom: 2px solid var(--border-color, #e5e7eb);
}
.repo-tab {
    padding: 0.75rem 1.25rem;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--text-muted);
    cursor: pointer;
    text-decoration: none;
    transition: all 0.2s;
}
.repo-tab:hover { color: var(--primary); }
.repo-tab.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
}
.repo-tab .badge {
    margin-left: 0.5rem;
    padding: 0.125rem 0.5rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
}
.repo-tab .badge.danger { background: #fee2e2; color: #991b1b; }
.repo-tab .badge.warning { background: #fef3c7; color: #92400e; }

.stats-row {
    display: flex;
    gap: 1rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}
.stat-pill {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 1.25rem;
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color);
    border-radius: 9999px;
}
.stat-pill-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.stat-pill-icon.danger { background: #fee2e2; color: #dc2626; }
.stat-pill-icon.warning { background: #fef3c7; color: #d97706; }
.stat-pill-icon.success { background: #d1fae5; color: #059669; }
.stat-pill-value {
    font-size: 1.25rem;
    font-weight: 700;
}
.stat-pill-label {
    font-size: 0.75rem;
    color: var(--text-muted);
}

.alert-card {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1rem;
    margin-bottom: 0.75rem;
    display: grid;
    grid-template-columns: auto 1fr auto auto;
    gap: 1rem;
    align-items: center;
}
.alert-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.alert-icon.sin-stock { background: #fee2e2; color: #dc2626; }
.alert-icon.bajo-minimo { background: #fef3c7; color: #d97706; }
.alert-icon.reorden { background: #dbeafe; color: #2563eb; }
.alert-info h4 {
    margin: 0;
    font-size: 0.9375rem;
}
.alert-info p {
    margin: 0.25rem 0 0;
    font-size: 0.8125rem;
    color: var(--text-muted);
}
.alert-stock {
    text-align: center;
}
.alert-stock-value {
    font-size: 1.5rem;
    font-weight: 700;
}
.alert-stock-value.danger { color: #dc2626; }
.alert-stock-value.warning { color: #d97706; }
.alert-stock-label {
    font-size: 0.75rem;
    color: var(--text-muted);
}
.alert-action {
    text-align: right;
}
.sugerido {
    font-size: 0.875rem;
    color: var(--primary);
    font-weight: 600;
}

.proveedor-section {
    margin-bottom: 2rem;
}
.proveedor-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    background: var(--bg-light);
    border-radius: 12px 12px 0 0;
    border: 1px solid var(--border-color);
    border-bottom: none;
}
.proveedor-name {
    font-weight: 600;
    font-size: 1rem;
}
.proveedor-stats {
    display: flex;
    gap: 1.5rem;
    font-size: 0.875rem;
}
.proveedor-table {
    border: 1px solid var(--border-color);
    border-radius: 0 0 12px 12px;
    overflow: hidden;
}

.config-card {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 1.25rem;
    margin-bottom: 1rem;
}
.config-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
}
.config-product h4 {
    margin: 0;
    font-size: 0.9375rem;
}
.config-product p {
    margin: 0.25rem 0 0;
    font-size: 0.8125rem;
    color: var(--text-muted);
}
.config-form {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
}
.config-form .form-group {
    margin: 0;
}
.config-form label {
    font-size: 0.75rem;
    color: var(--text-muted);
}
.config-form input {
    padding: 0.5rem;
    font-size: 0.875rem;
}
</style>

<div class="panel">
    <div class="panel-head">
        <div>
            <h1>Reposición Sugerida</h1>
            <p class="panel-subtitle">Alertas de stock bajo y lista de compras</p>
        </div>
        <?php if ($vista === 'lista'): ?>
        <form method="post" style="display: inline;">
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
        <?php endif; ?>
    </div>

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
                <div class="stat-pill-label">Bajo Mínimo</div>
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
            <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 0.5rem; vertical-align: -2px;">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                <line x1="12" y1="9" x2="12" y2="13"/>
                <line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            Alertas
            <?php 
            $totalAlertas = ($conteoEstados['sin_stock'] ?? 0) + ($conteoEstados['bajo_minimo'] ?? 0);
            if ($totalAlertas > 0): 
            ?>
                <span class="badge danger"><?= $totalAlertas ?></span>
            <?php endif; ?>
        </a>
        <a href="?v=lista" class="repo-tab <?= $vista === 'lista' ? 'active' : '' ?>">
            <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 0.5rem; vertical-align: -2px;">
                <line x1="8" y1="6" x2="21" y2="6"/>
                <line x1="8" y1="12" x2="21" y2="12"/>
                <line x1="8" y1="18" x2="21" y2="18"/>
                <line x1="3" y1="6" x2="3.01" y2="6"/>
                <line x1="3" y1="12" x2="3.01" y2="12"/>
                <line x1="3" y1="18" x2="3.01" y2="18"/>
            </svg>
            Lista de Compras
        </a>
        <?php if (user_has_permission('gestionar_stock')): ?>
        <a href="?v=config" class="repo-tab <?= $vista === 'config' ? 'active' : '' ?>">
            <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 0.5rem; vertical-align: -2px;">
                <circle cx="12" cy="12" r="3"/>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
            </svg>
            Configuración
        </a>
        <?php endif; ?>
    </div>

    <?php if ($vista === 'alertas'): ?>
    <!-- Alertas de stock -->
    <?php if (empty($stockBajo)): ?>
        <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 1rem; opacity: 0.5;">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
            <p style="margin: 0;">¡Excelente! No hay productos con stock bajo</p>
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
                    • Mínimo: <?= number_format((float)($producto['stock_minimo'] ?? 0), 0) ?>
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
                <div style="font-size: 0.75rem; color: var(--text-muted);">
                    ~$<?= number_format((float)$producto['cantidad_sugerida'] * (float)$producto['costo'], 2) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php elseif ($vista === 'lista'): ?>
        <!-- Lista de compras por proveedor -->
        <?php if (empty($listaPorProveedor)): ?>
            <div style="text-align: center; padding: 3rem; color: var(--text-muted);">
                <p>No hay productos que necesiten reposición</p>
            </div>
        <?php else: ?>
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
                    <table class="table" style="margin: 0;">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Producto</th>
                                <th class="t-right">Stock</th>
                                <th class="t-right">Mínimo</th>
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

        <?php elseif ($vista === 'config'): ?>


    <?php elseif ($vista === 'config'): ?>
    <!-- Configuración de stock mínimo/máximo -->
    <div style="margin-bottom: 1.5rem;">
        <form method="get">
            <input type="hidden" name="v" value="config">
            <div style="display: flex; gap: 0.5rem;">
                <input type="text" name="q" value="<?= h($_GET['q'] ?? '') ?>" class="form-control" placeholder="Buscar productos..." style="flex: 1; max-width: 400px;">
                <button type="submit" class="btn btn-ghost">Buscar</button>
            </div>
        </form>
    </div>

    <?php if (empty($productos)): ?>
        <div style="text-align: center; padding: 2rem; color: var(--text-muted);">
            <?= !empty($_GET['q']) ? 'No se encontraron productos' : 'Ingresá un término de búsqueda para configurar productos' ?>
        </div>
    <?php else: ?>
        <?php foreach ($productos as $p): 
            $config = reposicion_get_config($p['id']);
        ?>
        <div class="config-card">
            <div class="config-header">
                <div class="config-product">
                    <h4><?= h($p['codigo']) ?> - <?= h($p['nombre']) ?></h4>
                    <p>
                        Stock actual: <strong><?= number_format((float)$p['stock'], 0) ?></strong>
                        • <?= h($p['proveedor_nombre']) ?>
                    </p>
                </div>
            </div>
            
            <form method="post" class="config-form">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="accion" value="guardar_config">
                <input type="hidden" name="producto_id" value="<?= $p['id'] ?>">
                
                <div class="form-group">
                    <label>Stock Mínimo</label>
                    <input type="number" name="stock_minimo" value="<?= (float)($config['stock_minimo'] ?? $p['stock_minimo'] ?? 0) ?>" min="0" step="1" class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Punto de Reorden</label>
                    <input type="number" name="punto_reorden" value="<?= (float)($config['punto_reorden'] ?? 0) ?>" min="0" step="1" class="form-control">
                </div>
                
                <div class="form-group">
                    <label>Stock Máximo</label>
                    <input type="number" name="stock_maximo" value="<?= (float)($config['stock_maximo'] ?? 0) ?>" min="0" step="1" class="form-control">
                </div>
                
                <div class="form-group" style="display: flex; align-items: flex-end;">
                    <button type="submit" class="btn btn-sm btn-primary">Guardar</button>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php
// public/rol_permisos.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('administrar_usuarios');

/* ============================================================
   OBTENER ROL
============================================================ */
$roleId = (int)($_GET['id'] ?? 0);
if ($roleId <= 0) {
    http_response_code(400);
    flus_abort(400, 'ID de rol inválido.');
}

$stmt = $pdo->prepare("SELECT id, nombre, slug FROM roles WHERE id = ? LIMIT 1");
$stmt->execute([$roleId]);
$role = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$role) {
    http_response_code(404);
    flus_abort(404, 'Rol no encontrado.');
}

/* ============================================================
   FLASH MESSAGES
============================================================ */
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

/* ============================================================
   PROCESAR FORMULARIO (POST)
============================================================ */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? null);
    if (!csrf_verify($csrf)) {
        $_SESSION['flash_error'] = 'CSRF inválido. Recargá la página e intentá de nuevo.';
        header('Location: rol_permisos.php?id=' . $roleId);
        exit;
    }

    $selected = $_POST['perms'] ?? [];
    if (!is_array($selected)) $selected = [];

    // Normalizar a ints y filtrar basura
    $permIds = array_values(
        array_unique(
            array_filter(array_map('intval', $selected), fn(int $v) => $v > 0)
        )
    );

    try {
        $pdo->beginTransaction();

        $del = $pdo->prepare("DELETE FROM role_permission WHERE role_id = ?");
        $del->execute([$roleId]);

        if ($permIds) {
            $ins = $pdo->prepare("INSERT INTO role_permission (role_id, permission_id) VALUES (?, ?)");
            foreach ($permIds as $pid) {
                $ins->execute([$roleId, $pid]);
            }
        }

        $pdo->commit();

        $_SESSION['flash_success'] = 'Permisos actualizados correctamente.';
        header('Location: rol_permisos.php?id=' . $roleId);
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_error'] = 'Error al guardar permisos: ' . $e->getMessage();
        header('Location: rol_permisos.php?id=' . $roleId);
        exit;
    }
}

/* ============================================================
   OBTENER PERMISOS CON AGRUPACIÓN MEJORADA
============================================================ */
$st = $pdo->prepare("
    SELECT
        p.id,
        p.nombre,
        p.slug,
        (rp.permission_id IS NOT NULL) AS enabled
    FROM permissions p
    LEFT JOIN role_permission rp
        ON rp.permission_id = p.id AND rp.role_id = :rid
    ORDER BY p.slug ASC
");
$st->execute(['rid' => $roleId]);
$perms = $st->fetchAll(PDO::FETCH_ASSOC);

// Mapeo de categorías con iconos y nombres amigables
$categoryConfig = [
    'auditoria' => ['name' => 'Auditoría', 'icon' => '📋', 'desc' => 'Ver registros de actividad del sistema'],
    'backups' => ['name' => 'Backups', 'icon' => '💾', 'desc' => 'Gestionar copias de seguridad'],
    'caja' => ['name' => 'Caja', 'icon' => '💰', 'desc' => 'Apertura, cierre y movimientos de caja'],
    'clientes' => ['name' => 'Clientes', 'icon' => '👥', 'desc' => 'Gestión de clientes y cuenta corriente'],
    'config' => ['name' => 'Configuración', 'icon' => '⚙️', 'desc' => 'Ajustes generales del sistema'],
    'costos' => ['name' => 'Costos', 'icon' => '📊', 'desc' => 'Visualización de costos y márgenes'],
    'facturacion' => ['name' => 'Facturación', 'icon' => '🧾', 'desc' => 'Emisión y gestión de comprobantes'],
    'historial' => ['name' => 'Historial', 'icon' => '📜', 'desc' => 'Acceso a registros históricos'],
    'movimientos' => ['name' => 'Movimientos', 'icon' => '🔄', 'desc' => 'Ver movimientos de stock'],
    'productos' => ['name' => 'Productos', 'icon' => '📦', 'desc' => 'Gestión del catálogo de productos'],
    'reportes' => ['name' => 'Reportes', 'icon' => '📈', 'desc' => 'Acceso a informes y estadísticas'],
    'stock' => ['name' => 'Stock', 'icon' => '🏪', 'desc' => 'Control de inventario'],
    'usuarios' => ['name' => 'Usuarios', 'icon' => '🔐', 'desc' => 'Administración de usuarios y roles'],
    'venta' => ['name' => 'Venta', 'icon' => '🛒', 'desc' => 'Gestión de ventas'],
    'ventas' => ['name' => 'Ventas', 'icon' => '🛒', 'desc' => 'Realizar y gestionar ventas'],
    'general' => ['name' => 'General', 'icon' => '⚡', 'desc' => 'Permisos generales'],
];

// Agrupar permisos por categoría
$permisosAgrupados = [];
foreach ($perms as $p) {
    // Extraer categoría del slug (buscar la segunda parte)
    $parts = explode('_', $p['slug']);
    $rawCategory = count($parts) > 1 ? strtolower($parts[1]) : 'general';
    
    // Normalizar algunas categorías
    if (in_array($rawCategory, ['productos', 'producto'])) $rawCategory = 'productos';
    if (in_array($rawCategory, ['venta', 'ventas'])) $rawCategory = 'ventas';
    
    if (!isset($permisosAgrupados[$rawCategory])) {
        $permisosAgrupados[$rawCategory] = [];
    }
    
    $permisosAgrupados[$rawCategory][] = $p;
}

// Ordenar categorías
ksort($permisosAgrupados);

// Contar usuarios afectados
$stmtUsers = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id = ? AND activo = 1");
$stmtUsers->execute([$roleId]);
$usuariosActivos = (int)$stmtUsers->fetchColumn();

// Calcular estadísticas
$totalPermisos = count($perms);
$permisosActivos = array_reduce($perms, fn($c, $p) => $c + (int)$p['enabled'], 0);
$porcentaje = $totalPermisos > 0 ? round(($permisosActivos / $totalPermisos) * 100) : 0;

/* ============================================================
   CONFIG PARA HEADER
============================================================ */
$pageTitle = 'Permisos: ' . $role['nombre'];
$currentSection = 'roles';
$extraCss = ['assets/css/roles.css?v=3.0'];
$extraJs = ['assets/js/rol_permisos.js?v=3.0'];

require __DIR__ . '/partials/header.php';
?>

<div class="roles-page permisos-page">
    <div class="roles-panel">
        
        <!-- HEADER -->
        <header class="page-header">
            <div class="page-header-left">
                <nav class="breadcrumb">
                    <a href="roles.php" class="breadcrumb-link">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 12H5M12 19l-7-7 7-7"/>
                        </svg>
                        Roles
                    </a>
                    <svg class="breadcrumb-sep" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="9 18 15 12 9 6"/>
                    </svg>
                    <span class="breadcrumb-current">Permisos</span>
                </nav>
                <h1 class="page-title">Permisos del Rol</h1>
                <div class="page-meta">
                    <span class="role-badge">
                        <strong><?= h($role['nombre']) ?></strong>
                        <code><?= h($role['slug']) ?></code>
                    </span>
                    <?php if ($usuariosActivos > 0): ?>
                        <span class="users-badge">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/>
                            </svg>
                            <?= $usuariosActivos ?> usuario<?= $usuariosActivos !== 1 ? 's' : '' ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="page-actions">
                <a href="roles.php" class="btn btn-ghost">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M19 12H5M12 19l-7-7 7-7"/>
                    </svg>
                    Volver
                </a>
            </div>
        </header>

        <!-- ALERTAS -->
        <?php if ($flashSuccess): ?>
            <div class="alert alert-success">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                <?= h($flashSuccess) ?>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div class="alert alert-error">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
                <?= h($flashError) ?>
            </div>
        <?php endif; ?>

        <?php if ($usuariosActivos > 0): ?>
            <div class="alert alert-warning">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <span><strong>Atención:</strong> Los cambios afectarán a <?= $usuariosActivos ?> usuario<?= $usuariosActivos !== 1 ? 's' : '' ?> activo<?= $usuariosActivos !== 1 ? 's' : '' ?> con este rol.</span>
            </div>
        <?php endif; ?>

        <!-- BARRA DE HERRAMIENTAS -->
        <section class="permisos-toolbar">
            <div class="toolbar-left">
                <div class="search-wrap">
                    <svg class="search-icon" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                    </svg>
                    <input 
                        type="text" 
                        id="permisosSearch" 
                        class="search-input"
                        placeholder="Buscar permisos..."
                    >
                </div>
            </div>
            <div class="toolbar-right">
                <button type="button" class="btn btn-ghost btn-sm" onclick="selectAll()" title="Marcar todos (Ctrl+A)">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    <span class="btn-text">Marcar todos</span>
                </button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="deselectAll()" title="Desmarcar todos">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <rect x="3" y="3" width="18" height="18" rx="2"/>
                    </svg>
                    <span class="btn-text">Desmarcar</span>
                </button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="expandAll()" title="Abrir categorías (Ctrl+E)">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <polyline points="18 15 12 9 6 15"/>
                    </svg>
                    <span class="btn-text">Abrir</span>
                </button>
                <button type="button" class="btn btn-ghost btn-sm" onclick="collapseAll()" title="Cerrar categorías (Ctrl+Q)">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                    <span class="btn-text">Cerrar</span>
                </button>
            </div>
        </section>

        <!-- FORMULARIO DE PERMISOS -->
        <form method="post" id="permisosForm">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

            <div class="permisos-container">
                <?php foreach ($permisosAgrupados as $categoria => $permisosCategoria): ?>
                    <?php 
                        $catConfig = $categoryConfig[$categoria] ?? ['name' => ucfirst($categoria), 'icon' => '📁', 'desc' => ''];
                        $catChecked = array_reduce($permisosCategoria, fn($c, $p) => $c + (int)$p['enabled'], 0);
                    ?>
                    
                    <section class="permisos-category" data-categoria="<?= h($categoria) ?>">
                        <header class="category-header" onclick="toggleCategory(this)">
                            <div class="category-info">
                                <span class="category-icon"><?= $catConfig['icon'] ?></span>
                                <div class="category-text">
                                    <h3 class="category-name"><?= h($catConfig['name']) ?></h3>
                                    <p class="category-desc"><?= h($catConfig['desc']) ?></p>
                                </div>
                            </div>
                            <div class="category-meta">
                                <span class="category-count">
                                    <span class="count-selected"><?= $catChecked ?></span> / <?= count($permisosCategoria) ?>
                                </span>
                                <svg class="category-arrow" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="6 9 12 15 18 9"/>
                                </svg>
                            </div>
                        </header>

                        <div class="category-body">
                            <div class="permisos-grid">
                                <?php foreach ($permisosCategoria as $p): ?>
                                    <label class="permiso-item <?= (int)$p['enabled'] ? 'is-active' : '' ?>" 
                                           data-permiso="<?= h(strtolower($p['nombre'] . ' ' . $p['slug'])) ?>">
                                        <input type="checkbox"
                                               name="perms[]"
                                               value="<?= (int)$p['id'] ?>"
                                               class="permiso-check"
                                               <?= ((int)$p['enabled'] === 1) ? 'checked' : '' ?>
                                               onchange="updateCounts()">
                                        <span class="permiso-indicator">
                                            <svg class="check-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                                <polyline points="20 6 9 17 4 12"/>
                                            </svg>
                                        </span>
                                        <span class="permiso-text">
                                            <span class="permiso-nombre"><?= h($p['nombre']) ?></span>
                                            <code class="permiso-slug"><?= h($p['slug']) ?></code>
                                        </span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>

                <?php endforeach; ?>

                <?php if (empty($permisosAgrupados)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">🔑</div>
                        <h3>No hay permisos disponibles</h3>
                        <p>No se encontraron permisos configurados en el sistema.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- FOOTER FIJO -->
            <footer class="permisos-footer">
                <div class="footer-info">
                    <span class="footer-stat">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        <span id="totalSelected"><?= $permisosActivos ?></span> permiso<?= $permisosActivos !== 1 ? 's' : '' ?> seleccionado<?= $permisosActivos !== 1 ? 's' : '' ?>
                    </span>
                    <span class="footer-divider">•</span>
                    <span class="footer-stat" id="porcentajeText"><?= $porcentaje ?>% del total</span>
                </div>
                <div class="footer-actions">
                    <a href="roles.php" class="btn btn-ghost">Cancelar</a>
                    <button type="submit" class="btn btn-primary">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                            <polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
                        </svg>
                        Guardar permisos
                    </button>
                </div>
            </footer>

        </form>

    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php
// public/rol_permisos.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('administrar_usuarios');

// Asegurar sesión
if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* ============================================================
   OBTENER ROL
============================================================ */
$roleId = (int)($_GET['id'] ?? 0);
if ($roleId <= 0) {
    http_response_code(400);
    die('ID de rol inválido.');
}

$stmt = $pdo->prepare("SELECT id, nombre, slug FROM roles WHERE id = ? LIMIT 1");
$stmt->execute([$roleId]);
$role = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$role) {
    http_response_code(404);
    die('Rol no encontrado.');
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
   OBTENER PERMISOS CON AGRUPACIÓN
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

// Agrupar permisos por prefijo del slug
$permisosAgrupados = [];
foreach ($perms as $p) {
    // Extraer categoría del slug (ej: "ver_productos" -> "productos")
    $parts = explode('_', $p['slug']);
    $categoria = count($parts) > 1 ? ucfirst($parts[1]) : 'General';
    
    if (!isset($permisosAgrupados[$categoria])) {
        $permisosAgrupados[$categoria] = [];
    }
    
    $permisosAgrupados[$categoria][] = $p;
}

// Ordenar categorías
ksort($permisosAgrupados);

// Contar usuarios afectados
$stmtUsers = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id = ? AND activo = 1");
$stmtUsers->execute([$roleId]);
$usuariosActivos = (int)$stmtUsers->fetchColumn();

/* ============================================================
   CONFIG PARA HEADER
============================================================ */
$pageTitle = 'Permisos del rol';
$currentSection = 'roles';
$extraCss = ['assets/css/roles.css?v=1'];
$extraJs = ['assets/js/rol_permisos.js?v=1'];

require __DIR__ . '/partials/header.php';
?>

<div class="panel roles-panel">

    <header class="roles-header">
        <div class="roles-header-left">
            <div class="breadcrumb">
                <a href="roles.php" class="breadcrumb-link">Roles</a>
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="9 18 15 12 9 6"/>
                </svg>
                <span class="breadcrumb-current">Permisos</span>
            </div>
            <h1 class="page-title">Permisos del Rol</h1>
            <p class="page-sub">
                <strong><?= h($role['nombre']) ?></strong>
                <span class="slug-badge"><?= h($role['slug']) ?></span>
                <?php if ($usuariosActivos > 0): ?>
                    <span class="info-badge">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                            <circle cx="9" cy="7" r="4"/>
                        </svg>
                        <?= $usuariosActivos ?> usuario<?= $usuariosActivos !== 1 ? 's' : '' ?> activo<?= $usuariosActivos !== 1 ? 's' : '' ?>
                    </span>
                <?php endif; ?>
            </p>
        </div>

        <div class="roles-actions">
            <a href="roles.php" class="v-btn v-btn--ghost">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M19 12H5M12 19l-7-7 7-7"/>
                </svg>
                Volver
            </a>
        </div>
    </header>

    <?php if ($flashSuccess): ?>
        <div class="alert alert-success">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
            <?= h($flashSuccess) ?>
        </div>
    <?php endif; ?>

    <?php if ($flashError): ?>
        <div class="alert alert-error">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
            </svg>
            <?= h($flashError) ?>
        </div>
    <?php endif; ?>

    <!-- ADVERTENCIA SI AFECTA USUARIOS -->
    <?php if ($usuariosActivos > 0): ?>
        <div class="alert alert-warning">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            <div>
                <strong>Atención:</strong> Los cambios afectarán a <?= $usuariosActivos ?> usuario<?= $usuariosActivos !== 1 ? 's' : '' ?> activo<?= $usuariosActivos !== 1 ? 's' : '' ?> con este rol.
            </div>
        </div>
    <?php endif; ?>

    <!-- BÚSQUEDA Y ACCIONES RÁPIDAS -->
    <section class="permisos-toolbar">
        <div class="search-input-wrap">
            <svg class="search-icon" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
            </svg>
            <input 
                type="text" 
                id="permisosSearch" 
                placeholder="Buscar permisos..."
                class="filter-input"
            >
        </div>

        <div class="toolbar-actions">
            <button type="button" class="v-btn v-btn--ghost" onclick="selectAll()">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                Seleccionar todos
            </button>
            <button type="button" class="v-btn v-btn--ghost" onclick="deselectAll()">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                </svg>
                Deseleccionar todos
            </button>
        </div>
    </section>

    <!-- FORMULARIO DE PERMISOS -->
    <form method="post" id="permisosForm">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">

        <?php foreach ($permisosAgrupados as $categoria => $permisosCategoria): ?>
            
            <div class="permisos-section" data-categoria="<?= h(strtolower($categoria)) ?>">
                
                <div class="permisos-section-header">
                    <h3 class="permisos-section-title">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
                        </svg>
                        <?= h($categoria) ?>
                    </h3>
                    <span class="permisos-count">
                        <span class="permisos-count-selected">0</span> / <?= count($permisosCategoria) ?>
                    </span>
                </div>

                <div class="permisos-grid">
                    <?php foreach ($permisosCategoria as $p): ?>
                        <label class="permiso-card" data-permiso-id="<?= (int)$p['id'] ?>">
                            <input
                                type="checkbox"
                                name="perms[]"
                                value="<?= (int)$p['id'] ?>"
                                class="permiso-checkbox"
                                <?= ((int)$p['enabled'] === 1) ? 'checked' : '' ?>
                                onchange="updateCategoryCount(this)"
                            >
                            <div class="permiso-content">
                                <div class="permiso-icon">
                                    <?php if ((int)$p['enabled'] === 1): ?>
                                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                            <polyline points="20 6 9 17 4 12"/>
                                        </svg>
                                    <?php else: ?>
                                        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <div class="permiso-info">
                                    <div class="permiso-nombre"><?= h($p['nombre']) ?></div>
                                    <div class="permiso-slug"><?= h($p['slug']) ?></div>
                                </div>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>

            </div>

        <?php endforeach; ?>

        <!-- NO HAY PERMISOS -->
        <?php if (empty($permisosAgrupados)): ?>
            <div class="permisos-empty">
                <svg class="empty-icon" width="64" height="64" fill="none" stroke="currentColor" stroke-width="1.5">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
                <h3>No hay permisos disponibles</h3>
                <p>No se encontraron permisos en el sistema.</p>
            </div>
        <?php endif; ?>

        <!-- FOOTER CON BOTONES -->
        <div class="form-footer">
            <div class="form-footer-info">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>
                </svg>
                <span id="permisosSelectedCount">0</span> permiso(s) seleccionado(s)
            </div>
            <div class="form-footer-actions">
                <a href="roles.php" class="v-btn v-btn--ghost">Cancelar</a>
                <button type="submit" class="v-btn v-btn--primary">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                        <polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
                    </svg>
                    Guardar permisos
                </button>
            </div>
        </div>

    </form>

</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
<?php
// public/roles.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('administrar_usuarios');
if (session_status() === PHP_SESSION_NONE) session_start();

// Flash messages
$flashSuccess = (string)($_SESSION['flash_success'] ?? '');
$flashError = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

/* ============================================================
   FILTROS Y BÚSQUEDA
============================================================ */
$search = trim($_GET['search'] ?? '');

/* ============================================================
   CONSULTA DE ROLES CON ESTADÍSTICAS
============================================================ */
$whereClauses = [];
$params = [];

if ($search !== '') {
    $whereClauses[] = "(r.nombre LIKE :search OR r.slug LIKE :search)";
    $params[':search'] = "%{$search}%";
}

$whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

try {
    $sql = "
        SELECT
            r.id,
            r.nombre,
            r.slug,
            (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.activo = 1) AS usuarios_activos,
            (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS usuarios_totales,
            (SELECT COUNT(*) FROM role_permission rp WHERE rp.role_id = r.id) AS permisos_asignados,
            (SELECT COUNT(*) FROM permissions) AS permisos_totales
        FROM roles r
        {$whereSQL}
        ORDER BY r.id ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Obtener total de permisos disponibles
    $totalPermisos = $pdo->query("SELECT COUNT(*) FROM permissions")->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Error al cargar roles: " . $e->getMessage());
    $flashError = "Error al cargar roles. Por favor, intente nuevamente.";
    $roles = [];
    $totalPermisos = 0;
}

/**
 * Determinar si un rol es crítico (no se puede eliminar)
 */
function isRoleCritico(string $slug): bool {
    return in_array(strtolower($slug), ['administrador', 'admin', 'superadmin']);
}

/**
 * Calcular porcentaje de permisos asignados
 */
function getPermisosPercentage(int $asignados, int $totales): int {
    if ($totales === 0) return 0;
    return (int)round(($asignados / $totales) * 100);
}

/* ============================================================
   CONFIG PARA HEADER
============================================================ */
$pageTitle = 'Roles';
$currentSection = 'roles';
$extraCss = ['assets/css/roles.css?v=1'];
$extraJs = ['assets/js/roles.js?v=1'];

require __DIR__ . '/partials/header.php';
?>

<div class="panel roles-panel">

    <header class="roles-header">
        <div class="roles-header-left">
            <h1 class="page-title">Roles y Permisos</h1>
            <p class="page-sub">
                <?= count($roles) ?> rol<?= count($roles) !== 1 ? 'es' : '' ?> del sistema
            </p>
        </div>

        <div class="roles-actions">
            <a href="rol_nuevo.php" class="v-btn v-btn--primary">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
                Nuevo rol
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

    <!-- BARRA DE BÚSQUEDA -->
    <section class="roles-filters">
        <form method="get" action="roles.php" class="filters-form">
            <div class="filter-group filter-search">
                <div class="search-input-wrap">
                    <svg class="search-icon" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                    </svg>
                    <input 
                        type="text" 
                        id="search" 
                        name="search" 
                        placeholder="Buscar rol por nombre o slug..."
                        value="<?= h($search) ?>"
                        class="filter-input"
                    >
                    <?php if ($search): ?>
                        <button type="button" class="clear-search" onclick="document.getElementById('search').value=''; this.closest('form').submit();">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($search): ?>
                <a href="roles.php" class="filter-clear" title="Limpiar filtros">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
                    </svg>
                    Limpiar
                </a>
            <?php endif; ?>
        </form>
    </section>

    <!-- GRID DE ROLES -->
    <section class="roles-grid">
        <?php if (empty($roles)): ?>
            
            <div class="roles-empty">
                <svg class="empty-icon" width="64" height="64" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M17 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/>
                    <circle cx="12" cy="7" r="4"/>
                    <path d="M17 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/>
                    <circle cx="20" cy="7" r="4"/>
                </svg>
                <h3>No se encontraron roles</h3>
                <p>
                    <?php if ($search): ?>
                        Intenta ajustar los filtros de búsqueda.
                    <?php else: ?>
                        Comienza creando tu primer rol.
                    <?php endif; ?>
                </p>
            </div>

        <?php else: ?>

            <?php foreach ($roles as $rol): ?>
                <?php 
                    $isCritico = isRoleCritico($rol['slug']);
                    $permisosPercentage = getPermisosPercentage(
                        (int)$rol['permisos_asignados'], 
                        (int)$rol['permisos_totales']
                    );
                ?>
                
                <div class="role-card <?= $isCritico ? 'role-card--critico' : '' ?>">
                    
                    <!-- Header del card -->
                    <div class="role-card-header">
                        <div class="role-icon">
                            <?php if ($isCritico): ?>
                                <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/>
                                </svg>
                            <?php else: ?>
                                <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                    <circle cx="9" cy="7" r="4"/>
                                    <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
                                </svg>
                            <?php endif; ?>
                        </div>
                        
                        <div class="role-info">
                            <h3 class="role-name">
                                <?= h($rol['nombre']) ?>
                                <?php if ($isCritico): ?>
                                    <span class="badge-critico" title="Rol crítico del sistema">
                                        <svg width="14" height="14" fill="currentColor">
                                            <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                                        </svg>
                                    </span>
                                <?php endif; ?>
                            </h3>
                            <p class="role-slug"><?= h($rol['slug']) ?></p>
                        </div>
                    </div>

                    <!-- Estadísticas -->
                    <div class="role-stats">
                        <div class="stat-item">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/>
                            </svg>
                            <div class="stat-content">
                                <span class="stat-value"><?= (int)$rol['usuarios_activos'] ?></span>
                                <span class="stat-label">
                                    <?php if ((int)$rol['usuarios_totales'] !== (int)$rol['usuarios_activos']): ?>
                                        de <?= (int)$rol['usuarios_totales'] ?>
                                    <?php endif; ?>
                                    usuario<?= (int)$rol['usuarios_totales'] !== 1 ? 's' : '' ?>
                                </span>
                            </div>
                        </div>

                        <div class="stat-item">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                            <div class="stat-content">
                                <span class="stat-value"><?= (int)$rol['permisos_asignados'] ?></span>
                                <span class="stat-label">permiso<?= (int)$rol['permisos_asignados'] !== 1 ? 's' : '' ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Barra de progreso de permisos -->
                    <div class="role-progress">
                        <div class="progress-bar">
                            <div 
                                class="progress-fill" 
                                style="width: <?= $permisosPercentage ?>%"
                                data-percentage="<?= $permisosPercentage ?>"
                            ></div>
                        </div>
                        <span class="progress-text"><?= $permisosPercentage ?>% de permisos asignados</span>
                    </div>

                    <!-- Acciones -->
                    <div class="role-actions">
                        <a 
                            href="rol_permisos.php?id=<?= (int)$rol['id'] ?>" 
                            class="action-btn action-btn--primary"
                            title="Gestionar permisos"
                        >
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                            Permisos
                        </a>

                        <a 
                            href="rol_editar.php?id=<?= (int)$rol['id'] ?>" 
                            class="action-btn action-btn--secondary"
                            title="Editar rol"
                        >
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>
                            </svg>
                            Editar
                        </a>

                        <?php if (!$isCritico): ?>
                            <button 
                                type="button"
                                class="action-btn action-btn--danger"
                                title="Eliminar rol"
                                onclick="confirmDelete(<?= (int)$rol['id'] ?>, '<?= h($rol['nombre']) ?>', <?= (int)$rol['usuarios_totales'] ?>)"
                            >
                                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="3 6 5 6 21 6"/>
                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                </svg>
                            </button>
                        <?php endif; ?>
                    </div>

                </div>
            <?php endforeach; ?>

        <?php endif; ?>
    </section>

</div>

<!-- MODAL DE CONFIRMACIÓN DE ELIMINACIÓN -->
<div id="deleteModal" class="modal" role="dialog" aria-labelledby="deleteModalTitle" aria-hidden="true">
    <div class="modal-overlay" onclick="closeDeleteModal()"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="deleteModalTitle" class="modal-title">Confirmar eliminación</h2>
            <button type="button" class="modal-close" onclick="closeDeleteModal()" aria-label="Cerrar">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </div>
        <div class="modal-body">
            <div class="modal-icon modal-icon--warning">
                <svg width="48" height="48" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            </div>
            <p id="deleteModalMessage"></p>
            <p class="text-muted" id="deleteModalWarning"></p>
        </div>
        <div class="modal-footer">
            <button type="button" class="v-btn v-btn--ghost" onclick="closeDeleteModal()">Cancelar</button>
            <button type="button" id="confirmDeleteBtn" class="v-btn v-btn--danger">Eliminar rol</button>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
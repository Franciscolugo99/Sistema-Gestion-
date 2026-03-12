<?php
// public/roles.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('administrar_usuarios');

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
    // Obtener total de permisos una sola vez
    $totalPermisos = (int)$pdo->query("SELECT COUNT(*) FROM permissions")->fetchColumn();
    
    $sql = "
        SELECT
            r.id,
            r.nombre,
            r.slug,
            (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id AND u.activo = 1) AS usuarios_activos,
            (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS usuarios_totales,
            (SELECT COUNT(*) FROM role_permission rp WHERE rp.role_id = r.id) AS permisos_asignados
        FROM roles r
        {$whereSQL}
        ORDER BY r.id ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Agregar permisos_totales a cada rol (evita subquery repetida)
    foreach ($roles as &$rol) {
        $rol['permisos_totales'] = $totalPermisos;
    }
    unset($rol);
    
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
    return function_exists('flus_is_critical_role')
        ? flus_is_critical_role($slug)
        : in_array(strtolower($slug), ['administrador', 'admin', 'superadmin'], true);
}

/**
 * Calcular porcentaje de permisos asignados
 */
function getPermisosPercentage(int $asignados, int $totales): int {
    if ($totales === 0) return 0;
    return (int)round(($asignados / $totales) * 100);
}

/**
 * Obtener color según porcentaje de permisos
 */
function getProgressColor(int $percentage): string {
    if ($percentage === 100) return 'gold';
    if ($percentage >= 75) return 'green';
    if ($percentage >= 50) return 'cyan';
    if ($percentage >= 25) return 'blue';
    return 'gray';
}

/* ============================================================
   CONFIG PARA HEADER
============================================================ */
$pageTitle = 'Roles y Permisos';
$currentSection = 'roles';
$extraCss = ['assets/css/roles.css?v=3.0'];
$extraJs = ['assets/js/roles.js?v=3.0'];

require __DIR__ . '/partials/header.php';
?>

<div class="roles-page">
    <div class="roles-panel">
        
        <!-- HEADER -->
        <header class="page-header">
            <div class="page-header-left">
                <h1 class="page-title">Roles y Permisos</h1>
                <p class="page-sub"><?= count($roles) ?> rol<?= count($roles) !== 1 ? 'es' : '' ?> del sistema</p>
            </div>
            <div class="page-actions">
                <button type="button" class="btn btn-primary" onclick="openNewRoleDrawer()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    Nuevo rol
                </button>
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

        <!-- FILTROS -->
        <section class="filters">
            <div class="filters-left">
                <input 
                    type="text" 
                    id="roleSearch" 
                    class="search-input"
                    placeholder="Buscar rol por nombre o slug..."
                    value="<?= h($search) ?>"
                    onkeyup="filterRoles(this.value)"
                >
            </div>
            <div class="filters-right">
                <span class="results-count">
                    <strong id="rolesVisibleCount"><?= count($roles) ?></strong> de <?= count($roles) ?>
                </span>
            </div>
        </section>

        <!-- GRID DE ROLES -->
        <section class="roles-grid" id="rolesGrid">
            <?php if (empty($roles)): ?>
                <div class="empty-state">
                    <div class="empty-icon">👥</div>
                    <h3>No hay roles configurados</h3>
                    <p>Crea tu primer rol para comenzar a gestionar permisos.</p>
                    <button type="button" class="btn btn-primary" onclick="openNewRoleDrawer()">
                        Crear primer rol
                    </button>
                </div>
            <?php else: ?>
                <?php foreach ($roles as $rol): ?>
                    <?php 
                        $isCritico = isRoleCritico($rol['slug']);
                        $permisosPercentage = getPermisosPercentage(
                            (int)$rol['permisos_asignados'], 
                            (int)$rol['permisos_totales']
                        );
                        $progressColor = getProgressColor($permisosPercentage);
                    ?>
                    
                    <article class="role-card <?= $isCritico ? 'role-card--admin' : '' ?>" 
                             data-role-id="<?= (int)$rol['id'] ?>"
                             data-role-name="<?= h($rol['nombre']) ?>"
                             data-role-slug="<?= h($rol['slug']) ?>"
                             data-role-users="<?= (int)$rol['usuarios_totales'] ?>"
                             data-role-critical="<?= $isCritico ? '1' : '0' ?>">
                        
                        <div class="role-card-header">
                            <div class="role-icon role-icon--<?= $progressColor ?>">
                                <?php if ($isCritico): ?>
                                    <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
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
                                        <span class="badge badge-gold" title="Rol del sistema">
                                            <svg width="12" height="12" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                            </svg>
                                        </span>
                                    <?php endif; ?>
                                </h3>
                                <code class="role-slug"><?= h($rol['slug']) ?></code>
                            </div>
                        </div>

                        <div class="role-stats">
                            <div class="role-stat">
                                <span class="role-stat-icon">👤</span>
                                <span class="role-stat-value"><?= (int)$rol['usuarios_activos'] ?></span>
                                <span class="role-stat-label">Usuarios</span>
                            </div>
                            <div class="role-stat">
                                <span class="role-stat-icon">🔑</span>
                                <span class="role-stat-value"><?= (int)$rol['permisos_asignados'] ?></span>
                                <span class="role-stat-label">Permisos</span>
                            </div>
                        </div>

                        <div class="role-progress">
                            <div class="progress-bar">
                                <div class="progress-fill progress-fill--<?= $progressColor ?>" 
                                     style="width: <?= $permisosPercentage ?>%"></div>
                            </div>
                            <span class="progress-text"><?= $permisosPercentage ?>% de permisos asignados</span>
                        </div>

                        <div class="role-actions">
                            <a href="rol_permisos.php?id=<?= (int)$rol['id'] ?>" 
                               class="btn btn-primary btn-sm"
                               title="Gestionar permisos">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <rect x="3" y="11" width="18" height="11" rx="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                </svg>
                                Permisos
                            </a>
                            
                            <button type="button" 
                                    class="btn btn-ghost btn-sm btn-edit-role"
                                    title="Editar rol">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                                </svg>
                                Editar
                            </button>

                            <?php if (!$isCritico): ?>
                                <button type="button"
                                        class="btn btn-danger-ghost btn-icon btn-delete-role"
                                        title="Eliminar rol">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <polyline points="3 6 5 6 21 6"/>
                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                                    </svg>
                                </button>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

    </div>
</div>

<!-- DRAWER: NUEVO/EDITAR ROL -->
<div class="drawer-overlay" id="roleDrawerOverlay" onclick="closeRoleDrawer()"></div>
<aside class="drawer" id="roleDrawer">
    <header class="drawer-header">
        <h2 class="drawer-title" id="drawerTitle">Nuevo Rol</h2>
        <button type="button" class="drawer-close" onclick="closeRoleDrawer()" title="Cerrar">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>
    </header>
    
    <div class="drawer-body">
        <form id="roleForm" method="post" action="rol_guardar.php" class="role-form">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="role_id" id="roleId" value="">
            <input type="hidden" name="is_critical" id="isCritical" value="0">
            
            <div class="form-section">
                <h3 class="section-title">Información del rol</h3>
                
                <!-- Aviso para roles críticos -->
                <div class="alert alert-warning" id="criticalRoleAlert" style="display: none; margin-bottom: 1rem;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                        <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                    </svg>
                    <span>Este es un rol crítico del sistema. El slug no puede modificarse.</span>
                </div>
                
                <div class="form-grid">
                    <div class="form-field form-field-wide">
                        <label for="roleName">
                            Nombre <span class="required">*</span>
                        </label>
                        <input type="text" 
                               id="roleName" 
                               name="nombre" 
                               required 
                               placeholder="Ej: Supervisor"
                               maxlength="50"
                               oninput="handleNameInput(this.value)">
                        <span class="field-help">Nombre descriptivo del rol</span>
                    </div>
                    
                    <div class="form-field form-field-wide">
                        <label for="roleSlug">
                            Slug <span class="required">*</span>
                        </label>
                        <input type="text" 
                               id="roleSlug" 
                               name="slug" 
                               required 
                               placeholder="supervisor"
                               maxlength="50"
                               pattern="[a-z0-9_]+"
                               title="Solo letras minúsculas, números y guiones bajos"
                               oninput="markSlugDirty()">
                        <span class="field-help" id="slugHelp">Identificador único (sin espacios ni acentos)</span>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-ghost" onclick="closeRoleDrawer()">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="saveRoleBtn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                        <polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
                    </svg>
                    Guardar rol
                </button>
            </div>
        </form>
    </div>
</aside>

<!-- MODAL: CONFIRMAR ELIMINACIÓN -->
<div class="modal" id="deleteModal" role="dialog" aria-hidden="true">
    <div class="modal-overlay" onclick="closeDeleteModal()"></div>
    <div class="modal-content">
        <header class="modal-header">
            <h2 class="modal-title">Eliminar rol</h2>
            <button type="button" class="modal-close" onclick="closeDeleteModal()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
            </button>
        </header>
        <div class="modal-body">
            <div class="modal-icon modal-icon--danger">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            </div>
            <p id="deleteModalMessage">¿Estás seguro de eliminar este rol?</p>
            <p class="text-muted" id="deleteModalWarning"></p>
        </div>
        <footer class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeDeleteModal()">Cancelar</button>
            <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                </svg>
                Eliminar
            </button>
        </footer>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

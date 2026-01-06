<?php
// public/usuarios.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('administrar_usuarios');
if (session_status() === PHP_SESSION_NONE) session_start();

// Flash
$flashSuccess = (string)($_SESSION['flash_success'] ?? '');
$flashError   = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

/* ============================================================
   CONFIG PARA HEADER
============================================================ */
$pageTitle      = 'Usuarios';
$currentSection = 'usuarios';
$extraCss       = ['assets/css/usuarios.css?v=2'];
$extraJs        = ['assets/js/usuarios.js?v=2'];

require __DIR__ . '/partials/header.php';

/* ============================================================
   PARÁMETROS DE PAGINACIÓN Y FILTROS
============================================================ */
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 15;
$offset   = ($page - 1) * $perPage;

// Filtros
$search   = trim($_GET['search'] ?? '');
$rol      = trim($_GET['rol'] ?? '');
$estado   = trim($_GET['estado'] ?? ''); // 'activo', 'inactivo', ''

/* ============================================================
   CONSTRUIR QUERY CON FILTROS
============================================================ */
$whereClauses = [];
$params = [];

if ($search !== '') {
    $whereClauses[] = "(u.nombre LIKE :search OR u.username LIKE :search OR u.email LIKE :search)";
    $params[':search'] = "%{$search}%";
}

if ($rol !== '') {
    $whereClauses[] = "r.id = :rol_id";
    $params[':rol_id'] = $rol;
}

if ($estado === 'activo') {
    $whereClauses[] = "u.activo = 1";
} elseif ($estado === 'inactivo') {
    $whereClauses[] = "u.activo = 0";
}

$whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

/* ============================================================
   CONSULTA PRINCIPAL (CON ÚLTIMO ACCESO)
============================================================ */
try {
    // Contar total
    $countSql = "
        SELECT COUNT(*) 
        FROM users u
        JOIN roles r ON r.id = u.role_id
        {$whereSQL}
    ";
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $totalUsuarios = (int)$stmtCount->fetchColumn();
    $totalPages = (int)ceil($totalUsuarios / $perPage);

    // Obtener usuarios
    $sql = "
        SELECT 
            u.id,
            u.nombre,
            u.username,
            u.email,
            u.activo,
            u.ultimo_acceso,
            r.id AS rol_id,
            r.nombre AS rol
        FROM users u
        JOIN roles r ON r.id = u.role_id
        {$whereSQL}
        ORDER BY u.id ASC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $pdo->prepare($sql);
    
    // Bind parámetros de filtros
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    // Bind parámetros de paginación
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Obtener roles para el filtro
    $roles = $pdo->query("SELECT id, nombre FROM roles ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error en usuarios.php: " . $e->getMessage());
    $flashError = "Error al cargar usuarios. Por favor, intente nuevamente.";
    $usuarios = [];
    $roles = [];
    $totalUsuarios = 0;
    $totalPages = 0;
}

/**
 * Generar iniciales del nombre
 */
function getInitials(string $nombre): string {
    $words = explode(' ', trim($nombre));
    if (count($words) >= 2) {
        return strtoupper(substr($words[0], 0, 1) . substr($words[1], 0, 1));
    }
    return strtoupper(substr($nombre, 0, 2));
}

/**
 * Formatear última conexión
 */
function formatLastAccess(?string $datetime): string {
    if (!$datetime) return 'Nunca';
    
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) return 'Hace un momento';
    if ($diff < 3600) return 'Hace ' . floor($diff / 60) . ' min';
    if ($diff < 86400) return 'Hace ' . floor($diff / 3600) . ' h';
    if ($diff < 604800) return 'Hace ' . floor($diff / 86400) . ' días';
    
    return date('d/m/Y', $timestamp);
}
?>

<div class="panel usuarios-panel">

  <header class="usuarios-header">
    <div class="usuarios-header-left">
      <h1 class="page-title">Usuarios</h1>
      <p class="page-sub">
        <?= $totalUsuarios ?> usuario<?= $totalUsuarios !== 1 ? 's' : '' ?> registrado<?= $totalUsuarios !== 1 ? 's' : '' ?>
      </p>
    </div>

    <div class="usuarios-actions">
      <a href="usuario_nuevo.php" class="v-btn v-btn--primary">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
        Nuevo usuario
      </a>
    </div>
  </header>

  <?php if ($flashSuccess): ?>
    <div class="alert alert-success">
      <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <?= h($flashSuccess) ?>
    </div>
  <?php endif; ?>

  <?php if ($flashError): ?>
    <div class="alert alert-error">
      <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
      <?= h($flashError) ?>
    </div>
  <?php endif; ?>

  <!-- ===== BARRA DE FILTROS ===== -->
  <section class="usuarios-filters">
    <form method="get" action="usuarios.php" class="filters-form">
      
      <div class="filter-group filter-search">
        <label for="search" class="sr-only">Buscar</label>
        <div class="search-input-wrap">
          <svg class="search-icon" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
          </svg>
          <input 
            type="text" 
            id="search" 
            name="search" 
            placeholder="Buscar por nombre, usuario o email..."
            value="<?= h($search) ?>"
            class="filter-input"
          >
          <?php if ($search): ?>
            <button type="button" class="clear-search" onclick="document.getElementById('search').value=''; this.closest('form').submit();">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
          <?php endif; ?>
        </div>
      </div>

      <div class="filter-group">
        <label for="rol" class="sr-only">Rol</label>
        <select name="rol" id="rol" class="filter-select" onchange="this.form.submit()">
          <option value="">Todos los roles</option>
          <?php foreach ($roles as $r): ?>
            <option value="<?= (int)$r['id'] ?>" <?= $rol == $r['id'] ? 'selected' : '' ?>>
              <?= h($r['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="filter-group">
        <label for="estado" class="sr-only">Estado</label>
        <select name="estado" id="estado" class="filter-select" onchange="this.form.submit()">
          <option value="">Todos los estados</option>
          <option value="activo" <?= $estado === 'activo' ? 'selected' : '' ?>>Activos</option>
          <option value="inactivo" <?= $estado === 'inactivo' ? 'selected' : '' ?>>Inactivos</option>
        </select>
      </div>

      <?php if ($search || $rol || $estado): ?>
        <a href="usuarios.php" class="filter-clear" title="Limpiar filtros">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
          </svg>
          Limpiar
        </a>
      <?php endif; ?>

    </form>
  </section>

  <!-- ===== TABLA ===== -->
  <section class="usuarios-table-wrap">

    <?php if (empty($usuarios)): ?>

      <div class="usuarios-empty">
        <svg class="empty-icon" width="64" height="64" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M17 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"/><circle cx="24" cy="7" r="4"/>
        </svg>
        <h3>No se encontraron usuarios</h3>
        <p>
          <?php if ($search || $rol || $estado): ?>
            Intenta ajustar los filtros de búsqueda.
          <?php else: ?>
            Comienza creando tu primer usuario.
          <?php endif; ?>
        </p>
      </div>

    <?php else: ?>

      <div class="table-responsive">
        <table class="tabla tabla-usuarios">
          <thead>
            <tr>
              <th>Usuario</th>
              <th>Email</th>
              <th>Rol</th>
              <th>Último acceso</th>
              <th>Estado</th>
              <th class="th-actions">Acciones</th>
            </tr>
          </thead>

          <tbody>
            <?php foreach ($usuarios as $u): ?>
              <tr data-user-id="<?= (int)$u['id'] ?>">
                
                <!-- Usuario con avatar -->
                <td class="td-user">
                  <div class="user-cell">
                    <div class="user-avatar" title="<?= h($u['nombre']) ?>">
                      <?= h(getInitials($u['nombre'])) ?>
                    </div>
                    <div class="user-info">
                      <div class="user-name"><?= h($u['nombre']) ?></div>
                      <div class="user-username mono">@<?= h($u['username']) ?></div>
                    </div>
                  </div>
                </td>

                <!-- Email -->
                <td class="td-email">
                  <a href="mailto:<?= h($u['email']) ?>" class="email-link">
                    <?= h($u['email']) ?>
                  </a>
                </td>

                <!-- Rol -->
                <td>
                  <span class="badge-rol"><?= h($u['rol']) ?></span>
                </td>

                <!-- Último acceso -->
                <td class="td-last-access">
                  <span class="last-access" title="<?= h($u['ultimo_acceso'] ?? '') ?>">
                    <?= formatLastAccess($u['ultimo_acceso']) ?>
                  </span>
                </td>

                <!-- Estado -->
                <td>
                  <?php if (!empty($u['activo'])): ?>
                    <span class="badge-estado badge-estado--ok">
                      <span class="badge-dot"></span>
                      Activo
                    </span>
                  <?php else: ?>
                    <span class="badge-estado badge-estado--off">
                      <span class="badge-dot"></span>
                      Inactivo
                    </span>
                  <?php endif; ?>
                </td>

                <!-- Acciones -->
                <td class="td-actions">
                  <div class="action-buttons">
                    
                    <a 
                      href="usuario_editar.php?id=<?= (int)$u['id'] ?>" 
                      class="action-btn action-btn--edit"
                      title="Editar usuario"
                    >
                      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/>
                      </svg>
                    </a>

                    <button 
                      type="button"
                      class="action-btn action-btn--toggle"
                      title="<?= !empty($u['activo']) ? 'Desactivar' : 'Activar' ?> usuario"
                      onclick="toggleUserStatus(<?= (int)$u['id'] ?>, <?= !empty($u['activo']) ? 'true' : 'false' ?>)"
                    >
                      <?php if (!empty($u['activo'])): ?>
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                          <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
                        </svg>
                      <?php else: ?>
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                          <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                        </svg>
                      <?php endif; ?>
                    </button>

                    <button 
                      type="button"
                      class="action-btn action-btn--delete"
                      title="Eliminar usuario"
                      onclick="confirmDelete(<?= (int)$u['id'] ?>, '<?= h($u['nombre']) ?>')"
                    >
                      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                      </svg>
                    </button>

                  </div>
                </td>

              </tr>
            <?php endforeach; ?>
          </tbody>

        </table>
      </div>

      <!-- ===== PAGINACIÓN ===== -->
      <?php if ($totalPages > 1): ?>
        <nav class="pagination" role="navigation" aria-label="Paginación">
          
          <?php
          // Preservar filtros en la paginación
          $queryParams = [];
          if ($search) $queryParams['search'] = $search;
          if ($rol) $queryParams['rol'] = $rol;
          if ($estado) $queryParams['estado'] = $estado;
          
          function buildPageUrl(int $page, array $params): string {
              $params['page'] = $page;
              return 'usuarios.php?' . http_build_query($params);
          }
          ?>

          <div class="pagination-info">
            Mostrando <?= ($offset + 1) ?> - <?= min($offset + $perPage, $totalUsuarios) ?> de <?= $totalUsuarios ?>
          </div>

          <div class="pagination-controls">
            
            <?php if ($page > 1): ?>
              <a href="<?= buildPageUrl(1, $queryParams) ?>" class="pagination-btn" title="Primera página">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                  <polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/>
                </svg>
              </a>
              <a href="<?= buildPageUrl($page - 1, $queryParams) ?>" class="pagination-btn" title="Anterior">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                  <polyline points="15 18 9 12 15 6"/>
                </svg>
              </a>
            <?php else: ?>
              <span class="pagination-btn pagination-btn--disabled">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                  <polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/>
                </svg>
              </span>
              <span class="pagination-btn pagination-btn--disabled">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                  <polyline points="15 18 9 12 15 6"/>
                </svg>
              </span>
            <?php endif; ?>

            <span class="pagination-current">
              Página <?= $page ?> de <?= $totalPages ?>
            </span>

            <?php if ($page < $totalPages): ?>
              <a href="<?= buildPageUrl($page + 1, $queryParams) ?>" class="pagination-btn" title="Siguiente">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                  <polyline points="9 18 15 12 9 6"/>
                </svg>
              </a>
              <a href="<?= buildPageUrl($totalPages, $queryParams) ?>" class="pagination-btn" title="Última página">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                  <polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/>
                </svg>
              </a>
            <?php else: ?>
              <span class="pagination-btn pagination-btn--disabled">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                  <polyline points="9 18 15 12 9 6"/>
                </svg>
              </span>
              <span class="pagination-btn pagination-btn--disabled">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                  <polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/>
                </svg>
              </span>
            <?php endif; ?>

          </div>
        </nav>
      <?php endif; ?>

    <?php endif; ?>

  </section>
</div>

<!-- ===== MODAL DE CONFIRMACIÓN ===== -->
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
      <p class="text-muted">Esta acción no se puede deshacer.</p>
    </div>
    <div class="modal-footer">
      <button type="button" class="v-btn v-btn--ghost" onclick="closeDeleteModal()">Cancelar</button>
      <button type="button" id="confirmDeleteBtn" class="v-btn v-btn--danger">Eliminar usuario</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
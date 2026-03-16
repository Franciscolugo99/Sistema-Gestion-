<?php
// public/usuarios.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('administrar_usuarios');

// Flash
$flashSuccess = (string)($_SESSION['flash_success'] ?? '');
$flashError   = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

/* ============================================================
   CONFIG PARA HEADER
============================================================ */
$pageTitle      = 'Usuarios';
$currentSection = 'usuarios';
$extraCss       = ['assets/css/usuarios.css?v=3'];
$extraJs        = ['assets/js/usuarios.js?v=3'];

require __DIR__ . '/partials/header.php';

/* ============================================================
   PARÁMETROS DE PAGINACIÓN Y FILTROS
============================================================ */
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 15;
$offset   = ($page - 1) * $perPage;

$search   = trim($_GET['search'] ?? '');
$rolId    = (int)($_GET['rol'] ?? 0);
$estado   = trim($_GET['estado'] ?? '');

/* ============================================================
   CONSTRUIR QUERY CON FILTROS
============================================================ */
$whereClauses = [];
$params = [];

if ($search !== '') {
    $whereClauses[] = "(u.nombre LIKE :search OR u.username LIKE :search OR u.email LIKE :search)";
    $params[':search'] = "%{$search}%";
}

if ($rolId > 0) {
    $whereClauses[] = "r.id = :rol_id";
    $params[':rol_id'] = $rolId;
}

if ($estado === 'activo') {
    $whereClauses[] = "u.activo = 1";
} elseif ($estado === 'inactivo') {
    $whereClauses[] = "u.activo = 0";
}

$whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

/* ============================================================
   CONSULTA PRINCIPAL
============================================================ */
try {
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id {$whereSQL}");
    $stmtCount->execute($params);
    $totalUsuarios = (int)$stmtCount->fetchColumn();
    $totalPages = (int)ceil($totalUsuarios / $perPage);

    $sql = "
        SELECT u.id, u.nombre, u.username, u.email, u.activo, u.ultimo_acceso,
               r.id AS rol_id, r.nombre AS rol
        FROM users u
        JOIN roles r ON r.id = u.role_id
        {$whereSQL}
        ORDER BY u.id ASC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) { $stmt->bindValue($key, $value); }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $roles = $pdo->query("SELECT id, nombre FROM roles ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error en usuarios.php: " . $e->getMessage());
    $flashError = "Error al cargar usuarios.";
    $usuarios = [];
    $roles = [];
    $totalUsuarios = 0;
    $totalPages = 0;
}

function getInitials(string $nombre): string {
    $nombre = trim(preg_replace('/\s+/', ' ', $nombre) ?? '');
    if ($nombre === '') return '??';

    $words = explode(' ', $nombre);
    $a = (string)($words[0] ?? '');
    $b = (string)($words[1] ?? '');

    // Multibyte-safe (acentos/Ñ)
    if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
        $letters = (count($words) >= 2 && $a !== '' && $b !== '')
            ? (mb_substr($a, 0, 1, 'UTF-8') . mb_substr($b, 0, 1, 'UTF-8'))
            : mb_substr($nombre, 0, 2, 'UTF-8');
        return mb_strtoupper($letters, 'UTF-8');
    }

    // Fallback ASCII
    return count($words) >= 2
        ? strtoupper(substr($a, 0, 1) . substr($b, 0, 1))
        : strtoupper(substr($nombre, 0, 2));
}

function formatLastAccess(?string $datetime): string {
    if (!$datetime) return 'Nunca';
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Hace un momento';
    if ($diff < 3600)   return 'Hace ' . floor($diff / 60) . ' min';
    if ($diff < 86400)  return 'Hace ' . floor($diff / 3600) . ' h';
    if ($diff < 604800) return 'Hace ' . floor($diff / 86400) . ' días';
    return date('d/m/Y', strtotime($datetime));
}
?>

<div class="panel usuarios-panel">

  <header class="usuarios-header page-header module-header">
    <div class="usuarios-header-left page-header-main module-header-main">
      <div class="module-header-hero">
        <span class="module-header-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
            <path d="M20 21a8 8 0 0 0-16 0"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
        </span>
        <div class="module-header-copy">
          <span class="page-eyebrow module-eyebrow">Accesos del sistema</span>
          <h1 class="page-title">Usuarios</h1>
          <p class="page-sub">
            <?= $totalUsuarios ?> usuario<?= $totalUsuarios !== 1 ? 's' : '' ?> registrado<?= $totalUsuarios !== 1 ? 's' : '' ?>
          </p>
        </div>
      </div>
    </div>
    <div class="usuarios-actions module-header-actions">
      <a href="usuario_nuevo.php" class="v-btn v-btn--primary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M12 5v14M5 12h14"/>
        </svg>
        Nuevo usuario
      </a>
    </div>
  </header>

  <?php if ($flashSuccess): ?>
    <div class="alert alert-success">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M20 6 9 17l-5-5"/>
      </svg>
      <?= h($flashSuccess) ?>
    </div>
  <?php endif; ?>

  <?php if ($flashError): ?>
    <div class="alert alert-error">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/>
      </svg>
      <?= h($flashError) ?>
    </div>
  <?php endif; ?>

  <!-- ===== FILTROS ===== -->
  <section class="usuarios-filters">
    <form method="get" action="usuarios.php" class="filters-form">
      <div class="filter-group filter-search">
        <div class="search-input-wrap">
          <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
          </svg>
          <input type="text" id="search" name="search"
            placeholder="Buscar por nombre, usuario o email..."
            value="<?= h($search) ?>" class="filter-input">
          <?php if ($search): ?>
            <button type="button" class="clear-search"
              onclick="document.getElementById('search').value=''; this.closest('form').submit();">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
          <?php endif; ?>
        </div>
      </div>

      <div class="filter-group">
        <select name="rol" id="rol" class="filter-select" onchange="this.form.submit()">
          <option value="">Todos los roles</option>
          <?php foreach ($roles as $r): ?>
            <option value="<?= (int)$r['id'] ?>" <?= $rolId === (int)$r['id'] ? 'selected' : '' ?>>
              <?= h($r['nombre']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="filter-group">
        <select name="estado" id="estado" class="filter-select" onchange="this.form.submit()">
          <option value="">Todos los estados</option>
          <option value="activo"   <?= $estado === 'activo'   ? 'selected' : '' ?>>Activos</option>
          <option value="inactivo" <?= $estado === 'inactivo' ? 'selected' : '' ?>>Inactivos</option>
        </select>
      </div>

      <?php if ($search || $rolId > 0 || $estado): ?>
        <a href="usuarios.php" class="filter-clear">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/>
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
        <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
          <path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
        <h3>No se encontraron usuarios</h3>
        <p><?= ($search || $rolId > 0 || $estado) ? 'Intentá ajustar los filtros.' : 'Comenzá creando el primer usuario.' ?></p>
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
                    <div class="user-avatar"><?= h(getInitials($u['nombre'])) ?></div>
                    <div class="user-info">
                      <div class="user-name"><?= h($u['nombre']) ?></div>
                      <div class="user-username">@<?= h($u['username']) ?></div>
                    </div>
                  </div>
                </td>

                <!-- Email -->
                <td class="td-email">
                  <a href="mailto:<?= h($u['email']) ?>" class="email-link"><?= h($u['email']) ?></a>
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
                      <span class="badge-dot"></span>Activo
                    </span>
                  <?php else: ?>
                    <span class="badge-estado badge-estado--off">
                      <span class="badge-dot"></span>Inactivo
                    </span>
                  <?php endif; ?>
                </td>

                <!-- Acciones: solo Editar + Activar/Desactivar -->
                <td class="td-actions">
                  <div class="action-buttons">

                    <a href="usuario_editar.php?id=<?= (int)$u['id'] ?>"
                       class="action-btn action-btn--edit"
                       title="Editar usuario">
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/>
                      </svg>
                      <span>Editar</span>
                    </a>

                    <?php
                    $isActive = !empty($u['activo']);
                    $toggleLabel = $isActive ? 'Desactivar' : 'Activar';
                    $toggleClass = $isActive ? 'action-btn--deactivate' : 'action-btn--activate';
                    ?>
                    <button type="button"
                      class="action-btn <?= $toggleClass ?> js-toggle-user"
                      title="<?= $toggleLabel ?> usuario"
                      data-user-id="<?= (int)$u['id'] ?>"
                      data-active="<?= $isActive ? '1' : '0' ?>">
                      <?php if ($isActive): ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                          <path d="M18.36 6.64A9 9 0 0 1 20.77 15M6.16 6.16a9 9 0 1 0 12.68 12.68M2 2l20 20"/>
                          <path d="M9 9v3a3 3 0 0 0 5.12 2.12"/>
                        </svg>
                      <?php else: ?>
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                          <path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Z"/><path d="m9 12 2 2 4-4"/>
                        </svg>
                      <?php endif; ?>
                      <span><?= $toggleLabel ?></span>
                    </button>

                  </div>
                </td>

              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- PAGINACIÓN -->
      <?php if ($totalPages > 1): ?>
        <nav class="pagination">
          <?php
          $qp = [];
          if ($search) $qp['search'] = $search;
          if ($rolId > 0) $qp['rol'] = (string)$rolId;
          if ($estado) $qp['estado'] = $estado;
          function buildPageUrl(int $p, array $q): string {
              $q['page'] = $p;
              return 'usuarios.php?' . http_build_query($q);
          }
          ?>
          <div class="pagination-info">
            Mostrando <?= ($offset + 1) ?>–<?= min($offset + $perPage, $totalUsuarios) ?> de <?= $totalUsuarios ?>
          </div>
          <div class="pagination-controls">
            <?php if ($page > 1): ?>
              <a href="<?= buildPageUrl(1, $qp) ?>" class="pagination-btn" title="Primera">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m11 17-5-5 5-5"/><path d="m18 17-5-5 5-5"/></svg>
              </a>
              <a href="<?= buildPageUrl($page - 1, $qp) ?>" class="pagination-btn" title="Anterior">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
              </a>
            <?php else: ?>
              <span class="pagination-btn pagination-btn--disabled"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m11 17-5-5 5-5"/><path d="m18 17-5-5 5-5"/></svg></span>
              <span class="pagination-btn pagination-btn--disabled"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m15 18-6-6 6-6"/></svg></span>
            <?php endif; ?>
            <span class="pagination-current">Pág. <?= $page ?> / <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
              <a href="<?= buildPageUrl($page + 1, $qp) ?>" class="pagination-btn" title="Siguiente">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m9 18 6-6-6-6"/></svg>
              </a>
              <a href="<?= buildPageUrl($totalPages, $qp) ?>" class="pagination-btn" title="Última">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m6 17 5-5-5-5"/><path d="m13 17 5-5-5-5"/></svg>
              </a>
            <?php else: ?>
              <span class="pagination-btn pagination-btn--disabled"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m9 18 6-6-6-6"/></svg></span>
              <span class="pagination-btn pagination-btn--disabled"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="m6 17 5-5-5-5"/><path d="m13 17 5-5-5-5"/></svg></span>
            <?php endif; ?>
          </div>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>

</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

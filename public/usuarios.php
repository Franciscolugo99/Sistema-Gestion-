<?php
// public/usuarios.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
require_permission('administrar_usuarios');

require_once __DIR__ . '/../src/config.php';
$pdo  = getPDO();
$user = current_user();

require_once __DIR__ . '/lib/helpers.php';

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
$extraCss       = ['assets/css/usuarios.css?v=1'];

require __DIR__ . '/partials/header.php';

/* ============================================================
   CONSULTA DE USUARIOS
============================================================ */
$sql = "
  SELECT 
    u.id,
    u.nombre,
    u.username,
    u.email,
    u.activo,
    r.nombre AS rol
  FROM users u
  JOIN roles r ON r.id = u.role_id
  ORDER BY u.id ASC
";
$usuarios = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="panel usuarios-panel">

  <header class="usuarios-header">
    <div class="usuarios-header-left">
      <h1 class="page-title">Usuarios</h1>
      <p class="page-sub">Administración de accesos — roles, permisos y estado.</p>
    </div>

    <div class="usuarios-actions">
      <a href="usuario_nuevo.php" class="v-btn v-btn--primary">+ Nuevo usuario</a>
    </div>
  </header>

  <?php if ($flashSuccess): ?>
    <div class="alert alert-success"><?= h($flashSuccess) ?></div>
  <?php endif; ?>

  <?php if ($flashError): ?>
    <div class="alert alert-error"><?= h($flashError) ?></div>
  <?php endif; ?>

  <section class="usuarios-table-wrap">

    <?php if (empty($usuarios)): ?>

      <div class="usuarios-empty empty-cell">
        No hay usuarios registrados.
      </div>

    <?php else: ?>

      <div class="table-wrapper">
        <table class="tabla tabla-usuarios">
          <thead>
            <tr>
              <th>ID</th>
              <th>Nombre</th>
              <th>Usuario</th>
              <th>Email</th>
              <th>Rol</th>
              <th>Estado</th>
            </tr>
          </thead>

          <tbody>
            <?php foreach ($usuarios as $u): ?>
              <tr>
                <td class="t-center mono"><?= (int)$u['id'] ?></td>
                <td><?= h($u['nombre'] ?? '') ?></td>
                <td class="mono"><?= h($u['username'] ?? '') ?></td>
                <td><?= h($u['email'] ?? '') ?></td>

                <td>
                  <span class="badge-rol"><?= h($u['rol'] ?? '') ?></span>
                </td>

                <td>
                  <?php if (!empty($u['activo'])): ?>
                    <span class="badge-estado badge-estado--ok">Activo</span>
                  <?php else: ?>
                    <span class="badge-estado badge-estado--off">Inactivo</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>

        </table>
      </div>

    <?php endif; ?>

  </section>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

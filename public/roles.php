<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/auth.php';
require_login();
require_permission('administrar_usuarios');

require_once __DIR__ . '/lib/helpers.php';

$pageTitle      = 'Roles - Sistema Kiosco (FLUS)';
$currentSection = 'roles';
$bodyClass      = 'page-roles';

$pdo = getPDO();

$roles = $pdo->query("
  SELECT
    r.id, r.nombre, r.slug,
    (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS usuarios,
    (SELECT COUNT(*) FROM role_permission rp WHERE rp.role_id = r.id) AS permisos
  FROM roles r
  ORDER BY r.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/partials/header.php';
?>

<div class="panel" style="max-width:1100px;margin:24px auto;padding:22px;">
  <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap;">
    <div>
      <h1 style="margin:0;">ROLES</h1>
      <div style="opacity:.75;margin-top:6px;">Administración de roles y acceso por permisos.</div>
    </div>
  </div>

  <div style="margin-top:18px;overflow:auto;">
    <table class="tabla" style="width:100%;min-width:780px;">
      <thead>
        <tr>
          <th style="text-align:left;">ID</th>
          <th style="text-align:left;">Nombre</th>
          <th style="text-align:left;">Slug</th>
          <th style="text-align:right;">Usuarios</th>
          <th style="text-align:right;">Permisos</th>
          <th style="text-align:left;">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($roles as $r): ?>
          <tr>
            <td><?= (int)$r['id'] ?></td>
            <td><strong><?= h((string)$r['nombre']) ?></strong></td>
            <td><?= h((string)$r['slug']) ?></td>
            <td style="text-align:right;"><?= (int)$r['usuarios'] ?></td>
            <td style="text-align:right;"><?= (int)$r['permisos'] ?></td>
            <td>
              <a class="btn btn-primary" href="rol_permisos.php?id=<?= (int)$r['id'] ?>">
                Permisos
              </a>
            </td>
          </tr>
        <?php endforeach; ?>

        <?php if (!$roles): ?>
          <tr><td colspan="6">No hay roles cargados.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

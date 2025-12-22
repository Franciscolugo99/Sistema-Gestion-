<?php
// public/backups.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
require_permission('gestionar_backups');

require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/../src/backup_lib.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));



$pageTitle = 'Backups - FLUS';
$currentSection = 'configuracion';
$extraCss = ['assets/css/backups.css'];

$info = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals($_SESSION['csrf_token'], $token)) {
    $error = 'Token inválido. Recargá la página e intentá de nuevo.';
  } else {
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'crear') {
      $err = null;
      $file = backup_create($err);
      $info = $file ? ('Backup creado: ' . $file) : ($err ?: 'No se pudo crear el backup.');
      if (!$file) $error = $info;
      if ($file) $info = 'Backup creado: ' . $file;
    }

    if ($accion === 'borrar') {
      $file = (string)($_POST['file'] ?? '');
      $err = null;
      if (backup_delete($file, $err)) {
        $info = 'Backup borrado: ' . basename($file);
      } else {
        $error = $err ?: 'No se pudo borrar el backup.';
      }
    }
  }
}

$items = backup_list();

require __DIR__ . '/partials/header.php';
?>

<div class="panel backups-panel">
  <div class="panel-head">
    <h1>Backups</h1>
    <form method="post" class="bk-actions">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="accion" value="crear">
      <button class="btn btn-primary" type="submit">Crear backup ahora</button>
    </form>
  </div>

  <?php if ($info): ?>
    <div class="alert alert-ok"><?= h($info) ?></div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert alert-err"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="bk-note">
    <strong>Tip:</strong> para automatizar backups en Windows, ejecutá:
    <code>C:\xampp\php\php.exe C:\xampp\htdocs\kiosco\scripts\backup_db.php</code>
  </div>

  <div class="table-wrap">
    <table class="table bk-table">
      <thead>
        <tr>
          <th>Archivo</th>
          <th>Tamaño</th>
          <th>Fecha</th>
          <th class="t-right">Acciones</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$items): ?>
        <tr><td colspan="4" class="muted">No hay backups todavía.</td></tr>
      <?php else: ?>
        <?php foreach ($items as $it): ?>
          <tr>
            <td class="mono"><?= h($it['file']) ?></td>
            <td><?= h(fmt_bytes((int)$it['size'])) ?></td>
            <td><?= h(date('d/m/Y H:i:s', (int)$it['mtime'])) ?></td>
            <td class="t-right">
              <a class="btn btn-ghost" href="backup_download.php?f=<?= urlencode($it['file']) ?>">Descargar</a>

              <form method="post" class="inline" onsubmit="return confirm('¿Borrar este backup?');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="accion" value="borrar">
                <input type="hidden" name="file" value="<?= h($it['file']) ?>">
                <button class="btn btn-danger" type="submit">Borrar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

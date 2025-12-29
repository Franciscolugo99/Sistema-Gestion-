<?php
// public/terminales.php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/terminal.php';
require_login();
require_permission('administrar_config');

// CSRF
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$msg = null;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals($_SESSION['csrf_token'], $token)) {
    $err = 'CSRF inválido.';
  } else {
    $accion = (string)($_POST['accion'] ?? '');
    if ($accion === 'crear') {
      $nombre = trim((string)($_POST['nombre'] ?? ''));
      $codigo = trim((string)($_POST['codigo'] ?? ''));
      if ($nombre === '') {
        $err = 'Nombre requerido.';
      } else {
        $st = $pdo->prepare("INSERT INTO terminales (nombre, codigo, activo, created_at) VALUES (:n, :c, 1, NOW())");
        $st->execute([':n' => $nombre, ':c' => ($codigo !== '' ? $codigo : null)]);
        $msg = 'Terminal creada.';
      }
    } elseif ($accion === 'toggle') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        $pdo->prepare("UPDATE terminales SET activo = IF(activo=1,0,1) WHERE id = ?")->execute([$id]);
        $msg = 'Estado actualizado.';
      }
    }
  }
}

$rows = $pdo->query("SELECT id, nombre, codigo, activo, created_at FROM terminales ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Terminales';
$currentSection = 'config';
$extraCss = [];
$extraJs = [];
require __DIR__ . '/partials/header.php';
?>

<div class="panel">
  <h1 class="page-title">Terminales / Cajas</h1>
  <p class="page-sub">Creá “Caja 1, Caja 2…” y asignalas por PC desde <b>terminal_select.php</b>.</p>

  <?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-error"><?= h($err) ?></div><?php endif; ?>

  <div class="panel" style="margin-top:14px;">
    <h2 style="margin:0 0 10px;">Crear terminal</h2>
    <form method="post" class="row" style="gap:10px; align-items:end;">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
      <input type="hidden" name="accion" value="crear">
      <div class="field" style="flex:1 1 260px;">
        <label>Nombre</label>
        <input type="text" name="nombre" placeholder="Caja 1" required>
      </div>
      <div class="field" style="flex:1 1 220px;">
        <label>Código (opcional)</label>
        <input type="text" name="codigo" placeholder="CAJA-01">
      </div>
      <div>
        <button class="btn btn-primary" type="submit">Crear</button>
      </div>
    </form>
  </div>

  <div class="panel" style="margin-top:14px;">
    <h2 style="margin:0 0 10px;">Listado</h2>
    <table class="table" style="width:100%;">
      <thead>
        <tr>
          <th>ID</th>
          <th>Nombre</th>
          <th>Código</th>
          <th>Estado</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="mono"><?= (int)$r['id'] ?></td>
            <td><?= h((string)$r['nombre']) ?></td>
            <td class="mono"><?= h((string)($r['codigo'] ?? '')) ?></td>
            <td><?= ((int)$r['activo'] === 1) ? 'Activa' : 'Inactiva' ?></td>
            <td class="right">
              <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="accion" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn" type="submit">
                  <?= ((int)$r['activo'] === 1) ? 'Desactivar' : 'Activar' ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/auth.php';

require_login();
require_permission('administrar_usuarios');

$pdo = getPDO();

// Asegurar sesión (para CSRF + flash)
if (function_exists('startSecureSession')) {
  startSecureSession();
} elseif (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/* ==========================
   CSRF (sin redeclare)
========================== */
if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
  }
}

if (!function_exists('csrf_check')) {
  function csrf_check(string $token): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $sess = (string)($_SESSION['csrf_token'] ?? '');
    return $token !== '' && $sess !== '' && hash_equals($sess, $token);
  }
}

/* ==========================
   Page vars
========================== */
$pageTitle      = 'Permisos del rol - Sistema Kiosco (FLUS)';
$currentSection = 'roles';
$bodyClass      = 'page-rol-permisos';

/* ==========================
   Role
========================== */
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

/* ==========================
   Flash (PRG)
========================== */
$info  = $_SESSION['flash_info']  ?? null;
$error = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_info'], $_SESSION['flash_error']);

/* ==========================
   Save
========================== */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $csrf = (string)($_POST['csrf'] ?? '');
  if (!csrf_check($csrf)) {
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

    $_SESSION['flash_info'] = 'Permisos actualizados correctamente.';
    header('Location: rol_permisos.php?id=' . $roleId);
    exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash_error'] = 'Error al guardar permisos: ' . $e->getMessage();
    header('Location: rol_permisos.php?id=' . $roleId);
    exit;
  }
}

/* ==========================
   Perms list
========================== */
$st = $pdo->prepare("
  SELECT
    p.id, p.nombre, p.slug,
    (rp.permission_id IS NOT NULL) AS enabled
  FROM permissions p
  LEFT JOIN role_permission rp
    ON rp.permission_id = p.id AND rp.role_id = :rid
  ORDER BY p.slug ASC
");
$st->execute(['rid' => $roleId]);
$perms = $st->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/partials/header.php';
?>

<div class="panel" style="max-width:1100px;margin:24px auto;padding:22px;">
  <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:14px;flex-wrap:wrap;">
    <div>
      <h1 style="margin:0;">PERMISOS DEL ROL</h1>
      <div style="opacity:.75;margin-top:6px;">
        Rol: <strong><?= h($role['nombre']) ?></strong>
        <span style="opacity:.7;">(<?= h($role['slug']) ?>)</span>
      </div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <a class="btn" href="roles.php">Volver</a>
    </div>
  </div>

  <?php if ($info): ?>
    <div class="msg msg-ok" style="margin-top:14px;"><?= h($info) ?></div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="msg msg-error" style="margin-top:14px;"><?= h($error) ?></div>
  <?php endif; ?>

  <form method="post" style="margin-top:18px;">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;margin-top:12px;">
      <?php foreach ($perms as $p): ?>
        <label class="chk-card" style="display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid var(--panel-border);border-radius:12px;background:var(--panel);">
          <input
            type="checkbox"
            name="perms[]"
            value="<?= (int)$p['id'] ?>"
            <?= ((int)$p['enabled'] === 1) ? 'checked' : '' ?>
            style="margin-top:2px;"
          >
          <div>
            <div style="font-weight:800;"><?= h($p['nombre']) ?></div>
            <div style="opacity:.7;font-size:.92rem;"><?= h($p['slug']) ?></div>
          </div>
        </label>
      <?php endforeach; ?>
    </div>

    <div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;">
      <button class="btn btn-primary" type="submit">Guardar permisos</button>
      <a class="btn" href="roles.php">Cancelar</a>
    </div>
  </form>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

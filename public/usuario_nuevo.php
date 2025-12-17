<?php
// public/usuario_nuevo.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
require_permission('administrar_usuarios');

require_once __DIR__ . '/lib/helpers.php';

require_once __DIR__ . '/../src/config.php';
$pdo = getPDO();

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Flash (por si volvió con error)
$flashError = (string)($_SESSION['flash_error'] ?? '');
unset($_SESSION['flash_error']);

/* --------------------------------------------------------
   Cargar roles disponibles
-------------------------------------------------------- */
$roles = $pdo->query("
  SELECT id, nombre
  FROM roles
  ORDER BY nombre
")->fetchAll(PDO::FETCH_ASSOC);

/* --------------------------------------------------------
   Configuración para header global
-------------------------------------------------------- */
$pageTitle      = "Nuevo usuario";
$currentSection = "usuarios";
$extraCss       = ["assets/css/usuarios.css?v=1"];

require __DIR__ . "/partials/header.php";
?>

<div class="panel usuarios-panel">

  <header class="usuarios-header">
    <div class="usuarios-header-left">
      <h1 class="page-title">Nuevo usuario</h1>
      <p class="page-sub">Completá los datos para crear un nuevo usuario en el sistema.</p>
    </div>

    <div class="usuarios-actions">
      <a href="usuarios.php" class="v-btn v-btn--ghost">← Volver al listado</a>
    </div>
  </header>

  <?php if ($flashError): ?>
    <div class="alert alert-error"><?= h($flashError) ?></div>
  <?php endif; ?>

  <section class="usuarios-form-wrap">
    <form action="usuario_guardar.php" method="post" class="usuarios-form">
      <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

      <div class="form-row">
        <div class="form-field">
          <label for="nombre">Nombre completo</label>
          <input type="text" id="nombre" name="nombre" required>
        </div>

        <div class="form-field">
          <label for="email">Email</label>
          <input type="email" id="email" name="email">
        </div>
      </div>

      <div class="form-row">
        <div class="form-field">
          <label for="username">Usuario</label>
          <input type="text" id="username" name="username" required autocomplete="off">
        </div>

        <div class="form-field">
          <label for="password">Contraseña</label>
          <input type="password" id="password" name="password" required>
        </div>
      </div>

      <div class="form-row">
        <div class="form-field form-field--sm">
          <label for="role_id">Rol</label>
          <select id="role_id" name="role_id" required>
            <?php foreach ($roles as $r): ?>
              <option value="<?= (int)$r['id'] ?>"><?= h($r['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-field form-field--sm">
          <label for="activo">Estado</label>
          <select id="activo" name="activo">
            <option value="1" selected>Activo</option>
            <option value="0">Inactivo</option>
          </select>
        </div>
      </div>

      <div class="usuarios-form-footer">
        <button type="submit" class="v-btn v-btn--primary">Guardar usuario</button>
      </div>

    </form>
  </section>

</div>

<?php require __DIR__ . "/partials/footer.php"; ?>

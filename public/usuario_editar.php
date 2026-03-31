<?php
// public/usuario_editar.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('administrar_usuarios');
require_once __DIR__ . '/lib/csrf.php';

/* ============================================================
   OBTENER ID DEL USUARIO A EDITAR
============================================================ */
$userId = (int)($_GET['id'] ?? 0);

if ($userId <= 0) {
    $_SESSION['flash_error'] = 'ID de usuario inválido';
    header('Location: usuarios.php');
    exit;
}

/* ============================================================
   OBTENER DATOS DEL USUARIO
============================================================ */
try {
    $stmt = $pdo->prepare("SELECT id, nombre, email, username, role_id, activo FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        $_SESSION['flash_error'] = 'Usuario no encontrado';
        header('Location: usuarios.php');
        exit;
    }
} catch (PDOException $e) {
    error_log("Error al cargar usuario: " . $e->getMessage());
    $_SESSION['flash_error'] = 'Error al cargar el usuario';
    header('Location: usuarios.php');
    exit;
}

$esUsuarioResguardo = flus_is_reserved_admin_user($usuario);

/* ============================================================
   PROCESAR FORMULARIO (POST)
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];

    $csrfToken = (string)($_POST['csrf_token'] ?? $_POST['csrf'] ?? '');
    if (!csrf_verify($csrfToken)) {
        $errors[] = 'Token CSRF invalido. Reintenta el envio.';
    }

    $validation = flus_validate_user_payload($pdo, $_POST, [
        'existing_user_id' => $userId,
        'require_password' => false,
        'require_email' => true,
        'default_activo' => 0,
    ]);
    $data = $validation['data'];
    $errors = array_merge($errors, $validation['errors']);

    if (empty($errors)) {
        $guardError = flus_guard_user_admin_mutation(
            $pdo,
            (int)($_SESSION['user_id'] ?? 0),
            $userId,
            (int)$data['activo'],
            false,
            (int)$data['role_id'],
            (string)$data['username']
        );
        if ($guardError !== null) {
            $errors[] = $guardError;
        }
    }

    if (empty($errors) && $esUsuarioResguardo && (int)($_SESSION['user_id'] ?? 0) !== $userId && (string)$data['password'] !== '') {
        $errors[] = 'La contraseña de la cuenta admin de resguardo solo puede cambiarla ese mismo usuario.';
    }

    if (empty($errors)) {
        try {
            if ((string)$data['password'] !== '') {
                $sql = "UPDATE users SET nombre=:nombre, email=:email, username=:username,
                        password_hash=:ph, role_id=:role_id, activo=:activo WHERE id=:id";
                $params = [
                    ':nombre' => $data['nombre'],
                    ':email' => $data['email'],
                    ':username' => $data['username'],
                    ':ph' => password_hash((string)$data['password'], PASSWORD_DEFAULT),
                    ':role_id' => (int)$data['role_id'],
                    ':activo' => (int)$data['activo'],
                    ':id' => $userId,
                ];
            } else {
                $sql = "UPDATE users SET nombre=:nombre, email=:email, username=:username,
                        role_id=:role_id, activo=:activo WHERE id=:id";
                $params = [
                    ':nombre' => $data['nombre'],
                    ':email' => $data['email'],
                    ':username' => $data['username'],
                    ':role_id' => (int)$data['role_id'],
                    ':activo' => (int)$data['activo'],
                    ':id' => $userId,
                ];
            }
            $pdo->prepare($sql)->execute($params);
            $_SESSION['flash_success'] = 'Usuario actualizado correctamente';
            header('Location: usuarios.php');
            exit;
        } catch (PDOException $e) {
            error_log('Error al actualizar usuario: ' . $e->getMessage());
            $errors[] = 'Error al actualizar el usuario. Intenta de nuevo.';
        }
    }

    if (!empty($errors)) {
        $_SESSION['form_errors'] = $errors;
        $usuario = array_merge($usuario, [
            'nombre' => $data['nombre'],
            'email' => $data['email'],
            'username' => $data['username'],
            'role_id' => (int)$data['role_id'],
            'activo' => (int)$data['activo'],
        ]);
    }
}

$formErrors = $_SESSION['form_errors'] ?? [];
unset($_SESSION['form_errors']);

$passwordFieldError = '';
foreach ($formErrors as $error) {
    if (stripos((string)$error, 'contrasena') !== false) {
        $passwordFieldError = (string)$error;
        break;
    }
}

try {
    $roles = $pdo->query("SELECT id, nombre FROM roles ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $roles = [];
}

/* ============================================================
   CONFIG PARA HEADER
============================================================ */
$pageTitle      = 'Editar Usuario';
$currentSection = 'usuarios';
$extraCss       = ['assets/css/usuarios.css?v=3'];
$extraJs        = ['assets/js/usuario_form.js?v=2'];

require __DIR__ . '/partials/header.php';

$esMiUsuario = ($userId === (int)($_SESSION['user_id'] ?? 0));
$puedeCambiarPasswordResguardo = !$esUsuarioResguardo || $esMiUsuario;
?>

<div class="panel usuarios-panel">

  <header class="form-header">
    <div class="form-header-left">
      <h1 class="page-title">Editar Usuario</h1>
      <p class="page-sub">Modificá los datos de <strong>@<?= h($usuario['username']) ?></strong></p>
    </div>
    <div class="form-header-right">
      <a href="usuarios.php" class="v-btn v-btn--ghost">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="m19 12-14 0M5 12l7-7M5 12l7 7"/>
        </svg>
        Volver al listado
      </a>
    </div>
  </header>

  <?php if (!empty($formErrors)): ?>
    <div class="alert alert-error">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/>
      </svg>
      <div>
        <strong>Hay errores en el formulario:</strong>
        <ul class="error-list">
          <?php foreach ($formErrors as $error): ?>
            <li><?= h($error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($esUsuarioResguardo): ?>
    <div class="alert alert-warning">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>
        <path d="M9 12l2 2 4-4"/>
      </svg>
      Esta es la cuenta admin de resguardo. Se puede actualizar nombre y email, pero su usuario, rol y estado quedan fijos. La contraseña solo la puede cambiar esa misma cuenta.
    </div>
  <?php endif; ?>

  <div class="form-container">
    <form method="post" action="usuario_editar.php?id=<?= $userId ?>" class="usuario-form" id="usuarioForm" novalidate>

      <?= csrf_input() ?>

      <!-- Información Personal -->
      <div class="form-section">
        <div class="form-section-header">
          <div class="form-section-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 1 0-16 0"/>
            </svg>
          </div>
          <h3 class="form-section-title">Información Personal</h3>
        </div>
        <div class="form-row">
          <div class="form-field">
            <label for="nombre" class="form-label">Nombre completo <span class="required">*</span></label>
            <input type="text" id="nombre" name="nombre" class="form-input"
              placeholder="Ej: Juan Pérez"
              value="<?= h($usuario['nombre'] ?? '') ?>"
              required minlength="3" maxlength="100" autocomplete="name">
            <span class="form-error" data-error-for="nombre"></span>
          </div>
          <div class="form-field">
            <label for="email" class="form-label">Email <span class="required">*</span></label>
            <input type="email" id="email" name="email" class="form-input"
              placeholder="usuario@ejemplo.com"
              value="<?= h($usuario['email'] ?? '') ?>"
              required maxlength="150" autocomplete="email">
            <span class="form-error" data-error-for="email"></span>
          </div>
        </div>
      </div>

      <!-- Credenciales -->
      <div class="form-section">
        <div class="form-section-header">
          <div class="form-section-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
          </div>
          <h3 class="form-section-title">Credenciales de Acceso</h3>
        </div>
        <div class="form-row">
          <div class="form-field">
            <label for="username" class="form-label">Usuario <span class="required">*</span></label>
            <input type="text" id="username" name="username" class="form-input"
              placeholder="usuario123"
              value="<?= h($usuario['username'] ?? '') ?>"
              required minlength="3" maxlength="50"
              pattern="[a-zA-Z0-9_]+" autocomplete="username"
              <?= $esUsuarioResguardo ? 'readonly' : '' ?>>
            <span class="form-hint"><?= $esUsuarioResguardo ? 'La cuenta de resguardo conserva el usuario @admin.' : 'Solo letras, números y guion bajo (_)' ?></span>
            <span class="form-error" data-error-for="username"></span>
          </div>
          <div class="form-field">
            <label for="password" class="form-label">Nueva Contraseña</label>
            <div class="password-input-wrap">
              <input type="password" id="password" name="password" class="form-input<?= $passwordFieldError !== '' ? ' is-invalid' : '' ?>"
                placeholder="Dejar vacío para no cambiar"
                minlength="6" maxlength="255" autocomplete="new-password"
                aria-invalid="<?= $passwordFieldError !== '' ? 'true' : 'false' ?>"
                <?= $puedeCambiarPasswordResguardo ? '' : 'disabled' ?>>
              <button type="button" class="password-toggle" onclick="togglePassword('password')" aria-pressed="false" <?= $puedeCambiarPasswordResguardo ? '' : 'disabled' ?>>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>
                </svg>
              </button>
            </div>
            <span class="form-hint"><?= $puedeCambiarPasswordResguardo ? 'Dejá vacío si no querés cambiar la contraseña' : 'La contraseña de esta cuenta solo la puede cambiar el propio usuario admin.' ?></span>
            <span class="form-error" data-error-for="password"><?= h($passwordFieldError) ?></span>
          </div>
        </div>
      </div>

      <!-- Rol y Permisos -->
      <div class="form-section">
        <div class="form-section-header">
          <div class="form-section-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>
            </svg>
          </div>
          <h3 class="form-section-title">Rol y Permisos</h3>
        </div>
        <div class="form-row">
          <div class="form-field">
            <label for="role_id" class="form-label">Rol <span class="required">*</span></label>
            <?php if ($esUsuarioResguardo): ?>
              <input type="hidden" name="role_id" value="<?= (int)$usuario['role_id'] ?>">
            <?php endif; ?>
            <select id="role_id" name="<?= $esUsuarioResguardo ? '' : 'role_id' ?>" class="form-select" required <?= $esUsuarioResguardo ? 'disabled' : '' ?>>
              <option value="">Seleccionar rol...</option>
              <?php foreach ($roles as $rol): ?>
                <option value="<?= (int)$rol['id'] ?>"
                  <?= $usuario['role_id'] == $rol['id'] ? 'selected' : '' ?>>
                  <?= h($rol['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="form-error" data-error-for="role_id"></span>
          </div>
          <div class="form-field">
            <label class="form-label">Estado</label>
            <div class="uf-toggle-wrap">
              <?php if ($esMiUsuario || $esUsuarioResguardo): ?>
                <input type="hidden" name="activo" value="<?= !empty($usuario['activo']) ? '1' : '0' ?>">
              <?php endif; ?>
              <label class="uf-toggle-switch<?= ($esMiUsuario || $esUsuarioResguardo) ? ' uf-toggle-disabled' : '' ?>">
                <input type="checkbox" id="activo" name="activo" class="uf-toggle-input"
                  <?= !empty($usuario['activo']) ? 'checked' : '' ?>
                  <?= ($esMiUsuario || $esUsuarioResguardo) ? 'disabled' : '' ?>>
                <span class="uf-toggle-track">
                  <span class="uf-toggle-thumb"></span>
                </span>
                <span class="uf-toggle-label">Usuario activo</span>
              </label>
              <span class="form-hint">
                <?=
                  $esUsuarioResguardo
                    ? 'La cuenta admin de resguardo siempre queda activa.'
                    : ($esMiUsuario ? 'No podés desactivar tu propio usuario' : 'Si está desactivado, no podrá iniciar sesión')
                ?>
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Botones -->
      <div class="form-footer">
        <a href="usuarios.php" class="v-btn v-btn--ghost">Cancelar</a>
        <button type="submit" class="v-btn v-btn--primary">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
            <polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
          </svg>
          Actualizar usuario
        </button>
      </div>

    </form>
  </div>

</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

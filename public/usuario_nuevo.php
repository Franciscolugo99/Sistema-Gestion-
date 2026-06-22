<?php
// public/usuario_nuevo.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/../src/user_admin_lib.php';

require_login();
require_permission('administrar_usuarios');

/* ============================================================
   PROCESAR FORMULARIO (POST)  (PRG: Post/Redirect/Get)
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $data = [];

    $csrfToken = (string)($_POST['csrf_token'] ?? $_POST['csrf'] ?? '');
    if (!csrf_verify($csrfToken)) {
        $errors[] = 'Token CSRF invalido. Reintenta el envio.';
    }

    if (empty($errors)) {
        $result = flus_create_user_from_payload($pdo, $_POST);
        $data = $result['data'];
        $errors = array_merge($errors, $result['errors']);

        if (!empty($result['ok'])) {
            $_SESSION['flash_success'] = 'Usuario creado correctamente';
            header('Location: usuarios.php');
            exit;
        }
    }

    if (!empty($errors)) {
        $_SESSION['form_errors'] = $errors;
        $_SESSION['form_data'] = flus_user_create_form_data($data);

        header('Location: usuario_nuevo.php');
        exit;
    }
}

$formData   = $_SESSION['form_data']   ?? [];
$formErrors = $_SESSION['form_errors'] ?? [];
unset($_SESSION['form_data'], $_SESSION['form_errors']);

try {
    $roles = $pdo->query("SELECT id, nombre FROM roles ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $roles = [];
}

/* ============================================================
   CONFIG PARA HEADER
============================================================ */
$pageTitle      = 'Nuevo Usuario';
$currentSection = 'usuarios';
$extraCss       = ['assets/css/usuarios.css?v=3'];
$extraJs        = ['assets/js/usuario_form.js?v=3'];

require __DIR__ . '/partials/header.php';
?>

<div class="panel usuarios-panel">

  <header class="form-header">
    <div class="form-header-left">
      <h1 class="page-title">Nuevo Usuario</h1>
      <p class="page-sub">Completá los datos para crear un nuevo usuario en el sistema.</p>
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

  <div class="form-container">
    <form method="post" action="usuario_nuevo.php" class="usuario-form" id="usuarioForm" novalidate>

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
              value="<?= h($formData['nombre'] ?? '') ?>"
              required minlength="3" maxlength="100" autocomplete="name">
            <span class="form-error" data-error-for="nombre"></span>
          </div>
          <div class="form-field">
            <label for="email" class="form-label">Email <span class="required">*</span></label>
            <input type="email" id="email" name="email" class="form-input"
              placeholder="usuario@ejemplo.com"
              value="<?= h($formData['email'] ?? '') ?>"
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
              value="<?= h($formData['username'] ?? '') ?>"
              required minlength="3" maxlength="50"
              pattern="[a-zA-Z0-9_]+" autocomplete="username">
            <span class="form-hint">Solo letras, números y guion bajo (_)</span>
            <span class="form-error" data-error-for="username"></span>
          </div>
          <div class="form-field">
            <label for="password" class="form-label">Contraseña <span class="required">*</span></label>
            <div class="password-input-wrap">
              <input type="password" id="password" name="password" class="form-input"
                placeholder="••••••••"
                required minlength="6" maxlength="255" autocomplete="new-password">
              <button type="button" class="password-toggle" data-password-toggle="password" aria-label="Mostrar contraseña" aria-pressed="false">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>
                </svg>
              </button>
            </div>
            <span class="form-hint">Mínimo 6 caracteres</span>
            <span class="form-error" data-error-for="password"></span>
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
            <select id="role_id" name="role_id" class="form-select" required>
              <option value="">Seleccionar rol...</option>
              <?php foreach ($roles as $rol): ?>
                <option value="<?= (int)$rol['id'] ?>"
                  <?= isset($formData['role_id']) && (int)$formData['role_id'] === (int)$rol['id'] ? 'selected' : '' ?>>
                  <?= h($rol['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="form-error" data-error-for="role_id"></span>
          </div>
          <div class="form-field">
            <label class="form-label">Estado</label>
            <div class="uf-toggle-wrap">
              <label class="uf-toggle-switch">
                <input type="checkbox" id="activo" name="activo" class="uf-toggle-input"
                  <?= !isset($formData['activo']) || !empty($formData['activo']) ? 'checked' : '' ?>>
                <span class="uf-toggle-track">
                  <span class="uf-toggle-thumb"></span>
                </span>
                <span class="uf-toggle-label">Usuario activo</span>
              </label>
              <span class="form-hint">Si está desactivado, no podrá iniciar sesión</span>
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
          Guardar usuario
        </button>
      </div>

    </form>
  </div>

</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

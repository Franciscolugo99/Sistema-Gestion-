<?php
// public/usuario_editar.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('administrar_usuarios');
if (session_status() === PHP_SESSION_NONE) session_start();

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
    $stmt = $pdo->prepare("
        SELECT id, nombre, email, username, role_id, activo
        FROM users
        WHERE id = :id
    ");
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

/* ============================================================
   PROCESAR FORMULARIO (POST)
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $role_id = (int)($_POST['role_id'] ?? 0);
    $activo = isset($_POST['activo']) ? 1 : 0;
    
    $errors = [];
    
    // Validaciones
    if (empty($nombre)) {
        $errors[] = 'El nombre es obligatorio';
    } elseif (strlen($nombre) < 3) {
        $errors[] = 'El nombre debe tener al menos 3 caracteres';
    }
    
    if (empty($email)) {
        $errors[] = 'El email es obligatorio';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'El email no es válido';
    } else {
        // Verificar email único (excepto el actual)
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email AND id != :id");
        $stmt->execute([':email' => $email, ':id' => $userId]);
        if ($stmt->fetch()) {
            $errors[] = 'Este email ya está registrado por otro usuario';
        }
    }
    
    if (empty($username)) {
        $errors[] = 'El usuario es obligatorio';
    } elseif (strlen($username) < 3) {
        $errors[] = 'El usuario debe tener al menos 3 caracteres';
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $errors[] = 'El usuario solo puede contener letras, números y guion bajo';
    } else {
        // Verificar username único (excepto el actual)
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username AND id != :id");
        $stmt->execute([':username' => $username, ':id' => $userId]);
        if ($stmt->fetch()) {
            $errors[] = 'Este nombre de usuario ya está en uso por otro usuario';
        }
    }
    
    // Validar contraseña solo si se ingresó una nueva
    if (!empty($password) && strlen($password) < 6) {
        $errors[] = 'La contraseña debe tener al menos 6 caracteres';
    }
    
    if ($role_id <= 0) {
        $errors[] = 'Debe seleccionar un rol válido';
    }
    
    // Prevenir auto-desactivación
    if ($userId === (int)($_SESSION['user_id'] ?? 0) && $activo === 0) {
        $errors[] = 'No puedes desactivar tu propio usuario';
    }
    
    // Si no hay errores, actualizar usuario
    if (empty($errors)) {
        try {
            // Preparar query según si hay nueva contraseña
            if (!empty($password)) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $sql = "
                    UPDATE users 
                    SET nombre = :nombre,
                        email = :email,
                        username = :username,
                        password_hash = :password_hash,
                        role_id = :role_id,
                        activo = :activo
                    WHERE id = :id
                ";
                $params = [
                    ':nombre' => $nombre,
                    ':email' => $email,
                    ':username' => $username,
                    ':password_hash' => $password_hash,
                    ':role_id' => $role_id,
                    ':activo' => $activo,
                    ':id' => $userId
                ];
            } else {
                $sql = "
                    UPDATE users 
                    SET nombre = :nombre,
                        email = :email,
                        username = :username,
                        role_id = :role_id,
                        activo = :activo
                    WHERE id = :id
                ";
                $params = [
                    ':nombre' => $nombre,
                    ':email' => $email,
                    ':username' => $username,
                    ':role_id' => $role_id,
                    ':activo' => $activo,
                    ':id' => $userId
                ];
            }
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $_SESSION['flash_success'] = 'Usuario actualizado correctamente';
            header('Location: usuarios.php');
            exit;
            
        } catch (PDOException $e) {
            error_log("Error al actualizar usuario: " . $e->getMessage());
            $errors[] = 'Error al actualizar el usuario. Por favor, intente nuevamente.';
        }
    }
    
    // Si hay errores, guardar en sesión
    if (!empty($errors)) {
        $_SESSION['form_errors'] = $errors;
        // Mantener los datos del POST para el formulario
        $usuario = array_merge($usuario, $_POST);
    }
}

// Recuperar errores si hay
$formErrors = $_SESSION['form_errors'] ?? [];
unset($_SESSION['form_errors']);

// Obtener roles disponibles
try {
    $roles = $pdo->query("SELECT id, nombre FROM roles ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error al cargar roles: " . $e->getMessage());
    $roles = [];
}

/* ============================================================
   CONFIG PARA HEADER
============================================================ */
$pageTitle = 'Editar Usuario';
$currentSection = 'usuarios';
$extraCss = ['assets/css/usuarios.css?v=2'];
$extraJs = ['assets/js/usuario_form.js?v=1'];

require __DIR__ . '/partials/header.php';
?>

<div class="panel usuarios-panel">

  <header class="form-header">
    <div class="form-header-left">
      <h1 class="page-title">Editar Usuario</h1>
      <p class="page-sub">Modificá los datos del usuario <strong><?= h($usuario['username']) ?></strong></p>
    </div>
    <div class="form-header-right">
      <a href="usuarios.php" class="v-btn v-btn--ghost">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M19 12H5M12 19l-7-7 7-7"/>
        </svg>
        Volver al listado
      </a>
    </div>
  </header>

  <!-- Errores de validación -->
  <?php if (!empty($formErrors)): ?>
    <div class="alert alert-error">
      <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
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

  <!-- Formulario -->
  <div class="form-container">
    <form method="post" action="usuario_editar.php?id=<?= $userId ?>" class="usuario-form" id="usuarioForm" novalidate>
      
      <div class="form-section">
        <h3 class="form-section-title">Información Personal</h3>
        
        <div class="form-row">
          <div class="form-field">
            <label for="nombre" class="form-label">
              Nombre completo <span class="required">*</span>
            </label>
            <input 
              type="text" 
              id="nombre" 
              name="nombre" 
              class="form-input"
              placeholder="Ej: Juan Pérez"
              value="<?= h($usuario['nombre'] ?? '') ?>"
              required
              minlength="3"
              maxlength="100"
              autocomplete="name"
            >
            <span class="form-error" data-error-for="nombre"></span>
          </div>

          <div class="form-field">
            <label for="email" class="form-label">
              Email <span class="required">*</span>
            </label>
            <input 
              type="email" 
              id="email" 
              name="email" 
              class="form-input"
              placeholder="usuario@ejemplo.com"
              value="<?= h($usuario['email'] ?? '') ?>"
              required
              maxlength="150"
              autocomplete="email"
            >
            <span class="form-error" data-error-for="email"></span>
          </div>
        </div>
      </div>

      <div class="form-section">
        <h3 class="form-section-title">Credenciales de Acceso</h3>
        
        <div class="form-row">
          <div class="form-field">
            <label for="username" class="form-label">
              Usuario <span class="required">*</span>
            </label>
            <input 
              type="text" 
              id="username" 
              name="username" 
              class="form-input"
              placeholder="usuario123"
              value="<?= h($usuario['username'] ?? '') ?>"
              required
              minlength="3"
              maxlength="50"
              pattern="[a-zA-Z0-9_]+"
              autocomplete="username"
            >
            <span class="form-hint">Solo letras, números y guion bajo (_)</span>
            <span class="form-error" data-error-for="username"></span>
          </div>

          <div class="form-field">
            <label for="password" class="form-label">
              Nueva Contraseña
            </label>
            <div class="password-input-wrap">
              <input 
                type="password" 
                id="password" 
                name="password" 
                class="form-input"
                placeholder="Dejar vacío para no cambiar"
                minlength="6"
                maxlength="255"
                autocomplete="new-password"
              >
              <button type="button" class="password-toggle" onclick="togglePassword('password')">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                  <circle cx="12" cy="12" r="3"/>
                </svg>
              </button>
            </div>
            <span class="form-hint">Dejá vacío si no querés cambiar la contraseña</span>
            <span class="form-error" data-error-for="password"></span>
          </div>
        </div>
      </div>

      <div class="form-section">
        <h3 class="form-section-title">Rol y Permisos</h3>
        
        <div class="form-row">
          <div class="form-field">
            <label for="role_id" class="form-label">
              Rol <span class="required">*</span>
            </label>
            <select 
              id="role_id" 
              name="role_id" 
              class="form-select"
              required
            >
              <option value="">Seleccionar rol...</option>
              <?php foreach ($roles as $rol): ?>
                <option 
                  value="<?= (int)$rol['id'] ?>"
                  <?= $usuario['role_id'] == $rol['id'] ? 'selected' : '' ?>
                >
                  <?= h($rol['nombre']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <span class="form-error" data-error-for="role_id"></span>
          </div>

          <div class="form-field">
            <label class="form-label">Estado</label>
            <div class="checkbox-wrap">
              <label class="checkbox-label">
                <input 
                  type="checkbox" 
                  id="activo" 
                  name="activo" 
                  class="form-checkbox"
                  <?= !empty($usuario['activo']) ? 'checked' : '' ?>
                >
                <span class="checkbox-custom"></span>
                <span class="checkbox-text">Usuario activo</span>
              </label>
              <span class="form-hint">
                <?php if ($userId === (int)($_SESSION['user_id'] ?? 0)): ?>
                  No podés desactivar tu propio usuario
                <?php else: ?>
                  Si está desactivado, no podrá iniciar sesión
                <?php endif; ?>
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Botones -->
      <div class="form-footer">
        <a href="usuarios.php" class="v-btn v-btn--ghost">Cancelar</a>
        <button type="submit" class="v-btn v-btn--primary">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
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
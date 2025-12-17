<?php
// public/login.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/helpers.php';

if (is_logged_in()) {
  header('Location: index.php');
  exit;
}

// Whitelist de códigos de error
$errorCode = isset($_GET['error']) ? (string)$_GET['error'] : '';
if (!in_array($errorCode, ['user', 'pass', 'empty'], true)) {
  $errorCode = '';
}

$errorMsg = null;
switch ($errorCode) {
  case 'user':
    $errorMsg = 'El usuario no existe o está inactivo.';
    break;
  case 'pass':
    $errorMsg = 'La contraseña es incorrecta.';
    break;
  case 'empty':
    $errorMsg = 'Completá usuario y contraseña.';
    break;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>FLUS · Iniciar sesión</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <!-- Estilos core -->
  <link rel="stylesheet" href="assets/css/theme.css?v=1">
  <link rel="stylesheet" href="assets/css/app.css?v=1">
  <!-- Estilos específicos de login -->
  <link rel="stylesheet" href="assets/css/login.css?v=1">
</head>

<body data-theme="dark" class="page-login">

  <div class="login-wrapper">
    <div class="login-card">

      <div class="login-logo">
        <img src="img/logo1.png" alt="FLUS" class="logo-sistema">
      </div>

      <h1 class="login-title">Iniciar sesión</h1>

      <?php if ($errorMsg): ?>
        <div class="login-error" id="loginError" role="alert" aria-live="polite">
          <?= h($errorMsg) ?>
        </div>
      <?php endif; ?>

      <form class="login-form" method="post" action="login_process.php" autocomplete="on">
        <?= csrf_field() ?>

        <label for="login-username">Usuario</label>
        <input
          type="text"
          id="login-username"
          name="username"
          required
          autofocus
          autocomplete="username"
          maxlength="60"
        >

        <label for="login-password">Contraseña</label>
        <input
          type="password"
          name="password"
          id="login-password"
          required
          autocomplete="current-password"
          maxlength="120"
        >

        <p id="capsWarning" class="caps-warning" style="display:none;">
          Bloq Mayús está activado.
        </p>

        <button type="submit" class="btn-primary login-btn">
          Entrar
        </button>
      </form>

    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const pwd       = document.getElementById('login-password');
      const warning   = document.getElementById('capsWarning');
      const errorBox  = document.getElementById('loginError');
      const userInput = document.getElementById('login-username');

      // Aviso de Bloq Mayús
      if (pwd && warning) {
        const checkCaps = (e) => {
          const capsOn = e.getModifierState && e.getModifierState('CapsLock');
          warning.style.display = capsOn ? 'block' : 'none';
        };
        pwd.addEventListener('keydown', checkCaps);
        pwd.addEventListener('keyup', checkCaps);
      }

      // Error: fade-out + se oculta al tipear
      if (errorBox) {
        setTimeout(() => errorBox.classList.add('fade-out'), 4000);
        const hideError = () => errorBox.classList.add('fade-out');
        userInput && userInput.addEventListener('input', hideError);
        pwd && pwd.addEventListener('input', hideError);
      }
    });
  </script>

</body>
</html>

<?php
declare(strict_types=1);

// Evitar cache de páginas privadas
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// Vaciar variables de sesión
$_SESSION = [];

// Borrar cookie de sesión (si existe)
if (ini_get('session.use_cookies')) {
  $params = session_get_cookie_params();
  setcookie(
    session_name(),
    '',
    time() - 42000,
    $params['path'] ?? '/',
    $params['domain'] ?? '',
    (bool)($params['secure'] ?? false),
    (bool)($params['httponly'] ?? true)
  );
}

// Destruir sesión
session_destroy();

// Redirigir
header('Location: login.php');
exit;

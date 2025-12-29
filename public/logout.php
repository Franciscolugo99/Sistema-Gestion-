<?php
// public/logout.php
declare(strict_types=1);

// Evitar cache de páginas privadas
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/terminal.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// Liberar lock de terminal (si existe)
try {
  $pdo = getPDO();
  $tid = (int)($_SESSION['terminal_id'] ?? 0);
  if ($tid <= 0) $tid = terminal_cookie_id();

  $uid = 0;
  if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    $uid = (int)($_SESSION['user']['id'] ?? 0);
  }

  if ($tid > 0) {
    // liberar por terminal+user (session puede cambiar)
    terminal_lock_release($pdo, $tid, $uid, session_id());
  }
} catch (Throwable $e) {
  // no bloqueamos el logout por esto
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
$reason = (string)($_GET['reason'] ?? '');
if ($reason === 'locked') {
  header('Location: login.php?error=locked');
} else {
  header('Location: login.php');
}
exit;

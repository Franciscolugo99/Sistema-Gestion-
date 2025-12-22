<?php
// public/lib/csrf.php
declare(strict_types=1);

// Asegurar sesión
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/**
 * Token CSRF (si ya existe en helpers.php, NO lo redeclara)
 */
if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
  }
}

/**
 * Validar token CSRF (si ya existe en helpers.php, NO lo redeclara)
 */
if (!function_exists('csrf_check')) {
  function csrf_check(?string $token): bool {
    if (!is_string($token) || $token === '') return false;
    $sess = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sess) || $sess === '') return false;
    return hash_equals($sess, $token);
  }
}

/**
 * (Opcional) Input hidden para forms
 */
if (!function_exists('csrf_input')) {
  function csrf_input(string $name = 'csrf'): string {
    $t = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    $n = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="'.$n.'" value="'.$t.'">';
  }
}

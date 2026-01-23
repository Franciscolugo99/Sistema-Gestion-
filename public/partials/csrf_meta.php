<?php
/**
 * public/partials/csrf_meta.php
 * Expone el token CSRF como <meta name="csrf-token" ...> para JS.
 * Incluir en el <head> global.
 */
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$__csrf = (string)$_SESSION['csrf_token'];
?>
<meta name="csrf-token" content="<?= htmlspecialchars($__csrf, ENT_QUOTES, 'UTF-8') ?>">

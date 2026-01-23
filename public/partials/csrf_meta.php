<?php
// public/partials/csrf_meta.php
// Inserta el token CSRF en <head> para que JS pueda leerlo.
declare(strict_types=1);

require_once __DIR__ . '/../lib/csrf.php';

// Asegurar token
csrf_init();
$token = csrf_token();
?>
<meta name="csrf-token" content="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

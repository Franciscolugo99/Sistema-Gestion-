<?php
// public/bootstrap.php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';

// sesión una sola vez
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
define('APP_BOOTSTRAPPED', true);

// compatibilidad (lo que ya usa el sistema)
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/auth.php';

// core nuevo (safe)
$coreHelpers    = __DIR__ . '/../src/helpers.php';
$coreMiddleware = __DIR__ . '/../src/Middleware.php';
$coreBase       = __DIR__ . '/../src/BaseController.php';

if (file_exists($coreHelpers))    require_once $coreHelpers;
if (file_exists($coreMiddleware)) require_once $coreMiddleware;
if (file_exists($coreBase))       require_once $coreBase;

// Conveniencia: muchas páginas usan $pdo y $user
$pdo  = getPDO();
$user = current_user();

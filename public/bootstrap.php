<?php
// public/bootstrap.php
declare(strict_types=1);

require_once __DIR__ . '/lib/session.php';
flus_session_start();

require_once FLUS_ROOT . '/src/config.php';

define('APP_BOOTSTRAPPED', true);

// compatibilidad (lo que ya usa el sistema)
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/auth.php';

// core nuevo (safe)
$coreHelpers    = FLUS_ROOT . '/src/helpers.php';
$coreMiddleware = FLUS_ROOT . '/src/Middleware.php';
$coreBase       = FLUS_ROOT . '/src/BaseController.php';

if (file_exists($coreHelpers))    require_once $coreHelpers;
if (file_exists($coreMiddleware)) require_once $coreMiddleware;
if (file_exists($coreBase))       require_once $coreBase;

// Conveniencia: muchas páginas usan $pdo y $user
$pdo  = getPDO();
$user = current_user();

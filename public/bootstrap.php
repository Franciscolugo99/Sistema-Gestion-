<?php
// public/bootstrap.php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/auth.php';

// Conveniencia: muchas páginas usan $pdo y $user
$pdo  = getPDO();
$user = current_user();

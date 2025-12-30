<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

// SOLO local (evita dejar un agujero si lo subís sin querer)
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
  http_response_code(403);
  die('Forbidden');
}

echo "<pre>";

// Helpers
echo money_ar(1234.56) . PHP_EOL;        // $ 1.234,56
echo format_qty(3.5, true) . PHP_EOL;    // 3,500
echo "csrf: " . csrf_token() . PHP_EOL;

// Simular login/permisos SOLO para test si no existe sesión real
$_SESSION['user_id'] = $_SESSION['user_id'] ?? 1;
$_SESSION['permissions'] = $_SESSION['permissions'] ?? ['ver_reportes', 'editar_productos'];

// Middleware
Middleware::auth()->permission('ver_reportes')->run();

echo "✅ Todo funciona!" . PHP_EOL;
echo "</pre>";

<?php
declare(strict_types=1);

// Endpoint de diagnóstico (dev) para evitar 404 si algún JS viejo lo llama.
// Devuelve token CSRF actual y setea cookie FLUS_CSRF (double-submit).

require_once __DIR__ . '/../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start();
}

$token = $_SESSION['csrf_token'] ?? null;
if (!is_string($token) || $token === '') {
  $token = bin2hex(random_bytes(32));
  $_SESSION['csrf_token'] = $token;
}

// cookie no HttpOnly para que el cliente pueda leer si lo necesita
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
setcookie('FLUS_CSRF', $token, [
  'expires'  => time() + 60*60*8,
  'path'     => '/',
  'secure'   => $secure,
  'httponly' => false,
  'samesite' => 'Lax',
]);

echo json_encode([
  'ok' => true,
  'csrf' => $token,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

<?php
// public/lib/install_guard.php
declare(strict_types=1);

/**
 * Guard central para instalaciones nuevas:
 * - si falta src/config.php, evita fatal errors
 * - en web redirige a install.php
 * - en API responde JSON 503
 *
 * Este archivo es safe para incluir varias veces (require_once).
 */

if (!defined('FLUS_ROOT')) {
  require_once __DIR__ . '/session.php';
  flus_session_start();
}

$cfg = FLUS_ROOT . '/src/config.php';
if (is_file($cfg)) return;

$script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
$isApi = (str_contains($script, '/api/') || str_ends_with($script, '/api/index.php') || str_contains($accept, 'application/json'));

if ($isApi) {
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
  }
  http_response_code(503);
  echo json_encode([
    'ok' => false,
    'error' => 'CONFIG_MISSING',
    'hint' => 'Falta src/config.php. Abrí /install.php para configurar el sistema.'
  ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

// web: redirigir a install.php dentro de /public
$base = rtrim(dirname($script), '/');
if (str_ends_with($base, '/api')) $base = rtrim(dirname($base), '/');

$target = ($base !== '' ? $base : '') . '/install.php';
if (!headers_sent()) {
  header('Location: ' . $target);
  exit;
}

echo "<h1>Instalación requerida</h1><p>Falta <code>src/config.php</code>. Abrí <a href=\"" . htmlspecialchars($target) . "\">install.php</a>.</p>";
exit;

<?php
declare(strict_types=1);

/**
 * Diagnóstico - Descarga de paquetes (seguro)
 * - Permisos: gestionar_backups
 * - Anti path traversal: realpath + containment
 * - Rate limit simple (sesión)
 * - Expiración de paquetes (>7 días)
 * - Streaming por chunks
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/diagnostics_lib.php';

if (function_exists('require_login')) {
    require_login();
}

/**
 * Log local (fallback) para accesos a diagnósticos.
 */
function flus_diag_log(string $level, string $event, array $ctx = []): void {
    $root = defined('FLUS_ROOT') ? FLUS_ROOT : dirname(__DIR__);
    $logDir = $root . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $logFile = $logDir . '/diagnostic_access.log';

    $base = [
        'ts' => date('Y-m-d H:i:s'),
        'level' => $level,
        'event' => $event,
        'user_id' => $_SESSION['user_id'] ?? null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ];
    $line = json_encode($base + $ctx, JSON_UNESCAPED_UNICODE);
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

    // Si existen los logs del sistema, también los usamos
    if (function_exists('flus_log_security') && $level === 'security') {
        @flus_log_security($event, $ctx);
    } elseif (function_exists('flus_log_warning') && $level === 'warning') {
        @flus_log_warning($event, $ctx);
    } elseif (function_exists('flus_log_error') && $level === 'error') {
        @flus_log_error($event, $ctx);
    } elseif (function_exists('flus_log_info')) {
        @flus_log_info($event, $ctx);
    }
}

function diag_json_error(string $msg, int $code): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

// Permisos
if (!function_exists('user_has_permission') || !user_has_permission('gestionar_backups')) {
    flus_diag_log('security', 'diagnostic_download_denied', ['reason' => 'no_permission', 'file' => $_GET['f'] ?? null]);
    diag_json_error('Sin permisos', 403);
}

$file = (string)($_GET['f'] ?? '');

// Validación estricta de nombre
if ($file === '' || !preg_match('/^(flus_diagnostic|flus_diagnostico)_\d{8}_\d{6}\.zip$/', $file)) {
    flus_diag_log('security', 'diagnostic_download_invalid_file', ['file' => $file]);
    diag_json_error('Archivo inválido', 400);
}

$root = defined('FLUS_ROOT') ? FLUS_ROOT : dirname(__DIR__);
$diagDir = $root . '/storage/diagnostics';
$fullPath = $diagDir . '/' . $file;

$realPath = realpath($fullPath);
$realDir = realpath($diagDir);

if (!$realPath || !$realDir || strpos($realPath, $realDir) !== 0 || !is_file($realPath)) {
    flus_diag_log('warning', 'diagnostic_download_not_found', ['file' => $file]);
    diag_json_error('Archivo no encontrado', 404);
}

// Expiración: >7 días => 410 (no lo borra acá; lo limpia Diagnóstico)
$maxAge = 7 * 24 * 60 * 60;
$age = time() - (int)@filemtime($realPath);
if ($age > $maxAge) {
    flus_diag_log('info', 'diagnostic_download_expired', ['file' => $file, 'age_seconds' => $age]);
    diag_json_error('Archivo expirado (más de 7 días). Ejecutá "Limpiar > 7 días".', 410);
}

// Rate limit simple: 10 por hora por sesión
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$key = 'diag_dl_' . date('YmdH');
$_SESSION[$key] = (int)($_SESSION[$key] ?? 0) + 1;
if ((int)$_SESSION[$key] > 10) {
    flus_diag_log('warning', 'diagnostic_download_rate_limited', ['file' => $file, 'count' => (int)$_SESSION[$key]]);
    diag_json_error('Demasiadas descargas. Probá más tarde.', 429);
}

flus_diag_log('info', 'diagnostic_download_ok', ['file' => $file, 'size' => (int)@filesize($realPath)]);

// Headers de seguridad
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . basename($file) . '"');
header('Content-Length: ' . (string)@filesize($realPath));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Streaming en chunks
$handle = @fopen($realPath, 'rb');
if (!$handle) {
    diag_json_error('No se pudo abrir el archivo', 500);
}
while (!feof($handle)) {
    echo fread($handle, 8192);
    flush();
}
fclose($handle);
exit;

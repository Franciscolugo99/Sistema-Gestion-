<?php
// public/diagnostico_download.php - Descargar paquetes de diagnóstico
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

if (!user_has_permission('gestionar_backups')) {
    http_response_code(403);
    exit('Sin permisos');
}

$file = (string)($_GET['f'] ?? '');

// Validar nombre de archivo
if (!$file || !preg_match('/^flus_diagnostic_\d{8}_\d{6}\.zip$/', $file)) {
    http_response_code(400);
    exit('Archivo inválido');
}

$diagDir = FLUS_ROOT . '/storage/diagnostics';
$fullPath = $diagDir . '/' . $file;

// Verificar que existe y está dentro del directorio permitido
$realPath = realpath($fullPath);
$realDir = realpath($diagDir);

if (!$realPath || !$realDir || strpos($realPath, $realDir) !== 0 || !is_file($realPath)) {
    http_response_code(404);
    exit('Archivo no encontrado');
}

// Enviar archivo
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . filesize($realPath));
header('Cache-Control: no-store');

readfile($realPath);
exit;

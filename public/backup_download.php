<?php
// public/backup_download.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
require_permission('gestionar_backups');

require_once __DIR__ . '/../src/backup_lib.php';

$f = (string)($_GET['f'] ?? '');
$f = basename($f);

if (!preg_match('/^[A-Za-z0-9._-]+\.sql$/', $f)) {
  http_response_code(400);
  echo 'Archivo inválido.';
  exit;
}

$path = backups_dir() . DIRECTORY_SEPARATOR . $f;
if (!is_file($path)) {
  http_response_code(404);
  echo 'No existe.';
  exit;
}

// Descargar
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $f . '"');
header('Content-Length: ' . (string)filesize($path));

readfile($path);
exit;

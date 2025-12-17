<?php
// public/backup_download.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
require_permission('gestionar_backups');

require_once __DIR__ . '/../src/backup_lib.php';

$f = basename((string)($_GET['f'] ?? ''));

if (!preg_match('/^[A-Za-z0-9._-]+\.sql(\.gz)?$/', $f)) {
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

header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (string)filesize($path));

if (str_ends_with($f, '.gz')) {
  header('Content-Type: application/gzip');
} else {
  header('Content-Type: application/sql');
}

header('Content-Disposition: attachment; filename="' . $f . '"');

readfile($path);
exit;

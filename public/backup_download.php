<?php
// public/backup_download.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('gestionar_backups');

require_once __DIR__ . '/../src/backup_lib.php';

$f = basename((string)($_GET['f'] ?? ''));

if (!backup_is_downloadable_file_name($f)) {
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
header('Cache-Control: private, no-store, max-age=0');
header('Content-Length: ' . (string)filesize($path));

if (str_ends_with(strtolower($f), '.flus.zip')) {
  header('Content-Type: application/zip');
} elseif (str_ends_with(strtolower($f), '.gz')) {
  header('Content-Type: application/gzip');
} else {
  header('Content-Type: application/sql');
}

header('Content-Disposition: attachment; filename="' . $f . '"');

readfile($path);
exit;

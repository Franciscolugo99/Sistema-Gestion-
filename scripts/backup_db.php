<?php
// scripts/backup_db.php
declare(strict_types=1);

// Ejecutar con:
//   C:\xampp\php\php.exe C:\xampp\htdocs\kiosco\scripts\backup_db.php

require_once __DIR__ . '/../src/backup_lib.php';

if (php_sapi_name() !== 'cli') {
  http_response_code(403);
  echo "CLI only\n";
  exit;
}

$err = null;
$file = backup_create($err);

if (!$file) {
  fwrite(STDERR, "ERROR: " . ($err ?: 'no details') . "\n");
  exit(1);
}

echo "OK: $file\n";
exit(0);

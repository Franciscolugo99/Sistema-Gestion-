<?php
// scripts/backup_db.php
declare(strict_types=1);

require_once __DIR__ . '/../src/audit_lib.php';
require_once __DIR__ . '/../src/backup_lib.php';

$err = null;
$file = backup_create($err);

if (!$file) {
  fwrite(STDERR, "[ERROR] " . ($err ?: "No se pudo crear el backup") . PHP_EOL);
  exit(1);
}

echo "[OK] Backup creado: {$file}" . PHP_EOL;
exit(0);

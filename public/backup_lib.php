<?php
// src/backup_lib.php
declare(strict_types=1);
require_once __DIR__ . '/logger.php';

/**
 * Wrapper para compatibilidad: backup_lib usa app_log()
 * pero el sistema usa flus_log().
 */
if (!function_exists('app_log')) {
  function app_log(string $level, string $message, array $context = []): void {
    if (function_exists('flus_log')) {
      flus_log($level, $message, $context);
      return;
    }

    // Fallback ultra simple a archivo (por si no está logger.php)
    $dir = __DIR__ . '/../storage/logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $line = '[' . date('Y-m-d H:i:s') . "] [$level] $message";
    if ($context) {
      $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $line .= PHP_EOL;

    @file_put_contents($dir . '/app.log', $line, FILE_APPEND);
  }
}

require_once __DIR__ . '/config.php';

// Si existe audit_lib, lo cargamos (puede traer app_log)
if (file_exists(__DIR__ . '/audit_lib.php')) {
  require_once __DIR__ . '/audit_lib.php';
}

/**
 * Logger interno SIEMPRE disponible.
 * - Si existe app_log() => lo usa
 * - Si no => escribe en /storage/logs/app.log
 */
function bk_log(string $msg): void {
  if (function_exists('app_log')) {
    app_log($msg);
    return;
  }

  $dir = __DIR__ . '/../storage/logs';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  $file = $dir . '/app.log';
  $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
  @file_put_contents($file, $line, FILE_APPEND);
}

/**
 * ENV:
 * - KIOSCO_BACKUP_COMPRESS=1
 * - KIOSCO_BACKUP_KEEP=30
 * - KIOSCO_BACKUP_EXTERNAL_DIR=D:\Backups_FLUS
 */
function backup_cfg(): array {
  $compress = getenv('KIOSCO_BACKUP_COMPRESS');
  $keep     = getenv('KIOSCO_BACKUP_KEEP');
  $extDir   = getenv('KIOSCO_BACKUP_EXTERNAL_DIR');

  return [
    'compress' => ($compress === false) ? true : ((string)$compress !== '0'),
    'keep'     => ($keep === false) ? 30 : max(1, (int)$keep),
    'external_dir' => ($extDir === false) ? '' : trim((string)$extDir),
  ];
}

function backups_dir(): string {
  $dir = __DIR__ . '/../storage/backups';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  return realpath($dir) ?: $dir;
}

function backup_is_valid_filename(string $f): bool {
  $f = basename($f);
  return (bool)preg_match('/^[A-Za-z0-9._-]+\.sql(\.gz)?$/', $f);
}

function backup_list(): array {
  $dir = backups_dir();
  $items = [];

  foreach (glob($dir . DIRECTORY_SEPARATOR . '*.sql*') as $path) {
    if (!is_file($path)) continue;
    $file = basename($path);
    if (!backup_is_valid_filename($file)) continue;

    $items[] = [
      'file'  => $file,
      'size'  => (int)filesize($path),
      'mtime' => (int)filemtime($path),
    ];
  }

  usort($items, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
  return $items;
}

function backup_delete(string $file, ?string &$err = null): bool {
  $file = basename($file);
  if (!backup_is_valid_filename($file)) {
    $err = 'Archivo inválido.';
    return false;
  }

  $path = backups_dir() . DIRECTORY_SEPARATOR . $file;
  if (!is_file($path)) {
    $err = 'No existe.';
    return false;
  }

  if (!@unlink($path)) {
    $err = 'No se pudo borrar (permisos/archivo en uso).';
    return false;
  }

  bk_log("Backup borrado: {$file}");
  return true;
}

function backup_prune_keep_last(int $keep): void {
  $keep = max(1, $keep);
  $items = backup_list();
  if (count($items) <= $keep) return;

  $toDelete = array_slice($items, $keep);
  foreach ($toDelete as $it) {
    $path = backups_dir() . DIRECTORY_SEPARATOR . $it['file'];
    @unlink($path);
  }
}

function backup_copy_external(string $localPath, string $externalDir, ?string &$err = null): bool {
  $externalDir = rtrim($externalDir, "\\/ ");
  if ($externalDir === '') return true;

  if (!is_dir($externalDir)) {
    if (!@mkdir($externalDir, 0775, true)) {
      $err = "No se pudo crear carpeta externa: {$externalDir}";
      return false;
    }
  }

  $dest = $externalDir . DIRECTORY_SEPARATOR . basename($localPath);
  if (!@copy($localPath, $dest)) {
    $err = "No se pudo copiar a carpeta externa: {$externalDir}";
    return false;
  }

  return true;
}

function backup_create(?string &$err = null): ?string {
  $cfg = backup_cfg();
  $dir = backups_dir();

  $nameBase = 'kiosco_' . date('Ymd_His');
  $sqlFile  = $nameBase . '.sql';
  $sqlPath  = $dir . DIRECTORY_SEPARATOR . $sqlFile;

  // Validar conexión temprano
  try {
    getPDO();
  } catch (Throwable $e) {
    $err = 'No se pudo conectar a la base: ' . $e->getMessage();
    return null;
  }

  $dbHost = defined('DB_HOST') ? (string)DB_HOST : 'localhost';
  $dbName = defined('DB_NAME') ? (string)DB_NAME : '';
  $dbUser = defined('DB_USER') ? (string)DB_USER : 'root';
  $dbPass = defined('DB_PASS') ? (string)DB_PASS : '';

  if ($dbName === '') {
    $err = 'DB_NAME no está configurado.';
    return null;
  }

  $mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
  if (!is_file($mysqldump)) $mysqldump = 'mysqldump';

  // Mejor en Windows: --result-file (evita problemas con > redirección)
  $cmd = '"' . $mysqldump . '"'
    . ' --host=' . escapeshellarg($dbHost)
    . ' --user=' . escapeshellarg($dbUser);

  if ($dbPass !== '') {
    $cmd .= ' --password=' . escapeshellarg($dbPass);
  }

  $cmd .= ' --routines --triggers --single-transaction --quick --default-character-set=utf8mb4'
    . ' --result-file=' . escapeshellarg($sqlPath)
    . ' ' . escapeshellarg($dbName)
    . ' 2>&1';

  $out = [];
  $exitCode = 0;
  @exec($cmd, $out, $exitCode);

  if ($exitCode !== 0 || !is_file($sqlPath) || filesize($sqlPath) === 0) {
    @unlink($sqlPath);
    $tail = trim(implode("\n", array_slice($out, -6)));
    $err = 'Fallo mysqldump. ' . ($tail ? "Detalle: {$tail}" : 'Revisar ruta/credenciales.');
    bk_log("Backup ERROR mysqldump exit={$exitCode} cmd={$cmd}");
    return null;
  }

  $finalPath = $sqlPath;
  $finalFile = $sqlFile;

  if ($cfg['compress'] === true) {
    $gzFile = $sqlFile . '.gz';
    $gzPath = $dir . DIRECTORY_SEPARATOR . $gzFile;

    $in = @fopen($sqlPath, 'rb');
    $gz = @gzopen($gzPath, 'wb9');

    if (!$in || !$gz) {
      if ($in) fclose($in);
      if ($gz) gzclose($gz);
      @unlink($gzPath);
      $err = 'No se pudo comprimir el backup.';
      return null;
    }

    while (!feof($in)) {
      $chunk = fread($in, 1024 * 1024);
      if ($chunk === false) break;
      gzwrite($gz, $chunk);
    }

    fclose($in);
    gzclose($gz);
    @unlink($sqlPath);

    $finalPath = $gzPath;
    $finalFile = $gzFile;
  }

  $copyErr = null;
  if ($cfg['external_dir'] !== '') {
    if (!backup_copy_external($finalPath, $cfg['external_dir'], $copyErr)) {
      bk_log("Backup WARN copy external: {$copyErr}");
    }
  }

  backup_prune_keep_last((int)$cfg['keep']);

  bk_log("Backup creado: {$finalFile}");
  return $finalFile;
}

<?php
// src/backup_lib.php
declare(strict_types=1);

require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/config.php';

// Si existe audit_lib, lo cargamos
if (file_exists(__DIR__ . '/audit_lib.php')) {
  require_once __DIR__ . '/audit_lib.php';
}

/**
 * Wrapper compat: algunos módulos llaman app_log("mensaje") o app_log("level","mensaje",[])
 * - Si existe flus_log() (logger.php) lo usa
 * - Si no, fallback a /storage/logs/app.log
 */
if (!function_exists('app_log')) {
  function app_log(string $level, string $message = '', array $context = []): void {
    // Compat: si llamaron app_log("mensaje") -> lo tratamos como info
    if ($message === '') {
      $message = $level;
      $level = 'info';
    }

    if (function_exists('flus_log')) {
      flus_log($level, $message, $context);
      return;
    }

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

/**
 * Logger interno consistente
 */
function bk_log(string $msg, string $level = 'info', array $context = []): void {
  if (function_exists('app_log')) {
    app_log($level, $msg, $context);
    return;
  }

  $dir = __DIR__ . '/../storage/logs';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  $file = $dir . '/app.log';
  $line = '[' . date('Y-m-d H:i:s') . "] [$level] " . $msg;
  if ($context) {
    $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  $line .= PHP_EOL;

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
    'compress'     => ($compress === false) ? true : ((string)$compress !== '0'),
    'keep'         => ($keep === false) ? 30 : max(1, (int)$keep),
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

  bk_log("Backup borrado: {$file}", 'info');
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

/**
 * Crea backup SQL (y opcional .gz)
 * Devuelve el nombre final del archivo o null en error.
 */
function backup_create(?string &$err = null): ?string {
  $cfg = backup_cfg();
  $dir = backups_dir();

  // exec habilitado?
  $disabled = (string)ini_get('disable_functions');
  if ($disabled !== '' && stripos($disabled, 'exec') !== false) {
    $err = 'La función exec() está deshabilitada en PHP (disable_functions).';
    bk_log($err, 'error');
    return null;
  }
  if (!function_exists('exec')) {
    $err = 'La función exec() no está disponible.';
    bk_log($err, 'error');
    return null;
  }

  $nameBase = 'kiosco_' . date('Ymd_His');
  $sqlFile  = $nameBase . '.sql';
  $sqlPath  = $dir . DIRECTORY_SEPARATOR . $sqlFile;

  // Validar conexión PDO temprano
  try {
    getPDO();
  } catch (Throwable $e) {
    $err = 'No se pudo conectar a la base: ' . $e->getMessage();
    bk_log($err, 'error');
    return null;
  }

  $dbHost = defined('DB_HOST') ? (string)DB_HOST : '127.0.0.1';
  $dbPort = defined('DB_PORT') ? (int)DB_PORT : 3306;
  $dbName = defined('DB_NAME') ? (string)DB_NAME : '';
  $dbUser = defined('DB_USER') ? (string)DB_USER : 'root';
  $dbPass = defined('DB_PASS') ? (string)DB_PASS : '';

  if ($dbName === '') {
    $err = 'DB_NAME no está configurado.';
    bk_log($err, 'error');
    return null;
  }

  // mysqldump
  $mysqldump = defined('MYSQLDUMP_BIN') ? (string)MYSQLDUMP_BIN : 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
  if (PHP_OS_FAMILY === 'Windows') {
    if (!is_file($mysqldump)) $mysqldump = 'mysqldump';
  }

  // Quote seguro por OS (NO usar escapeshellarg en Windows cmd.exe)
  $q = function(string $s): string {
    if (PHP_OS_FAMILY === 'Windows') {
      return '"' . str_replace('"', '\"', $s) . '"';
    }
    return escapeshellarg($s);
  };

  // Password: mejor por env (evita líos de quoting)
  $prefix = '';
  if ($dbPass !== '') {
    if (PHP_OS_FAMILY === 'Windows') {
      $safe = str_replace('"', '\"', $dbPass);
      $prefix = 'set "MYSQL_PWD=' . $safe . '" && ';
    } else {
      $prefix = 'MYSQL_PWD=' . escapeshellarg($dbPass) . ' ';
    }
  }

  // Comando (Windows friendly)
  $cmd = $prefix
    . $q($mysqldump)
    . ' --host=' . $q($dbHost)
    . ' --port=' . (int)$dbPort
    . ' --user=' . $q($dbUser)
    . ' --routines --triggers --single-transaction --quick --skip-lock-tables --default-character-set=utf8mb4'
    . ' --result-file=' . $q($sqlPath)
    . ' ' . $q($dbName)
    . ' 2>&1';

  $out = [];
  $exitCode = 0;
  @exec($cmd, $out, $exitCode);

  if ($exitCode !== 0 || !is_file($sqlPath) || filesize($sqlPath) === 0) {
    @unlink($sqlPath);
    $tail = trim(implode("\n", array_slice($out, -10)));
    $err = 'Fallo mysqldump. ' . ($tail ? "Detalle: {$tail}" : 'Revisar ruta/credenciales/puerto.');
    bk_log("Backup ERROR mysqldump", 'error', [
      'exit' => $exitCode,
      'cmd'  => $cmd,
      'tail' => $tail,
    ]);
    return null;
  }

  $finalPath = $sqlPath;
  $finalFile = $sqlFile;

  // Compresión opcional
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
      bk_log($err, 'error');
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

  // Copia externa opcional
  $copyErr = null;
  if ($cfg['external_dir'] !== '') {
    if (!backup_copy_external($finalPath, $cfg['external_dir'], $copyErr)) {
      bk_log("Backup WARN copy external: {$copyErr}", 'warning');
    }
  }

  // Prune
  backup_prune_keep_last((int)$cfg['keep']);

  bk_log("Backup creado: {$finalFile}", 'info');
  return $finalFile;
}

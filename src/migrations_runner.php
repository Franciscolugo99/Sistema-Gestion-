<?php
declare(strict_types=1);

/**
 * src/migrations_runner.php
 * Runner simple de migraciones (SQL) para FLUS.
 * - Soporta DELIMITER (triggers/views) ejecutando statements por separado.
 * - Guarda estado en schema_migrations.
 * - DEBUG: si falla, muestra archivo + nro de statement + preview del SQL.
 * - Evita PREPARE en migraciones: tolera errores "ya aplicado" sin necesitar SQL dinámico.
 */

if (defined('FLUS_MIGRATIONS_RUNNER_LOADED')) return;
define('FLUS_MIGRATIONS_RUNNER_LOADED', true);

function flus_sha1_file(string $path): string {
  return is_file($path) ? sha1_file($path) : '';
}

function flus_db_has_tables(PDO $pdo): bool {
  $stmt = $pdo->query("SHOW TABLES");
  if (!$stmt) return false;
  $rows = $stmt->fetchAll(PDO::FETCH_NUM);
  foreach ($rows as $r) {
    $t = (string)($r[0] ?? '');
    if ($t !== '' && strtolower($t) !== 'schema_migrations') return true;
  }
  return false;
}

function flus_ensure_schema_migrations(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `filename` varchar(255) NOT NULL,
    `checksum` char(40) NOT NULL,
    `applied_at` datetime NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_schema_migrations_filename` (`filename`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function flus_get_applied_migrations(PDO $pdo): array {
  flus_ensure_schema_migrations($pdo);
  $stmt = $pdo->query("SELECT filename, checksum FROM schema_migrations");
  $out = [];
  if (!$stmt) return $out;
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $out[(string)$row['filename']] = (string)$row['checksum'];
  }
  return $out;
}

function flus_mark_migration(PDO $pdo, string $filename, string $checksum, bool $ignore = false): void {
  $f = $pdo->quote($filename);
  $c = $pdo->quote($checksum);
  $sql = ($ignore ? "INSERT IGNORE" : "INSERT")
       . " INTO schema_migrations (filename, checksum, applied_at) VALUES ($f, $c, NOW())";
  $pdo->exec($sql);
}

/**
 * Ejecuta un statement, tolerando errores "ya aplicado" para evitar SQL con PREPARE/EXECUTE.
 * Devuelve true si ejecutó, false si lo ignoró (ya aplicado/no existe).
 */
function flus_exec_statement(PDO $pdo, string $sql, string $file, int $n): bool {
  $sqlTrim = trim($sql);
  if ($sqlTrim === '') return false;

  try {
    $pdo->exec($sqlTrim);
    return true;
  } catch (PDOException $e) {
    // MySQL/MariaDB error code (2do elemento de errorInfo)
    $errno = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;

    // Errores tolerables típicos de migraciones idempotentes:
    // 1060 duplicate column, 1061 duplicate key, 1050 table exists, 1091 can't drop (no existe)
    $tolerable = [1060, 1061, 1050, 1091];

    if (in_array($errno, $tolerable, true)) {
      // Ignorar y seguir
      return false;
    }

    // Si es 1295, casi seguro hay PREPARE/EXECUTE en el SQL o un comando interno no soportado
    if ($errno === 1295) {
      $preview = substr(preg_replace('/\s+/', ' ', $sqlTrim), 0, 220);
      throw new RuntimeException(
        "1295 en $file (stmt #$n). Probable SQL con PREPARE/EXECUTE o comando no soportado en prepared.\nSQL: $preview",
        0,
        $e
      );
    }

    // Error real: mostrar contexto
    $preview = substr(preg_replace('/\s+/', ' ', $sqlTrim), 0, 220);
    throw new RuntimeException(
      "Error en $file (stmt #$n) errno=$errno\nSQL: $preview\nMensaje: " . $e->getMessage(),
      0,
      $e
    );
  }
}

/**
 * Ejecuta un archivo SQL soportando DELIMITER.
 * Devuelve cantidad de statements ejecutados (no cuenta los tolerados/ignorados).
 */
function flus_exec_sql_file(PDO $pdo, string $path): int {
  if (!is_file($path)) throw new RuntimeException("No existe el archivo: $path");
  $content = file_get_contents($path);
  if ($content === false) throw new RuntimeException("No se pudo leer: $path");

  // Quitar BOM UTF-8
  if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
    $content = substr($content, 3);
  }

  $fileName = basename($path);
  $delimiter = ';';
  $buffer = '';
  $executed = 0;
  $stmtNum = 0;

  $lines = preg_split("/\r\n|\n|\r/", $content);
  foreach ($lines as $line) {
    $trimLeft = ltrim($line);

    // Saltar comentarios de línea completa
    if ($trimLeft === '' || str_starts_with($trimLeft, '--') || str_starts_with($trimLeft, '#')) {
      continue;
    }

    // Cambios de delimiter
    if (preg_match('/^DELIMITER\s+(.+)$/i', trim($line), $m)) {
      $delimiter = $m[1];
      continue;
    }

    $buffer .= $line . "\n";

    $bufTrim = rtrim($buffer);
    if ($delimiter !== '' && str_ends_with($bufTrim, $delimiter)) {
      $stmtSql = rtrim(substr($bufTrim, 0, -strlen($delimiter)));
      $buffer = '';

      $stmtSql = trim($stmtSql);
      if ($stmtSql === '') continue;

      $stmtNum++;
      $did = flus_exec_statement($pdo, $stmtSql, $fileName, $stmtNum);
      if ($did) $executed++;
    }
  }

  $tail = trim($buffer);
  if ($tail !== '') {
    $stmtNum++;
    $did = flus_exec_statement($pdo, $tail, $fileName, $stmtNum);
    if ($did) $executed++;
  }

  return $executed;
}

/**
 * Aplica migraciones desde $migrationsDir.
 */
function flus_apply_migrations(PDO $pdo, string $migrationsDir, bool $allowBaseline = true): array {
  if (!is_dir($migrationsDir)) throw new RuntimeException("No existe migrations/: $migrationsDir");

  // Importante: migraciones con DDL/triggers/views => emulación ON
  $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

  flus_ensure_schema_migrations($pdo);
  $applied = flus_get_applied_migrations($pdo);

  $files = glob(rtrim($migrationsDir, '/\\') . '/*.sql');
  sort($files, SORT_NATURAL);

  $dbHasTables = flus_db_has_tables($pdo);

  $results = [
    'db_has_tables' => $dbHasTables,
    'applied' => [],
    'skipped' => [],
    'baseline' => [],
  ];

  // Baseline si DB ya tiene tablas
  if ($dbHasTables && $allowBaseline) {
    $baseName = '001_init_schema.sql';
    $basePath = rtrim($migrationsDir, '/\\') . '/' . $baseName;
    if (is_file($basePath) && !isset($applied[$baseName])) {
      $checksum = flus_sha1_file($basePath);
      flus_mark_migration($pdo, $baseName, $checksum, true);
      $results['baseline'][] = $baseName;
      $applied[$baseName] = $checksum;
    }
  }

  foreach ($files as $f) {
    $name = basename($f);
    $checksum = flus_sha1_file($f);

    if (isset($applied[$name])) {
      $results['skipped'][] = $name;
      continue;
    }

    // No uses transacciones para DDL “a full”, pero si ya hay una abierta, respetala.
    if (!$pdo->inTransaction()) {
      $pdo->beginTransaction();
    }

    try {
      $count = flus_exec_sql_file($pdo, $f);
      flus_mark_migration($pdo, $name, $checksum, false);

      if ($pdo->inTransaction()) $pdo->commit();
      $results['applied'][] = ['file' => $name, 'statements' => $count];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        try { $pdo->rollBack(); } catch (Throwable $ignored) {}
      }
      throw $e;
    }
  }

  return $results;
}

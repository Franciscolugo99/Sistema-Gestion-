<?php
declare(strict_types=1);

/**
 * src/db_helpers.php
 * Helpers de esquema DB (MySQL/MariaDB) – centralizados para FLUS
 *
 * Fuente preferida: src/db_schema.php (cache + tolerancia a permisos).
 * Este archivo mantiene nombres legacy:
 *  - has_table / has_column / has_view
 *  - tableExists / columnExists
 */

$__flus_schema_helpers = __DIR__ . '/db_schema.php';
if (is_file($__flus_schema_helpers)) {
  require_once $__flus_schema_helpers;
}

if (!function_exists('_flus_dbname')) {
  function _flus_dbname(PDO $pdo): string {
    if (function_exists('flus_current_db')) {
      return (string)flus_current_db($pdo);
    }

    static $cache = [];
    $id = spl_object_id($pdo);
    if (!isset($cache[$id])) {
      try {
        $q = $pdo->query('SELECT DATABASE()');
        $db = $q ? $q->fetchColumn() : '';
        $cache[$id] = is_string($db) ? $db : '';
      } catch (Throwable $e) {
        $cache[$id] = '';
      }
    }
    return $cache[$id];
  }
}

if (!function_exists('has_table')) {
  function has_table(PDO $pdo, string $table, ?string $schema = null): bool {
    if (function_exists('flus_table_exists')) {
      return (bool)flus_table_exists($pdo, $table, $schema);
    }

    $schema = $schema ?: _flus_dbname($pdo);
    if ($schema === '') return false;

    try {
      $sql = "SELECT 1
              FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = :s AND TABLE_NAME = :t
              LIMIT 1";
      $st = $pdo->prepare($sql);
      $st->execute([':s' => $schema, ':t' => $table]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('has_column')) {
  function has_column(PDO $pdo, string $table, string $column, ?string $schema = null): bool {
    if (function_exists('flus_column_exists')) {
      return (bool)flus_column_exists($pdo, $table, $column, $schema);
    }

    $schema = $schema ?: _flus_dbname($pdo);
    if ($schema === '') return false;

    try {
      $sql = "SELECT 1
              FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = :s AND TABLE_NAME = :t AND COLUMN_NAME = :c
              LIMIT 1";
      $st = $pdo->prepare($sql);
      $st->execute([':s' => $schema, ':t' => $table, ':c' => $column]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('has_view')) {
  function has_view(PDO $pdo, string $view, ?string $schema = null): bool {
    $schema = $schema ?: _flus_dbname($pdo);
    if ($schema === '') return false;

    try {
      $sql = "SELECT 1
              FROM INFORMATION_SCHEMA.VIEWS
              WHERE TABLE_SCHEMA = :s AND TABLE_NAME = :v
              LIMIT 1";
      $st = $pdo->prepare($sql);
      $st->execute([':s' => $schema, ':v' => $view]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

/**
 * Aliases temporales para no romper código existente
 */
if (!function_exists('tableExists')) {
  function tableExists(PDO $pdo, string $table): bool { return has_table($pdo, $table); }
}
if (!function_exists('columnExists')) {
  function columnExists(PDO $pdo, string $table, string $column): bool { return has_column($pdo, $table, $column); }
}


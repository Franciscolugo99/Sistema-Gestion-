<?php
declare(strict_types=1);

/**
 * Helpers de esquema DB (FLUS)
 * - flus_table_exists()
 * - flus_column_exists()
 * - flus_first_existing_column()
 * - flus_table_columns()
 */

function flus_schema_identifier_ok(string $identifier): bool {
  return $identifier !== '' && (bool)preg_match('/^[A-Za-z0-9_]+$/', $identifier);
}

function flus_current_db(PDO $pdo): string {
  static $cache = [];
  $id = spl_object_id($pdo);

  if (!isset($cache[$id])) {
    try {
      $q = $pdo->query('SELECT DATABASE()');
      $db = $q ? $q->fetchColumn() : '';
      $cache[$id] = is_string($db) ? $db : '';
    } catch (Throwable $e) {
      // Do not break on connection or privilege issues.
      $cache[$id] = '';
    }
  }
  return $cache[$id];
}

function flus_schema_probe_table(PDO $pdo, string $table): bool {
  if (!flus_schema_identifier_ok($table)) {
    return false;
  }

  try {
    $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 0');
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

/**
 * Centralized fallback for installs without information_schema access.
 *
 * @return array<string,true>
 */
function flus_schema_show_columns_set(PDO $pdo, string $table): array {
  if (!flus_schema_identifier_ok($table)) {
    return [];
  }

  try {
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
    if (!$stmt) {
      return [];
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $cols = [];
    foreach ($rows as $row) {
      $name = (string)($row['Field'] ?? '');
      if ($name !== '') {
        $cols[$name] = true;
      }
    }
    return $cols;
  } catch (Throwable $e) {
    return [];
  }
}

function flus_table_exists(PDO $pdo, string $table, ?string $schema = null): bool {
  static $memo = [];

  $schema = $schema ?: flus_current_db($pdo);
  if ($schema === '') return false;

  $key = spl_object_id($pdo) . '|' . $schema . '|' . $table;
  if (array_key_exists($key, $memo)) return (bool)$memo[$key];

  try {
    $stmt = $pdo->prepare(
      "SELECT 1
       FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = ?
        AND TABLE_NAME = ?
      LIMIT 1"
    );
    if (!$stmt) {
      $memo[$key] = false;
      return false;
    }
    $stmt->execute([$schema, $table]);
    $memo[$key] = (bool)$stmt->fetchColumn();
  } catch (Throwable $e) {
    $memo[$key] = false;
  }

  if (!$memo[$key] && $schema === flus_current_db($pdo)) {
    $memo[$key] = flus_schema_probe_table($pdo, $table);
  }

  return (bool)$memo[$key];
}

/**
 * Cache all columns known for a table.
 *
 * @return array<string,true>
 */
function flus_columns_set(PDO $pdo, string $schema, string $table): array {
  static $colsMemo = [];

  $memoKey = spl_object_id($pdo) . '|' . $schema . '|' . $table;
  if (!isset($colsMemo[$memoKey])) {
    try {
      $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ?
          AND TABLE_NAME = ?"
      );
      if (!$stmt) {
        $colsMemo[$memoKey] = [];
        return $colsMemo[$memoKey];
      }
      $stmt->execute([$schema, $table]);
      $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
      $colsMemo[$memoKey] = array_fill_keys(array_map('strval', (array)$cols), true);
    } catch (Throwable $e) {
      $colsMemo[$memoKey] = [];
    }

    if ($colsMemo[$memoKey] === [] && $schema === flus_current_db($pdo) && flus_schema_probe_table($pdo, $table)) {
      $colsMemo[$memoKey] = flus_schema_show_columns_set($pdo, $table);
    }
  }

  return $colsMemo[$memoKey];
}

/**
 * Return metadata for one column.
 *
 * @return array<string,mixed>|null
 */
function flus_column_metadata(PDO $pdo, string $table, string $column, ?string $schema = null): ?array {
  static $metaMemo = [];

  $schema = $schema ?: flus_current_db($pdo);
  if ($schema === '') return null;
  if (!flus_table_exists($pdo, $table, $schema)) return null;

  $memoKey = spl_object_id($pdo) . '|' . $schema . '|' . $table . '|' . $column;
  if (array_key_exists($memoKey, $metaMemo)) return $metaMemo[$memoKey];

  try {
    $stmt = $pdo->prepare(
      "SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
         FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ?
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1"
    );
    if (!$stmt) {
      $metaMemo[$memoKey] = null;
      return null;
    }
    $stmt->execute([$schema, $table, $column]);
    $metaMemo[$memoKey] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return $metaMemo[$memoKey];
  } catch (Throwable $e) {
    $metaMemo[$memoKey] = null;
    return null;
  }
}

/**
 * Return the list of known columns for a table.
 *
 * @return array<int,string>
 */
function flus_table_columns(PDO $pdo, string $table, ?string $schema = null): array {
  $schema = $schema ?: flus_current_db($pdo);
  if ($schema === '') return [];
  if (!flus_table_exists($pdo, $table, $schema)) return [];

  return array_keys(flus_columns_set($pdo, $schema, $table));
}

/**
 * Check whether a column exists in a table.
 */
function flus_column_exists(PDO $pdo, string $table, string $column, ?string $schema = null): bool {
  $schema = $schema ?: flus_current_db($pdo);
  if ($schema === '') return false;
  if (!flus_table_exists($pdo, $table, $schema)) return false;

  $set = flus_columns_set($pdo, $schema, $table);
  return isset($set[$column]);
}

/**
 * Return the first candidate column that exists in the table.
 */
function flus_first_existing_column(PDO $pdo, string $table, array $candidates, ?string $schema = null): ?string {
  $schema = $schema ?: flus_current_db($pdo);
  if ($schema === '') return null;
  if (!$candidates) return null;
  if (!flus_table_exists($pdo, $table, $schema)) return null;

  $set = flus_columns_set($pdo, $schema, $table);

  foreach ($candidates as $col) {
    $col = (string)$col;
    if (isset($set[$col])) return $col;
  }
  return null;
}
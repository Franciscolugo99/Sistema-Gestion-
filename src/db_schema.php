<?php
declare(strict_types=1);

/**
 * Helpers de esquema DB (FLUS)
 * - flus_table_exists()
 * - flus_first_existing_column()
 */

function flus_current_db(PDO $pdo): string {
  static $cache = [];
  $id = spl_object_id($pdo);

  if (!isset($cache[$id])) {
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $cache[$id] = is_string($db) ? $db : '';
  }
  return $cache[$id];
}

function flus_table_exists(PDO $pdo, string $table, ?string $schema = null): bool {
  static $memo = [];

  $schema = $schema ?: flus_current_db($pdo);
  if ($schema === '') return false;

  $key = spl_object_id($pdo) . '|' . $schema . '|' . $table;
  if (array_key_exists($key, $memo)) return (bool)$memo[$key];

  $stmt = $pdo->prepare(
    "SELECT 1
       FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = ?
        AND TABLE_NAME = ?
      LIMIT 1"
  );
  $stmt->execute([$schema, $table]);

  $memo[$key] = (bool)$stmt->fetchColumn();
  return (bool)$memo[$key];
}

/**
 * Devuelve el primer nombre de columna que exista en la tabla, según orden de $candidates.
 * Si no existe ninguna, devuelve null.
 */
function flus_first_existing_column(PDO $pdo, string $table, array $candidates, ?string $schema = null): ?string {
  static $colsMemo = [];

  $schema = $schema ?: flus_current_db($pdo);
  if ($schema === '') return null;
  if (!$candidates) return null;

  $memoKey = spl_object_id($pdo) . '|' . $schema . '|' . $table;
  if (!isset($colsMemo[$memoKey])) {
    // Cacheamos TODAS las columnas de esa tabla (por request)
    $stmt = $pdo->prepare(
      "SELECT COLUMN_NAME
         FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = ?
          AND TABLE_NAME = ?"
    );
    $stmt->execute([$schema, $table]);
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $colsMemo[$memoKey] = array_fill_keys(array_map('strval', $cols), true);
  }

  $set = $colsMemo[$memoKey];

  foreach ($candidates as $col) {
    $col = (string)$col;
    if (isset($set[$col])) return $col;
  }
  return null;
}

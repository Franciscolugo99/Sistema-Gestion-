<?php
declare(strict_types=1);

/**
 * Helpers de esquema DB (FLUS)
 * - flus_table_exists()
 * - flus_column_exists()
 * - flus_first_existing_column()
 */

function flus_current_db(PDO $pdo): string {
  static $cache = [];
  $id = spl_object_id($pdo);

  if (!isset($cache[$id])) {
    try {
      $q = $pdo->query('SELECT DATABASE()');
      $db = $q ? $q->fetchColumn() : '';
      $cache[$id] = is_string($db) ? $db : '';
    } catch (Throwable $e) {
      // No romper por fallas de conexión/privilegios (p.ej. info_schema)
      $cache[$id] = '';
    }
  }
  return $cache[$id];
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
    if (!$stmt) { $memo[$key] = false; return false; }
    $stmt->execute([$schema, $table]);
    $memo[$key] = (bool)$stmt->fetchColumn();
    return (bool)$memo[$key];
  } catch (Throwable $e) {
    // Sin privilegios sobre information_schema o fallo DB => asumir que no existe
    $memo[$key] = false;
    return false;
  }
}

/**
 * Cachea todas las columnas existentes para una tabla.
 * @return array<string,true> set de columnas
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
      if (!$stmt) { $colsMemo[$memoKey] = []; return $colsMemo[$memoKey]; }
      $stmt->execute([$schema, $table]);
      $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
      $colsMemo[$memoKey] = array_fill_keys(array_map('strval', (array)$cols), true);
    } catch (Throwable $e) {
      // Sin privilegios o fallo DB => devolver vacío
      $colsMemo[$memoKey] = [];
    }
  }

  return $colsMemo[$memoKey];
}

/**
 * Verifica si existe una columna en la tabla.
 */
function flus_column_exists(PDO $pdo, string $table, string $column, ?string $schema = null): bool {
  $schema = $schema ?: flus_current_db($pdo);
  if ($schema === '') return false;

  // Si la tabla no existe, devolvemos false sin consultar columnas
  if (!flus_table_exists($pdo, $table, $schema)) return false;

  $set = flus_columns_set($pdo, $schema, $table);
  return isset($set[$column]);
}

/**
 * Devuelve el primer nombre de columna que exista en la tabla, según orden de $candidates.
 * Si no existe ninguna, devuelve null.
 */
function flus_first_existing_column(PDO $pdo, string $table, array $candidates, ?string $schema = null): ?string {
  $schema = $schema ?: flus_current_db($pdo);
  if ($schema === '') return null;
  if (!$candidates) return null;

  // Si la tabla no existe, evitamos consultas innecesarias
  if (!flus_table_exists($pdo, $table, $schema)) return null;

  $set = flus_columns_set($pdo, $schema, $table);

  foreach ($candidates as $col) {
    $col = (string)$col;
    if (isset($set[$col])) return $col;
  }
  return null;
}

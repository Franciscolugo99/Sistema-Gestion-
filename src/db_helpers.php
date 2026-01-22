<?php
declare(strict_types=1);

/**
 * src/db_helpers.php
 * Helpers de esquema DB (MySQL/MariaDB) – centralizados para FLUS
 */

if (!function_exists('has_table')) {
  function has_table(PDO $pdo, string $table, ?string $schema = null): bool {
    $schema = $schema ?: (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $sql = "SELECT 1
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = :s AND TABLE_NAME = :t
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':s' => $schema, ':t' => $table]);
    return (bool)$st->fetchColumn();
  }
}

if (!function_exists('has_column')) {
  function has_column(PDO $pdo, string $table, string $column, ?string $schema = null): bool {
    $schema = $schema ?: (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $sql = "SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = :s AND TABLE_NAME = :t AND COLUMN_NAME = :c
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':s' => $schema, ':t' => $table, ':t' => $table, ':c' => $column]);
    return (bool)$st->fetchColumn();
  }
}

/* Aliases temporales para no romper código existente */
if (!function_exists('tableExists')) {
  function tableExists(PDO $pdo, string $table): bool { return has_table($pdo, $table); }
}
if (!function_exists('columnExists')) {
  function columnExists(PDO $pdo, string $table, string $column): bool { return has_column($pdo, $table, $column); }
}

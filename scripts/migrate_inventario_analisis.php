<?php
/**
 * scripts/migrate_inventario_analisis.php
 * Migra/asegura columnas mínimas para InventarioAnalisis.php (idempotente).
 *
 * Uso:
 *   php scripts/migrate_inventario_analisis.php
 */

declare(strict_types=1);

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
  header('Content-Type: text/plain; charset=utf-8');
}

function out(string $msg): void { echo $msg . PHP_EOL; }

// Cargar config + PDO
$configFile = dirname(__DIR__) . '/src/config.php';
if (!is_file($configFile)) {
  out("❌ No existe src/config.php");
  exit(1);
}
require_once $configFile;

if (!function_exists('getPDO')) {
  out("❌ No existe getPDO() en src/config.php");
  exit(1);
}

try {
  $pdo = getPDO();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  out("✓ Conexión DB OK");
} catch (Throwable $e) {
  out("❌ Error DB: " . $e->getMessage());
  exit(1);
}

function hasTable(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1");
  $st->execute([':t'=>$table]);
  return (bool)$st->fetchColumn();
}

function hasColumn(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1");
  $st->execute([':t'=>$table, ':c'=>$col]);
  return (bool)$st->fetchColumn();
}

function addColumnIfMissing(PDO $pdo, string $table, string $col, string $def): void {
  if (!hasTable($pdo, $table)) {
    out("❌ Falta tabla: {$table} (no sigo)");
    throw new RuntimeException("Missing table {$table}");
  }
  if (hasColumn($pdo, $table, $col)) {
    out("[=] {$table}.{$col} OK");
    return;
  }
  out("[+] Agregando {$table}.{$col} ...");
  $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def}");
  out("    OK");
}

try {
  // Tablas mínimas
  foreach (['productos','ventas','venta_items'] as $t) {
    if (!hasTable($pdo, $t)) {
      out("❌ Falta tabla requerida: {$t}");
      exit(1);
    }
  }

  // === productos ===
  addColumnIfMissing($pdo, 'productos', 'activo',       "TINYINT(1) NOT NULL DEFAULT 1");
  addColumnIfMissing($pdo, 'productos', 'stock_minimo', "DECIMAL(12,3) NOT NULL DEFAULT 0");
  addColumnIfMissing($pdo, 'productos', 'categoria',    "VARCHAR(120) NULL");
  addColumnIfMissing($pdo, 'productos', 'proveedor_id', "INT NULL");
  addColumnIfMissing($pdo, 'productos', 'es_pesable',   "TINYINT(1) NOT NULL DEFAULT 0");
  addColumnIfMissing($pdo, 'productos', 'unidad_venta', "VARCHAR(12) NOT NULL DEFAULT 'UN'");
  addColumnIfMissing($pdo, 'productos', 'fecha_alta',   "DATETIME NULL");

  // Backfill fecha_alta si quedó NULL (para dias_sin_venta)
  out("[~] Backfill productos.fecha_alta (si hay NULL) ...");
  $pdo->exec("UPDATE productos SET fecha_alta = COALESCE(fecha_alta, NOW()) WHERE fecha_alta IS NULL");
  out("    OK");

  // === ventas === (normalmente ya existen; solo asegurar columnas usadas)
  addColumnIfMissing($pdo, 'ventas', 'estado', "VARCHAR(20) NULL");
  addColumnIfMissing($pdo, 'ventas', 'fecha',  "DATETIME NULL");
  addColumnIfMissing($pdo, 'ventas', 'total',  "DECIMAL(12,2) NULL");

  // === venta_items === (asegurar lo usado en análisis)
  addColumnIfMissing($pdo, 'venta_items', 'venta_id',    "INT NULL");
  addColumnIfMissing($pdo, 'venta_items', 'producto_id', "INT NULL");
  addColumnIfMissing($pdo, 'venta_items', 'cantidad',    "DECIMAL(12,3) NULL");
  addColumnIfMissing($pdo, 'venta_items', 'subtotal',    "DECIMAL(12,2) NULL");

  out("✅ Migración inventario_analisis: OK");

  out("");
  out("➡️ Recomendado: crear índices (performance) ejecutando:");
  out("   php scripts/add_indexes.php");

} catch (Throwable $e) {
  out("❌ ERROR: " . $e->getMessage());
  exit(1);
}

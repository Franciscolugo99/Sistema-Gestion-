<?php
/**
 * scripts/add_indexes.php
 * Crea índices recomendados si no existen (idempotente y seguro).
 * Uso: php scripts/add_indexes.php
 */
declare(strict_types=1);

/* Bootstrap FLUS */
$bootstrapCandidates = [
  __DIR__ . '/../public/bootstrap.php',
  __DIR__ . '/../../public/bootstrap.php',
];
$boot = null;
foreach ($bootstrapCandidates as $p) { if (is_file($p)) { $boot = $p; break; } }
if ($boot === null) {
  fwrite(STDERR, "No se encontró public/bootstrap.php\n");
  exit(1);
}
require_once $boot;

$pdo = function_exists('getPDO') ? getPDO() : null;
if (!$pdo instanceof PDO) {
  fwrite(STDERR, "PDO no disponible\n");
  exit(1);
}

/* Helpers locales (por si no existen en el proyecto) */
if (!function_exists('db_current_schema')) {
  function db_current_schema(PDO $pdo): string {
    $s = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($s === '') throw new RuntimeException('No se pudo resolver DATABASE()');
    return $s;
  }
}
if (!function_exists('has_table')) {
  function has_table(PDO $pdo, string $table): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':t'=>$table]);
    return (bool)$st->fetchColumn();
  }
}
if (!function_exists('has_column')) {
  function has_column(PDO $pdo, string $table, string $column): bool {
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':t'=>$table, ':c'=>$column]);
    return (bool)$st->fetchColumn();
  }
}

/* Índices */
function has_index(PDO $pdo, string $table, string $index): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([':t'=>$table, ':i'=>$index]);
  return (bool)$st->fetchColumn();
}

function ensure_index(PDO $pdo, string $table, string $index, string $columns): void {
  if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) throw new RuntimeException("Tabla inválida: $table");
  if (!preg_match('/^[A-Za-z0-9_]+$/', $index)) throw new RuntimeException("Índice inválido: $index");

  if (has_index($pdo, $table, $index)) {
    echo "[=] $table: $index existe\n";
    return;
  }
  $sql = "CREATE INDEX `$index` ON `$table` ($columns)";
  echo "[+] Creando $index en $table ... ";
  $pdo->exec($sql);
  echo "OK\n";
}

/* === ventas === */
if (has_table($pdo, 'ventas')) {
  if (has_column($pdo, 'ventas', 'fecha'))        ensure_index($pdo, 'ventas', 'idx_ventas_fecha', 'fecha');
  if (has_column($pdo, 'ventas', 'estado'))       ensure_index($pdo, 'ventas', 'idx_ventas_estado', 'estado');
  if (has_column($pdo, 'ventas', 'medio_pago'))   ensure_index($pdo, 'ventas', 'idx_ventas_medio', 'medio_pago');
  if (has_column($pdo, 'ventas', 'estado') && has_column($pdo, 'ventas', 'fecha')) {
    ensure_index($pdo, 'ventas', 'idx_ventas_estado_fecha', 'estado, fecha');
  }
}

/* === venta_items === */
if (has_table($pdo, 'venta_items')) {
  // Detectar nombres de columnas más comunes
  $ventaId = null; foreach (['venta_id','id_venta','ventaId'] as $c) { if (has_column($pdo,'venta_items',$c)) { $ventaId = $c; break; } }
  $prodId  = null; foreach (['producto_id','id_producto','productoId'] as $c) { if (has_column($pdo,'venta_items',$c)) { $prodId  = $c; break; } }
  if ($ventaId) ensure_index($pdo, 'venta_items', 'idx_vi_venta', "`$ventaId`");
  if ($prodId)  ensure_index($pdo, 'venta_items', 'idx_vi_producto', "`$prodId`");
}

/* === productos === */
if (has_table($pdo, 'productos')) {
  $catCol = null; foreach (['categoria','rubro','familia'] as $c) { if (has_column($pdo,'productos',$c)) { $catCol = $c; break; } }
  if ($catCol) ensure_index($pdo, 'productos', 'idx_prod_categoria', "`$catCol`");
}

/* === venta_pagos === */
if (has_table($pdo, 'venta_pagos')) {
  if (has_column($pdo,'venta_pagos','venta_id'))   ensure_index($pdo, 'venta_pagos', 'idx_vp_venta', 'venta_id');
  if (has_column($pdo,'venta_pagos','medio_pago')) ensure_index($pdo, 'venta_pagos', 'idx_vp_medio', 'medio_pago');
}

/* === venta_promos === */
if (has_table($pdo, 'venta_promos')) {
  if (has_column($pdo,'venta_promos','venta_id')) ensure_index($pdo, 'venta_promos', 'idx_vpromo_venta', 'venta_id');
}

echo "Listo.\n";

<?php
/**
 * kpis_categoria_helper.php
 * Construye condición SQL para filtrar ventas por categoría de producto.
 */
declare(strict_types=1);

function flus_first_existing_column(PDO $pdo, string $table, array $candidates): ?string {
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    if (!$dbName) return null;
    $in = implode(',', array_map(static fn($c) => $pdo->quote($c), $candidates));
    $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND COLUMN_NAME IN ($in)
            ORDER BY FIELD(COLUMN_NAME, $in) LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':db'=>$dbName, ':tbl'=>$table]);
    $col = $st->fetchColumn();
    return $col ? (string)$col : null;
}

function kpis_categoria_condition(PDO $pdo, ?string $categoria, ?string $prodCatCol): string {
    $categoria = is_string($categoria) ? trim($categoria) : '';
    if ($categoria === '' || !$prodCatCol) return '';

    $ventaIdCols    = ['venta_id','id_venta','ventaId'];
    $productoIdCols = ['producto_id','id_producto','productoId'];
    $viVentaCol = null; $viProdCol = null;

    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $st = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'venta_items'");
    $st->execute([':db'=>$dbName]);
    $cols = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];

    foreach ($ventaIdCols as $c)  if (in_array($c, $cols, true)) { $viVentaCol = $c; break; }
    foreach ($productoIdCols as $c) if (in_array($c, $cols, true)) { $viProdCol = $c; break; }

    $viVentaCol = $viVentaCol ?: 'venta_id';
    $viProdCol  = $viProdCol  ?: 'producto_id';

    if (strcasecmp($categoria, 'Sin Categoría') === 0) {
        return "v.id IN (
            SELECT vi.`{$viVentaCol}`
            FROM venta_items vi
            JOIN productos p ON p.id = vi.`{$viProdCol}`
            WHERE (p.`{$prodCatCol}` IS NULL OR TRIM(p.`{$prodCatCol}`) = '')
        )";
    }

    $catVal = $pdo->quote($categoria);
    return "v.id IN (
        SELECT vi.`{$viVentaCol}`
        FROM venta_items vi
        JOIN productos p ON p.id = vi.`{$viProdCol}`
        WHERE p.`{$prodCatCol}` = {$catVal}
    )";
}

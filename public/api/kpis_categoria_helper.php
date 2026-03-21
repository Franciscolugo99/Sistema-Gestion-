<?php
/**
 * kpis_categoria_helper.php
 * Construye condición SQL para filtrar ventas por categoría de producto.
 */
declare(strict_types=1);

// Evitar redeclare si el helper ya está cargado desde src/db_schema.php (bootstrap)
if (!function_exists('flus_first_existing_column')) {
    $schemaHelpers = dirname(__DIR__, 3) . '/src/db_schema.php'; // <root>/src/db_schema.php
    if (is_file($schemaHelpers)) {
        require_once $schemaHelpers;
    }
}

// Fallback ultra-compat (instalaciones viejas / sin src/db_schema.php)
if (!function_exists('flus_first_existing_column')) {
    function flus_first_existing_column(PDO $pdo, string $table, array $candidates): ?string {
        if (function_exists('flus_table_columns')) {
            $available = array_map('strval', flus_table_columns($pdo, $table) ?: []);
            foreach ($candidates as $candidate) {
                if (in_array($candidate, $available, true)) {
                    return (string)$candidate;
                }
            }
        }
        return null;
    }
}

function kpis_categoria_condition(PDO $pdo, ?string $categoria, ?string $prodCatCol): string {
    $categoria = is_string($categoria) ? trim($categoria) : '';
    if ($categoria === '' || !$prodCatCol) return '';

    $ventaIdCols    = ['venta_id','id_venta','ventaId'];
    $productoIdCols = ['producto_id','id_producto','productoId'];
    $viVentaCol = null; $viProdCol = null;

    $cols = function_exists('flus_table_columns')
        ? (flus_table_columns($pdo, 'venta_items') ?: [])
        : [];

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

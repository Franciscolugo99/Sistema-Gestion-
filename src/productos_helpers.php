<?php
declare(strict_types=1);

if (!function_exists('flus_calcular_estado_producto')) {
    function flus_calcular_estado_producto(array $producto): string
    {
        if (!(int)($producto['activo'] ?? 0)) {
            return 'inactivo';
        }

        $stock = (float)($producto['stock'] ?? 0);
        $stockMinimo = (float)($producto['stock_minimo'] ?? 0);

        if ($stock <= 0) {
            return 'sin';
        }

        if ($stock <= $stockMinimo) {
            return 'bajo';
        }

        return 'ok';
    }
}

if (!function_exists('flus_producto_stock_input_value')) {
    function flus_producto_stock_input_value($storedValue, string $unidadVenta): float
    {
        $value = (float)$storedValue;
        $unidad = strtoupper(trim($unidadVenta));

        return match ($unidad) {
            'G', 'ML' => $value * 100,
            default => $value,
        };
    }
}

if (!function_exists('flus_producto_unidad_descripcion')) {
    function flus_producto_unidad_descripcion(?string $unidadVenta, bool $esPesable = true): string
    {
        $unidad = strtoupper(trim((string)$unidadVenta));

        if (!$esPesable || $unidad === '' || $unidad === 'UNIDAD') {
            return 'Unidad';
        }

        return match ($unidad) {
            'KG' => 'Por kilo',
            'G' => 'Por 100 g',
            'LT' => 'Por litro',
            'ML' => 'Por 100 ml',
            default => $unidad,
        };
    }
}

if (!function_exists('flus_stock_search_order_sql')) {
    function flus_stock_search_order_sql(string $buscar): array
    {
        $buscar = trim($buscar);
        if ($buscar === '') {
            return [
                'sql' => "ORDER BY
                    CASE
                        WHEN activo = 1 AND stock <= 0 THEN 1
                        WHEN activo = 1 AND stock <= stock_minimo THEN 2
                        WHEN activo = 1 THEN 3
                        ELSE 4
                    END,
                    nombre ASC",
                'params' => [],
            ];
        }

        return [
            'sql' => "ORDER BY
                CASE
                    WHEN codigo = :order_codigo_exact THEN 0
                    WHEN activo = 1 AND codigo LIKE :order_codigo_prefix THEN 1
                    WHEN activo = 1 AND nombre LIKE :order_nombre THEN 2
                    WHEN activo = 1 AND categoria LIKE :order_categoria THEN 3
                    WHEN activo = 1 AND marca LIKE :order_marca THEN 4
                    WHEN activo = 1 AND proveedor LIKE :order_proveedor THEN 5
                    ELSE 6
                END,
                CASE
                    WHEN activo = 1 AND stock <= 0 THEN 1
                    WHEN activo = 1 AND stock <= stock_minimo THEN 2
                    WHEN activo = 1 THEN 3
                    ELSE 4
                END,
                nombre ASC",
            'params' => [
                ':order_codigo_exact' => $buscar,
                ':order_codigo_prefix' => $buscar . '%',
                ':order_nombre' => '%' . $buscar . '%',
                ':order_categoria' => '%' . $buscar . '%',
                ':order_marca' => '%' . $buscar . '%',
                ':order_proveedor' => '%' . $buscar . '%',
            ],
        ];
    }
}

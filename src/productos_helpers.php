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

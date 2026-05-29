<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const FLUS_RECARGO_HORARIO_KEYS = [
    'enabled' => 'recargo_horario_enabled',
    'nombre' => 'recargo_horario_nombre',
    'porcentaje' => 'recargo_horario_porcentaje',
    'inicio' => 'recargo_horario_inicio',
    'fin' => 'recargo_horario_fin',
    'dias' => 'recargo_horario_dias',
    'redondeo' => 'recargo_horario_redondeo',
];

function flus_recargo_horario_defaults(): array
{
    return [
        'enabled' => false,
        'nombre' => 'Horario especial',
        'porcentaje' => 10.0,
        'inicio' => '22:00',
        'fin' => '06:00',
        'dias' => [1, 2, 3, 4, 5, 6, 7],
        'redondeo' => 'NINGUNO',
    ];
}

function flus_recargo_horario_normalizar_hora(mixed $value, string $fallback): string
{
    $raw = trim((string)$value);
    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $raw, $m) !== 1) {
        return $fallback;
    }

    return str_pad((string)(int)$m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2];
}

function flus_recargo_horario_normalizar_dias(mixed $value): array
{
    if (is_string($value)) {
        $parts = preg_split('/[,\s]+/', trim($value)) ?: [];
    } elseif (is_array($value)) {
        $parts = $value;
    } else {
        $parts = [];
    }

    $dias = [];
    foreach ($parts as $part) {
        $dia = (int)$part;
        if ($dia >= 1 && $dia <= 7) {
            $dias[$dia] = $dia;
        }
    }

    return $dias ? array_values($dias) : [1, 2, 3, 4, 5, 6, 7];
}

function flus_recargo_horario_normalizar_config(array $raw): array
{
    $defaults = flus_recargo_horario_defaults();
    $porcentaje = (float)($raw['porcentaje'] ?? $defaults['porcentaje']);
    $porcentaje = max(1.0, min(100.0, $porcentaje));

    $nombre = trim((string)($raw['nombre'] ?? $defaults['nombre']));
    if ($nombre === '') {
        $nombre = $defaults['nombre'];
    }

    $nombre = function_exists('mb_substr') ? mb_substr($nombre, 0, 80) : substr($nombre, 0, 80);
    $redondeo = flus_recargo_horario_normalizar_redondeo($raw['redondeo'] ?? $defaults['redondeo']);

    return [
        'enabled' => filter_var($raw['enabled'] ?? $defaults['enabled'], FILTER_VALIDATE_BOOL),
        'nombre' => $nombre,
        'porcentaje' => round($porcentaje, 2),
        'inicio' => flus_recargo_horario_normalizar_hora($raw['inicio'] ?? '', $defaults['inicio']),
        'fin' => flus_recargo_horario_normalizar_hora($raw['fin'] ?? '', $defaults['fin']),
        'dias' => flus_recargo_horario_normalizar_dias($raw['dias'] ?? $defaults['dias']),
        'redondeo' => $redondeo,
    ];
}

function flus_recargo_horario_redondeo_options(): array
{
    return [
        'NINGUNO' => 'Sin redondeo',
        'ARRIBA_10' => 'Hacia arriba a $10',
        'ARRIBA_50' => 'Hacia arriba a $50',
        'ARRIBA_100' => 'Hacia arriba a $100',
        'PSICO_90' => 'Psicologico: siguiente terminacion 90',
    ];
}

function flus_recargo_horario_normalizar_redondeo(mixed $value): string
{
    $mode = strtoupper(trim((string)$value));
    return array_key_exists($mode, flus_recargo_horario_redondeo_options()) ? $mode : 'NINGUNO';
}

function flus_recargo_horario_redondeo_label(string $mode): string
{
    $mode = flus_recargo_horario_normalizar_redondeo($mode);
    $options = flus_recargo_horario_redondeo_options();
    return $options[$mode] ?? $options['NINGUNO'];
}

function flus_recargo_horario_config(PDO $pdo): array
{
    $d = flus_recargo_horario_defaults();

    return flus_recargo_horario_normalizar_config([
        'enabled' => config_get($pdo, FLUS_RECARGO_HORARIO_KEYS['enabled'], $d['enabled'] ? '1' : '0') === '1',
        'nombre' => config_get($pdo, FLUS_RECARGO_HORARIO_KEYS['nombre'], $d['nombre']),
        'porcentaje' => config_get($pdo, FLUS_RECARGO_HORARIO_KEYS['porcentaje'], (string)$d['porcentaje']),
        'inicio' => config_get($pdo, FLUS_RECARGO_HORARIO_KEYS['inicio'], $d['inicio']),
        'fin' => config_get($pdo, FLUS_RECARGO_HORARIO_KEYS['fin'], $d['fin']),
        'dias' => config_get($pdo, FLUS_RECARGO_HORARIO_KEYS['dias'], implode(',', $d['dias'])),
        'redondeo' => config_get($pdo, FLUS_RECARGO_HORARIO_KEYS['redondeo'], $d['redondeo']),
    ]);
}

function flus_recargo_horario_save(PDO $pdo, array $input): array
{
    $config = flus_recargo_horario_normalizar_config([
        'enabled' => !empty($input['enabled']),
        'nombre' => $input['nombre'] ?? null,
        'porcentaje' => $input['porcentaje'] ?? null,
        'inicio' => $input['inicio'] ?? null,
        'fin' => $input['fin'] ?? null,
        'dias' => $input['dias'] ?? [],
        'redondeo' => $input['redondeo'] ?? null,
    ]);

    config_set($pdo, FLUS_RECARGO_HORARIO_KEYS['enabled'], $config['enabled'] ? '1' : '0');
    config_set($pdo, FLUS_RECARGO_HORARIO_KEYS['nombre'], $config['nombre']);
    config_set($pdo, FLUS_RECARGO_HORARIO_KEYS['porcentaje'], (string)$config['porcentaje']);
    config_set($pdo, FLUS_RECARGO_HORARIO_KEYS['inicio'], $config['inicio']);
    config_set($pdo, FLUS_RECARGO_HORARIO_KEYS['fin'], $config['fin']);
    config_set($pdo, FLUS_RECARGO_HORARIO_KEYS['dias'], implode(',', $config['dias']));
    config_set($pdo, FLUS_RECARGO_HORARIO_KEYS['redondeo'], $config['redondeo']);

    return $config;
}

function flus_recargo_horario_minutes(string $hhmm): int
{
    [$h, $m] = array_map('intval', explode(':', $hhmm));
    return ($h * 60) + $m;
}

function flus_recargo_horario_estado_desde_config(array $config, ?DateTimeInterface $now = null): array
{
    $now = $now ?? new DateTimeImmutable('now');
    $cfg = flus_recargo_horario_normalizar_config($config);
    $active = false;
    $currentDay = (int)$now->format('N');
    $previousDay = $currentDay === 1 ? 7 : $currentDay - 1;
    $currentMinutes = ((int)$now->format('G') * 60) + (int)$now->format('i');
    $start = flus_recargo_horario_minutes($cfg['inicio']);
    $end = flus_recargo_horario_minutes($cfg['fin']);

    if ($cfg['enabled']) {
        if ($start === $end) {
            $active = in_array($currentDay, $cfg['dias'], true);
        } elseif ($start < $end) {
            $active = in_array($currentDay, $cfg['dias'], true) && $currentMinutes >= $start && $currentMinutes < $end;
        } else {
            $active = ($currentMinutes >= $start && in_array($currentDay, $cfg['dias'], true))
                || ($currentMinutes < $end && in_array($previousDay, $cfg['dias'], true));
        }
    }

    return $cfg + [
        'active' => $active,
        'factor' => $active ? (1.0 + ($cfg['porcentaje'] / 100.0)) : 1.0,
    ];
}

function flus_recargo_horario_estado(PDO $pdo, ?DateTimeInterface $now = null): array
{
    return flus_recargo_horario_estado_desde_config(flus_recargo_horario_config($pdo), $now);
}

function flus_recargo_horario_aplicar_precio(float $precio, array $estado): float
{
    return flus_recargo_horario_aplicar_precio_detalle($precio, $estado)['precio_final'];
}

function flus_recargo_horario_redondear(float $precio, string $mode): float
{
    $precio = round($precio, 2);
    $mode = flus_recargo_horario_normalizar_redondeo($mode);
    if ($precio <= 0 || $mode === 'NINGUNO') {
        return $precio;
    }

    $multiples = [
        'ARRIBA_10' => 10,
        'ARRIBA_50' => 50,
        'ARRIBA_100' => 100,
    ];

    if (isset($multiples[$mode])) {
        $m = (float)$multiples[$mode];
        return round(ceil(($precio - 0.00001) / $m) * $m, 2);
    }

    if ($mode === 'PSICO_90') {
        $candidate = floor($precio / 100) * 100 + 90;
        if ($candidate + 0.00001 < $precio) {
            $candidate += 100;
        }
        return round(max($precio, $candidate), 2);
    }

    return $precio;
}

function flus_recargo_horario_aplicar_precio_detalle(float $precio, array $estado): array
{
    $precioBase = round($precio, 2);
    if (empty($estado['active']) || $precioBase <= 0) {
        return [
            'precio_base' => $precioBase,
            'precio_regla' => $precioBase,
            'precio_final' => $precioBase,
            'recargo_unit_monto' => 0.0,
            'redondeo_modo' => 'NINGUNO',
            'redondeo_unit_monto' => 0.0,
        ];
    }

    $factor = (float)($estado['factor'] ?? 1.0);
    $precioRegla = round($precioBase * max(1.0, $factor), 2);
    $redondeoModo = flus_recargo_horario_normalizar_redondeo($estado['redondeo'] ?? 'NINGUNO');
    $precioFinal = flus_recargo_horario_redondear($precioRegla, $redondeoModo);

    return [
        'precio_base' => $precioBase,
        'precio_regla' => $precioRegla,
        'precio_final' => $precioFinal,
        'recargo_unit_monto' => round($precioRegla - $precioBase, 2),
        'redondeo_modo' => $redondeoModo,
        'redondeo_unit_monto' => round($precioFinal - $precioRegla, 2),
    ];
}

function flus_recargo_horario_describir_ajuste(float $precioBase, float $precioAplicado, array $estado, ?array $detalle = null): ?array
{
    $precioBase = round($precioBase, 2);
    $precioAplicado = round($precioAplicado, 2);
    $unitMonto = round($precioAplicado - $precioBase, 2);

    if (empty($estado['active']) || $precioBase <= 0 || $unitMonto <= 0.00001) {
        return null;
    }

    $detalle = $detalle ?? flus_recargo_horario_aplicar_precio_detalle($precioBase, $estado);

    return [
        'tipo' => 'recargo',
        'origen' => 'horario',
        'nombre' => (string)($estado['nombre'] ?? 'Regla por horario'),
        'porcentaje' => round((float)($estado['porcentaje'] ?? 0), 3),
        'unit_monto' => $unitMonto,
        'regla_unit_monto' => round((float)($detalle['recargo_unit_monto'] ?? 0), 2),
        'redondeo_modo' => flus_recargo_horario_normalizar_redondeo($detalle['redondeo_modo'] ?? 'NINGUNO'),
        'redondeo_unit_monto' => round((float)($detalle['redondeo_unit_monto'] ?? 0), 2),
    ];
}

function flus_recargo_horario_aplicar_producto(array $producto, array $estado): array
{
    $detalle = flus_recargo_horario_aplicar_precio_detalle((float)($producto['precio'] ?? 0), $estado);
    $producto['precio'] = $detalle['precio_final'];
    if (!empty($estado['active'])) {
        $producto['recargo_horario'] = [
            'active' => true,
            'nombre' => (string)($estado['nombre'] ?? ''),
            'porcentaje' => (float)($estado['porcentaje'] ?? 0),
            'redondeo' => $detalle['redondeo_modo'],
            'redondeo_monto' => $detalle['redondeo_unit_monto'],
        ];
    }

    return $producto;
}

function flus_recargo_horario_dias_label(array $dias): string
{
    $labels = [1 => 'Lun', 2 => 'Mar', 3 => 'Mie', 4 => 'Jue', 5 => 'Vie', 6 => 'Sab', 7 => 'Dom'];
    $out = [];
    foreach (flus_recargo_horario_normalizar_dias($dias) as $dia) {
        $out[] = $labels[$dia] ?? (string)$dia;
    }

    return implode(', ', $out);
}

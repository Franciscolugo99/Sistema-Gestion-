<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const FLUS_PROMOS_CONFIG_KEYS = [
    'enabled' => 'promos_enabled',
    'schedule_enabled' => 'promos_block_schedule_enabled',
    'block_start' => 'promos_block_start',
    'block_end' => 'promos_block_end',
];

function flus_promos_config_defaults(): array
{
    return [
        'enabled' => true,
        'schedule_enabled' => false,
        'block_start' => '22:00',
        'block_end' => '06:00',
    ];
}

function flus_promos_normalize_time(mixed $value, string $fallback): string
{
    $raw = trim((string)$value);
    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $raw, $matches) !== 1) {
        return $fallback;
    }

    return str_pad((string)(int)$matches[1], 2, '0', STR_PAD_LEFT) . ':' . $matches[2];
}

function flus_promos_normalize_config(array $raw): array
{
    $defaults = flus_promos_config_defaults();

    return [
        'enabled' => filter_var($raw['enabled'] ?? $defaults['enabled'], FILTER_VALIDATE_BOOL),
        'schedule_enabled' => filter_var(
            $raw['schedule_enabled'] ?? $defaults['schedule_enabled'],
            FILTER_VALIDATE_BOOL
        ),
        'block_start' => flus_promos_normalize_time(
            $raw['block_start'] ?? '',
            $defaults['block_start']
        ),
        'block_end' => flus_promos_normalize_time(
            $raw['block_end'] ?? '',
            $defaults['block_end']
        ),
    ];
}

function flus_promos_config(PDO $pdo): array
{
    $defaults = flus_promos_config_defaults();

    return flus_promos_normalize_config([
        'enabled' => config_get(
            $pdo,
            FLUS_PROMOS_CONFIG_KEYS['enabled'],
            $defaults['enabled'] ? '1' : '0'
        ) === '1',
        'schedule_enabled' => config_get(
            $pdo,
            FLUS_PROMOS_CONFIG_KEYS['schedule_enabled'],
            $defaults['schedule_enabled'] ? '1' : '0'
        ) === '1',
        'block_start' => config_get(
            $pdo,
            FLUS_PROMOS_CONFIG_KEYS['block_start'],
            $defaults['block_start']
        ),
        'block_end' => config_get(
            $pdo,
            FLUS_PROMOS_CONFIG_KEYS['block_end'],
            $defaults['block_end']
        ),
    ]);
}

function flus_promos_save_config(PDO $pdo, array $input): array
{
    $config = flus_promos_normalize_config($input);
    $saved = [
        config_set($pdo, FLUS_PROMOS_CONFIG_KEYS['enabled'], $config['enabled'] ? '1' : '0'),
        config_set(
            $pdo,
            FLUS_PROMOS_CONFIG_KEYS['schedule_enabled'],
            $config['schedule_enabled'] ? '1' : '0'
        ),
        config_set($pdo, FLUS_PROMOS_CONFIG_KEYS['block_start'], $config['block_start']),
        config_set($pdo, FLUS_PROMOS_CONFIG_KEYS['block_end'], $config['block_end']),
    ];

    if (in_array(false, $saved, true)) {
        throw new RuntimeException('No se pudo guardar la disponibilidad de promociones.');
    }

    return $config;
}

function flus_promos_time_minutes(string $time): int
{
    [$hours, $minutes] = array_map('intval', explode(':', $time));
    return ($hours * 60) + $minutes;
}

function flus_promos_status_from_config(array $config, ?DateTimeInterface $now = null): array
{
    $cfg = flus_promos_normalize_config($config);
    $now = $now ?? new DateTimeImmutable('now');
    $currentMinutes = ((int)$now->format('G') * 60) + (int)$now->format('i');
    $start = flus_promos_time_minutes($cfg['block_start']);
    $end = flus_promos_time_minutes($cfg['block_end']);
    $blockedBySchedule = false;

    if ($cfg['schedule_enabled']) {
        if ($start === $end) {
            $blockedBySchedule = true;
        } elseif ($start < $end) {
            $blockedBySchedule = $currentMinutes >= $start && $currentMinutes < $end;
        } else {
            $blockedBySchedule = $currentMinutes >= $start || $currentMinutes < $end;
        }
    }

    $available = $cfg['enabled'] && !$blockedBySchedule;
    $reason = 'available';
    if (!$cfg['enabled']) {
        $reason = 'disabled';
    } elseif ($blockedBySchedule) {
        $reason = 'blocked_schedule';
    }

    return $cfg + [
        'available' => $available,
        'blocked_by_schedule' => $blockedBySchedule,
        'reason' => $reason,
        'server_time' => $now->format('H:i'),
    ];
}

function flus_promos_status(PDO $pdo, ?DateTimeInterface $now = null): array
{
    return flus_promos_status_from_config(flus_promos_config($pdo), $now);
}

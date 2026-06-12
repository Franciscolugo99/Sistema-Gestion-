<?php
declare(strict_types=1);

function flus_ticket_normalize_paper(?string $paper, string $fallback = '80'): string
{
    $paper = trim((string)$paper);
    if (in_array($paper, ['58', '80'], true)) {
        return $paper;
    }

    return $fallback === '58' ? '58' : '80';
}

function flus_ticket_normalize_mode(?string $mode, string $fallback = 'autoprint'): string
{
    $mode = trim((string)$mode);
    if (in_array($mode, ['autoprint', 'preview', 'none'], true)) {
        return $mode;
    }

    return in_array($fallback, ['autoprint', 'preview', 'none'], true)
        ? $fallback
        : 'autoprint';
}

function flus_ticket_global_config(PDO $pdo): array
{
    $businessLogoUrl = trim((string)config_get($pdo, 'business_logo_url', ''));
    $logoUrl = trim((string)config_get($pdo, 'ticket_logo_url', $businessLogoUrl));

    return [
        'paper' => flus_ticket_normalize_paper(config_get($pdo, 'print_ticket_paper', '80')),
        'mode' => flus_ticket_normalize_mode(config_get($pdo, 'print_ticket_mode', 'autoprint')),
        'footer' => trim((string)config_get($pdo, 'ticket_footer', 'Gracias por su compra')),
        'logo_url' => $logoUrl,
        'show_logo' => $logoUrl !== '' && config_get($pdo, 'ticket_show_logo', '0') === '1',
        'show_register' => config_get($pdo, 'ticket_show_register', '0') === '1',
        'show_cashier' => config_get($pdo, 'ticket_show_cashier', '0') === '1',
    ];
}

function flus_ticket_logo_src(?string $value): string
{
    $raw = trim(str_replace('\\', '/', (string)$value));
    if ($raw === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $raw) === 1 || str_starts_with($raw, '/')) {
        return $raw;
    }

    return $raw;
}

function flus_ticket_logo_local_path(?string $value): ?string
{
    $raw = trim(str_replace('\\', '/', (string)$value));
    if ($raw === '' || !str_starts_with($raw, 'uploads/logos/')) {
        return null;
    }

    return FLUS_ROOT . '/public/' . str_replace('/', DIRECTORY_SEPARATOR, $raw);
}

function flus_ticket_terminal_config(PDO $pdo, int $terminalId): array
{
    if ($terminalId <= 0) {
        return ['paper' => 'inherit', 'mode' => 'inherit'];
    }

    $paper = trim((string)config_get($pdo, 'terminal_' . $terminalId . '_ticket_paper', 'inherit'));
    $mode = trim((string)config_get($pdo, 'terminal_' . $terminalId . '_ticket_print_mode', 'inherit'));

    return [
        'paper' => in_array($paper, ['inherit', '58', '80'], true) ? $paper : 'inherit',
        'mode' => in_array($mode, ['inherit', 'autoprint', 'preview', 'none'], true) ? $mode : 'inherit',
    ];
}

function flus_ticket_resolved_config(PDO $pdo, int $terminalId = 0): array
{
    $global = flus_ticket_global_config($pdo);
    $terminal = flus_ticket_terminal_config($pdo, $terminalId);

    return [
        'paper' => $terminal['paper'] === 'inherit' ? $global['paper'] : $terminal['paper'],
        'mode' => $terminal['mode'] === 'inherit' ? $global['mode'] : $terminal['mode'],
        'footer' => $global['footer'],
        'global' => $global,
        'terminal' => $terminal,
    ];
}

<?php
declare(strict_types=1);

function flus_ticket_public_secret(): string
{
    if (!defined('APP_SECRET')) {
        throw new RuntimeException('APP_SECRET no está definido. Configurá un secreto fuerte para habilitar tickets públicos.');
    }

    $secret = (string)APP_SECRET;
    if (strlen($secret) < 16 || $secret === 'flus-default-secret-change-me' || str_contains($secret, 'change-me')) {
        throw new RuntimeException('APP_SECRET es débil o es un placeholder. Configurá un secreto fuerte (>= 16 chars) para habilitar tickets públicos.');
    }

    return $secret;
}

function flus_ticket_token_ttl_seconds(): int
{
    if (defined('TICKET_TOKEN_TTL_SECONDS')) {
        $ttl = (int)TICKET_TOKEN_TTL_SECONDS;
        return $ttl > 0 ? $ttl : 7 * 24 * 60 * 60;
    }

    return 7 * 24 * 60 * 60;
}

function flus_ticket_token_generate(int $ventaId, int $timestamp, string $secret = ''): string
{
    if ($ventaId <= 0 || $timestamp <= 0) {
        throw new InvalidArgumentException('Venta o timestamp inválido para token.');
    }

    $secret = $secret !== '' ? $secret : flus_ticket_public_secret();
    return substr(hash_hmac('sha256', "ticket-{$ventaId}-{$timestamp}", $secret), 0, 32);
}

function flus_ticket_token_validate(int $ventaId, int $timestamp, string $token, ?int $now = null, string $secret = ''): bool
{
    if ($ventaId <= 0 || $timestamp <= 0 || !in_array(strlen($token), [16, 32], true) || !ctype_xdigit($token)) {
        return false;
    }

    $now ??= time();
    if ($timestamp > $now + 300 || ($now - $timestamp) > flus_ticket_token_ttl_seconds()) {
        return false;
    }

    try {
        $expected32 = flus_ticket_token_generate($ventaId, $timestamp, $secret);
    } catch (Throwable) {
        return false;
    }

    return hash_equals($expected32, $token)
        || hash_equals(substr($expected32, 0, 16), $token);
}

<?php
declare(strict_types=1);

function flus_factura_pdf_token_create(int $facturaId, int $expiresAt): string
{
    $payload = $facturaId . '|' . $expiresAt;
    $sig = hash_hmac('sha256', $payload, (string)APP_SECRET);
    return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
}

function flus_factura_pdf_token_validate(string $token, int $facturaId): bool
{
    if ($facturaId <= 0 || trim($token) === '') {
        return false;
    }

    $normalized = strtr($token, '-_', '+/');
    $padding = strlen($normalized) % 4;
    if ($padding > 0) {
        $normalized .= str_repeat('=', 4 - $padding);
    }

    $raw = base64_decode($normalized, true);
    if (!is_string($raw) || $raw === '') {
        return false;
    }

    $parts = explode('|', $raw);
    if (count($parts) !== 3) {
        return false;
    }

    [$tokenFacturaId, $expiresAt, $sig] = $parts;
    if (!ctype_digit($tokenFacturaId) || !ctype_digit($expiresAt)) {
        return false;
    }

    if ((int)$tokenFacturaId !== $facturaId || (int)$expiresAt < time()) {
        return false;
    }

    $expected = hash_hmac('sha256', $tokenFacturaId . '|' . $expiresAt, (string)APP_SECRET);
    return hash_equals($expected, (string)$sig);
}

function flus_factura_pdf_browser_path(): ?string
{
    static $cached = false;
    if ($cached !== false) {
        return $cached ?: null;
    }

    $candidates = [
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
        'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            $cached = $path;
            return $cached;
        }
    }

    $cached = '';
    return null;
}

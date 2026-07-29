<?php
declare(strict_types=1);

if (!function_exists('flus_cloud_http_contract_error')) {
    function flus_cloud_http_contract_error(?array $body, string $fallback = 'HTTP_CONTRACT_INVALID'): string
    {
        if (!is_array($body)) {
            return $fallback;
        }

        $message = strtolower(trim((string)($body['message'] ?? '')));
        $error = trim((string)($body['error'] ?? ''));
        $combined = strtolower($error . ' ' . $message);
        if (str_contains($combined, 'imunify360') || str_contains($combined, 'bot-protection')) {
            return 'BOT_PROTECTION_BLOCKED';
        }

        $normalized = preg_replace('/[^A-Za-z0-9._:-]/', '', strtoupper($error)) ?: '';
        return $normalized !== '' ? substr($normalized, 0, 80) : $fallback;
    }
}

if (!function_exists('flus_cloud_http_sync_contract_valid')) {
    function flus_cloud_http_sync_contract_valid(array $body): bool
    {
        if (!array_key_exists('ok', $body)) {
            return false;
        }
        if (empty($body['ok'])) {
            return trim((string)($body['error'] ?? '')) !== '';
        }

        foreach (['accepted', 'duplicates', 'rejected'] as $field) {
            if (!array_key_exists($field, $body) || !is_numeric($body[$field])) {
                return false;
            }
        }
        return true;
    }
}

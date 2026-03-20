<?php
declare(strict_types=1);

if (!function_exists('dashboard_load_cache')) {
  function dashboard_load_cache(array $parts, int $ttl = 300): array {
    return [
      'ttl' => $ttl,
      'hit' => false,
      'payload' => null,
      'apcu_enabled' => false,
      'apcu_key' => '',
      'file_enabled' => false,
      'file' => '',
    ];
  }
}

if (!function_exists('dashboard_store_cache')) {
  function dashboard_store_cache(array $cacheState, array $payload): void {
    if (!empty($cacheState['apcu_enabled']) && !empty($cacheState['apcu_key'])) {
      apcu_store((string)$cacheState['apcu_key'], $payload, (int)($cacheState['ttl'] ?? 300));
    }

    if (!empty($cacheState['file_enabled']) && !empty($cacheState['file'])) {
      @file_put_contents(
        (string)$cacheState['file'],
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
      );
    }
  }
}

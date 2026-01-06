<?php
declare(strict_types=1);

require_once __DIR__ . '/root.php';

/**
 * Session helpers (portable)
 * - FLUS_ROOT lo define public/lib/root.php
 * - session.save_path: usa /stack/tmp o /tmp si existen
 */

if (!function_exists('flus_detect_root')) {
  function flus_detect_root(): string {
    return defined('FLUS_ROOT') ? (string)FLUS_ROOT : dirname(__DIR__, 2);
  }
}

if (!function_exists('flus_session_start')) {
  function flus_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $tmp1 = FLUS_ROOT . '/stack/tmp';
    $tmp2 = FLUS_ROOT . '/tmp';
    $savePath = is_dir($tmp1) ? $tmp1 : (is_dir($tmp2) ? $tmp2 : '');

    if ($savePath !== '') {
      @ini_set('session.save_path', $savePath);
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['SERVER_PORT'] ?? '') == 443);

    if (PHP_VERSION_ID >= 70300) {
      session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $isHttps,
      ]);
    } else {
      session_set_cookie_params(0, '/; samesite=Lax', '', $isHttps, true);
    }

    session_start();
  }
}
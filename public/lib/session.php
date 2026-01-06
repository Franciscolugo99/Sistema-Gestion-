<?php
// public/lib/session.php
declare(strict_types=1);

if (!function_exists('flus_detect_root')) {
  function flus_detect_root(): string {
    $base1 = dirname(__DIR__, 2);      // desde /public/lib -> /public
    $base2 = dirname(__DIR__, 3);      // desde /public/lib -> root

    // casos:
    // root/public/lib/session.php  => base2 es root
    // root/app/public/lib/session.php => base2 es root/app (y base3 sería root)
    // hacemos una detección robusta por existencia de /src/config.php
    $candidates = [
      $base2,               // root
      dirname($base2),      // por si estás en /app/public/lib
      $base1,               // fallback
    ];

    foreach ($candidates as $r) {
      if (is_file($r . '/src/config.php')) return $r;
    }
    // último fallback
    return $base2;
  }
}

if (!defined('FLUS_ROOT')) {
  define('FLUS_ROOT', flus_detect_root());
}

if (!function_exists('flus_session_start')) {
  function flus_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    // save_path portable
    $tmp1 = FLUS_ROOT . '/stack/tmp';
    $tmp2 = FLUS_ROOT . '/tmp';
    $savePath = is_dir($tmp1) ? $tmp1 : (is_dir($tmp2) ? $tmp2 : sys_get_temp_dir());

    if (is_dir($savePath)) {
      @ini_set('session.save_path', $savePath);
    }

    // nombre único para FLUS
    session_name('FLUSSESSID');

    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

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

<?php
declare(strict_types=1);

/**
 * src/version.php
 * Fuente única de versión/build para todo FLUS.
 * El instalador/updater solo tiene que reemplazar este archivo.
 */

defined('FLUS_VERSION') || define('FLUS_VERSION', '3.8.1');
defined('FLUS_BUILD')   || define('FLUS_BUILD',   '2026-03-22');

if (!function_exists('flus_version_label')) {
  function flus_version_label(): string {
    $v = (string)(defined('FLUS_VERSION') ? FLUS_VERSION : 'dev');
    return "FLUS v{$v}";
  }
}

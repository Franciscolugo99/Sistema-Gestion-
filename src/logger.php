<?php
// src/logger.php
declare(strict_types=1);

/**
 * Logger simple a archivo (JSON Lines).
 * Guarda en /storage/logs/app.log (fuera de /public).
 */
if (!function_exists('flus_log')) {
  function flus_log(string $level, string $message, array $context = []): void {
    $baseDir = dirname(__DIR__); // /kiosco
    $logDir  = $baseDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';

    if (!is_dir($logDir)) {
      @mkdir($logDir, 0775, true);
    }

    $file = $logDir . DIRECTORY_SEPARATOR . 'app.log';

    $row = [
      'ts'      => date('c'),
      'level'   => $level,
      'message' => $message,
      'context' => $context,
    ];

    // No romper el flujo si no puede escribir.
    @file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
  }
}

if (!function_exists('flus_log_info')) {
  function flus_log_info(string $message, array $context = []): void {
    flus_log('INFO', $message, $context);
  }
}
if (!function_exists('flus_log_warn')) {
  function flus_log_warn(string $message, array $context = []): void {
    flus_log('WARN', $message, $context);
  }
}
if (!function_exists('flus_log_error')) {
  function flus_log_error(string $message, array $context = []): void {
    flus_log('ERROR', $message, $context);
  }
}

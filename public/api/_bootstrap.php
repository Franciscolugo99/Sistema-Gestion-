<?php
// public/api/_bootstrap.php
// ✅ Compat: bootstrap JSON para endpoints API legacy
// - Centraliza config + helpers
// - Evita hardcodear rutas y duplicar funciones

declare(strict_types=1);

define('FLUS_API_CONTEXT', true);

require_once __DIR__ . '/../bootstrap.php';
require_once FLUS_ROOT . '/src/api_helpers.php';

// JSON: nunca romper por warnings/HTML
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

setup_api_error_handlers();

/**
 * Responder JSON genérico
 */
function api_json(array $data, int $status = 200): void {
  json_response($data, $status);
}

/**
 * Leer JSON del body
 */
function api_read_json(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $d = json_decode($raw, true);
  return is_array($d) ? $d : [];
}

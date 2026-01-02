<?php
// public/api/_bootstrap.php
declare(strict_types=1);

ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/audit_log.php';


$pdo  = getPDO();
$user = current_user();

function api_json(array $data, int $status = 200): void {
  http_response_code($status);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  // vaciar cualquier ruido previo
  if (ob_get_length()) { ob_end_flush(); }
  exit;
}

function api_read_json(): array {
  $raw = file_get_contents('php://input');
  if (!$raw) return [];
  $d = json_decode($raw, true);
  return is_array($d) ? $d : [];
}

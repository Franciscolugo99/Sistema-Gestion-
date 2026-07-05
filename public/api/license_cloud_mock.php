<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/root.php';

$config = FLUS_ROOT . '/src/config.php';
if (is_file($config)) {
  require_once $config;
}

require_once FLUS_ROOT . '/src/license_cloud_mock.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (!flus_license_cloud_mock_enabled()) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'MOCK_DISABLED'], JSON_UNESCAPED_UNICODE);
  exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
  exit;
}

$raw = file_get_contents('php://input');
$request = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
if (!is_array($request)) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'BAD_JSON'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  echo json_encode(
    flus_license_cloud_mock_signed_document($request),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
  );
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'MOCK_SIGN_FAILED'], JSON_UNESCAPED_UNICODE);
}

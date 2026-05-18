<?php
declare(strict_types=1);

define('FLUS_API_CONTEXT', true);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/csrf.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$token = csrf_token();

echo json_encode([
  'ok' => true,
  'csrf' => $token,
  'csrf_token' => $token,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

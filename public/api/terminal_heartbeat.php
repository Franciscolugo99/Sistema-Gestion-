<?php
// public/api/terminal_heartbeat.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/terminal.php';

require_login_json();

// CSRF header (preferente)
$csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if ($csrf === '') {
  $raw = file_get_contents('php://input');
  $data = $raw ? json_decode($raw, true) : null;
  if (is_array($data)) $csrf = (string)($data['csrf'] ?? '');
}

if (!csrf_check($csrf)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'CSRF'], JSON_UNESCAPED_UNICODE);
  exit;
}

$user = current_user();
$uid  = (int)($user['id'] ?? 0);
if ($uid <= 0) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'AUTH'], JSON_UNESCAPED_UNICODE);
  exit;
}

$tid = (int)($_SESSION['terminal_id'] ?? 0);
if ($tid <= 0) $tid = current_terminal_id();

if ($tid <= 0) {
  http_response_code(409);
  echo json_encode(['ok' => false, 'error' => 'NO_TERMINAL'], JSON_UNESCAPED_UNICODE);
  exit;
}

$sid = session_id();

try {
  $pdo = getPDO();

  // ✅ En vez de touch (estricto), hacemos acquire/assert:
  // - mismo user -> takeover ok
  // - expirado -> takeover ok
  // - otro user -> LOCKED
  $res = terminal_lock_acquire($pdo, $tid, $uid, $sid, 90);

  if (!($res['ok'] ?? false)) {
    $err = (string)($res['error'] ?? 'LOCK_FAIL');
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => $err, 'detail' => $res], JSON_UNESCAPED_UNICODE);
    exit;
  }

} catch (Throwable $e) {
  http_response_code(503);
  echo json_encode(['ok' => false, 'error' => 'DB_ERROR'], JSON_UNESCAPED_UNICODE);
  exit;
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);

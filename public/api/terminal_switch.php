<?php
// public/api/terminal_switch.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/terminal.php';
require_once __DIR__ . '/../caja_lib.php';

require_login_json();

// CSRF por header
$csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!csrf_check($csrf)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'CSRF'], JSON_UNESCAPED_UNICODE);
  exit;
}

$raw = file_get_contents('php://input');
$data = $raw ? json_decode($raw, true) : null;
$newTid = is_array($data) ? (int)($data['terminal_id'] ?? 0) : 0;

if ($newTid <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'BAD_TERMINAL'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $pdo = getPDO();

  // Terminal válida y activa
  $t = terminal_get($pdo, $newTid);
  if (!$t || (int)($t['activo'] ?? 0) !== 1) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'NOT_FOUND'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $u = current_user();
  $uid = (int)($u['id'] ?? 0);
  $sid = session_id();

  // Terminal actual (para liberar si corresponde)
  $oldTid = (int)($_SESSION['terminal_id'] ?? 0);
  if ($oldTid <= 0) $oldTid = terminal_current_id($pdo);

  // Regla UX/contable: NO permitir cambiar terminal si hay caja abierta en la terminal actual
  if ($oldTid > 0 && $oldTid !== $newTid) {
    $cajaAbierta = caja_get_abierta($pdo, $oldTid);
    if (is_array($cajaAbierta) && !empty($cajaAbierta['id'])) {
      http_response_code(409);
      echo json_encode(['ok' => false, 'error' => 'CAJA_OPEN'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  }

  // Limpia locks viejos
  terminal_locks_gc($pdo, 90);

  // Intentar tomar lock del nuevo terminal
  $res = terminal_lock_acquire($pdo, $newTid, $uid, $sid, 90);
  if (!($res['ok'] ?? false)) {
    http_response_code(409);
    echo json_encode([
      'ok' => false,
      'error' => (string)($res['error'] ?? 'LOCKED'),
      'by' => $res['by'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Si cambiamos, liberamos lock anterior (solo si era del usuario)
  if ($oldTid > 0 && $oldTid !== $newTid) {
    terminal_lock_release($pdo, $oldTid, $uid);
  }

  // Persistir selección
  terminal_set_cookie($newTid);
  $_SESSION['terminal_id'] = $newTid;

  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR'], JSON_UNESCAPED_UNICODE);
}

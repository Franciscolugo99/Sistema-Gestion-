<?php
// public/api/terminal_select.php
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
if ($csrf === '') {
  // fallback por body
  $raw = file_get_contents('php://input');
  $data = $raw ? json_decode($raw, true) : null;
  if (is_array($data)) $csrf = (string)($data['csrf'] ?? '');
}
if (!csrf_check($csrf)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'CSRF_INVALID'], JSON_UNESCAPED_UNICODE);
  exit;
}

$user = current_user();
$uid  = (int)($user['id'] ?? 0);
$sid  = session_id();

$pdo = getPDO();
$ttl = 90;

// limpiar expirados
terminal_locks_gc($pdo, $ttl);

// leer JSON
$raw  = file_get_contents('php://input');
$body = $raw ? json_decode($raw, true) : [];
if (!is_array($body)) $body = [];

$requestedTerminalId = (int)($body['terminal_id'] ?? 0);

// terminal actual (cookie validada)
$currentTid = terminal_current_id($pdo);
if ($currentTid > 0) {
  $_SESSION['terminal_id'] = $currentTid;
}

// ---------------------------
// 1) LISTA (si no mandan terminal_id)
// ---------------------------
if ($requestedTerminalId <= 0) {
  $terminals = terminal_list_active($pdo);

  // traer locks en una sola query
  $locks = $pdo->query("
    SELECT tl.terminal_id, tl.user_id, tl.session_id, tl.last_seen_at, u.username
    FROM terminal_locks tl
    LEFT JOIN users u ON u.id = tl.user_id
  ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $lockMap = [];
  foreach ($locks as $l) {
    $lockMap[(int)$l['terminal_id']] = $l;
  }

  $out = [];
  foreach ($terminals as $t) {
    $tid = (int)$t['id'];

    $status = 'free';
    $lockedBy = null;

    if (isset($lockMap[$tid])) {
      $l = $lockMap[$tid];
      $lockedUserId = (int)($l['user_id'] ?? 0);
      $lastSeen     = (string)($l['last_seen_at'] ?? '');
      $lastTs       = $lastSeen ? strtotime($lastSeen) : 0;
      $expired      = (!$lastTs) || (time() - $lastTs > $ttl);

      // locked solo si NO expiró y es OTRO usuario
      if (!$expired && $lockedUserId > 0 && $lockedUserId !== $uid) {
        $status = 'locked';
        $lockedBy = (string)($l['username'] ?? 'Otro usuario');
      }
    }

    $out[] = [
      'id'       => $tid,
      'nombre'   => (string)($t['nombre'] ?? ('Caja #' . $tid)),
      'codigo'   => (string)($t['codigo'] ?? ''),
      'status'   => $status,
      'lockedBy' => $lockedBy,
    ];
  }

  echo json_encode([
    'ok' => true,
    'current_terminal_id' => (int)($currentTid ?: 0),
    'terminals' => $out,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// ---------------------------
// 2) CAMBIAR TERMINAL
// ---------------------------

// validar terminal destino
$tNew = terminal_get($pdo, $requestedTerminalId);
if (!$tNew || (int)$tNew['activo'] !== 1) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'INVALID_TERMINAL'], JSON_UNESCAPED_UNICODE);
  exit;
}

// si hay caja abierta en la terminal actual -> no dejamos cambiar
if ($currentTid > 0) {
  $open = caja_get_abierta($pdo, $currentTid);
  if (is_array($open) && !empty($open['id'])) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'CAJA_ABIERTA'], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

// si cambia, liberamos lock anterior (así no bloqueás 90s)
if ($currentTid > 0 && $currentTid !== $requestedTerminalId) {
  terminal_lock_release($pdo, $currentTid, $uid);
}

// tomar lock en la nueva
$res = terminal_lock_acquire($pdo, $requestedTerminalId, $uid, $sid, $ttl);
if (!($res['ok'] ?? false)) {
  http_response_code(409);
  echo json_encode($res, JSON_UNESCAPED_UNICODE);
  exit;
}

// guardar cookie + sesión
terminal_set_cookie($requestedTerminalId);
$_SESSION['terminal_id'] = $requestedTerminalId;

echo json_encode([
  'ok' => true,
  'terminal_id' => $requestedTerminalId,
  'terminal_nombre' => (string)($tNew['nombre'] ?? ('Caja #' . $requestedTerminalId)),
], JSON_UNESCAPED_UNICODE);

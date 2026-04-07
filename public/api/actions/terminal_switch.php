<?php
declare(strict_types=1);
// public/api/actions/terminal_switch.php

$pdo = $pdo ?? getPDO();
$user = current_user();
$uid = (int)($user['id'] ?? 0);
$sid = session_id();

$newTid = (int)($body['terminal_id'] ?? 0);
if ($newTid <= 0) {
  json_fail('Terminal invalida', 400);
}

$oldTid = terminal_current_id($pdo);
if ($oldTid > 0) {
  $_SESSION['terminal_id'] = $oldTid;
}

$terminal = terminal_get($pdo, $newTid);
if (!$terminal || (int)($terminal['activo'] ?? 0) !== 1) {
  json_fail('Terminal invalida', 400, ['error_code' => 'TERMINAL_INVALIDA']);
}

if ($oldTid > 0 && $oldTid !== $newTid) {
  $open = caja_get_abierta($pdo, $oldTid);
  if (is_array($open) && !empty($open['id'])) {
    json_fail('CAJA_ABIERTA', 409, ['error_code' => 'CAJA_ABIERTA']);
  }
}

$currentLock = terminal_lock_status($pdo, $newTid);
$lockedByOther = is_array($currentLock)
  && (int)($currentLock['user_id'] ?? 0) > 0
  && (
    (int)($currentLock['user_id'] ?? 0) !== $uid
    || (string)($currentLock['session_id'] ?? '') !== $sid
  );
if ($lockedByOther) {
  json_fail('LOCKED', 409, [
    'error_code' => 'TERMINAL_LOCKED',
    'detail' => $currentLock,
  ]);
}

if ($oldTid > 0 && $oldTid !== $newTid) {
  terminal_lock_release($pdo, $oldTid, $uid);
}

terminal_set_cookie($newTid);
$_SESSION['terminal_id'] = $newTid;
if ($sid !== '' && function_exists('flus_session_update_selected_terminal')) {
  flus_session_update_selected_terminal($pdo, $sid, $newTid);
}

json_ok([
  'terminal_id' => $newTid,
  'terminal_nombre' => (string)($terminal['nombre'] ?? ('Caja #' . $newTid)),
]);

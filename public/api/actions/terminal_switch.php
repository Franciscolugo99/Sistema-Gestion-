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
  if (is_array($open) && !empty($open['id']) && caja_user_can_operar_turno($open, $uid)) {
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
  $lockedBy = '';
  $lockUserId = (int)($currentLock['user_id'] ?? 0);
  if ($lockUserId > 0) {
    try {
      $st = $pdo->prepare('SELECT nombre, username FROM users WHERE id = :id LIMIT 1');
      $st->execute([':id' => $lockUserId]);
      $lockUser = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      $lockedBy = trim((string)($lockUser['nombre'] ?? ''));
      if ($lockedBy === '') {
        $lockedBy = trim((string)($lockUser['username'] ?? ''));
      }
    } catch (Throwable $e) {
      $lockedBy = '';
    }
  }

  json_fail('LOCKED', 409, [
    'error_code' => 'TERMINAL_LOCKED',
    'locked_by_name' => $lockedBy !== '' ? $lockedBy : 'Otro usuario',
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

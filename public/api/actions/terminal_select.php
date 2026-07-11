<?php
declare(strict_types=1);
// public/api/actions/terminal_select.php

$pdo = $pdo ?? getPDO();
$ttl = 90;
$user = current_user();
$uid = (int)($user['id'] ?? 0);
$sid = session_id();
terminal_locks_gc($pdo, $ttl);

$requestedTerminalId = (int)($body['terminal_id'] ?? 0);
$currentTid = terminal_current_id($pdo);
if ($currentTid > 0) {
  $_SESSION['terminal_id'] = $currentTid;
}

if ($requestedTerminalId <= 0) {
  $terminales = array_map(static function (array $terminal) use ($pdo, $uid, $sid): array {
    $terminalId = (int)($terminal['id'] ?? 0);
    $lock = $terminalId > 0 ? terminal_lock_status($pdo, $terminalId) : null;
    $lockUserId = (int)($lock['user_id'] ?? 0);
    $lockSessionId = (string)($lock['session_id'] ?? '');
    $isMine = $lockUserId > 0 && $lockUserId === $uid && $lockSessionId !== '' && $lockSessionId === $sid;
    $locked = is_array($lock) && !$isMine;
    $lockedBy = '';

    if ($locked && $lockUserId > 0) {
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

    $terminal['status'] = $locked ? 'locked' : 'free';
    $terminal['locked'] = $locked;
    $terminal['locked_by_name'] = $lockedBy;
    $terminal['lockedBy'] = $lockedBy !== '' ? $lockedBy : ($locked ? 'Otro usuario' : '');
    $terminal['last_seen_at'] = (string)($lock['updated_at'] ?? $lock['expires_at'] ?? '');
    $terminal['is_mine'] = $isMine;

    return $terminal;
  }, terminal_list($pdo));

  json_ok([
    'terminales' => $terminales,
    'current' => $currentTid,
    'terminals' => $terminales,
    'current_terminal_id' => $currentTid,
  ]);
}

$terminal = terminal_get($pdo, $requestedTerminalId);
if (!$terminal || (int)($terminal['activo'] ?? 0) !== 1) {
  json_fail('Terminal invalida', 400);
}

if ($currentTid > 0 && $requestedTerminalId !== $currentTid) {
  $open = caja_get_abierta($pdo, $currentTid);
  if (is_array($open) && !empty($open['id']) && caja_user_can_operar_turno($open, $uid)) {
    json_fail('CAJA_ABIERTA', 409);
  }
}

$currentLock = terminal_lock_status($pdo, $requestedTerminalId);
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

if ($currentTid > 0 && $requestedTerminalId !== $currentTid && $uid > 0) {
  terminal_lock_release($pdo, $currentTid, $uid);
}

terminal_set_cookie($requestedTerminalId);
$_SESSION['terminal_id'] = $requestedTerminalId;
if ($sid !== '' && function_exists('flus_session_update_selected_terminal')) {
  flus_session_update_selected_terminal($pdo, $sid, $requestedTerminalId);
}

json_ok([
  'terminal_id' => $requestedTerminalId,
  'terminal_nombre' => (string)($terminal['nombre'] ?? ('Caja #' . $requestedTerminalId)),
]);

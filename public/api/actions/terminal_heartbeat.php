<?php
declare(strict_types=1);
// public/api/actions/terminal_heartbeat.php

$pdo = $pdo ?? getPDO();
$user = current_user();
$uid = (int)($user['id'] ?? 0);
$sid = session_id();
$ttl = 90;
$context = strtolower(trim((string)($body['context'] ?? '')));
$refererPath = strtolower((string)parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH));
$refererIsCaja = in_array(basename($refererPath), ['caja.php', 'caja_cerrar.php', 'caja_movimientos.php'], true);

if ($context !== 'caja' && !$refererIsCaja) {
  json_fail('INVALID_TERMINAL_HEARTBEAT_CONTEXT', 400);
}

$tid = (int)($_SESSION['terminal_id'] ?? 0);
if ($tid <= 0) {
  $tid = terminal_current_id($pdo);
}

if ($tid <= 0) {
  json_fail('NO_TERMINAL', 409);
}

$_SESSION['terminal_id'] = $tid;
terminal_locks_gc($pdo, $ttl);

$res = terminal_lock_heartbeat($pdo, $tid, $uid, $sid, $ttl);
if (!($res['ok'] ?? false)) {
  $err = (string)($res['error'] ?? '');
  if ($err === 'LOCK_NOT_OWNED' || $err === 'LOCK_LOST') {
    $try = terminal_lock_acquire($pdo, $tid, $uid, $sid, $ttl);
    if (($try['ok'] ?? false) === true) {
      json_ok(['reacquired' => true]);
    }
    json_fail((string)($try['error'] ?? $err), 409, ['detail' => $try]);
  }

  json_fail($err !== '' ? $err : 'LOCK_FAIL', 409, ['detail' => $res]);
}

json_ok();

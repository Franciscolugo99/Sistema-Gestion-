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

if ($oldTid > 0 && $oldTid !== $newTid) {
  terminal_lock_release($pdo, $oldTid, $uid);
}

terminal_set_cookie($newTid);
$_SESSION['terminal_id'] = $newTid;
if ($sid !== '' && function_exists('flus_session_update_selected_terminal')) {
  flus_session_update_selected_terminal($pdo, $sid, $newTid);
}

json_ok();

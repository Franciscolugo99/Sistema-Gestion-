<?php
declare(strict_types=1);
// public/api/actions/session_heartbeat.php

$pdo = $pdo ?? getPDO();
$user = current_user();
$uid = (int)($user['id'] ?? 0);
$sid = session_id();

if ($uid > 0 && $sid !== '' && function_exists('flus_session_touch')) {
  flus_session_touch($pdo, $uid, $sid, ['force' => true]);
}

json_ok(['session_id' => $sid]);

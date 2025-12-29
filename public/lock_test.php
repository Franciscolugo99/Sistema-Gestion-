<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/terminal.php';

header('Content-Type: text/plain; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$pdo = getPDO();

// Simulamos: terminal 1, user 1, session actual
$tid = 1;
$uid = 1;
$sid = session_id();

$res = terminal_lock_acquire($pdo, $tid, $uid, $sid, 90);

echo "SID=$sid\n";
echo "RESULT=" . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";

$row = $pdo->query("SELECT terminal_id,user_id,session_id,last_seen_at FROM terminal_locks WHERE terminal_id=1")->fetch(PDO::FETCH_ASSOC);
echo "ROW=" . json_encode($row ?: null, JSON_UNESCAPED_UNICODE) . "\n";

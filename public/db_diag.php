<?php
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = getPDO();
echo "DATABASE(): " . $pdo->query("SELECT DATABASE()")->fetchColumn() . PHP_EOL;

$exists = $pdo->query("SHOW TABLES LIKE 'terminal_locks'")->fetchColumn();
echo "terminal_locks exists: " . ($exists ? "YES" : "NO") . PHP_EOL;

if ($exists) {
  $rows = $pdo->query("SELECT terminal_id,user_id,session_id,last_seen_at FROM terminal_locks ORDER BY terminal_id")->fetchAll(PDO::FETCH_ASSOC);
  echo "locks=" . count($rows) . PHP_EOL;
  print_r($rows);
}

echo "SESSION_ID: " . session_id() . PHP_EOL;
echo "SESSION terminal_id: " . (int)($_SESSION['terminal_id'] ?? 0) . PHP_EOL;
$u = current_user();
echo "USER id: " . (int)($u['id'] ?? 0) . PHP_EOL;

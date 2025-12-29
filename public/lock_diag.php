<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: text/plain; charset=utf-8');

echo "URL: " . ($_SERVER['REQUEST_URI'] ?? '') . PHP_EOL;

$pdo = getPDO();

echo "DATABASE(): " . $pdo->query("SELECT DATABASE()")->fetchColumn() . PHP_EOL;

$u = current_user();
echo "USER id: " . (int)($u['id'] ?? 0) . PHP_EOL;
echo "SESSION_ID: " . session_id() . PHP_EOL;

echo "COOKIE terminal_id: " . (int)($_COOKIE['terminal_id'] ?? 0) . PHP_EOL;
echo "SESSION terminal_id: " . (int)($_SESSION['terminal_id'] ?? 0) . PHP_EOL;

$tid = current_terminal_id();
echo "current_terminal_id(): " . $tid . PHP_EOL;

if ($tid > 0) {
  $st = $pdo->prepare("SELECT terminal_id,user_id,session_id,last_seen_at FROM terminal_locks WHERE terminal_id=?");
  $st->execute([$tid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  echo "LOCK ROW: " . json_encode($row ?: null, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

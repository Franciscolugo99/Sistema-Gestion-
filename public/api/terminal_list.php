<?php
// public/api/terminal_list.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/terminal.php';

require_login_json();

$csrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!csrf_check($csrf)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'CSRF'], JSON_UNESCAPED_UNICODE);
  exit;
}

$pdo = getPDO();
$TTL = 90;

// locks vigentes (no expirados)
$sql = "
  SELECT
    t.id, t.nombre, t.codigo,
    tl.user_id, tl.last_seen_at,
    u.username
  FROM terminales t
  LEFT JOIN terminal_locks tl
    ON tl.terminal_id = t.id
   AND tl.last_seen_at >= DATE_SUB(NOW(), INTERVAL {$TTL} SECOND)
  LEFT JOIN users u ON u.id = tl.user_id
  WHERE t.activo = 1
  ORDER BY t.id ASC
";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo json_encode([
  'ok' => true,
  'current_terminal_id' => terminal_cookie_id(),
  'ttl' => $TTL,
  'terminales' => array_map(static function($r) {
    return [
      'id' => (int)$r['id'],
      'nombre' => (string)($r['nombre'] ?? ''),
      'codigo' => (string)($r['codigo'] ?? ''),
      'locked' => !empty($r['user_id']),
      'locked_by' => !empty($r['user_id']) ? (string)($r['username'] ?? 'Otro') : '',
      'last_seen_at' => (string)($r['last_seen_at'] ?? ''),
    ];
  }, $rows),
], JSON_UNESCAPED_UNICODE);

<?php
// public/auth.php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/terminal.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

function current_user(): ?array {
  return isset($_SESSION['user']) && is_array($_SESSION['user'])
    ? $_SESSION['user']
    : null;
}

function is_logged_in(): bool {
  return current_user() !== null;
}

/**
 * Login NORMAL (Backoffice): NO exige terminal.
 */
function require_login(bool $withNext = true): void {
  if (!is_logged_in()) {
    $url = 'login.php';
    if ($withNext) {
      $req = $_SERVER['REQUEST_URI'] ?? '';
      if ($req !== '') $url .= '?next=' . urlencode($req);
    }
    header('Location: ' . $url);
    exit;
  }
}

function require_login_json(): void {
  if (!is_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

/* ==========================
   PERMISOS
========================== */
function user_has_permission(string $slug): bool {
  $u = current_user();
  if (!$u) return false;

  static $cache = [];
  if (array_key_exists($slug, $cache)) return (bool)$cache[$slug];

  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) {
    $cache[$slug] = false;
    return false;
  }

  $pdo = getPDO();
  $sql = "
    SELECT 1
    FROM users u
    JOIN roles r ON u.role_id = r.id
    JOIN role_permission rp ON r.id = rp.role_id
    JOIN permissions p ON rp.permission_id = p.id
    WHERE u.id = :uid
      AND u.activo = 1
      AND p.slug = :slug
    LIMIT 1
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([':uid' => $userId, ':slug' => $slug]);

  $ok = (bool)$stmt->fetchColumn();
  $cache[$slug] = $ok;
  return $ok;
}

function require_permission(string $slug): void {
  if (!user_has_permission($slug)) {
    http_response_code(403);
    echo "No tenés permisos para acceder a esta sección.";
    exit;
  }
}

/* ==========================
   TERMINAL / POS
========================== */
function current_terminal_id(): int {
  static $cache = null;
  if ($cache !== null) return (int)$cache;

  try {
    $pdo = getPDO();
    $tid = terminal_current_id($pdo);
    if ($tid > 0) {
      $_SESSION['terminal_id'] = $tid;
      $cache = $tid;
      return $tid;
    }
  } catch (Throwable $e) {}

  $cache = 0;
  return 0;
}

function require_terminal(bool $withNext = true): void {
  $tid = current_terminal_id();
  if ($tid > 0) return;

  $url = 'terminal_select.php';
  if ($withNext) {
    $req = $_SERVER['REQUEST_URI'] ?? '';
    if ($req !== '') $url .= '?next=' . urlencode($req);
  }
  header('Location: ' . $url);
  exit;
}

function require_pos(bool $withNext = true): void {
  require_login($withNext);
  require_terminal($withNext);

  $u = current_user();
  $uid = (int)($u['id'] ?? 0);
  $tid = (int)($_SESSION['terminal_id'] ?? 0);
  $sid = session_id();

  if ($uid <= 0 || $tid <= 0 || $sid === '') return;

  try {
    $pdo = getPDO();
    $lock = terminal_lock_acquire($pdo, $tid, $uid, $sid, 90);
    if (!($lock['ok'] ?? false)) {
      $req = $_SERVER['REQUEST_URI'] ?? 'caja.php';
      header('Location: terminal_select.php?msg=locked&next=' . urlencode($req));
      exit;
    }
  } catch (Throwable $e) {
    $req = $_SERVER['REQUEST_URI'] ?? 'caja.php';
    header('Location: terminal_select.php?msg=locked&next=' . urlencode($req));
    exit;
  }
}

function require_pos_json(): void {
  require_login_json();

  $tid = current_terminal_id();
  if ($tid <= 0) {
    http_response_code(409);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'NO_TERMINAL'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $u = current_user();
  $uid = (int)($u['id'] ?? 0);
  $sid = session_id();

  try {
    $pdo = getPDO();
    $lock = terminal_lock_acquire($pdo, $tid, $uid, $sid, 90);
    if (!($lock['ok'] ?? false)) {
      http_response_code(409);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'error' => 'LOCKED'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  } catch (Throwable $e) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'DB_ERROR'], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

// compat
function require_terminal_lock(bool $withNext = true): void { require_pos($withNext); }
function require_terminal_lock_json(): void { require_pos_json(); }

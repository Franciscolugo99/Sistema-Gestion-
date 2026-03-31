<?php
// public/login_process.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/lib/csrf.php';

function login_redirect(string $error): never {
  $next = (string)($_POST['next'] ?? $_GET['next'] ?? '');
  $url = 'login.php?error=' . urlencode($error);

  if ($next !== '' && $next[0] === '/' && strpos($next, '://') === false && strpos($next, "\n") === false && strpos($next, "\r") === false) {
    $url .= '&next=' . urlencode($next);
  }

  header('Location: ' . $url);
  exit;
}

function login_throttle_dir(): string {
  $dir = FLUS_ROOT . '/storage/login_throttle';
  if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
  }
  foreach ((array)glob($dir . '/*.json') as $path) {
    if (!is_file($path)) continue;
    if ((@filemtime($path) ?: 0) < (time() - 86400)) {
      @unlink($path);
    }
  }
  return $dir;
}

function login_throttle_scopes(string $username, string $ip): array {
  $user = strtolower(trim($username));
  return [
    ['ip', $ip],
    ['user', $user],
    ['user_ip', $user . '|' . $ip],
  ];
}

function login_throttle_key(string $scope, string $value): string {
  return hash('sha256', strtolower($scope . '|' . trim($value)));
}

function login_throttle_path(string $scope, string $value): string {
  return login_throttle_dir() . '/' . login_throttle_key($scope, $value) . '.json';
}

function login_throttle_load(string $scope, string $value): array {
  $path = login_throttle_path($scope, $value);
  if (!is_file($path)) {
    return ['attempts' => [], 'blocked_until' => 0];
  }

  $raw = @file_get_contents($path);
  $data = json_decode((string)$raw, true);
  if (!is_array($data)) {
    return ['attempts' => [], 'blocked_until' => 0];
  }

  $attempts = array_values(array_filter((array)($data['attempts'] ?? []), static fn($ts) => is_int($ts) || ctype_digit((string)$ts)));
  return [
    'attempts' => array_map('intval', $attempts),
    'blocked_until' => (int)($data['blocked_until'] ?? 0),
  ];
}

function login_throttle_save(string $scope, string $value, array $state): void {
  $path = login_throttle_path($scope, $value);
  @file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX);
}

function login_throttle_clear(string $username, string $ip): void {
  foreach (login_throttle_scopes($username, $ip) as [$scope, $value]) {
    $path = login_throttle_path($scope, $value);
    if (is_file($path)) {
      @unlink($path);
    }
  }
}

function login_throttle_check(string $username, string $ip): int {
  $now = time();
  $wait = 0;

  foreach (login_throttle_scopes($username, $ip) as [$scope, $value]) {
    $state = login_throttle_load($scope, $value);
    $blockedUntil = (int)($state['blocked_until'] ?? 0);
    if ($blockedUntil > $now) {
      $wait = max($wait, $blockedUntil - $now);
    }
  }

  return $wait;
}

function login_throttle_record_failure(string $username, string $ip): void {
  $windowSeconds = 15 * 60;
  $maxAttempts = 5;
  $blockSeconds = 15 * 60;
  $now = time();

  foreach (login_throttle_scopes($username, $ip) as [$scope, $value]) {
    $state = login_throttle_load($scope, $value);
    $attempts = array_values(array_filter(
      array_map('intval', (array)($state['attempts'] ?? [])),
      static fn(int $ts): bool => $ts >= ($now - $windowSeconds)
    ));

    $attempts[] = $now;
    $state['attempts'] = $attempts;
    $state['blocked_until'] = (count($attempts) >= $maxAttempts)
      ? max((int)($state['blocked_until'] ?? 0), $now + $blockSeconds)
      : (int)($state['blocked_until'] ?? 0);

    login_throttle_save($scope, $value, $state);
  }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: login.php');
  exit;
}

if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
  login_redirect('csrf');
}

$pdo = getPDO();

$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

$len = function(string $s): int {
  return function_exists('mb_strlen') ? (int)mb_strlen($s, 'UTF-8') : (int)strlen($s);
};

if ($username === '' || $password === '') {
  login_redirect('empty');
}
if ($len($username) > 60 || $len($password) > 120) {
  login_redirect('too_long');
}

if (login_throttle_check($username, $ip) > 0) {
  login_redirect('rate_limit');
}

$sql = "
  SELECT u.*, r.slug AS role_slug
  FROM users u
  JOIN roles r ON u.role_id = r.id
  WHERE u.username = :username
    AND u.activo = 1
  LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':username' => $username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$hash = (string)($user['password_hash'] ?? '');
if (!$user || $hash === '' || !password_verify($password, $hash)) {
  login_throttle_record_failure($username, $ip);
  login_redirect('invalid');
}

login_throttle_clear($username, $ip);

session_regenerate_id(true);
unset($_SESSION['csrf_token']);

$roleId = (int)($user['role_id'] ?? 0);
$perms = [];

if ($roleId > 0) {
  try {
    $stPerms = $pdo->prepare("
      SELECT p.slug
      FROM role_permission rp
      JOIN permissions p ON rp.permission_id = p.id
      WHERE rp.role_id = :rid
    ");
    $stPerms->execute([':rid' => $roleId]);
    $perms = $stPerms->fetchAll(PDO::FETCH_COLUMN) ?: [];
  } catch (Throwable $e) {
    error_log('login_process: permisos no disponibles - ' . $e->getMessage());
    $perms = [];
  }
}

$_SESSION['user'] = [
  'id'        => (int)$user['id'],
  'nombre'    => (string)($user['nombre'] ?? ''),
  'username'  => (string)$user['username'],
  'email'     => (string)($user['email'] ?? ''),
  'role_id'   => $roleId,
  'role_slug' => (string)($user['role_slug'] ?? ''),
];

$_SESSION['user_id']     = (int)$user['id'];
$_SESSION['user_name']   = (string)($user['nombre'] ?? $user['username'] ?? '');
$_SESSION['user_email']  = (string)($user['email'] ?? '');
$_SESSION['user_role']   = (string)($user['role_slug'] ?? '');
$_SESSION['permissions'] = $perms;

try {
  $pdo->prepare("UPDATE users SET ultimo_acceso = NOW() WHERE id = :id")
      ->execute([':id' => (int)$user['id']]);
} catch (Throwable $e) {
  error_log('login_process: no se pudo actualizar ultimo_acceso - ' . $e->getMessage());
}

if (function_exists('flus_session_register')) {
  flus_session_register($pdo, $_SESSION['user']);
}

$next = (string)($_POST['next'] ?? $_GET['next'] ?? '');
if ($next !== '' && $next[0] === '/' && strpos($next, '://') === false && strpos($next, "\n") === false && strpos($next, "\r") === false) {
  header('Location: ' . $next);
  exit;
}

header('Location: index.php');
exit;

<?php
// public/login_process.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/lib/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: login.php');
  exit;
}

if (!csrf_verify((string)($_POST['csrf_token'] ?? ''))) {
  header('Location: login.php?error=csrf');
  exit;
}

$pdo = getPDO();

$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');

$len = function(string $s): int {
  return function_exists('mb_strlen') ? (int)mb_strlen($s, 'UTF-8') : (int)strlen($s);
};

if ($username === '' || $password === '') {
  header('Location: login.php?error=empty');
  exit;
}
if ($len($username) > 60 || $len($password) > 120) {
  header('Location: login.php?error=too_long');
  exit;
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

if (!$user) {
  header('Location: login.php?error=user');
  exit;
}

$hash = (string)($user['password_hash'] ?? '');
if ($hash === '' || !password_verify($password, $hash)) {
  header('Location: login.php?error=pass');
  exit;
}

session_regenerate_id(true);
// Si tu csrf_input() regenera token cuando falta, esto está OK.
// Si no, en vez de unset, rotalo.
unset($_SESSION['csrf_token']);

/* Permisos (no romper login si algo falta) */
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
    error_log('login_process: permisos no disponibles — ' . $e->getMessage());
    $perms = [];
  }
}

/* Sesión unificada */
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

/* Último acceso */
try {
  $pdo->prepare("UPDATE users SET ultimo_acceso = NOW() WHERE id = :id")
      ->execute([':id' => (int)$user['id']]);
} catch (Throwable $e) {
  error_log('login_process: no se pudo actualizar ultimo_acceso — ' . $e->getMessage());
}

/* Redirect: respetar next (solo interno) */
$next = (string)($_POST['next'] ?? $_GET['next'] ?? '');
if ($next !== '' && $next[0] === '/' && strpos($next, '://') === false && strpos($next, "\n") === false && strpos($next, "\r") === false) {
  header('Location: ' . $next);
  exit;
}

header('Location: index.php');
exit;
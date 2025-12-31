<?php
// public/login_process.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: login.php');
  exit;
}

if (!csrf_verify($_POST['csrf_token'] ?? null)) {
  header('Location: login.php?error=csrf');
  exit;
}

$pdo = getPDO();

$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($username === '' || $password === '') {
  header('Location: login.php?error=empty');
  exit;
}
if (mb_strlen($username) > 60 || mb_strlen($password) > 120) {
  header('Location: login.php?error=empty');
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
unset($_SESSION['csrf_token']);

/* =========================================================
   ✅ Permisos a sesión (rápido + consistente)
========================================================= */
$roleId = (int)($user['role_id'] ?? 0);
$perms = [];

if ($roleId > 0) {
  $stPerms = $pdo->prepare("
    SELECT p.slug
    FROM role_permission rp
    JOIN permissions p ON rp.permission_id = p.id
    WHERE rp.role_id = :rid
  ");
  $stPerms->execute([':rid' => $roleId]);
  $perms = $stPerms->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/* =========================================================
   ✅ Sesión unificada (compat vieja + middleware/controller)
========================================================= */
$_SESSION['user'] = [
  'id'        => (int)$user['id'],
  'nombre'    => (string)($user['nombre'] ?? ''),
  'username'  => (string)$user['username'],
  'email'     => (string)($user['email'] ?? ''),
  'role_id'   => $roleId,
  'role_slug' => (string)($user['role_slug'] ?? ''),
];

// Compatibilidad para Middleware/BaseController/etc
$_SESSION['user_id']    = (int)$user['id'];
$_SESSION['user_name']  = (string)($user['nombre'] ?? $user['username'] ?? '');
$_SESSION['user_email'] = (string)($user['email'] ?? '');
$_SESSION['user_role']  = (string)($user['role_slug'] ?? '');
$_SESSION['permissions'] = $perms;

// Ya logueado: mandalo al inicio / dashboard
header('Location: index.php');
exit;

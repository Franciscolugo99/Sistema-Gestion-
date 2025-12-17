<?php
// public/login_process.php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

// Solo POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: login.php');
  exit;
}

// CSRF (viene del login.php con csrf_field())
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

// (Opcional pero sano) límite de tamaño
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

// Evitar session fixation
session_regenerate_id(true);

// Rotar CSRF después de login
unset($_SESSION['csrf_token']);

$_SESSION['user'] = [
  'id'        => (int)$user['id'],
  'nombre'    => (string)($user['nombre'] ?? ''),
  'username'  => (string)$user['username'],
  'role_id'   => (int)$user['role_id'],
  'role_slug' => (string)($user['role_slug'] ?? ''),
];

header('Location: caja.php');
exit;

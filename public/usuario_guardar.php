<?php
// public/usuario_guardar.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
require_permission('administrar_usuarios');

require_once __DIR__ . '/../src/config.php';
$pdo = getPDO();

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

function back_with_error(string $msg): void {
  $_SESSION['flash_error'] = $msg;
  header('Location: usuario_nuevo.php');
  exit;
}

/* --------------------------------------------------------
   CSRF
-------------------------------------------------------- */
$token = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
  back_with_error('Token inválido. Recargá el formulario e intentá de nuevo.');
}

/* --------------------------------------------------------
   Recibir datos
-------------------------------------------------------- */
$nombre   = trim((string)($_POST['nombre'] ?? ''));
$email    = trim((string)($_POST['email'] ?? ''));
$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');
$role_id  = (int)($_POST['role_id'] ?? 0);
$activo   = isset($_POST['activo']) ? (int)$_POST['activo'] : 1;
$activo   = $activo === 0 ? 0 : 1;

/* --------------------------------------------------------
   Validaciones
-------------------------------------------------------- */
if ($nombre === '' || $username === '' || $password === '' || $role_id <= 0) {
  back_with_error('Completá nombre, usuario, contraseña y rol.');
}

if (strlen($username) < 3 || strlen($username) > 50) {
  back_with_error('El usuario debe tener entre 3 y 50 caracteres.');
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  back_with_error('Email inválido.');
}

if (strlen($password) < 6) {
  back_with_error('La contraseña debe tener al menos 6 caracteres.');
}

// Validar rol existente
$stRole = $pdo->prepare("SELECT 1 FROM roles WHERE id = :id LIMIT 1");
$stRole->execute([':id' => $role_id]);
if (!$stRole->fetchColumn()) {
  back_with_error('Rol inválido.');
}

/* --------------------------------------------------------
   Validar usuario único
-------------------------------------------------------- */
$sqlCheck = "SELECT id FROM users WHERE username = :u LIMIT 1";
$stmt = $pdo->prepare($sqlCheck);
$stmt->execute([':u' => $username]);

if ($stmt->fetchColumn()) {
  back_with_error('El usuario ya existe. Elegí otro nombre de usuario.');
}

/* --------------------------------------------------------
   Crear usuario
-------------------------------------------------------- */
$hash = password_hash($password, PASSWORD_DEFAULT);

$sql = "
  INSERT INTO users (nombre, email, username, password_hash, role_id, activo)
  VALUES (:n, :e, :u, :p, :r, :a)
";
$stmt = $pdo->prepare($sql);
$stmt->execute([
  ':n' => $nombre,
  ':e' => $email !== '' ? $email : null,
  ':u' => $username,
  ':p' => $hash,
  ':r' => $role_id,
  ':a' => $activo,
]);

$_SESSION['flash_success'] = 'Usuario creado correctamente.';
header("Location: usuarios.php");
exit;

<?php
// public/usuario_guardar.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('administrar_usuarios');


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
  back_with_error('Token invalido. Recarga el formulario e intenta de nuevo.');
}

/* --------------------------------------------------------
   Validaciones compartidas
-------------------------------------------------------- */
$validation = flus_validate_user_payload($pdo, $_POST, [
  'require_password' => true,
  'require_email' => false,
  'default_activo' => 1,
]);
$data = $validation['data'];
$errors = $validation['errors'];

if (!empty($errors)) {
  back_with_error((string)$errors[0]);
}

/* --------------------------------------------------------
   Crear usuario
-------------------------------------------------------- */
$hash = password_hash((string)$data['password'], PASSWORD_DEFAULT);

$sql = "
  INSERT INTO users (nombre, email, username, password_hash, role_id, activo)
  VALUES (:n, :e, :u, :p, :r, :a)
";
$stmt = $pdo->prepare($sql);
$stmt->execute([
  ':n' => $data['nombre'],
  ':e' => $data['email'] !== '' ? $data['email'] : null,
  ':u' => $data['username'],
  ':p' => $hash,
  ':r' => (int)$data['role_id'],
  ':a' => (int)$data['activo'],
]);

$_SESSION['flash_success'] = 'Usuario creado correctamente.';
header('Location: usuarios.php');
exit;

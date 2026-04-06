<?php
// public/usuario_guardar.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/../src/user_admin_lib.php';
require_login();
require_permission('administrar_usuarios');


function back_with_form_errors(array $errors, array $data = []): void {
    $_SESSION['form_errors'] = $errors;
    $_SESSION['form_data'] = flus_user_create_form_data($data);
    header('Location: usuario_nuevo.php');
    exit;
}

/* --------------------------------------------------------
   CSRF
-------------------------------------------------------- */
$token = (string)($_POST['csrf_token'] ?? $_POST['csrf'] ?? '');
if (!csrf_verify($token)) {
    back_with_form_errors(['Token CSRF invalido. Reintenta el envio.']);
}

$result = flus_create_user_from_payload($pdo, $_POST);
$data = $result['data'];
$errors = $result['errors'];

if (!empty($errors)) {
    back_with_form_errors($errors, $data);
}

$_SESSION['flash_success'] = 'Usuario creado correctamente.';
header('Location: usuarios.php');
exit;

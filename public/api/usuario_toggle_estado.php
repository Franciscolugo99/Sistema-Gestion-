<?php
declare(strict_types=1);
// public/api/usuario_toggle_estado.php

require_once __DIR__ . '/_bootstrap.php';

$input = api_read_json();

try {
    require_login();
    require_permission('administrar_usuarios');
} catch (Throwable $e) {
    success_fail('Acceso denegado', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    success_fail('Metodo no permitido', 405);
}

$csrfToken = function_exists('flus_csrf_from_request') ? flus_csrf_from_request($input) : '';
if ($csrfToken === '' || !function_exists('csrf_verify') || !csrf_verify($csrfToken)) {
    success_fail('Token CSRF invalido', 403);
}

if (!is_array($input) || !isset($input['user_id'])) {
    success_fail('Datos invalidos', 400);
}

$userId = (int)$input['user_id'];
$activo = !empty($input['activo']) ? 1 : 0;

if ($userId <= 0) {
    success_fail('ID de usuario invalido', 400);
}

$actorUserId = function_exists('session_user_id')
    ? session_user_id()
    : (int)($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0));

$guardError = flus_guard_user_admin_mutation(
    $pdo,
    $actorUserId,
    $userId,
    $activo,
    false,
    null
);
if (is_string($guardError) && $guardError !== '') {
    success_fail($guardError, 409);
}

try {
    $stmt = $pdo->prepare(
        'UPDATE users
         SET activo = :activo,
             updated_at = NOW()
         WHERE id = :id'
    );
    $stmt->execute([
        ':activo' => $activo,
        ':id' => $userId,
    ]);

    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('Usuario no encontrado');
    }

    try {
        $logStmt = $pdo->prepare(
            "INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, created_at)
             VALUES (:user_id, :action, 'user', :entity_id, :details, NOW())"
        );

        $logStmt->execute([
            ':user_id' => $actorUserId > 0 ? $actorUserId : null,
            ':action' => $activo ? 'activate_user' : 'deactivate_user',
            ':entity_id' => $userId,
            ':details' => json_encode(['activo' => $activo]),
        ]);
    } catch (PDOException $e) {
        error_log('Audit log error: ' . $e->getMessage());
    }

    success_ok([
        'message' => $activo ? 'Usuario activado correctamente' : 'Usuario desactivado correctamente',
        'activo' => $activo,
    ]);
} catch (PDOException $e) {
    error_log('Error al actualizar estado de usuario: ' . $e->getMessage());
    success_fail('Error al actualizar el estado del usuario', 500);
} catch (Throwable $e) {
    success_fail($e->getMessage(), 404);
}

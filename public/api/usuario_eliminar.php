<?php
declare(strict_types=1);
// public/api/usuario_eliminar.php

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
    null,
    true,
    null
);
if (is_string($guardError) && $guardError !== '') {
    success_fail($guardError, 409);
}

try {
    $pdo->beginTransaction();

    $checkStmt = $pdo->prepare('SELECT id, nombre FROM users WHERE id = :id');
    $checkStmt->execute([':id' => $userId]);
    $user = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new RuntimeException('Usuario no encontrado');
    }

    $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);

    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('No se pudo eliminar el usuario');
    }

    try {
        $logStmt = $pdo->prepare(
            "INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, created_at)
             VALUES (:user_id, 'delete_user', 'user', :entity_id, :details, NOW())"
        );

        $logStmt->execute([
            ':user_id' => $actorUserId > 0 ? $actorUserId : null,
            ':entity_id' => $userId,
            ':details' => json_encode([
                'deleted_user_name' => $user['nombre'],
            ]),
        ]);
    } catch (PDOException $e) {
        error_log('Audit log error: ' . $e->getMessage());
    }

    $pdo->commit();

    success_ok(['message' => 'Usuario eliminado correctamente']);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Error al eliminar usuario: ' . $e->getMessage());

    if (stripos($e->getMessage(), 'foreign key constraint') !== false) {
        success_fail(
            'No se puede eliminar el usuario porque tiene registros asociados. Considere desactivarlo en su lugar.',
            409
        );
    }

    success_fail('Error al eliminar el usuario', 500);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    success_fail($e->getMessage(), 404);
}

<?php
// api/rol_eliminar.php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$input = api_read_json();
if ($input === [] && !empty($_POST)) {
    $input = $_POST;
}

require_login_json();
require_perm_json('administrar_usuarios');
require_method_json('POST');
require_csrf_json($input);

$roleId = (int)($input['role_id'] ?? 0);
if ($roleId <= 0) {
    success_fail('ID de rol invalido', 400);
}

// Verificar que el rol existe
try {
    $stmt = $pdo->prepare("SELECT id, nombre, slug FROM roles WHERE id = :id");
    $stmt->execute([':id' => $roleId]);
    $role = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$role) {
        success_fail('Rol no encontrado', 404);
    }
    
    if ((function_exists('flus_is_critical_role') && flus_is_critical_role((string)$role['slug'])) || in_array(strtolower((string)$role['slug']), ['administrador', 'admin', 'superadmin'], true)) {
        success_fail('No se puede eliminar un rol critico del sistema', 409);
    }
    
    // Verificar si tiene usuarios asignados
    $stmtUsers = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id = :id");
    $stmtUsers->execute([':id' => $roleId]);
    $totalUsers = (int)$stmtUsers->fetchColumn();
    
    if ($totalUsers > 0) {
        success_fail("No se puede eliminar el rol porque tiene {$totalUsers} usuario(s) asignado(s). Reasigna los usuarios a otro rol primero.", 409);
    }
    
} catch (PDOException $e) {
    error_log("Error al verificar rol: " . $e->getMessage());
    success_fail('Error al verificar el rol', 500);
}

// Eliminar rol
try {
    $pdo->beginTransaction();
    
    // Eliminar permisos asociados
    $stmtPerms = $pdo->prepare("DELETE FROM role_permission WHERE role_id = :id");
    $stmtPerms->execute([':id' => $roleId]);
    
    // Eliminar rol
    $stmtRole = $pdo->prepare("DELETE FROM roles WHERE id = :id");
    $stmtRole->execute([':id' => $roleId]);
    
    if ($stmtRole->rowCount() === 0) {
        throw new RuntimeException('No se pudo eliminar el rol');
    }
    
    // Log de auditoría (opcional)
    try {
        $logStmt = $pdo->prepare("
            INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, created_at)
            VALUES (:user_id, 'delete_role', 'role', :entity_id, :details, NOW())
        ");

        $actorUserId = function_exists('session_user_id')
            ? session_user_id()
            : (int)($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0));
        
        $logStmt->execute([
            ':user_id' => $actorUserId > 0 ? $actorUserId : null,
            ':entity_id' => $roleId,
            ':details' => json_encode([
                'role_name' => $role['nombre'],
                'role_slug' => $role['slug']
            ])
        ]);
    } catch (PDOException $e) {
        // Si la tabla de auditoría no existe, continuar sin error
        error_log("Audit log error: " . $e->getMessage());
    }
    
    $pdo->commit();
    success_ok(['message' => "Rol \"{$role['nombre']}\" eliminado correctamente"]);
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error al eliminar rol: " . $e->getMessage());
    success_fail('Error al eliminar el rol', 500);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    success_fail($e->getMessage(), 500);
}

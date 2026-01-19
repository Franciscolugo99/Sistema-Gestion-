<?php
// api/rol_eliminar.php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../bootstrap.php';

// Verificar autenticación y permisos
try {
    require_login();
    require_permission('administrar_usuarios');
} catch (Exception $e) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Acceso denegado'
    ]);
    exit;
}

// Solo permitir POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido'
    ]);
    exit;
}

// Obtener datos JSON
$input = json_decode(file_get_contents('php://input'), true);

// ✅ FIX v2.1.2: Validar CSRF
require_once __DIR__ . '/../lib/csrf.php';
$csrfToken = $input['csrf_token'] ?? $input['csrf'] ?? $_POST['csrf_token'] ?? '';
if (!csrf_verify($csrfToken)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Token CSRF inválido'
    ]);
    exit;
}

if (!$input || !isset($input['role_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Datos inválidos'
    ]);
    exit;
}

$roleId = (int)$input['role_id'];

// Validaciones
if ($roleId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'ID de rol inválido'
    ]);
    exit;
}

// Verificar que el rol existe
try {
    $stmt = $pdo->prepare("SELECT id, nombre, slug FROM roles WHERE id = :id");
    $stmt->execute([':id' => $roleId]);
    $role = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$role) {
        throw new Exception('Rol no encontrado');
    }
    
    // No permitir eliminar roles críticos
    $rolesProtegidos = ['administrador', 'admin', 'superadmin'];
    if (in_array(strtolower($role['slug']), $rolesProtegidos)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No se puede eliminar un rol crítico del sistema'
        ]);
        exit;
    }
    
    // Verificar si tiene usuarios asignados
    $stmtUsers = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id = :id");
    $stmtUsers->execute([':id' => $roleId]);
    $totalUsers = (int)$stmtUsers->fetchColumn();
    
    if ($totalUsers > 0) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => "No se puede eliminar el rol porque tiene {$totalUsers} usuario(s) asignado(s). Reasigne los usuarios a otro rol primero."
        ]);
        exit;
    }
    
} catch (PDOException $e) {
    error_log("Error al verificar rol: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al verificar el rol'
    ]);
    exit;
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
        throw new Exception('No se pudo eliminar el rol');
    }
    
    // Log de auditoría (opcional)
    try {
        $logStmt = $pdo->prepare("
            INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, created_at)
            VALUES (:user_id, 'delete_role', 'role', :entity_id, :details, NOW())
        ");
        
        if (session_status() === PHP_SESSION_NONE) session_start();
        
        $logStmt->execute([
            ':user_id' => $_SESSION['user_id'] ?? null,
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
    
    echo json_encode([
        'success' => true,
        'message' => 'Rol eliminado correctamente'
    ]);
    
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Error al eliminar rol: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al eliminar el rol'
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
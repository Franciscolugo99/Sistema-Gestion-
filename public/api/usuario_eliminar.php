<?php
// api/usuario_eliminar.php
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

if (!$input || !isset($input['user_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Datos inválidos'
    ]);
    exit;
}

$userId = (int)$input['user_id'];

// Validaciones
if ($userId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'ID de usuario inválido'
    ]);
    exit;
}

// No permitir eliminar el propio usuario
if (session_status() === PHP_SESSION_NONE) session_start();
if ($userId === (int)($_SESSION['user_id'] ?? 0)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'No puedes eliminar tu propio usuario'
    ]);
    exit;
}

// Eliminar usuario
try {
    $pdo->beginTransaction();
    
    // Verificar que el usuario existe
    $checkStmt = $pdo->prepare("SELECT id, nombre FROM users WHERE id = :id");
    $checkStmt->execute([':id' => $userId]);
    $user = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception('Usuario no encontrado');
    }
    
    // IMPORTANTE: Dependiendo de tu esquema, puede que necesites:
    // 1. Eliminar registros relacionados (CASCADE si no está configurado en DB)
    // 2. O marcar como eliminado soft-delete (activo = -1, deleted_at = NOW())
    
    // Opción 1: Eliminación permanente (hard delete)
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    
    // Opción 2: Soft delete (comentar la línea anterior y descomentar estas):
    /*
    $stmt = $pdo->prepare("
        UPDATE users 
        SET activo = -1, 
            deleted_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([':id' => $userId]);
    */
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('No se pudo eliminar el usuario');
    }
    
    // Log de auditoría
    try {
        $logStmt = $pdo->prepare("
            INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, created_at)
            VALUES (:user_id, 'delete_user', 'user', :entity_id, :details, NOW())
        ");
        
        $logStmt->execute([
            ':user_id' => $_SESSION['user_id'] ?? null,
            ':entity_id' => $userId,
            ':details' => json_encode([
                'deleted_user_name' => $user['nombre']
            ])
        ]);
    } catch (PDOException $e) {
        // Si la tabla de auditoría no existe, continuar sin error
        error_log("Audit log error: " . $e->getMessage());
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Usuario eliminado correctamente'
    ]);
    
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("Error al eliminar usuario: " . $e->getMessage());
    
    // Verificar si es error de constraint (registros relacionados)
    if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'No se puede eliminar el usuario porque tiene registros asociados. Considere desactivarlo en su lugar.'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error al eliminar el usuario'
        ]);
    }
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
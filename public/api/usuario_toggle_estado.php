<?php
// api/usuario_toggle_estado.php
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
if (!csrf_validate($csrfToken)) {
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
$activo = !empty($input['activo']) ? 1 : 0;

// Validaciones
if ($userId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'ID de usuario inválido'
    ]);
    exit;
}

// No permitir desactivar el propio usuario
if (session_status() === PHP_SESSION_NONE) session_start();
if ($userId === (int)($_SESSION['user_id'] ?? 0)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'No puedes desactivar tu propio usuario'
    ]);
    exit;
}

// Actualizar estado
try {
    $stmt = $pdo->prepare("
        UPDATE users 
        SET activo = :activo,
            updated_at = NOW()
        WHERE id = :id
    ");
    
    $stmt->execute([
        ':activo' => $activo,
        ':id' => $userId
    ]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Usuario no encontrado');
    }
    
    // Log de auditoría (opcional)
    try {
        $logStmt = $pdo->prepare("
            INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, created_at)
            VALUES (:user_id, :action, 'user', :entity_id, :details, NOW())
        ");
        
        $logStmt->execute([
            ':user_id' => $_SESSION['user_id'] ?? null,
            ':action' => $activo ? 'activate_user' : 'deactivate_user',
            ':entity_id' => $userId,
            ':details' => json_encode(['activo' => $activo])
        ]);
    } catch (PDOException $e) {
        // Si la tabla de auditoría no existe, continuar sin error
        error_log("Audit log error: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => $activo 
            ? 'Usuario activado correctamente' 
            : 'Usuario desactivado correctamente',
        'activo' => $activo
    ]);
    
} catch (PDOException $e) {
    error_log("Error al actualizar estado de usuario: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error al actualizar el estado del usuario'
    ]);
} catch (Exception $e) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
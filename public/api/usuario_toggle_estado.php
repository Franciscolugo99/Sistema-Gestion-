<?php
// api/usuario_toggle_estado.php
declare(strict_types=1);

// Contexto API
define('FLUS_API_CONTEXT', true);

header('Content-Type: application/json');

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/_csrf_guard.php'; csrf_require(['methods'=>['POST','PUT','DELETE']]);
require_once FLUS_ROOT . '/src/api_helpers.php';
setup_api_error_handlers();

// Verificar autenticación y permisos
try {
    require_login();
    require_permission('administrar_usuarios');
} catch (Exception $e) {
    success_fail('Acceso denegado', 403);
}

// Solo permitir POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    success_fail('Método no permitido', 405);
}

// Obtener datos JSON
$input = json_decode(file_get_contents('php://input'), true);

// ✅ FIX v2.1.2: Validar CSRF
require_once __DIR__ . '/../lib/csrf.php';
$csrfToken = $input['csrf_token'] ?? $input['csrf'] ?? $_POST['csrf_token'] ?? '';
if (!csrf_verify($csrfToken)) {
    success_fail('Token CSRF inválido', 403);
}

if (!$input || !isset($input['user_id'])) {
    success_fail('Datos inválidos', 400);
}

$userId = (int)$input['user_id'];
$activo = !empty($input['activo']) ? 1 : 0;

// Validaciones
if ($userId <= 0) {
    success_fail('ID de usuario inválido', 400);
}

if (session_status() === PHP_SESSION_NONE) session_start();

$guardError = flus_guard_user_admin_mutation(
    $pdo,
    (int)($_SESSION['user_id'] ?? 0),
    $userId,
    $activo,
    false,
    null
);
if (is_string($guardError) && $guardError !== '') {
    success_fail($guardError, 409);
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
    
    success_ok(['message' => ($activo ? 'Usuario activado correctamente' : 'Usuario desactivado correctamente'), 'activo' => $activo]);
    
} catch (PDOException $e) {
    error_log("Error al actualizar estado de usuario: " . $e->getMessage());
    success_fail('Error al actualizar el estado del usuario', 500);
} catch (Exception $e) {
    success_fail($e->getMessage(), 404);
}

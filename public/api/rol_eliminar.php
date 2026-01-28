<?php
// api/rol_eliminar.php
declare(strict_types=1);

// Contexto API
define('FLUS_API_CONTEXT', true);

require_once __DIR__ . '/../bootstrap.php';

// Verificar autenticación y permisos
try {
    require_login();
    require_permission('administrar_usuarios');
} catch (Exception $e) {
    // Si es request AJAX, responder JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Acceso denegado']);
        exit;
    }
    // Si no, redirigir
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash_error'] = 'Acceso denegado';
    header('Location: ../roles.php');
    exit;
}

// Solo permitir POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
        exit;
    }
    header('Location: ../roles.php');
    exit;
}

// Detectar tipo de request (JSON o form-data)
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isJson = strpos($contentType, 'application/json') !== false;

if ($isJson) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $roleId = (int)($input['role_id'] ?? 0);
    $csrfToken = $input['csrf_token'] ?? $input['csrf'] ?? '';
} else {
    $roleId = (int)($_POST['role_id'] ?? 0);
    $csrfToken = $_POST['csrf_token'] ?? '';
}

// Validar CSRF (csrf_verify viene de bootstrap.php -> lib/csrf.php)
if (!csrf_verify($csrfToken)) {
    if ($isJson || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido']);
        exit;
    }
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash_error'] = 'Token CSRF inválido. Recarga la página e intenta de nuevo.';
    header('Location: ../roles.php');
    exit;
}

// Validaciones
if ($roleId <= 0) {
    if ($isJson || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'ID de rol inválido']);
        exit;
    }
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash_error'] = 'ID de rol inválido';
    header('Location: ../roles.php');
    exit;
}

// Función helper para respuestas
function respond($success, $message, $isJson) {
    if ($isJson || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        if ($success) {
            echo json_encode(['ok' => true, 'message' => $message]);
        } else {
            echo json_encode(['ok' => false, 'error' => $message]);
        }
        exit;
    }
    
    if (session_status() === PHP_SESSION_NONE) session_start();
    if ($success) {
        $_SESSION['flash_success'] = $message;
    } else {
        $_SESSION['flash_error'] = $message;
    }
    header('Location: ../roles.php');
    exit;
}

// Verificar que el rol existe
try {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT id, nombre, slug FROM roles WHERE id = :id");
    $stmt->execute([':id' => $roleId]);
    $role = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$role) {
        respond(false, 'Rol no encontrado', $isJson);
    }
    
    // No permitir eliminar roles críticos
    $rolesProtegidos = ['administrador', 'admin', 'superadmin'];
    if (in_array(strtolower($role['slug']), $rolesProtegidos)) {
        respond(false, 'No se puede eliminar un rol crítico del sistema', $isJson);
    }
    
    // Verificar si tiene usuarios asignados
    $stmtUsers = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id = :id");
    $stmtUsers->execute([':id' => $roleId]);
    $totalUsers = (int)$stmtUsers->fetchColumn();
    
    if ($totalUsers > 0) {
        respond(false, "No se puede eliminar el rol porque tiene {$totalUsers} usuario(s) asignado(s). Reasigna los usuarios a otro rol primero.", $isJson);
    }
    
} catch (PDOException $e) {
    error_log("Error al verificar rol: " . $e->getMessage());
    respond(false, 'Error al verificar el rol', $isJson);
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
    respond(true, "Rol \"{$role['nombre']}\" eliminado correctamente", $isJson);
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Error al eliminar rol: " . $e->getMessage());
    respond(false, 'Error al eliminar el rol', $isJson);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    respond(false, $e->getMessage(), $isJson);
}

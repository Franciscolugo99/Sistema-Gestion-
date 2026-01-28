<?php
// public/rol_guardar.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('administrar_usuarios');

if (session_status() === PHP_SESSION_NONE) session_start();

// Solo POST
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: roles.php');
    exit;
}

// CSRF verification
$csrf = (string)($_POST['csrf_token'] ?? '');
if (!csrf_verify($csrf)) {
    $_SESSION['flash_error'] = 'Token CSRF inválido. Recarga la página e intenta de nuevo.';
    header('Location: roles.php');
    exit;
}

// Obtener datos
$roleId = (int)($_POST['role_id'] ?? 0);
$nombre = trim((string)($_POST['nombre'] ?? ''));
$slug = trim((string)($_POST['slug'] ?? ''));
$isCriticalFlag = (string)($_POST['is_critical'] ?? '0');

// Roles protegidos (slugs que no pueden modificarse)
$rolesProtegidos = ['administrador', 'admin', 'superadmin'];

// Si es edición, verificar si el rol actual es crítico
$originalSlug = '';
$isRoleCritico = false;

if ($roleId > 0) {
    $stmt = $pdo->prepare("SELECT slug FROM roles WHERE id = ? LIMIT 1");
    $stmt->execute([$roleId]);
    $existingRole = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingRole) {
        $originalSlug = $existingRole['slug'];
        $isRoleCritico = in_array(strtolower($originalSlug), $rolesProtegidos);
    }
}

// Si es rol crítico, forzar que el slug no cambie
if ($isRoleCritico) {
    $slug = $originalSlug;
}

// Validaciones
$errors = [];

if ($nombre === '') {
    $errors[] = 'El nombre es requerido.';
}

if ($slug === '') {
    $errors[] = 'El slug es requerido.';
}

if ($slug !== '' && !preg_match('/^[a-z0-9_]+$/', $slug)) {
    $errors[] = 'El slug solo puede contener letras minúsculas, números y guiones bajos.';
}

if (strlen($nombre) > 50) {
    $errors[] = 'El nombre no puede exceder 50 caracteres.';
}

if (strlen($slug) > 50) {
    $errors[] = 'El slug no puede exceder 50 caracteres.';
}

// Verificar slug único
if ($slug !== '') {
    $stmt = $pdo->prepare("SELECT id FROM roles WHERE slug = ? AND id != ? LIMIT 1");
    $stmt->execute([$slug, $roleId]);
    if ($stmt->fetch()) {
        $errors[] = 'Ya existe un rol con ese slug.';
    }
}

// Prevenir crear un nuevo rol con slug protegido
if ($roleId === 0 && in_array(strtolower($slug), $rolesProtegidos)) {
    $errors[] = 'No se puede crear un rol con un slug reservado del sistema.';
}

// Si hay errores, redirigir
if (!empty($errors)) {
    $_SESSION['flash_error'] = implode(' ', $errors);
    header('Location: roles.php');
    exit;
}

try {
    if ($roleId > 0) {
        // Actualizar rol existente
        $stmt = $pdo->prepare("UPDATE roles SET nombre = ?, slug = ? WHERE id = ?");
        $stmt->execute([$nombre, $slug, $roleId]);
        $_SESSION['flash_success'] = "Rol \"{$nombre}\" actualizado correctamente.";
    } else {
        // Crear nuevo rol
        $stmt = $pdo->prepare("INSERT INTO roles (nombre, slug) VALUES (?, ?)");
        $stmt->execute([$nombre, $slug]);
        $newId = $pdo->lastInsertId();
        $_SESSION['flash_success'] = "Rol \"{$nombre}\" creado correctamente.";
    }
} catch (PDOException $e) {
    error_log("Error al guardar rol: " . $e->getMessage());
    $_SESSION['flash_error'] = 'Error al guardar el rol. Por favor, intente nuevamente.';
}

header('Location: roles.php');
exit;

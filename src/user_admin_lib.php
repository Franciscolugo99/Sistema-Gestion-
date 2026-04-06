<?php
declare(strict_types=1);

if (!function_exists('flus_user_create_form_data')) {
    function flus_user_create_form_data(array $data): array {
        return [
            'nombre' => (string)($data['nombre'] ?? ''),
            'email' => (string)($data['email'] ?? ''),
            'username' => (string)($data['username'] ?? ''),
            'role_id' => (int)($data['role_id'] ?? 0),
            'activo' => (int)($data['activo'] ?? 0),
        ];
    }
}

if (!function_exists('flus_create_user_from_payload')) {
    function flus_create_user_from_payload(PDO $pdo, array $input): array {
        $validation = flus_validate_user_payload($pdo, $input, [
            'require_password' => true,
            'require_email' => true,
            'default_activo' => 0,
        ]);
        $data = $validation['data'];
        $errors = $validation['errors'];

        if (!empty($errors)) {
            return [
                'ok' => false,
                'data' => $data,
                'errors' => $errors,
            ];
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO users (nombre, email, username, password_hash, role_id, activo, created_at)
                VALUES (:nombre, :email, :username, :password_hash, :role_id, :activo, NOW())
            ");
            $stmt->execute([
                ':nombre' => $data['nombre'],
                ':email' => $data['email'],
                ':username' => $data['username'],
                ':password_hash' => password_hash((string)$data['password'], PASSWORD_DEFAULT),
                ':role_id' => (int)$data['role_id'],
                ':activo' => (int)$data['activo'],
            ]);
        } catch (PDOException $e) {
            error_log('Error al crear usuario: ' . $e->getMessage());

            return [
                'ok' => false,
                'data' => $data,
                'errors' => ['Error al crear el usuario. Intenta de nuevo.'],
            ];
        }

        return [
            'ok' => true,
            'data' => $data,
            'errors' => [],
        ];
    }
}

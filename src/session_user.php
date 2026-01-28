<?php
// src/session_user.php
// Helpers unificados de sesión (compat legacy)
// Objetivo: una sola forma de leer user_id / permisos sin romper instalaciones viejas.

declare(strict_types=1);

if (!function_exists('flus_session_ensure_started')) {
  function flus_session_ensure_started(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    // Preferir helper del sistema si existe
    if (function_exists('flus_session_start')) {
      flus_session_start();
      return;
    }

    @session_start();
  }
}

if (!function_exists('flus_session_normalize_user')) {
  function flus_session_normalize_user(): void {
    flus_session_ensure_started();

    // Permisos: compat
    if (empty($_SESSION['permissions']) && isset($_SESSION['permisos']) && is_array($_SESSION['permisos'])) {
      $_SESSION['permissions'] = $_SESSION['permisos'];
    }

    $u = $_SESSION['user'] ?? null;

    // Si viene user[] (nuevo o legacy), derivar user_id y permissions
    if (is_array($u)) {
      $id = $u['id'] ?? $u['user_id'] ?? $u['usuario_id'] ?? null;
      if ($id !== null && $id !== '' && empty($_SESSION['user_id'])) {
        $_SESSION['user_id'] = (int)$id;
      }

      if (empty($_SESSION['permissions'])) {
        $perms = $u['permissions'] ?? $u['permisos'] ?? null;
        if (is_array($perms)) $_SESSION['permissions'] = $perms;
      }
    }

    // Legacy: usuario_id directo
    if (empty($_SESSION['user_id']) && !empty($_SESSION['usuario_id'])) {
      $_SESSION['user_id'] = (int)$_SESSION['usuario_id'];
    }

    // Si no existe user[] pero existe user_id, construir uno mínimo
    if ((!isset($_SESSION['user']) || !is_array($_SESSION['user'])) && !empty($_SESSION['user_id'])) {
      $perms = $_SESSION['permissions'] ?? [];
      if (!is_array($perms)) $perms = [];

      $_SESSION['user'] = [
        'id'          => (int)$_SESSION['user_id'],
        'nombre'      => (string)($_SESSION['user_name'] ?? ''),
        'username'    => (string)($_SESSION['user_username'] ?? ''),
        'email'       => (string)($_SESSION['user_email'] ?? ''),
        'role_slug'   => (string)($_SESSION['user_role'] ?? ''),
        'permissions' => $perms,
      ];
      return;
    }

    // Garantizar coherencia dentro de user[]
    if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
      if (empty($_SESSION['user']['id']) && !empty($_SESSION['user_id'])) {
        $_SESSION['user']['id'] = (int)$_SESSION['user_id'];
      }
      if (empty($_SESSION['user']['permissions']) && !empty($_SESSION['permissions']) && is_array($_SESSION['permissions'])) {
        $_SESSION['user']['permissions'] = $_SESSION['permissions'];
      }
    }
  }
}

if (!function_exists('session_user_id')) {
  function session_user_id(): int {
    if (function_exists('flus_session_normalize_user')) flus_session_normalize_user();

    if (!empty($_SESSION['user_id'])) return (int)$_SESSION['user_id'];
    $u = $_SESSION['user'] ?? null;
    if (is_array($u) && !empty($u['id'])) return (int)$u['id'];
    return (int)($_SESSION['usuario_id'] ?? 0);
  }
}

if (!function_exists('session_user')) {
  function session_user(): ?array {
    if (function_exists('flus_session_normalize_user')) flus_session_normalize_user();
    return (isset($_SESSION['user']) && is_array($_SESSION['user'])) ? $_SESSION['user'] : null;
  }
}

if (!function_exists('session_permissions')) {
  function session_permissions(): array {
    if (function_exists('flus_session_normalize_user')) flus_session_normalize_user();

    $perms = $_SESSION['permissions'] ?? ($_SESSION['permisos'] ?? []);
    if (is_array($perms)) return $perms;

    $u = $_SESSION['user'] ?? null;
    if (is_array($u) && isset($u['permissions']) && is_array($u['permissions'])) return $u['permissions'];

    return [];
  }
}

if (!function_exists('session_has_permission')) {
  function session_has_permission(string $perm): bool {
    $perm = trim($perm);
    if ($perm === '') return false;
    return in_array($perm, session_permissions(), true);
  }
}

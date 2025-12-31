<?php
// src/Middleware.php
declare(strict_types=1);

final class Middleware {
  private array $checks = [];

  public static function create(): self {
    return new self();
  }

  public static function auth(): self {
    return self::create()->requireAuth();
  }

  private static function wants_json(): bool {
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xhr    = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    $uri    = $_SERVER['REQUEST_URI'] ?? '';
    $isApi  = str_contains($uri, '/api/');
    return $isApi || str_contains($accept, 'application/json') || $xhr === 'XMLHttpRequest';
  }

  private static function abort(int $code, string $message): never {
    http_response_code($code);

    if (self::wants_json() && function_exists('json_error')) {
      json_error($message, $code);
    }

    die($message);
  }

  /**
   * Normaliza sesión vieja -> nueva
   * Acepta:
   * - $_SESSION['user_id']
   * - $_SESSION['user']['id'] / ['user_id'] / ['usuario_id']
   */
  private static function normalizeSession(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    // user_id directo
    if (!empty($_SESSION['user_id'])) return;

    // formatos legacy
    $u = $_SESSION['user'] ?? null;
    if (is_array($u)) {
      $id = $u['id'] ?? $u['user_id'] ?? $u['usuario_id'] ?? null;
      if ($id !== null && $id !== '') {
        $_SESSION['user_id'] = (int)$id;
      }

      // permisos legacy si vienen adentro de user
      if (empty($_SESSION['permissions'])) {
        $perms = $u['permissions'] ?? $u['permisos'] ?? [];
        if (is_array($perms)) $_SESSION['permissions'] = $perms;
      }
    }
  }

  /**
   * IMPORTANTE: login.php SIN "/" adelante (relativo a /kiosco/public/)
   */
public function requireAuth(?string $redirectTo = 'login.php'): self {
  $this->checks[] = function() use ($redirectTo) {
    // Si existe tu auth.php, usalo como fuente de verdad
    if (self::wants_json()) {
      if (function_exists('require_login_json')) {
        require_login_json(); // devuelve 401 JSON y corta
        return;
      }
    } else {
      if (function_exists('require_login')) {
        require_login(true); // redirige a login.php?next=...
        return;
      }
    }

    // Fallback si por algún motivo no existe auth.php
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    $logged =
      (!empty($_SESSION['user']) && is_array($_SESSION['user'])) ||
      (!empty($_SESSION['user_id'])); // compat vieja

    if (!$logged) {
      if ($redirectTo && !self::wants_json()) {
        header("Location: {$redirectTo}");
        exit;
      }
      self::abort(401, 'No autenticado');
    }
  };

  return $this;
}
  public function permission(string $permission): self {
  $this->checks[] = function() use ($permission) {
    // 1) Preferir DB (tu user_has_permission de auth.php)
    if (function_exists('user_has_permission')) {
      if (!user_has_permission($permission)) {
        self::abort(403, 'Acceso denegado: no tenés permiso.');
      }
      return;
    }

    // 2) Fallback: permisos en sesión (si existieran)
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $userPerms = $_SESSION['permissions'] ?? [];
    if (!is_array($userPerms)) $userPerms = [];

    if (!in_array($permission, $userPerms, true)) {
      self::abort(403, 'Acceso denegado: no tenés permiso.');
    }
  };

  return $this;
}

  public function csrf(): self {
    $this->checks[] = function() {
      $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
      if (!in_array($method, ['POST','PUT','PATCH','DELETE'], true)) return;

      $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

      if (!function_exists('csrf_verify') || !csrf_verify($token)) {
        self::abort(403, 'Token CSRF inválido.');
      }
    };
    return $this;
  }

  public function methods(string ...$allowed): self {
    $this->checks[] = function() use ($allowed) {
      $allowed = array_map('strtoupper', $allowed);
      $method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

      if (!in_array($method, $allowed, true)) {
        http_response_code(405);
        header('Allow: ' . implode(', ', $allowed));
        die('Método no permitido.');
      }
    };
    return $this;
  }

  public function requireParam(string $param, string $method = 'GET'): self {
    $this->checks[] = function() use ($param, $method) {
      $method = strtoupper($method);
      $data   = $method === 'POST' ? $_POST : $_GET;

      if (!array_key_exists($param, $data) || trim((string)$data[$param]) === '') {
        self::abort(400, "Parámetro requerido faltante: {$param}");
      }
    };
    return $this;
  }

  public function run(): void {
    foreach ($this->checks as $check) $check();
  }

  public static function api(): self {
    return self::create()
      ->requireAuth(null) // API: no redirect
      ->methods('POST','PUT','DELETE','PATCH')
      ->csrf();
  }
}

/**
 * COMPATIBILIDAD: no rompe el código viejo
 */
if (!function_exists('require_login')) {
  function require_login(): void {
    Middleware::create()->requireAuth()->run();
  }
}

if (!function_exists('require_permission')) {
  function require_permission(string $permission): void {
    Middleware::create()->requireAuth()->permission($permission)->run();
  }
}

if (!function_exists('require_method')) {
  function require_method(string ...$methods): void {
    Middleware::create()->methods(...$methods)->run();
  }
}

<?php
// src/BaseController.php
declare(strict_types=1);

abstract class BaseController {
  protected PDO $pdo;
  protected ?array $user = null;
  protected array $viewData = [];

  public function __construct() {
    $this->pdo = getPDO();
    $this->loadUser();
  }

  private function loadUser(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    // Si existe tu sistema viejo:
    if (function_exists('current_user')) {
      $u = current_user();
      if (is_array($u) && !empty($u['id'])) {
        $this->user = $u;
        return;
      }
    }

    // Fallback “nuevo”
    if (!empty($_SESSION['user_id'])) {
      $perms = $_SESSION['permissions'] ?? [];
      if (!is_array($perms)) $perms = [];

      $this->user = [
        'id' => (int)$_SESSION['user_id'],
        'nombre' => (string)($_SESSION['user_name'] ?? ''),
        'email' => (string)($_SESSION['user_email'] ?? ''),
        'role' => (string)($_SESSION['user_role'] ?? ''),
        'permissions' => $perms,
      ];
    }
  }

  protected function requireAuth(): void {
    Middleware::create()->requireAuth()->run();
  }

  protected function requirePermission(string $permission): void {
    Middleware::create()->requireAuth()->permission($permission)->run();
  }

  protected function redirect(string $url, int $code = 302): never {
    http_response_code($code);
    header("Location: {$url}");
    exit;
  }

  protected function render(string $view, array $data = []): void {
    $data = array_merge($this->viewData, $data, [
      'user' => $this->user,
      'flash_success' => function_exists('get_flash') ? get_flash('success') : null,
      'flash_error'   => function_exists('get_flash') ? get_flash('error') : null,
    ]);

    extract($data);

    $viewPath = __DIR__ . "/../views/{$view}.php";
    if (!file_exists($viewPath)) {
      throw new RuntimeException("Vista no encontrada: {$view} ({$viewPath})");
    }

    require $viewPath;
  }

  protected function setViewData(string $key, mixed $value): self {
    $this->viewData[$key] = $value;
    return $this;
  }
}

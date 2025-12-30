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

  // Delegamos seguridad a Middleware (una sola fuente de verdad)
  protected function requireAuth(): void {
    Middleware::create()->requireAuth()->run();
  }

  protected function requirePermission(string $permission): void {
    Middleware::create()->requireAuth()->permission($permission)->run();
  }

  protected function verifyCsrf(): void {
    Middleware::create()->csrf()->run();
  }

  protected function redirect(string $url, int $code = 302): never {
    http_response_code($code);
    header("Location: {$url}");
    exit;
  }

  protected function redirectWithSuccess(string $url, string $message): never {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['flash_success'] = $message;
    $this->redirect($url);
  }

  protected function redirectWithError(string $url, string $message): never {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['flash_error'] = $message;
    $this->redirect($url);
  }

  protected function abort(int $code, string $message = ''): never {
    http_response_code($code);
    if ($message !== '') die($message);
    exit;
  }

  // JSON (usa helpers si existen)
  protected function json(mixed $data = null, string $message = 'OK', int $code = 200): never {
    if (function_exists('json_success')) {
      json_success($data, $message, $code);
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
  }

  protected function jsonError(string $message, int $code = 400, mixed $errors = null): never {
    if (function_exists('json_error')) {
      json_error($message, $code, $errors);
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $message, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // Render de vistas: /views/{view}.php
  protected function render(string $view, array $data = []): void {
    $data = array_merge($this->viewData, $data, [
      'user' => $this->user,
      'flash_success' => function_exists('get_flash') ? get_flash('success') : null,
      'flash_error' => function_exists('get_flash') ? get_flash('error') : null,
    ]);

    extract($data, EXTR_SKIP);

    $viewPath = __DIR__ . "/../views/{$view}.php";
    if (!file_exists($viewPath)) {
      throw new RuntimeException("Vista no encontrada: {$viewPath}");
    }

    require $viewPath;
  }

  protected function setViewData(string $key, mixed $value): self {
    $this->viewData[$key] = $value;
    return $this;
  }

  // Paginación segura: recibe TOTAL ya calculado (no SQL dinámico)
  protected function paginate(int $total, int $perPage = 20, string $pageParam = 'page'): array {
    $page = max(1, (int)($_GET[$pageParam] ?? 1));
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    return [
      'total' => $total,
      'per_page' => $perPage,
      'current_page' => $page,
      'total_pages' => $totalPages,
      'offset' => $offset,
      'has_prev' => $page > 1,
      'has_next' => $page < $totalPages,
    ];
  }

  // Transacciones
  protected function transaction(callable $callback): mixed {
    if (!$this->pdo->inTransaction()) $this->pdo->beginTransaction();

    try {
      $result = $callback($this->pdo);
      if ($this->pdo->inTransaction()) $this->pdo->commit();
      return $result;
    } catch (Throwable $e) {
      if ($this->pdo->inTransaction()) $this->pdo->rollBack();
      throw $e;
    }
  }
}

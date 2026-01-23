<?php
/**
 * public/api/_csrf_guard.php
 * Guard genérico: exige CSRF para métodos que modifican estado.
 *
 * Uso:
 *   require_once __DIR__ . '/_csrf_guard.php';           // en /api/*.php
 *   require_once __DIR__ . '/../_csrf_guard.php';        // en /api/actions/*.php
 *   csrf_require(); // por defecto solo POST
 *   // o: csrf_require(['methods' => ['POST','PUT','DELETE']]);
 */
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
  }
}

if (!function_exists('csrf_verify_token')) {
  function csrf_verify_token(?string $token): bool {
    $sess = $_SESSION['csrf_token'] ?? '';
    return is_string($token) && $token !== '' && is_string($sess) && $sess !== '' && hash_equals((string)$sess, (string)$token);
  }
}

if (!function_exists('csrf_read_from_request')) {
  function csrf_read_from_request(): ?string {
    // Header preferido
    $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (is_string($hdr) && $hdr !== '') return $hdr;

    // Body x-www-form-urlencoded / multipart
    $post = $_POST['csrf_token'] ?? '';
    if (is_string($post) && $post !== '') return $post;

    // JSON body (opcional)
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos((string)$ct, 'application/json') !== false) {
      $raw = file_get_contents('php://input');
      if (is_string($raw) && $raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j) && isset($j['csrf_token']) && is_string($j['csrf_token'])) {
          return $j['csrf_token'];
        }
      }
    }
    return null;
  }
}

if (!function_exists('csrf_json_error')) {
  function csrf_json_error(string $code, int $http = 403): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($http);
    echo json_encode(['success'=>false,'error'=>$code], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
  }
}

if (!function_exists('csrf_require')) {
  /**
   * @param array{methods?: string[]} $opts
   */
  function csrf_require(array $opts = []): void {
    $methods = $opts['methods'] ?? ['POST']; // por defecto solo POST
    $method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $method  = is_string($method) ? strtoupper($method) : 'GET';

    if (!in_array($method, $methods, true)) {
      csrf_json_error('METHOD_NOT_ALLOWED', 405);
    }

    $tok = csrf_read_from_request();
    if (!csrf_verify_token($tok)) {
      csrf_json_error('CSRF_INVALID', 403);
    }
  }
}

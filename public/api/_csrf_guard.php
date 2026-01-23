<?php
// public/api/_csrf_guard.php
declare(strict_types=1);

// Helpers CSRF centralizados (src/helpers.php) a través del wrapper público.
require_once __DIR__ . '/../lib/csrf.php';

if (!function_exists('flus_get_header')) {
  function flus_get_header(string $name): string {
    $name = strtoupper(str_replace('-', '_', $name));

    // Preferir getallheaders si existe
    if (function_exists('getallheaders')) {
      $hs = getallheaders();
      if (is_array($hs)) {
        foreach ($hs as $k => $v) {
          if (strtoupper(str_replace('-', '_', (string)$k)) === $name) {
            return is_array($v) ? (string)($v[0] ?? '') : (string)$v;
          }
        }
      }
    }

    // Fallback SERVER
    $serverKey = 'HTTP_' . $name;
    if (isset($_SERVER[$serverKey])) return (string)$_SERVER[$serverKey];

    return '';
  }
}

if (!function_exists('flus_wants_json')) {
  function flus_wants_json(): bool {
    if (defined('FLUS_API_CONTEXT') && FLUS_API_CONTEXT) return true;
    $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    if (stripos($accept, 'application/json') !== false) return true;

    $xhr = (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    if (strcasecmp($xhr, 'XMLHttpRequest') === 0) return true;

    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    return (stripos($uri, '/api/') !== false);
  }
}

if (!function_exists('csrf_request_token')) {
  /**
   * Obtiene token CSRF SIN consumir php://input (para no romper endpoints que luego leen JSON).
   * Fuente (en orden): headers -> POST
   */
  function csrf_request_token(): string {
    $h = flus_get_header('X-CSRF-Token');
    if ($h !== '') return trim($h);

    $h = flus_get_header('X-CSRF-TOKEN');
    if ($h !== '') return trim($h);

    $h = flus_get_header('X-XSRF-TOKEN');
    if ($h !== '') return trim($h);

    $p = (string)($_POST['csrf_token'] ?? $_POST['csrf'] ?? '');
    return trim($p);
  }
}

if (!function_exists('csrf_fail_response')) {
  function csrf_fail_response(string $msg = 'Token CSRF inválido', int $code = 403): never {
    if (ob_get_length()) @ob_clean();
    http_response_code($code);

    if (flus_wants_json()) {
      if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
      }
      echo json_encode([
        'ok' => false,
        'error' => 'CSRF_INVALID',
        'message' => $msg,
      ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
      exit;
    }

    echo $msg;
    exit;
  }
}

if (!function_exists('csrf_require')) {
  /**
   * Obliga CSRF para métodos no seguros.
   * Opciones:
   *  - methods: ['POST','PUT','PATCH','DELETE']
   *  - regenerate: bool (rota token cuando fue válido)
   */
  function csrf_require(array $opts = []): void {
    $methods = $opts['methods'] ?? ['POST', 'PUT', 'PATCH', 'DELETE'];
    if (!is_array($methods) || !$methods) $methods = ['POST', 'PUT', 'PATCH', 'DELETE'];

    $m = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($m, $methods, true)) return;

    $token = csrf_request_token();

    // Si no vino en header/POST, NO consumimos php://input.
    // Muchos endpoints envían token dentro del JSON y lo validan más abajo.
    // En ese caso, dejamos pasar este guard.
    if ($token === '') return;

    $reg = !empty($opts['regenerate']);
    if (!csrf_verify($token, $reg)) {
      csrf_fail_response('Token CSRF inválido', 403);
    }
  }
}

<?php
declare(strict_types=1);

if (!function_exists('flus_is_api_request')) {
  function flus_is_api_request(): bool {
    if (defined('FLUS_API_CONTEXT') && FLUS_API_CONTEXT) return true;

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if ($uri && str_contains($uri, '/api/')) return true;

    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if ($accept && str_contains($accept, 'application/json')) return true;

    $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    if ($xhr && strtolower($xhr) === 'xmlhttprequest') return true;

    return false;
  }
}

if (!function_exists('flus_abort')) {
  function flus_abort(int $code, string $message, array $extra = []): void {
    http_response_code($code);

    if (flus_is_api_request()) {
      if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
      }
      if (ob_get_length()) { @ob_clean(); }

      echo json_encode(['ok' => false, 'error' => $message] + $extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
      exit;
    }

    if (!headers_sent()) {
      header('Content-Type: text/html; charset=utf-8');
      header('Cache-Control: no-store');
    }
    $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo "<!doctype html><html lang='es'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'>";
    echo "<title>FLUS - Error {$code}</title>";
    echo "<style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:32px;max-width:760px}.card{border:1px solid #ddd;border-radius:12px;padding:18px}</style>";
    echo "</head><body><h1>Error {$code}</h1><div class='card'><p>{$safe}</p></div></body></html>";
    exit;
  }
}

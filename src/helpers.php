<?php
// src/helpers.php
declare(strict_types=1);

/**
 * HELPERS CENTRALIZADOS (V1-safe)
 * - No rompe funciones existentes (usa function_exists)
 * - Corrige validate_numeric (PHP_FLOAT_MIN -> -INF)
 * - Agrega db_ident para evitar inyección en identificadores
 */

// =============================================================================
// HTML & SEGURIDAD
// =============================================================================

if (!function_exists('h')) {
  function h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('clean_string')) {
  function clean_string(mixed $value): string {
    return trim((string)$value);
  }
}

// =============================================================================
// FORMATO DE MONEDA
// =============================================================================

if (!function_exists('money_ar')) {
  function money_ar(float $amount, bool $symbol = true): string {
    $formatted = number_format($amount, 2, ',', '.');
    return $symbol ? '$ ' . $formatted : $formatted;
  }
}

if (!function_exists('parse_money_ar')) {
  function parse_money_ar(string $money): float {
    $clean = preg_replace('/[^\d,.-]/', '', $money);
    $clean = str_replace(['.', ','], ['', '.'], $clean);
    return (float)$clean;
  }
}

// =============================================================================
// FORMATO DE CANTIDADES
// =============================================================================

if (!function_exists('is_pesable_row')) {
  function is_pesable_row(array $row): bool {
    return isset($row['es_pesable']) && (int)$row['es_pesable'] === 1;
  }
}

if (!function_exists('format_qty')) {
  function format_qty(float $qty, bool $isPesable = false): string {
    $decimals = $isPesable ? 3 : 0;
    return number_format($qty, $decimals, ',', '.');
  }
}

if (!function_exists('format_qty_field')) {
  function format_qty_field(array $row, string $field): string {
    $value = (float)($row[$field] ?? 0);
    return format_qty($value, is_pesable_row($row));
  }
}

// =============================================================================
// FECHAS
// =============================================================================

if (!function_exists('validDateYmd')) {
  function validDateYmd(?string $date): ?string {
    if (!$date || trim($date) === '') return null;

    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) return null;

    return $date;
  }
}

if (!function_exists('format_datetime')) {
  function format_datetime(?string $datetime, string $format = 'd/m/Y H:i'): string {
    if (!$datetime) return '-';
    try {
      return (new DateTime($datetime))->format($format);
    } catch (Throwable) {
      return '-';
    }
  }
}

if (!function_exists('format_date')) {
  function format_date(?string $date, string $format = 'd/m/Y'): string {
    return format_datetime($date, $format);
  }
}

// =============================================================================
// URL & QUERY STRINGS
// =============================================================================

if (!function_exists('urlWith')) {
  function urlWith(array $new, string $base = ''): string {
    if ($base === '') {
      $base = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
    }

    parse_str($_SERVER['QUERY_STRING'] ?? '', $current);
    $merged = array_merge($current, $new);
    $merged = array_filter($merged, fn($v) => $v !== null && $v !== '');

    return $base . ($merged ? '?' . http_build_query($merged) : '');
  }
}

// =============================================================================
// CSRF
// =============================================================================

if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    if (empty($_SESSION['csrf_token'])) {
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
  }
}

if (!function_exists('csrf_verify')) {
  function csrf_verify(?string $token): bool {
    if (!$token) return false;
    return hash_equals(csrf_token(), $token);
  }
}

if (!function_exists('csrf_field')) {
  function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
  }
}

// =============================================================================
// FLASH (mensajes)
// =============================================================================

if (!function_exists('get_flash')) {
  function get_flash(string $key): ?string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    $value = $_SESSION["flash_{$key}"] ?? null;
    unset($_SESSION["flash_{$key}"]);

    return $value;
  }
}

if (!function_exists('redirect_with_success')) {
  function redirect_with_success(string $url, string $message): never {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['flash_success'] = $message;
    header("Location: {$url}");
    exit;
  }
}

if (!function_exists('redirect_with_error')) {
  function redirect_with_error(string $url, string $message): never {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['flash_error'] = $message;
    header("Location: {$url}");
    exit;
  }
}

// =============================================================================
// VALIDACIONES
// =============================================================================

if (!function_exists('validate_required')) {
  function validate_required(array $data, array $fields): array {
    $errors = [];
    foreach ($fields as $field) {
      $value = $data[$field] ?? null;
      if ($value === null || trim((string)$value) === '') {
        $errors[] = "El campo '{$field}' es requerido.";
      }
    }
    return $errors;
  }
}

if (!function_exists('validate_email')) {
  function validate_email(?string $email): bool {
    return $email ? (bool)filter_var($email, FILTER_VALIDATE_EMAIL) : false;
  }
}

if (!function_exists('validate_numeric')) {
  function validate_numeric(mixed $value, float $min = -INF, float $max = INF): bool {
    if (!is_numeric($value)) return false;
    $num = (float)$value;
    return $num >= $min && $num <= $max;
  }
}

// =============================================================================
// DB HELPERS (cuidado con WHERE dinámico)
// =============================================================================

if (!function_exists('db_ident')) {
  function db_ident(string $name): string {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
      throw new InvalidArgumentException("Identificador SQL inválido");
    }
    return $name;
  }
}

if (!function_exists('db_exists')) {
  function db_exists(PDO $pdo, string $table, string $column, mixed $value): bool {
    $t = db_ident($table);
    $c = db_ident($column);
    $stmt = $pdo->prepare("SELECT 1 FROM `$t` WHERE `$c` = ? LIMIT 1");
    $stmt->execute([$value]);
    return (bool)$stmt->fetchColumn();
  }
}

// =============================================================================
// JSON helpers (no rompe: incluye ok y success)
// =============================================================================

if (!function_exists('json_success')) {
  function json_success(mixed $data = null, string $message = 'OK', int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
      'ok' => true,
      'success' => true,
      'message' => $message,
      'data' => $data,
      'timestamp' => date('c'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
  }
}

if (!function_exists('json_error')) {
  function json_error(string $message, int $code = 400, mixed $errors = null): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');

    $response = [
      'ok' => false,
      'success' => false,
      'error' => $message,
      'timestamp' => date('c'),
    ];
    if ($errors !== null) $response['errors'] = $errors;

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
  }
}

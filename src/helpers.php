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
  /**
   * Formato AR para moneda.
   * Acepta string/float/int (para no romper pantallas viejas).
   */
  function money_ar($n, bool $symbol = true): string {
    $formatted = number_format((float)$n, 2, ',', '.');
    return $symbol ? '$' . $formatted : $formatted;
  }
}

if (!function_exists('parse_money_ar')) {
  /**
   * Convierte "$ 1.234,56" / "1.234,56" / "1234.56" a float.
   * - Tolera null/int/float/string
   * - Valida que '-' sólo esté al principio
   */
  function parse_money_ar($v): float {
    if ($v === null) return 0.0;
    if (is_int($v) || is_float($v)) return (float)$v;

    $s = trim((string)$v);
    if ($s === '') return 0.0;

    // Limpia caracteres no numéricos
    $s = preg_replace('/[^0-9,\.\-]/', '', $s) ?? '';
    if ($s === '' || $s === '-' || $s === '.' || $s === ',') return 0.0;

    // Validar que el signo - solo esté al principio
    $minusCount = substr_count($s, '-');
    if ($minusCount > 1 || ($minusCount === 1 && strpos($s, '-') !== 0)) {
      return 0.0;
    }

    // Si tiene coma, asumimos formato AR 1.234,56
    if (strpos($s, ',') !== false) {
      $s = str_replace('.', '', $s);
      $s = str_replace(',', '.', $s);
    }

    return is_numeric($s) ? (float)$s : 0.0;
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
  /**
   * Formatea cantidad (unidad vs pesable).
   * - Compat: acepta 3er parámetro $decPesable.
   */
  function format_qty($qty, bool $isPesable = false, int $decPesable = 3): string {
    $decimals = $isPesable ? $decPesable : 0;
    return number_format((float)$qty, $decimals, ',', '.');
  }
}

if (!function_exists('format_qty_field')) {
  function format_qty_field(array $row, string $field, int $decPesable = 3): string {
    $value = (float)($row[$field] ?? 0);
    return format_qty($value, is_pesable_row($row), $decPesable);
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
// BASE URL / PATH (instalación en subcarpeta)
// =============================================================================

if (!function_exists('flus_public_base_path')) {
  /**
   * Devuelve el path base del "public" en URL.
   * Ejemplos:
   * - DocRoot = /public            => ''
   * - URL = /kiosco/public/...     => '/kiosco/public'
   * - URL = /kiosco/public/api/... => '/kiosco/public'
   *
   * Se puede sobreescribir definiendo FLUS_PUBLIC_BASE (string) en config.
   */
  function flus_public_base_path(): string {
    static $cached = null;
    if ($cached !== null) return $cached;

    // Override explícito
    if (defined('FLUS_PUBLIC_BASE') && is_string(FLUS_PUBLIC_BASE)) {
      $cached = rtrim((string)FLUS_PUBLIC_BASE, '/');
      if ($cached === '/') $cached = '';
      return $cached;
    }

    $sn = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($sn === '') { $cached = ''; return $cached; }

    $dir = str_replace('\\', '/', dirname($sn));
    if ($dir === '.') $dir = '/';

    // Si estamos en /api o /api/actions => subir a raíz pública
    $base = $dir;
    $base = preg_replace('#/api(?:/.*)?$#', '', $base) ?? $base;

    if ($base === '' || $base === '/') {
      $cached = '';
      return $cached;
    }

    $cached = rtrim($base, '/');
    return $cached;
  }
}

if (!function_exists('flus_url')) {
  /**
   * Construye una URL absoluta (path) hacia un recurso dentro de public.
   * - flus_url('caja.php') => '/caja.php' o '/kiosco/public/caja.php'
   */
  function flus_url(string $path = ''): string {
    $base = flus_public_base_path();
    $path = ltrim($path, '/');

    if ($base === '') {
      return $path === '' ? '/' : ('/' . $path);
    }

    return $path === '' ? $base : ($base . '/' . $path);
  }
}

// =============================================================================
// CSRF
// =============================================================================

if (!function_exists('csrf_init')) {
  function csrf_init(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    // Token en sesión (primario)
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    // ✅ Robustez: además, “double submit cookie” para evitar des-sync de sesión
    // (Ej: instalaciones/paths raros donde API y páginas terminan con sesiones distintas).
    // - Cookie HttpOnly (no la necesita leer JS; el token se lee del <meta>)
    // - Solo la seteamos en contexto HTML para no ensuciar respuestas de API.
    if (!defined('FLUS_CSRF_COOKIE')) {
      define('FLUS_CSRF_COOKIE', 'FLUS_CSRF');
    }

    if (!defined('FLUS_API_CONTEXT') && !headers_sent()) {
      $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);

      @setcookie(FLUS_CSRF_COOKIE, (string)$_SESSION['csrf_token'], [
        'expires'  => 0,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
      ]);
    }
  }
}

if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    csrf_init();
    return (string)($_SESSION['csrf_token'] ?? '');
  }
}

if (!function_exists('csrf_verify')) {
  /**
   * Verifica CSRF contra el token de sesión.
   * Si $regenerate=true, rota el token si fue válido (one-time token).
   */
  function csrf_verify(?string $token, bool $regenerate = false): bool {
    csrf_init();
    if (!$token) return false;

    $token = (string)$token;

    // 1) sesión (normal)
    $sess = (string)($_SESSION['csrf_token'] ?? '');
    $valid = ($sess !== '') && hash_equals($sess, $token);

    // 2) fallback cookie (double submit)
    if (!$valid) {
      $cookieName = defined('FLUS_CSRF_COOKIE') ? (string)FLUS_CSRF_COOKIE : 'FLUS_CSRF';
      $c = (string)($_COOKIE[$cookieName] ?? '');
      if ($c !== '') {
        $valid = hash_equals($c, $token);
      }
    }

    if ($valid && $regenerate) {
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $valid;
  }
}

// Compat API vieja: csrf_check()
if (!function_exists('csrf_check')) {
  function csrf_check(?string $token, bool $regenerate = false): bool {
    return csrf_verify($token, $regenerate);
  }
}

if (!function_exists('csrf_field')) {
  function csrf_field(string $name = 'csrf_token'): string {
    $t = h(csrf_token());
    $n = h($name);
    return '<input type="hidden" name="' . $n . '" value="' . $t . '">';
  }
}

// Compat: csrf_input()
if (!function_exists('csrf_input')) {
  function csrf_input(string $name = 'csrf_token'): string {
    return csrf_field($name);
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
// COMPAT / LEGACY (helpers usados por pantallas viejas)
// =============================================================================

if (!function_exists('sanitize_int')) {
  /** Sanitiza y valida un entero de forma segura. */
  function sanitize_int($value, int $default = 0): int {
    if ($value === null || $value === '') return $default;
    $clean = filter_var($value, FILTER_VALIDATE_INT);
    return ($clean !== false) ? (int)$clean : $default;
  }
}

if (!function_exists('sanitize_float')) {
  /** Sanitiza y valida un float de forma segura. */
  function sanitize_float($value, float $default = 0.0): float {
    if ($value === null || $value === '') return $default;
    $clean = filter_var($value, FILTER_VALIDATE_FLOAT);
    return ($clean !== false) ? (float)$clean : $default;
  }
}

if (!function_exists('is_post_request')) {
  function is_post_request(): bool {
    return (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
  }
}

if (!function_exists('redirect')) {
  /** Redirección con exit automático. */
  function redirect(string $url, int $code = 302): never {
    header("Location: {$url}", true, $code);
    exit;
  }
}

if (!function_exists('parse_decimal')) {
  /**
   * Convierte "1.234,56" / "1234.56" / "$ 1.234,56" a float (o devuelve $default).
   * - Acepta formato AR con coma como decimal.
   * - Limpia caracteres no numéricos (mantiene - , .)
   */
  function parse_decimal(?string $s, ?float $default = null): ?float {
    if ($s === null) return $default;

    $s = trim($s);
    if ($s === '') return $default;

    $s = str_replace(' ', '', $s);

    $s = preg_replace('/[^0-9,\.\-]/', '', $s) ?? '';
    if ($s === '' || $s === '-' || $s === '.' || $s === ',') return $default;

    // Signo "-" solo al inicio
    if (substr_count($s, '-') > 1 || (strpos($s, '-') !== false && strpos($s, '-') > 0)) {
      return $default;
    }

    // Si trae coma, asumimos formato AR: 1.234,56
    if (strpos($s, ',') !== false) {
      $s = str_replace('.', '', $s);
      $s = str_replace(',', '.', $s);
    }

    return is_numeric($s) ? (float)$s : $default;
  }
}

if (!function_exists('format_datetime_ar')) {
  /** Formatea "Y-m-d H:i:s" a "d/m/Y H:i" (AR). Si es inválido → "—". */
  function format_datetime_ar(?string $dt): string {
    if (!$dt || $dt === '0000-00-00 00:00:00' || $dt === '') return '—';
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $dt);
    return $d ? $d->format('d/m/Y H:i') : $dt;
  }
}

if (!function_exists('format_qty_ar')) {
  function format_qty_ar(float $valor, bool $pesable, int $decPesable = 3): string {
    $dec = $pesable ? $decPesable : 0;
    return number_format($valor, $dec, ',', '.');
  }
}

if (!function_exists('format_cantidad')) {
  /** Alias usado en algunas pantallas viejas (stock). */
  function format_cantidad(array $row, string $field, int $decPesable = 3): string {
    $valor = isset($row[$field]) ? (float)$row[$field] : 0.0;
    return format_qty_ar($valor, is_pesable_row($row), $decPesable);
  }
}

if (!function_exists('url_with')) {
  /** Alias snake_case de urlWith() */
  function url_with(array $overrides = [], ?string $base = null): string {
    return urlWith($overrides, $base ?? '');
  }
}

/* ----------------------------
   NUM helpers
---------------------------- */
if (!function_exists('num_round2')) {
  function num_round2(float $n): float {
    return round($n, 2);
  }
}

if (!function_exists('num_clamp0')) {
  function num_clamp0(float $n): float {
    return ($n < 0) ? 0.0 : $n;
  }
}

if (!function_exists('num_clamp')) {
  function num_clamp(float $n, float $min, float $max): float {
    return max($min, min($max, $n));
  }
}

if (!function_exists('num_is_int_like')) {
  function num_is_int_like(float $n, float $eps = 0.00001): bool {
    return abs($n - floor($n)) < $eps;
  }
}

/* ----------------------------
   Config en DB + cache
---------------------------- */
if (!isset($GLOBALS['__app_config_cache']) || !is_array($GLOBALS['__app_config_cache'])) {
  $GLOBALS['__app_config_cache'] = [];
}

if (!function_exists('config_get')) {
  function config_get(PDO $pdo, string $k, ?string $default = null): ?string {
    $k = trim($k);
    if ($k === '') return $default;

    $cache =& $GLOBALS['__app_config_cache'];
    if (array_key_exists($k, $cache)) {
      return $cache[$k];
    }

    try {
      $st = $pdo->prepare("SELECT valor FROM app_config WHERE clave = ? LIMIT 1");
      $st->execute([$k]);
      $val = $st->fetchColumn();
      $val = ($val === false) ? $default : (string)$val;
      $cache[$k] = $val;
      return $val;
    } catch (Throwable $e) {
      // en caso de error, no romper: devolver default
      return $default;
    }
  }
}

if (!function_exists('config_set')) {
  function config_set(PDO $pdo, string $k, string $v): bool {
    $k = trim($k);
    if ($k === '') return false;

    try {
      $st = $pdo->prepare("
        INSERT INTO app_config (clave, valor)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE valor = VALUES(valor)
      ");
      $ok = $st->execute([$k, $v]);

      if ($ok) {
        $GLOBALS['__app_config_cache'][$k] = $v;
      }

      return (bool)$ok;
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('config_clear_cache')) {
  function config_clear_cache(): void {
    $GLOBALS['__app_config_cache'] = [];
  }
}

/* ----------------------------
   Alias compat
---------------------------- */
if (!function_exists('money')) {
  function money($n): string {
    return money_ar($n);
  }
}

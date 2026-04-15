<?php
// src/helpers.php
declare(strict_types=1);

/**
 * HELPERS CENTRALIZADOS (V1-safe)
 * - No rompe funciones existentes (usa function_exists)
 * - Corrige validate_numeric (PHP_FLOAT_MIN -> -INF)
 * - Agrega db_ident para evitar inyección en identificadores
 */
if (!function_exists('array_is_list')) {
    function array_is_list(array $array): bool
    {
        $expectedKey = 0;
        foreach ($array as $key => $_) {
            if ($key !== $expectedKey++) {
                return false;
            }
        }
        return true;
    }
}
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

    // Si tiene coma, asumimos formato AR 1.234,56.
    if (strpos($s, ',') !== false) {
      $s = str_replace('.', '', $s);
      $s = str_replace(',', '.', $s);
    } elseif (preg_match('/^-?\d{1,3}(?:\.\d{3})+$/', $s)) {
      // Si solo hay puntos y calza como miles AR, 40.000 es 40000.
      $s = str_replace('.', '', $s);
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


if (!function_exists('flus_normalize_breadcrumbs')) {
  /**
   * Normaliza breadcrumbs declarados como $breadcrumb o $breadcrumbs.
   *
   * @param mixed $items
   * @return array<int, array{label:string,url:?string}>
   */
  function flus_normalize_breadcrumbs($items): array {
    if (!is_array($items)) {
      return [];
    }

    $normalized = [];
    foreach ($items as $item) {
      if (!is_array($item)) {
        continue;
      }

      $label = trim((string)($item['label'] ?? ''));
      if ($label === '') {
        continue;
      }

      $url = $item['url'] ?? null;
      if ($url !== null) {
        $url = trim((string)$url);
        if ($url === '') {
          $url = null;
        }
      }

      $normalized[] = [
        'label' => $label,
        'url' => $url,
      ];
    }

    return $normalized;
  }
}

if (!function_exists('render_pagination')) {
  /**
   * Paginacion numerica compartida para listados.
   *
   * @param array<int,string> $dropParams
   */
  function render_pagination(
    int $page,
    int $totalPages,
    array $params,
    bool $showInfo = true,
    int $total = 0,
    int $from = 0,
    int $to = 0,
    array $dropParams = []
  ): string {
    if ($totalPages <= 1) {
      return '';
    }

    unset($params['page']);
    foreach ($dropParams as $key) {
      unset($params[(string)$key]);
    }

    $html = '<div class="pagination">';

    if ($showInfo && $total > 0) {
      $html .= '<span class="pagination-info">' . number_format($from) . '-' . number_format($to) . ' de ' . number_format($total) . '</span>';
    }

    $html .= '<div class="pagination-btns">';

    if ($page > 1) {
      $params['page'] = $page - 1;
      $html .= '<a href="?' . http_build_query($params) . '" class="pg-btn">&lsaquo;</a>';
    } else {
      $html .= '<span class="pg-btn disabled">&lsaquo;</span>';
    }

    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);

    if ($start > 1) {
      $params['page'] = 1;
      $html .= '<a href="?' . http_build_query($params) . '" class="pg-btn">1</a>';
      if ($start > 2) {
        $html .= '<span class="pg-ellipsis">...</span>';
      }
    }

    for ($i = $start; $i <= $end; $i++) {
      $params['page'] = $i;
      $html .= ($i === $page)
        ? '<span class="pg-btn active">' . $i . '</span>'
        : '<a href="?' . http_build_query($params) . '" class="pg-btn">' . $i . '</a>';
    }

    if ($end < $totalPages) {
      if ($end < $totalPages - 1) {
        $html .= '<span class="pg-ellipsis">...</span>';
      }
      $params['page'] = $totalPages;
      $html .= '<a href="?' . http_build_query($params) . '" class="pg-btn">' . $totalPages . '</a>';
    }

    if ($page < $totalPages) {
      $params['page'] = $page + 1;
      $html .= '<a href="?' . http_build_query($params) . '" class="pg-btn">&rsaquo;</a>';
    } else {
      $html .= '<span class="pg-btn disabled">&rsaquo;</span>';
    }

    $html .= '</div></div>';

    return $html;
  }
}

if (!function_exists('flus_export_limit')) {
  function flus_export_limit(): int {
    return 10000;
  }
}

if (!function_exists('flus_stock_tipos_ajuste')) {
  /**
   * @return array<string,array<string,int|string>>
   */
  function flus_stock_tipos_ajuste(): array {
    return [
      'entrada' => ['label' => 'Entrada', 'mov' => 'AJUSTE_POSITIVO', 'signo' => +1],
      'salida' => ['label' => 'Salida', 'mov' => 'AJUSTE_NEGATIVO', 'signo' => -1, 'motivo_default' => 'Salida manual'],
      'ajuste_pos' => ['label' => 'Ajuste (+)', 'mov' => 'AJUSTE_POSITIVO', 'signo' => +1],
      'ajuste_neg' => ['label' => 'Ajuste (-)', 'mov' => 'AJUSTE_NEGATIVO', 'signo' => -1],
      'perdida' => ['label' => 'Perdida', 'mov' => 'AJUSTE_NEGATIVO', 'signo' => -1, 'motivo_default' => 'Perdida/Rotura/Vencimiento'],
    ];
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

if (!function_exists('format_stock_con_unidad')) {
  /**
   * Formatea stock/cantidad con una unidad legible para operacion.
   * Para productos vendidos por 100g/100ml, muestra el total real acumulado.
   */
  function format_stock_con_unidad(array $row, string $field = 'stock', int $decPesable = 3): string {
    $valor = isset($row[$field]) ? (float)$row[$field] : 0.0;
    $esPesable = is_pesable_row($row);
    $unidad = strtoupper(trim($row['unidad_venta'] ?? 'UNIDAD'));

    if (!$esPesable || $unidad === 'UNIDAD') {
      return format_qty_ar($valor, false, $decPesable);
    }

    if ($unidad === 'KG') {
      return format_qty_ar($valor, true, $decPesable) . ' kg';
    }

    if ($unidad === 'LT') {
      return format_qty_ar($valor, true, $decPesable) . ' L';
    }

    if ($unidad === 'G') {
      $gramos = $valor * 100;
      if ($gramos >= 1000) {
        return format_qty_ar($gramos / 1000, true, $decPesable) . ' kg';
      }
      return number_format($gramos, 0, ',', '.') . ' g';
    }

    if ($unidad === 'ML') {
      $mililitros = $valor * 100;
      if ($mililitros >= 1000) {
        return format_qty_ar($mililitros / 1000, true, $decPesable) . ' L';
      }
      return number_format($mililitros, 0, ',', '.') . ' ml';
    }

    return format_qty_ar($valor, true, $decPesable) . ' ' . strtolower($unidad);
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
      $st = $pdo->prepare("SELECT v FROM app_config WHERE k = ? LIMIT 1");
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
        INSERT INTO app_config (k, v)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE v = VALUES(v)
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

if (!function_exists('flus_admin_role_slugs')) {
  function flus_admin_role_slugs(): array {
    return ['administrador', 'admin', 'superadmin'];
  }
}

if (!function_exists('flus_is_protected_admin_role')) {
  function flus_is_protected_admin_role(string $slug): bool {
    return in_array(strtolower(trim($slug)), flus_admin_role_slugs(), true);
  }
}

if (!function_exists('flus_active_admin_users_count')) {
  function flus_active_admin_users_count(PDO $pdo): int {
    $slugs = flus_admin_role_slugs();
    if ($slugs === []) return 0;

    $ph = implode(',', array_fill(0, count($slugs), '?'));
    $sql = "
      SELECT COUNT(*)
      FROM users u
      JOIN roles r ON r.id = u.role_id
      WHERE u.activo = 1
        AND LOWER(r.slug) IN ($ph)
    ";

    try {
      $st = $pdo->prepare($sql);
      $st->execute($slugs);
      return (int)$st->fetchColumn();
    } catch (Throwable $e) {
      return 0;
    }
  }
}

if (!function_exists('flus_guard_last_admin_user_change')) {
  function flus_guard_last_admin_user_change(PDO $pdo, int $userId, ?int $nextActivo = null, bool $deleting = false, ?int $nextRoleId = null): ?string {
    if ($userId <= 0) return null;

    try {
      $st = $pdo->prepare("
        SELECT u.id, u.activo, u.role_id, r.slug
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = ?
        LIMIT 1
      ");
      $st->execute([$userId]);
      $user = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
      return null;
    }

    if (!$user) return null;

    $currentRoleSlug = (string)($user['slug'] ?? '');
    if (!flus_is_protected_admin_role($currentRoleSlug)) return null;

    $currentActivo = (int)($user['activo'] ?? 0) === 1;
    if (!$currentActivo) return null;

    $willRemainActive = $deleting ? false : ($nextActivo === null ? $currentActivo : ((int)$nextActivo === 1));
    $nextRoleSlug = $currentRoleSlug;

    if ($nextRoleId !== null && $nextRoleId > 0 && (int)($user['role_id'] ?? 0) !== $nextRoleId) {
      try {
        $stRole = $pdo->prepare('SELECT slug FROM roles WHERE id = ? LIMIT 1');
        $stRole->execute([$nextRoleId]);
        $nextRoleSlug = (string)($stRole->fetchColumn() ?? '');
      } catch (Throwable $e) {
        $nextRoleSlug = '';
      }
    }

    $willRemainAdmin = $willRemainActive && flus_is_protected_admin_role($nextRoleSlug);
    if ($willRemainAdmin) return null;

    if (flus_active_admin_users_count($pdo) <= 1) {
      return 'No se puede quitar el ultimo administrador activo del sistema.';
    }

    return null;
  }
}

if (!function_exists('flus_is_critical_role')) {
  function flus_is_critical_role(string $slug): bool {
    return flus_is_protected_admin_role($slug);
  }
}

if (!function_exists('flus_text_length')) {
  function flus_text_length(string $value): int {
    return function_exists('mb_strlen') ? (int) mb_strlen($value, 'UTF-8') : (int) strlen($value);
  }
}

if (!function_exists('flus_role_exists')) {
  function flus_role_exists(PDO $pdo, int $roleId): bool {
    if ($roleId <= 0) return false;
    try {
      $st = $pdo->prepare('SELECT 1 FROM roles WHERE id = ? LIMIT 1');
      $st->execute([$roleId]);
      return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('flus_user_email_exists')) {
  function flus_user_email_exists(PDO $pdo, string $email, ?int $excludeUserId = null): bool {
    if ($email === '') return false;

    try {
      if ($excludeUserId !== null && $excludeUserId > 0) {
        $st = $pdo->prepare('SELECT 1 FROM users WHERE email = ? AND id != ? LIMIT 1');
        $st->execute([$email, $excludeUserId]);
      } else {
        $st = $pdo->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $st->execute([$email]);
      }
      return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('flus_user_username_exists')) {
  function flus_user_username_exists(PDO $pdo, string $username, ?int $excludeUserId = null): bool {
    if ($username === '') return false;

    try {
      if ($excludeUserId !== null && $excludeUserId > 0) {
        $st = $pdo->prepare('SELECT 1 FROM users WHERE username = ? AND id != ? LIMIT 1');
        $st->execute([$username, $excludeUserId]);
      } else {
        $st = $pdo->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
        $st->execute([$username]);
      }
      return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('flus_is_reserved_admin_user')) {
  function flus_is_reserved_admin_user(array $user): bool {
    $userId = (int)($user['id'] ?? 0);
    $username = strtolower(trim((string)($user['username'] ?? '')));
    $email = strtolower(trim((string)($user['email'] ?? '')));

    if ($userId === 1) return true;
    if ($username === 'admin' && $email === 'admin@flus.local') return true;

    return false;
  }
}

if (!function_exists('flus_get_user_identity')) {
  function flus_get_user_identity(PDO $pdo, int $userId): ?array {
    if ($userId <= 0) return null;

    try {
      $st = $pdo->prepare('SELECT id, username, email, role_id, activo FROM users WHERE id = ? LIMIT 1');
      $st->execute([$userId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      return is_array($row) ? $row : null;
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('flus_guard_reserved_admin_user_mutation')) {
  function flus_guard_reserved_admin_user_mutation(PDO $pdo, int $targetUserId, ?int $nextActivo = null, bool $deleting = false, ?int $nextRoleId = null, ?string $nextUsername = null): ?string {
    $user = flus_get_user_identity($pdo, $targetUserId);
    if (!$user || !flus_is_reserved_admin_user($user)) {
      return null;
    }

    if ($deleting) {
      return 'La cuenta admin de resguardo no se puede eliminar.';
    }

    if ($nextActivo !== null && (int)$nextActivo !== (int)($user['activo'] ?? 0)) {
      return 'La cuenta admin de resguardo no permite cambiar su estado.';
    }

    if ($nextRoleId !== null && (int)$nextRoleId !== (int)($user['role_id'] ?? 0)) {
      return 'La cuenta admin de resguardo mantiene su rol original.';
    }

    if ($nextUsername !== null && trim($nextUsername) !== '' && strcasecmp(trim($nextUsername), (string)($user['username'] ?? '')) !== 0) {
      return 'La cuenta admin de resguardo mantiene su usuario de acceso.';
    }

    return null;
  }
}

if (!function_exists('flus_reserved_admin_role_id')) {
  function flus_reserved_admin_role_id(PDO $pdo): int {
    try {
      $st = $pdo->query("SELECT id, username, email, role_id FROM users ORDER BY id ASC");
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $user) {
        if (flus_is_reserved_admin_user($user)) {
          return (int)($user['role_id'] ?? 0);
        }
      }
    } catch (Throwable $e) {
      return 0;
    }

    return 0;
  }
}

if (!function_exists('flus_is_reserved_admin_role_id')) {
  function flus_is_reserved_admin_role_id(PDO $pdo, int $roleId): bool {
    return $roleId > 0 && $roleId === flus_reserved_admin_role_id($pdo);
  }
}

if (!function_exists('flus_guard_reserved_admin_role_mutation')) {
  function flus_guard_reserved_admin_role_mutation(PDO $pdo, int $roleId): ?string {
    if ($roleId <= 0) return null;
    return flus_is_reserved_admin_role_id($pdo, $roleId)
      ? 'El rol base de la cuenta admin de resguardo no se puede editar desde Roles y Permisos.'
      : null;
  }
}

if (!function_exists('flus_validate_user_payload')) {
  function flus_validate_user_payload(PDO $pdo, array $input, array $options = []): array {
    $existingUserId = isset($options['existing_user_id']) ? (int) $options['existing_user_id'] : 0;
    $existingUserId = $existingUserId > 0 ? $existingUserId : null;
    $requireEmail = array_key_exists('require_email', $options) ? (bool) $options['require_email'] : true;
    $requirePassword = !empty($options['require_password']);
    $defaultActivo = array_key_exists('default_activo', $options) ? (int) $options['default_activo'] : 1;

    $nombre = trim((string) ($input['nombre'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $username = trim((string) ($input['username'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $roleId = (int) ($input['role_id'] ?? 0);
    $activoRaw = $input['activo'] ?? $defaultActivo;
    $activo = in_array($activoRaw, [1, '1', true, 'true', 'on', 'yes'], true) ? 1 : 0;

    $errors = [];

    if ($nombre === '') {
      $errors[] = 'El nombre es obligatorio';
    } elseif (flus_text_length($nombre) < 3) {
      $errors[] = 'El nombre debe tener al menos 3 caracteres';
    }

    if ($requireEmail && $email === '') {
      $errors[] = 'El email es obligatorio';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $errors[] = 'El email no es valido';
    } elseif ($email !== '' && flus_user_email_exists($pdo, $email, $existingUserId)) {
      $errors[] = $existingUserId ? 'Este email ya esta registrado por otro usuario' : 'Este email ya esta registrado';
    }

    if ($username === '') {
      $errors[] = 'El usuario es obligatorio';
    } elseif (flus_text_length($username) < 3) {
      $errors[] = 'El usuario debe tener al menos 3 caracteres';
    } elseif (!preg_match('/\A[a-zA-Z0-9_]+\z/', $username)) {
      $errors[] = 'El usuario solo puede contener letras, numeros y guion bajo';
    } elseif (flus_user_username_exists($pdo, $username, $existingUserId)) {
      $errors[] = $existingUserId ? 'Este nombre de usuario ya esta en uso por otro usuario' : 'Este nombre de usuario ya esta en uso';
    }

    if ($requirePassword && $password === '') {
      $errors[] = 'La contrasena es obligatoria';
    } elseif ($password !== '' && flus_text_length($password) < 6) {
      $errors[] = 'La contrasena debe tener al menos 6 caracteres';
    }

    if ($roleId <= 0 || !flus_role_exists($pdo, $roleId)) {
      $errors[] = 'Debe seleccionar un rol valido';
    }

    return [
      'data' => [
        'nombre' => $nombre,
        'email' => $email,
        'username' => $username,
        'password' => $password,
        'role_id' => $roleId,
        'activo' => $activo,
      ],
      'errors' => $errors,
    ];
  }
}

if (!function_exists('flus_guard_user_admin_mutation')) {
  function flus_guard_user_admin_mutation(PDO $pdo, int $currentUserId, int $targetUserId, ?int $nextActivo = null, bool $deleting = false, ?int $nextRoleId = null, ?string $nextUsername = null): ?string {
    if ($targetUserId <= 0) return null;

    if ($currentUserId > 0 && $targetUserId === $currentUserId) {
      if ($deleting) {
        return 'No puedes eliminar tu propio usuario';
      }

      if ($nextActivo !== null && (int) $nextActivo === 0) {
        return 'No puedes desactivar tu propio usuario';
      }
    }

    $reservedAdminError = flus_guard_reserved_admin_user_mutation($pdo, $targetUserId, $nextActivo, $deleting, $nextRoleId, $nextUsername);
    if ($reservedAdminError !== null) {
      return $reservedAdminError;
    }

    return flus_guard_last_admin_user_change($pdo, $targetUserId, $nextActivo, $deleting, $nextRoleId);
  }
}

if (!function_exists('flus_normalize_sale_status')) {
  function flus_normalize_sale_status($estado): string {
    $value = strtoupper(trim((string) $estado));
    return $value === '' ? 'EMITIDA' : $value;
  }
}

if (!function_exists('flus_sale_is_annulled')) {
  function flus_sale_is_annulled($ventaOrEstado): bool {
    if (is_array($ventaOrEstado)) {
      $ventaOrEstado = $ventaOrEstado['estado'] ?? null;
    }
    return flus_normalize_sale_status($ventaOrEstado) === 'ANULADA';
  }
}

if (!function_exists('flus_sale_can_be_annulled')) {
  function flus_sale_can_be_annulled($ventaOrEstado): bool {
    if (is_array($ventaOrEstado) && (int)($ventaOrEstado['facturada'] ?? 0) === 1) {
      return false;
    }
    return !flus_sale_is_annulled($ventaOrEstado);
  }
}

if (!function_exists('flus_sale_emitida_where')) {
  function flus_sale_emitida_where(string $alias = 'v'): string {
    $prefix = trim($alias) !== '' ? trim($alias) . '.' : '';
    return "({$prefix}estado IS NULL OR {$prefix}estado <> 'ANULADA')";
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

<?php
// public/lib/helpers.php
declare(strict_types=1);

/* ============================
   SEGURIDAD / OUTPUT
============================ */
if (!function_exists('h')) {
  function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}

/* ============================
   DINERO AR
============================ */
if (!function_exists('money_ar')) {
  function money_ar($n): string {
    return '$' . number_format((float)$n, 2, ',', '.');
  }
}

if (!function_exists('parse_money_ar')) {
  /**
   * Convierte "$ 1.234,56" / "1.234,56" / "1234.56" a float
   */
  function parse_money_ar($v): float {
    if ($v === null) return 0.0;
    if (is_int($v) || is_float($v)) return (float)$v;

    $s = trim((string)$v);
    if ($s === '') return 0.0;

    // deja dígitos, coma, punto y signo
    $s = preg_replace('/[^0-9,\.\-]/', '', $s) ?? '0';
    if ($s === '' || $s === '-' || $s === '.' || $s === ',') return 0.0;

    // str_contains compatible
    $hasComma = (strpos($s, ',') !== false);

    // Si trae coma, asumimos formato AR: 1.234,56
    if ($hasComma) {
      $s = str_replace('.', '', $s);
      $s = str_replace(',', '.', $s);
    }

    return is_numeric($s) ? (float)$s : 0.0;
  }
}

/* ============================
   FECHAS
============================ */
if (!function_exists('validDateYmd')) {
  function validDateYmd(?string $s): ?string {
    if (!$s) return null;
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
  }
}

/* ============================
   URL CON FILTROS (paginación)
============================ */
if (!function_exists('urlWith')) {
  /**
   * Mantiene $_GET y pisa con overrides.
   * $base por defecto: archivo actual.
   */
  function urlWith(array $overrides = [], ?string $base = null): string {
    $q = $_GET;

    foreach ($overrides as $k => $v) {
      if ($v === null) unset($q[$k]);
      else $q[$k] = $v;
    }

    if ($base === null) {
      $base = basename((string)($_SERVER['PHP_SELF'] ?? 'index.php'));
    }

    return $base . (empty($q) ? '' : '?' . http_build_query($q));
  }
}

// Alias snake_case
if (!function_exists('url_with')) {
  function url_with(array $overrides = [], ?string $base = null): string {
    return urlWith($overrides, $base);
  }
}

/* ============================
   STOCK: pesable vs unidad
============================ */
if (!function_exists('is_pesable_row')) {
  function is_pesable_row(array $p): bool {
    return !empty($p['es_pesable']) && (int)$p['es_pesable'] === 1;
  }
}

if (!function_exists('format_qty_ar')) {
  function format_qty_ar(float $valor, bool $pesable, int $decPesable = 3): string {
    $dec = $pesable ? $decPesable : 0;
    return number_format($valor, $dec, ',', '.');
  }
}

if (!function_exists('format_qty_field')) {
  function format_qty_field(array $p, string $field, int $decPesable = 3): string {
    $valor = isset($p[$field]) ? (float)$p[$field] : 0.0;
    return format_qty_ar($valor, is_pesable_row($p), $decPesable);
  }
}

// Alias usado en stock.php
if (!function_exists('format_cantidad')) {
  function format_cantidad(array $p, string $field, int $decPesable = 3): string {
    return format_qty_field($p, $field, $decPesable);
  }
}

/* ============================
   CSRF (formularios POST)
============================ */
if (!function_exists('csrf_init')) {
  function csrf_init(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['csrf_token'])) {
      $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
  }
}

if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    csrf_init();
    return (string)($_SESSION['csrf_token'] ?? '');
  }
}

if (!function_exists('csrf_field')) {
  function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
  }
}

if (!function_exists('csrf_verify')) {
  function csrf_verify(?string $token): bool {
    csrf_init();
    if (!$token) return false;
    return hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$token);
  }
}

/* ============================
   APP CONFIG (DB) + CACHE
============================ */
if (!isset($GLOBALS['__app_config_cache']) || !is_array($GLOBALS['__app_config_cache'])) {
  $GLOBALS['__app_config_cache'] = [];
}

if (!function_exists('config_get')) {
  function config_get(PDO $pdo, string $k, ?string $default = null): ?string {
    $cache =& $GLOBALS['__app_config_cache'];

    if (array_key_exists($k, $cache)) {
      return $cache[$k];
    }

    $st = $pdo->prepare("SELECT v FROM app_config WHERE k = :k LIMIT 1");
    $st->execute([':k' => $k]);
    $v = $st->fetchColumn();

    $cache[$k] = ($v !== false) ? (string)$v : $default;
    return $cache[$k];
  }
}

if (!function_exists('config_set')) {
  function config_set(PDO $pdo, string $k, ?string $v): void {
    $st = $pdo->prepare("
      INSERT INTO app_config (k, v)
      VALUES (:k, :v)
      ON DUPLICATE KEY UPDATE v = VALUES(v)
    ");
    $st->execute([':k' => $k, ':v' => $v]);

    // ✅ invalida/actualiza cache local
    $GLOBALS['__app_config_cache'][$k] = $v;
  }
}

/* ============================
   NUM (redondeos, clamp, int-like)
============================ */
if (!function_exists('num_round2')) {
  function num_round2(float $n): float { return round($n, 2); }
}

if (!function_exists('num_clamp0')) {
  function num_clamp0(float $n): float { return ($n < 0) ? 0.0 : $n; }
}

if (!function_exists('num_is_int_like')) {
  function num_is_int_like(float $n, float $eps = 0.00001): bool {
    return abs($n - floor($n)) < $eps;
  }
}
/* ============================
   ALIASES COMPAT (money / format_qty)
   Para no romper pantallas viejas
============================ */
if (!function_exists('money')) {
  function money($n): string {
    return money_ar($n);
  }
}

if (!function_exists('format_qty')) {
  // Alias simple: si no pasás "pesable", asume unidad (0 decimales)
  function format_qty($valor, bool $pesable = false, int $decPesable = 3): string {
    return format_qty_ar((float)$valor, $pesable, $decPesable);
  }
}
if (!function_exists('config_set')) {
  function config_set(PDO $pdo, string $k, ?string $v): void {
    $k = trim($k);
    if ($k === '') return;

    $v = $v === null ? null : trim($v);

    $st = $pdo->prepare("
      INSERT INTO app_config (k, v)
      VALUES (:k, :v)
      ON DUPLICATE KEY UPDATE v = VALUES(v)
    ");
    $st->execute([':k' => $k, ':v' => $v]);

    // si usás cache en config_get, lo invalidamos:
    if (function_exists('config_get')) {
      // la cache de config_get es static interna; no la podemos tocar desde acá
      // (no pasa nada, refresca con F5 o en otra request ya está ok)
    }
  }
}

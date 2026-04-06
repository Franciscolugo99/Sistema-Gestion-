<?php
declare(strict_types=1);

// Schema helpers (cache + compat)
$__flus_schema_helpers = __DIR__ . '/db_schema.php';
if (is_file($__flus_schema_helpers)) require_once $__flus_schema_helpers;
/**
 * src/api_helpers.php
 * Funciones helper centralizadas para todas las APIs
 * 
 * @version 2.2.0
 */

// Evitar redefinición
if (defined('FLUS_API_HELPERS_LOADED')) return;
require_once __DIR__ . '/http_helpers.php';
/**
 * Respuesta JSON exitosa
 */
function json_ok(array $data = [], int $code = 200): void {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Respuesta JSON de error
 */
function json_fail(string $msg, int $code = 400, array $extra = []): void {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(['ok' => false, 'error' => $msg] + $extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/**
 * Alias de compatibilidad para json_fail
 */
if (!function_exists('json_error')) {
function json_error(string $msg, int $code = 400, array $extra = []): void {
    json_fail($msg, $code, $extra);
}
}


/**
 * Respuesta éxito con formato legacy {success:true}
 */
function success_ok(array $data = [], int $code = 200): void {
    json_response(['success' => true] + $data, $code);
}

/**
 * Respuesta error con formato legacy {success:false,message:""}
 */
function success_fail(string $message, int $code = 400, array $extra = []): void {
    json_response(['success' => false, 'message' => $message] + $extra, $code);
}

/**
 * Respuesta JSON genérica (compat con ventas_api)
 */
function json_response(array $data, int $code = 200): never {
    if (ob_get_length()) ob_clean();
    http_response_code($code);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}


/**
 * Requerir permiso (API JSON)
 */
if (!function_exists('flus_auth_issue_json_payload')) {
  function flus_auth_issue_json_payload(): ?array {
    if (!function_exists('auth_issue_get')) {
      return null;
    }

    $issue = auth_issue_get();
    $type = (string)($issue['type'] ?? 'OK');
    $detail = (string)($issue['detail'] ?? '');
    $extra = [];

    if (defined('APP_DEBUG') && APP_DEBUG && $detail !== '') {
      $extra['detail'] = $detail;
    }

    return match ($type) {
      'DB_DOWN' => ['status' => 503, 'error' => 'DB_DOWN', 'extra' => $extra],
      'SCHEMA_MISSING' => ['status' => 503, 'error' => 'SCHEMA_MISSING', 'extra' => $extra],
      'DB_ERROR' => ['status' => 503, 'error' => 'DB_ERROR', 'extra' => $extra],
      default => null,
    };
  }
}

if (!function_exists('require_perm_json')) {
  function require_perm_json(string $perm): void {
    if (function_exists('user_has_permission') && user_has_permission($perm)) {
      return;
    }

    $issuePayload = flus_auth_issue_json_payload();
    if (is_array($issuePayload)) {
      json_fail((string)$issuePayload['error'], (int)$issuePayload['status'], $issuePayload['extra']);
    }

    json_fail('FORBIDDEN', 403, ['perm' => $perm]);
  }
}

if (!function_exists('require_any_perm_json')) {
  function require_any_perm_json(array $perms): void {
    $normalized = array_values(array_map('strval', $perms));
    if (function_exists('user_has_permission')) {
      foreach ($normalized as $perm) {
        if (user_has_permission($perm)) {
          return;
        }
      }
    }

    $issuePayload = flus_auth_issue_json_payload();
    if (is_array($issuePayload)) {
      json_fail((string)$issuePayload['error'], (int)$issuePayload['status'], $issuePayload['extra']);
    }

    json_fail('FORBIDDEN', 403, ['perms' => $normalized]);
  }
}

if (!function_exists('require_method_json')) {
  function require_method_json(string|array $allowedMethods): void {
    $methods = is_array($allowedMethods) ? $allowedMethods : [$allowedMethods];
    $methods = array_values(array_filter(array_map(
      static fn($method): string => strtoupper(trim((string)$method)),
      $methods
    )));

    if ($methods === []) {
      throw new InvalidArgumentException('Allowed methods list cannot be empty');
    }

    $currentMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($currentMethod, $methods, true)) {
      json_fail('Método no permitido', 405, ['allowed_methods' => $methods]);
    }
  }
}

/**
 * Extraer token CSRF desde headers/body
 */
if (!function_exists('flus_csrf_from_request')) {
  function flus_csrf_from_request(array $body = []): string {
    // Header primero
    $h = '';
    if (function_exists('getallheaders')) {
      $headers = getallheaders();
      if (is_array($headers)) {
        foreach ($headers as $k => $v) {
          if (strcasecmp((string)$k, 'X-CSRF-Token') === 0) {
            $h = (string)$v;
            break;
          }
        }
      }
    }
    if ($h !== '') return trim($h);

    // Body JSON / POST
    foreach (['csrf_token','csrf','_csrf'] as $k) {
      if (isset($body[$k]) && is_string($body[$k]) && $body[$k] !== '') return trim($body[$k]);
      if (isset($_POST[$k]) && is_string($_POST[$k]) && $_POST[$k] !== '') return trim((string)$_POST[$k]);
    }
    return '';
  }
}

/**
 * Requerir CSRF (API JSON). Acepta body opcional.
 */
if (!function_exists('require_csrf_json')) {
  function require_csrf_json(?array $body = null): void {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (in_array($method, ['GET','HEAD','OPTIONS'], true)) return;

    $payload = is_array($body) ? $body : [];
    // Si viene vacío, intentar leer JSON (solo si existe helper)
    if (!$payload && function_exists('api_read_json')) {
      $tmp = api_read_json();
      if (is_array($tmp)) $payload = $tmp;
    }
    $t = flus_csrf_from_request($payload);

    if ($t === '' || !function_exists('csrf_verify') || !csrf_verify($t)) {
      json_fail('CSRF', 419, ['hint' => 'Token CSRF inválido o ausente. Recargá la página e intentá de nuevo.']);
    }
  }
}
/**
 * Parsear número con formato argentino (1.234,56 -> 1234.56)
 */
function parse_num($v): float {
    if (is_int($v) || is_float($v)) return (float)$v;
    $s = trim((string)$v);
    if ($s === '') return 0.0;
    // AR: 1.234,56 -> 1234.56
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
    return is_numeric($s) ? (float)$s : 0.0;
}

/**
 * Normalizar medio de pago
 */
function norm_medio_pago(string $m): string {
    $m = strtoupper(trim($m));
    if ($m === 'EFECTIVO') return 'EFECTIVO';
    if ($m === 'MP' || str_contains($m, 'MERCADO')) return 'MP';
    if ($m === 'DEBITO' || str_contains($m, 'DEB')) return 'DEBITO';
    if ($m === 'CREDITO' || str_contains($m, 'CRED')) return 'CREDITO';
    // BUG-06 FIX: TRANSFERENCIA, MODO y QR se perdían → caían al default EFECTIVO.
    // Consecuencia: venta_pagos.medio_pago quedaba 'EFECTIVO' y los totales de caja
    // (transferencia/MP) quedaban incorrectos.
    if ($m === 'TRANSFERENCIA' || $m === 'TRANSFER') return 'TRANSFERENCIA';
    // MODO y QR son pagos digitales sin columna propia en caja_sesiones: se acumulan en total_mp.
    if ($m === 'MODO') return 'MODO';
    if ($m === 'QR')   return 'QR';
    if ($m === 'CC' || str_contains($m, 'CUENTA')) return 'CC';
    return 'EFECTIVO';
}

/**
 * Obtener columnas de una tabla (con cache)
 */
function get_table_columns(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];

    // Preferir helpers cacheados del core (db_schema.php)
    if (function_exists('flus_current_db') && function_exists('flus_columns_set')) {
        $schema = (string)flus_current_db($pdo);
        if ($schema !== '') {
            $set = flus_columns_set($pdo, $schema, $table);
            $cache[$table] = array_keys($set);
            return $cache[$table];
        }
    }

    // Fallback clásico (INFORMATION_SCHEMA)
    $st = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $st->execute([$table]);
    $cache[$table] = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    return $cache[$table];
}

/**
 * Verificar si una columna existe en tabla
 */
function has_col(PDO $pdo, string $table, string $col): bool {
    // Preferir helper core
    if (function_exists('flus_column_exists')) {
        return (bool)flus_column_exists($pdo, $table, $col);
    }

    $cols = get_table_columns($pdo, $table);
    return in_array($col, $cols, true);
}

/**
 * Insert dinámico (solo columnas que existen)
 */
function insert_dynamic(PDO $pdo, string $table, array $data): int {
    $availableCols = get_table_columns($pdo, $table);

    $cols = [];
    $ph   = [];
    $params = [];

    foreach ($data as $col => $val) {
        if (!in_array($col, $availableCols, true)) continue;
        $cols[] = $col;
        $ph[] = ':' . $col;
        $params[':' . $col] = $val;
    }

    if (!$cols) {
        throw new RuntimeException("No hay columnas compatibles para insertar en {$table}");
    }

    $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$pdo->lastInsertId();
}

/**
 * Configurar handlers de error para API
 */
function setup_api_error_handlers(): void {
    // Exception handler
    set_exception_handler(function (Throwable $e): void {
        if (ob_get_length()) ob_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        // Log detallado (no se expone al cliente)
        try {
            $msg = sprintf('[FLUS][API][EXCEPTION] %s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
            $trace = $e->getTraceAsString();
            if (is_string($trace) && $trace !== '') {
                $msg .= "\n" . substr($trace, 0, 4000);
            }
            error_log($msg);
        } catch (Throwable $ignore) {}
        }
        $code = ($e instanceof PDOException) ? 503 : 500;
        http_response_code($code);
        echo json_encode([
            'ok' => false,
            'error' => ($e instanceof PDOException) ? 'DB_DOWN' : 'SERVER_ERROR'
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    });

    // Shutdown handler para errores fatales
    register_shutdown_function(function (): void {
        $e = error_get_last();
        if (!$e) return;

        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array((int)$e['type'], $fatal, true)) return;

        // Log del error fatal (no se expone al cliente)
        try {
            $msg = sprintf('[FLUS][API][FATAL] %s in %s:%d', (string)($e['message'] ?? 'Fatal'), (string)($e['file'] ?? ''), (int)($e['line'] ?? 0));
            error_log($msg);
        } catch (Throwable $ignore) {}


        if (ob_get_length()) ob_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        http_response_code(500);
        
        $response = ['ok' => false, 'error' => 'FATAL'];
        if (defined('APP_DEBUG') && APP_DEBUG === true) {
            $response['detail'] = $e['message'] ?? 'Error fatal';
            $response['file'] = $e['file'] ?? '';
            $response['line'] = $e['line'] ?? 0;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    });
}

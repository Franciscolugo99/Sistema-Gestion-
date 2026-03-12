<?php
// public/auth.php
declare(strict_types=1);

require_once __DIR__ . '/lib/session.php';
flus_session_start();
require_once __DIR__ . '/lib/install_guard.php';

require_once FLUS_ROOT . '/src/config.php';
require_once __DIR__ . '/lib/terminal.php';

// ✅ Sesión unificada (compat legacy)
$sessionHelper = FLUS_ROOT . '/src/session_user.php';
if (is_file($sessionHelper)) {
  require_once $sessionHelper;
  if (function_exists('flus_session_normalize_user')) flus_session_normalize_user();
}

/* ============================================================================
   AUTH/DB DIAGNOSTICS (bulletproof)
============================================================================ */

/** @var array{type:string,code:string,message:string,detail?:string} */
$GLOBALS['__flus_auth_issue'] = [
  'type'    => 'OK',
  'code'    => 'OK',
  'message' => '',
];

function auth_issue_set(string $type, string $code, string $message, string $detail = ''): void {
  $GLOBALS['__flus_auth_issue'] = [
    'type'    => $type,
    'code'    => $code,
    'message' => $message,
    'detail'  => $detail,
  ];
}

function auth_issue_get(): array {
  return $GLOBALS['__flus_auth_issue'] ?? ['type'=>'OK','code'=>'OK','message'=>''];
}

function auth_log(string $msg, string $level = 'info', array $ctx = []): void {
  $uid = function_exists('session_user_id') ? session_user_id() : (int)($_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0));
  $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
  $ctxStr = $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : '';
  $line = "[AUTH][$level] uid={$uid} uri={$uri} {$msg}{$ctxStr}";

  if (function_exists('app_log')) {
    app_log($line, $level);
  } else {
    error_log($line);
  }
}

function is_schema_missing(PDOException $e): bool {
  // SQLSTATE 42S02: Base table or view not found
  if ($e->getCode() === '42S02') return true;

  $m = $e->getMessage();
  // MySQL 1146
  if (strpos($m, '1146') !== false) return true;
  if (stripos($m, "doesn't exist") !== false) return true;

  return false;
}

function is_server_gone(PDOException $e): bool {
  $m = $e->getMessage();
  // 2006 MySQL server has gone away / 2013 Lost connection during query
  if (strpos($m, '2006') !== false || strpos($m, '2013') !== false) return true;
  if (stripos($m, 'server has gone away') !== false) return true;
  if (stripos($m, 'lost connection') !== false) return true;
  return false;
}

function is_cant_connect(PDOException $e): bool {
  $m = $e->getMessage();
  // 2002 Can't connect / 10061 refused / 10060 timeout (Windows)
  if (strpos($m, '2002') !== false) return true;
  if (strpos($m, '(10061)') !== false) return true;
  if (strpos($m, '(10060)') !== false) return true;
  if (stripos($m, "can't connect") !== false) return true;
  return false;
}

/**
 * PDO “fresco” (evita quedarte atado al PDO estático cuando MySQL se reinicia).
 * No toca tu getPDO(), solo lo usa como primer intento.
 */
function flus_pdo_fresh(): PDO {
  $dsn = sprintf(
    "mysql:host=%s;port=%s;dbname=%s;charset=%s;connect_timeout=3",
    (string)DB_HOST,
    (string)DB_PORT,
    (string)DB_NAME,
    (string)DB_CHARSET
  );

  $opts = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_PERSISTENT         => false,
    PDO::ATTR_TIMEOUT            => 3,
  ];

  return new PDO($dsn, (string)DB_USER, (string)DB_PASS, $opts);
}

/**
 * Obtiene PDO con diagnóstico:
 * - Si getPDO() falla → issue DB_DOWN
 * - Si getPDO() devuelve PDO viejo y MySQL se reinició → intenta fresh
 */
function flus_get_pdo_diag(): ?PDO {
  try {
    $pdo = getPDO();
  } catch (Throwable $e) {
    auth_issue_set('DB_DOWN', 'GETPDO_FAIL', 'No se pudo obtener conexión PDO', $e->getMessage());
    auth_log('getPDO() falló', 'error', ['ex' => $e->getMessage()]);
    return null;
  }

  // Ping suave: si está muerto, intentamos fresh
  try {
    $pdo->query("SELECT 1")->fetchColumn();
    return $pdo;
  } catch (PDOException $e) {
    if (is_server_gone($e) || is_cant_connect($e)) {
      auth_log('PDO viejo detectado (server gone). Intentando conexión fresh...', 'warning', ['ex' => $e->getMessage()]);
      try {
        $fresh = flus_pdo_fresh();
        $fresh->query("SELECT 1")->fetchColumn();
        auth_issue_set('OK', 'RECONNECTED', 'Reconectado a MySQL', '');
        return $fresh;
      } catch (Throwable $e2) {
        auth_issue_set('DB_DOWN', 'RECONNECT_FAIL', 'MySQL no responde (reconexión fallida)', $e2->getMessage());
        auth_log('Reconexión fresh falló', 'error', ['ex' => $e2->getMessage()]);
        return null;
      }
    }

    // Otro error raro
    auth_issue_set('DB_ERROR', 'PDO_PING_FAIL', 'Error al validar conexión a BD', $e->getMessage());
    auth_log('PDO ping falló', 'error', ['ex' => $e->getMessage()]);
    return null;
  }
}

/* ============================================================================
   USER / LOGIN
============================================================================ */

function current_user(): ?array {
  if (function_exists('session_user')) {
    return session_user();
  }
  return isset($_SESSION['user']) && is_array($_SESSION['user'])
    ? $_SESSION['user']
    : null;
}

function is_logged_in(): bool {
  if (function_exists('session_user_id')) {
    return session_user_id() > 0;
  }
  return current_user() !== null;
}

/**
 * Login NORMAL (Backoffice): NO exige terminal.
 */
function require_login(bool $withNext = true): void {
  if (!is_logged_in()) {
    $url = 'login.php';
    if ($withNext) {
      $req = $_SERVER['REQUEST_URI'] ?? '';
      if ($req !== '') $url .= '?next=' . urlencode($req);
    }
    header('Location: ' . $url);
    exit;
  }
}

function require_login_json(): void {
  if (!is_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
    exit;
  }
}

/* ============================================================================
   PERMISOS (con diagnóstico)
============================================================================ */

function user_has_permission(string $slug): bool {
  $u = current_user();
  if (!$u) return false;

  $slug = trim($slug);
  if ($slug === '') return false;

  // Cache por request: SOLO cacheamos resultados OK.
  static $cache = [];
  if (array_key_exists($slug, $cache)) return (bool)$cache[$slug];

  $userId = (int)($u['id'] ?? 0);
  if ($userId <= 0) {
    $cache[$slug] = false;
    return false;
  }

  $pdo = flus_get_pdo_diag();
  if (!$pdo) {
    // DB caída: no cacheamos
    $iss = auth_issue_get();
    auth_log('Perm check: sin PDO', 'warning', ['slug' => $slug, 'issue' => $iss['code'] ?? '']);
    return false;
  }

  $sql = "
    SELECT 1
    FROM users u
    JOIN roles r ON u.role_id = r.id
    JOIN role_permission rp ON r.id = rp.role_id
    JOIN permissions p ON rp.permission_id = p.id
    WHERE u.id = :uid
      AND u.activo = 1
      AND p.slug = :slug
    LIMIT 1
  ";

  try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':uid' => $userId, ':slug' => $slug]);
    $ok = (bool)$stmt->fetchColumn();

    // Cachear solo si todo fue OK
    $cache[$slug] = $ok;
    auth_issue_set('OK', 'OK', '', '');
    return $ok;

  } catch (PDOException $e) {
    if (is_schema_missing($e)) {
      auth_issue_set('SCHEMA_MISSING', 'MISSING_TABLE', 'Esquema incompleto: faltan tablas de permisos', $e->getMessage());
      auth_log('Perm check: schema missing', 'error', ['slug' => $slug, 'ex' => $e->getMessage()]);
      // Esto sí lo cacheamos (es consistente dentro del request)
      $cache[$slug] = false;
      return false;
    }

    if (is_server_gone($e) || is_cant_connect($e)) {
      auth_issue_set('DB_DOWN', 'SERVER_GONE', 'MySQL se reinició o no responde durante la consulta', $e->getMessage());
      auth_log('Perm check: server gone', 'error', ['slug' => $slug, 'ex' => $e->getMessage()]);
      // NO cachear: puede volver
      return false;
    }

    auth_issue_set('DB_ERROR', 'PDO_EXCEPTION', 'Error de base de datos en permisos', $e->getMessage());
    auth_log('Perm check: PDOException', 'error', ['slug' => $slug, 'ex' => $e->getMessage()]);

    if (defined('APP_DEBUG') && APP_DEBUG) throw $e;
    return false;

  } catch (Throwable $e) {
    auth_issue_set('DB_ERROR', 'THROWABLE', 'Error inesperado en permisos', $e->getMessage());
    auth_log('Perm check: Throwable', 'error', ['slug' => $slug, 'ex' => $e->getMessage()]);

    if (defined('APP_DEBUG') && APP_DEBUG) throw $e;
    return false;
  }
}

function user_has_any_permission(array $slugs): bool {
  foreach ($slugs as $s) {
    $s = trim((string)$s);
    if ($s === '') continue;

    if (user_has_permission($s)) return true;

    // Si la razón fue DB_DOWN o SCHEMA_MISSING, cortamos: no es “no tenés permiso”
    $iss = auth_issue_get();
    if (($iss['type'] ?? '') === 'DB_DOWN' || ($iss['type'] ?? '') === 'SCHEMA_MISSING') {
      return false;
    }
  }
  return false;
}

/* Mensajes (HTML / JSON) claros cuando el problema NO es un 403 real */
function user_can_access_diagnostics(): bool {
  return user_has_any_permission(['ver_diagnostico', 'gestionar_backups']);
}

function require_diagnostics_permission(): void {
  require_any_permission(['ver_diagnostico', 'gestionar_backups']);
}
function user_can_access_technical_panel(): bool {
  return user_has_any_permission(['administrar_config', 'gestionar_backups']);
}

function require_technical_permission(): void {
  require_any_permission(['administrar_config', 'gestionar_backups']);
}
function flus_render_access_error(string $mode, int $http, string $code, string $msg, string $detail = ''): void {
  http_response_code($http);

  if ($mode === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    $payload = ['ok' => false, 'error' => $code, 'message' => $msg];
    if (defined('APP_DEBUG') && APP_DEBUG && $detail !== '') $payload['detail'] = $detail;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
  }

  header('Content-Type: text/html; charset=utf-8');
  $d = (defined('APP_DEBUG') && APP_DEBUG && $detail !== '')
    ? '<pre style="white-space:pre-wrap;opacity:.8;margin-top:10px;">' . h($detail) . '</pre>'
    : '';

  echo '<div style="max-width:820px;margin:40px auto;font-family:system-ui,Segoe UI,Arial;padding:16px;">';
  echo '<h2 style="margin:0 0 10px 0;">' . h($msg) . '</h2>';
  echo '<div style="opacity:.85;">Código: <b>' . h($code) . '</b> — HTTP ' . (int)$http . '</div>';
  echo $d;
  echo '</div>';
  exit;
}

function require_any_permission(array $slugs): void {
  if (user_has_any_permission($slugs)) return;

  $iss = auth_issue_get();
  $type = (string)($iss['type'] ?? 'OK');

  if ($type === 'DB_DOWN') {
    flus_render_access_error('html', 503, 'DB_DOWN', 'Base de datos no disponible (MySQL no responde / se reinició).', (string)($iss['detail'] ?? ''));
  }
  if ($type === 'SCHEMA_MISSING') {
    flus_render_access_error('html', 503, 'SCHEMA_MISSING', 'Esquema incompleto: faltan tablas (restaurá backup / corré instalación).', (string)($iss['detail'] ?? ''));
  }

  http_response_code(403);
  echo "No tenés permisos para acceder a esta sección.";
  exit;
}

function require_permission(string $slug): void {
  if (user_has_permission($slug)) return;

  $iss = auth_issue_get();
  $type = (string)($iss['type'] ?? 'OK');

  if ($type === 'DB_DOWN') {
    flus_render_access_error('html', 503, 'DB_DOWN', 'Base de datos no disponible (MySQL no responde / se reinició).', (string)($iss['detail'] ?? ''));
  }
  if ($type === 'SCHEMA_MISSING') {
    flus_render_access_error('html', 503, 'SCHEMA_MISSING', 'Esquema incompleto: faltan tablas (restaurá backup / corré instalación).', (string)($iss['detail'] ?? ''));
  }

  http_response_code(403);
  echo "No tenés permisos para acceder a esta sección.";
  exit;
}

/* ============================================================================
   TERMINAL / POS (con diagnóstico)
============================================================================ */

function current_terminal_id(): int {
  static $cache = null;
  if ($cache !== null) return (int)$cache;

  $pdo = flus_get_pdo_diag();
  if (!$pdo) {
    $cache = 0;
    return 0;
  }

  try {
    $tid = terminal_current_id($pdo);
    if ($tid > 0) {
      $_SESSION['terminal_id'] = $tid;
      $cache = $tid;
      auth_issue_set('OK', 'OK', '', '');
      return $tid;
    }
  } catch (PDOException $e) {
    if (is_schema_missing($e)) {
      auth_issue_set('SCHEMA_MISSING', 'MISSING_TABLE', 'Esquema incompleto: faltan tablas de terminales', $e->getMessage());
      auth_log('terminal_current_id: schema missing', 'error', ['ex' => $e->getMessage()]);
      $cache = 0;
      return 0;
    }
    if (is_server_gone($e) || is_cant_connect($e)) {
      auth_issue_set('DB_DOWN', 'SERVER_GONE', 'MySQL no responde durante terminal_current_id()', $e->getMessage());
      auth_log('terminal_current_id: server gone', 'error', ['ex' => $e->getMessage()]);
      $cache = 0;
      return 0;
    }
    auth_issue_set('DB_ERROR', 'PDO_EXCEPTION', 'Error BD en terminal_current_id()', $e->getMessage());
    auth_log('terminal_current_id: PDOException', 'error', ['ex' => $e->getMessage()]);
    $cache = 0;
    return 0;
  } catch (Throwable $e) {
    auth_issue_set('DB_ERROR', 'THROWABLE', 'Error inesperado en terminal_current_id()', $e->getMessage());
    auth_log('terminal_current_id: Throwable', 'error', ['ex' => $e->getMessage()]);
    $cache = 0;
    return 0;
  }

  $cache = 0;
  return 0;
}

function require_terminal(bool $withNext = true): void {
  $tid = current_terminal_id();
  if ($tid > 0) return;

  // Si el problema es DB/Schema, no redirijimos “como si fuera selección”
  $iss = auth_issue_get();
  $type = (string)($iss['type'] ?? 'OK');
  if ($type === 'DB_DOWN') {
    flus_render_access_error('html', 503, 'DB_DOWN', 'No se puede leer la terminal porque MySQL no responde.', (string)($iss['detail'] ?? ''));
  }
  if ($type === 'SCHEMA_MISSING') {
    flus_render_access_error('html', 503, 'SCHEMA_MISSING', 'Faltan tablas de terminales (esquema incompleto).', (string)($iss['detail'] ?? ''));
  }

  $url = 'terminal_select.php';
  if ($withNext) {
    $req = $_SERVER['REQUEST_URI'] ?? '';
    if ($req !== '') $url .= '?next=' . urlencode($req);
  }
  header('Location: ' . $url);
  exit;
}

function require_pos(bool $withNext = true): void {
  require_login($withNext);
  require_terminal($withNext);

  $u = current_user();
  $uid = (int)($u['id'] ?? 0);
  $tid = (int)($_SESSION['terminal_id'] ?? 0);
  $sid = session_id();

  if ($uid <= 0 || $tid <= 0 || $sid === '') return;

  $pdo = flus_get_pdo_diag();
  if (!$pdo) {
    $iss = auth_issue_get();
    flus_render_access_error('html', 503, 'DB_DOWN', 'No se pudo conectar a la base de datos para validar el lock de terminal.', (string)($iss['detail'] ?? ''));
  }

  try {
    $lock = terminal_lock_acquire($pdo, $tid, $uid, $sid, 90);
    if (!($lock['ok'] ?? false)) {
      auth_issue_set('LOCK_FAIL', 'LOCKED', 'Terminal ocupada', json_encode($lock, JSON_UNESCAPED_UNICODE));
      $req = $_SERVER['REQUEST_URI'] ?? 'caja.php';
      header('Location: terminal_select.php?msg=locked&next=' . urlencode($req));
      exit;
    }
  } catch (PDOException $e) {
    if (is_server_gone($e) || is_cant_connect($e)) {
      auth_issue_set('DB_DOWN', 'SERVER_GONE', 'MySQL se cayó al intentar tomar lock', $e->getMessage());
      auth_log('terminal_lock_acquire: server gone', 'error', ['ex' => $e->getMessage()]);
      flus_render_access_error('html', 503, 'DB_DOWN', 'MySQL no responde al intentar tomar el lock de terminal.', $e->getMessage());
    }
    if (is_schema_missing($e)) {
      auth_issue_set('SCHEMA_MISSING', 'MISSING_TABLE', 'Faltan tablas de terminal lock', $e->getMessage());
      auth_log('terminal_lock_acquire: schema missing', 'error', ['ex' => $e->getMessage()]);
      flus_render_access_error('html', 503, 'SCHEMA_MISSING', 'Faltan tablas de terminal lock (esquema incompleto).', $e->getMessage());
    }
    auth_issue_set('DB_ERROR', 'PDO_EXCEPTION', 'Error BD en terminal lock', $e->getMessage());
    auth_log('terminal_lock_acquire: PDOException', 'error', ['ex' => $e->getMessage()]);
    flus_render_access_error('html', 503, 'DB_ERROR', 'Error de base de datos al validar lock de terminal.', $e->getMessage());
  } catch (Throwable $e) {
    auth_issue_set('DB_ERROR', 'THROWABLE', 'Error inesperado en terminal lock', $e->getMessage());
    auth_log('terminal_lock_acquire: Throwable', 'error', ['ex' => $e->getMessage()]);
    flus_render_access_error('html', 503, 'DB_ERROR', 'Error inesperado al validar lock de terminal.', $e->getMessage());
  }
}

function require_pos_json(): void {
  require_login_json();

  $tid = current_terminal_id();
  if ($tid <= 0) {
    $iss = auth_issue_get();
    $type = (string)($iss['type'] ?? 'OK');

    if ($type === 'DB_DOWN') {
      flus_render_access_error('json', 503, 'DB_DOWN', 'MySQL no responde.', (string)($iss['detail'] ?? ''));
    }
    if ($type === 'SCHEMA_MISSING') {
      flus_render_access_error('json', 503, 'SCHEMA_MISSING', 'Faltan tablas de terminales.', (string)($iss['detail'] ?? ''));
    }

    http_response_code(409);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'NO_TERMINAL'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $u = current_user();
  $uid = (int)($u['id'] ?? 0);
  $sid = session_id();

  $pdo = flus_get_pdo_diag();
  if (!$pdo) {
    $iss = auth_issue_get();
    flus_render_access_error('json', 503, 'DB_DOWN', 'No se pudo conectar a la base de datos.', (string)($iss['detail'] ?? ''));
  }

  try {
    $lock = terminal_lock_acquire($pdo, $tid, $uid, $sid, 90);
    if (!($lock['ok'] ?? false)) {
      http_response_code(409);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'error' => 'LOCKED'], JSON_UNESCAPED_UNICODE);
      exit;
    }
  } catch (PDOException $e) {
    if (is_server_gone($e) || is_cant_connect($e)) {
      flus_render_access_error('json', 503, 'DB_DOWN', 'MySQL no responde al tomar lock.', $e->getMessage());
    }
    if (is_schema_missing($e)) {
      flus_render_access_error('json', 503, 'SCHEMA_MISSING', 'Faltan tablas de terminal lock.', $e->getMessage());
    }
    flus_render_access_error('json', 503, 'DB_ERROR', 'Error BD en terminal lock.', $e->getMessage());
  } catch (Throwable $e) {
    flus_render_access_error('json', 503, 'DB_ERROR', 'Error inesperado en terminal lock.', $e->getMessage());
  }
}

// compat
function require_terminal_lock(bool $withNext = true): void { require_pos($withNext); }
function require_terminal_lock_json(): void { require_pos_json(); }

<?php
// public/auth.php
declare(strict_types=1);

require_once __DIR__ . '/lib/session.php';
flus_session_start();
require_once __DIR__ . '/lib/install_guard.php';

require_once FLUS_ROOT . '/src/config.php';
require_once __DIR__ . '/lib/terminal.php';

// âœ… SesiÃ³n unificada (compat legacy)
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
 * PDO â€œfrescoâ€ (evita quedarte atado al PDO estÃ¡tico cuando MySQL se reinicia).
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
 * Obtiene PDO con diagnÃ³stico:
 * - Si getPDO() falla â†’ issue DB_DOWN
 * - Si getPDO() devuelve PDO viejo y MySQL se reiniciÃ³ â†’ intenta fresh
 */
function flus_get_pdo_diag(): ?PDO {
  static $checked = false;
  static $cached = null;

  if ($checked && $cached instanceof PDO) {
    return $cached;
  }

  try {
    $pdo = getPDO();
  } catch (Throwable $e) {
    auth_issue_set('DB_DOWN', 'GETPDO_FAIL', 'No se pudo obtener conexiÃ³n PDO', $e->getMessage());
    auth_log('getPDO() fallÃ³', 'error', ['ex' => $e->getMessage()]);
    return null;
  }

  // Ping suave: si estÃ¡ muerto, intentamos fresh
  try {
    $pdo->query("SELECT 1")->fetchColumn();
    $checked = true;
    $cached = $pdo;
    return $pdo;
  } catch (PDOException $e) {
    if (is_server_gone($e) || is_cant_connect($e)) {
      auth_log('PDO viejo detectado (server gone). Intentando conexiÃ³n fresh...', 'warning', ['ex' => $e->getMessage()]);
      try {
        $fresh = flus_pdo_fresh();
        $fresh->query("SELECT 1")->fetchColumn();
        auth_issue_set('OK', 'RECONNECTED', 'Reconectado a MySQL', '');
        $checked = true;
        $cached = $fresh;
        return $fresh;
      } catch (Throwable $e2) {
        auth_issue_set('DB_DOWN', 'RECONNECT_FAIL', 'MySQL no responde (reconexiÃ³n fallida)', $e2->getMessage());
        auth_log('ReconexiÃ³n fresh fallÃ³', 'error', ['ex' => $e2->getMessage()]);
        return null;
      }
    }

    // Otro error raro
    auth_issue_set('DB_ERROR', 'PDO_PING_FAIL', 'Error al validar conexiÃ³n a BD', $e->getMessage());
    auth_log('PDO ping fallÃ³', 'error', ['ex' => $e->getMessage()]);
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
   PERMISOS (con diagnÃ³stico)
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
    // DB caÃ­da: no cacheamos
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
      // Esto sÃ­ lo cacheamos (es consistente dentro del request)
      $cache[$slug] = false;
      return false;
    }

    if (is_server_gone($e) || is_cant_connect($e)) {
      auth_issue_set('DB_DOWN', 'SERVER_GONE', 'MySQL se reiniciÃ³ o no responde durante la consulta', $e->getMessage());
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

    // Si la razÃ³n fue DB_DOWN o SCHEMA_MISSING, cortamos: no es â€œno tenÃ©s permisoâ€
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
  $safeCode = h($code);
  $safeMsg = h($msg);
  $safeDetail = (defined('APP_DEBUG') && APP_DEBUG && $detail !== '') ? h($detail) : '';
  $homeHref = is_logged_in() ? 'index.php' : 'login.php';
  $homeLabel = is_logged_in() ? 'Volver al inicio' : 'Ir al login';
  $statusLabel = $http === 403
    ? 'Acceso restringido'
    : ($http >= 500 ? 'Servicio temporalmente no disponible' : 'Atencion');
  $toneClass = $http >= 500 ? 'flus-access-chip--warn' : 'flus-access-chip--lock';

  echo '<!doctype html>';
  echo '<html lang="es"><head>';
  echo '<meta charset="utf-8">';
  echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
  echo '<title>' . $safeMsg . ' | FLUS</title>';
  echo '<style>
    :root{
      --flus-bg:#f4f7fb;
      --flus-card:#ffffff;
      --flus-text:#172033;
      --flus-muted:#66758f;
      --flus-line:#d8e3f2;
      --flus-brand:#19c37d;
      --flus-brand-deep:#0f8f66;
      --flus-warn:#c77d2b;
      --flus-warn-bg:#fff4e2;
      --flus-lock:#315d9a;
      --flus-lock-bg:#edf4ff;
      --flus-shadow:0 30px 70px rgba(25,45,84,.12);
    }
    *{box-sizing:border-box}
    body{
      margin:0;
      min-height:100vh;
      font-family:Segoe UI,Arial,sans-serif;
      color:var(--flus-text);
      background:
        radial-gradient(circle at top left, rgba(25,195,125,.10), transparent 28%),
        radial-gradient(circle at top right, rgba(49,93,154,.10), transparent 22%),
        linear-gradient(180deg, #f8fbff 0%, var(--flus-bg) 100%);
    }
    .flus-access-shell{
      min-height:100vh;
      display:flex;
      align-items:center;
      justify-content:center;
      padding:32px 18px;
    }
    .flus-access-card{
      width:min(760px, 100%);
      background:rgba(255,255,255,.95);
      border:1px solid rgba(216,227,242,.9);
      border-radius:28px;
      box-shadow:var(--flus-shadow);
      overflow:hidden;
      backdrop-filter:blur(10px);
    }
    .flus-access-topbar{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:16px;
      padding:24px 28px 18px;
      border-bottom:1px solid rgba(216,227,242,.85);
    }
    .flus-access-brand{
      font-size:32px;
      font-weight:900;
      letter-spacing:.12em;
      color:#12203a;
    }
    .flus-access-chip{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:10px 14px;
      border-radius:999px;
      font-size:14px;
      font-weight:700;
      border:1px solid transparent;
      white-space:nowrap;
    }
    .flus-access-chip::before{
      content:"";
      width:10px;
      height:10px;
      border-radius:50%;
      background:currentColor;
      opacity:.9;
    }
    .flus-access-chip--warn{
      color:var(--flus-warn);
      background:var(--flus-warn-bg);
      border-color:rgba(199,125,43,.18);
    }
    .flus-access-chip--lock{
      color:var(--flus-lock);
      background:var(--flus-lock-bg);
      border-color:rgba(49,93,154,.16);
    }
    .flus-access-body{
      padding:32px 28px 30px;
      display:grid;
      gap:22px;
    }
    .flus-access-kicker{
      margin:0 0 8px;
      text-transform:uppercase;
      letter-spacing:.16em;
      font-size:12px;
      font-weight:800;
      color:var(--flus-brand-deep);
    }
    .flus-access-title{
      margin:0;
      font-size:clamp(28px, 5vw, 42px);
      line-height:1.05;
      letter-spacing:.04em;
    }
    .flus-access-copy{
      margin:0;
      max-width:52ch;
      color:var(--flus-muted);
      font-size:16px;
      line-height:1.65;
    }
    .flus-access-panel{
      display:grid;
      gap:14px;
      padding:20px;
      border:1px dashed var(--flus-line);
      border-radius:22px;
      background:linear-gradient(180deg, rgba(248,251,255,.86), rgba(255,255,255,.96));
    }
    .flus-access-meta{
      display:flex;
      flex-wrap:wrap;
      gap:12px;
      color:var(--flus-muted);
      font-size:14px;
    }
    .flus-access-badge{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:8px 12px;
      border-radius:999px;
      border:1px solid var(--flus-line);
      background:#fff;
      color:#21314f;
      font-weight:700;
    }
    .flus-access-detail{
      margin:0;
      padding:14px 16px;
      border-radius:16px;
      border:1px solid rgba(216,227,242,.9);
      background:#0f172a;
      color:#dbeafe;
      font:13px/1.6 Consolas, Monaco, monospace;
      white-space:pre-wrap;
      word-break:break-word;
    }
    .flus-access-actions{
      display:flex;
      flex-wrap:wrap;
      gap:12px;
    }
    .flus-btn{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-height:46px;
      padding:0 18px;
      border-radius:999px;
      text-decoration:none;
      font-weight:800;
      letter-spacing:.02em;
      transition:transform .15s ease, box-shadow .15s ease;
    }
    .flus-btn:hover{
      transform:translateY(-1px);
    }
    .flus-btn--primary{
      color:#fff;
      background:linear-gradient(135deg, var(--flus-brand) 0%, #12a06d 100%);
      box-shadow:0 14px 28px rgba(25,195,125,.22);
    }
    @media (max-width: 640px){
      .flus-access-topbar,
      .flus-access-body{
        padding-left:20px;
        padding-right:20px;
      }
      .flus-access-topbar{
        align-items:flex-start;
        flex-direction:column;
      }
      .flus-access-actions .flus-btn{
        width:100%;
      }
    }
  </style>';
  echo '</head><body>';
  echo '<main class="flus-access-shell">';
  echo '<section class="flus-access-card" aria-labelledby="flus-access-title">';
  echo '<div class="flus-access-topbar">';
  echo '<div class="flus-access-brand">FLUS</div>';
  echo '<div class="flus-access-chip ' . $toneClass . '">' . h($statusLabel) . '</div>';
  echo '</div>';
  echo '<div class="flus-access-body">';
  echo '<div>';
  echo '<p class="flus-access-kicker">Acceso protegido</p>';
  echo '<h1 class="flus-access-title" id="flus-access-title">' . $safeMsg . '</h1>';
  echo '<p class="flus-access-copy">La solicitud fue bloqueada por una regla de acceso del sistema. Si crees que deberias poder entrar, revisa el rol del usuario o consulta con un administrador.</p>';
  echo '</div>';
  echo '<div class="flus-access-panel">';
  echo '<div class="flus-access-meta">';
  echo '<span class="flus-access-badge">Codigo ' . $safeCode . '</span>';
  echo '<span class="flus-access-badge">HTTP ' . (int)$http . '</span>';
  echo '</div>';
  if ($safeDetail !== '') {
    echo '<pre class="flus-access-detail">' . $safeDetail . '</pre>';
  }
  echo '<div class="flus-access-actions">';
  echo '<a class="flus-btn flus-btn--primary" href="' . h($homeHref) . '">' . h($homeLabel) . '</a>';
  echo '</div>';
  echo '</div>';
  echo '</div>';
  echo '</section>';
  echo '</main>';
  echo '</body></html>';
  exit;
}

function require_any_permission(array $slugs): void {
  if (user_has_any_permission($slugs)) return;

  $iss = auth_issue_get();
  $type = (string)($iss['type'] ?? 'OK');

  if ($type === 'DB_DOWN') {
    flus_render_access_error('html', 503, 'DB_DOWN', 'Base de datos no disponible (MySQL no responde / se reiniciÃ³).', (string)($iss['detail'] ?? ''));
  }
  if ($type === 'SCHEMA_MISSING') {
    flus_render_access_error('html', 503, 'SCHEMA_MISSING', 'Esquema incompleto: faltan tablas (restaurÃ¡ backup / corrÃ© instalaciÃ³n).', (string)($iss['detail'] ?? ''));
  }

  flus_render_access_error('html', 403, 'FORBIDDEN', 'No tenes permisos para acceder a esta seccion.');
}

function require_permission(string $slug): void {
  if (user_has_permission($slug)) return;

  $iss = auth_issue_get();
  $type = (string)($iss['type'] ?? 'OK');

  if ($type === 'DB_DOWN') {
    flus_render_access_error('html', 503, 'DB_DOWN', 'Base de datos no disponible (MySQL no responde / se reiniciÃ³).', (string)($iss['detail'] ?? ''));
  }
  if ($type === 'SCHEMA_MISSING') {
    flus_render_access_error('html', 503, 'SCHEMA_MISSING', 'Esquema incompleto: faltan tablas (restaurÃ¡ backup / corrÃ© instalaciÃ³n).', (string)($iss['detail'] ?? ''));
  }

  flus_render_access_error('html', 403, 'FORBIDDEN', 'No tenes permisos para acceder a esta seccion.');
}

/* ============================================================================
   TERMINAL / POS (con diagnÃ³stico)
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

  // Si el problema es DB/Schema, no redirijimos â€œcomo si fuera selecciÃ³nâ€
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
      auth_issue_set('DB_DOWN', 'SERVER_GONE', 'MySQL se cayÃ³ al intentar tomar lock', $e->getMessage());
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


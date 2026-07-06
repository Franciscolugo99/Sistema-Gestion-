<?php
declare(strict_types=1);
// public/bootstrap.php
require_once __DIR__ . '/lib/root.php';

require_once __DIR__ . '/lib/session.php';
flus_session_start();

// ✅ Sesión unificada (compat legacy)
$sessionHelper = FLUS_ROOT . '/src/session_user.php';
if (is_file($sessionHelper)) {
  require_once $sessionHelper;
  if (function_exists('flus_session_normalize_user')) flus_session_normalize_user();
}
require_once __DIR__ . '/lib/install_guard.php';

require_once FLUS_ROOT . '/src/config.php';
require_once FLUS_ROOT . '/src/db_helpers.php';
require_once FLUS_ROOT . '/src/http_helpers.php';

// Forzar UTF-8 en respuestas HTML normales para evitar mojibake por charset por defecto del servidor.
$accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
$requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
$isApiBootstrapContext = (
  defined('FLUS_API_CONTEXT') ||
  str_contains($requestUri, '/api/') ||
  str_contains($accept, 'application/json')
);
if (!$isApiBootstrapContext && !headers_sent()) {
  header('Content-Type: text/html; charset=utf-8');
}
// ✅ Modo mantenimiento (p.ej. durante restore de backups)
$maintenanceFlag = FLUS_ROOT . '/storage/maintenance.flag';
if (!defined('FLUS_MAINTENANCE_BYPASS') && is_file($maintenanceFlag)) {
  $isApiContext = (
    defined('FLUS_API_CONTEXT') ||
    str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/') ||
    (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
  );

  if ($isApiContext) {
    if (!headers_sent()) {
      header('Content-Type: application/json; charset=utf-8');
      header('Cache-Control: no-store');
    }
    if (ob_get_length()) { @ob_clean(); }
    http_response_code(503);
    echo json_encode([
      'ok' => false,
      'error' => 'MAINTENANCE',
      'hint' => 'Sistema en mantenimiento. Reintentá en unos minutos.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
  }
  http_response_code(503);
  echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>FLUS - Mantenimiento</title>';
  echo '<style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:32px;max-width:760px}';
  echo '.card{border:1px solid #ddd;border-radius:12px;padding:18px}';
  echo 'code{background:#f4f4f4;padding:2px 6px;border-radius:6px}</style>';
  echo '</head><body>';
  echo '<h1>FLUS está en mantenimiento</h1>';
  echo '<div class="card">';
  echo '<p>Se está ejecutando una tarea de restauración/backup o el sistema quedó en modo mantenimiento.</p>';
  echo '<p>Probá de nuevo en unos minutos. Si quedó trabado, desactivá el mantenimiento desde <code>/backups.php</code> (administración) o borrando <code>storage/maintenance.flag</code>.</p>';
  echo '</div>';
  echo '</body></html>';
  exit;
}


define('APP_BOOTSTRAPPED', true);

// ✅ Licencias (enforcement central)
$licFile = FLUS_ROOT . '/src/license.php';
if (is_file($licFile)) {
  require_once $licFile;
  if (function_exists('flus_license_status')) {
    $lic = flus_license_status();
    if (!defined('FLUS_LICENSE')) define('FLUS_LICENSE', $lic);
    if (!defined('FLUS_LIMITED')) define('FLUS_LIMITED', (bool)($lic['limited'] ?? false));
    if (!defined('FLUS_PLAN')) define('FLUS_PLAN', (string)($lic['plan'] ?? 'NONE'));
  }
}





// ✅ LICENCIA: bloqueo total tras gracia (y trial si no hay licencia)
if (!defined('FLUS_LICENSE_GRACE_DAYS')) define('FLUS_LICENSE_GRACE_DAYS', 3);
if (!defined('FLUS_LICENSE_TRIAL_DAYS')) define('FLUS_LICENSE_TRIAL_DAYS', 3);

if (defined('FLUS_LICENSE') && is_array(FLUS_LICENSE) && function_exists('flus_is_api_context')) {
  $licStatus = (string)(FLUS_LICENSE['status'] ?? '');
  $daysLeft  = FLUS_LICENSE['days_left'] ?? null; // int|null (puede ser negativo)

  $graceDays = (int)FLUS_LICENSE_GRACE_DAYS;
  $trialDays = (int)FLUS_LICENSE_TRIAL_DAYS;

  $locked = false;
  $lockReason = '';

  // Expirada: gracia N días, luego bloqueo
  if ($licStatus === 'expired') {
    $overdue = 0;
    if (is_int($daysLeft) || is_numeric($daysLeft)) {
      $dl = (int)$daysLeft;
      $overdue = ($dl < 0) ? (-$dl) : 0;
    }
    if ($overdue > $graceDays) { // día 4 si grace=3
      $locked = true;
      $lockReason = 'GRACE_EXCEEDED';
    }
  }

  // Sin licencia: trial N días desde primer uso, luego bloqueo
  if (!$locked && $licStatus === 'missing' && function_exists('flus_license_state_load') && function_exists('flus_license_state_save')) {
    $st = flus_license_state_load();
    $ts = (int)($st['trial_start_ts'] ?? 0);
    if ($ts <= 0) {
      $ts = time();
      $st['trial_start_ts'] = $ts;
      $st['trial_start_at'] = date('c', $ts);
      flus_license_state_save($st);
    }
    $elapsed = (int)floor((time() - $ts) / 86400);
    if ($elapsed >= $trialDays) {
      $locked = true;
      $lockReason = 'TRIAL_EXPIRED';
    }
  }

  // Inválida / reloj modificado / etc: bloqueo inmediato
  if (!$locked && $licStatus !== 'active' && $licStatus !== 'bypass' && $licStatus !== 'expired' && $licStatus !== 'missing') {
    $locked = true;
    $licReason = trim((string)(FLUS_LICENSE['reason'] ?? ''));
    $lockReason = $licReason !== '' ? $licReason : strtoupper($licStatus !== '' ? $licStatus : 'LOCKED');
  }

  // Si está locked, solo permitimos: login, login_process, licencia, logout y assets estáticos
  if ($locked) {
    if (!defined('FLUS_LICENSE_LOCKED')) define('FLUS_LICENSE_LOCKED', true);
    if (!defined('FLUS_LICENSE_LOCK_REASON')) define('FLUS_LICENSE_LOCK_REASON', $lockReason);

    $uriPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $base    = strtolower(basename($uriPath));

    $isAsset = (bool)preg_match('~\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot|map)$~i', $uriPath)
      || str_contains($uriPath, '/assets/');

    $allowed = $isAsset || in_array($base, [
      'login.php',
      'login_process.php',
      'licencia.php',
      'logout.php',
    ], true);

    if (!$allowed && defined('FLUS_LICENSE_CLOUD_MOCK_ENABLED') && FLUS_LICENSE_CLOUD_MOCK_ENABLED) {
      $allowed = ($base === 'license_cloud_mock.php');
    }

    if (!$allowed) {
      // API: JSON 402
      if (flus_is_api_context()) {
        if (!headers_sent()) {
          header('Content-Type: application/json; charset=utf-8');
          header('Cache-Control: no-store');
        }
        if (ob_get_length()) { @ob_clean(); }
        http_response_code(402);
        echo json_encode([
          'ok' => false,
          'error' => 'LICENSE_LOCKED',
          'reason' => $lockReason,
          'grace_days' => $graceDays,
          'trial_days' => $trialDays,
        ], JSON_UNESCAPED_UNICODE);
        exit;
      }

      // HTML: redirigir a licencia (vía login si no está autenticado)
      $baseDir    = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
      $licenseUrl = $baseDir . '/licencia.php?locked=1&reason=' . urlencode($lockReason);

      // Si hay helper de auth, lo usamos; si no, fallback por sesión
      $isAuthed = function_exists('isAuthenticated') ? isAuthenticated() : !empty($_SESSION['user_id']);

      if (!$isAuthed) {
        $loginUrl = $baseDir . '/login.php?next=' . urlencode($licenseUrl);
        header('Location: ' . $loginUrl);
        exit;
      }

      header('Location: ' . $licenseUrl);
      exit;
    }
  }
}

// compatibilidad (lo que ya usa el sistema)
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/auth.php';

// core nuevo (safe)
$coreHelpers    = FLUS_ROOT . '/src/helpers.php';
$schemaHelpers = FLUS_ROOT . '/src/db_schema.php';
$sessionRegistry = FLUS_ROOT . '/src/session_registry.php';
if (file_exists($schemaHelpers)) require_once $schemaHelpers;
if (file_exists($sessionRegistry)) require_once $sessionRegistry;
$coreMiddleware = FLUS_ROOT . '/src/Middleware.php';
$coreBase       = FLUS_ROOT . '/src/BaseController.php';

if (file_exists($coreHelpers))    require_once $coreHelpers;
if (file_exists($coreMiddleware)) require_once $coreMiddleware;
if (file_exists($coreBase))       require_once $coreBase;

// Conveniencia: muchas páginas usan $pdo y $user
try {
  $pdo = getPDO();
} catch (Throwable $e) {
  http_response_code(503);
  
  // ✅ FIX v2.1.1: Si estamos en contexto API, devolver JSON en lugar de HTML
  $isApiContext = (
    defined('FLUS_API_CONTEXT') || 
    str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/') ||
    (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
  );
  
  if ($isApiContext) {
    if (!headers_sent()) {
      header('Content-Type: application/json; charset=utf-8');
      header('Cache-Control: no-store');
    }
    echo json_encode([
      'ok' => false,
      'error' => 'DB_DOWN',
      'hint' => 'Base de datos no disponible'
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
  
  // Contexto HTML normal
  if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
  }

  echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>FLUS - DB no disponible</title>';
  echo '<style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;margin:32px;max-width:760px}';
  echo '.card{border:1px solid #ddd;border-radius:12px;padding:18px}';
  echo 'code{background:#f4f4f4;padding:2px 6px;border-radius:6px}</style>';
  echo '</head><body>';
  echo '<h1>Base de datos no disponible</h1>';
  echo '<div class="card">';
  echo '<p>FLUS no puede conectarse a la base de datos en este momento.</p>';
  echo '<p>Revisá que MySQL/MariaDB esté iniciado y que <code>src/config.php</code> tenga host/puerto/credenciales correctas.</p>';
  echo '<p>Si es una instalación nueva, abrí <a href="install.php">install.php</a>.</p>';
  echo '</div>';
  echo '</body></html>';
  exit;
}
$user = current_user();

if (
  !defined('FLUS_SESSION_ENFORCE_BYPASS') &&
  is_array($user) &&
  function_exists('flus_user_sessions_table_exists') &&
  function_exists('flus_session_fetch')
) {
  try {
    $sessionId = session_id();
    $userId = (int)($user['id'] ?? 0);

    if ($userId > 0 && $sessionId !== '' && flus_user_sessions_table_exists($pdo)) {
      $sessionRow = flus_session_fetch($pdo, $sessionId);
      if (!is_array($sessionRow) && function_exists('flus_session_register')) {
        flus_session_register($pdo, $user, ['session_id' => $sessionId]);
        $sessionRow = flus_session_fetch($pdo, $sessionId);
      }

      $status = strtoupper((string)($sessionRow['status'] ?? 'ACTIVE'));
      if ($status !== 'ACTIVE') {
        unset($_SESSION['terminal_id']);
        if (function_exists('terminal_clear_cookie')) {
          terminal_clear_cookie();
        }

        $isApiContext = (
          defined('FLUS_API_CONTEXT') ||
          str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/') ||
          (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string)$_SERVER['HTTP_ACCEPT'], 'application/json'))
        );

        if ($isApiContext) {
          if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
          }
          if (ob_get_length()) { @ob_clean(); }
          http_response_code(401);
          echo json_encode([
            'ok' => false,
            'error' => 'SESSION_REVOKED',
          ], JSON_UNESCAPED_UNICODE);
          exit;
        }

        header('Location: logout.php?reason=revoked');
        exit;
      }

      if (function_exists('flus_session_touch')) {
        flus_session_touch($pdo, $userId, $sessionId);
      }
    }
  } catch (Throwable $e) {
    error_log('bootstrap session_registry: ' . $e->getMessage());
  }
}

<?php
declare(strict_types=1);
// public/bootstrap.php
require_once __DIR__ . '/lib/root.php';

require_once __DIR__ . '/lib/session.php';
flus_session_start();
require_once __DIR__ . '/lib/install_guard.php';

require_once FLUS_ROOT . '/src/config.php';
require_once FLUS_ROOT . '/src/version.php';

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

// compatibilidad (lo que ya usa el sistema)
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/auth.php';

// core nuevo (safe)
$coreHelpers    = FLUS_ROOT . '/src/helpers.php';
$schemaHelpers = FLUS_ROOT . '/src/db_schema.php';
if (file_exists($schemaHelpers)) require_once $schemaHelpers;
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


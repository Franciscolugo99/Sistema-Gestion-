<?php
// public/backups.php
declare(strict_types=1);

$isAjax = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['ajax']);
if ($isAjax) {
  // Evitar que warnings/notices rompan el JSON (Unexpected token '<')
  error_reporting(E_ALL);
  ini_set('display_errors', '0');
  if (ob_get_level() === 0) { ob_start(); }
}

define('FLUS_MAINTENANCE_BYPASS', true);
require_once __DIR__ . '/bootstrap.php';

require_login();
// Permiso: preferir sesión (no depender de DB si está en restauración)
if (session_status() === PHP_SESSION_NONE) session_start();

$hasPerm = in_array('gestionar_backups', $_SESSION['permissions'] ?? [], true);
if (!$hasPerm && function_exists('user_has_permission')) {
  $hasPerm = user_has_permission('gestionar_backups');
}
if (!$hasPerm) {
  http_response_code(403);
  echo 'No tenés permisos para acceder a esta sección.';
  exit;
}

require_once __DIR__ . '/../src/backup_lib.php';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$pageTitle = 'Backups - FLUS';
$maintenanceFlag = FLUS_ROOT . '/storage/maintenance.flag';
$maintenanceActive = is_file($maintenanceFlag);

$maintenanceMeta = null;
$maintenanceError = null;
if ($maintenanceActive) {
  $raw = @file_get_contents($maintenanceFlag);
  $m = is_string($raw) ? json_decode($raw, true) : null;
  if (is_array($m)) {
    $maintenanceMeta = $m;
    $maintenanceError = (string)($m['last_error'] ?? '');
  }
}
$currentSection = 'configuracion';
$extraCss = ['assets/css/backups.css'];
$extraJs = ['assets/js/backups.js'];

$info = null;
$error = null;

// Manejo de acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $token = (string)($_POST['csrf_token'] ?? '');

  if (!hash_equals($_SESSION['csrf_token'], $token)) {
    $error = 'Token CSRF inválido. Recargá la página e intentá de nuevo.';
  } else {
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'crear') {
      $err = null;
      $file = backup_create($err);
      if ($file) {
        $info = 'Backup creado exitosamente: ' . basename($file);
      } else {
        $error = 'Error al crear backup: ' . ($err ?: 'Error desconocido. Verificá los permisos y la configuración de la base de datos.');
      }
    } elseif ($accion === 'borrar') {
      $file = (string)($_POST['file'] ?? '');
      $err = null;
      if (backup_delete($file, $err)) {
        $info = 'Backup eliminado: ' . basename($file);
      } else {
        $error = 'Error al eliminar backup: ' . ($err ?: 'No se pudo borrar el archivo.');
      }
    } elseif ($accion === 'restaurar') {
      $file = (string)($_POST['file'] ?? '');
      $err = null;
      if (backup_restore($file, $err)) {
        $info = 'Restauración completada: ' . basename($file);
      } else {
        $error = 'Error al restaurar backup: ' . ($err ?: 'No se pudo restaurar.');
      }
    } elseif ($accion === 'maintenance_off') {
      $confirm = (string)($_POST['confirm'] ?? '');
      if ($confirm !== 'SALIR') {
        $error = 'Confirmación inválida. Escribí SALIR para desactivar mantenimiento.';
      } else {
        if (is_file($maintenanceFlag)) {
          @unlink($maintenanceFlag);
        }
        $info = 'Mantenimiento desactivado.';
      }
    }
  }

  // Para AJAX requests, devolver JSON
  if (!empty($_POST['ajax'])) {
    if (ob_get_length()) { @ob_clean(); }
    if (!headers_sent()) {
      header('Content-Type: application/json; charset=utf-8');
      header('Cache-Control: no-store');
      header('X-Content-Type-Options: nosniff');
    }
    echo json_encode([
      'success' => !$error,
      'message' => $error ?: $info,
      'items' => backup_list()
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
  }
}

$items = backup_list();
$totalSize = array_sum(array_column($items, 'size'));

require __DIR__ . '/partials/header.php';
?>

<div class="panel backups-panel">
  <div class="panel-head">
    <div>
      <h1>Gestión de Backups</h1>
      <p class="panel-subtitle">Administrá las copias de seguridad de tu base de datos</p>
    </div>
    <div class="bk-actions">
      <form method="post" id="createBackupForm" class="inline-form">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="accion" value="crear">
        <button class="btn btn-primary" type="submit" id="btnCrearBackup">
          <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
            <polyline points="7 10 12 15 17 10"/>
            <line x1="12" y1="15" x2="12" y2="3"/>
          </svg>
          <span>Crear Backup</span>
        </button>
      </form>
    </div>
  </div>

  <?php if ($maintenanceActive): ?>
    <div class="alert alert-err">
      <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      <span>
  <strong>Sistema en mantenimiento:</strong> se está restaurando un backup o quedó activo por error.
  <?php if (!empty($maintenanceError)): ?>
    <details class="bk-details">
      <summary>Ver detalle del error</summary>
      <pre class="bk-pre"><?= h($maintenanceError) ?></pre>
    </details>
  <?php endif; ?>
  Podés esperar o <button type="button" class="btn btn-warning btn-sm" id="btnMaintenanceOff">Salir de mantenimiento</button>
</span>
    </div>
  <?php endif; ?>

  <?php if ($info): ?>
    <div class="alert alert-ok">
      <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
        <polyline points="22 4 12 14.01 9 11.01"/>
      </svg>
      <span><?= h($info) ?></span>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert alert-err">
      <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      <span><?= h($error) ?></span>
    </div>
  <?php endif; ?>

  <!-- Info cards -->
  <div class="bk-stats">
    <div class="stat-card">
      <div class="stat-icon">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
        </svg>
      </div>
      <div class="stat-content">
        <div class="stat-value"><?= count($items) ?></div>
        <div class="stat-label">Backups Totales</div>
      </div>
    </div>

    <div class="stat-card">
      <div class="stat-icon">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
          <polyline points="17 8 12 3 7 8"/>
          <line x1="12" y1="3" x2="12" y2="15"/>
        </svg>
      </div>
      <div class="stat-content">
        <div class="stat-value"><?= h(fmt_bytes($totalSize)) ?></div>
        <div class="stat-label">Espacio Utilizado</div>
      </div>
    </div>

    <div class="stat-card">
      <div class="stat-icon">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10"/>
          <polyline points="12 6 12 12 16 14"/>
        </svg>
      </div>
      <div class="stat-content">
        <div class="stat-value">
          <?php 
          if (!empty($items)) {
            echo h(date('d/m H:i', (int)$items[0]['mtime']));
          } else {
            echo '—';
          }
          ?>
        </div>
        <div class="stat-label">Último Backup</div>
      </div>
    </div>
  </div>

  <!-- Tip box -->
  <div class="bk-note">
    <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <circle cx="12" cy="12" r="10"/>
      <line x1="12" y1="16" x2="12" y2="12"/>
      <line x1="12" y1="8" x2="12.01" y2="8"/>
    </svg>
    <div>
      <strong>Automatización:</strong> Para programar backups automáticos en Windows, usá el Programador de Tareas con:
      <code>C:\xampp\php\php.exe C:\xampp\htdocs\kiosco\scripts\backup_db.php</code>
    </div>
  </div>

  <!-- Tabla de backups -->
  <div class="table-wrap">
    <table class="table bk-table">
      <thead>
        <tr>
          <th>
            <div class="th-content">
              <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/>
                <polyline points="13 2 13 9 20 9"/>
              </svg>
              <span>Archivo</span>
            </div>
          </th>
          <th>
            <div class="th-content">
              <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
              </svg>
              <span>Tamaño</span>
            </div>
          </th>
          <th>
            <div class="th-content">
              <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                <line x1="16" y1="2" x2="16" y2="6"/>
                <line x1="8" y1="2" x2="8" y2="6"/>
                <line x1="3" y1="10" x2="21" y2="10"/>
              </svg>
              <span>Fecha de Creación</span>
            </div>
          </th>
          <th class="t-right">Acciones</th>
        </tr>
      </thead>
      <tbody id="backupsTableBody">
      <?php if (!$items): ?>
        <tr>
          <td colspan="4" class="empty-state">
            <svg class="icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
              <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
            </svg>
            <p>No hay backups disponibles</p>
            <small>Creá tu primer backup usando el botón de arriba</small>
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($items as $idx => $it): ?>
          <tr>
            <td>
              <div class="file-info">
                <svg class="icon file-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                  <polyline points="14 2 14 8 20 8"/>
                </svg>
                <span class="mono"><?= h($it['file']) ?></span>
              </div>
            </td>
            <td class="size-cell"><?= h(fmt_bytes((int)$it['size'])) ?></td>
            <td class="date-cell">
              <?php 
              $time = (int)$it['mtime'];
              $now = time();
              $diff = $now - $time;
              
              if ($diff < 3600) {
                $rel = 'Hace ' . round($diff / 60) . ' min';
              } elseif ($diff < 86400) {
                $rel = 'Hace ' . round($diff / 3600) . ' hs';
              } elseif ($diff < 604800) {
                $rel = 'Hace ' . round($diff / 86400) . ' días';
              } else {
                $rel = date('d/m/Y', $time);
              }
              ?>
              <span class="date-rel"><?= h($rel) ?></span>
              <span class="date-abs"><?= h(date('d/m/Y H:i:s', $time)) ?></span>
            </td>
            <td class="actions-cell t-right">
              <a class="btn btn-ghost btn-sm" 
                 href="backup_download.php?f=<?= urlencode($it['file']) ?>"
                 title="Descargar backup">
                <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                  <polyline points="7 10 12 15 17 10"/>
                  <line x1="12" y1="15" x2="12" y2="3"/>
                </svg>
                Descargar
              </a>

              <button class="btn btn-warning btn-sm btn-restore" type="button" data-file="<?= h($it['file']) ?>" title="Restaurar este backup">
                <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <polyline points="1 4 1 10 7 10"/>
                  <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
                </svg>
                Restaurar
              </button>

              <form method="post" class="inline-form" onsubmit="return confirmarBorrado('<?= h($it['file']) ?>');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="accion" value="borrar">
                <input type="hidden" name="file" value="<?= h($it['file']) ?>">
                <button class="btn btn-danger btn-sm" type="submit" title="Eliminar backup">
                  <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                  </svg>
                  Borrar
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Loading overlay -->
<div id="loadingOverlay" class="loading-overlay" style="display: none;">
  <div class="spinner-container">
    <div class="spinner"></div>
    <p id="loadingText">Creando backup...</p>
  </div>
</div>

<script>
function confirmarBorrado(file) {
  return confirm('¿Estás seguro de que querés eliminar el backup "' + file + '"?\n\nEsta acción no se puede deshacer.');
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
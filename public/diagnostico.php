<?php
// public/diagnostico.php - Diagnóstico del Sistema FLUS
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

require_diagnostics_permission();

require_once __DIR__ . '/../src/diagnostics_lib.php';
require_once __DIR__ . '/../src/audit_events.php';

csrf_token();

$pageTitle = 'Diagnóstico - FLUS';
$currentSection = 'diagnostico';                 // para el menú/active section
$extraCss = ['assets/css/diagnostico.css'];      // ruta real dentro de /public
$extraJs = ['assets/js/diagnostico.js'];

$info = null;
$error = null;
$downloadFile = null;
$activeSessions = [];
$currentSessionId = session_id();
$currentUserId = function_exists('session_user_id')
    ? session_user_id()
    : (int)(($user['id'] ?? 0));
$recentAdminActions = [];

function flus_diag_recent_admin_actions(PDO $pdo, int $limit = 8): array
{
    $limit = max(1, min(50, $limit));

    try {
        $sql = "
            SELECT al.*, u.nombre AS actor_nombre, u.username AS actor_username
            FROM audit_log al
            LEFT JOIN users u ON u.id = al.user_id
            WHERE al.action IN ('SESSION_REVOKE', 'TERMINAL_FORCE_RELEASE')
            ORDER BY al.created_at DESC, al.id DESC
            LIMIT {$limit}
        ";

        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static function (array $row): array {
            $metaRaw = $row['meta'] ?? ($row['meta_json'] ?? null);
            $meta = is_string($metaRaw) ? json_decode($metaRaw, true) : $metaRaw;
            if (!is_array($meta)) {
                $meta = [];
            }

            $row['meta'] = $meta;
            $row['action'] = (string)($row['action'] ?? '');
            $row['entity'] = (string)($row['entity'] ?? '');
            $row['created_at'] = (string)($row['created_at'] ?? '');
            $row['actor_nombre'] = (string)($row['actor_nombre'] ?? '');
            $row['actor_username'] = (string)($row['actor_username'] ?? '');
            return $row;
        }, $rows);
    } catch (Throwable $e) {
        error_log('flus_diag_recent_admin_actions: ' . $e->getMessage());
        return [];
    }
}

function flus_diag_admin_action_label(array $row): string
{
    $action = strtoupper((string)($row['action'] ?? ''));
    if ($action === 'SESSION_REVOKE') {
        return 'Forzó salida';
    }
    if ($action === 'TERMINAL_FORCE_RELEASE') {
        return 'Liberó terminal';
    }
    return $action !== '' ? $action : 'Acción admin';
}

function flus_diag_admin_action_target(array $row): string
{
    $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
    $targetName = trim((string)($meta['target_user_name'] ?? ''));
    $targetUsername = trim((string)($meta['target_username'] ?? ''));
    $terminalName = trim((string)($meta['locked_terminal_name'] ?? $meta['selected_terminal_name'] ?? ''));
    $sessionShort = trim((string)($meta['session_id_short'] ?? ''));

    $parts = [];
    if ($targetName !== '') {
        $parts[] = $targetName;
    } elseif ($targetUsername !== '') {
        $parts[] = '@' . ltrim($targetUsername, '@');
    }

    if ($terminalName !== '') {
        $parts[] = $terminalName;
    }

    if ($sessionShort !== '') {
        $parts[] = $sessionShort;
    }

    return $parts !== [] ? implode(' · ', $parts) : 'Sin detalle';
}

function flus_diag_sessions_payload(PDO $pdo): array
{
    return [
        'sessions' => function_exists('flus_session_list_active') ? flus_session_list_active($pdo, 120) : [],
        'actions' => flus_diag_recent_admin_actions($pdo, 8),
        'generated_at' => date('c'),
    ];
}

// Manejo de acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    
    if (!csrf_verify($token)) {
        $error = 'Token CSRF inválido. Recargá la página e intentá de nuevo.';
    } else {
        $accion = (string)($_POST['accion'] ?? '');
        
        if ($accion === 'generar_paquete') {
            $err = null;
            $zipPath = flus_generate_diagnostic_package();
            if ($zipPath && is_file($zipPath)) {
                $downloadFile = basename($zipPath);
                $info = 'Paquete de diagnóstico generado exitosamente.';
            } else {
                $error = 'Error al generar paquete: ' . ($err ?: 'Error desconocido');
            }
        } elseif ($accion === 'limpiar_paquetes_7d') {
            if (function_exists('flus_cleanup_diagnostic_packages_stats')) {
                $res = flus_cleanup_diagnostic_packages_stats(7);
                $info = "Limpieza >7 días: se eliminaron {$res['deleted_count']} paquete(s) (" . ($res['space_freed_formatted'] ?? '0 B') . " liberados).";
            } else {
                $deleted = flus_cleanup_diagnostic_packages(7);
                $info = "Limpieza >7 días: se eliminaron $deleted paquete(s).";
            }
        } elseif ($accion === 'borrar_todo_paquetes') {
            if (function_exists('flus_cleanup_diagnostic_packages_stats')) {
                $res = flus_cleanup_diagnostic_packages_stats(0);
                $info = "Borrado total: se eliminaron {$res['deleted_count']} paquete(s) (" . ($res['space_freed_formatted'] ?? '0 B') . " liberados).";
            } else {
                $deleted = flus_cleanup_diagnostic_packages(0);
                $info = "Borrado total: se eliminaron $deleted paquete(s).";
            }
        } elseif ($accion === 'eliminar_paquete') {
            $fileToDelete = (string)($_POST['file'] ?? '');
            if ($fileToDelete === '') {
                $error = "Archivo inválido.";
            } elseif (function_exists('flus_delete_diagnostic_package') && flus_delete_diagnostic_package($fileToDelete)) {
                $info = "Paquete eliminado: " . $fileToDelete;
            } else {
                $error = "No se pudo eliminar el paquete (archivo inválido o no existe).";
            }
        } elseif ($accion === 'revocar_sesion') {
            $targetSessionId = trim((string)($_POST['session_id'] ?? ''));
            if ($targetSessionId === '') {
                $error = 'Sesion invalida.';
            } elseif (!function_exists('flus_session_revoke')) {
                $error = 'El registro de sesiones todavia no esta disponible en este entorno.';
            } else {
                $targetSession = function_exists('flus_session_fetch') ? flus_session_fetch($pdo, $targetSessionId) : null;
                flus_session_revoke($pdo, $targetSessionId, $currentUserId, 'Cierre forzado desde diagnostico');
                audit_event('SESSION_REVOKE', AuditEntities::USER, (int)($targetSession['user_id'] ?? 0) ?: null, [
                    'target_user_id' => (int)($targetSession['user_id'] ?? 0),
                    'target_user_name' => (string)($targetSession['user_nombre'] ?? $targetSession['display_name'] ?? ''),
                    'target_username' => (string)($targetSession['username'] ?? ''),
                    'session_id' => $targetSessionId,
                    'session_id_short' => substr($targetSessionId, 0, 12) . '...',
                    'reason' => 'Cierre forzado desde diagnostico',
                ], $currentUserId);
                if ($targetSessionId === $currentSessionId) {
                    header('Location: logout.php?reason=revoked');
                    exit;
                }
                $info = 'Sesion revocada y terminal liberada si estaba en uso.';
            }
        } elseif ($accion === 'liberar_terminal_sesion') {
            $targetSessionId = trim((string)($_POST['session_id'] ?? ''));
            if ($targetSessionId === '') {
                $error = 'Sesion invalida.';
            } elseif (!function_exists('terminal_lock_release_by_session')) {
                $error = 'La liberacion de terminales no esta disponible en este entorno.';
            } else {
                $targetSessions = function_exists('flus_session_list_active') ? flus_session_list_active($pdo, 120) : [];
                $targetSession = null;
                foreach ($targetSessions as $sessionRow) {
                    if ((string)($sessionRow['session_id'] ?? '') === $targetSessionId) {
                        $targetSession = $sessionRow;
                        break;
                    }
                }
                $released = terminal_lock_release_by_session($pdo, $targetSessionId);
                if (function_exists('flus_session_update_selected_terminal')) {
                    flus_session_update_selected_terminal($pdo, $targetSessionId, null);
                }
                audit_event('TERMINAL_FORCE_RELEASE', AuditEntities::TERMINAL, (int)($targetSession['locked_terminal_id'] ?? $targetSession['selected_terminal_id'] ?? 0) ?: null, [
                    'target_user_id' => (int)($targetSession['user_id'] ?? 0),
                    'target_user_name' => (string)($targetSession['display_name'] ?? ''),
                    'target_username' => (string)($targetSession['username'] ?? ''),
                    'session_id' => $targetSessionId,
                    'session_id_short' => substr($targetSessionId, 0, 12) . '...',
                    'selected_terminal_id' => (int)($targetSession['selected_terminal_id'] ?? 0),
                    'selected_terminal_name' => (string)($targetSession['selected_terminal_nombre'] ?? ''),
                    'locked_terminal_id' => (int)($targetSession['locked_terminal_id'] ?? 0),
                    'locked_terminal_name' => (string)($targetSession['locked_terminal_nombre'] ?? ''),
                    'released_rows' => $released,
                ], $currentUserId);
                $info = $released > 0
                    ? 'Terminal liberada para la sesion seleccionada.'
                    : 'La sesion no tenia una terminal bloqueada en este momento.';
            }
        }
    }
}

// Obtener health check
$health = flus_health_check();
$schema = flus_get_schema_summary();
$config = flus_get_sanitized_config();

// Checks adicionales (si existen en la lib)
$schemaIntegrity = function_exists('flus_check_schema_integrity') ? flus_check_schema_integrity() : null;
$securityConfig  = function_exists('flus_check_security_config') ? flus_check_security_config() : null;
$criticalFiles   = function_exists('flus_check_critical_files') ? flus_check_critical_files() : null;
$recentErrors    = function_exists('flus_analyze_recent_errors') ? flus_analyze_recent_errors(24) : null;
$overview = function_exists('flus_build_diagnostic_overview')
    ? flus_build_diagnostic_overview($health, $schemaIntegrity, $securityConfig, $criticalFiles, $recentErrors)
    : ['status' => 'ok', 'message' => 'Todos los sistemas funcionan correctamente', 'restore_in_progress' => false, 'maintenance_active' => false];

// Contar paquetes existentes
$diagDir = FLUS_ROOT . '/storage/diagnostics';
$existingPackages = [];
if (is_dir($diagDir)) {
    $packageFiles = array_merge(
        glob($diagDir . '/flus_diagnostic_*.zip') ?: [],
        glob($diagDir . '/flus_diagnostico_*.zip') ?: []
    );
    foreach ($packageFiles as $file) {
        $existingPackages[] = [
            'file' => basename($file),
            'size' => filesize($file),
            'mtime' => filemtime($file),
            'age_days' => (time() - filemtime($file)) / 86400,
        ];
    }
    usort($existingPackages, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
}

if (function_exists('flus_session_list_active')) {
    $activeSessions = flus_session_list_active($pdo, 120);
}
$recentAdminActions = flus_diag_recent_admin_actions($pdo, 8);

if (isset($_GET['panel']) && (string)$_GET['panel'] === 'sessions_json') {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode(flus_diag_sessions_payload($pdo), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require __DIR__ . '/partials/header.php';
?>


<div class="panel">
    <div id="diagSessionsConfig"
         data-endpoint="diagnostico.php?panel=sessions_json"
         data-current-session-id="<?= h($currentSessionId) ?>"
         hidden></div>

    <header class="panel-head page-header module-header">
        <div class="page-header-main module-header-main">
            <div class="module-header-hero">
                <span class="module-header-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                        <path d="M3 12h4l2-6 4 12 2-6h6"/>
                    </svg>
                </span>
                <div class="module-header-copy">
                    <span class="module-eyebrow">Salud del sistema</span>
                    <h1 class="page-title">Diagnóstico del Sistema</h1>
                    <p class="page-sub panel-subtitle">Estado de salud y herramientas de soporte técnico.</p>
                </div>
            </div>
        </div>
        <div class="bk-actions module-header-actions">
            <form method="post" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="accion" value="generar_paquete">
                <button class="btn btn-primary" type="submit">
                    <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Generar Paquete de Soporte
                </button>
            </form>
        </div>
    </header>

    <div class="bk-note mt-2">
        <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="16" x2="12" y2="12"/>
            <line x1="12" y1="8" x2="12.01" y2="8"/>
        </svg>
        <div>
            <strong>Atención:</strong> el paquete de soporte ahora sale en modo compartible: incluye logs saneados y metadata técnica resumida. Igual revisalo antes de compartirlo fuera del equipo de soporte.
        </div>
    </div>

    <?php if ($info): ?>
        <div class="alert alert-ok">
            <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
            <span>
                <?= h($info) ?>
                <?php if ($downloadFile): ?>
                    <a href="diagnostico_download.php?f=<?= urlencode($downloadFile) ?>" class="btn btn-sm btn-ghost ml-1">
                        Descargar
                    </a>
                <?php endif; ?>
            </span>
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

    <!-- Estado General -->
    <?php
    $overallStatus = (string)($overview['status'] ?? 'ok');
    $statusMsg = (string)($overview['message'] ?? 'Todos los sistemas funcionan correctamente');
    $restoreInProgress = !empty($overview['restore_in_progress']);
    $maintenanceActive = !empty($overview['maintenance_active']);
    ?>
    <div class="overall-status">
        <div class="overall-icon <?= $overallStatus ?>">
            <?php if ($overallStatus === 'ok'): ?>
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
            <?php elseif ($overallStatus === 'warning'): ?>
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
            <?php else: ?>
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="15" y1="9" x2="9" y2="15"/>
                    <line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
            <?php endif; ?>
        </div>
        <div class="overall-text">
            <h2>Estado del Sistema: 
                <span class="status-badge <?= $overallStatus ?>">
                    <?= $overallStatus === 'ok' ? 'Saludable' : ($overallStatus === 'warning' ? 'Advertencia' : 'Error') ?>
                </span>
            </h2>
            <p><?= h($statusMsg) ?></p>
        </div>
    </div>

    <!-- Grid de diagnósticos -->
    <div class="diag-grid">
        <!-- Base de Datos -->
        <div class="health-card">
            <h3>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <ellipse cx="12" cy="5" rx="9" ry="3"/>
                    <path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/>
                    <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
                </svg>
                Base de Datos
            </h3>
            <div class="health-items">
                <div class="health-item">
                    <span class="health-label">Conexión</span>
                    <span class="health-value <?= $health['database']['connected'] ? 'ok' : 'error' ?>">
                        <?= $health['database']['connected'] ? '✓ Conectado' : '✗ Error' ?>
                    </span>
                </div>
                <div class="health-item">
                    <span class="health-label">Host</span>
                    <span class="health-value"><?= h($health['database']['host'] ?? 'N/A') ?></span>
                </div>
                <div class="health-item">
                    <span class="health-label">DB (config)</span>
                    <span class="health-value"><?= h($health['database']['name'] ?? 'N/A') ?></span>
                </div>
                <div class="health-item">
                    <span class="health-label">DB (seleccionada)</span>
                    <?php
                        $dbCfgName = $health['database']['name'] ?? null;
                        $dbSelName = $health['database']['selected_db'] ?? null;
                        $dbMismatch2 = ($health['database']['connected'] ?? false) && $dbCfgName && $dbSelName && strcasecmp((string)$dbCfgName, (string)$dbSelName) !== 0;
                    ?>
                    <span class="health-value <?= $dbMismatch2 ? 'error' : 'ok' ?>">
                        <?= h($dbSelName ?? 'Ninguna') ?>
                        <?php if ($dbMismatch2): ?>
                            <span class="chip error ml-05">MISMATCH</span>
                        <?php endif; ?>
                    </span>
                </div>

                <?php if (!empty($health['database']['error'])): ?>
                <div class="health-item">
                    <span class="health-label">Error</span>
                    <span class="health-value error text-xs">
                        <?= h($health['database']['error']) ?>
                    </span>
                </div>
                <?php endif; ?>

                <?php if ($health['database']['connected']): ?>
                <div class="health-item">
                    <span class="health-label">Versión MySQL</span>
                    <span class="health-value"><?= h($health['database']['version'] ?? 'N/A') ?></span>
                </div>
                <div class="health-item">
                    <span class="health-label">Latencia</span>
                    <span class="health-value <?= ($health['database']['latency_ms'] ?? 0) < 50 ? 'ok' : 'warning' ?>">
                        <?= h($health['database']['latency_ms'] ?? 0) ?> ms
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tablas Críticas -->
        <div class="health-card">
            <h3>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                    <line x1="3" y1="9" x2="21" y2="9"/>
                    <line x1="9" y1="21" x2="9" y2="9"/>
                </svg>
                Tablas Críticas
            </h3>
            <div class="health-items">
                <div class="health-item">
                    <span class="health-label">Tablas OK</span>
                    <span class="health-value ok"><?= $health['critical_tables']['existing_count'] ?? 0 ?></span>
                </div>
                <div class="health-item">
                    <span class="health-label">Tablas Faltantes</span>
                    <span class="health-value <?= ($health['critical_tables']['missing_count'] ?? 0) > 0 ? 'error' : 'ok' ?>">
                        <?= $health['critical_tables']['missing_count'] ?? 0 ?>
                    </span>
                </div>
                <?php if (!empty($health['critical_tables']['check_failed'])): ?>
                <div class="health-item">
                    <span class="health-label">Chequeo</span>
                    <span class="health-value error text-xs">
                        Falló: <?= h($health['critical_tables']['error'] ?? 'Error desconocido') ?>
                    </span>
                </div>
                <?php elseif (!empty($health['critical_tables']['missing'])): ?>
                <div class="health-item">
                    <span class="health-label">Faltantes</span>
                    <span class="health-value error text-xs">
                        <?= h(implode(', ', $health['critical_tables']['missing'])) ?>
                    </span>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- Espacio en Disco -->
        <div class="health-card">
            <h3>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                </svg>
                Espacio en Disco
            </h3>
            <div class="health-items">
                <div class="health-item">
                    <span class="health-label">Total</span>
                    <span class="health-value"><?= h($health['disk']['total_formatted'] ?? 'N/A') ?></span>
                </div>
                <div class="health-item">
                    <span class="health-label">Usado</span>
                    <span class="health-value"><?= h($health['disk']['used_formatted'] ?? 'N/A') ?></span>
                </div>
                <div class="health-item">
                    <span class="health-label">Libre</span>
                    <span class="health-value <?= ($health['disk']['used_percent'] ?? 0) > 90 ? 'error' : (($health['disk']['used_percent'] ?? 0) > 80 ? 'warning' : 'ok') ?>">
                        <?= h($health['disk']['free_formatted'] ?? 'N/A') ?> (<?= 100 - ($health['disk']['used_percent'] ?? 0) ?>%)
                    </span>
                </div>
            </div>
        </div>

        <!-- Permisos de Storage -->
        <div class="health-card">
            <h3>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
                </svg>
                Permisos de Storage
            </h3>
            <div class="health-items">
                <?php $storagePerms = is_array($health['storage_permissions'] ?? null) ? $health['storage_permissions'] : []; ?>
                <?php foreach ($storagePerms as $dir => $perms): ?>
                <div class="health-item">
                    <span class="health-label"><?= h(basename($dir)) ?></span>
                    <?php $r = (bool)($perms['readable'] ?? false); $w = (bool)($perms['writable'] ?? false); ?>
                    <span class="health-value <?= ($r && $w) ? 'ok' : 'error' ?>">
                        <?= $r ? 'R' : '-' ?><?= $w ? 'W' : '-' ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php $lb = is_array($health['last_backup'] ?? null) ? $health['last_backup'] : []; ?>
        <?php $locksArr = is_array($health['active_locks'] ?? null) ? $health['active_locks'] : []; $locksCount = count($locksArr); ?>
        <?php $maint = is_array($health['maintenance'] ?? null) ? $health['maintenance'] : ['active' => false, 'reason' => null]; ?>

        <!-- Último Backup -->
        <div class="health-card">
            <h3>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                    <polyline points="17 8 12 3 7 8"/>
                    <line x1="12" y1="3" x2="12" y2="15"/>
                </svg>
                Backups
            </h3>
            <div class="health-items">
                <div class="health-item">
                    <span class="health-label">Último backup</span>
                    <span class="health-value <?= ($lb['days_ago'] ?? 999) > 7 ? 'warning' : 'ok' ?>">
                        <?php if (!empty($lb['file'])): ?>
                            Hace <?= (int)($lb['days_ago'] ?? 999) ?> días
                        <?php else: ?>
                            <span class="warning">Sin backups</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="health-item">
                    <span class="health-label">Total backups</span>
                    <span class="health-value"><?= (int)($lb['count'] ?? 0) ?></span>
                </div>
                <div class="health-item">
                    <span class="health-label">Espacio usado</span>
                    <span class="health-value"><?= h($lb['total_size_formatted'] ?? '0 B') ?></span>
                </div>
            </div>
        </div>

        <!-- Locks Activos -->
        <div class="health-card">
            <h3>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
                Estado del Sistema
            </h3>
            <div class="health-items">
                <div class="health-item">
                    <span class="health-label">Mantenimiento</span>
                    <span class="health-value <?= !empty($maint['active']) ? 'warning' : 'ok' ?>">
                        <?= !empty($maint['active']) ? '⚠ Activo' : '✓ Inactivo' ?>
                    </span>
                </div>
                <div class="health-item">
                    <span class="health-label">Restore activo</span>
                    <span class="health-value <?= $restoreInProgress ? 'warning' : 'ok' ?>">
                        <?= $restoreInProgress ? '⚠ En curso' : '✓ No' ?>
                    </span>
                </div>
                <div class="health-item">
                    <span class="health-label">Locks activos</span>
                    <span class="health-value <?= $locksCount > 0 ? 'warning' : 'ok' ?>">
                        <?= $locksCount ?>
                    </span>
                </div>
                <?php if (!empty($maint['active']) && !empty($maint['reason'])): ?>
                <div class="health-item">
                    <span class="health-label">Razón</span>
                    <span class="health-value text-xs"><?= h($maint['reason']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

<!-- PHP Extensiones -->
<div class="health-card">
    <h3>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M4 7h16"/>
            <path d="M4 12h16"/>
            <path d="M4 17h16"/>
        </svg>
        PHP Extensiones
    </h3>
    <?php
        $phpExt = $health['php_extensions'] ?? [];
        $missReq = is_array($phpExt['missing_required'] ?? null) ? $phpExt['missing_required'] : [];
        $missRec = is_array($phpExt['missing_recommended'] ?? null) ? $phpExt['missing_recommended'] : [];
        $reqMap = is_array($phpExt['required'] ?? null) ? $phpExt['required'] : [];
    ?>
    <div class="health-items">
        <div class="health-item">
            <span class="health-label">Requeridas</span>
            <span class="health-value <?= empty($missReq) ? 'ok' : 'error' ?>">
                <?= empty($missReq) ? '✓ OK' : '✗ Faltan ' . count($missReq) ?>
            </span>
        </div>
        <?php if (!empty($missReq)): ?>
        <div class="health-item">
            <span class="health-label">Faltan</span>
            <span class="health-value error text-xs">
                <?= h(implode(', ', $missReq)) ?>
            </span>
        </div>
        <?php endif; ?>
        <div class="health-item">
            <span class="health-label">Recomendadas</span>
            <span class="health-value <?= empty($missRec) ? 'ok' : 'warning' ?>">
                <?= empty($missRec) ? '✓ OK' : '⚠ Faltan ' . count($missRec) ?>
            </span>
        </div>
        <?php if (!empty($missRec)): ?>
        <div class="health-item">
            <span class="health-label">Opcional</span>
            <span class="health-value warning text-xs">
                <?= h(implode(', ', array_slice($missRec, 0, 6))) ?><?= count($missRec) > 6 ? '…' : '' ?>
            </span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- PHP Configuración -->
<div class="health-card">
    <h3>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 1v2"/>
            <path d="M12 21v2"/>
            <path d="M4.22 4.22l1.42 1.42"/>
            <path d="M18.36 18.36l1.42 1.42"/>
            <path d="M1 12h2"/>
            <path d="M21 12h2"/>
            <path d="M4.22 19.78l1.42-1.42"/>
            <path d="M18.36 5.64l1.42-1.42"/>
            <circle cx="12" cy="12" r="3"/>
        </svg>
        PHP Configuración
    </h3>
    <?php
        $ini = $health['php_ini'] ?? [];
        $checks = is_array($ini['checks'] ?? null) ? $ini['checks'] : [];
        $keys = ['memory_limit','max_execution_time','post_max_size','upload_max_filesize','max_input_vars','date.timezone'];
    ?>
    <div class="health-items">
        <?php foreach ($keys as $k):
            $c = $checks[$k] ?? null;
            $ok = is_array($c) ? (bool)($c['ok'] ?? false) : true;
            $sev = is_array($c) ? (string)($c['severity'] ?? 'info') : 'info';
            $cls = $ok ? 'ok' : ($sev === 'warning' ? 'warning' : 'warning');
        ?>
        <div class="health-item">
            <span class="health-label"><?= h($k) ?></span>
            <span class="health-value <?= $ok ? 'ok' : ($sev === 'warning' ? 'warning' : 'warning') ?>">
                <?= h(is_array($c) ? (string)($c['value'] ?? '') : '') ?>
                <?php if (!$ok): ?>
                    <span class="chip <?= $sev === 'warning' ? 'warning' : 'warning' ?> ml-05">
                        <?= h(is_array($c) ? (string)($c['recommended'] ?? '') : '') ?>
                    </span>
                <?php endif; ?>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

    </div>


<!-- Alertas (resumen de logs / hints de esquema) -->
<div class="health-card mb-2">
    <h3>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
            <line x1="12" y1="9" x2="12" y2="13"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        Alertas en logs
    </h3>

    <p class="diag-note">Referencia operativa: esta tarjeta resume la cola reciente de logs. El estado general prioriza errores críticos recientes y problemas activos.</p>

    <?php
        $logSum = is_array($health['log_summary'] ?? null) ? $health['log_summary'] : [];
        $appSum = is_array($logSum['app'] ?? null) ? $logSum['app'] : ['errors'=>0,'warnings'=>0,'top'=>[]];
        $phpSum = is_array($logSum['php'] ?? null) ? $logSum['php'] : ['fatals'=>0,'warnings'=>0,'notices'=>0,'top'=>[]];
        $schemaHints = is_array($health['schema_hints'] ?? null) ? $health['schema_hints'] : [];
    ?>

    <div class="health-items">
        <div class="health-item">
            <span class="health-label">app.log</span>
            <span class="health-value <?= ((int)($appSum['errors'] ?? 0)) > 0 ? 'error' : (((int)($appSum['warnings'] ?? 0)) > 0 ? 'warning' : 'ok') ?>">
                <?= (int)($appSum['errors'] ?? 0) ?> error(es) • <?= (int)($appSum['warnings'] ?? 0) ?> warning(s)
            </span>
        </div>
        <div class="health-item">
            <span class="health-label">PHP error_log</span>
            <span class="health-value <?= ((int)($phpSum['fatals'] ?? 0)) > 0 ? 'error' : (((int)($phpSum['warnings'] ?? 0)) > 0 ? 'warning' : 'ok') ?>">
                <?= (int)($phpSum['fatals'] ?? 0) ?> fatal(es) • <?= (int)($phpSum['warnings'] ?? 0) ?> warning(s) • <?= (int)($phpSum['notices'] ?? 0) ?> notice(s)
            </span>
        </div>
    </div>

    <?php if (!empty($schemaHints)): ?>
        <div class="meta mt-075">
            <strong>Hints de esquema (detectados en logs):</strong>
            <div class="mt-035 lh-16">
                <?php foreach (array_slice($schemaHints, 0, 8) as $h): ?>
                    <span class="chip warning chip-inline"><?= h((string)$h) ?></span>
                <?php endforeach; ?>
                <?php if (count($schemaHints) > 8): ?>
                    <span class="chip ml-025">+<?= count($schemaHints) - 8 ?></span>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($appSum['top'])): ?>
        <div class="meta mt-1">
            <strong>Top mensajes repetidos (app.log):</strong>
            <div class="mt-035">
                <?php foreach (array_slice((array)$appSum['top'], 0, 3) as $it): ?>
                    <?php
                        $lvl = (string)($it['level'] ?? 'INFO');
                        $cls = ($lvl === 'ERROR') ? 'error' : (($lvl === 'WARNING' || $lvl === 'WARN') ? 'warning' : 'ok');
                    ?>
                    <div class="my-025">
                        <span class="chip <?= $cls ?>"><?= h((string)$lvl) ?></span>
                        <span class="chip ml-035"><?= (int)($it['count'] ?? 0) ?>x</span>
                        <span class="ml-035 text-sm2"><?= h((string)($it['label'] ?? '')) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>


<!-- Integridad de esquema (heurística + basada en logs) -->
<?php if (is_array($schemaIntegrity) && (!($schemaIntegrity['ok'] ?? true))): ?>
<div class="health-card mb-2">
    <h3>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M3 3h18v18H3z"/>
            <path d="M3 9h18"/>
            <path d="M9 21V9"/>
        </svg>
        Integridad de esquema
    </h3>
    <div class="health-items">
        <?php $issues = is_array($schemaIntegrity['issues'] ?? null) ? $schemaIntegrity['issues'] : []; ?>
        <?php $warns  = is_array($schemaIntegrity['warnings'] ?? null) ? $schemaIntegrity['warnings'] : []; ?>
        <div class="health-item">
            <span class="health-label">Críticos</span>
            <span class="health-value <?= count($issues) ? 'error' : 'ok' ?>"><?= count($issues) ?></span>
        </div>
        <div class="health-item">
            <span class="health-label">Advertencias</span>
            <span class="health-value <?= count($warns) ? 'warning' : 'ok' ?>"><?= count($warns) ?></span>
        </div>
        <?php if (!empty($schemaIntegrity['notes'])): ?>
        <div class="health-item">
            <span class="health-label">Nota</span>
            <span class="health-value text-xs"><?= h((string)$schemaIntegrity['notes']) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($issues)): ?>
        <div class="health-item">
            <span class="health-label">Detalle</span>
            <span class="health-value error text-xs"><?= h(implode(' | ', array_slice(array_map(fn($x)=>$x['message'] ?? 'issue', $issues), 0, 2))) ?><?= count($issues) > 2 ? '…' : '' ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Seguridad (config) -->
<?php if (is_array($securityConfig) && ((!($securityConfig['secure'] ?? true)) || !empty(($securityConfig['warnings'] ?? [])))): ?>
<div class="health-card mb-2">
    <h3>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M12 1l9 4v6c0 5-3.8 9.7-9 11-5.2-1.3-9-6-9-11V5l9-4z"/>
        </svg>
        Seguridad
    </h3>
    <?php
        $secIssues = array_merge(
            is_array($securityConfig['issues'] ?? null) ? $securityConfig['issues'] : [],
            is_array($securityConfig['warnings'] ?? null) ? $securityConfig['warnings'] : []
        );
    ?>
    <div class="health-items">
        <div class="health-item">
            <span class="health-label">Hallazgos</span>
            <span class="health-value <?= count($secIssues) ? 'warning' : 'ok' ?>"><?= count($secIssues) ?></span>
        </div>
        <?php if (!empty($secIssues)): ?>
        <div class="health-item">
            <span class="health-label">Ejemplos</span>
            <span class="health-value warning text-xs">
                <?= h(implode(' | ', array_slice(array_map(fn($x)=> (string)($x['message'] ?? $x), $secIssues), 0, 2))) ?><?= count($secIssues) > 2 ? '…' : '' ?>
            </span>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Archivos críticos -->
<?php if (is_array($criticalFiles) && !empty(($criticalFiles['issues'] ?? []))): ?>
<div class="health-card mb-2">
    <h3>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
        </svg>
        Archivos críticos
    </h3>
    <div class="health-items">
        <div class="health-item">
            <span class="health-label">Problemas</span>
            <span class="health-value error"><?= count($criticalFiles['issues']) ?></span>
        </div>
        <div class="health-item">
            <span class="health-label">Detalle</span>
            <span class="health-value error text-xs"><?= h(implode(' | ', array_slice($criticalFiles['issues'], 0, 2))) ?><?= count($criticalFiles['issues']) > 2 ? '…' : '' ?></span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Errores recientes (por tiempo) -->
<?php if (is_array($recentErrors) && ((int)($recentErrors['total_critical'] ?? 0) > 0)): ?>
<div class="health-card mb-2">
    <h3>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        Errores críticos recientes
    </h3>
    <div class="health-items">
        <div class="health-item">
            <span class="health-label">Últimas 24h</span>
            <span class="health-value error"><?= (int)($recentErrors['total_critical'] ?? 0) ?></span>
        </div>
        <?php if (!empty($recentErrors['errors'])): ?>
        <div class="health-item">
            <span class="health-label">Ejemplos</span>
            <span class="health-value error text-xs"><?= h(implode(' | ', array_slice(array_map(fn($e)=> (string)($e['message'] ?? ''), $recentErrors['errors']), 0, 2))) ?><?= count($recentErrors['errors']) > 2 ? '…' : '' ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>


<!-- Logs recientes -->
<div class="health-card mb-2">
    <h3>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M4 4h16v16H4z"/>
            <path d="M8 8h8"/>
            <path d="M8 12h8"/>
            <path d="M8 16h6"/>
        </svg>
        Logs recientes (últimas líneas)
    </h3>

    <?php
        $logs = $health['logs'] ?? [];
        $app = is_array($logs['app_log'] ?? null) ? $logs['app_log'] : [];
        $phpErr = is_array($logs['php_error_log'] ?? null) ? $logs['php_error_log'] : [];
    ?>

    <div class="log-panel">
        <div class="log-box">
            <div class="log-box-head">
                <div>
                    <strong>app.log</strong>
                    <div class="meta">
                        <?= h((string)($app['path'] ?? '')) ?>
                        • <?= h(flus_format_bytes((int)($app['size'] ?? 0))) ?>
                    </div>
                </div>
                <button class="btn btn-sm btn-ghost" type="button" onclick="flusCopyText('log_app');">Copiar</button>
            </div>
            <pre id="log_app" class="log-pre"><?php
                $lines = is_array($app['tail'] ?? null) ? $app['tail'] : [];
                echo h(implode("\n", $lines) ?: 'Sin datos / no existe / sin permisos.');
            ?></pre>
        </div>

        <div class="log-box">
            <div class="log-box-head">
                <div>
                    <strong>PHP error_log</strong>
                    <div class="meta">
                        <?= h((string)($phpErr['path'] ?? 'No configurado')) ?>
                        • <?= h(flus_format_bytes((int)($phpErr['size'] ?? 0))) ?>
                    </div>
                </div>
                <button class="btn btn-sm btn-ghost" type="button" onclick="flusCopyText('log_php');">Copiar</button>
            </div>
            <pre id="log_php" class="log-pre"><?php
                $lines = is_array($phpErr['tail'] ?? null) ? $phpErr['tail'] : [];
                echo h(implode("\n", $lines) ?: 'Sin datos / no existe / no configurado / sin permisos.');
            ?></pre>
        </div>
    </div>
</div>

<script>
function flusCopyText(elId) {
    try {
        const el = document.getElementById(elId);
        if (!el) return;
        const txt = el.innerText || el.textContent || '';
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(txt);
            return;
        }
        // Fallback
        const ta = document.createElement('textarea');
        ta.value = txt;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    } catch (e) {}
}
</script>

    <!-- Resumen de Schema -->
    <!-- Resumen de Schema -->
    <div class="health-card mb-2">
        <h3>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <line x1="16" y1="13" x2="8" y2="13"/>
                <line x1="16" y1="17" x2="8" y2="17"/>
            </svg>
            Resumen de Base de Datos
        </h3>

        <?php
        $tablesAssoc = $schema['tables'] ?? [];
        if (!is_array($tablesAssoc)) $tablesAssoc = [];

        // Top 10 (manteniendo keys)
        $topTables = array_slice($tablesAssoc, 0, 10, true);
        ?>

        <div class="table-wrap">
            <table class="table schema-table">
                <thead>
                    <tr>
                        <th>Tabla</th>
                        <th class="t-right">Registros</th>
                        <th class="t-right">Tamaño</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($topTables)): ?>
                        <tr>
                            <td colspan="3" class="muted">
                                No se pudo obtener el resumen del esquema.
                                <?= !empty($schema['error']) ? h(' (' . $schema['error'] . ')') : '' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($topTables as $tableName => $meta): ?>
                            <tr>
                                <td><code><?= h((string)$tableName) ?></code></td>
                                <td class="t-right"><?= number_format((int)($meta['rows'] ?? 0)) ?></td>
                                <td class="t-right"><?= h($meta['size_formatted'] ?? '0 B') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if (count($tablesAssoc) > 10): ?>
            <p class="diag-note">
                Mostrando 10 de <?= count($tablesAssoc) ?> tablas. Generá el paquete de diagnóstico para ver todas.
            </p>
        <?php endif; ?>
    </div>


    <div class="health-card mb-2">
        <h3>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
            Sesiones activas y terminales
        </h3>

        <p id="diagSessionsMeta" class="diag-note">Actualizado al cargar la página.</p>

        <?php if (!empty($activeSessions)): ?>
            <div class="table-wrap">
                <table class="table schema-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Sesion</th>
                            <th>Ultima actividad</th>
                            <th>Terminal seleccionada</th>
                            <th>Caja bloqueada</th>
                            <th>IP</th>
                            <th>Ruta</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="diagSessionsBody">
                        <?php foreach ($activeSessions as $sessionRow): ?>
                            <?php
                                $sid = (string)($sessionRow['session_id'] ?? '');
                                $isCurrentSession = $sid !== '' && $sid === $currentSessionId;
                                $displayName = trim((string)($sessionRow['display_name'] ?? ''));
                                if ($displayName === '') {
                                    $displayName = (string)($sessionRow['username'] ?? 'Usuario');
                                }
                                $selectedTerminal = trim((string)($sessionRow['selected_terminal_nombre'] ?? ''));
                                if ($selectedTerminal === '' && (int)($sessionRow['selected_terminal_id'] ?? 0) > 0) {
                                    $selectedTerminal = 'Caja #' . (int)$sessionRow['selected_terminal_id'];
                                }
                                $lockedTerminal = trim((string)($sessionRow['locked_terminal_nombre'] ?? ''));
                                if ($lockedTerminal === '' && (int)($sessionRow['locked_terminal_id'] ?? 0) > 0) {
                                    $lockedTerminal = 'Caja #' . (int)$sessionRow['locked_terminal_id'];
                                }
                            ?>
                            <tr>
                                <td>
                                    <strong><?= h($displayName) ?></strong><br>
                                    <span class="muted">@<?= h((string)($sessionRow['username'] ?? '')) ?></span>
                                    <?php if ($isCurrentSession): ?>
                                        <span class="chip ok ml-05">actual</span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= h($sid !== '' ? substr($sid, 0, 12) . '...' : '-') ?></code></td>
                                <td><?= h((string)($sessionRow['last_seen_at'] ?? '')) ?></td>
                                <td><?= h($selectedTerminal !== '' ? $selectedTerminal : 'Sin terminal') ?></td>
                                <td><?= h($lockedTerminal !== '' ? $lockedTerminal : 'Libre') ?></td>
                                <td><?= h((string)($sessionRow['ip_address'] ?? '-')) ?></td>
                                <td><code><?= h((string)($sessionRow['last_path'] ?? '-')) ?></code></td>
                                <td>
                                    <div class="pkg-actions">
                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="accion" value="liberar_terminal_sesion">
                                            <input type="hidden" name="session_id" value="<?= h($sid) ?>">
                                            <button type="submit" class="btn btn-sm btn-secondary">Liberar terminal</button>
                                        </form>
                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="accion" value="revocar_sesion">
                                            <input type="hidden" name="session_id" value="<?= h($sid) ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Forzar cierre de sesion para este usuario?');">
                                                Forzar salida
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p id="diagSessionsEmpty" class="muted">No hay sesiones activas registradas en las ultimas 2 horas.</p>
        <?php endif; ?>
    </div>    <div class="health-card mb-2">
        <h3>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 20v-6"/>
                <path d="M6 20v-4"/>
                <path d="M18 20v-8"/>
                <path d="M4 4h16"/>
            </svg>
            Acciones admin recientes
        </h3>

        <div id="diagAdminActions" class="packages-list">
            <?php if (!empty($recentAdminActions)): ?>
                <?php foreach ($recentAdminActions as $auditRow): ?>
                    <?php
                        $actorName = trim((string)($auditRow['actor_nombre'] ?? ''));
                        if ($actorName === '') {
                            $actorName = trim((string)($auditRow['actor_username'] ?? 'Sistema'));
                        }
                    ?>
                    <div class="package-item">
                        <div class="package-info">
                            <div>
                                <div><strong><?= h(flus_diag_admin_action_label($auditRow)) ?></strong></div>
                                <div class="package-meta">
                                    <?= h($actorName) ?> � <?= h(flus_diag_admin_action_target($auditRow)) ?> � <?= h((string)($auditRow['created_at'] ?? '')) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="muted">Todavia no hay acciones administrativas recientes sobre sesiones o terminales.</p>
            <?php endif; ?>
        </div>
    </div>
    <!-- Paquetes de diagnóstico -->
<div class="health-card">
    <h3>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
        </svg>
        Paquetes de diagnóstico
    </h3>

    <?php if (!empty($existingPackages)): ?>
        <div class="packages-list">
            <?php foreach ($existingPackages as $pkg): ?>
                <div class="package-item">
                    <div class="package-info">
                        <svg class="icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 8v13H3V8"/>
                            <path d="M1 3h22v5H1z"/>
                            <path d="M10 12h4"/>
                        </svg>
                        <div>
                            <div><strong><?= h($pkg['file']) ?></strong></div>
                            <div class="package-meta">
                                <?= h(flus_format_bytes((int)$pkg['size'])) ?>
                                • <?= h(date('Y-m-d H:i', (int)$pkg['mtime'])) ?>
                                • <?= h(number_format((float)($pkg['age_days'] ?? 0), 1)) ?> días
                            </div>
                        </div>
                    </div>
                    <div class="pkg-actions">
                        <a href="diagnostico_download.php?f=<?= urlencode($pkg['file']) ?>" class="btn btn-sm btn-ghost">Descargar</a>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="accion" value="eliminar_paquete">
                            <input type="hidden" name="file" value="<?= h($pkg['file']) ?>">
                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar este paquete?');">Eliminar</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="pkg-clean-actions mt-1">
            <form method="post" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="accion" value="limpiar_paquetes_7d">
                <button class="btn btn-sm btn-secondary" type="submit" onclick="return confirm('¿Eliminar paquetes de diagnóstico con más de 7 días?');">
                    Limpiar &gt; 7 días
                </button>
            </form>

            <form method="post" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="accion" value="borrar_todo_paquetes">
                <button class="btn btn-sm btn-danger" type="submit" onclick="return confirm('⚠️ ¿Borrar TODOS los paquetes de diagnóstico?');">
                    Borrar todo
                </button>
            </form>
        </div>
    <?php else: ?>
        <p class="muted">No hay paquetes de diagnóstico generados todavía.</p>
    <?php endif; ?>
</div>

<!-- Información del Sistema -->
    <div class="bk-note mt-2">
        <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="16" x2="12" y2="12"/>
            <line x1="12" y1="8" x2="12.01" y2="8"/>
        </svg>
        <div>
            <strong>Información del Sistema:</strong>
            FLUS v<?= h($health['version'] ?? 'N/A') ?> (Build <?= h($health['build'] ?? 'N/A') ?>) •
            PHP <?= h(PHP_VERSION) ?> •
            <?= h(PHP_OS) ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>




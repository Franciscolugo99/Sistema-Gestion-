<?php
// public/diagnostico.php - Diagnóstico del Sistema FLUS
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

if (!user_has_permission('gestionar_backups')) {
    http_response_code(403);
    echo 'No tenés permisos para acceder a esta sección.';
    exit;
}

require_once __DIR__ . '/../src/diagnostics_lib.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle = 'Diagnóstico - FLUS';
$currentSection = 'diagnostico';
$extraCss = [];
$extraJs = [];

$info = null;
$error = null;
$downloadFile = null;

// Manejo de acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
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
        } elseif ($accion === 'limpiar_paquetes') {
            $deleted = flus_cleanup_diagnostic_packages(0); // Eliminar todos
            $info = "Se eliminaron $deleted paquetes de diagnóstico antiguos.";
        }
    }
}

// Obtener health check
$health = flus_health_check();
$schema = flus_get_schema_summary();
$config = flus_get_sanitized_config();

// Contar paquetes existentes
$diagDir = FLUS_ROOT . '/storage/diagnostics';
$existingPackages = [];
if (is_dir($diagDir)) {
    foreach (glob($diagDir . '/flus_diagnostic_*.zip') as $file) {
        $existingPackages[] = [
            'file' => basename($file),
            'size' => filesize($file),
            'mtime' => filemtime($file)
        ];
    }
    usort($existingPackages, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
}

require __DIR__ . '/partials/header.php';
?>

<style>
.diag-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}
.health-card {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 12px;
    padding: 1.25rem;
}
.health-card h3 {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--text-muted, #6b7280);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin: 0 0 1rem 0;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.health-items {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
.health-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--border-light, #f3f4f6);
}
.health-item:last-child {
    border-bottom: none;
}
.health-label {
    font-size: 0.875rem;
    color: var(--text-color, #374151);
}
.health-value {
    font-size: 0.875rem;
    font-weight: 500;
}
.health-value.ok { color: #059669; }
.health-value.warning { color: #d97706; }
.health-value.error { color: #dc2626; }

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
}
.status-badge.ok {
    background: #d1fae5;
    color: #065f46;
}
.status-badge.warning {
    background: #fef3c7;
    color: #92400e;
}
.status-badge.error {
    background: #fee2e2;
    color: #991b1b;
}

.overall-status {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.5rem;
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 12px;
    margin-bottom: 2rem;
}
.overall-icon {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.overall-icon.ok { background: #d1fae5; color: #059669; }
.overall-icon.warning { background: #fef3c7; color: #d97706; }
.overall-icon.error { background: #fee2e2; color: #dc2626; }
.overall-text h2 {
    margin: 0 0 0.25rem 0;
    font-size: 1.25rem;
}
.overall-text p {
    margin: 0;
    color: var(--text-muted, #6b7280);
}

.schema-table {
    font-size: 0.8125rem;
}
.schema-table th, .schema-table td {
    padding: 0.5rem 0.75rem;
}
.packages-list {
    margin-top: 1rem;
}
.package-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem;
    background: var(--bg-light, #f9fafb);
    border-radius: 8px;
    margin-bottom: 0.5rem;
}
.package-info {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.package-info .icon {
    color: var(--text-muted);
}
.package-meta {
    font-size: 0.75rem;
    color: var(--text-muted);
}
</style>

<div class="panel">
    <div class="panel-head">
        <div>
            <h1>Diagnóstico del Sistema</h1>
            <p class="panel-subtitle">Estado de salud y herramientas de soporte técnico</p>
        </div>
        <div class="bk-actions">
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
                    <a href="diagnostico_download.php?f=<?= urlencode($downloadFile) ?>" class="btn btn-sm btn-ghost" style="margin-left: 1rem;">
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
    $overallStatus = 'ok';
    $statusMsg = 'Todos los sistemas funcionan correctamente';
    
    if (!$health['database']['connected'] || $health['critical_tables']['missing_count'] > 0) {
        $overallStatus = 'error';
        $statusMsg = 'Se detectaron problemas críticos';
    } elseif ($health['disk']['used_percent'] > 90 || !empty($health['active_locks'])) {
        $overallStatus = 'warning';
        $statusMsg = 'Se detectaron advertencias';
    }
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
                <?php if (!empty($health['critical_tables']['missing'])): ?>
                <div class="health-item">
                    <span class="health-label">Faltantes</span>
                    <span class="health-value error" style="font-size: 0.75rem;">
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
                <?php foreach ($health['storage_permissions'] as $dir => $perms): ?>
                <div class="health-item">
                    <span class="health-label"><?= h(basename($dir)) ?></span>
                    <span class="health-value <?= ($perms['readable'] && $perms['writable']) ? 'ok' : 'error' ?>">
                        <?= $perms['readable'] ? 'R' : '-' ?><?= $perms['writable'] ? 'W' : '-' ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

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
                    <span class="health-value <?= ($health['last_backup']['days_ago'] ?? 999) > 7 ? 'warning' : 'ok' ?>">
                        <?php if ($health['last_backup']['file']): ?>
                            Hace <?= $health['last_backup']['days_ago'] ?> días
                        <?php else: ?>
                            <span class="warning">Sin backups</span>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="health-item">
                    <span class="health-label">Total backups</span>
                    <span class="health-value"><?= $health['last_backup']['count'] ?? 0 ?></span>
                </div>
                <div class="health-item">
                    <span class="health-label">Espacio usado</span>
                    <span class="health-value"><?= h($health['last_backup']['total_size_formatted'] ?? '0 B') ?></span>
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
                    <span class="health-value <?= $health['maintenance']['active'] ? 'warning' : 'ok' ?>">
                        <?= $health['maintenance']['active'] ? '⚠ Activo' : '✓ Inactivo' ?>
                    </span>
                </div>
                <div class="health-item">
                    <span class="health-label">Locks activos</span>
                    <span class="health-value <?= count($health['active_locks']) > 0 ? 'warning' : 'ok' ?>">
                        <?= count($health['active_locks']) ?>
                    </span>
                </div>
                <?php if ($health['maintenance']['active'] && !empty($health['maintenance']['reason'])): ?>
                <div class="health-item">
                    <span class="health-label">Razón</span>
                    <span class="health-value" style="font-size: 0.75rem;"><?= h($health['maintenance']['reason']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Resumen de Schema -->
    <!-- Resumen de Schema -->
    <div class="health-card" style="margin-bottom: 2rem;">
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
                            <td colspan="3" style="color: var(--text-muted);">
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
            <p style="margin: 1rem 0 0; font-size: 0.875rem; color: var(--text-muted);">
                Mostrando 10 de <?= count($tablesAssoc) ?> tablas. Generá el paquete de diagnóstico para ver todas.
            </p>
        <?php endif; ?>
    </div>


    <!-- Paquetes de diagnóstico existentes -->
    <?php if (!empty($existingPackages)): ?>
    <div class="health-card">
        <h3>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            Paquetes de Diagnóstico Generados
        </h3>
        <div class="packages-list">
            <?php foreach ($existingPackages as $pkg): ?>
            <div class="package-item">
                <div class="package-info">
                    <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 8v13H3V8"/>
                        <path d="M1 3h22v5H1z"/>
                        <path d="M10 12h4"/>
                    </svg>
                    <div>
                        <div><?= h($pkg['file']) ?></div>
                        <div class="package-meta">
                            <?= h(flus_format_bytes((int)$pkg['size'])) ?>• 
                            <?= h(date('d/m/Y H:i', $pkg['mtime'])) ?>
                        </div>
                    </div>
                </div>
                <a href="diagnostico_download.php?f=<?= urlencode($pkg['file']) ?>" class="btn btn-sm btn-ghost">
                    <svg class="icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                        <polyline points="7 10 12 15 17 10"/>
                        <line x1="12" y1="15" x2="12" y2="3"/>
                    </svg>
                    Descargar
                </a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (count($existingPackages) > 1): ?>
        <form method="post" style="margin-top: 1rem;">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="accion" value="limpiar_paquetes">
            <button type="submit" class="btn btn-sm btn-ghost" onclick="return confirm('¿Eliminar todos los paquetes de diagnóstico?');">
                Limpiar todos
            </button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Información del Sistema -->
    <div class="bk-note" style="margin-top: 2rem;">
        <svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <line x1="12" y1="16" x2="12" y2="12"/>
            <line x1="12" y1="8" x2="12.01" y2="8"/>
        </svg>
        <div>
            <strong>Información del Sistema:</strong>
            FLUS v<?= h($config['version'] ?? 'N/A') ?> (Build <?= h($config['build'] ?? 'N/A') ?>) •
            PHP <?= h(PHP_VERSION) ?> •
            <?= h(PHP_OS) ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

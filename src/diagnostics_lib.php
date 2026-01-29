<?php
// src/diagnostics_lib.php
declare(strict_types=1);

/**
 * FLUS Diagnostics Library
 * Sistema de diagnóstico y paquete de soporte
 *
 * @version 1.0.1
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/version.php';

/**
 * Obtiene información completa de salud del sistema
 */
function flus_health_check(): array {
    $health = [
        'timestamp'   => date('c'),
        'version'     => defined('FLUS_VERSION') ? FLUS_VERSION : 'unknown',
        'build'       => defined('FLUS_BUILD') ? FLUS_BUILD : 'unknown',
        'php_version' => PHP_VERSION,
        'php_os'      => PHP_OS_FAMILY,
        'status'      => 'OK',
        'issues'      => [],
    ];

    // 1. Verificar DB
    $health['database'] = flus_check_database();

    // ✅ Backward-compat: diagnostico.php viejo suele leer database.connected
    $health['database']['connected'] = (bool)($health['database']['connected'] ?? $health['database']['ok'] ?? false);

    if (!(bool)($health['database']['ok'] ?? false)) {
        $health['status'] = 'ERROR';
        $health['issues'][] = 'Database connection failed';
    }

    // 2. Verificar tablas críticas
    $health['tables'] = flus_check_critical_tables();
    $missingTables = array_filter($health['tables'], fn($v) => !$v);
    if ($missingTables) {
        $health['status'] = 'WARNING';
        $health['issues'][] = 'Missing tables: ' . implode(', ', array_keys($missingTables));
    }

    // 3. Verificar espacio en disco
    $health['disk'] = flus_check_disk_space();
    if (($health['disk']['free_percent'] ?? 100) < 10) {
        $health['status'] = $health['status'] === 'OK' ? 'WARNING' : $health['status'];
        $health['issues'][] = 'Low disk space: ' . ($health['disk']['free_percent'] ?? 'N/A') . '%';
    }

    // 4. Verificar permisos de storage
    $health['storage'] = flus_check_storage_permissions();

    // ✅ Backward-compat: diagnostico.php viejo suele leer storage_permissions directo
    $health['storage_permissions'] = $health['storage']['directories'] ?? [];
    if (!is_array($health['storage_permissions'])) {
        $health['storage_permissions'] = [];
    }

    if (!(bool)($health['storage']['all_ok'] ?? false)) {
        $health['status'] = $health['status'] === 'OK' ? 'WARNING' : $health['status'];
        $health['issues'][] = 'Storage permission issues';
    }

    // 5. Verificar locks activos
    $health['locks'] = flus_check_active_locks();

    // 6. Último backup
    $health['backup'] = flus_check_last_backup();
    if (($health['backup']['days_since'] ?? 999) > 7) {
        $health['status'] = $health['status'] === 'OK' ? 'WARNING' : $health['status'];
        $health['issues'][] = 'No backup in ' . (string)($health['backup']['days_since'] ?? 999) . ' days';
    }

       // 7. Estado de mantenimiento
    $health['maintenance'] = flus_check_maintenance_status();

    // =========================
    // Compatibilidad con public/diagnostico.php
    // =========================

    // critical_tables summary (diagnostico.php lo espera)
    $tablesMap = is_array($health['tables'] ?? null) ? $health['tables'] : [];
    $missing = [];
    $existingCount = 0;

    foreach ($tablesMap as $t => $ok) {
        if ($ok) {
            $existingCount++;
        } else {
            $missing[] = (string)$t;
        }
    }

    $health['critical_tables'] = [
        'existing_count' => $existingCount,
        'missing_count'  => count($missing),
        'missing'        => $missing,
    ];

    // last_backup alias (diagnostico.php lo usa así)
    $health['last_backup'] = [
        'file' => $health['backup']['last_file'] ?? null,
        'days_ago' => $health['backup']['days_since'] ?? 999,
        'count' => $health['backup']['backup_count'] ?? 0,
        'total_size_formatted' => $health['backup']['total_size_formatted'] ?? '0 B',
    ];

    // active_locks alias (evita fatal count())
    $health['active_locks'] = $health['locks']['terminal_locks'] ?? [];
    if (!is_array($health['active_locks'])) {
        $health['active_locks'] = [];
    }

    return $health;

}

/**
 * Verificar conexión a base de datos
 */
function flus_check_database(): array {
    $result = [
        'ok'         => false,
        'connected'  => false, // compat
        'host'       => defined('DB_HOST') ? DB_HOST : 'unknown',
        'name'       => defined('DB_NAME') ? DB_NAME : 'unknown',
        'latency_ms' => null,
        'error'      => null,
        'mysql_version' => 'unknown',
        'version'       => 'unknown', // compat (diagnostico.php usa 'version')
    ];

    try {
        $start = microtime(true);
        $pdo = getPDO();
        $pdo->query('SELECT 1');

        $result['latency_ms'] = round((microtime(true) - $start) * 1000, 2);
        $result['ok'] = true;
        $result['connected'] = true;

        $versionQuery = $pdo->query('SELECT VERSION() as ver');
        if ($versionQuery) {
            $ver = $versionQuery->fetchColumn() ?: 'unknown';
            $result['mysql_version'] = $ver;
            $result['version'] = $ver; // compat
        }
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
    }

    return $result;
}


/**
 * Verificar tablas críticas
 */
function flus_check_critical_tables(): array {
    $tables = [
        'users' => false,
        'productos' => false,
        'ventas' => false,
        'venta_items' => false,
        'terminales' => false,
        'caja_sesiones' => false,
        'movimientos_stock' => false,
        'clientes' => false,
        'proveedores' => false,
        'compras' => false,
        'roles' => false,
        'permissions' => false,
        'audit_log' => false,
    ];

    try {
        $pdo = getPDO();
        foreach (array_keys($tables) as $table) {
            $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$table]);
            $tables[$table] = (bool)$stmt->fetchColumn();
        }
    } catch (Throwable $e) {
        // Dejar todo en false si falla DB
    }

    return $tables;
}

/**
 * Verificar espacio en disco
 */
function flus_check_disk_space(): array {
    $path = defined('FLUS_ROOT') ? FLUS_ROOT : dirname(__DIR__);

    $result = [
        'path' => $path,
        'total' => null,
        'free' => null,
        'used' => null,
        'free_percent' => null,

        'total_formatted' => null,
        'free_formatted' => null,

        // compat con diagnostico.php
        'used_percent' => null,
        'used_formatted' => null,
    ];

    $total = @disk_total_space($path);
    $free  = @disk_free_space($path);

    if ($total !== false && $free !== false && (int)$total > 0) {
        $total = (int)$total;
        $free  = (int)$free;
        $used  = (int)($total - $free);

        $result['total'] = $total;
        $result['free']  = $free;
        $result['used']  = $used;

        $freePct = round(($free / $total) * 100, 1);
        $result['free_percent'] = $freePct;

        $result['total_formatted'] = flus_format_bytes($total);
        $result['free_formatted']  = flus_format_bytes($free);
        $result['used_formatted']  = flus_format_bytes($used);

        $result['used_percent'] = round(100 - $freePct, 1);
    }

    return $result;
}


/**
 * Verificar permisos de storage
 */
function flus_check_storage_permissions(): array {
    $baseDir = defined('FLUS_ROOT') ? FLUS_ROOT : dirname(__DIR__);
    $storageDir = $baseDir . DIRECTORY_SEPARATOR . 'storage';

    $dirs = [
        'storage' => $storageDir,
        'storage/logs' => $storageDir . DIRECTORY_SEPARATOR . 'logs',
        'storage/backups' => $storageDir . DIRECTORY_SEPARATOR . 'backups',
        'storage/cache' => $storageDir . DIRECTORY_SEPARATOR . 'cache',
        'public/img/productos' => $baseDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'productos',
    ];

    $result = [
        'all_ok' => true,
        'directories' => [],
    ];

    foreach ($dirs as $name => $path) {
        $exists   = is_dir($path);
        $readable = $exists ? is_readable($path) : false;
        $writable = $exists ? is_writable($path) : false;

        $result['directories'][$name] = [
            'path'     => $path,
            'exists'   => $exists,
            'readable' => $readable,  // ✅ needed por diagnostico.php
            'writable' => $writable,
            'ok'       => $exists && $readable && $writable,
        ];

        if (!$exists || !$readable || !$writable) {
            $result['all_ok'] = false;
        }
    }

    return $result;
}

/**
 * Verificar locks activos
 */
function flus_check_active_locks(): array {
    $result = [
        'terminal_locks' => [],
        'restore_lock' => false,
        'maintenance_active' => false,
    ];

    try {
        $pdo = getPDO();

        // Terminal locks
        $stmt = $pdo->query("
            SELECT tl.*, t.nombre as terminal_nombre, u.nombre as user_nombre
            FROM terminal_locks tl
            LEFT JOIN terminales t ON tl.terminal_id = t.id
            LEFT JOIN users u ON tl.user_id = u.id
            WHERE tl.locked_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
        ");
        $result['terminal_locks'] = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        // Ignorar si no existe la tabla
    }

    $base = (defined('FLUS_ROOT') ? FLUS_ROOT : dirname(__DIR__));

    // Restore lock
    $lockPath = $base . '/storage/restore.lock';
    $result['restore_lock'] = is_file($lockPath);

    // Maintenance flag
    $maintenancePath = $base . '/storage/maintenance.flag';
    $result['maintenance_active'] = is_file($maintenancePath);

    return $result;
}

/**
 * Verificar último backup
 */
function flus_check_last_backup(): array {
    $result = [
        'last_backup' => null,
        'last_backup_formatted' => null,
        'days_since' => 999,
        'backup_count' => 0,
        'total_size' => 0,
        'total_size_formatted' => null,

        // compat
        'last_file' => null,
    ];

    $backupDir = (defined('FLUS_ROOT') ? FLUS_ROOT : dirname(__DIR__)) . '/storage/backups';
    if (!is_dir($backupDir)) return $result;

    $files = glob($backupDir . '/*.sql') ?: [];
    $result['backup_count'] = count($files);

    $latestTime = null;
    $latestFile = null;
    $totalSize  = 0;

    foreach ($files as $file) {
        $mtime = filemtime($file);
        $size  = filesize($file);

        if ($mtime !== false && ($latestTime === null || $mtime > $latestTime)) {
            $latestTime = $mtime;
            $latestFile = $file;
        }
        if ($size !== false) $totalSize += $size;
    }

    if ($latestTime !== null) {
        $result['last_backup'] = date('c', $latestTime);
        $result['last_backup_formatted'] = date('d/m/Y H:i:s', $latestTime);
        $result['days_since'] = (int)floor((time() - $latestTime) / 86400);
    }

    $result['last_file'] = $latestFile ? basename($latestFile) : null;
    $result['total_size'] = $totalSize;
    $result['total_size_formatted'] = flus_format_bytes($totalSize);

    return $result;
}

/**
 * Verificar estado de mantenimiento
 */
function flus_check_maintenance_status(): array {
    $flagPath = (defined('FLUS_ROOT') ? FLUS_ROOT : dirname(__DIR__)) . '/storage/maintenance.flag';

    $result = [
        'active' => false,
        'since' => null,
        'reason' => null,
        'meta' => null,
    ];

    if (is_file($flagPath)) {
        $result['active'] = true;
        $content = @file_get_contents($flagPath);

        if ($content) {
            $meta = @json_decode($content, true);
            if (is_array($meta)) {
                $result['since'] = $meta['since'] ?? null;
                $result['reason'] = $meta['reason'] ?? null;
                $result['meta'] = $meta;
            }
        }
    }

    return $result;
}

/**
 * Obtener resumen del esquema de base de datos
 */
function flus_get_schema_summary(): array {
    $result = [
        'tables' => [],
        'total_rows' => 0,
        'total_size' => null,
    ];

    try {
        $pdo = getPDO();
        $dbName = defined('DB_NAME') ? DB_NAME : '';

        $stmt = $pdo->prepare("
            SELECT
                TABLE_NAME,
                TABLE_ROWS,
                DATA_LENGTH,
                INDEX_LENGTH,
                AUTO_INCREMENT
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME
        ");
        $stmt->execute([$dbName]);
        $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalSize = 0;
        foreach ($tables as $t) {
            $size = ((int)($t['DATA_LENGTH'] ?? 0)) + ((int)($t['INDEX_LENGTH'] ?? 0));
            $totalSize += $size;
            $result['total_rows'] += (int)($t['TABLE_ROWS'] ?? 0);

            $result['tables'][$t['TABLE_NAME']] = [
                'rows' => (int)($t['TABLE_ROWS'] ?? 0),
                'size' => $size,
                'size_formatted' => flus_format_bytes($size),
                'auto_increment' => $t['AUTO_INCREMENT'] ?? null,
            ];
        }

        $result['total_size'] = $totalSize;
        $result['total_size_formatted'] = flus_format_bytes($totalSize);

    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
    }

    return $result;
}

/**
 * Obtener logs recientes
 */
function flus_get_recent_logs(int $lines = 100): array {
    $logPath = (defined('FLUS_ROOT') ? FLUS_ROOT : dirname(__DIR__)) . '/storage/logs/app.log';

    $result = [
        'path' => $logPath,
        'exists' => false,
        'size' => 0,
        'lines' => [],
    ];

    if (!is_file($logPath)) {
        return $result;
    }

    $result['exists'] = true;
    $result['size'] = (int)@filesize($logPath);

    $file = @fopen($logPath, 'r');
    if (!$file) {
        return $result;
    }

    $buffer = '';
    $logLines = [];
    $chunk = 8192;

    fseek($file, 0, SEEK_END);
    $pos = ftell($file);

    while ($pos > 0 && count($logLines) < $lines) {
        $toRead = min($chunk, $pos);
        $pos -= $toRead;
        fseek($file, $pos);
        $buffer = fread($file, $toRead) . $buffer;

        $parts = explode("\n", $buffer);
        $buffer = array_shift($parts);

        foreach (array_reverse($parts) as $line) {
            if (trim($line) !== '') {
                array_unshift($logLines, $line);
                if (count($logLines) >= $lines) break;
            }
        }
    }

    fclose($file);

    foreach ($logLines as $line) {
        $decoded = @json_decode($line, true);
        if (is_array($decoded)) {
            $result['lines'][] = $decoded;
        } else {
            $result['lines'][] = ['raw' => $line];
        }
    }

    return $result;
}

/**
 * Obtener configuración sanitizada (sin passwords)
 */
function flus_get_sanitized_config(): array {
    return [
        'DB_HOST' => defined('DB_HOST') ? DB_HOST : null,
        'DB_PORT' => defined('DB_PORT') ? DB_PORT : null,
        'DB_NAME' => defined('DB_NAME') ? DB_NAME : null,
        'DB_USER' => defined('DB_USER') ? DB_USER : null,
        'DB_PASS' => defined('DB_PASS') && DB_PASS !== '' ? '***SET***' : '***EMPTY***',
        'APP_DEBUG' => defined('APP_DEBUG') ? APP_DEBUG : null,
        'APP_NAME' => defined('APP_NAME') ? APP_NAME : null,
        'APP_VERSION' => defined('APP_VERSION') ? APP_VERSION : null,
        'APP_BUILD' => defined('APP_BUILD') ? APP_BUILD : null,
        'FLUS_VERSION' => defined('FLUS_VERSION') ? FLUS_VERSION : null,
        'FLUS_BUILD' => defined('FLUS_BUILD') ? FLUS_BUILD : null,
    ];
}

/**
 * Generar paquete de diagnóstico (ZIP)
 */
function flus_generate_diagnostic_package(): ?string {
    if (!class_exists('ZipArchive')) {
        return null;
    }

    $baseDir = defined('FLUS_ROOT') ? FLUS_ROOT : dirname(__DIR__);
    $storageDir = $baseDir . '/storage';
    $outputDir = $storageDir . '/diagnostics';

    if (!is_dir($outputDir)) {
        @mkdir($outputDir, 0775, true);
    }

    $timestamp = date('Ymd_His');
    $filename = "flus_diagnostic_{$timestamp}.zip";
    $filepath = $outputDir . '/' . $filename;

    $zip = new ZipArchive();
    if ($zip->open($filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return null;
    }

    $health = flus_health_check();
    $zip->addFromString('health_check.json', json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $schema = flus_get_schema_summary();
    $zip->addFromString('schema_summary.json', json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $config = flus_get_sanitized_config();
    $zip->addFromString('config_sanitized.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $logs = flus_get_recent_logs(500);
    $zip->addFromString('recent_logs.json', json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $logPath = $storageDir . '/logs/app.log';
    if (is_file($logPath) && filesize($logPath) < 5 * 1024 * 1024) {
        $zip->addFile($logPath, 'logs/app.log');
    }

    ob_start();
    phpinfo(INFO_GENERAL | INFO_CONFIGURATION | INFO_MODULES);
    $phpinfo = ob_get_clean();
    $zip->addFromString('phpinfo.html', $phpinfo);

    $sysinfo = [
        'timestamp' => date('c'),
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'php_os' => PHP_OS,
        'php_os_family' => PHP_OS_FAMILY,
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
        'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'unknown',
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'post_max_size' => ini_get('post_max_size'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'extensions' => get_loaded_extensions(),
    ];
    $zip->addFromString('system_info.json', json_encode($sysinfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $maintenancePath = $storageDir . '/maintenance.flag';
    if (is_file($maintenancePath)) {
        $zip->addFile($maintenancePath, 'maintenance.flag');
    }

    $zip->close();

    flus_log_info('Diagnostic package generated', ['file' => $filename]);

    return $filepath;
}


/**
 * Formatear bytes
 */
function flus_format_bytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $n = (float)$bytes;

    while ($n >= 1024 && $i < count($units) - 1) {
        $n /= 1024;
        $i++;
    }

    return number_format($n, $i === 0 ? 0 : 2, ',', '.') . ' ' . $units[$i];
}

/**
 * Limpiar paquetes de diagnóstico antiguos (> 7 días)
 */
function flus_cleanup_diagnostic_packages(int $days = 7): int {
    $dir = (defined('FLUS_ROOT') ? FLUS_ROOT : dirname(__DIR__)) . '/storage/diagnostics';

    if (!is_dir($dir)) {
        return 0;
    }

    $deleted = 0;
    $cutoff = time() - ($days * 86400);

    // ✅ Soportar ambos nombres por compatibilidad
    $files = array_merge(
        glob($dir . '/flus_diagnostic_*.zip') ?: [],
        glob($dir . '/flus_diagnostico_*.zip') ?: []
    );

    foreach ($files as $file) {
        $mtime = filemtime($file);
        if ($mtime !== false && $mtime < $cutoff) {
            if (@unlink($file)) {
                $deleted++;
            }
        }
    }

    return $deleted;
}

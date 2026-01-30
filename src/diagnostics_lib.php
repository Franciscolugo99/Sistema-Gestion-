<?php
// src/diagnostics_lib.php
declare(strict_types=1);

/**
 * FLUS Diagnostics Library
 * Sistema de diagnóstico y paquete de soporte
 *
 * @version 1.0.3
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

    // Validación: DB configurada vs DB seleccionada
    $dbCfg = $health['database']['name'] ?? null;
    $dbSel = $health['database']['selected_db'] ?? null;
    if ((bool)($health['database']['ok'] ?? false) && $dbCfg && $dbSel && strcasecmp((string)$dbCfg, (string)$dbSel) !== 0) {
        $health['status'] = 'ERROR';
        $health['issues'][] = 'Database mismatch: configured=' . (string)$dbCfg . ' selected=' . (string)$dbSel;
    }

    // 2. Verificar tablas críticas

$tablesDetail = flus_check_critical_tables_detailed();
$health['tables'] = $tablesDetail['tables'] ?? [];
$health['tables_meta'] = $tablesDetail;

if (!empty($tablesDetail['error'])) {
    // No confundir "faltan tablas" con "falló el chequeo"
    $health['status'] = ($health['status'] === 'OK') ? 'WARNING' : $health['status'];
    $health['issues'][] = 'Tables check failed: ' . (string)$tablesDetail['error'];
} else {
    $missingTables = array_filter($health['tables'], fn($v) => !$v);
    if ($missingTables) {
        $health['status'] = 'WARNING';
        $health['issues'][] = 'Missing tables: ' . implode(', ', array_keys($missingTables));
    }
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

    // 8. Verificar extensiones y configuración de PHP
    $health['php_extensions'] = flus_check_php_extensions();
    if (!(bool)($health['php_extensions']['all_ok'] ?? true)) {
        $health['status'] = $health['status'] === 'OK' ? 'WARNING' : $health['status'];
        $missReq = $health['php_extensions']['missing_required'] ?? [];
        if (is_array($missReq) && !empty($missReq)) {
            $health['issues'][] = 'Missing PHP extensions: ' . implode(', ', $missReq);
        } else {
            $health['issues'][] = 'PHP extensions missing';
        }
    }

    $health['php_ini'] = flus_check_php_ini();
    if (!(bool)($health['php_ini']['all_ok'] ?? true)) {
        $health['status'] = $health['status'] === 'OK' ? 'WARNING' : $health['status'];
        $health['issues'][] = 'PHP configuration warnings';
    }

    // 9. Logs recientes (para vista rápida en Diagnóstico)
    $health['logs'] = flus_get_recent_text_logs(80);

    // 10. Resumen de logs (errores repetidos) + hints de esquema (Unknown column, etc.)
    $health['log_summary'] = flus_summarize_recent_text_logs($health['logs']);
    $health['schema_hints'] = flus_extract_schema_hints_from_logs($health['logs']);

    // Si hay errores/fatals recientes en logs, marcar como WARNING (aunque sean "históricos", sirven para soporte)
    $appErrs = (int)($health['log_summary']['app']['errors'] ?? 0);
    $phpFatals = (int)($health['log_summary']['php']['fatals'] ?? 0);
    if ($appErrs > 0 || $phpFatals > 0) {
        $health['status'] = $health['status'] === 'OK' ? 'WARNING' : $health['status'];
        $health['issues'][] = 'Se detectaron errores en logs: app=' . $appErrs . ' php_fatal=' . $phpFatals;
    }

    if (is_array($health['schema_hints']) && !empty($health['schema_hints'])) {
        $health['status'] = $health['status'] === 'OK' ? 'WARNING' : $health['status'];
        $health['issues'][] = 'Schema hints from logs: ' . implode(', ', array_slice($health['schema_hints'], 0, 5));
    }

    // =========================
    // Compatibilidad con public/diagnostico.php
    // =========================

    // critical_tables summary (diagnostico.php lo espera)
$tablesMeta = is_array($health['tables_meta'] ?? null) ? $health['tables_meta'] : [];
$checkFailed = !empty($tablesMeta['error']);

if ($checkFailed) {
    $health['critical_tables'] = [
        'existing_count' => 0,
        'missing_count'  => 0,
        'missing'        => [],
        'check_failed'   => true,
        'error'          => (string)($tablesMeta['error'] ?? 'Error desconocido'),
        'db_config'      => $tablesMeta['db_config'] ?? null,
        'db_selected'    => $tablesMeta['db_selected'] ?? null,
    ];
} else {
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
        'check_failed'   => false,
    ];
}


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
        'selected_db'   => null,
    ];

    try {
        $start = microtime(true);
        $pdo = getPDO();
        $pdo->query('SELECT 1');

        // DB realmente seleccionada en esta conexión
        $selected = null;
        try {
            $selected = $pdo->query('SELECT DATABASE()')->fetchColumn();
        } catch (Throwable $e2) {
            $selected = null;
        }
        $result['selected_db'] = $selected ?: null;

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
     * Verificar tablas críticas (compat): devuelve solo mapa tabla=>bool
     */
    function flus_check_critical_tables(): array {
        return flus_check_critical_tables_detailed()['tables'];
    }

    /**
     * Verificar tablas críticas (detallado)
     * - No depende de tener DB "seleccionada" en el DSN: consulta information_schema.TABLES usando DB_NAME o SELECT DATABASE().
     */
    function flus_check_critical_tables_detailed(): array {
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

        $detail = [
            'ok' => false,
            'error' => null,
            'checked_via' => 'information_schema',
            'db_config' => defined('DB_NAME') ? (string)DB_NAME : null,
            'db_selected' => null,
            'tables' => $tables,
        ];

        try {
            $pdo = getPDO();

            // DB realmente seleccionada en esta conexión
            $selected = null;
            try {
                $selected = $pdo->query('SELECT DATABASE()')->fetchColumn();
            } catch (Throwable $e2) {
                $selected = null;
            }
            $detail['db_selected'] = $selected ?: null;

            $schema = $detail['db_selected'] ?: $detail['db_config'];
            if (!$schema) {
                throw new RuntimeException('No hay base de datos seleccionada en la conexión y DB_NAME está vacío');
            }

            // Traer todas las tablas presentes en una sola query
            $placeholders = implode(',', array_fill(0, count($tables), '?'));
            $sql = "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ($placeholders)";
            $params = array_merge([$schema], array_keys($tables));

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $present = $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            $presentMap = [];
            foreach ($present as $t) {
                $presentMap[(string)$t] = true;
            }

            foreach (array_keys($tables) as $t) {
                $detail['tables'][$t] = isset($presentMap[$t]);
            }

            $detail['ok'] = true;
        } catch (Throwable $e) {
            $detail['error'] = $e->getMessage();
            // Mantener tables en false para compat, pero marcar ok=false y propagar error
        }

        return $detail;
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
 * Verificar extensiones PHP requeridas y recomendadas
 */
function flus_check_php_extensions(?array $required = null, ?array $recommended = null): array {
    $required = $required ?? ['pdo_mysql', 'mbstring', 'json'];
    $recommended = $recommended ?? ['curl', 'openssl', 'zip', 'fileinfo', 'gd', 'intl'];

    $loaded = array_map('strtolower', get_loaded_extensions());
    $loadedMap = array_fill_keys($loaded, true);

    $req = [];
    $rec = [];
    $missingReq = [];
    $missingRec = [];

    foreach ($required as $ext) {
        $ext = strtolower((string)$ext);
        $isLoaded = isset($loadedMap[$ext]);
        $req[$ext] = ['loaded' => $isLoaded];
        if (!$isLoaded) $missingReq[] = $ext;
    }

    foreach ($recommended as $ext) {
        $ext = strtolower((string)$ext);
        $isLoaded = isset($loadedMap[$ext]);
        $rec[$ext] = ['loaded' => $isLoaded];
        if (!$isLoaded) $missingRec[] = $ext;
    }

    return [
        'required' => $req,
        'recommended' => $rec,
        'missing_required' => $missingReq,
        'missing_recommended' => $missingRec,
        'all_ok' => count($missingReq) === 0,
    ];
}

/**
 * Parsear valores tipo "128M", "1G" de php.ini a bytes.
 * Devuelve -1 si es ilimitado (ej: -1), o null si no se puede parsear.
 */
function flus_ini_size_to_bytes(?string $value): ?int {
    if ($value === null) return null;
    $value = trim($value);
    if ($value === '') return null;
    if ($value === '-1') return -1;

    $unit = strtolower(substr($value, -1));
    $num = $value;

    if (in_array($unit, ['k', 'm', 'g', 't'], true)) {
        $num = substr($value, 0, -1);
    } else {
        $unit = '';
    }

    if (!is_numeric($num)) return null;

    $n = (float)$num;
    switch ($unit) {
        case 'k': $n *= 1024; break;
        case 'm': $n *= 1024 * 1024; break;
        case 'g': $n *= 1024 * 1024 * 1024; break;
        case 't': $n *= 1024 * 1024 * 1024 * 1024; break;
        default: break;
    }
    return (int)round($n);
}

/**
 * Verificar configuración PHP (php.ini)
 */
function flus_check_php_ini(): array {
    $checks = [
        'memory_limit' => [
            'label' => 'memory_limit',
            'value' => (string)ini_get('memory_limit'),
            'type'  => 'size',
            'min'   => 256 * 1024 * 1024, // 256M
            'recommended' => '>= 256M',
            'severity' => 'warning',
        ],
        'max_execution_time' => [
            'label' => 'max_execution_time',
            'value' => (string)ini_get('max_execution_time'),
            'type'  => 'int',
            'min'   => 60,
            'recommended' => '>= 60',
            'severity' => 'warning',
        ],
        'post_max_size' => [
            'label' => 'post_max_size',
            'value' => (string)ini_get('post_max_size'),
            'type'  => 'size',
            'min'   => 32 * 1024 * 1024, // 32M
            'recommended' => '>= 32M',
            'severity' => 'warning',
        ],
        'upload_max_filesize' => [
            'label' => 'upload_max_filesize',
            'value' => (string)ini_get('upload_max_filesize'),
            'type'  => 'size',
            'min'   => 32 * 1024 * 1024, // 32M
            'recommended' => '>= 32M',
            'severity' => 'warning',
        ],
        'max_input_vars' => [
            'label' => 'max_input_vars',
            'value' => (string)ini_get('max_input_vars'),
            'type'  => 'int',
            'min'   => 2000,
            'recommended' => '>= 2000',
            'severity' => 'info',
        ],
        'date.timezone' => [
            'label' => 'date.timezone',
            'value' => (string)ini_get('date.timezone'),
            'type'  => 'string',
            'min'   => null,
            'recommended' => 'Definido',
            'severity' => 'warning',
        ],
        'error_log' => [
            'label' => 'error_log',
            'value' => (string)ini_get('error_log'),
            'type'  => 'string',
            'min'   => null,
            'recommended' => 'Ruta válida o vacío',
            'severity' => 'info',
        ],
    ];

    // Mostrar TZ de runtime si difiere del ini
    try {
        $iniTz = (string)($checks['date.timezone']['value'] ?? '');
        $runTz = function_exists('date_default_timezone_get') ? (string)date_default_timezone_get() : '';
        if ($runTz !== '' && $iniTz !== '' && strcasecmp($iniTz, $runTz) !== 0) {
            $checks['date.timezone']['value'] = $iniTz . ' (runtime: ' . $runTz . ')';
        }
    } catch (Throwable $e) {
        // ignore
    }

    $out = [];
    $warnings = [];
    $allOk = true;

    foreach ($checks as $key => $c) {
        $ok = true;
        $parsed = null;

        if ($c['type'] === 'size') {
            $parsed = flus_ini_size_to_bytes($c['value']);
            if ($parsed === null) {
                $ok = false;
            } elseif ($parsed === -1) {
                $ok = true; // ilimitado
            } else {
                $ok = $parsed >= (int)$c['min'];
            }
        } elseif ($c['type'] === 'int') {
            $parsed = (int)$c['value'];
            $ok = $parsed >= (int)$c['min'];
        } elseif ($c['type'] === 'string') {
            $ok = trim((string)$c['value']) !== '';
        }

        $out[$key] = [
            'label' => $c['label'],
            'value' => $c['value'],
            'ok' => $ok,
            'recommended' => $c['recommended'],
            'severity' => $c['severity'],
            'parsed' => $parsed,
        ];

        if (!$ok) {
            if ($c['severity'] === 'warning') {
                $warnings[] = $key;
                $allOk = false;
            } elseif ($c['severity'] === 'info') {
                // no impacta allOk
            } else {
                $allOk = false;
            }
        }
    }

    return [
        'checks' => $out,
        'warnings' => $warnings,
        'all_ok' => $allOk,
    ];
}

/**
 * Leer las últimas N líneas de un archivo de texto (sin cargarlo entero).
 */
function flus_tail_text_file(string $path, int $lines = 80, int $maxBytes = 262144): array {
    if (!is_file($path) || !is_readable($path)) return [];

    $size = (int)@filesize($path);
    if ($size <= 0) return [];

    $file = @fopen($path, 'r');
    if (!$file) return [];

    // Limitar la lectura máxima al final del archivo
    $readBytes = min($size, $maxBytes);
    fseek($file, -$readBytes, SEEK_END);

    $data = fread($file, $readBytes);
    fclose($file);

    if ($data === false || $data === '') return [];

    $parts = preg_split("/\r\n|\n|\r/", $data) ?: [];
    // Si el archivo es enorme y cortamos al medio, la primer línea puede estar incompleta
    if (count($parts) > 0 && $readBytes < $size) {
        array_shift($parts);
    }

    $parts = array_values(array_filter($parts, fn($l) => trim((string)$l) !== ''));

    if (count($parts) > $lines) {
        $parts = array_slice($parts, -$lines);
    }
    return $parts;
}

/**
 * Detectar ruta de error_log de PHP (si está configurada).
 */
function flus_get_php_error_log_path(): ?string {
    $p = (string)ini_get('error_log');
    $p = trim($p);
    if ($p === '' || strtolower($p) === 'syslog') return null;
    return $p;
}

/**
 * Logs recientes en formato texto para UI (app.log + php error_log si existe)
 */
function flus_get_recent_text_logs(int $lines = 80): array {
    $base = (defined('FLUS_ROOT') ? FLUS_ROOT : dirname(__DIR__));

    $appLog = $base . '/storage/logs/app.log';
    $phpErr = flus_get_php_error_log_path();

    $out = [
        'app_log' => [
            'path' => $appLog,
            'exists' => is_file($appLog),
            'size' => (int)@filesize($appLog),
            'tail' => flus_tail_text_file($appLog, $lines),
        ],
        'php_error_log' => [
            'path' => $phpErr,
            'exists' => $phpErr ? is_file($phpErr) : false,
            'size' => $phpErr ? (int)@filesize($phpErr) : 0,
            'tail' => ($phpErr && is_file($phpErr)) ? flus_tail_text_file($phpErr, $lines) : [],
        ],
    ];

    return $out;
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


$phpExt = flus_check_php_extensions();
$zip->addFromString('php_extensions.json', json_encode($phpExt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$phpIni = flus_check_php_ini();
$zip->addFromString('php_ini.json', json_encode($phpIni, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

$textLogs = flus_get_recent_text_logs(200);
$zip->addFromString('recent_text_logs.json', json_encode($textLogs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

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


// Resumen rápido para soporte (copiable)
$summaryLines = [];
$summaryLines[] = 'FLUS Diagnostics Summary';
$summaryLines[] = 'Timestamp: ' . ($health['timestamp'] ?? date('c'));
$summaryLines[] = 'FLUS: ' . ($health['version'] ?? 'unknown') . ' (Build ' . ($health['build'] ?? 'unknown') . ')';
$summaryLines[] = 'PHP: ' . PHP_VERSION . ' (' . PHP_SAPI . ')';
$summaryLines[] = 'OS: ' . PHP_OS_FAMILY . ' / ' . PHP_OS;
$summaryLines[] = 'DB host: ' . (($health['database']['host'] ?? 'unknown')) . ' DB_NAME=' . (($health['database']['name'] ?? 'unknown')) . ' SELECTED=' . (($health['database']['selected_db'] ?? 'null'));
$summaryLines[] = 'DB ok: ' . ((($health['database']['ok'] ?? false) ? 'YES' : 'NO'));
$summaryLines[] = 'Tables check: ' . ((($health['critical_tables']['check_failed'] ?? false) ? 'FAILED' : 'OK'));
if (!empty($health['critical_tables']['missing'])) {
    $summaryLines[] = 'Missing tables: ' . implode(', ', (array)$health['critical_tables']['missing']);
}
if (!empty($health['issues'])) {
    $summaryLines[] = 'Issues: ' . implode(' | ', (array)$health['issues']);

	if (!empty($health['schema_hints'])) {
	    $summaryLines[] = 'Schema hints: ' . implode(', ', array_slice((array)$health['schema_hints'], 0, 10));
	}
	if (!empty($health['log_summary'])) {
	    $ls = $health['log_summary'];
	    $summaryLines[] = 'app.log errors: ' . (string)($ls['app']['errors'] ?? 0) . ' warnings: ' . (string)($ls['app']['warnings'] ?? 0);
	    $summaryLines[] = 'php fatals: ' . (string)($ls['php']['fatals'] ?? 0) . ' warnings: ' . (string)($ls['php']['warnings'] ?? 0) . ' notices: ' . (string)($ls['php']['notices'] ?? 0);
	}
}
if (!empty($phpExt['missing_required'])) {
    $summaryLines[] = 'Missing PHP extensions (required): ' . implode(', ', (array)$phpExt['missing_required']);
}
$zip->addFromString('support_summary.txt', implode("\n", $summaryLines) . "\n");

    $zip->close();

    flus_log_info('Diagnostic package generated', ['file' => $filename]);

    return $filepath;
}


/**
 * Formatear bytes
 */

/**
 * Resumen de errores recientes a partir de logs en texto (app.log + php error log).
 * Devuelve conteos y top mensajes repetidos para facilitar soporte.
 */
function flus_summarize_recent_text_logs(array $logs, int $top = 6): array {
    $out = [
        'app' => ['errors' => 0, 'warnings' => 0, 'top' => []],
        'php' => ['fatals' => 0, 'warnings' => 0, 'notices' => 0, 'top' => []],
    ];

    // ---- app.log (JSON lines en muchos casos) ----
    $appTail = $logs['app_log']['tail'] ?? [];
    if (is_array($appTail)) {
        $counts = [];
        foreach ($appTail as $line) {
            $line = (string)$line;
            $j = @json_decode($line, true);
            if (is_array($j)) {
                $level = strtoupper((string)($j['level'] ?? ''));
                $msg   = (string)($j['message'] ?? '');
                $ctxErr = '';
                if (isset($j['context']['error'])) {
                    $ctxErr = (string)$j['context']['error'];
                } elseif (isset($j['error'])) {
                    $ctxErr = (string)$j['error'];
                }
                $label = trim($msg);
                if ($ctxErr !== '') {
                    $ctxErrShort = function_exists('mb_substr') ? mb_substr($ctxErr, 0, 160) : substr($ctxErr, 0, 160);
                    $label .= ' — ' . $ctxErrShort;
                }
                if ($label === '') $label = $line;

                if ($level === 'ERROR') $out['app']['errors']++;
                if ($level === 'WARNING' || $level === 'WARN') $out['app']['warnings']++;

                $key = $level . '|' . $label;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            } else {
                $label = function_exists('mb_substr') ? mb_substr($line, 0, 220) : substr($line, 0, 220);
                $k = 'RAW|' . $label;
                $counts[$k] = ($counts[$k] ?? 0) + 1;
            }
        }
        arsort($counts);
        $topItems = [];
        foreach (array_slice($counts, 0, $top, true) as $k => $c) {
            [$lvl, $label] = array_pad(explode('|', (string)$k, 2), 2, '');
            $topItems[] = ['level' => $lvl, 'count' => $c, 'label' => $label];
        }
        $out['app']['top'] = $topItems;
    }

    // ---- PHP error log ----
    $phpTail = $logs['php_error_log']['tail'] ?? [];
    if (is_array($phpTail)) {
        $counts = [];
        foreach ($phpTail as $line) {
            $line = (string)$line;

            if (stripos($line, 'PHP Fatal error') !== false) $out['php']['fatals']++;
            if (stripos($line, 'PHP Warning') !== false) $out['php']['warnings']++;
            if (stripos($line, 'PHP Notice') !== false) $out['php']['notices']++;

            $label = $line;
            if (preg_match('/PHP\s+(Fatal error|Warning|Notice|Parse error):\s+(.*?)(\s+in\s+|$)/i', $line, $m)) {
                $label = strtoupper($m[1]) . ': ' . trim($m[2]);
            }
            $label = function_exists('mb_substr') ? mb_substr($label, 0, 240) : substr($label, 0, 240);
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        arsort($counts);
        $topItems = [];
        foreach (array_slice($counts, 0, $top, true) as $label => $c) {
            $topItems[] = ['count' => $c, 'label' => $label];
        }
        $out['php']['top'] = $topItems;
    }

    return $out;
}

/**
 * Extraer "hints" de esquema desde logs (por ejemplo Unknown column 'x.y')
 * Devuelve una lista única, ordenada.
 */
function flus_extract_schema_hints_from_logs(array $logs): array {
    $hints = [];

    $scan = function($tail) use (&$hints) {
        if (!is_array($tail)) return;
        foreach ($tail as $line) {
            $line = (string)$line;
            $j = @json_decode($line, true);
            if (is_array($j)) {
                $line = (string)($j['context']['error'] ?? $j['error'] ?? $j['message'] ?? $line);
            }

            if (preg_match_all("/Unknown column '([^']+)'/i", $line, $mm)) {
                foreach ($mm[1] as $col) {
                    $col = trim((string)$col);
                    if ($col !== '') $hints[$col] = true;
                }
            }
            if (preg_match_all("/Table '([^']+)' doesn't exist/i", $line, $mm2)) {
                foreach ($mm2[1] as $t) {
                    $t = trim((string)$t);
                    if ($t !== '') $hints['missing_table:' . $t] = true;
                }
            }
        }
    };

    $scan($logs['app_log']['tail'] ?? []);
    $scan($logs['php_error_log']['tail'] ?? []);

    $keys = array_keys($hints);
    sort($keys, SORT_NATURAL | SORT_FLAG_CASE);
    return $keys;
}

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


/* =====================================================================
   Additions (P0 consolidado): limpieza avanzada + seguridad + análisis
   - Se agregan con guardas function_exists() para evitar redeclare
===================================================================== */

if (!function_exists('flus_delete_diagnostic_package')) {
    /**
     * Elimina un paquete de diagnóstico específico (validado + protegido).
     * Acepta: flus_diagnostic_YYYYMMDD_HHMMSS.zip (y compat flus_diagnostico_*)
     */
    function flus_delete_diagnostic_package(string $file): bool {
        $file = trim($file);
        if ($file === '') return false;

        $ok = (bool)preg_match('/^flus_diagnostic_\d{8}_\d{6}\.zip$/', $file)
            || (bool)preg_match('/^flus_diagnostico_\d{8}_\d{6}\.zip$/', $file);

        if (!$ok) return false;

        $root = defined('FLUS_ROOT') ? FLUS_ROOT : dirname(__DIR__);
        $dir = $root . '/storage/diagnostics';
        if (!is_dir($dir)) return false;

        $full = $dir . '/' . $file;
        $realFile = realpath($full);
        $realDir  = realpath($dir);
        if (!$realFile || !$realDir) return false;

        // path traversal guard
        if (strpos($realFile, $realDir) !== 0) return false;
        if (!is_file($realFile)) return false;

        return (bool)@unlink($realFile);
    }
}

if (!function_exists('flus_cleanup_diagnostic_packages_stats')) {
    /**
     * Limpia paquetes viejos y devuelve estadísticas.
     * - $days=7: elimina >7 días
     * - $days=0: borra todo
     */
    function flus_cleanup_diagnostic_packages_stats(int $days = 7): array {
        $root = defined('FLUS_ROOT') ? FLUS_ROOT : dirname(__DIR__);
        $dir = $root . '/storage/diagnostics';

        $result = [
            'deleted' => [],
            'failed' => [],
            'deleted_count' => 0,
            'failed_count' => 0,
            'space_freed' => 0,
            'space_freed_formatted' => '0 B',
            'days' => $days,
        ];

        if (!is_dir($dir)) {
            return $result;
        }

        $cutoff = time() - max(0, $days) * 86400;

        $files = array_merge(
            glob($dir . '/flus_diagnostic_*.zip') ?: [],
            glob($dir . '/flus_diagnostico_*.zip') ?: []
        );

        foreach ($files as $path) {
            $mtime = @filemtime($path);
            $shouldDelete = ($days <= 0) ? true : ($mtime !== false && $mtime < $cutoff);

            if (!$shouldDelete) continue;

            $size = (int)@filesize($path);
            $base = basename($path);

            if (@unlink($path)) {
                $result['deleted'][] = $base;
                $result['deleted_count']++;
                $result['space_freed'] += max(0, $size);
            } else {
                $result['failed'][] = $base;
                $result['failed_count']++;
            }
        }

        if (function_exists('flus_format_bytes')) {
            $result['space_freed_formatted'] = flus_format_bytes((int)$result['space_freed']);
        }

        return $result;
    }
}

if (!function_exists('flus_analyze_recent_errors')) {
    /**
     * Analiza logs por tiempo (últimas N horas).
     * - Tolera logs JSONL y texto plano.
     */
    function flus_analyze_recent_errors(int $hours = 24): array {
        $root = defined('FLUS_ROOT') ? FLUS_ROOT : dirname(__DIR__);
        $logFile = $root . '/storage/logs/app.log';

        $cutoff = time() - max(1, $hours) * 3600;

        $out = [
            'hours' => $hours,
            'total_critical' => 0,
            'total_warning' => 0,
            'errors' => [],
            'warnings' => [],
        ];

        if (!is_file($logFile) || !is_readable($logFile)) {
            return $out;
        }

        $handle = @fopen($logFile, 'rb');
        if (!$handle) return $out;

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') continue;

            $ts = null;
            $level = null;
            $msg = null;

            // JSONL
            $j = json_decode($line, true);
            if (is_array($j)) {
                $ts = isset($j['ts']) ? strtotime((string)$j['ts']) : null;
                $level = strtolower((string)($j['level'] ?? ''));
                $msg = (string)($j['msg'] ?? ($j['message'] ?? ''));
            } else {
                // texto: [YYYY-mm-dd HH:ii:ss] [level] message
                if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s*\[([a-zA-Z]+)\]\s*(.*)$/', $line, $m)) {
                    $ts = strtotime($m[1]);
                    $level = strtolower($m[2]);
                    $msg = $m[3];
                } elseif (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\].*?(ERROR|CRITICAL|FATAL|WARNING):(.*)$/i', $line, $m)) {
                    $ts = strtotime($m[1]);
                    $level = strtolower($m[2]);
                    $msg = trim($m[3]);
                }
            }

            if (!$ts || $ts < $cutoff) continue;

            $isCritical = in_array($level, ['error','critical','fatal'], true);
            $isWarning  = in_array($level, ['warning','warn'], true);

            if ($isCritical) {
                $out['total_critical']++;
                if (count($out['errors']) < 50) {
                    $out['errors'][] = ['time' => date('Y-m-d H:i:s', $ts), 'level' => $level, 'message' => $msg];
                }
            } elseif ($isWarning) {
                $out['total_warning']++;
                if (count($out['warnings']) < 50) {
                    $out['warnings'][] = ['time' => date('Y-m-d H:i:s', $ts), 'level' => $level, 'message' => $msg];
                }
            }
        }

        fclose($handle);
        return $out;
    }
}

if (!function_exists('flus_check_critical_files')) {
    /**
     * Chequea archivos críticos. Devuelve issues simples para UI.
     */
    function flus_check_critical_files(): array {
        $root = defined('FLUS_ROOT') ? FLUS_ROOT : dirname(__DIR__);

        $files = [
            'public/index.php',
            'public/bootstrap.php',
            'src/config.php',
            'src/diagnostics_lib.php',
        ];

        $issues = [];
        $detail = [];

        foreach ($files as $rel) {
            $full = $root . '/' . $rel;
            $exists = is_file($full);
            $readable = $exists ? is_readable($full) : false;

            $detail[$rel] = [
                'exists' => $exists,
                'readable' => $readable,
                'size' => $exists ? (int)@filesize($full) : 0,
                'modified' => $exists ? (string)@date('Y-m-d H:i:s', (int)@filemtime($full)) : null,
            ];

            if (!$exists) $issues[] = "Falta: $rel";
            elseif (!$readable) $issues[] = "No legible: $rel";
        }

        return [
            'ok' => empty($issues),
            'issues' => $issues,
            'detail' => $detail,
        ];
    }
}

if (!function_exists('flus_check_security_config')) {
    /**
     * Chequeo de configuración de seguridad (solo lectura).
     */
    function flus_check_security_config(): array {
        $issues = [];
        $warnings = [];

        // display_errors en producción es malísimo; en dev solo warning
        $display = ini_get('display_errors');
        $env = defined('APP_ENV') ? (string)APP_ENV : (defined('APP_DEBUG') && APP_DEBUG ? 'development' : 'production');

        if ($display === '1' || strtolower((string)$display) === 'on') {
            if ($env === 'production') $issues[] = ['message' => 'display_errors está habilitado (inseguro en producción)'];
            else $warnings[] = ['message' => 'display_errors habilitado (OK en desarrollo, NO en producción)'];
        }

        $expose = ini_get('expose_php');
        if ($expose === '1' || strtolower((string)$expose) === 'on') {
            $warnings[] = ['message' => 'expose_php está habilitado'];
        }

        $allowUrlInclude = ini_get('allow_url_include');
        if ($allowUrlInclude === '1' || strtolower((string)$allowUrlInclude) === 'on') {
            $issues[] = ['message' => 'allow_url_include está habilitado (riesgo alto)'];
        }

        // HTTPS (solo advertir si no es localhost)
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $isLocal = $host === '' || stripos($host, 'localhost') !== false || preg_match('/^\d{1,3}(\.\d{1,3}){3}(:\d+)?$/', $host);
        $https = (string)($_SERVER['HTTPS'] ?? '');
        if (!$isLocal && ($https === '' || strtolower($https) === 'off')) {
            $warnings[] = ['message' => 'El sitio no está usando HTTPS'];
        }

        // uploads dentro de docroot => warning (no ejecuta pruebas activas)
        $root = defined('FLUS_ROOT') ? FLUS_ROOT : dirname(__DIR__);
        $uploads = $root . '/storage/uploads';
        $docroot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');

        if ($docroot !== '' && is_dir($uploads)) {
            $realUp = realpath($uploads);
            $realDoc = realpath($docroot);
            if ($realUp && $realDoc && strpos($realUp, $realDoc) === 0) {
                $warnings[] = ['message' => 'storage/uploads está dentro del document_root (revisar .htaccess / permisos)'];
            }
        }

        return [
            'secure' => empty($issues),
            'issues' => $issues,
            'warnings' => $warnings,
            'count' => count($issues) + count($warnings),
        ];
    }
}

if (!function_exists('flus_check_schema_integrity')) {
    /**
     * Integridad de esquema basada en:
     * - errores reales encontrados en logs (schema hints existentes)
     * - chequeos heurísticos (no bloqueantes) por columnas comunes
     */
    function flus_check_schema_integrity(): array {
        $out = [
            'ok' => true,
            'issues' => [],
            'warnings' => [],
            'notes' => 'Incluye heurísticas (puede variar según versión) + pistas reales desde logs.',
        ];

        // 1) hints reales desde logs (si existe)
        if (function_exists('flus_extract_schema_hints_from_logs') && function_exists('flus_get_recent_text_logs')) {
            $logs = flus_get_recent_text_logs(800);
            $hints = flus_extract_schema_hints_from_logs($logs);
            if (is_array($hints) && !empty($hints)) {
                foreach ($hints as $h) {
                    $msg = is_string($h) ? $h : json_encode($h);
                    $out['issues'][] = ['severity' => 'critical', 'message' => $msg];
                }
            }
        }

        // 2) chequeo heurístico de columnas comunes (warning)
        try {
            $pdo = getPDO();
            $db = null;
            try { $db = $pdo->query('SELECT DATABASE()')->fetchColumn(); } catch (\Throwable $e) { $db = null; }
            if (!$db && defined('DB_NAME')) $db = DB_NAME;

            if ($db) {
                $checks = [
                    'productos' => ['id', 'nombre'],
                    'ventas' => ['id'],
                    'users' => ['id'],
                ];

                foreach ($checks as $tbl => $cols) {
                    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
                    $stmt->execute([$db, $tbl]);
                    $existing = array_map('strtolower', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME'));

                    if (empty($existing)) {
                        $out['issues'][] = ['severity' => 'critical', 'message' => "Falta la tabla '$tbl' (según information_schema)"];
                        continue;
                    }

                    foreach ($cols as $c) {
                        if (!in_array(strtolower($c), $existing, true)) {
                            $out['warnings'][] = ['table' => $tbl, 'column' => $c, 'message' => "Columna esperada no encontrada: $tbl.$c"];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $out['warnings'][] = ['message' => 'No se pudo validar schema heurístico: ' . $e->getMessage()];
        }

        $out['ok'] = empty($out['issues']);
        return $out;
    }
}


<?php
// src/backup_lib.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';

/**
 * Obtiene el directorio de backups
 */
function backups_dir(): string {
    return defined('BACKUPS_PATH') ? BACKUPS_PATH : (dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups');
}

/**
 * Asegura que existan los directorios necesarios
 */
function backups_ensure_dirs(): void {
    $dir = backups_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

/**
 * Quote compatible para comandos shell (Linux/Windows)
 */
function sh_quote(string $s): string {
    // En Windows, escapeshellarg usa comillas simples que rompen cmd
    if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
        // Escapar comillas dobles y envolver en comillas dobles
        return '"' . str_replace('"', '""', $s) . '"';
    }
    return escapeshellarg($s);
}


/**
 * Crea un archivo temporal de credenciales para mysql/mysqldump.
 * Evita exponer el password en la línea de comandos (lista de procesos).
 *
 * Nota: --defaults-extra-file debe ir como primera opción luego del binario.
 *
 * @return string|null Ruta del archivo temporal, o null si no se pudo crear.
 */
function flus_mysql_make_defaults_file(array $db): ?string {
    $tmp = @tempnam(sys_get_temp_dir(), 'flus_mysql_');
    if (!$tmp) return null;

    // MySQL lee cualquier extensión, pero mantenemos formato .cnf para claridad
    $cnf = $tmp . '.cnf';
    @rename($tmp, $cnf);

    $lines = [];
    $lines[] = "[client]";
    if (!empty($db['user'])) $lines[] = "user=" . (string)$db['user'];
    if (array_key_exists('pass', $db) && $db['pass'] !== '') $lines[] = "password=" . (string)$db['pass'];
    if (!empty($db['host'])) $lines[] = "host=" . (string)$db['host'];
    if (!empty($db['port'])) $lines[] = "port=" . (string)$db['port'];

    $body = implode(PHP_EOL, $lines) . PHP_EOL;

    $ok = @file_put_contents($cnf, $body, LOCK_EX);
    if ($ok === false) {
        @unlink($cnf);
        return null;
    }

    // Endurecer permisos en Unix
    if (stripos(PHP_OS_FAMILY, 'Windows') !== 0) {
        @chmod($cnf, 0600);
    }

    return $cnf;
}

function flus_mysql_delete_defaults_file(?string $path): void {
    if (!$path) return;
    @unlink($path);
}

/**
 * Busca mysqldump en ubicaciones comunes
 */
function find_mysqldump(): ?array {
    $isWindows = stripos(PHP_OS_FAMILY, 'Windows') === 0;
    
    // Si hay una constante MYSQLDUMP_BIN definida, usarla primero
    if (defined('MYSQLDUMP_BIN') && file_exists(MYSQLDUMP_BIN)) {
        $path = MYSQLDUMP_BIN;
        $output = [];
        $returnCode = 0;
        $testCmd = sh_quote($path) . ' --version 2>&1';
        @exec($testCmd, $output, $returnCode);
        
        if ($returnCode === 0) {
            return [
                'path' => $path,
                'version' => implode(' ', $output)
            ];
        }
    }
    
    // Lista de candidatos
    $candidates = [];
    
    if ($isWindows) {
        // Rutas comunes en Windows
        $candidates = [
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'C:\\wamp64\\bin\\mysql\\mysql8.0.31\\bin\\mysqldump.exe',
            'C:\\wamp\\bin\\mysql\\mysql8.0.27\\bin\\mysqldump.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 5.7\\bin\\mysqldump.exe',
        ];
        
        // Intentar encontrar en PATH
        $output = [];
        $returnCode = 0;
        @exec('where mysqldump 2>nul', $output, $returnCode);
        if ($returnCode === 0 && !empty($output[0])) {
            array_unshift($candidates, $output[0]);
        }
    } else {
        // Rutas comunes en Linux/Mac
        $candidates = [
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
            '/opt/lampp/bin/mysqldump',
        ];
        
        // Intentar encontrar en PATH
        $output = [];
        $returnCode = 0;
        @exec('which mysqldump 2>/dev/null', $output, $returnCode);
        if ($returnCode === 0 && !empty($output[0])) {
            array_unshift($candidates, $output[0]);
        }
    }
    
    // Verificar cada candidato
    foreach ($candidates as $path) {
        if (is_file($path)) {
            // Verificar que sea ejecutable probando --version
            $output = [];
            $returnCode = 0;
            
            $testCmd = sh_quote($path) . ' --version 2>&1';
            @exec($testCmd, $output, $returnCode);
            
            if ($returnCode === 0) {
                return [
                    'path' => $path,
                    'version' => implode(' ', $output)
                ];
            }
        }
    }
    
    return null;
}

/**
 * Obtiene las credenciales de la base de datos
 * Compatible con define() y $GLOBALS[]
 */
function get_db_credentials(): array {
    // Prioridad 1: Constantes definidas con define()
    $host = defined('DB_HOST') ? DB_HOST : ($GLOBALS['DB_HOST'] ?? 'localhost');
    $port = defined('DB_PORT') ? DB_PORT : ($GLOBALS['DB_PORT'] ?? '3306');
    $name = defined('DB_NAME') ? DB_NAME : ($GLOBALS['DB_NAME'] ?? '');
    $user = defined('DB_USER') ? DB_USER : ($GLOBALS['DB_USER'] ?? 'root');
    $pass = defined('DB_PASS') ? DB_PASS : ($GLOBALS['DB_PASS'] ?? '');
    
    return [
        'host' => (string)$host,
        'port' => (string)$port,
        'name' => (string)$name,
        'user' => (string)$user,
        'pass' => (string)$pass,
    ];
}

/**
 * Crea un backup de la base de datos
 */
function backup_create(?string &$err = null): ?string {
    backups_ensure_dirs();

    // Obtener credenciales
    $db = get_db_credentials();
    
    // Verificar configuración
    if (empty($db['name'])) {
        $err = 'Error de configuración: nombre de base de datos no está definido';
        app_log('ERROR', 'backup_create: DB_NAME no definida');
        return null;
    }

    // Buscar mysqldump
    $mysqldumpInfo = find_mysqldump();
    if (!$mysqldumpInfo) {
        $err = 'No se encontró mysqldump. Instalá MySQL/MariaDB o verificá que MYSQLDUMP_BIN esté correctamente configurado.';
        if (defined('MYSQLDUMP_BIN')) {
            $err .= ' (Ruta configurada: ' . MYSQLDUMP_BIN . ')';
        }
        app_log('ERROR', 'backup_create: mysqldump not found');
        return null;
    }
    
    $mysqldump = $mysqldumpInfo['path'];
    app_log('INFO', 'mysqldump encontrado', ['path' => $mysqldump, 'version' => $mysqldumpInfo['version']]);

    // Generar nombre de archivo
    $ts = date('Ymd_His');
    $fileName = sprintf('%s_%s.sql', preg_replace('/[^a-zA-Z0-9_]/', '_', $db['name']), $ts);
    $fullPath = backups_dir() . DIRECTORY_SEPARATOR . $fileName;

    // Verificar que el directorio sea escribible
    if (!is_writable(backups_dir())) {
        $err = 'El directorio de backups no tiene permisos de escritura: ' . backups_dir();
        app_log('ERROR', 'backup_create: directorio no escribible', ['dir' => backups_dir()]);
        return null;
    }

    // Construir comando mysqldump
    $isWindows = stripos(PHP_OS_FAMILY, 'Windows') === 0;

    // ✅ Evitar exponer password en args: usamos --defaults-extra-file si hay pass
    $defaultsFile = null;
    if ($db['pass'] !== '') {
        $defaultsFile = flus_mysql_make_defaults_file($db);
        if (!$defaultsFile) {
            $err = 'No se pudo crear el archivo temporal de credenciales para mysqldump.';
            app_log('ERROR', 'backup_create: defaults-extra-file no se pudo crear');
            return null;
        }
    }

    if ($isWindows) {
        // En Windows, formato especial para evitar problemas con espacios y comillas
        $args = [
            '--host=' . $db['host'],
            '--user=' . $db['user'],
            '--default-character-set=utf8mb4',
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
            '--add-drop-table',
            '--skip-comments',
        ];

        if ($defaultsFile) {
            array_unshift($args, '--defaults-extra-file=' . sh_quote($defaultsFile));
        }

        // Agregar database
        $args[] = $db['name'];
        
        // Comando final con redirección
        $cmd = sprintf(
            '"%s" %s > "%s" 2>&1',
            $mysqldump,
            implode(' ', $args),
            $fullPath
        );
    } else {
        // En Linux/Mac, usar escapeshellarg
        $args = [
            '--host=' . escapeshellarg($db['host']),
            '--user=' . escapeshellarg($db['user']),
            '--default-character-set=utf8mb4',
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
            '--add-drop-table',
            '--skip-comments',
        ];

        if ($defaultsFile) {
            array_unshift($args, '--defaults-extra-file=' . escapeshellarg($defaultsFile));
        }

        $args[] = escapeshellarg($db['name']);
        
        $cmd = sprintf(
            '%s %s > %s 2>&1',
            escapeshellarg($mysqldump),
            implode(' ', $args),
            escapeshellarg($fullPath)
        );
    }
    
    app_log('DEBUG', 'Ejecutando mysqldump', ['bin' => $mysqldump, 'out' => $fullPath]);
    // Ejecutar comando
    $output = [];
    $returnCode = 0;
    @exec($cmd, $output, $returnCode);
    flus_mysql_delete_defaults_file($defaultsFile);
    
    // Verificar resultado
    if ($returnCode !== 0) {
        $outputStr = implode("\n", $output);
        $err = 'mysqldump falló (código ' . $returnCode . '): ' . $outputStr;
        app_log('ERROR', 'backup_create: mysqldump falló', [
            'code' => $returnCode,
            'output' => $outputStr
        ]);
        
        // Limpiar archivo parcial
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }
        
        return null;
    }
    
    // Verificar que el archivo se creó y tiene contenido
    if (!is_file($fullPath)) {
        $err = 'El archivo de backup no se creó en: ' . $fullPath;
        app_log('ERROR', 'backup_create: archivo no creado', ['path' => $fullPath]);
        return null;
    }
    
    $filesize = filesize($fullPath);
    if ($filesize === false || $filesize < 200) {
        $err = 'El backup está vacío o es muy pequeño (' . $filesize . ' bytes). Verificá la conexión a la base de datos.';
        app_log('ERROR', 'backup_create: archivo muy pequeño', ['size' => $filesize]);
        @unlink($fullPath);
        return null;
    }

    // Log de éxito
    app_log('INFO', 'Backup creado exitosamente', [
        'file' => $fileName,
        'size' => $filesize,
        'size_formatted' => fmt_bytes($filesize)
    ]);
    
    // Rotación de backups antiguos
    backup_rotate(30);
    
    return $fileName;
}

/**
 * Lista backups disponibles
 * @return array<int,array{file:string, path:string, size:int, mtime:int}>
 */
function backup_list(): array {
    backups_ensure_dirs();
    $dir = backups_dir();

    $items = [];
    $files = @glob($dir . DIRECTORY_SEPARATOR . '*.sql');
    
    if ($files === false) {
        app_log('WARNING', 'No se pudo leer el directorio de backups', ['dir' => $dir]);
        return [];
    }
    
    foreach ($files as $path) {
        if (!is_file($path)) continue;
        
        $items[] = [
            'file' => basename($path),
            'path' => $path,
            'size' => (int)@filesize($path),
            'mtime' => (int)@filemtime($path),
        ];
    }

    // Ordenar por fecha descendente (más reciente primero)
    usort($items, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    
    return $items;
}

/**
 * Elimina un backup
 */
function backup_delete(string $file, ?string &$err = null): bool {
    $file = basename($file);
    
    // Validar nombre de archivo
    if (!preg_match('/^[A-Za-z0-9._-]+\.sql$/', $file)) {
        $err = 'Nombre de archivo inválido.';
        app_log('WARNING', 'backup_delete: nombre inválido', ['file' => $file]);
        return false;
    }

    $path = backups_dir() . DIRECTORY_SEPARATOR . $file;
    
    if (!is_file($path)) {
        $err = 'El archivo no existe.';
        app_log('WARNING', 'backup_delete: archivo no existe', ['path' => $path]);
        return false;
    }

    if (!@unlink($path)) {
        $err = 'No se pudo borrar el archivo. Verificá los permisos.';
        app_log('ERROR', 'backup_delete: error al eliminar', ['path' => $path]);
        return false;
    }

    app_log('INFO', 'Backup eliminado', ['file' => $file]);
    return true;
}

/**
 * Rota backups antiguos manteniendo solo los últimos N
 */
function backup_rotate(int $keep = 30): void {
    $items = backup_list();
    if (count($items) <= $keep) {
        return;
    }

    $toDelete = array_slice($items, $keep);
    $deleted = 0;
    
    foreach ($toDelete as $it) {
        if (@unlink($it['path'])) {
            $deleted++;
        }
    }
    
    if ($deleted > 0) {
        app_log('INFO', 'Backups rotados', ['deleted' => $deleted, 'kept' => $keep]);
    }
}

/**
 * Formatea bytes a formato legible
 */
function fmt_bytes(int $bytes): string {
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    $n = (float)$bytes;
    
    while ($n >= 1024 && $i < count($units) - 1) {
        $n /= 1024;
        $i++;
    }
    
    return number_format($n, $i === 0 ? 0 : 2, ',', '.') . ' ' . $units[$i];
}

/**
 * Obtiene información de diagnóstico del sistema de backups
 */
function backup_diagnostics(): array {
    $mysqldumpInfo = find_mysqldump();
    $dir = backups_dir();
    $db = get_db_credentials();
    
    return [
        'mysqldump_found' => $mysqldumpInfo !== null,
        'mysqldump_path' => $mysqldumpInfo['path'] ?? null,
        'mysqldump_version' => $mysqldumpInfo['version'] ?? null,
        'mysqldump_defined' => defined('MYSQLDUMP_BIN') ? MYSQLDUMP_BIN : null,
        'backups_dir' => $dir,
        'dir_exists' => is_dir($dir),
        'dir_writable' => is_writable($dir),
        'db_config' => [
            'host' => $db['host'],
            'name' => $db['name'],
            'user' => $db['user'],
            'pass_set' => !empty($db['pass']),
        ],
        'php_os' => PHP_OS_FAMILY,
        'backups_count' => count(backup_list()),
    ];
}
/* ==========================================================================
   RESTORE (importar .sql)
   Nota: restaura la DB desde un backup generado por mysqldump.
   Seguridad:
   - valida nombre de archivo
   - lock de restauración
   - activa maintenance.flag durante el proceso
========================================================================== */

function find_mysql_cli(): ?array {
    $isWindows = stripos(PHP_OS_FAMILY, 'Windows') === 0;

    // Constante opcional
    if (defined('MYSQL_BIN') && MYSQL_BIN && file_exists(MYSQL_BIN)) {
        $path = MYSQL_BIN;
        $output = [];
        $rc = 0;
        $testCmd = sh_quote($path) . ' --version 2>&1';
        @exec($testCmd, $output, $rc);
        if ($rc === 0) {
            return ['path' => $path, 'version' => implode(' ', $output)];
        }
    }

    $candidates = [];
    if ($isWindows) {
        $candidates = [
            'C:\\xampp\\mysql\\bin\\mysql.exe',
            'C:\\wamp64\\bin\\mysql\\mysql8.0.31\\bin\\mysql.exe',
            'C:\\wamp\\bin\\mysql\\mysql8.0.27\\bin\\mysql.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysql.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 5.7\\bin\\mysql.exe',
        ];

        $output = [];
        $rc = 0;
        @exec('where mysql 2>nul', $output, $rc);
        if ($rc === 0 && !empty($output[0])) {
            array_unshift($candidates, $output[0]);
        }
    } else {
        $candidates = [
            '/usr/bin/mysql',
            '/usr/local/bin/mysql',
            '/usr/local/mysql/bin/mysql',
            '/opt/lampp/bin/mysql',
        ];

        $output = [];
        $rc = 0;
        @exec('which mysql 2>/dev/null', $output, $rc);
        if ($rc === 0 && !empty($output[0])) {
            array_unshift($candidates, $output[0]);
        }
    }

    foreach ($candidates as $path) {
        if (is_file($path)) {
            $output = [];
            $rc = 0;
            $testCmd = sh_quote($path) . ' --version 2>&1';
            @exec($testCmd, $output, $rc);
            if ($rc === 0) {
                return ['path' => $path, 'version' => implode(' ', $output)];
            }
        }
    }

    return null;
}


/**
 * Testea conexión a MySQL con credenciales antes de iniciar un restore.
 * Evita entrar en modo mantenimiento si la conexión/credenciales están mal.
 */
function mysql_test_connection(array $mysql, array $creds, ?string &$errDetail = null): bool {
    $errDetail = null;
    if (!function_exists('proc_open')) {
        $errDetail = 'proc_open no está disponible en PHP.';
        return false;
    }

    $defaultsFile = null;
    if (!empty($creds['pass'])) {
        $defaultsFile = flus_mysql_make_defaults_file($creds);
        if (!$defaultsFile) {
            $errDetail = 'No se pudo crear el archivo temporal de credenciales para mysql.';
            return false;
        }
    }

    $cmd = [
        $mysql['path'],
    ];
    if ($defaultsFile) {
        // Debe ser la primera opción luego del binario
        $cmd[] = '--defaults-extra-file=' . $defaultsFile;
    }
    $cmd[] = '--protocol=tcp';
    $cmd[] = '--default-character-set=utf8mb4';
    $cmd[] = '--batch';
    $cmd[] = '--skip-column-names';

    if (!empty($creds['host'])) $cmd[] = '--host=' . (string)$creds['host'];
    if (!empty($creds['port'])) $cmd[] = '--port=' . (string)$creds['port'];
    if (!empty($creds['user'])) $cmd[] = '--user=' . (string)$creds['user'];

    $db = (string)($creds['name'] ?? '');
    if ($db !== '') $cmd[] = $db;
    $cmd[] = '--execute=SELECT 1';

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = null;
    $pipes = [];

    try {
        $proc = @proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            $errDetail = 'No se pudo iniciar el proceso mysql para testear conexión.';
            return false;
        }

        // No escribimos nada
        if (isset($pipes[0]) && is_resource($pipes[0])) @fclose($pipes[0]);

        $stdout = (isset($pipes[1]) && is_resource($pipes[1])) ? stream_get_contents($pipes[1]) : '';
        $stderr = (isset($pipes[2]) && is_resource($pipes[2])) ? stream_get_contents($pipes[2]) : '';

        if (isset($pipes[1]) && is_resource($pipes[1])) @fclose($pipes[1]);
        if (isset($pipes[2]) && is_resource($pipes[2])) @fclose($pipes[2]);

        $code = @proc_close($proc);
        $proc = null;

        if ((int)$code !== 0) {
            $errDetail = trim((string)$stderr) !== '' ? trim((string)$stderr) : trim((string)$stdout);
            if ($errDetail === '') $errDetail = 'mysql devolvió exit ' . (int)$code;
            return false;
        }

        return true;

    } finally {
        flus_mysql_delete_defaults_file($defaultsFile);
        foreach ($pipes as $p) {
            if (is_resource($p)) @fclose($p);
        }
        if (is_resource($proc)) @proc_close($proc);
    }
}



/**
 * Restaura la base de datos desde un backup (.sql) ubicado en storage/backups.
 *
 * IMPORTANTE: esto reemplaza datos existentes.
 */
function backup_restore(string $file, ?string &$err = null): bool {
    $err = null;

    // Sanitizar y validar nombre
    $file = basename($file);
    if (!preg_match('/^[A-Za-z0-9_.-]+\.sql$/', $file)) {
        $err = 'Nombre de archivo inválido.';
        return false;
    }

    $dir  = backups_dir();
    $path = $dir . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        $err = 'Archivo no encontrado.';
        return false;
    }

    // Lock de restauración (evita doble restore)
    $lockPath = FLUS_ROOT . '/storage/restore.lock';
    $lockFp = @fopen($lockPath, 'c');
    if (!$lockFp) {
        $err = 'No se pudo crear lock de restauración.';
        return false;
    }
    if (!@flock($lockFp, LOCK_EX | LOCK_NB)) {
        @fclose($lockFp);
        $err = 'Ya hay una restauración en curso.';
        return false;
    }

    $maintenanceFlag = FLUS_ROOT . '/storage/maintenance.flag';

    $ok = false;
    $started = false;
    $bytesWritten = 0;

    // Asegurar que el proceso continúe aunque el usuario cierre el navegador
    @ignore_user_abort(true);
    @set_time_limit(0);

    $fh = null;
    $proc = null;
    $pipes = [];

    $defaultsFile = null; // temp defaults-extra-file for mysql


    // Meta de mantenimiento (se completa al iniciar)
    $meta = [
        'active' => true,
        'since'  => date('c'),
        'reason' => 'backup_restore',
        'file'   => $file,
    ];

    try {
        $mysql = find_mysql_cli();
        if (!$mysql) {
            $err = 'No se encontró mysql client (mysql.exe).';
            return false;
        }

        $creds = get_db_credentials();
        if (empty($creds['name'])) {
            $err = 'Error de configuración: DB_NAME no definida.';
            return false;
        }

        // ✅ Pre-flight: testear conexión antes de entrar en mantenimiento
        $testErr = null;
        if (!mysql_test_connection($mysql, $creds, $testErr)) {
            $err = 'No se pudo conectar a MySQL para restaurar: ' . ($testErr ?: 'error desconocido');
            return false;
        }

        $fh = @fopen($path, 'rb');
        if (!$fh) {
            $err = 'No se pudo abrir el archivo de backup.';
            return false;
        }

        // Activar mantenimiento (recién cuando estamos listos para importar)
        @file_put_contents($maintenanceFlag, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $cmd = [
            $mysql['path'],
        ];

        if (!empty($creds['pass'])) {
            $defaultsFile = flus_mysql_make_defaults_file($creds);
            if (!$defaultsFile) {
                $err = 'No se pudo crear el archivo temporal de credenciales para mysql.';
                return false;
            }
            // Debe ser la primera opción luego del binario
            $cmd[] = '--defaults-extra-file=' . $defaultsFile;
        }

        $cmd[] = '--protocol=tcp';
        $cmd[] = '--default-character-set=utf8mb4';

        if (!empty($creds['host'])) $cmd[] = '--host=' . (string)$creds['host'];
        if (!empty($creds['port'])) $cmd[] = '--port=' . (string)$creds['port'];
        if (!empty($creds['user'])) $cmd[] = '--user=' . (string)$creds['user'];

        $cmd[] = (string)$creds['name'];


        // Importar por STDIN (evita redirecciones y es más robusto)
        $descriptors = [
            0 => ['pipe', 'w'],
            1 => ['pipe', 'r'],
            2 => ['pipe', 'r'],
        ];

        $proc = @proc_open($cmd, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            $err = 'No se pudo iniciar el proceso mysql.';
            return false;
        }
        $started = true;

        // Escribir en stdin
        while (!feof($fh)) {
            $chunk = fread($fh, 1024 * 1024); // 1MB
            if ($chunk === false) break;
            if ($chunk === '') continue;

            $w = fwrite($pipes[0], $chunk);
            if ($w === false) break;
            $bytesWritten += (int)$w;
        }
        @fclose($fh);
        $fh = null;
        @fclose($pipes[0]);

        // Leer salida / errores
        $stdout = is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
        $stderr = is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
        if (is_resource($pipes[1])) @fclose($pipes[1]);
        if (is_resource($pipes[2])) @fclose($pipes[2]);

        $code = @proc_close($proc);
        $proc = null;

        if ((int)$code !== 0) {
            $err = 'mysql import falló (exit ' . (int)$code . '). ' . trim((string)$stderr);

            // Guardar error en maintenance.flag para debugging
            $meta['last_error']    = $err;
            $meta['last_error_at'] = date('c');
            $meta['mysql_exit']    = (int)$code;
            $meta['bytes_written'] = (int)$bytesWritten;
            $meta['mysql_stderr']  = mb_substr((string)$stderr, 0, 2000);

            @file_put_contents($maintenanceFlag, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            if (function_exists('app_log')) {
                app_log('ERROR', 'Backup restore failed', [
                    'file' => $file,
                    'code' => (int)$code,
                    'bytes' => (int)$bytesWritten,
                    'stderr' => (string)$stderr,
                    'stdout' => (string)$stdout
                ]);
            }
            return false;
        }

        $ok = true;
        if (function_exists('app_log')) {
            app_log('INFO', 'Backup restaurado', ['file' => $file]);
        }
        return true;

    } finally {
        if (is_resource($fh)) @fclose($fh);

        foreach ($pipes as $p) {
            if (is_resource($p)) @fclose($p);
        }

        if (is_resource($proc)) @proc_close($proc);

        // Borrar archivo temporal de credenciales (si se creó)
        flus_mysql_delete_defaults_file($defaultsFile);

        // Si salió OK, o si nunca llegó a importar, quitar mantenimiento.
        // Si falló habiendo importado, dejamos el flag para evitar operar en estado incierto.
        if (($ok || !$started) && is_file($maintenanceFlag)) {
            @unlink($maintenanceFlag);
        }

        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
    }
}



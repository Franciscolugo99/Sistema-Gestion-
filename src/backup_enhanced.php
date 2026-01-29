<?php
// src/backup_enhanced.php
declare(strict_types=1);

/**
 * FLUS Enhanced Backup System
 * Backups completos: DB + storage + metadata
 * Restore seguro con validación y auditoría
 * 
 * @version 1.0.0
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/version.php';
require_once __DIR__ . '/backup_lib.php';

// Constante para identificar backups mejorados
defined('FLUS_BACKUP_ENHANCED_VERSION') || define('FLUS_BACKUP_ENHANCED_VERSION', '1.0.0');

/**
 * Metadata del backup
 */

// ============================================================
// ZIP SAFETY HELPERS (anti Zip-Slip)
// ============================================================

/**
 * Normaliza y valida una entrada de ZIP para evitar path traversal.
 * Devuelve el path relativo normalizado con "/" o null si es inválido.
 */
function flus_backup_normalize_zip_entry(string $name): ?string {
    $name = str_replace('\\', '/', $name);
    $name = trim($name);

    if ($name === '' || strpos($name, "\0") !== false) return null;

    // Rechazar rutas absolutas o con drive letter (Windows)
    if (preg_match('#^/|^[A-Za-z]:#', $name)) return null;

    // Quitar prefijos "./"
    $name = preg_replace('#^\./#', '', $name);

    // Rechazar traversal
    if (strpos($name, '../') !== false) return null;

    // Colapsar dobles barras
    $name = preg_replace('#/+#', '/', $name);

    return $name;
}

/**
 * Une base + relativo de forma segura y garantiza que el destino quede dentro de base.
 * Retorna la ruta destino o false si es inválida.
 */
function flus_backup_safe_join(string $baseDir, string $relative) {
    $relative = flus_backup_normalize_zip_entry($relative);
    if ($relative === null) return false;

    $baseReal = realpath($baseDir);
    if (!$baseReal) return false;

    $destPath = rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $destDir  = dirname($destPath);

    if (!is_dir($destDir)) {
        @mkdir($destDir, 0755, true);
    }

    $destDirReal = realpath($destDir);
    if (!$destDirReal) return false;

    // Verificar que el directorio destino está dentro de base
    $baseRealNorm = rtrim($baseReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $destDirNorm  = rtrim($destDirReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    if (strpos($destDirNorm, $baseRealNorm) !== 0) return false;

    return $destPath;
}

/**
 * Extracción segura de ZIP: valida cada entrada y escribe dentro del destino.
 */
function flus_backup_extract_zip_safe(ZipArchive $zip, string $destDir, ?string &$err = null): bool {
    $destReal = realpath($destDir);
    if (!$destReal) {
        if (!@mkdir($destDir, 0755, true)) {
            $err = 'No se puede crear el directorio destino';
            return false;
        }
        $destReal = realpath($destDir);
        if (!$destReal) {
            $err = 'Directorio destino inválido';
            return false;
        }
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) continue;

        $norm = flus_backup_normalize_zip_entry($name);
        if ($norm === null) continue;

        // Directorios
        if (substr($norm, -1) === '/') {
            $dirPath = flus_backup_safe_join($destReal, $norm);
            if ($dirPath !== false && !is_dir($dirPath)) {
                @mkdir($dirPath, 0755, true);
            }
            continue;
        }

        $destPath = flus_backup_safe_join($destReal, $norm);
        if ($destPath === false) continue;

        $content = $zip->getFromIndex($i);
        if ($content === false) continue;

        @file_put_contents($destPath, $content);
    }

    return true;
}

function flus_backup_metadata(): array {
    return [
        'flus_version' => defined('FLUS_VERSION') ? FLUS_VERSION : 'unknown',
        'flus_build' => defined('FLUS_BUILD') ? FLUS_BUILD : 'unknown',
        'backup_version' => FLUS_BACKUP_ENHANCED_VERSION,
        'created_at' => date('c'),
        'created_by' => flus_backup_get_current_user(),
        'php_version' => PHP_VERSION,
        'mysql_version' => flus_backup_get_mysql_version(),
        'db_name' => defined('DB_NAME') ? DB_NAME : 'unknown',
        'checksum_algo' => 'sha256',
    ];
}

/**
 * Obtener usuario actual para auditoría
 */
function flus_backup_get_current_user(): ?array {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    
    $userId = $_SESSION['user_id'] ?? null;
    $userName = $_SESSION['user_nombre'] ?? $_SESSION['user_name'] ?? null;
    
    if ($userId) {
        return [
            'id' => (int)$userId,
            'name' => (string)$userName,
        ];
    }
    
    return null;
}

/**
 * Obtener versión de MySQL
 */
function flus_backup_get_mysql_version(): string {
    try {
        $pdo = getPDO();
        $stmt = $pdo->query('SELECT VERSION()');
        return $stmt ? (string)$stmt->fetchColumn() : 'unknown';
    } catch (Throwable $e) {
        return 'unknown';
    }
}

/**
 * Crear backup completo (DB + metadata)
 * Opcionalmente incluye archivos de storage
 */
function flus_backup_create_full(bool $includeStorage = false, ?string &$err = null): ?string {
    $err = null;
    
    // 1. Crear backup de DB normal
    $sqlFile = backup_create($err);
    if (!$sqlFile) {
        return null;
    }

    $backupDir = backups_dir();
    $sqlPath = $backupDir . DIRECTORY_SEPARATOR . $sqlFile;
    
    // 2. Generar metadata
    $metadata = flus_backup_metadata();
    
    // Calcular checksum del SQL
    $sqlChecksum = hash_file('sha256', $sqlPath);
    $metadata['sql_file'] = $sqlFile;
    $metadata['sql_checksum'] = $sqlChecksum;
    $metadata['sql_size'] = filesize($sqlPath);
    
    // 3. Crear archivo de metadata
    $baseName = pathinfo($sqlFile, PATHINFO_FILENAME);
    $metaFile = $baseName . '.meta.json';
    $metaPath = $backupDir . DIRECTORY_SEPARATOR . $metaFile;
    
    if (!@file_put_contents($metaPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
        $err = 'Error al crear archivo de metadata';
        flus_log_error('flus_backup_create_full: metadata creation failed', ['file' => $metaFile]);
        return null;
    }

    // 4. Si se solicita, crear ZIP con storage
    if ($includeStorage) {
        $zipResult = flus_backup_create_zip($sqlPath, $metaPath, $err);
        if ($zipResult) {
            // Limpiar archivos individuales
            @unlink($sqlPath);
            @unlink($metaPath);
            return basename($zipResult);
        }
        // Si falla el ZIP, devolver el SQL normal
        flus_log_warn('flus_backup_create_full: ZIP creation failed, returning SQL only', ['error' => $err]);
    }

    flus_log_info('Full backup created', [
        'sql_file' => $sqlFile,
        'meta_file' => $metaFile,
        'include_storage' => $includeStorage,
    ]);

    return $sqlFile;
}

/**
 * Crear archivo ZIP completo
 */
function flus_backup_create_zip(string $sqlPath, string $metaPath, ?string &$err = null): ?string {
    if (!class_exists('ZipArchive')) {
        $err = 'ZipArchive no está disponible en PHP';
        return null;
    }

    $backupDir = backups_dir();
    $baseName = pathinfo($sqlPath, PATHINFO_FILENAME);
    $zipFile = $baseName . '.flus.zip';
    $zipPath = $backupDir . DIRECTORY_SEPARATOR . $zipFile;

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        $err = 'No se pudo crear el archivo ZIP';
        return null;
    }

    // Agregar SQL
    $zip->addFile($sqlPath, 'database/' . basename($sqlPath));
    
    // Agregar metadata
    $zip->addFile($metaPath, 'metadata.json');

    // Agregar archivos de storage (selectivamente)
    $baseDir = defined('FLUS_ROOT') ? FLUS_ROOT : dirname(__DIR__);
    $storageDir = $baseDir . '/storage';

    // Archivos críticos de storage a incluir
    $criticalFiles = [
        'license.json',
        'license_state.json',
    ];

    foreach ($criticalFiles as $file) {
        $filePath = $storageDir . '/' . $file;
        if (is_file($filePath)) {
            $zip->addFile($filePath, 'storage/' . $file);
        }
    }

    // Agregar imágenes de productos (si no son muchas)
    $imgDir = $baseDir . '/public/img/productos';
    if (is_dir($imgDir)) {
        $imgFiles = glob($imgDir . '/*.{jpg,jpeg,png,gif,webp}', GLOB_BRACE);
        $totalSize = 0;
        $maxSize = 50 * 1024 * 1024; // Max 50MB de imágenes
        
        foreach ($imgFiles as $img) {
            $size = filesize($img);
            if ($totalSize + $size < $maxSize) {
                $zip->addFile($img, 'storage/img/productos/' . basename($img));
                $totalSize += $size;
            }
        }
    }

    $zip->close();

    return $zipPath;
}

/**
 * Validar archivo de backup antes de restaurar
 */
function flus_backup_validate(string $file, ?string &$err = null): array {
    $err = null;
    $result = [
        'valid' => false,
        'type' => null, // 'sql', 'sql_with_meta', 'zip'
        'metadata' => null,
        'sql_file' => null,
        'warnings' => [],
    ];

    $backupDir = backups_dir();
    $file = basename($file);
    
    // Determinar tipo de archivo
    if (preg_match('/\.flus\.zip$/i', $file)) {
        $result['type'] = 'zip';
        return flus_backup_validate_zip($file, $err);
    }
    
    if (preg_match('/\.sql$/i', $file)) {
        $result['type'] = 'sql';
        $sqlPath = $backupDir . DIRECTORY_SEPARATOR . $file;
        
        if (!is_file($sqlPath)) {
            $err = 'Archivo SQL no encontrado';
            return $result;
        }

        // Buscar metadata asociada
        $baseName = pathinfo($file, PATHINFO_FILENAME);
        $metaPath = $backupDir . DIRECTORY_SEPARATOR . $baseName . '.meta.json';
        
        if (is_file($metaPath)) {
            $result['type'] = 'sql_with_meta';
            $metaContent = @file_get_contents($metaPath);
            if ($metaContent) {
                $meta = @json_decode($metaContent, true);
                if (is_array($meta)) {
                    $result['metadata'] = $meta;
                    
                    // Verificar checksum
                    $currentChecksum = hash_file('sha256', $sqlPath);
                    if (isset($meta['sql_checksum']) && $meta['sql_checksum'] !== $currentChecksum) {
                        $result['warnings'][] = 'El checksum del archivo SQL no coincide (archivo posiblemente modificado)';
                    }
                    
                    // Verificar versión
                    $currentVersion = defined('FLUS_VERSION') ? FLUS_VERSION : 'unknown';
                    if (isset($meta['flus_version']) && $meta['flus_version'] !== $currentVersion) {
                        $result['warnings'][] = sprintf(
                            'Backup creado con FLUS %s, versión actual: %s',
                            $meta['flus_version'],
                            $currentVersion
                        );
                    }
                }
            }
        }

        // Validar contenido del SQL
        $validateResult = flus_backup_validate_sql_content($sqlPath);
        if (!$validateResult['valid']) {
            $err = $validateResult['error'];
            return $result;
        }
        
        $result['sql_file'] = $file;
        $result['valid'] = true;
        $result['sql_info'] = $validateResult;
        
        return $result;
    }

    $err = 'Tipo de archivo no soportado. Use .sql o .flus.zip';
    return $result;
}

/**
 * Validar contenido de archivo SQL
 */
function flus_backup_validate_sql_content(string $path): array {
    $result = [
        'valid' => false,
        'error' => null,
        'size' => 0,
        'has_drop_table' => false,
        'has_create_table' => false,
        'has_insert' => false,
        'tables_count' => 0,
    ];

    if (!is_file($path)) {
        $result['error'] = 'Archivo no encontrado';
        return $result;
    }

    $size = filesize($path);
    $result['size'] = $size;

    if ($size < 500) {
        $result['error'] = 'Archivo muy pequeño para ser un backup válido';
        return $result;
    }

    // Leer primeros KB para validar estructura
    $handle = @fopen($path, 'r');
    if (!$handle) {
        $result['error'] = 'No se puede leer el archivo';
        return $result;
    }

    $sample = fread($handle, 8192);
    fclose($handle);

    // Verificar que parece un dump de MySQL
    if (!preg_match('/mysqldump|MySQL dump|CREATE TABLE|INSERT INTO/i', $sample)) {
        $result['error'] = 'El archivo no parece ser un dump válido de MySQL';
        return $result;
    }

    $result['has_drop_table'] = (bool)preg_match('/DROP TABLE/i', $sample);
    $result['has_create_table'] = (bool)preg_match('/CREATE TABLE/i', $sample);
    $result['has_insert'] = (bool)preg_match('/INSERT INTO/i', $sample);

    // Contar tablas (aproximado)
    preg_match_all('/CREATE TABLE.*?`([^`]+)`/i', $sample, $matches);
    $result['tables_count'] = count($matches[1] ?? []);

    $result['valid'] = true;
    return $result;
}

/**
 * Validar archivo ZIP de backup
 */
function flus_backup_validate_zip(string $file, ?string &$err = null): array {
    $result = [
        'valid' => false,
        'type' => 'zip',
        'metadata' => null,
        'sql_file' => null,
        'warnings' => [],
    ];

    if (!class_exists('ZipArchive')) {
        $err = 'ZipArchive no disponible';
        return $result;
    }

    $backupDir = backups_dir();
    $zipPath = $backupDir . DIRECTORY_SEPARATOR . basename($file);

    if (!is_file($zipPath)) {
        $err = 'Archivo ZIP no encontrado';
        return $result;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        $err = 'No se puede abrir el archivo ZIP';
        return $result;
    }

    // Buscar metadata
    $metaContent = $zip->getFromName('metadata.json');
    if ($metaContent) {
        $meta = @json_decode($metaContent, true);
        if (is_array($meta)) {
            $result['metadata'] = $meta;
            $result['sql_file'] = $meta['sql_file'] ?? null;
        }
    }

    // Verificar que hay al menos un SQL
    $hasSql = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('/\.sql$/i', $name)) {
            $hasSql = true;
            if (!$result['sql_file']) {
                $result['sql_file'] = basename($name);
            }
            break;
        }
    }

    $zip->close();

    if (!$hasSql) {
        $err = 'El archivo ZIP no contiene ningún archivo SQL';
        return $result;
    }

    $result['valid'] = true;
    return $result;
}

/**
 * Restaurar backup con validación y auditoría
 */
function flus_backup_restore_safe(string $file, ?string &$err = null): bool {
    $err = null;
    $baseDir = defined('FLUS_ROOT') ? FLUS_ROOT : dirname(__DIR__);
    
    // 1. Validar archivo
    $validation = flus_backup_validate($file, $err);
    if (!$validation['valid']) {
        return false;
    }

    // 2. Obtener información de usuario para auditoría
    $user = flus_backup_get_current_user();
    
    // 3. Log de inicio
    $restoreId = bin2hex(random_bytes(8));
    flus_log_info('Backup restore started', [
        'restore_id' => $restoreId,
        'file' => $file,
        'type' => $validation['type'],
        'user' => $user,
        'metadata' => $validation['metadata'],
        'warnings' => $validation['warnings'],
    ]);

    // 4. Si es ZIP, extraer primero
    $sqlFile = $file;
    $tempDir = null;
    
    if ($validation['type'] === 'zip') {
        $tempDir = sys_get_temp_dir() . '/flus_restore_' . $restoreId;
        @mkdir($tempDir, 0755, true);
        
        $extractResult = flus_backup_extract_zip($file, $tempDir, $err);
        if (!$extractResult) {
            @rmdir($tempDir);
            return false;
        }
        
        // Buscar SQL extraído
        $sqlFiles = glob($tempDir . '/database/*.sql');
        if (!$sqlFiles) {
            $sqlFiles = glob($tempDir . '/*.sql');
        }
        
        if (!$sqlFiles) {
            $err = 'No se encontró archivo SQL en el ZIP';
            flus_backup_cleanup_temp($tempDir);
            return false;
        }
        
        // Copiar SQL a backups para usar restore normal
        $tempSqlName = 'temp_restore_' . $restoreId . '.sql';
        $tempSqlPath = backups_dir() . DIRECTORY_SEPARATOR . $tempSqlName;
        
        if (!@copy($sqlFiles[0], $tempSqlPath)) {
            $err = 'No se pudo copiar archivo SQL temporal';
            flus_backup_cleanup_temp($tempDir);
            return false;
        }
        
        $sqlFile = $tempSqlName;
    }

    // 5. Realizar restore usando función existente
    $restoreResult = backup_restore($sqlFile, $err);
    
    // 6. Limpiar archivos temporales
    if ($tempDir) {
        flus_backup_cleanup_temp($tempDir);
        @unlink(backups_dir() . DIRECTORY_SEPARATOR . $sqlFile);
    }

    // 7. Si el ZIP incluye storage, restaurar archivos
    if ($restoreResult && $validation['type'] === 'zip' && $tempDir) {
        flus_backup_restore_storage_files($file, $baseDir);
    }

    // 8. Registrar auditoría en DB (después del restore)
    if ($restoreResult) {
        flus_backup_log_audit('BACKUP_RESTORE', [
            'restore_id' => $restoreId,
            'file' => basename($file),
            'type' => $validation['type'],
            'metadata' => $validation['metadata'],
            'user' => $user,
            'result' => 'SUCCESS',
        ]);
    }

    // 9. Log de finalización
    flus_log_info('Backup restore completed', [
        'restore_id' => $restoreId,
        'success' => $restoreResult,
        'error' => $err,
    ]);

    return $restoreResult;
}

/**
 * Extraer archivo ZIP
 */
function flus_backup_extract_zip(string $file, string $destDir, ?string &$err = null): bool {
    if (!class_exists('ZipArchive')) {
        $err = 'ZipArchive no disponible';
        return false;
    }

    $backupDir = backups_dir();
    $zipPath = $backupDir . DIRECTORY_SEPARATOR . basename($file);

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        $err = 'No se puede abrir el archivo ZIP';
        return false;
    }

    $result = flus_backup_extract_zip_safe($zip, $destDir, $err);
    $zip->close();

    if (!$result) {
        $err = 'Error al extraer el archivo ZIP';
        return false;
    }

    return true;
}

/**
 * Limpiar directorio temporal
 */
function flus_backup_cleanup_temp(string $dir): void {
    if (!is_dir($dir)) return;
    
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
        if ($file->isDir()) {
            @rmdir($file->getRealPath());
        } else {
            @unlink($file->getRealPath());
        }
    }

    @rmdir($dir);
}

/**
 * Restaurar archivos de storage desde ZIP
 */
function flus_backup_restore_storage_files(string $zipFile, string $baseDir): void {
    if (!class_exists('ZipArchive')) return;

    $backupDir = backups_dir();
    $zipPath = $backupDir . DIRECTORY_SEPARATOR . basename($zipFile);

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return;

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        
        // Solo restaurar archivos de storage/ y storage/img/
        if (!preg_match('/^storage\//', $name)) continue;
        if (preg_match('/\.sql$/i', $name)) continue; // No restaurar SQL otra vez
        
        $norm = flus_backup_normalize_zip_entry($name);
        if ($norm === null) continue;

        $destPath = flus_backup_safe_join($baseDir, $norm);
        if ($destPath === false) continue;

        $content = $zip->getFromIndex($i);
        if ($content !== false) {
            @file_put_contents($destPath, $content);
        }
        }

    $zip->close();
    
    flus_log_info('Storage files restored from backup ZIP');
}

/**
 * Registrar evento de auditoría de backup
 */
function flus_backup_log_audit(string $action, array $data): void {
    try {
        $pdo = getPDO();
        
        // Verificar si existe tabla audit_log
        $stmt = $pdo->query("SHOW TABLES LIKE 'audit_log'");
        if (!$stmt || !$stmt->fetchColumn()) {
            return;
        }

        // Verificar columnas disponibles
        $userId = $data['user']['id'] ?? null;
        $metaJson = json_encode($data, JSON_UNESCAPED_UNICODE);
        
        // Usar audit_log_event si está disponible
        if (function_exists('audit_log_event')) {
            audit_log_event($pdo, $userId, $action, 'BACKUP', null, $data);
            return;
        }

        // Fallback: insert directo
        $cols = $pdo->query("SHOW COLUMNS FROM audit_log")->fetchAll(PDO::FETCH_COLUMN);
        $hasMetaCol = in_array('meta', $cols) || in_array('meta_json', $cols);
        $metaColName = in_array('meta', $cols) ? 'meta' : 'meta_json';

        if ($hasMetaCol) {
            $sql = "INSERT INTO audit_log (user_id, action, entity, {$metaColName}) VALUES (?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId, $action, 'BACKUP', $metaJson]);
        } else {
            $sql = "INSERT INTO audit_log (user_id, action, entity) VALUES (?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$userId, $action, 'BACKUP']);
        }

    } catch (Throwable $e) {
        flus_log_error('Audit log failed for backup action', [
            'action' => $action,
            'error' => $e->getMessage(),
        ]);
    }
}

/**
 * Listar backups con metadata
 */
function flus_backup_list_enhanced(): array {
    $items = backup_list(); // Usar función existente
    $backupDir = backups_dir();
    
    $enhanced = [];
    
    foreach ($items as $item) {
        $file = $item['file'];
        $baseName = pathinfo($file, PATHINFO_FILENAME);
        
        // Buscar metadata asociada
        $metaPath = $backupDir . DIRECTORY_SEPARATOR . $baseName . '.meta.json';
        $metadata = null;
        
        if (is_file($metaPath)) {
            $content = @file_get_contents($metaPath);
            if ($content) {
                $metadata = @json_decode($content, true);
            }
        }
        
        $enhanced[] = array_merge($item, [
            'has_metadata' => $metadata !== null,
            'metadata' => $metadata,
            'type' => preg_match('/\.flus\.zip$/i', $file) ? 'full' : 'sql',
        ]);
    }
    
    return $enhanced;
}

/**
 * Obtener información detallada de un backup
 */
function flus_backup_info(string $file): array {
    $validation = flus_backup_validate($file, $err);
    
    return [
        'file' => basename($file),
        'valid' => $validation['valid'],
        'type' => $validation['type'],
        'metadata' => $validation['metadata'],
        'sql_file' => $validation['sql_file'],
        'warnings' => $validation['warnings'],
        'validation_error' => $err,
    ];
}

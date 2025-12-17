<?php
// src/backup_lib.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/logger.php';

function backups_dir(): string {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';
}

function backups_ensure_dirs(): void {
    $dir = backups_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

/**
 * Quote compatible (Linux/Windows) for shell commands.
 */
function sh_quote(string $s): string {
    // En Windows, escapeshellarg usa comillas simples, que suelen romper cmd.
    if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
        return '"' . str_replace('"', '\\"', $s) . '"';
    }
    return escapeshellarg($s);
}

function find_mysqldump(): ?string {
    // 1) Si está en PATH
    $candidates = ['mysqldump'];

    // 2) Rutas típicas XAMPP (Windows)
    if (stripos(PHP_OS_FAMILY, 'Windows') === 0) {
        $candidates[] = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
        $candidates[] = 'C:\\xampp\\mysql\\bin\\mysqldump';
    }

    foreach ($candidates as $c) {
        if ($c === 'mysqldump') {
            // Probar existencia a través del comando
            $out = [];
            $code = 0;
            @exec('mysqldump --version 2>NUL', $out, $code);
            if ($code === 0) return 'mysqldump';
            continue;
        }
        if (is_file($c)) return $c;
    }
    return null;
}

/**
 * Crea un backup .sql en /storage/backups y devuelve el nombre del archivo.
 */
function backup_create(?string &$err = null): ?string {
    backups_ensure_dirs();

    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;

    $mysqldump = find_mysqldump();
    if (!$mysqldump) {
        $err = 'No se encontró mysqldump. En XAMPP debería estar en C:\\xampp\\mysql\\bin\\mysqldump.exe';
        app_log('ERROR', 'backup_create: mysqldump not found');
        return null;
    }

    $ts = date('Ymd_His');
    $fileName = sprintf('%s_%s.sql', $DB_NAME ?: 'db', $ts);
    $fullPath = backups_dir() . DIRECTORY_SEPARATOR . $fileName;

    $hostArg = '--host=' . sh_quote((string)$DB_HOST);
    $userArg = '--user=' . sh_quote((string)$DB_USER);
    $passArg = '';
    $dbArg   = sh_quote((string)$DB_NAME);

    if ((string)$DB_PASS !== '') {
        // mysqldump acepta --password=... (sin espacio)
        $passArg = '--password=' . sh_quote((string)$DB_PASS);
    }

    // Flags recomendados
    $flags = implode(' ', [
        '--default-character-set=utf8mb4',
        '--single-transaction',
        '--routines',
        '--triggers',
        '--events',
        '--add-drop-table',
        '--skip-comments'
    ]);

    // Redirección a archivo
    $cmd = sh_quote($mysqldump) . " $flags $hostArg $userArg " . ($passArg ? "$passArg " : '') . "$dbArg > " . sh_quote($fullPath) . " 2>&1";

    $out = [];
    $code = 0;
    @exec($cmd, $out, $code);

    if ($code !== 0 || !is_file($fullPath) || filesize($fullPath) < 200) {
        @unlink($fullPath);
        $err = 'Error creando backup. ' . implode("\n", $out);
        app_log('ERROR', 'backup_create failed', ['code' => $code, 'out' => $out]);
        return null;
    }

    app_log('INFO', 'Backup creado', ['file' => $fileName, 'size' => filesize($fullPath)]);
    backup_rotate(30);
    return $fileName;
}

/**
 * Lista backups disponibles.
 * @return array<int,array{file:string, path:string, size:int, mtime:int}>
 */
function backup_list(): array {
    backups_ensure_dirs();
    $dir = backups_dir();

    $items = [];
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.sql') as $path) {
        if (!is_file($path)) continue;
        $items[] = [
            'file' => basename($path),
            'path' => $path,
            'size' => (int)filesize($path),
            'mtime' => (int)filemtime($path),
        ];
    }

    usort($items, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $items;
}

function backup_delete(string $file, ?string &$err = null): bool {
    $file = basename($file);
    if (!preg_match('/^[A-Za-z0-9._-]+\.sql$/', $file)) {
        $err = 'Nombre de archivo inválido.';
        return false;
    }

    $path = backups_dir() . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        $err = 'El archivo no existe.';
        return false;
    }

    if (!@unlink($path)) {
        $err = 'No se pudo borrar el archivo.';
        return false;
    }

    app_log('INFO', 'Backup borrado', ['file' => $file]);
    return true;
}

function backup_rotate(int $keep = 30): void {
    $items = backup_list();
    if (count($items) <= $keep) return;

    $toDelete = array_slice($items, $keep);
    foreach ($toDelete as $it) {
        @unlink($it['path']);
    }
}

function fmt_bytes(int $bytes): string {
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    $n = (float)$bytes;
    while ($n >= 1024 && $i < count($units)-1) {
        $n /= 1024;
        $i++;
    }
    return number_format($n, $i === 0 ? 0 : 2, ',', '.') . ' ' . $units[$i];
}

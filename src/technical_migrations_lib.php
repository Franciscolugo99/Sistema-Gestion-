<?php
declare(strict_types=1);

require_once __DIR__ . '/migrations_runner.php';
require_once __DIR__ . '/logger.php';

function flus_technical_migration_files(string $migrationsDir): array
{
    $files = glob(rtrim($migrationsDir, '/\\') . '/*.sql') ?: [];
    sort($files, SORT_NATURAL);
    return $files;
}

function flus_technical_schema_migrations_exists(PDO $pdo): bool
{
    $stmt = $pdo->prepare("
        SELECT 1
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'schema_migrations'
        LIMIT 1
    ");
    $stmt->execute();
    return (bool)$stmt->fetchColumn();
}

function flus_technical_migration_status(PDO $pdo, string $migrationsDir): array
{
    if (!is_dir($migrationsDir)) {
        throw new RuntimeException('No existe la carpeta de migraciones del sistema.');
    }

    $files = flus_technical_migration_files($migrationsDir);
    $collisions = flus_migration_sequence_collisions($files);
    flus_assert_migration_sequence_policy($collisions);

    $applied = [];
    if (flus_technical_schema_migrations_exists($pdo)) {
        $stmt = $pdo->query('SELECT filename, checksum, applied_at FROM schema_migrations');
        while ($stmt && ($row = $stmt->fetch(PDO::FETCH_ASSOC))) {
            $applied[(string)$row['filename']] = [
                'checksum' => (string)($row['checksum'] ?? ''),
                'applied_at' => (string)($row['applied_at'] ?? ''),
            ];
        }
    }

    $pending = [];
    $checksumWarnings = [];
    $known = [];

    foreach ($files as $file) {
        $name = basename($file);
        $known[] = $name;
        if (!isset($applied[$name])) {
            $pending[] = $name;
            continue;
        }

        $storedChecksum = (string)($applied[$name]['checksum'] ?? '');
        $currentChecksum = flus_sha1_file($file);
        if ($storedChecksum !== '' && $currentChecksum !== '' && !hash_equals($storedChecksum, $currentChecksum)) {
            $checksumWarnings[] = $name;
        }
    }

    return [
        'total' => count($known),
        'applied' => count($known) - count($pending),
        'pending' => $pending,
        'pending_count' => count($pending),
        'latest' => $known !== [] ? $known[array_key_last($known)] : null,
        'checksum_warnings' => $checksumWarnings,
        'sequence_collisions' => $collisions,
        'tracking_ready' => flus_technical_schema_migrations_exists($pdo),
    ];
}

function flus_technical_migration_lock_file(string $root): string
{
    $dir = rtrim($root, '/\\') . '/storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/technical_migrations.lock';
}

function flus_technical_open_migration_lock(string $root, int $userId, ?string &$error = null)
{
    $handle = @fopen(flus_technical_migration_lock_file($root), 'c+');
    if (!is_resource($handle)) {
        $error = 'No se pudo abrir el lock de migraciones.';
        return null;
    }

    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        $error = 'Ya hay una actualizacion de base en curso. Espera a que termine.';
        return null;
    }

    ftruncate($handle, 0);
    fwrite($handle, json_encode([
        'pid' => getmypid(),
        'started_at' => date('c'),
        'user_id' => $userId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($handle);

    return $handle;
}

function flus_technical_run_pending_migrations(
    PDO $pdo,
    string $root,
    int $userId,
    ?string &$error = null
): ?array {
    require_once __DIR__ . '/audit_events.php';

    $root = rtrim($root, '/\\');
    $migrationsDir = $root . '/migrations';
    $status = flus_technical_migration_status($pdo, $migrationsDir);
    if ((int)($status['pending_count'] ?? 0) === 0) {
        return ['applied' => [], 'pending_before' => 0, 'ran_at' => date('c')];
    }

    $lockError = null;
    $lockHandle = flus_technical_open_migration_lock($root, $userId, $lockError);
    if (!is_resource($lockHandle)) {
        $error = $lockError ?: 'No se pudo bloquear la ejecucion de migraciones.';
        return null;
    }

    $maintenancePath = $root . '/storage/maintenance.flag';
    $maintenanceHandle = null;
    $maintenanceCreated = false;

    try {
        $maintenanceHandle = @fopen($maintenancePath, 'x');
        if (!is_resource($maintenanceHandle)) {
            $error = 'El sistema ya esta en mantenimiento. Finaliza esa tarea antes de migrar.';
            return null;
        }

        $maintenanceCreated = true;
        fwrite($maintenanceHandle, json_encode([
            'operation' => 'technical_migrations',
            'started_at' => date('c'),
            'user_id' => $userId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($maintenanceHandle);
        fclose($maintenanceHandle);
        $maintenanceHandle = null;

        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $result = flus_apply_migrations($pdo, $migrationsDir, true);
        $applied = array_values(array_map(
            static fn(array $item): string => (string)($item['file'] ?? ''),
            (array)($result['applied'] ?? [])
        ));
        $applied = array_values(array_filter($applied, static fn(string $name): bool => $name !== ''));

        audit_event(AuditEvents::SYSTEM_CONFIG_CHANGE, AuditEntities::SYSTEM, null, [
            'operation' => 'RUN_MIGRATIONS',
            'result' => 'OK',
            'applied' => $applied,
            'pending_before' => (int)($status['pending_count'] ?? 0),
        ], $userId, $pdo);
        flus_log_info('technical migrations finished', [
            'applied' => $applied,
            'pending_before' => (int)($status['pending_count'] ?? 0),
            'user_id' => $userId,
        ]);

        return [
            'applied' => $applied,
            'pending_before' => (int)($status['pending_count'] ?? 0),
            'ran_at' => date('c'),
        ];
    } catch (Throwable $e) {
        flus_log_error('technical migrations failed', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'user_id' => $userId,
        ]);
        audit_event(AuditEvents::SYSTEM_CONFIG_CHANGE, AuditEntities::SYSTEM, null, [
            'operation' => 'RUN_MIGRATIONS',
            'result' => 'ERROR',
            'error_type' => get_class($e),
        ], $userId, $pdo);
        $error = 'No se pudieron completar las migraciones. Revisa los logs tecnicos antes de reintentar.';
        return null;
    } finally {
        if (is_resource($maintenanceHandle)) {
            fclose($maintenanceHandle);
        }
        if ($maintenanceCreated && is_file($maintenancePath)) {
            @unlink($maintenancePath);
        }
        if (is_resource($lockHandle)) {
            @flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}

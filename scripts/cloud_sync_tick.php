<?php
declare(strict_types=1);

$root = dirname(__DIR__);
defined('FLUS_ROOT') || define('FLUS_ROOT', $root);

$config = $root . '/src/config.php';
if (!is_file($config)) {
    fwrite(STDERR, "CONFIG_MISSING\n");
    exit(2);
}

require_once $config;
require_once $root . '/src/db_helpers.php';
require_once $root . '/src/cloud_sync_lib.php';

$storageDir = $root . '/storage';
$statePath = $storageDir . '/cloud_sync_tick_state.json';
$lockPath = $storageDir . '/cloud_sync_tick.lock';
$stockLimit = isset($argv[1]) ? max(1, min(500, (int)$argv[1])) : 250;
$pushLimit = isset($argv[2]) ? max(1, min(100, (int)$argv[2])) : 50;
$interval = defined('FLUS_CLOUD_SYNC_STOCK_SNAPSHOT_INTERVAL_SEC')
    ? max(300, (int)FLUS_CLOUD_SYNC_STOCK_SNAPSHOT_INTERVAL_SEC)
    : 900;
$heartbeatInterval = defined('FLUS_CLOUD_SYNC_HEARTBEAT_INTERVAL_SEC')
    ? max(60, (int)FLUS_CLOUD_SYNC_HEARTBEAT_INTERVAL_SEC)
    : 300;

if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0775, true);
}

$lockHandle = @fopen($lockPath, 'c+');
if (!is_resource($lockHandle) || !@flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo json_encode(['ok' => true, 'busy' => true, 'message' => 'WORKER_ALREADY_RUNNING']) . PHP_EOL;
    exit(0);
}

function flus_cloud_tick_read_state(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : [];
}

function flus_cloud_tick_write_state(string $path, array $state): void
{
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return;
    }

    $temporary = $path . '.tmp';
    if (@file_put_contents($temporary, $json, LOCK_EX) !== false) {
        @rename($temporary, $path);
    }
}

function flus_cloud_tick_error_code(string $error): string
{
    $error = preg_replace('/[^A-Za-z0-9._:-]/', '', strtoupper(trim($error))) ?: 'WORKER_FAILED';
    return substr($error, 0, 80);
}

$state = flus_cloud_tick_read_state($statePath);
$startedAt = microtime(true);
$now = time();
$state['last_attempt_ts'] = $now;
$state['last_attempt_at'] = date(DATE_ATOM, $now);

try {
    $pdo = getPDO();
    $lastHeartbeatTs = (int)($state['last_heartbeat_ts'] ?? 0);
    $shouldHeartbeat = $lastHeartbeatTs <= 0 || ($now - $lastHeartbeatTs) >= $heartbeatInterval;
    $heartbeat = null;
    if ($shouldHeartbeat) {
        $heartbeat = flus_cloud_sync_preflight();
        $state['last_heartbeat_attempt_ts'] = $now;
        $state['last_heartbeat_attempt_at'] = date(DATE_ATOM, $now);
        if (!empty($heartbeat['ok'])) {
            $state['last_heartbeat_ts'] = $now;
            $state['last_heartbeat_at'] = date(DATE_ATOM, $now);
            $state['last_heartbeat_error'] = null;
        } else {
            $state['last_heartbeat_error'] = flus_cloud_tick_error_code((string)($heartbeat['error'] ?? 'HEARTBEAT_FAILED'));
        }
    }

    $lastStockSnapshotTs = (int)($state['last_stock_snapshot_ts'] ?? 0);
    $shouldSnapshotStock = $lastStockSnapshotTs <= 0 || ($now - $lastStockSnapshotTs) >= $interval;
    $snapshot = null;

    if ($shouldSnapshotStock) {
        $snapshot = flus_cloud_sync_enqueue_stock_snapshot($pdo, null, 'tick', $stockLimit);
        if ((int)($snapshot['queued'] ?? 0) > 0) {
            $state['last_stock_snapshot_ts'] = $now;
            $state['last_stock_snapshot_at'] = date(DATE_ATOM, $now);
        }
    }

    $push = flus_cloud_sync_push($pdo, $pushLimit);
    $ok = !empty($push['ok']) && ($heartbeat === null || !empty($heartbeat['ok']));
    $state['last_duration_ms'] = (int)round((microtime(true) - $startedAt) * 1000);
    $state['last_sent_count'] = max(0, (int)($push['sent'] ?? 0));
    $state['last_counts'] = is_array($push['counts'] ?? null) ? $push['counts'] : [];

    if ($ok) {
        $state['last_success_ts'] = time();
        $state['last_success_at'] = date(DATE_ATOM, (int)$state['last_success_ts']);
        $state['last_error'] = null;
    } else {
        $state['last_error'] = flus_cloud_tick_error_code((string)($push['error'] ?? 'SYNC_FAILED'));
    }

    flus_cloud_tick_write_state($statePath, $state);
    echo json_encode([
        'ok' => $ok,
        'heartbeat' => $heartbeat,
        'stock_snapshot' => $snapshot,
        'push' => $push,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($ok ? 0 : 1);
} catch (Throwable $e) {
    $state['last_duration_ms'] = (int)round((microtime(true) - $startedAt) * 1000);
    $state['last_sent_count'] = 0;
    $state['last_error'] = 'WORKER_FAILED';
    flus_cloud_tick_write_state($statePath, $state);
    error_log('[FLUS][cloud-sync] scheduled worker failed');
    fwrite(STDERR, "WORKER_FAILED\n");
    exit(1);
} finally {
    @flock($lockHandle, LOCK_UN);
    @fclose($lockHandle);
}

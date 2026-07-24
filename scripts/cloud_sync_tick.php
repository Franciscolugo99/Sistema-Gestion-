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

$stockLimit = isset($argv[1]) ? max(1, min(500, (int)$argv[1])) : 250;
$pushLimit = isset($argv[2]) ? max(1, min(100, (int)$argv[2])) : 50;
$interval = defined('FLUS_CLOUD_SYNC_STOCK_SNAPSHOT_INTERVAL_SEC')
    ? max(300, (int)FLUS_CLOUD_SYNC_STOCK_SNAPSHOT_INTERVAL_SEC)
    : 900;

$statePath = $root . '/storage/cloud_sync_tick_state.json';
$state = [];
if (is_file($statePath)) {
    $raw = @file_get_contents($statePath);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $state = $decoded;
    }
}

$now = time();
$lastStockSnapshotTs = (int)($state['last_stock_snapshot_ts'] ?? 0);
$shouldSnapshotStock = $lastStockSnapshotTs <= 0 || ($now - $lastStockSnapshotTs) >= $interval;

try {
    $pdo = getPDO();
    $snapshot = null;
    if ($shouldSnapshotStock) {
        $snapshot = flus_cloud_sync_enqueue_stock_snapshot($pdo, null, 'tick', $stockLimit);
        if ((int)($snapshot['queued'] ?? 0) > 0) {
            $state['last_stock_snapshot_ts'] = $now;
            $state['last_stock_snapshot_at'] = date(DATE_ATOM, $now);
        }
    }

    $push = flus_cloud_sync_push($pdo, $pushLimit);

    if (is_dir(dirname($statePath))) {
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            @file_put_contents($statePath, $json, LOCK_EX);
        }
    }

    $result = [
        'ok' => !empty($push['ok']),
        'stock_snapshot' => $snapshot,
        'push' => $push,
    ];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(!empty($push['ok']) ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR - ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

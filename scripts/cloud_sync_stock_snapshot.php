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

$limit = isset($argv[1]) ? max(1, min(500, (int)$argv[1])) : 250;

try {
    $pdo = getPDO();
    $snapshot = flus_cloud_sync_enqueue_stock_snapshot($pdo, null, 'cli', $limit);
    if ((int)($snapshot['queued'] ?? 0) <= 0) {
        fwrite(STDERR, 'ERROR - No se pudo preparar stock: ' . (string)($snapshot['reason'] ?? 'STOCK_EMPTY') . PHP_EOL);
        exit(1);
    }

    $push = flus_cloud_sync_push($pdo, 20);
    if (empty($push['ok'])) {
        fwrite(STDERR, 'ERROR - Stock en cola, envio fallido: ' . (string)($push['error'] ?? 'SYNC_FAILED') . PHP_EOL);
        exit(2);
    }

    echo 'OK - Stock sincronizado' . PHP_EOL;
    echo ' - Productos: ' . (int)($snapshot['products'] ?? 0) . PHP_EOL;
    echo ' - Eventos enviados: ' . (int)($push['sent'] ?? 0) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR - ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

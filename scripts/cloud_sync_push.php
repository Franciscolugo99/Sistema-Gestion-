<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$config = $root . '/src/config.php';
if (!is_file($config)) {
    fwrite(STDERR, "CONFIG_MISSING\n");
    exit(2);
}

require_once $config;
require_once $root . '/src/db_helpers.php';
require_once $root . '/src/cloud_sync_lib.php';

$pdo = getPDO();
$limit = isset($argv[1]) ? (int)$argv[1] : 50;
$result = flus_cloud_sync_push($pdo, $limit);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);

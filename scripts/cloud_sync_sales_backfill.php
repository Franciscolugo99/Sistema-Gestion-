<?php
declare(strict_types=1);

$root = dirname(__DIR__);
defined('FLUS_ROOT') || define('FLUS_ROOT', $root);

require_once $root . '/src/config.php';
require_once $root . '/src/cloud_sync_sales_backfill_lib.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['enqueue', 'from:', 'to:', 'after-id:', 'limit:']);
$enqueue = array_key_exists('enqueue', $options);
$backfillOptions = [
    'after_id' => (int)($options['after-id'] ?? 0),
    'limit' => (int)($options['limit'] ?? 100),
    'from' => (string)($options['from'] ?? ''),
    'to' => (string)($options['to'] ?? ''),
];

try {
    $pdo = getPDO();
} catch (Throwable $e) {
    error_log('[FLUS][cloud-sync] historical sales backfill could not connect to DB');
    fwrite(STDERR, "DB_UNAVAILABLE\n");
    exit(2);
}
try {
    $summary = flus_cloud_sync_sales_backfill($pdo, $backfillOptions, $enqueue);
} catch (InvalidArgumentException | RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($summary['failed'] > 0 ? 1 : 0);

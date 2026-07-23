<?php
declare(strict_types=1);

require_once FLUS_ROOT . '/src/cloud_sync_lib.php';

$limit = (int)($body['limit'] ?? $_GET['limit'] ?? 50);
$result = flus_cloud_sync_push($pdo, $limit);

if (!empty($result['ok'])) {
    json_ok($result);
}

json_fail('No se pudo sincronizar con la nube.', 409, [
    'error_code' => (string)($result['error'] ?? 'SYNC_FAILED'),
    'sync' => $result,
]);

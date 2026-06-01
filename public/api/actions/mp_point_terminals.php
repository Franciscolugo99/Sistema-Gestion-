<?php
declare(strict_types=1);

require_once FLUS_ROOT . '/src/mercadopago_qr_lib.php';

$storeId = isset($_GET['store_id']) ? trim((string)$_GET['store_id']) : null;
$posId = isset($_GET['pos_id']) ? trim((string)$_GET['pos_id']) : null;

$result = flus_mp_point_list_terminals($storeId, $posId);
if (!($result['ok'] ?? false)) {
    json_fail((string)($result['error'] ?? 'No se pudieron listar terminales Point'), (int)($result['status'] ?? 500) ?: 500, [
        'mp_status' => (int)($result['status'] ?? 0),
        'mp_response' => $result['response'] ?? null,
    ]);
}

json_ok([
    'terminals' => $result['terminals'] ?? [],
    'raw' => $result['raw'] ?? null,
]);

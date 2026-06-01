<?php
declare(strict_types=1);

require_once FLUS_ROOT . '/src/mercadopago_qr_lib.php';

$orderId = trim((string)($body['order_id'] ?? ''));
$result = flus_mp_qr_cancel_order($orderId);
if (!($result['ok'] ?? false)) {
    json_fail((string)($result['error'] ?? 'No se pudo cancelar la order'), (int)($result['status'] ?? 500) ?: 500, [
        'mp_status' => (int)($result['status'] ?? 0),
        'mp_response' => $result['response'] ?? null,
    ]);
}

$order = isset($result['response']) && is_array($result['response'])
    ? flus_mp_qr_normalize_order($result['response'])
    : null;

json_ok(['order' => $order]);

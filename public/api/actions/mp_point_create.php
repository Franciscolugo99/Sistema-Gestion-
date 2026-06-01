<?php
declare(strict_types=1);

require_once FLUS_ROOT . '/src/mercadopago_qr_lib.php';

$amount = isset($body['amount']) ? (float)$body['amount'] : 0.0;
$terminalId = isset($body['terminal_id']) ? trim((string)$body['terminal_id']) : null;
$paymentType = isset($body['payment_type']) ? trim((string)$body['payment_type']) : 'credit_card';
$ticketNumber = isset($body['ticket_number']) ? trim((string)$body['ticket_number']) : null;

if (!flus_mp_point_is_configured() && ($terminalId === null || $terminalId === '')) {
    json_fail('Mercado Pago Point no esta configurado. Completa FLUS_MP_POINT_TERMINAL_ID en src/config_mp.php.', 409);
}

$result = flus_mp_point_create_order($amount, $terminalId, $paymentType, $ticketNumber);
if (!($result['ok'] ?? false)) {
    json_fail((string)($result['error'] ?? 'No se pudo crear la order Point'), (int)($result['status'] ?? 500) ?: 500, [
        'mp_status' => (int)($result['status'] ?? 0),
        'mp_response' => $result['response'] ?? null,
    ]);
}

json_ok(['order' => $result['order']]);

<?php
declare(strict_types=1);

require_once FLUS_ROOT . '/src/mercadopago_qr_lib.php';

$amount = isset($body['amount']) ? (float)$body['amount'] : 0.0;
$description = isset($body['description']) ? trim((string)$body['description']) : null;
$mode = isset($body['mode']) ? trim((string)$body['mode']) : null;

if (!flus_mp_qr_is_configured()) {
    json_fail('Mercado Pago no esta configurado. Copia src/config_mp.example.php a src/config_mp.php y completa token + external_pos_id.', 409);
}

$result = flus_mp_qr_create_order($amount, $description, $mode);
if (!($result['ok'] ?? false)) {
    json_fail((string)($result['error'] ?? 'No se pudo crear la order'), (int)($result['status'] ?? 500) ?: 500, [
        'mp_status' => (int)($result['status'] ?? 0),
        'mp_response' => $result['response'] ?? null,
    ]);
}

json_ok(['order' => $result['order']]);

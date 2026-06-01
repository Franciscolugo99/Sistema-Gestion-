<?php
declare(strict_types=1);

$__flusMpConfig = __DIR__ . '/config_mp.php';
if (is_file($__flusMpConfig)) {
    require_once $__flusMpConfig;
}

function flus_mp_qr_config_value(string $constant, string $env, string $default = ''): string
{
    if (defined($constant)) {
        return trim((string)constant($constant));
    }

    $value = getenv($env);
    return is_string($value) ? trim($value) : $default;
}

function flus_mp_qr_config_bool(string $constant, string $env, bool $default = false): bool
{
    $raw = flus_mp_qr_config_value($constant, $env, $default ? '1' : '0');
    if ($raw === '') {
        return $default;
    }

    $normalized = strtolower($raw);
    if (in_array($normalized, ['1', 'true', 'yes', 'si', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return $default;
}

function flus_mp_qr_access_token(): string
{
    return flus_mp_qr_config_value('FLUS_MP_ACCESS_TOKEN', 'FLUS_MP_ACCESS_TOKEN');
}

function flus_mp_cashier_mode(): string
{
    $mode = strtolower(flus_mp_qr_config_value('FLUS_MP_CASHIER_MODE', 'FLUS_MP_CASHIER_MODE', 'automatic'));
    return in_array($mode, ['automatic', 'manual'], true) ? $mode : 'automatic';
}

function flus_mp_cashier_automatic_enabled(): bool
{
    return flus_mp_cashier_mode() === 'automatic';
}

function flus_mp_manual_fallback_enabled(): bool
{
    return flus_mp_qr_config_bool('FLUS_MP_MANUAL_FALLBACK', 'FLUS_MP_MANUAL_FALLBACK', true);
}

function flus_mp_qr_external_pos_id(): string
{
    return flus_mp_qr_config_value('FLUS_MP_QR_EXTERNAL_POS_ID', 'FLUS_MP_QR_EXTERNAL_POS_ID');
}

function flus_mp_qr_mode(): string
{
    $mode = strtolower(flus_mp_qr_config_value('FLUS_MP_QR_MODE', 'FLUS_MP_QR_MODE', 'hybrid'));
    return in_array($mode, ['dynamic', 'static', 'hybrid'], true) ? $mode : 'hybrid';
}

function flus_mp_qr_description(): string
{
    $description = flus_mp_qr_config_value('FLUS_MP_QR_DESCRIPTION', 'FLUS_MP_QR_DESCRIPTION', 'Prueba FLUS QR');
    return $description !== '' ? mb_substr($description, 0, 150, 'UTF-8') : 'Prueba FLUS QR';
}

function flus_mp_qr_is_configured(): bool
{
    return flus_mp_qr_access_token() !== '' && flus_mp_qr_external_pos_id() !== '';
}

function flus_mp_qr_cashier_enabled(): bool
{
    return flus_mp_cashier_automatic_enabled() && flus_mp_qr_is_configured();
}

function flus_mp_qr_static_assets(): array
{
    return [
        'image' => flus_mp_qr_config_value('FLUS_MP_QR_IMAGE_URL', 'FLUS_MP_QR_IMAGE_URL'),
        'template_document' => flus_mp_qr_config_value('FLUS_MP_QR_TEMPLATE_DOCUMENT_URL', 'FLUS_MP_QR_TEMPLATE_DOCUMENT_URL'),
        'template_image' => flus_mp_qr_config_value('FLUS_MP_QR_TEMPLATE_IMAGE_URL', 'FLUS_MP_QR_TEMPLATE_IMAGE_URL'),
    ];
}

function flus_mp_qr_static_pos_id(): string
{
    foreach (flus_mp_qr_static_assets() as $url) {
        if (preg_match('~/instore/merchant/qr/([0-9]+)/~', (string)$url, $m) === 1) {
            return (string)$m[1];
        }
    }
    return '';
}

function flus_mp_point_terminal_id(): string
{
    return flus_mp_qr_config_value('FLUS_MP_POINT_TERMINAL_ID', 'FLUS_MP_POINT_TERMINAL_ID');
}

function flus_mp_point_is_configured(): bool
{
    return flus_mp_qr_access_token() !== '' && flus_mp_point_terminal_id() !== '';
}

function flus_mp_point_cashier_enabled(): bool
{
    return flus_mp_cashier_automatic_enabled() && flus_mp_point_is_configured();
}

function flus_mp_qr_money_string(float $amount): string
{
    return number_format(round($amount, 2), 2, '.', '');
}

function flus_mp_qr_reference(string $prefix = 'FLUSMPTEST'): string
{
    $seed = bin2hex(random_bytes(8));
    return $prefix . '-' . date('YmdHis') . '-' . $seed;
}

function flus_mp_qr_idempotency_key(): string
{
    $bytes = bin2hex(random_bytes(16));
    return substr($bytes, 0, 8) . '-' . substr($bytes, 8, 4) . '-' . substr($bytes, 12, 4) . '-' . substr($bytes, 16, 4) . '-' . substr($bytes, 20);
}

function flus_mp_qr_http(string $method, string $path, ?array $payload = null, ?string $idempotencyKey = null): array
{
    $token = flus_mp_qr_access_token();
    if ($token === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'Falta FLUS_MP_ACCESS_TOKEN en src/config_mp.php'];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'error' => 'PHP no tiene cURL habilitado para llamar a Mercado Pago'];
    }

    $url = 'https://api.mercadopago.com' . $path;
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ];
    if ($idempotencyKey !== null && $idempotencyKey !== '') {
        $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'status' => $status, 'error' => $curlError !== '' ? $curlError : 'Error llamando a Mercado Pago'];
    }

    $decoded = json_decode((string)$raw, true);
    $body = is_array($decoded) ? $decoded : ['raw' => (string)$raw];
    if ($status < 200 || $status >= 300) {
        $message = (string)($body['message'] ?? $body['error'] ?? 'Mercado Pago rechazo la solicitud');
        return ['ok' => false, 'status' => $status, 'error' => $message, 'response' => $body];
    }

    return ['ok' => true, 'status' => $status, 'response' => $body];
}

function flus_mp_qr_find_value(array $data, array $paths): ?string
{
    foreach ($paths as $path) {
        $node = $data;
        foreach ($path as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) {
                $node = null;
                break;
            }
            $node = $node[$key];
        }
        if (is_scalar($node) && trim((string)$node) !== '') {
            return trim((string)$node);
        }
    }
    return null;
}

function flus_mp_qr_extract_qr_data(array $order): ?string
{
    return flus_mp_qr_find_value($order, [
        ['qr_data'],
        ['type_response', 'qr_data'],
        ['point_of_interaction', 'transaction_data', 'qr_code'],
        ['transactions', 'payments', 0, 'qr_data'],
    ]);
}

function flus_mp_qr_extract_payment_id(array $order): ?string
{
    return flus_mp_qr_find_value($order, [
        ['transactions', 'payments', 0, 'id'],
        ['payments', 0, 'id'],
    ]);
}

function flus_mp_qr_normalize_order(array $order): array
{
    $status = strtolower(trim((string)($order['status'] ?? '')));
    $statusDetail = strtolower(trim((string)($order['status_detail'] ?? '')));
    $payment = $order['transactions']['payments'][0] ?? null;
    $paymentStatus = is_array($payment) ? strtolower(trim((string)($payment['status'] ?? ''))) : '';
    $paymentDetail = is_array($payment) ? strtolower(trim((string)($payment['status_detail'] ?? ''))) : '';

    $approved = $status === 'processed' || ($paymentStatus === 'processed' && in_array($paymentDetail, ['accredited', 'processed'], true));

    return [
        'id' => (string)($order['id'] ?? ''),
        'status' => $status !== '' ? $status : 'unknown',
        'status_detail' => $statusDetail,
        'payment_id' => flus_mp_qr_extract_payment_id($order),
        'payment_status' => $paymentStatus,
        'payment_status_detail' => $paymentDetail,
        'approved' => $approved,
        'terminal' => in_array($status, ['processed', 'refunded', 'expired', 'canceled'], true),
        'qr_data' => flus_mp_qr_extract_qr_data($order),
        'external_reference' => (string)($order['external_reference'] ?? ''),
        'raw' => $order,
    ];
}

function flus_mp_qr_create_order(float $amount, ?string $description = null, ?string $mode = null): array
{
    $amount = round($amount, 2);
    if ($amount <= 0) {
        return ['ok' => false, 'status' => 0, 'error' => 'El importe debe ser mayor a cero'];
    }

    $externalPosId = flus_mp_qr_external_pos_id();
    if ($externalPosId === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'Falta FLUS_MP_QR_EXTERNAL_POS_ID en src/config_mp.php'];
    }

    $mode = strtolower(trim((string)($mode ?: flus_mp_qr_mode())));
    if (!in_array($mode, ['dynamic', 'static', 'hybrid'], true)) {
        $mode = 'dynamic';
    }

    $amountString = flus_mp_qr_money_string($amount);
    $externalReference = flus_mp_qr_reference();
    $description = mb_substr(trim((string)($description ?: flus_mp_qr_description())), 0, 150, 'UTF-8');
    if ($description === '') {
        $description = 'Prueba FLUS QR';
    }

    $payload = [
        'type' => 'qr',
        'total_amount' => $amountString,
        'external_reference' => $externalReference,
        'description' => $description,
        'expiration_time' => 'PT10M',
        'config' => [
            'qr' => [
                'external_pos_id' => $externalPosId,
                'mode' => $mode,
            ],
        ],
        'transactions' => [
            'payments' => [
                ['amount' => $amountString],
            ],
        ],
        'items' => [
            [
                'title' => 'Prueba FLUS',
                'unit_price' => $amountString,
                'quantity' => 1,
                'unit_measure' => 'unit',
            ],
        ],
    ];

    $result = flus_mp_qr_http('POST', '/v1/orders', $payload, flus_mp_qr_idempotency_key());
    if (!($result['ok'] ?? false)) {
        return $result;
    }

    $order = (array)($result['response'] ?? []);
    return [
        'ok' => true,
        'status' => (int)($result['status'] ?? 200),
        'order' => flus_mp_qr_normalize_order($order),
    ];
}

function flus_mp_qr_get_order(string $orderId): array
{
    $orderId = trim($orderId);
    if ($orderId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $orderId)) {
        return ['ok' => false, 'status' => 400, 'error' => 'order_id invalido'];
    }

    $result = flus_mp_qr_http('GET', '/v1/orders/' . rawurlencode($orderId));
    if (!($result['ok'] ?? false)) {
        return $result;
    }

    $order = (array)($result['response'] ?? []);
    return [
        'ok' => true,
        'status' => (int)($result['status'] ?? 200),
        'order' => flus_mp_qr_normalize_order($order),
    ];
}

function flus_mp_qr_cancel_order(string $orderId): array
{
    $orderId = trim($orderId);
    if ($orderId === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $orderId)) {
        return ['ok' => false, 'status' => 400, 'error' => 'order_id invalido'];
    }

    $result = flus_mp_qr_http('POST', '/v1/orders/' . rawurlencode($orderId) . '/cancel', null, flus_mp_qr_idempotency_key());
    if (($result['ok'] ?? false) || !in_array((int)($result['status'] ?? 0), [404, 405], true)) {
        return $result;
    }

    return flus_mp_qr_http('PUT', '/v1/orders/' . rawurlencode($orderId), ['status' => 'canceled']);
}

function flus_mp_qr_normalize_pos(array $pos): array
{
    $qr = is_array($pos['qr'] ?? null) ? $pos['qr'] : [];
    return [
        'id' => (string)($pos['id'] ?? ''),
        'name' => (string)($pos['name'] ?? ''),
        'external_id' => (string)($pos['external_id'] ?? ''),
        'store_id' => (string)($pos['store_id'] ?? ''),
        'external_store_id' => (string)($pos['external_store_id'] ?? ''),
        'fixed_amount' => (bool)($pos['fixed_amount'] ?? false),
        'qr' => [
            'image' => (string)($qr['image'] ?? ''),
            'template_document' => (string)($qr['template_document'] ?? ''),
            'template_image' => (string)($qr['template_image'] ?? ''),
            'qr_code' => (string)($pos['qr_code'] ?? ($qr['qr_code'] ?? '')),
        ],
        'raw' => $pos,
    ];
}

function flus_mp_qr_get_pos(string $posId): array
{
    $posId = trim($posId);
    if ($posId === '' || !preg_match('/^[0-9]+$/', $posId)) {
        return ['ok' => false, 'status' => 400, 'error' => 'pos_id invalido'];
    }

    $result = flus_mp_qr_http('GET', '/pos/' . rawurlencode($posId));
    if (!($result['ok'] ?? false)) {
        return $result;
    }

    return [
        'ok' => true,
        'status' => (int)($result['status'] ?? 200),
        'pos' => flus_mp_qr_normalize_pos((array)($result['response'] ?? [])),
    ];
}

function flus_mp_qr_find_pos_by_external_id(string $externalPosId): array
{
    $externalPosId = trim($externalPosId);
    if ($externalPosId === '') {
        return ['ok' => false, 'status' => 400, 'error' => 'Falta FLUS_MP_QR_EXTERNAL_POS_ID en src/config_mp.php'];
    }

    $result = flus_mp_qr_http('GET', '/pos?' . http_build_query(['external_id' => $externalPosId]));
    if (!($result['ok'] ?? false)) {
        return $result;
    }

    $response = is_array($result['response'] ?? null) ? $result['response'] : [];
    $results = is_array($response['results'] ?? null) ? $response['results'] : [];
    $pos = is_array($results[0] ?? null) ? $results[0] : null;
    if ($pos === null) {
        return ['ok' => false, 'status' => 404, 'error' => 'No se encontro una caja Mercado Pago con ese external_pos_id'];
    }

    return [
        'ok' => true,
        'status' => (int)($result['status'] ?? 200),
        'pos' => flus_mp_qr_normalize_pos($pos),
        'raw' => $response,
    ];
}

function flus_mp_qr_get_configured_pos(): array
{
    $externalPosId = flus_mp_qr_external_pos_id();
    if ($externalPosId !== '') {
        return flus_mp_qr_find_pos_by_external_id($externalPosId);
    }

    $posId = flus_mp_qr_static_pos_id();
    if ($posId !== '') {
        return flus_mp_qr_get_pos($posId);
    }

    return ['ok' => false, 'status' => 400, 'error' => 'Falta configurar una caja QR de Mercado Pago'];
}

function flus_mp_point_list_terminals(?string $storeId = null, ?string $posId = null): array
{
    $params = [
        'limit' => '50',
        'offset' => '0',
    ];
    if ($storeId !== null && trim($storeId) !== '') {
        $params['store_id'] = trim($storeId);
    }
    if ($posId !== null && trim($posId) !== '') {
        $params['pos_id'] = trim($posId);
    }

    $result = flus_mp_qr_http('GET', '/terminals/v1/list?' . http_build_query($params));
    if (!($result['ok'] ?? false)) {
        return $result;
    }

    $response = is_array($result['response'] ?? null) ? $result['response'] : [];
    $terminals = $response['data']['terminals'] ?? [];
    return [
        'ok' => true,
        'status' => (int)($result['status'] ?? 200),
        'terminals' => is_array($terminals) ? $terminals : [],
        'raw' => $response,
    ];
}

function flus_mp_point_create_order(float $amount, ?string $terminalId = null, string $paymentType = 'credit_card', ?string $ticketNumber = null): array
{
    $amount = round($amount, 2);
    if ($amount <= 0) {
        return ['ok' => false, 'status' => 0, 'error' => 'El importe debe ser mayor a cero'];
    }

    $terminalId = trim((string)($terminalId ?: flus_mp_point_terminal_id()));
    if ($terminalId === '') {
        return ['ok' => false, 'status' => 0, 'error' => 'Falta FLUS_MP_POINT_TERMINAL_ID en src/config_mp.php'];
    }

    $paymentType = strtolower(trim($paymentType));
    if (!in_array($paymentType, ['credit_card', 'debit_card'], true)) {
        $paymentType = 'credit_card';
    }

    $amountString = flus_mp_qr_money_string($amount);
    $externalReference = flus_mp_qr_reference('FLUSMPPOINT');
    $ticketNumber = trim((string)($ticketNumber ?: ('FLUS-' . date('YmdHis'))));
    $ticketNumber = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $ticketNumber) ?: 'FLUS', 0, 40);

    $payload = [
        'type' => 'point',
        'external_reference' => $externalReference,
        'expiration_time' => 'PT16M',
        'description' => 'Venta FLUS Point',
        'transactions' => [
            'payments' => [
                ['amount' => $amountString],
            ],
        ],
        'config' => [
            'point' => [
                'terminal_id' => $terminalId,
                'print_on_terminal' => 'no_ticket',
                'ticket_number' => $ticketNumber,
            ],
            'payment_method' => [
                'default_type' => $paymentType,
            ],
        ],
    ];

    $result = flus_mp_qr_http('POST', '/v1/orders', $payload, flus_mp_qr_idempotency_key());
    if (!($result['ok'] ?? false)) {
        return $result;
    }

    $order = (array)($result['response'] ?? []);
    return [
        'ok' => true,
        'status' => (int)($result['status'] ?? 200),
        'order' => flus_mp_qr_normalize_order($order),
    ];
}

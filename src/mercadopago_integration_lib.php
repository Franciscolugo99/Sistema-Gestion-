<?php
declare(strict_types=1);

function flus_mp_environment(string $value = ''): string
{
    $value = strtolower(trim($value));
    return in_array($value, ['test', 'production'], true) ? $value : 'test';
}

function flus_mp_token_fingerprint(string $accessToken): string
{
    return hash('sha256', trim($accessToken));
}

function flus_mp_integration_state(PDO $pdo, string $environment): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM mercadopago_integraciones WHERE environment = ? LIMIT 1');
    $stmt->execute([flus_mp_environment($environment)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function flus_mp_integration_prepare(PDO $pdo, string $environment, string $accessToken, array $setup): array
{
    $environment = flus_mp_environment($environment);
    $fingerprint = flus_mp_token_fingerprint($accessToken);
    $userId = preg_replace('/\D+/', '', (string)($setup['user_id'] ?? '')) ?: '';
    $current = flus_mp_integration_state($pdo, $environment);
    $reuse = is_array($current)
        && hash_equals((string)$current['token_fingerprint'], $fingerprint)
        && (string)$current['user_id'] === $userId;

    $storeExternalId = $reuse
        ? (string)$current['store_external_id']
        : flus_mp_qr_setup_external_id($environment === 'production' ? 'FLUSPRODSUC' : 'FLUSTESTSUC', 60);
    $posExternalId = $reuse
        ? (string)$current['pos_external_id']
        : flus_mp_qr_setup_external_id($environment === 'production' ? 'FLUSPRODCAJA' : 'FLUSTESTCAJA', 40);
    $storeId = $reuse ? (string)($current['store_id'] ?? '') : '';
    $posId = $reuse ? (string)($current['pos_id'] ?? '') : '';
    $status = $posId !== '' ? 'ready' : ($storeId !== '' ? 'pending_pos' : 'pending_store');
    $setupJson = json_encode($setup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $sql = 'INSERT INTO mercadopago_integraciones
              (environment, token_fingerprint, user_id, store_id, store_external_id, store_name,
               pos_id, pos_external_id, pos_name, status, last_error, setup_json)
            VALUES (?, ?, ?, NULLIF(?, \'\'), ?, ?, NULLIF(?, \'\'), ?, ?, ?, NULL, ?)
            ON DUPLICATE KEY UPDATE
              token_fingerprint = VALUES(token_fingerprint),
              user_id = VALUES(user_id),
              store_id = VALUES(store_id),
              store_external_id = VALUES(store_external_id),
              store_name = VALUES(store_name),
              pos_id = VALUES(pos_id),
              pos_external_id = VALUES(pos_external_id),
              pos_name = VALUES(pos_name),
              status = VALUES(status),
              last_error = NULL,
              setup_json = VALUES(setup_json)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $environment,
        $fingerprint,
        $userId,
        $storeId,
        $storeExternalId,
        (string)($setup['store_name'] ?? ''),
        $posId,
        $posExternalId,
        (string)($setup['pos_name'] ?? ''),
        $status,
        $setupJson === false ? null : $setupJson,
    ]);

    return flus_mp_integration_state($pdo, $environment) ?? [];
}

function flus_mp_integration_record_result(PDO $pdo, string $environment, array $result): void
{
    $store = is_array($result['store'] ?? null) ? $result['store'] : [];
    $pos = is_array($result['pos'] ?? null) ? $result['pos'] : [];
    $ok = (bool)($result['ok'] ?? false);
    $step = (string)($result['step'] ?? '');
    $status = $ok ? 'ready' : ($step === 'pos' || (string)($store['id'] ?? '') !== '' ? 'pending_pos' : 'pending_store');

    $stmt = $pdo->prepare(
        'UPDATE mercadopago_integraciones
         SET store_id = COALESCE(NULLIF(?, \'\'), store_id),
             pos_id = COALESCE(NULLIF(?, \'\'), pos_id),
             status = ?,
             last_error = ?
         WHERE environment = ?'
    );
    $stmt->execute([
        (string)($store['id'] ?? ''),
        (string)($pos['id'] ?? ''),
        $status,
        $ok ? null : mb_substr((string)($result['error'] ?? 'Error desconocido'), 0, 1000, 'UTF-8'),
        flus_mp_environment($environment),
    ]);
}

function flus_mp_webhook_signature_parts(string $header): array
{
    $parts = [];
    foreach (explode(',', $header) as $piece) {
        $pair = explode('=', trim($piece), 2);
        if (count($pair) === 2) {
            $parts[trim($pair[0])] = trim($pair[1]);
        }
    }
    return $parts;
}

function flus_mp_webhook_signature_valid(string $dataId, string $requestId, string $signature, string $secret): bool
{
    if ($dataId === '' || $requestId === '' || $signature === '' || $secret === '') {
        return false;
    }
    $parts = flus_mp_webhook_signature_parts($signature);
    $timestamp = (string)($parts['ts'] ?? '');
    $receivedHash = strtolower((string)($parts['v1'] ?? ''));
    if ($timestamp === '' || $receivedHash === '') {
        return false;
    }

    $manifest = 'id:' . strtolower($dataId) . ';request-id:' . $requestId . ';ts:' . $timestamp . ';';
    $expectedHash = hash_hmac('sha256', $manifest, $secret);
    return hash_equals($expectedHash, $receivedHash);
}

function flus_mp_webhook_event_key(array $payload, string $resourceId, string $requestId): string
{
    return hash('sha256', implode('|', [
        (string)($payload['id'] ?? ''),
        (string)($payload['type'] ?? ''),
        (string)($payload['action'] ?? ''),
        $resourceId,
        $requestId,
    ]));
}

function flus_mp_webhook_record(PDO $pdo, array $event): bool
{
    $sql = 'INSERT IGNORE INTO mercadopago_webhook_eventos
              (event_key, environment, event_id, event_type, action_name, resource_id, request_id,
               signature_valid, live_mode, status, payload_json, error_message)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        (string)$event['event_key'],
        flus_mp_environment((string)$event['environment']),
        (string)($event['event_id'] ?? ''),
        (string)($event['event_type'] ?? ''),
        (string)($event['action_name'] ?? ''),
        (string)($event['resource_id'] ?? ''),
        (string)($event['request_id'] ?? ''),
        !empty($event['signature_valid']) ? 1 : 0,
        array_key_exists('live_mode', $event) ? (!empty($event['live_mode']) ? 1 : 0) : null,
        (string)($event['status'] ?? 'received'),
        (string)($event['payload_json'] ?? ''),
        (string)($event['error_message'] ?? ''),
    ]);
    return $stmt->rowCount() > 0;
}

function flus_mp_webhook_status(PDO $pdo, string $eventKey): string
{
    $stmt = $pdo->prepare('SELECT status FROM mercadopago_webhook_eventos WHERE event_key = ? LIMIT 1');
    $stmt->execute([$eventKey]);
    return trim((string)$stmt->fetchColumn());
}

function flus_mp_webhook_mark_processed(PDO $pdo, string $eventKey, array $order): void
{
    $normalized = flus_mp_qr_normalize_order($order);
    $stmt = $pdo->prepare(
        'UPDATE mercadopago_webhook_eventos
         SET status = \'processed\', order_status = ?, external_reference = ?, error_message = NULL,
             processed_at = CURRENT_TIMESTAMP
         WHERE event_key = ?'
    );
    $stmt->execute([
        (string)($normalized['status'] ?? ''),
        (string)($normalized['external_reference'] ?? ''),
        $eventKey,
    ]);
}

function flus_mp_webhook_mark_error(PDO $pdo, string $eventKey, string $error): void
{
    $stmt = $pdo->prepare(
        'UPDATE mercadopago_webhook_eventos
         SET status = \'error\', error_message = ?, processed_at = CURRENT_TIMESTAMP
         WHERE event_key = ?'
    );
    $stmt->execute([mb_substr($error, 0, 500, 'UTF-8'), $eventKey]);
}

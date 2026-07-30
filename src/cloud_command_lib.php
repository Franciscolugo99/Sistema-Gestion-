<?php
declare(strict_types=1);

require_once __DIR__ . '/cloud_sync_lib.php';

if (!function_exists('flus_cloud_command_schema_ready')) {
    function flus_cloud_command_schema_ready(PDO $pdo): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
            $stmt->execute(['cloud_command_receipts']);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('flus_cloud_command_endpoint')) {
    function flus_cloud_command_endpoint(string $syncUrl, string $endpoint): string
    {
        $endpoint = in_array($endpoint, ['command-poll.php', 'command-ack.php'], true) ? $endpoint : '';
        if ($endpoint === '') {
            return '';
        }
        $syncUrl = trim($syncUrl);
        if ($syncUrl === '') {
            return '';
        }
        if (preg_match('#/sync-ingest\.php$#i', $syncUrl) === 1) {
            return preg_replace('#/sync-ingest\.php$#i', '/' . $endpoint, $syncUrl) ?: '';
        }
        return rtrim($syncUrl, '/') . '/' . $endpoint;
    }
}

if (!function_exists('flus_cloud_command_http_post')) {
    function flus_cloud_command_http_post(string $url, string $token, array $payload, int $timeoutSec): array
    {
        if (!flus_cloud_sync_url_is_safe($url)) {
            return ['ok' => false, 'error' => 'COMMAND_URL_INVALID'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'CURL_MISSING'];
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($body)) {
            return ['ok' => false, 'error' => 'JSON_ENCODE_FAILED'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => $timeoutSec,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $token,
                'X-Flus-Cloud-Token: ' . $token,
                'User-Agent: FLUS-Cloud-Command/1',
            ],
        ]);
        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if ($raw === false || $status < 200 || $status >= 300) {
            return [
                'ok' => false,
                'status' => $status,
                'error' => is_array($decoded) && is_string($decoded['error'] ?? null)
                    ? substr((string) $decoded['error'], 0, 80)
                    : ($curlError !== '' ? 'HTTP_TRANSPORT_ERROR' : 'HTTP_STATUS_' . $status),
            ];
        }
        if (!is_array($decoded) || !array_key_exists('ok', $decoded)) {
            return ['ok' => false, 'status' => $status, 'error' => 'HTTP_JSON_INVALID'];
        }
        return [
            'ok' => !empty($decoded['ok']),
            'status' => $status,
            'body' => $decoded,
            'error' => empty($decoded['ok']) ? substr((string) ($decoded['error'] ?? 'COMMAND_API_ERROR'), 0, 80) : '',
        ];
    }
}

if (!function_exists('flus_cloud_command_result')) {
    function flus_cloud_command_result(string $commandUid, string $claimToken, string $status, array $result): array
    {
        return [
            'command_uid' => $commandUid,
            'claim_token' => $claimToken,
            'status' => $status,
            'result' => $result,
        ];
    }
}

if (!function_exists('flus_cloud_command_complete_receipt')) {
    function flus_cloud_command_complete_receipt(PDO $pdo, int $receiptId, string $status, array $result, ?string $errorCode = null): void
    {
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            $json = '{}';
        }
        $stmt = $pdo->prepare('UPDATE cloud_command_receipts SET status = :status, attempts = attempts + 1, result_json = :result_json, last_error = :last_error, completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([
            'status' => $status,
            'result_json' => $json,
            'last_error' => $errorCode !== null ? substr($errorCode, 0, 120) : null,
            'id' => $receiptId,
        ]);
    }
}

if (!function_exists('flus_cloud_command_process')) {
    function flus_cloud_command_process(PDO $pdo, array $command): array
    {
        $commandUid = preg_replace('/[^A-Za-z0-9._:@-]/', '', trim((string) ($command['command_uid'] ?? ''))) ?: '';
        $commandUid = substr($commandUid, 0, 120);
        $commandType = trim((string) ($command['command_type'] ?? ''));
        $claimToken = trim((string) ($command['claim_token'] ?? ''));
        $payload = is_array($command['payload'] ?? null) ? $command['payload'] : [];
        if ($commandUid === '' || strlen($claimToken) < 32) {
            return flus_cloud_command_result($commandUid, $claimToken, 'rejected', ['error_code' => 'COMMAND_ENVELOPE_INVALID']);
        }
        if (!flus_cloud_command_schema_ready($pdo)) {
            throw new RuntimeException('COMMAND_SCHEMA_MISSING');
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $payloadHash = hash('sha256', is_string($payloadJson) ? $payloadJson : '');

        try {
            $pdo->beginTransaction();
            $receiptStmt = $pdo->prepare('SELECT * FROM cloud_command_receipts WHERE command_uid = :command_uid LIMIT 1 FOR UPDATE');
            $receiptStmt->execute(['command_uid' => $commandUid]);
            $receipt = $receiptStmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($receipt)) {
                if (!hash_equals((string) $receipt['payload_hash'], $payloadHash)) {
                    $pdo->rollBack();
                    return flus_cloud_command_result($commandUid, $claimToken, 'rejected', ['error_code' => 'COMMAND_PAYLOAD_MISMATCH']);
                }
                if (in_array((string) $receipt['status'], ['applied', 'rejected', 'conflict', 'failed'], true)) {
                    $savedResult = json_decode((string) ($receipt['result_json'] ?? '{}'), true);
                    $pdo->commit();
                    return flus_cloud_command_result($commandUid, $claimToken, (string) $receipt['status'], is_array($savedResult) ? $savedResult : []);
                }
                $receiptId = (int) $receipt['id'];
            } else {
                $insert = $pdo->prepare("INSERT INTO cloud_command_receipts (command_uid, command_type, payload_hash, status, attempts) VALUES (:command_uid, :command_type, :payload_hash, 'received', 0)");
                $insert->execute(['command_uid' => $commandUid, 'command_type' => substr($commandType, 0, 60), 'payload_hash' => $payloadHash]);
                $receiptId = (int) $pdo->lastInsertId();
            }

            if ($commandType !== 'price.update' || (string) ($payload['operation'] ?? '') !== 'price.update') {
                $result = ['error_code' => 'COMMAND_TYPE_UNSUPPORTED'];
                flus_cloud_command_complete_receipt($pdo, $receiptId, 'rejected', $result, $result['error_code']);
                $pdo->commit();
                return flus_cloud_command_result($commandUid, $claimToken, 'rejected', $result);
            }

            $productId = (int) ($payload['local_product_id'] ?? 0);
            $productUid = trim((string) ($payload['product_uid'] ?? ''));
            $productCode = trim((string) ($payload['product_code'] ?? ''));
            $expectedPrice = round((float) ($payload['expected_price'] ?? 0), 2);
            $newPrice = round((float) ($payload['new_price'] ?? 0), 2);
            $reason = trim((string) ($payload['reason_label'] ?? $payload['reason'] ?? 'Cambio remoto'));
            if ($productId <= 0 || $productUid !== 'id:' . $productId || $newPrice <= 0 || $newPrice > 999999999.99) {
                $result = ['error_code' => 'PRICE_COMMAND_INVALID'];
                flus_cloud_command_complete_receipt($pdo, $receiptId, 'rejected', $result, $result['error_code']);
                $pdo->commit();
                return flus_cloud_command_result($commandUid, $claimToken, 'rejected', $result);
            }

            $productStmt = $pdo->prepare('SELECT id, codigo, nombre, precio, activo FROM productos WHERE id = :id LIMIT 1 FOR UPDATE');
            $productStmt->execute(['id' => $productId]);
            $product = $productStmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($product) || (int) ($product['activo'] ?? 0) !== 1 || ($productCode !== '' && (string) ($product['codigo'] ?? '') !== $productCode)) {
                $result = ['product_id' => $productId, 'error_code' => 'PRODUCT_NOT_FOUND'];
                flus_cloud_command_complete_receipt($pdo, $receiptId, 'rejected', $result, $result['error_code']);
                $pdo->commit();
                return flus_cloud_command_result($commandUid, $claimToken, 'rejected', $result);
            }

            $currentPrice = round((float) $product['precio'], 2);
            if (abs($currentPrice - $expectedPrice) >= 0.005) {
                $result = ['product_id' => $productId, 'previous_price' => $currentPrice, 'error_code' => 'PRICE_CHANGED_LOCALLY'];
                flus_cloud_command_complete_receipt($pdo, $receiptId, 'conflict', $result, $result['error_code']);
                $pdo->commit();
                return flus_cloud_command_result($commandUid, $claimToken, 'conflict', $result);
            }

            $update = $pdo->prepare('UPDATE productos SET precio = :new_price WHERE id = :id');
            $update->execute(['new_price' => $newPrice, 'id' => $productId]);
            if (!function_exists('precio_registrar_cambio')) {
                require_once __DIR__ . '/precio_historial.php';
            }
            $historyId = precio_registrar_cambio(
                $productId,
                $currentPrice,
                $newPrice,
                'VENTA',
                substr('Cloud: ' . ($reason !== '' ? $reason : 'Cambio remoto') . ' [' . $commandUid . ']', 0, 255),
                null,
                $pdo
            );
            if ($historyId === null) {
                throw new RuntimeException('PRICE_HISTORY_FAILED');
            }

            $snapshot = flus_cloud_sync_enqueue_stock_snapshot($pdo, [$productId], 'command');
            if ((int) ($snapshot['failed'] ?? 0) > 0) {
                throw new RuntimeException('PRICE_SYNC_EVENT_FAILED');
            }
            $result = [
                'product_id' => $productId,
                'previous_price' => $currentPrice,
                'applied_price' => $newPrice,
                'history_id' => $historyId,
                'error_code' => '',
            ];
            flus_cloud_command_complete_receipt($pdo, $receiptId, 'applied', $result);
            $pdo->commit();
            return flus_cloud_command_result($commandUid, $claimToken, 'applied', $result);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[FLUS][cloud-command] command failed: ' . $commandUid);
            return flus_cloud_command_result($commandUid, $claimToken, 'retry', ['error_code' => 'COMMAND_EXECUTION_FAILED']);
        }
    }
}

if (!function_exists('flus_cloud_commands_run')) {
    function flus_cloud_commands_run(PDO $pdo, int $limit = 5): array
    {
        $config = flus_cloud_sync_config();
        if (empty($config['enabled'])) {
            return ['ok' => true, 'message' => 'DISABLED', 'received' => 0, 'applied' => 0];
        }
        if (!flus_cloud_command_schema_ready($pdo)) {
            return ['ok' => false, 'error' => 'COMMAND_SCHEMA_MISSING', 'received' => 0, 'applied' => 0];
        }
        $licenseKey = flus_cloud_sync_license_key();
        $installationId = flus_cloud_sync_installation_id();
        $token = trim((string) ($config['token'] ?? ''));
        $pollUrl = flus_cloud_command_endpoint((string) ($config['url'] ?? ''), 'command-poll.php');
        $ackUrl = flus_cloud_command_endpoint((string) ($config['url'] ?? ''), 'command-ack.php');
        if ($licenseKey === '' || $installationId === '' || $token === '' || $pollUrl === '' || $ackUrl === '') {
            return ['ok' => false, 'error' => 'COMMAND_CONFIG_INCOMPLETE', 'received' => 0, 'applied' => 0];
        }

        $identity = ['license_key' => $licenseKey, 'installation_id' => $installationId];
        $poll = flus_cloud_command_http_post($pollUrl, $token, $identity + ['limit' => max(1, min(10, $limit))], (int) $config['timeout_sec']);
        if (empty($poll['ok'])) {
            return ['ok' => false, 'error' => (string) ($poll['error'] ?? 'COMMAND_POLL_FAILED'), 'received' => 0, 'applied' => 0];
        }
        $commands = is_array($poll['body']['commands'] ?? null) ? $poll['body']['commands'] : [];
        if (!$commands) {
            return ['ok' => true, 'message' => 'NO_COMMANDS', 'received' => 0, 'applied' => 0];
        }

        $results = [];
        $applied = 0;
        $retryable = 0;
        foreach ($commands as $command) {
            if (!is_array($command)) {
                continue;
            }
            $processed = flus_cloud_command_process($pdo, $command);
            if (($processed['status'] ?? '') === 'retry') {
                $retryable++;
                continue;
            }
            $results[] = $processed;
            if (($processed['status'] ?? '') === 'applied') {
                $applied++;
            }
        }
        if (!$results) {
            return [
                'ok' => $retryable === 0,
                'message' => $retryable > 0 ? 'COMMANDS_RETRY_PENDING' : 'NO_VALID_COMMANDS',
                'error' => $retryable > 0 ? 'COMMAND_EXECUTION_RETRY' : null,
                'received' => count($commands),
                'applied' => 0,
            ];
        }

        $ack = flus_cloud_command_http_post($ackUrl, $token, $identity + ['results' => $results], (int) $config['timeout_sec']);
        if (empty($ack['ok'])) {
            return ['ok' => false, 'error' => (string) ($ack['error'] ?? 'COMMAND_ACK_FAILED'), 'received' => count($commands), 'applied' => $applied];
        }
        $ackedUids = is_array($ack['body']['acknowledged_uids'] ?? null) ? $ack['body']['acknowledged_uids'] : [];
        if ($ackedUids) {
            $mark = $pdo->prepare('UPDATE cloud_command_receipts SET acked_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE command_uid = :command_uid');
            foreach ($ackedUids as $ackedUid) {
                $mark->execute(['command_uid' => substr((string) $ackedUid, 0, 120)]);
            }
        }
        return [
            'ok' => $retryable === 0,
            'error' => $retryable > 0 ? 'COMMAND_EXECUTION_RETRY' : null,
            'received' => count($commands),
            'applied' => $applied,
            'acknowledged' => count($ackedUids),
            'retryable' => $retryable,
        ];
    }
}

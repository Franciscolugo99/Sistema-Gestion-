<?php
declare(strict_types=1);

/**
 * Cola local de sincronizacion cloud.
 *
 * Regla central: la operacion local de FLUS no depende de internet. Si la cola
 * no existe, no esta configurada o la nube falla, solo se registra/omite el
 * envio y la venta sigue su curso.
 */

if (!function_exists('flus_cloud_sync_env')) {
    function flus_cloud_sync_env(string $constant, string $env, string $default = ''): string
    {
        if (defined($constant)) {
            return trim((string)constant($constant));
        }

        $value = getenv($env);
        if ($value === false || trim((string)$value) === '') {
            return $default;
        }

        return trim((string)$value);
    }
}

if (!function_exists('flus_cloud_sync_bool')) {
    function flus_cloud_sync_bool(string $constant, string $env, bool $default = false): bool
    {
        if (defined($constant)) {
            return (bool)constant($constant);
        }

        $value = getenv($env);
        if ($value === false || trim((string)$value) === '') {
            return $default;
        }

        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}

if (!function_exists('flus_cloud_sync_derive_url')) {
    function flus_cloud_sync_derive_url(string $licenseUrl): string
    {
        $url = trim($licenseUrl);
        if ($url === '') {
            return '';
        }

        $patterns = [
            '~/admin/api/license-check\.php(?:\?.*)?$~i',
            '~/api/license-check\.php(?:\?.*)?$~i',
            '~/license-check\.php(?:\?.*)?$~i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url) === 1) {
                return preg_replace($pattern, '/admin/api/sync-ingest.php', $url) ?: '';
            }
        }

        return rtrim($url, '/') . '/admin/api/sync-ingest.php';
    }
}

if (!function_exists('flus_cloud_sync_config')) {
    function flus_cloud_sync_config(): array
    {
        $explicitUrl = flus_cloud_sync_env('FLUS_CLOUD_SYNC_URL', 'FLUS_CLOUD_SYNC_URL');
        $licenseUrl = flus_cloud_sync_env('FLUS_LICENSE_CLOUD_URL', 'FLUS_LICENSE_CLOUD_URL');
        $url = $explicitUrl !== '' ? $explicitUrl : flus_cloud_sync_derive_url($licenseUrl);

        $token = flus_cloud_sync_env('FLUS_CLOUD_SYNC_TOKEN', 'FLUS_CLOUD_SYNC_TOKEN');
        if ($token === '') {
            $token = flus_cloud_sync_env('FLUS_LICENSE_CLOUD_TOKEN', 'FLUS_LICENSE_CLOUD_TOKEN');
        }

        $enabledDefault = $url !== '';
        return [
            'enabled' => flus_cloud_sync_bool('FLUS_CLOUD_SYNC_ENABLED', 'FLUS_CLOUD_SYNC_ENABLED', $enabledDefault),
            'url' => $url,
            'token' => $token,
            'timeout_sec' => max(1, min(15, (int)flus_cloud_sync_env('FLUS_CLOUD_SYNC_TIMEOUT_SEC', 'FLUS_CLOUD_SYNC_TIMEOUT_SEC', '5'))),
            'max_attempts' => max(1, min(20, (int)flus_cloud_sync_env('FLUS_CLOUD_SYNC_MAX_ATTEMPTS', 'FLUS_CLOUD_SYNC_MAX_ATTEMPTS', '8'))),
            'branch_code' => flus_cloud_sync_env('FLUS_CLOUD_BRANCH_CODE', 'FLUS_CLOUD_BRANCH_CODE'),
            'branch_name' => flus_cloud_sync_env('FLUS_CLOUD_BRANCH_NAME', 'FLUS_CLOUD_BRANCH_NAME'),
            'branch_address' => flus_cloud_sync_env('FLUS_CLOUD_BRANCH_ADDRESS', 'FLUS_CLOUD_BRANCH_ADDRESS'),
            'display_name' => flus_cloud_sync_env('FLUS_CLOUD_INSTALLATION_NAME', 'FLUS_CLOUD_INSTALLATION_NAME'),
        ];
    }
}

if (!function_exists('flus_cloud_sync_schema_ready')) {
    function flus_cloud_sync_schema_ready(PDO $pdo): bool
    {
        try {
            if (function_exists('flus_table_exists')) {
                return (bool)flus_table_exists($pdo, 'cloud_sync_queue');
            }
            if (function_exists('has_table')) {
                return (bool)has_table($pdo, 'cloud_sync_queue');
            }

            $stmt = $pdo->prepare('
                SELECT 1
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                LIMIT 1
            ');
            $stmt->execute(['cloud_sync_queue']);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('[FLUS][cloud-sync] schema check failed: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('flus_cloud_sync_license_key')) {
    function flus_cloud_sync_license_key(): string
    {
        try {
            if (!function_exists('flus_license_load')) {
                $licenseFile = __DIR__ . '/license.php';
                if (is_file($licenseFile)) {
                    require_once $licenseFile;
                }
            }

            $license = function_exists('flus_license_load') ? flus_license_load() : null;
            if (!is_array($license)) {
                return '';
            }

            if (function_exists('flus_license_validate_payload')) {
                $validated = flus_license_validate_payload($license);
                if (is_array($validated['license'] ?? null)) {
                    $license = $validated['license'];
                }
            }

            return strtoupper(trim((string)($license['license_key'] ?? '')));
        } catch (Throwable $e) {
            error_log('[FLUS][cloud-sync] license key read failed: ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('flus_cloud_sync_installation_id')) {
    function flus_cloud_sync_installation_id(): string
    {
        try {
            if (!function_exists('flus_license_cloud_installation_id')) {
                $licenseCloudFile = __DIR__ . '/license_cloud.php';
                if (is_file($licenseCloudFile)) {
                    require_once $licenseCloudFile;
                }
            }

            if (function_exists('flus_license_cloud_installation_id')) {
                return flus_license_cloud_installation_id();
            }
        } catch (Throwable $e) {
            error_log('[FLUS][cloud-sync] installation id read failed: ' . $e->getMessage());
        }

        return '';
    }
}

if (!function_exists('flus_cloud_sync_machine_label')) {
    function flus_cloud_sync_machine_label(): string
    {
        foreach (['COMPUTERNAME', 'HOSTNAME'] as $env) {
            $value = trim((string)(getenv($env) ?: ''));
            if ($value !== '') {
                return substr($value, 0, 150);
            }
        }

        return 'FLUS local';
    }
}

if (!function_exists('flus_cloud_sync_json')) {
    function flus_cloud_sync_json($value, int $maxBytes): ?string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return null;
        }

        if (strlen($json) > $maxBytes) {
            $json = json_encode([
                'truncated' => true,
                'reason' => 'MAX_BYTES',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $json === false ? null : $json;
    }
}

if (!function_exists('flus_cloud_sync_enqueue_event')) {
    function flus_cloud_sync_enqueue_event(PDO $pdo, string $eventType, string $eventUid, array $summary = [], array $payload = [], ?string $occurredAt = null): array
    {
        try {
            $config = flus_cloud_sync_config();
            if (empty($config['enabled'])) {
                return ['queued' => false, 'reason' => 'disabled'];
            }
            if (!flus_cloud_sync_schema_ready($pdo)) {
                return ['queued' => false, 'reason' => 'schema_missing'];
            }

            $eventType = preg_replace('/[^A-Za-z0-9._:@-]/', '', trim($eventType)) ?: '';
            $eventUid = preg_replace('/[^A-Za-z0-9._:@-]/', '', trim($eventUid)) ?: '';
            $eventType = substr($eventType, 0, 60);
            $eventUid = substr($eventUid, 0, 120);
            if ($eventType === '' || $eventUid === '') {
                return ['queued' => false, 'reason' => 'invalid_event'];
            }

            $summaryJson = flus_cloud_sync_json($summary, 16384);
            $payloadJson = flus_cloud_sync_json($payload, 65535);
            if ($summaryJson === null || $payloadJson === null) {
                return ['queued' => false, 'reason' => 'json_failed'];
            }

            $stmt = $pdo->prepare('
                INSERT INTO cloud_sync_queue (
                    event_uid, event_type, occurred_at, summary_json, payload_json, status
                ) VALUES (
                    :event_uid, :event_type, :occurred_at, :summary_json, :payload_json, "pending"
                )
                ON DUPLICATE KEY UPDATE
                    updated_at = CURRENT_TIMESTAMP
            ');
            $stmt->execute([
                ':event_uid' => $eventUid,
                ':event_type' => $eventType,
                ':occurred_at' => $occurredAt ?: date('Y-m-d H:i:s'),
                ':summary_json' => $summaryJson,
                ':payload_json' => $payloadJson,
            ]);

            return ['queued' => true, 'duplicate' => $stmt->rowCount() === 2];
        } catch (Throwable $e) {
            error_log('[FLUS][cloud-sync] enqueue failed: ' . $e->getMessage());
            return ['queued' => false, 'reason' => 'enqueue_failed'];
        }
    }
}

if (!function_exists('flus_cloud_sync_enqueue_sale')) {
    function flus_cloud_sync_enqueue_sale(PDO $pdo, array $sale): array
    {
        $ventaId = (int)($sale['venta_id'] ?? 0);
        if ($ventaId <= 0) {
            return ['queued' => false, 'reason' => 'invalid_sale'];
        }

        $requestUid = trim((string)($sale['request_uid'] ?? ''));
        $eventUid = $requestUid !== '' ? ('sale:' . $requestUid) : ('sale-id:' . $ventaId);
        $occurredAt = (string)($sale['fecha'] ?? date('Y-m-d H:i:s'));

        $summary = [
            'venta_id' => $ventaId,
            'total' => round((float)($sale['total'] ?? 0), 2),
            'medio_pago' => (string)($sale['medio_pago'] ?? ''),
            'caja_id' => (int)($sale['caja_id'] ?? 0),
            'terminal_id' => (int)($sale['terminal_id'] ?? 0),
            'user_id' => (int)($sale['user_id'] ?? 0),
            'items_count' => (int)($sale['items_count'] ?? 0),
        ];

        $payload = [
            'venta_id' => $ventaId,
            'request_uid' => $requestUid,
            'fecha' => $occurredAt,
            'caja_id' => (int)($sale['caja_id'] ?? 0),
            'terminal_id' => (int)($sale['terminal_id'] ?? 0),
            'user_id' => (int)($sale['user_id'] ?? 0),
            'total' => round((float)($sale['total'] ?? 0), 2),
            'total_bruto' => round((float)($sale['total_bruto'] ?? 0), 2),
            'descuento_total' => round((float)($sale['descuento_total'] ?? 0), 2),
            'ajuste_precio_total' => round((float)($sale['ajuste_precio_total'] ?? 0), 2),
            'ajuste_precio_redondeo_total' => round((float)($sale['ajuste_precio_redondeo_total'] ?? 0), 2),
            'medio_pago' => (string)($sale['medio_pago'] ?? ''),
            'monto_pagado' => round((float)($sale['monto_pagado'] ?? 0), 2),
            'vuelto' => round((float)($sale['vuelto'] ?? 0), 2),
            'monto_cc' => round((float)($sale['monto_cc'] ?? 0), 2),
            'pagos' => $sale['pagos'] ?? [],
            'items' => $sale['items'] ?? [],
        ];

        return flus_cloud_sync_enqueue_event($pdo, 'sale.created', $eventUid, $summary, $payload, $occurredAt);
    }
}

if (!function_exists('flus_cloud_sync_stock_rows')) {
    function flus_cloud_sync_stock_rows(PDO $pdo, ?array $productIds = null, int $limit = 250): array
    {
        $limit = max(1, min(500, $limit));
        $params = [];
        $where = 'WHERE p.activo = 1';
        if (is_array($productIds)) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn(int $id): bool => $id > 0)));
            if (!$ids) {
                return [];
            }
            $where = 'WHERE p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = $ids;
            $limit = count($ids);
        }

        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.codigo,
                p.nombre,
                p.categoria,
                p.marca,
                p.precio,
                p.stock,
                p.stock_minimo,
                p.es_pesable,
                p.unidad_venta,
                p.activo,
                p.fecha_modificacion
            FROM productos p
            {$where}
            ORDER BY p.nombre ASC, p.codigo ASC
            LIMIT {$limit}
        ");
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $stock = round((float)($row['stock'] ?? 0), 3);
            $min = round((float)($row['stock_minimo'] ?? 0), 3);
            $rows[] = [
                'product_uid' => 'id:' . (int)($row['id'] ?? 0),
                'producto_id' => (int)($row['id'] ?? 0),
                'codigo' => (string)($row['codigo'] ?? ''),
                'nombre' => (string)($row['nombre'] ?? ''),
                'categoria' => (string)($row['categoria'] ?? ''),
                'marca' => (string)($row['marca'] ?? ''),
                'precio' => round((float)($row['precio'] ?? 0), 2),
                'stock' => $stock,
                'stock_minimo' => $min,
                'estado_stock' => $stock <= 0 ? 'sin_stock' : ($min > 0 && $stock <= $min ? 'bajo_minimo' : 'ok'),
                'es_pesable' => (int)($row['es_pesable'] ?? 0) === 1,
                'unidad_venta' => (string)($row['unidad_venta'] ?? 'UNIDAD'),
                'activo' => (int)($row['activo'] ?? 1) === 1,
                'updated_at' => (string)($row['fecha_modificacion'] ?? date('Y-m-d H:i:s')),
            ];
        }

        return $rows;
    }
}

if (!function_exists('flus_cloud_sync_enqueue_stock_snapshot')) {
    function flus_cloud_sync_enqueue_stock_snapshot(PDO $pdo, ?array $productIds = null, string $reason = 'manual', int $limit = 250): array
    {
        if (!flus_cloud_sync_schema_ready($pdo)) {
            return ['queued' => 0, 'reason' => 'schema_missing'];
        }

        $rows = flus_cloud_sync_stock_rows($pdo, $productIds, $limit);
        if (!$rows) {
            return ['queued' => 0, 'reason' => 'empty_stock'];
        }

        $reason = preg_replace('/[^A-Za-z0-9._:@-]/', '', trim($reason)) ?: 'manual';
        $eventType = is_array($productIds) ? 'stock.updated' : 'stock.snapshot';
        $occurredAt = date('Y-m-d H:i:s');
        $uidReason = is_array($productIds) ? $reason : $reason . ':' . date('YmdHis');
        $chunks = array_chunk($rows, 40);
        $queued = 0;
        $failed = 0;

        foreach ($chunks as $index => $products) {
            $outCount = 0;
            $lowCount = 0;
            foreach ($products as $product) {
                if (($product['estado_stock'] ?? '') === 'sin_stock') {
                    $outCount++;
                } elseif (($product['estado_stock'] ?? '') === 'bajo_minimo') {
                    $lowCount++;
                }
            }

            $summary = [
                'reason' => $reason,
                'products_count' => count($products),
                'out_count' => $outCount,
                'low_count' => $lowCount,
                'full_snapshot' => !is_array($productIds),
            ];
            $payload = [
                'reason' => $reason,
                'products' => $products,
            ];

            $result = flus_cloud_sync_enqueue_event(
                $pdo,
                $eventType,
                'stock:' . $uidReason . ':' . ($index + 1),
                $summary,
                $payload,
                $occurredAt
            );

            if (!empty($result['queued'])) {
                $queued++;
            } else {
                $failed++;
            }
        }

        return ['queued' => $queued, 'failed' => $failed, 'products' => count($rows), 'event_type' => $eventType];
    }
}

if (!function_exists('flus_cloud_sync_pending_counts')) {
    function flus_cloud_sync_pending_counts(PDO $pdo): array
    {
        if (!flus_cloud_sync_schema_ready($pdo)) {
            return ['ready' => false, 'pending' => 0, 'failed' => 0, 'sent' => 0];
        }

        $stmt = $pdo->query("
            SELECT status, COUNT(*) AS qty
            FROM cloud_sync_queue
            GROUP BY status
        ");
        $counts = ['ready' => true, 'pending' => 0, 'failed' => 0, 'sent' => 0];
        foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $status = (string)($row['status'] ?? '');
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int)($row['qty'] ?? 0);
            }
        }

        return $counts;
    }
}

if (!function_exists('flus_cloud_sync_build_push_payload')) {
    function flus_cloud_sync_build_push_payload(array $events, array $config): array
    {
        $branch = null;
        if ((string)$config['branch_code'] !== '') {
            $branch = [
                'code' => (string)$config['branch_code'],
                'name' => (string)($config['branch_name'] ?: $config['branch_code']),
                'address' => (string)$config['branch_address'],
                'status' => 'active',
            ];
        }

        $payload = [
            'license_key' => flus_cloud_sync_license_key(),
            'installation_id' => flus_cloud_sync_installation_id(),
            'app_version' => defined('FLUS_VERSION') ? (string)FLUS_VERSION : (defined('APP_VERSION') ? (string)APP_VERSION : ''),
            'device_label' => flus_cloud_sync_machine_label(),
            'display_name' => (string)($config['display_name'] ?: flus_cloud_sync_machine_label()),
            'sent_at' => date(DATE_ATOM),
            'events' => [],
        ];

        if ($branch !== null) {
            $payload['branch'] = $branch;
        }

        foreach ($events as $event) {
            $payload['events'][] = [
                'event_uid' => (string)$event['event_uid'],
                'event_type' => (string)$event['event_type'],
                'occurred_at' => date(DATE_ATOM, strtotime((string)$event['occurred_at']) ?: time()),
                'summary' => json_decode((string)($event['summary_json'] ?? '{}'), true) ?: [],
                'payload' => json_decode((string)($event['payload_json'] ?? '{}'), true) ?: [],
            ];
        }

        return $payload;
    }
}

if (!function_exists('flus_cloud_sync_http_post')) {
    function flus_cloud_sync_http_post(string $url, string $token, array $payload, int $timeoutSec): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'CURL_MISSING'];
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
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
                'User-Agent: FLUS-Cloud-Sync/1',
            ],
        ]);

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            return [
                'ok' => false,
                'status' => $status,
                'error' => $curlError !== '' ? 'HTTP_TRANSPORT_ERROR' : 'HTTP_STATUS_' . $status,
            ];
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'status' => $status, 'error' => 'HTTP_JSON_INVALID'];
        }

        return ['ok' => !empty($decoded['ok']), 'status' => $status, 'body' => $decoded, 'error' => (string)($decoded['error'] ?? '')];
    }
}

if (!function_exists('flus_cloud_sync_push')) {
    function flus_cloud_sync_push(PDO $pdo, int $limit = 50): array
    {
        $config = flus_cloud_sync_config();
        if (empty($config['enabled'])) {
            return ['ok' => false, 'error' => 'DISABLED', 'counts' => flus_cloud_sync_pending_counts($pdo)];
        }
        if ((string)$config['url'] === '') {
            return ['ok' => false, 'error' => 'URL_MISSING', 'counts' => flus_cloud_sync_pending_counts($pdo)];
        }
        if ((string)$config['token'] === '') {
            return ['ok' => false, 'error' => 'TOKEN_MISSING', 'counts' => flus_cloud_sync_pending_counts($pdo)];
        }
        if (flus_cloud_sync_license_key() === '') {
            return ['ok' => false, 'error' => 'LICENSE_KEY_MISSING', 'counts' => flus_cloud_sync_pending_counts($pdo)];
        }
        if (flus_cloud_sync_installation_id() === '') {
            return ['ok' => false, 'error' => 'INSTALLATION_ID_MISSING', 'counts' => flus_cloud_sync_pending_counts($pdo)];
        }
        if (!flus_cloud_sync_schema_ready($pdo)) {
            return ['ok' => false, 'error' => 'SCHEMA_MISSING', 'counts' => flus_cloud_sync_pending_counts($pdo)];
        }

        $limit = max(1, min(50, $limit));
        $stmt = $pdo->prepare("
            SELECT *
            FROM cloud_sync_queue
            WHERE status IN ('pending', 'failed')
              AND attempts < :max_attempts
              AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
            ORDER BY occurred_at ASC, id ASC
            LIMIT {$limit}
        ");
        $stmt->bindValue(':max_attempts', (int)$config['max_attempts'], PDO::PARAM_INT);
        $stmt->execute();
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $selected = [];
        $estimatedBytes = 4096;
        foreach ($events as $event) {
            $eventBytes = strlen((string)($event['summary_json'] ?? '')) + strlen((string)($event['payload_json'] ?? '')) + 512;
            if ($selected && ($estimatedBytes + $eventBytes) > 220000) {
                break;
            }
            $selected[] = $event;
            $estimatedBytes += $eventBytes;
        }
        $events = $selected;

        if (!$events) {
            return ['ok' => true, 'sent' => 0, 'message' => 'NO_PENDING', 'counts' => flus_cloud_sync_pending_counts($pdo)];
        }

        $payload = flus_cloud_sync_build_push_payload($events, $config);
        $response = flus_cloud_sync_http_post((string)$config['url'], (string)$config['token'], $payload, (int)$config['timeout_sec']);
        $ids = array_map(static fn(array $event): int => (int)$event['id'], $events);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        if (!empty($response['ok'])) {
            $body = is_array($response['body'] ?? null) ? $response['body'] : [];
            $rejected = (int)($body['rejected'] ?? 0);
            if ($rejected <= 0) {
                $update = $pdo->prepare("
                    UPDATE cloud_sync_queue
                    SET status = 'sent',
                        sent_at = NOW(),
                        last_error = NULL,
                        attempts = attempts + 1,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id IN ({$placeholders})
                ");
                $update->execute($ids);
                return [
                    'ok' => true,
                    'sent' => count($ids),
                    'accepted' => (int)($body['accepted'] ?? 0),
                    'duplicates' => (int)($body['duplicates'] ?? 0),
                    'rejected' => $rejected,
                    'counts' => flus_cloud_sync_pending_counts($pdo),
                ];
            }

            $error = 'CLOUD_REJECTED_' . $rejected;
        } else {
            $error = (string)($response['error'] ?? 'SYNC_FAILED');
        }

        $retryMinutes = min(60, max(1, count($events)));
        $params = array_merge([substr($error, 0, 190)], $ids);
        $update = $pdo->prepare("
            UPDATE cloud_sync_queue
            SET status = 'failed',
                attempts = attempts + 1,
                last_error = ?,
                next_attempt_at = DATE_ADD(NOW(), INTERVAL {$retryMinutes} MINUTE),
                updated_at = CURRENT_TIMESTAMP
            WHERE id IN ({$placeholders})
        ");
        $update->execute($params);

        return ['ok' => false, 'error' => $error, 'sent' => 0, 'attempted' => count($ids), 'counts' => flus_cloud_sync_pending_counts($pdo)];
    }
}

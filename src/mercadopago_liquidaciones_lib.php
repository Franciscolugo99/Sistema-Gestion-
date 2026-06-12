<?php
declare(strict_types=1);

require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/mercadopago_qr_lib.php';

function flus_mp_liquidaciones_ready(PDO $pdo): bool
{
    return flus_table_exists($pdo, 'mercadopago_liquidaciones')
        && flus_table_exists($pdo, 'venta_pagos');
}

function flus_mp_liquidacion_datetime(mixed $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function flus_mp_liquidacion_amount(mixed $value): float
{
    return round((float)$value, 2);
}

function flus_mp_liquidacion_normalize_payment(array $payment): array
{
    $feeDetails = is_array($payment['fee_details'] ?? null) ? $payment['fee_details'] : [];
    $chargeDetails = is_array($payment['charges_details'] ?? null) ? $payment['charges_details'] : [];
    $taxDetails = [];
    $feeAmount = 0.0;
    $taxesAmount = flus_mp_liquidacion_amount($payment['taxes_amount'] ?? 0);

    foreach ($feeDetails as $detail) {
        if (!is_array($detail)) continue;
        $feeAmount += abs(flus_mp_liquidacion_amount($detail['amount'] ?? 0));
    }

    foreach ($chargeDetails as $detail) {
        if (!is_array($detail)) continue;
        $type = strtolower(trim((string)($detail['type'] ?? $detail['name'] ?? '')));
        $amount = abs(flus_mp_liquidacion_amount(
            $detail['amounts']['original'] ?? $detail['amount'] ?? 0
        ));
        if (str_contains($type, 'tax')) {
            $taxDetails[] = $detail;
            if ($taxesAmount <= 0) {
                $taxesAmount += $amount;
            }
        } elseif ($feeAmount <= 0 && (str_contains($type, 'fee') || str_contains($type, 'charge'))) {
            $feeAmount += $amount;
        }
    }

    $transactionAmount = flus_mp_liquidacion_amount($payment['transaction_amount'] ?? 0);
    $refundedAmount = abs(flus_mp_liquidacion_amount($payment['transaction_amount_refunded'] ?? 0));
    $netReceived = flus_mp_liquidacion_amount(
        $payment['transaction_details']['net_received_amount']
        ?? ($transactionAmount - $feeAmount - $taxesAmount - $refundedAmount)
    );
    $status = trim((string)($payment['status'] ?? ''));
    $transactionType = $refundedAmount > 0 || $status === 'refunded' ? 'REFUND' : 'SETTLEMENT';

    return [
        'payment_id' => trim((string)($payment['id'] ?? '')),
        'order_id' => trim((string)($payment['order']['id'] ?? '')),
        'external_reference' => trim((string)($payment['external_reference'] ?? '')),
        'status' => $status,
        'status_detail' => trim((string)($payment['status_detail'] ?? '')),
        'transaction_type' => $transactionType,
        'currency_id' => trim((string)($payment['currency_id'] ?? 'ARS')) ?: 'ARS',
        'transaction_amount' => $transactionAmount,
        'fee_amount' => round($feeAmount, 2),
        'taxes_amount' => round($taxesAmount, 2),
        'refunded_amount' => $refundedAmount,
        'net_received_amount' => $netReceived,
        'payment_method_id' => trim((string)($payment['payment_method_id'] ?? '')),
        'payment_type_id' => trim((string)($payment['payment_type_id'] ?? '')),
        'date_created' => flus_mp_liquidacion_datetime($payment['date_created'] ?? null),
        'date_approved' => flus_mp_liquidacion_datetime($payment['date_approved'] ?? null),
        'money_release_date' => flus_mp_liquidacion_datetime($payment['money_release_date'] ?? null),
        'fee_json' => json_encode($feeDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'taxes_json' => json_encode($taxDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'raw_json' => json_encode($payment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];
}

function flus_mp_liquidacion_upsert(
    PDO $pdo,
    string $environment,
    array $payment,
    ?int $ventaId = null,
    ?int $ventaPagoId = null
): void {
    $row = flus_mp_liquidacion_normalize_payment($payment);
    if ($row['payment_id'] === '') {
        throw new RuntimeException('Mercado Pago no devolvio el ID del pago.');
    }

    $sql = 'INSERT INTO mercadopago_liquidaciones
        (environment, source, venta_id, venta_pago_id, payment_id, order_id, external_reference,
         status, status_detail, transaction_type, currency_id, transaction_amount, fee_amount,
         taxes_amount, refunded_amount, net_received_amount, payment_method_id, payment_type_id,
         date_created, date_approved, money_release_date, fee_json, taxes_json, raw_json,
         sync_error, last_synced_at)
        VALUES
        (:environment, \'payment_api\', :venta_id, :venta_pago_id, :payment_id, :order_id, :external_reference,
         :status, :status_detail, :transaction_type, :currency_id, :transaction_amount, :fee_amount,
         :taxes_amount, :refunded_amount, :net_received_amount, :payment_method_id, :payment_type_id,
         :date_created, :date_approved, :money_release_date, :fee_json, :taxes_json, :raw_json,
         NULL, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
         venta_id = VALUES(venta_id), venta_pago_id = VALUES(venta_pago_id), order_id = VALUES(order_id),
         external_reference = VALUES(external_reference), status = VALUES(status),
         status_detail = VALUES(status_detail), transaction_type = VALUES(transaction_type),
         currency_id = VALUES(currency_id), transaction_amount = VALUES(transaction_amount),
         fee_amount = VALUES(fee_amount), taxes_amount = VALUES(taxes_amount),
         refunded_amount = VALUES(refunded_amount), net_received_amount = VALUES(net_received_amount),
         payment_method_id = VALUES(payment_method_id), payment_type_id = VALUES(payment_type_id),
         date_created = VALUES(date_created), date_approved = VALUES(date_approved),
         money_release_date = VALUES(money_release_date), fee_json = VALUES(fee_json),
         taxes_json = VALUES(taxes_json), raw_json = VALUES(raw_json), sync_error = NULL,
         last_synced_at = CURRENT_TIMESTAMP';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($row, [
        'environment' => flus_mp_qr_environment(),
        'venta_id' => $ventaId,
        'venta_pago_id' => $ventaPagoId,
    ]));
}

function flus_mp_liquidaciones_sync(PDO $pdo, int $limit = 100): array
{
    if (!flus_mp_liquidaciones_ready($pdo)) {
        return ['ok' => false, 'synced' => 0, 'failed' => 0, 'error' => 'Falta aplicar la migracion de liquidaciones Mercado Pago.'];
    }

    $limit = max(1, min(250, $limit));
    $sql = "SELECT vp.id AS venta_pago_id, vp.venta_id, vp.mp_payment_id
            FROM venta_pagos vp
            WHERE UPPER(vp.medio_pago) IN ('MP', 'MERCADOPAGO', 'MERCADO PAGO')
              AND vp.mp_payment_id IS NOT NULL
              AND vp.mp_payment_id <> ''
            ORDER BY vp.id DESC
            LIMIT {$limit}";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $synced = 0;
    $failed = 0;
    $errors = [];

    foreach ($rows as $row) {
        $paymentId = trim((string)$row['mp_payment_id']);
        $result = flus_mp_qr_http('GET', '/v1/payments/' . rawurlencode($paymentId));
        if (!($result['ok'] ?? false)) {
            $failed++;
            $errors[] = '#' . $paymentId . ': ' . (string)($result['error'] ?? 'Error desconocido');
            continue;
        }

        flus_mp_liquidacion_upsert(
            $pdo,
            flus_mp_qr_environment(),
            (array)($result['response'] ?? []),
            (int)$row['venta_id'],
            (int)$row['venta_pago_id']
        );
        $synced++;
    }

    return [
        'ok' => $failed === 0,
        'synced' => $synced,
        'failed' => $failed,
        'errors' => array_slice($errors, 0, 5),
    ];
}

function flus_mp_liquidaciones_report(PDO $pdo, array $filters = []): array
{
    $today = new DateTimeImmutable('today');
    $desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($filters['desde'] ?? ''))
        ? (string)$filters['desde']
        : $today->modify('first day of this month')->format('Y-m-d');
    $hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($filters['hasta'] ?? ''))
        ? (string)$filters['hasta']
        : $today->format('Y-m-d');
    $environment = flus_mp_qr_environment();

    if (!flus_mp_liquidaciones_ready($pdo)) {
        return ['ready' => false, 'desde' => $desde, 'hasta' => $hasta, 'summary' => [], 'rows' => []];
    }

    $params = [$environment, $desde . ' 00:00:00', $hasta . ' 23:59:59'];
    $where = 'environment = ? AND COALESCE(date_approved, date_created, created_at) BETWEEN ? AND ?';
    $summaryStmt = $pdo->prepare(
        "SELECT COUNT(*) AS operaciones,
                COALESCE(SUM(transaction_amount), 0) AS bruto,
                COALESCE(SUM(fee_amount), 0) AS comisiones,
                COALESCE(SUM(taxes_amount), 0) AS impuestos,
                COALESCE(SUM(refunded_amount), 0) AS devoluciones,
                COALESCE(SUM(net_received_amount), 0) AS neto
         FROM mercadopago_liquidaciones WHERE {$where}"
    );
    $summaryStmt->execute($params);

    $rowsStmt = $pdo->prepare(
        "SELECT * FROM mercadopago_liquidaciones
         WHERE {$where}
         ORDER BY COALESCE(date_approved, date_created, created_at) DESC, id DESC
         LIMIT 250"
    );
    $rowsStmt->execute($params);

    return [
        'ready' => true,
        'desde' => $desde,
        'hasta' => $hasta,
        'summary' => $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [],
        'rows' => $rowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ];
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/db_helpers.php';
require_once __DIR__ . '/cloud_sync_lib.php';

if (!function_exists('flus_cloud_sync_sales_backfill_normalize_options')) {
    function flus_cloud_sync_sales_backfill_normalize_options(array $options): array
    {
        $normalized = [
            'from' => trim((string)($options['from'] ?? '')),
            'to' => trim((string)($options['to'] ?? '')),
            'after_id' => max(0, (int)($options['after_id'] ?? $options['after-id'] ?? 0)),
            'limit' => max(1, min(500, (int)($options['limit'] ?? 100))),
        ];

        foreach (['from', 'to'] as $label) {
            $value = $normalized[$label];
            if ($value === '') {
                continue;
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (!$date instanceof DateTimeImmutable
                || ($dateErrors !== false && ((int)$dateErrors['warning_count'] > 0 || (int)$dateErrors['error_count'] > 0))
                || $date->format('Y-m-d') !== $value
            ) {
                throw new InvalidArgumentException(strtoupper($label) . '_INVALID');
            }
        }
        if ($normalized['from'] !== '' && $normalized['to'] !== '' && $normalized['from'] > $normalized['to']) {
            throw new InvalidArgumentException('DATE_RANGE_INVALID');
        }

        return $normalized;
    }
}

if (!function_exists('flus_cloud_sync_sales_backfill')) {
    function flus_cloud_sync_sales_backfill(PDO $pdo, array $options, bool $enqueue = false): array
    {
        $options = flus_cloud_sync_sales_backfill_normalize_options($options);
        if (!flus_cloud_sync_schema_ready($pdo)) {
            throw new RuntimeException('SCHEMA_MISSING');
        }

        $selectColumn = static function (PDO $pdo, string $table, string $column, string $fallback = 'NULL'): string {
            return flus_column_exists($pdo, $table, $column) ? 'v.`' . $column . '`' : $fallback;
        };
        $userIdParts = [];
        foreach (['user_id', 'usuario_id', 'vendedor_id'] as $column) {
            if (flus_column_exists($pdo, 'ventas', $column)) {
                $userIdParts[] = 'v.`' . $column . '`';
            }
        }
        $userIdExpr = $userIdParts ? 'COALESCE(' . implode(', ', $userIdParts) . ')' : 'NULL';
        $annulledAmountExpr = flus_table_exists($pdo, 'venta_anulaciones')
            ? "GREATEST((SELECT COALESCE(SUM(va.monto_total), 0) FROM venta_anulaciones va WHERE va.venta_id = v.id AND va.estado = 'CONFIRMADA'), CASE WHEN UPPER({$selectColumn($pdo, 'ventas', 'estado', "'EMITIDA'")}) = 'ANULADA' THEN {$selectColumn($pdo, 'ventas', 'total', '0')} ELSE 0 END)"
            : "CASE WHEN UPPER({$selectColumn($pdo, 'ventas', 'estado', "'EMITIDA'")}) = 'ANULADA' THEN {$selectColumn($pdo, 'ventas', 'total', '0')} ELSE 0 END";

        $where = ['v.id > :after_id'];
        $params = ['after_id' => $options['after_id']];
        if ($options['from'] !== '') {
            $where[] = 'v.fecha >= :from_date';
            $params['from_date'] = $options['from'] . ' 00:00:00';
        }
        if ($options['to'] !== '') {
            $where[] = 'v.fecha < DATE_ADD(:to_date, INTERVAL 1 DAY)';
            $params['to_date'] = $options['to'] . ' 00:00:00';
        }

        $salesStmt = $pdo->prepare("
            SELECT v.id, {$selectColumn($pdo, 'ventas', 'request_uid', "''")} AS request_uid,
                   v.fecha, {$userIdExpr} AS resolved_user_id,
                   {$selectColumn($pdo, 'ventas', 'caja_id', '0')} AS caja_id,
                   {$selectColumn($pdo, 'ventas', 'terminal_id', '0')} AS terminal_id,
                   {$selectColumn($pdo, 'ventas', 'total', '0')} AS total,
                   {$selectColumn($pdo, 'ventas', 'total_bruto', '0')} AS total_bruto,
                   {$selectColumn($pdo, 'ventas', 'descuento_total', '0')} AS descuento_total,
                   {$selectColumn($pdo, 'ventas', 'ajuste_precio_total', '0')} AS ajuste_precio_total,
                   {$selectColumn($pdo, 'ventas', 'ajuste_precio_redondeo_total', '0')} AS ajuste_precio_redondeo_total,
                   {$selectColumn($pdo, 'ventas', 'medio_pago', "'EFECTIVO'")} AS medio_pago,
                   {$selectColumn($pdo, 'ventas', 'monto_pagado', '0')} AS monto_pagado,
                   {$selectColumn($pdo, 'ventas', 'vuelto', '0')} AS vuelto,
                   {$selectColumn($pdo, 'ventas', 'monto_cc', '0')} AS monto_cc,
                   {$selectColumn($pdo, 'ventas', 'estado', "'EMITIDA'")} AS estado,
                   {$annulledAmountExpr} AS monto_anulado,
                   COALESCE(NULLIF(TRIM(u.nombre), ''), u.username, '') AS cajero_nombre
            FROM ventas v
            LEFT JOIN users u ON u.id = {$userIdExpr}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY v.id ASC
            LIMIT {$options['limit']}
        ");
        $salesStmt->execute($params);
        $sales = $salesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $itemStmt = $pdo->prepare("
            SELECT vi.producto_id, COALESCE(p.codigo, '') AS codigo, COALESCE(p.nombre, '') AS nombre,
                   vi.cantidad, vi.precio, vi.subtotal
            FROM venta_items vi
            LEFT JOIN productos p ON p.id = vi.producto_id
            WHERE vi.venta_id = ?
            ORDER BY vi.id ASC
        ");
        $paymentStmt = flus_table_exists($pdo, 'venta_pagos')
            ? $pdo->prepare('SELECT medio_pago AS medio, monto FROM venta_pagos WHERE venta_id = ? ORDER BY id ASC')
            : null;
        $existingStmt = $pdo->prepare('SELECT status FROM cloud_sync_queue WHERE event_uid = ? LIMIT 1');
        $summary = [
            'mode' => $enqueue ? 'enqueue' : 'preview',
            'selected' => count($sales),
            'queued' => 0,
            'existing' => 0,
            'failed' => 0,
            'last_id' => $options['after_id'],
        ];

        foreach ($sales as $sale) {
            $saleId = (int)$sale['id'];
            $requestUid = trim((string)$sale['request_uid']);
            $eventUid = $requestUid !== '' ? ('sale:' . $requestUid) : ('sale-id:' . $saleId);
            $existingStmt->execute([$eventUid]);
            if ($existingStmt->fetchColumn() !== false) {
                $summary['existing']++;
                $summary['last_id'] = $saleId;
                continue;
            }
            if (!$enqueue) {
                $summary['queued']++;
                $summary['last_id'] = $saleId;
                continue;
            }

            $itemStmt->execute([$saleId]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $payments = [];
            if ($paymentStmt instanceof PDOStatement) {
                $paymentStmt->execute([$saleId]);
                $payments = $paymentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
            $result = flus_cloud_sync_enqueue_sale($pdo, [
                'venta_id' => $saleId,
                'request_uid' => $requestUid,
                'fecha' => (string)$sale['fecha'],
                'caja_id' => (int)$sale['caja_id'],
                'terminal_id' => (int)$sale['terminal_id'],
                'user_id' => (int)$sale['resolved_user_id'],
                'cajero_nombre' => (string)$sale['cajero_nombre'],
                'estado' => (string)$sale['estado'],
                'monto_anulado' => (float)$sale['monto_anulado'],
                'total' => (float)$sale['total'],
                'total_bruto' => (float)$sale['total_bruto'],
                'descuento_total' => (float)$sale['descuento_total'],
                'ajuste_precio_total' => (float)$sale['ajuste_precio_total'],
                'ajuste_precio_redondeo_total' => (float)$sale['ajuste_precio_redondeo_total'],
                'medio_pago' => (string)$sale['medio_pago'],
                'monto_pagado' => (float)$sale['monto_pagado'],
                'vuelto' => (float)$sale['vuelto'],
                'monto_cc' => (float)$sale['monto_cc'],
                'pagos' => $payments,
                'items_count' => count($items),
                'items' => $items,
            ]);
            if (!empty($result['duplicate'])) {
                $summary['existing']++;
            } elseif (!empty($result['queued'])) {
                $summary['queued']++;
            } else {
                $summary['failed']++;
            }
            $summary['last_id'] = $saleId;
        }

        return $summary;
    }
}

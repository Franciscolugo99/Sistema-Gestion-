<?php
// src/cobranzas_lib.php

declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';

$flusApiHelpersPath = __DIR__ . '/api_helpers.php';
if (file_exists($flusApiHelpersPath)) {
    require_once $flusApiHelpersPath;
}

function flus_cobranzas_tables_ready(PDO $pdo): bool
{
    return flus_table_exists($pdo, 'cobranzas') && flus_table_exists($pdo, 'cobranza_aplicaciones');
}

function flus_cobranzas_insert_dynamic(PDO $pdo, string $table, array $data): int
{
    $schema = flus_current_db($pdo);
    if ($schema === '' || !flus_table_exists($pdo, $table, $schema)) {
        throw new RuntimeException("La tabla {$table} no existe.");
    }

    $colsSet = flus_columns_set($pdo, $schema, $table);
    $cols = [];
    $placeholders = [];
    $params = [];

    foreach ($data as $col => $value) {
        $col = (string)$col;
        if (!isset($colsSet[$col])) {
            continue;
        }
        $cols[] = "`{$col}`";
        $placeholders[] = ':' . $col;
        $params[':' . $col] = $value;
    }

    if ($cols === []) {
        throw new RuntimeException("No hay columnas compatibles para insertar en {$table}.");
    }

    $sql = sprintf(
        'INSERT INTO `%s` (%s) VALUES (%s)',
        $table,
        implode(', ', $cols),
        implode(', ', $placeholders)
    );

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return (int)$pdo->lastInsertId();
}

function flus_cobranzas_build_sale_external_key(int $ventaId, int $linea, string $medioPago, float $monto): string
{
    return implode(':', [
        'VENTA',
        max(0, $ventaId),
        'LINEA',
        max(1, $linea),
        strtoupper(trim($medioPago)),
        number_format(round($monto, 2), 2, '.', ''),
    ]);
}

function flus_cobranzas_build_cc_external_key(int $ccMovimientoId): string
{
    return 'CCPAGO:' . max(0, $ccMovimientoId);
}

function flus_cobranzas_build_application_key(int $cobranzaId, string $tipoAplicacion, int $refId): string
{
    return implode(':', [
        'COB',
        max(0, $cobranzaId),
        strtoupper(trim($tipoAplicacion)),
        max(0, $refId),
    ]);
}

function flus_cobranzas_find_by_external_key(PDO $pdo, string $externalKey): ?array
{
    $externalKey = trim($externalKey);
    if ($externalKey === '' || !flus_cobranzas_tables_ready($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM cobranzas WHERE external_key = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$externalKey]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function flus_cobranzas_find_application_by_key(PDO $pdo, string $applicationKey): ?array
{
    $applicationKey = trim($applicationKey);
    if ($applicationKey === '' || !flus_cobranzas_tables_ready($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM cobranza_aplicaciones WHERE application_key = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$applicationKey]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function flus_cobranzas_create(PDO $pdo, array $payload): int
{
    if (!flus_cobranzas_tables_ready($pdo)) {
        return 0;
    }

    $externalKey = trim((string)($payload['external_key'] ?? ''));
    if ($externalKey !== '') {
        $existing = flus_cobranzas_find_by_external_key($pdo, $externalKey);
        if (is_array($existing)) {
            return (int)($existing['id'] ?? 0);
        }
    }

    $timestamp = date('Y-m-d H:i:s');
    return flus_cobranzas_insert_dynamic($pdo, 'cobranzas', [
        'external_key' => $externalKey !== '' ? $externalKey : null,
        'origen' => strtoupper(trim((string)($payload['origen'] ?? 'GENERAL'))) ?: 'GENERAL',
        'estado' => strtoupper(trim((string)($payload['estado'] ?? 'ACTIVA'))) ?: 'ACTIVA',
        'venta_id' => (int)($payload['venta_id'] ?? 0) > 0 ? (int)$payload['venta_id'] : null,
        'cliente_id' => (int)($payload['cliente_id'] ?? 0) > 0 ? (int)$payload['cliente_id'] : null,
        'cc_movimiento_id' => (int)($payload['cc_movimiento_id'] ?? 0) > 0 ? (int)$payload['cc_movimiento_id'] : null,
        'caja_id' => (int)($payload['caja_id'] ?? 0) > 0 ? (int)$payload['caja_id'] : null,
        'caja_movimiento_id' => (int)($payload['caja_movimiento_id'] ?? 0) > 0 ? (int)$payload['caja_movimiento_id'] : null,
        'medio_pago' => trim((string)($payload['medio_pago'] ?? '')),
        'importe_total' => round((float)($payload['importe_total'] ?? 0), 2),
        'referencia' => trim((string)($payload['referencia'] ?? '')) ?: null,
        'observaciones' => trim((string)($payload['observaciones'] ?? '')) ?: null,
        'created_by' => (int)($payload['created_by'] ?? 0) > 0 ? (int)$payload['created_by'] : null,
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
}

function flus_cobranzas_create_application(PDO $pdo, int $cobranzaId, array $payload): int
{
    if ($cobranzaId <= 0 || !flus_cobranzas_tables_ready($pdo)) {
        return 0;
    }

    $applicationKey = trim((string)($payload['application_key'] ?? ''));
    if ($applicationKey !== '') {
        $existing = flus_cobranzas_find_application_by_key($pdo, $applicationKey);
        if (is_array($existing)) {
            return (int)($existing['id'] ?? 0);
        }
    }

    return flus_cobranzas_insert_dynamic($pdo, 'cobranza_aplicaciones', [
        'cobranza_id' => $cobranzaId,
        'application_key' => $applicationKey !== '' ? $applicationKey : null,
        'tipo_aplicacion' => strtoupper(trim((string)($payload['tipo_aplicacion'] ?? 'GENERAL'))) ?: 'GENERAL',
        'venta_id' => (int)($payload['venta_id'] ?? 0) > 0 ? (int)$payload['venta_id'] : null,
        'documento_id' => (int)($payload['documento_id'] ?? 0) > 0 ? (int)$payload['documento_id'] : null,
        'factura_id' => (int)($payload['factura_id'] ?? 0) > 0 ? (int)$payload['factura_id'] : null,
        'cc_movimiento_id' => (int)($payload['cc_movimiento_id'] ?? 0) > 0 ? (int)$payload['cc_movimiento_id'] : null,
        'monto' => round((float)($payload['monto'] ?? 0), 2),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function flus_cobranzas_register_sale_payment(PDO $pdo, array $payload): int
{
    if (!flus_cobranzas_tables_ready($pdo)) {
        return 0;
    }

    $ventaId = (int)($payload['venta_id'] ?? 0);
    $linea = max(1, (int)($payload['linea'] ?? 1));
    $medioPago = trim((string)($payload['medio_pago'] ?? ''));
    $monto = round((float)($payload['monto'] ?? 0), 2);

    if ($ventaId <= 0 || $medioPago === '' || $monto <= 0) {
        return 0;
    }

    $externalKey = trim((string)($payload['external_key'] ?? ''));
    if ($externalKey === '') {
        $externalKey = flus_cobranzas_build_sale_external_key($ventaId, $linea, $medioPago, $monto);
    }

    $cobranzaId = flus_cobranzas_create($pdo, [
        'external_key' => $externalKey,
        'origen' => 'VENTA',
        'estado' => 'ACTIVA',
        'venta_id' => $ventaId,
        'cliente_id' => (int)($payload['cliente_id'] ?? 0) > 0 ? (int)$payload['cliente_id'] : null,
        'caja_id' => (int)($payload['caja_id'] ?? 0) > 0 ? (int)$payload['caja_id'] : null,
        'medio_pago' => strtoupper($medioPago),
        'importe_total' => $monto,
        'referencia' => trim((string)($payload['referencia'] ?? '')) ?: null,
        'observaciones' => trim((string)($payload['observaciones'] ?? '')) ?: null,
        'created_by' => (int)($payload['created_by'] ?? 0) > 0 ? (int)$payload['created_by'] : null,
    ]);

    if ($cobranzaId <= 0) {
        return 0;
    }

    flus_cobranzas_create_application($pdo, $cobranzaId, [
        'application_key' => flus_cobranzas_build_application_key($cobranzaId, 'VENTA', $ventaId),
        'tipo_aplicacion' => 'VENTA',
        'venta_id' => $ventaId,
        'documento_id' => (int)($payload['documento_id'] ?? 0) > 0 ? (int)$payload['documento_id'] : null,
        'factura_id' => (int)($payload['factura_id'] ?? 0) > 0 ? (int)$payload['factura_id'] : null,
        'monto' => $monto,
    ]);

    return $cobranzaId;
}

function flus_cobranzas_register_cc_payment(PDO $pdo, array $payload): int
{
    if (!flus_cobranzas_tables_ready($pdo)) {
        return 0;
    }

    $ccMovimientoId = (int)($payload['cc_movimiento_id'] ?? 0);
    $clienteId = (int)($payload['cliente_id'] ?? 0);
    $medioPago = trim((string)($payload['medio_pago'] ?? ''));
    $monto = round((float)($payload['monto'] ?? 0), 2);

    if ($ccMovimientoId <= 0 || $clienteId <= 0 || $medioPago === '' || $monto <= 0) {
        return 0;
    }

    $externalKey = trim((string)($payload['external_key'] ?? ''));
    if ($externalKey === '') {
        $externalKey = flus_cobranzas_build_cc_external_key($ccMovimientoId);
    }

    $cobranzaId = flus_cobranzas_create($pdo, [
        'external_key' => $externalKey,
        'origen' => 'CC_PAGO',
        'estado' => 'ACTIVA',
        'cliente_id' => $clienteId,
        'cc_movimiento_id' => $ccMovimientoId,
        'caja_id' => (int)($payload['caja_id'] ?? 0) > 0 ? (int)$payload['caja_id'] : null,
        'caja_movimiento_id' => (int)($payload['caja_movimiento_id'] ?? 0) > 0 ? (int)$payload['caja_movimiento_id'] : null,
        'medio_pago' => strtoupper($medioPago),
        'importe_total' => $monto,
        'referencia' => trim((string)($payload['referencia'] ?? '')) ?: null,
        'observaciones' => trim((string)($payload['observaciones'] ?? '')) ?: null,
        'created_by' => (int)($payload['created_by'] ?? 0) > 0 ? (int)$payload['created_by'] : null,
    ]);

    if ($cobranzaId <= 0) {
        return 0;
    }

    flus_cobranzas_create_application($pdo, $cobranzaId, [
        'application_key' => flus_cobranzas_build_application_key($cobranzaId, 'CC_MOVIMIENTO', $ccMovimientoId),
        'tipo_aplicacion' => 'CC_MOVIMIENTO',
        'cc_movimiento_id' => $ccMovimientoId,
        'monto' => $monto,
    ]);

    return $cobranzaId;
}

function flus_cobranzas_fetch_applications_by_venta(PDO $pdo, int $ventaId): array
{
    if ($ventaId <= 0 || !flus_cobranzas_tables_ready($pdo)) {
        return [];
    }

    $st = $pdo->prepare('SELECT * FROM cobranza_aplicaciones WHERE venta_id = ? ORDER BY id ASC');
    $st->execute([$ventaId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

function flus_cobranzas_link_factura_from_sale(PDO $pdo, int $ventaId, int $facturaId, ?int $documentoId = null): void
{
    if ($ventaId <= 0 || $facturaId <= 0 || !flus_cobranzas_tables_ready($pdo)) {
        return;
    }

    foreach (flus_cobranzas_fetch_applications_by_venta($pdo, $ventaId) as $row) {
        $applicationId = (int)($row['id'] ?? 0);
        if ($applicationId <= 0) {
            continue;
        }

        $currentFacturaId = (int)($row['factura_id'] ?? 0);
        if ($currentFacturaId > 0 && $currentFacturaId !== $facturaId) {
            continue;
        }

        $set = ['factura_id = ?'];
        $params = [$facturaId];
        if ($documentoId !== null && $documentoId > 0) {
            $set[] = 'documento_id = ?';
            $params[] = $documentoId;
        }
        $params[] = $applicationId;

        $st = $pdo->prepare('UPDATE cobranza_aplicaciones SET ' . implode(', ', $set) . ' WHERE id = ?');
        $st->execute($params);
    }
}

function flus_cobranzas_fetch_by_factura(PDO $pdo, int $facturaId): array
{
    if ($facturaId <= 0 || !flus_cobranzas_tables_ready($pdo)) {
        return [];
    }

    $st = $pdo->prepare('SELECT * FROM cobranza_aplicaciones WHERE factura_id = ? ORDER BY id ASC');
    $st->execute([$facturaId]);
    $apps = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($apps) || $apps === []) {
        return [];
    }

    $rows = [];
    foreach ($apps as $app) {
        $cobranzaId = (int)($app['cobranza_id'] ?? 0);
        if ($cobranzaId <= 0) {
            continue;
        }
        $stCob = $pdo->prepare('SELECT * FROM cobranzas WHERE id = ? LIMIT 1');
        $stCob->execute([$cobranzaId]);
        $cobranza = $stCob->fetch(PDO::FETCH_ASSOC);
        if (!is_array($cobranza)) {
            continue;
        }
        $rows[] = [
            'cobranza_id' => $cobranzaId,
            'origen' => (string)($cobranza['origen'] ?? ''),
            'medio_pago' => (string)($cobranza['medio_pago'] ?? ''),
            'importe_total' => round((float)($cobranza['importe_total'] ?? 0), 2),
            'monto_aplicado' => round((float)($app['monto'] ?? 0), 2),
            'venta_id' => (int)($app['venta_id'] ?? 0),
            'documento_id' => (int)($app['documento_id'] ?? 0),
            'factura_id' => (int)($app['factura_id'] ?? 0),
            'cc_movimiento_id' => (int)($app['cc_movimiento_id'] ?? 0),
        ];
    }

    return $rows;
}

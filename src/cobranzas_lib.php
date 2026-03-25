<?php
// src/cobranzas_lib.php

declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/facturacion_manual_lib.php';

$flusApiHelpersPath = __DIR__ . '/api_helpers.php';
if (file_exists($flusApiHelpersPath)) {
    require_once $flusApiHelpersPath;
}

function flus_cobranzas_tables_ready(PDO $pdo): bool
{
    return flus_table_exists($pdo, 'cobranzas') && flus_table_exists($pdo, 'cobranza_aplicaciones');
}

function flus_cobranzas_receipts_ready(PDO $pdo): bool
{
    return flus_cobranzas_tables_ready($pdo)
        && flus_facturacion_documentos_table_ready($pdo)
        && flus_table_exists($pdo, 'recibo_aplicaciones')
        && flus_column_exists($pdo, 'cobranzas', 'recibo_documento_id');
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

function flus_cobranzas_build_receipt_request_uid(int $cobranzaId): string
{
    return sprintf('00000000-0000-4000-8000-%012d', max(0, $cobranzaId));
}

function flus_cobranzas_build_receipt_application_key(int $reciboDocumentoId, string $tipoAplicacion, int $refId): string
{
    return implode(':', [
        'REC',
        max(0, $reciboDocumentoId),
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

function flus_cobranzas_find_by_id(PDO $pdo, int $cobranzaId): ?array
{
    if ($cobranzaId <= 0 || !flus_cobranzas_tables_ready($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM cobranzas WHERE id = ? LIMIT 1');
    $st->execute([$cobranzaId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function flus_cobranzas_find_by_cc_movimiento_id(PDO $pdo, int $ccMovimientoId): ?array
{
    if ($ccMovimientoId <= 0 || !flus_cobranzas_tables_ready($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM cobranzas WHERE cc_movimiento_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$ccMovimientoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function flus_cobranzas_find_receipt_application_by_cobranza(PDO $pdo, int $cobranzaId): ?array
{
    if ($cobranzaId <= 0 || !flus_cobranzas_receipts_ready($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM recibo_aplicaciones WHERE cobranza_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$cobranzaId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function flus_cobranzas_float_eq(float $a, float $b, float $delta = 0.009): bool
{
    return abs($a - $b) <= $delta;
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

function flus_cobranzas_find_receipt_application_by_key(PDO $pdo, string $applicationKey): ?array
{
    $applicationKey = trim($applicationKey);
    if ($applicationKey === '' || !flus_cobranzas_receipts_ready($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM recibo_aplicaciones WHERE application_key = ? ORDER BY id DESC LIMIT 1');
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

function flus_cobranzas_receipt_sync_document(PDO $pdo, int $cobranzaId): int
{
    if ($cobranzaId <= 0 || !flus_cobranzas_receipts_ready($pdo)) {
        return 0;
    }

    $cobranza = flus_cobranzas_find_by_id($pdo, $cobranzaId);
    if (!is_array($cobranza)) {
        return 0;
    }

    $linkedDocumentoId = (int)($cobranza['recibo_documento_id'] ?? 0);
    if ($linkedDocumentoId > 0) {
        $documento = flus_facturacion_documento_buscar($pdo, $linkedDocumentoId);
        if (is_array($documento) && (int)($documento['id'] ?? 0) === $linkedDocumentoId) {
            return $linkedDocumentoId;
        }
    }

    $requestUid = flus_cobranzas_build_receipt_request_uid($cobranzaId);
    $documento = flus_facturacion_documento_buscar_por_request_uid($pdo, $requestUid);
    $timestamp = date('Y-m-d H:i:s');

    if (!is_array($documento)) {
        $nota = trim((string)($cobranza['observaciones'] ?? ''));
        $referencia = trim((string)($cobranza['referencia'] ?? ''));
        if ($nota === '') {
            $nota = 'Recibo de cobranza';
        }
        if ($referencia !== '') {
            $nota .= ' · Ref: ' . $referencia;
        }

        $linkedDocumentoId = flus_cobranzas_insert_dynamic($pdo, 'documentos_comerciales', [
            'request_uid' => $requestUid,
            'tipo_documento' => 'RECIBO',
            'origen' => 'COBRANZA',
            'estado' => 'EMITIDO',
            'cliente_id' => (int)($cobranza['cliente_id'] ?? 0) > 0 ? (int)$cobranza['cliente_id'] : null,
            'venta_id' => (int)($cobranza['venta_id'] ?? 0) > 0 ? (int)$cobranza['venta_id'] : null,
            'nota' => $nota,
            'medio_pago' => trim((string)($cobranza['medio_pago'] ?? '')) ?: null,
            'total' => round((float)($cobranza['importe_total'] ?? 0), 2),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    } else {
        $linkedDocumentoId = (int)($documento['id'] ?? 0);
    }

    if ($linkedDocumentoId > 0) {
        $st = $pdo->prepare('UPDATE cobranzas SET recibo_documento_id = ?, updated_at = ? WHERE id = ?');
        $st->execute([$linkedDocumentoId, $timestamp, $cobranzaId]);
    }

    return $linkedDocumentoId;
}

function flus_cobranzas_receipt_create_application(PDO $pdo, int $reciboDocumentoId, int $cobranzaId, array $payload): int
{
    if ($reciboDocumentoId <= 0 || $cobranzaId <= 0 || !flus_cobranzas_receipts_ready($pdo)) {
        return 0;
    }

    $tipoAplicacion = strtoupper(trim((string)($payload['tipo_aplicacion'] ?? 'SALDO_CC'))) ?: 'SALDO_CC';
    $clienteId = (int)($payload['cliente_id'] ?? 0) > 0 ? (int)$payload['cliente_id'] : null;
    $ccMovimientoId = (int)($payload['cc_movimiento_id'] ?? 0) > 0 ? (int)$payload['cc_movimiento_id'] : null;
    $documentoId = (int)($payload['documento_id'] ?? 0) > 0 ? (int)$payload['documento_id'] : null;
    $facturaId = (int)($payload['factura_id'] ?? 0) > 0 ? (int)$payload['factura_id'] : null;
    $monto = round((float)($payload['monto'] ?? 0), 2);

    $existingByCobranza = flus_cobranzas_find_receipt_application_by_cobranza($pdo, $cobranzaId);
    if (is_array($existingByCobranza)) {
        $sameTarget = (int)($existingByCobranza['recibo_documento_id'] ?? 0) === $reciboDocumentoId
            && strtoupper(trim((string)($existingByCobranza['tipo_aplicacion'] ?? ''))) === $tipoAplicacion
            && (int)($existingByCobranza['cliente_id'] ?? 0) === (int)($clienteId ?? 0)
            && (int)($existingByCobranza['cc_movimiento_id'] ?? 0) === (int)($ccMovimientoId ?? 0)
            && (int)($existingByCobranza['documento_id'] ?? 0) === (int)($documentoId ?? 0)
            && (int)($existingByCobranza['factura_id'] ?? 0) === (int)($facturaId ?? 0)
            && flus_cobranzas_float_eq((float)($existingByCobranza['monto'] ?? 0), $monto);

        if ($sameTarget) {
            return (int)($existingByCobranza['id'] ?? 0);
        }

        throw new RuntimeException('La cobranza ya tiene una aplicacion de recibo distinta. Fase 4 no soporta multiples aplicaciones por cobranza.');
    }

    $refId = 0;
    if ($facturaId !== null) {
        $refId = $facturaId;
    } elseif ($documentoId !== null) {
        $refId = $documentoId;
    } elseif ($ccMovimientoId !== null) {
        $refId = $ccMovimientoId;
    } else {
        $refId = $cobranzaId;
    }

    $applicationKey = trim((string)($payload['application_key'] ?? ''));
    if ($applicationKey === '') {
        $applicationKey = flus_cobranzas_build_receipt_application_key($reciboDocumentoId, $tipoAplicacion, $refId);
    }

    $existing = flus_cobranzas_find_receipt_application_by_key($pdo, $applicationKey);
    if (is_array($existing)) {
        if ((int)($existing['cobranza_id'] ?? 0) !== $cobranzaId) {
            throw new RuntimeException('La clave de aplicacion del recibo ya existe para otra cobranza.');
        }
        return (int)($existing['id'] ?? 0);
    }

    return flus_cobranzas_insert_dynamic($pdo, 'recibo_aplicaciones', [
        'recibo_documento_id' => $reciboDocumentoId,
        'cobranza_id' => $cobranzaId,
        'application_key' => $applicationKey,
        'tipo_aplicacion' => $tipoAplicacion,
        'cliente_id' => $clienteId,
        'cc_movimiento_id' => $ccMovimientoId,
        'documento_id' => $documentoId,
        'factura_id' => $facturaId,
        'monto' => $monto,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

function flus_cobranzas_resolve_receipt_target(PDO $pdo, int $clienteId, ?int $facturaId = null, ?int $documentoId = null): array
{
    $clienteId = max(0, $clienteId);
    $facturaId = $facturaId !== null ? max(0, $facturaId) : 0;
    $documentoId = $documentoId !== null ? max(0, $documentoId) : 0;

    $documento = null;
    if ($documentoId > 0) {
        if (!flus_facturacion_documentos_table_ready($pdo)) {
            return ['ok' => false, 'error' => 'Las tablas documentales no existen para aplicar el recibo.'];
        }

        $documento = flus_facturacion_documento_buscar($pdo, $documentoId);
        if (!is_array($documento)) {
            return ['ok' => false, 'error' => 'El documento comercial indicado no existe.'];
        }

        if (strtoupper(trim((string)($documento['tipo_documento'] ?? ''))) === 'RECIBO') {
            return ['ok' => false, 'error' => 'No se puede aplicar un recibo sobre otro recibo.'];
        }

        $documentoClienteId = (int)($documento['cliente_id'] ?? 0);
        if ($clienteId > 0 && $documentoClienteId > 0 && $documentoClienteId !== $clienteId) {
            return ['ok' => false, 'error' => 'El documento comercial indicado no pertenece al cliente seleccionado.'];
        }
    }

    if ($facturaId > 0) {
        if (!flus_table_exists($pdo, 'facturas')) {
            return ['ok' => false, 'error' => 'La tabla facturas no existe para aplicar el recibo.'];
        }

        $st = $pdo->prepare('SELECT * FROM facturas WHERE id = ? LIMIT 1');
        $st->execute([$facturaId]);
        $factura = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!is_array($factura)) {
            return ['ok' => false, 'error' => 'La factura indicada no existe.'];
        }

        $facturaClienteId = (int)($factura['cliente_id'] ?? 0);
        if ($clienteId > 0 && $facturaClienteId > 0 && $facturaClienteId !== $clienteId) {
            return ['ok' => false, 'error' => 'La factura indicada no pertenece al cliente seleccionado.'];
        }

        $facturaDocumentoId = (int)($factura['documento_id'] ?? 0);
        if ($documentoId > 0) {
            if ($facturaDocumentoId <= 0) {
                return ['ok' => false, 'error' => 'La factura indicada no esta vinculada al documento comercial recibido.'];
            }
            if ($facturaDocumentoId !== $documentoId) {
                return ['ok' => false, 'error' => 'La factura indicada no coincide con el documento comercial recibido.'];
            }
        }

        if ($facturaDocumentoId > 0 && !is_array($documento)) {
            $documento = flus_facturacion_documento_buscar($pdo, $facturaDocumentoId);
            if (!is_array($documento)) {
                return ['ok' => false, 'error' => 'La factura apunta a un documento comercial inexistente.'];
            }
            if (strtoupper(trim((string)($documento['tipo_documento'] ?? ''))) === 'RECIBO') {
                return ['ok' => false, 'error' => 'La factura apunta a un recibo, lo cual es incoherente para esta aplicacion.'];
            }
            $documentoClienteId = (int)($documento['cliente_id'] ?? 0);
            $clienteValidacion = $facturaClienteId > 0 ? $facturaClienteId : $clienteId;
            if ($clienteValidacion > 0 && $documentoClienteId > 0 && $documentoClienteId !== $clienteValidacion) {
                return ['ok' => false, 'error' => 'La factura y el documento comercial pertenecen a clientes distintos.'];
            }
        }

        return [
            'ok' => true,
            'tipo_aplicacion' => 'FACTURA',
            'cliente_id' => $facturaClienteId > 0 ? $facturaClienteId : ($documento['cliente_id'] ?? ($clienteId > 0 ? $clienteId : null)),
            'factura_id' => $facturaId,
            'documento_id' => $facturaDocumentoId > 0 ? $facturaDocumentoId : null,
        ];
    }

    if ($documentoId > 0) {
        return [
            'ok' => true,
            'tipo_aplicacion' => 'DOCUMENTO',
            'cliente_id' => (int)($documento['cliente_id'] ?? 0) > 0 ? (int)$documento['cliente_id'] : ($clienteId > 0 ? $clienteId : null),
            'factura_id' => null,
            'documento_id' => $documentoId,
        ];
    }

    return [
        'ok' => true,
        'tipo_aplicacion' => 'SALDO_CC',
        'cliente_id' => $clienteId > 0 ? $clienteId : null,
        'factura_id' => null,
        'documento_id' => null,
    ];
}

function flus_cobranzas_attach_receipt_to_cobranza(PDO $pdo, int $cobranzaId, array $payload = []): array
{
    if ($cobranzaId <= 0 || !flus_cobranzas_receipts_ready($pdo)) {
        return [
            'cobranza_id' => $cobranzaId,
            'recibo_documento_id' => 0,
            'recibo_aplicacion_id' => 0,
            'tipo_aplicacion' => null,
        ];
    }

    $cobranza = flus_cobranzas_find_by_id($pdo, $cobranzaId);
    if (!is_array($cobranza)) {
        return [
            'cobranza_id' => $cobranzaId,
            'recibo_documento_id' => 0,
            'recibo_aplicacion_id' => 0,
            'tipo_aplicacion' => null,
        ];
    }

    $target = flus_cobranzas_resolve_receipt_target(
        $pdo,
        (int)($payload['cliente_id'] ?? ($cobranza['cliente_id'] ?? 0)),
        isset($payload['factura_id']) ? (int)$payload['factura_id'] : null,
        isset($payload['documento_id']) ? (int)$payload['documento_id'] : null
    );

    if (($target['ok'] ?? false) !== true) {
        throw new RuntimeException((string)($target['error'] ?? 'No se pudo resolver la aplicacion del recibo.'));
    }

    $cobranzaTotal = round((float)($cobranza['importe_total'] ?? 0), 2);
    $montoAplicado = round((float)($payload['monto'] ?? $cobranzaTotal), 2);
    if ($montoAplicado <= 0) {
        throw new RuntimeException('El monto aplicado del recibo debe ser mayor a cero.');
    }
    if ($cobranzaTotal > 0 && $montoAplicado - $cobranzaTotal > 0.009) {
        throw new RuntimeException('El monto aplicado del recibo no puede superar el importe total de la cobranza.');
    }

    $reciboDocumentoId = flus_cobranzas_receipt_sync_document($pdo, $cobranzaId);
    if ($reciboDocumentoId <= 0) {
        return [
            'cobranza_id' => $cobranzaId,
            'recibo_documento_id' => 0,
            'recibo_aplicacion_id' => 0,
            'tipo_aplicacion' => null,
        ];
    }

    $reciboAplicacionId = flus_cobranzas_receipt_create_application($pdo, $reciboDocumentoId, $cobranzaId, [
        'tipo_aplicacion' => (string)($target['tipo_aplicacion'] ?? 'SALDO_CC'),
        'cliente_id' => (int)($target['cliente_id'] ?? ($cobranza['cliente_id'] ?? 0)) > 0 ? (int)($target['cliente_id'] ?? ($cobranza['cliente_id'] ?? 0)) : null,
        'cc_movimiento_id' => (int)($payload['cc_movimiento_id'] ?? ($cobranza['cc_movimiento_id'] ?? 0)) > 0 ? (int)($payload['cc_movimiento_id'] ?? ($cobranza['cc_movimiento_id'] ?? 0)) : null,
        'documento_id' => (int)($target['documento_id'] ?? 0) > 0 ? (int)$target['documento_id'] : null,
        'factura_id' => (int)($target['factura_id'] ?? 0) > 0 ? (int)$target['factura_id'] : null,
        'monto' => $montoAplicado,
    ]);

    return [
        'cobranza_id' => $cobranzaId,
        'recibo_documento_id' => $reciboDocumentoId,
        'recibo_aplicacion_id' => $reciboAplicacionId,
        'tipo_aplicacion' => (string)($target['tipo_aplicacion'] ?? 'SALDO_CC'),
        'factura_id' => (int)($target['factura_id'] ?? 0) > 0 ? (int)$target['factura_id'] : null,
        'documento_id' => (int)($target['documento_id'] ?? 0) > 0 ? (int)$target['documento_id'] : null,
        'monto_aplicado' => $montoAplicado,
    ];
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

function flus_cobranzas_link_receipt_factura_from_documento(PDO $pdo, int $documentoId, int $facturaId): void
{
    if ($documentoId <= 0 || $facturaId <= 0 || !flus_cobranzas_receipts_ready($pdo)) {
        return;
    }

    $st = $pdo->prepare('SELECT * FROM recibo_aplicaciones WHERE documento_id = ? ORDER BY id ASC');
    $st->execute([$documentoId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $row) {
        $applicationId = (int)($row['id'] ?? 0);
        if ($applicationId <= 0) {
            continue;
        }

        $currentFacturaId = (int)($row['factura_id'] ?? 0);
        if ($currentFacturaId > 0 && $currentFacturaId !== $facturaId) {
            continue;
        }

        $stUpd = $pdo->prepare('UPDATE recibo_aplicaciones SET factura_id = ? WHERE id = ?');
        $stUpd->execute([$facturaId, $applicationId]);
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

function flus_cobranzas_fetch_receipts_by_factura(PDO $pdo, int $facturaId, ?int $documentoId = null): array
{
    if (!flus_cobranzas_receipts_ready($pdo)) {
        return [];
    }

    $apps = [];
    $seen = [];

    if ($facturaId > 0) {
        $st = $pdo->prepare('SELECT * FROM recibo_aplicaciones WHERE factura_id = ? ORDER BY id ASC');
        $st->execute([$facturaId]);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $appId = (int)($row['id'] ?? 0);
            if ($appId <= 0 || isset($seen[$appId])) {
                continue;
            }
            $seen[$appId] = true;
            $apps[] = $row;
        }
    }

    if ($documentoId !== null && $documentoId > 0) {
        $st = $pdo->prepare('SELECT * FROM recibo_aplicaciones WHERE documento_id = ? ORDER BY id ASC');
        $st->execute([$documentoId]);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $appId = (int)($row['id'] ?? 0);
            if ($appId <= 0 || isset($seen[$appId])) {
                continue;
            }
            $seen[$appId] = true;
            $apps[] = $row;
        }
    }

    if ($apps === []) {
        return [];
    }

    $rows = [];
    foreach ($apps as $app) {
        $cobranzaId = (int)($app['cobranza_id'] ?? 0);
        $reciboDocumentoId = (int)($app['recibo_documento_id'] ?? 0);
        if ($cobranzaId <= 0 || $reciboDocumentoId <= 0) {
            continue;
        }

        $cobranza = flus_cobranzas_find_by_id($pdo, $cobranzaId);
        $recibo = flus_facturacion_documento_buscar($pdo, $reciboDocumentoId);
        if (!is_array($cobranza) || !is_array($recibo)) {
            continue;
        }

        $rows[] = [
            'recibo_aplicacion_id' => (int)($app['id'] ?? 0),
            'recibo_documento_id' => $reciboDocumentoId,
            'cobranza_id' => $cobranzaId,
            'cliente_id' => (int)($app['cliente_id'] ?? ($cobranza['cliente_id'] ?? 0)),
            'tipo_aplicacion' => (string)($app['tipo_aplicacion'] ?? ''),
            'monto_aplicado' => round((float)($app['monto'] ?? 0), 2),
            'factura_id' => (int)($app['factura_id'] ?? 0),
            'documento_id' => (int)($app['documento_id'] ?? 0),
            'cc_movimiento_id' => (int)($app['cc_movimiento_id'] ?? 0),
            'caja_id' => (int)($cobranza['caja_id'] ?? 0),
            'caja_movimiento_id' => (int)($cobranza['caja_movimiento_id'] ?? 0),
            'medio_pago' => (string)($cobranza['medio_pago'] ?? ''),
            'importe_total' => round((float)($cobranza['importe_total'] ?? 0), 2),
            'recibo_total' => round((float)($recibo['total'] ?? 0), 2),
            'cobranza_estado' => (string)($cobranza['estado'] ?? ''),
            'recibo_estado' => (string)($recibo['estado'] ?? ''),
            'referencia' => (string)($cobranza['referencia'] ?? ''),
            'observaciones' => (string)($cobranza['observaciones'] ?? ''),
            'created_at' => (string)($recibo['created_at'] ?? ($cobranza['created_at'] ?? '')),
            'nota' => (string)($recibo['nota'] ?? ''),
        ];
    }

    usort($rows, static fn(array $a, array $b): int => strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? '')));

    return $rows;
}

<?php
// src/facturacion_manual_lib.php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';

function flus_facturacion_habilitada(PDO $pdo): bool
{
    return config_get($pdo, 'facturacion_habilitada', '0') === '1';
}

function flus_facturacion_uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    $hex = bin2hex($bytes);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function flus_facturacion_ensure_manual_items_table(PDO $pdo): void
{
    if (flus_table_exists($pdo, 'factura_manual_items')) {
        return;
    }
    throw new RuntimeException(
        'Falta la tabla factura_manual_items. Aplica primero la migracion migrations/007_support_modules_schema.sql.'
    );
}

function flus_facturacion_manual_items_fetch(PDO $pdo, int $ventaId): array
{
    if ($ventaId <= 0 || !flus_table_exists($pdo, 'factura_manual_items')) {
        return [];
    }

    $st = $pdo->prepare('
        SELECT
            codigo,
            descripcion AS nombre,
            cantidad,
            precio_unitario AS precio,
            subtotal,
            iva_porcentaje
        FROM factura_manual_items
        WHERE venta_id = ?
        ORDER BY id ASC
    ');
    $st->execute([$ventaId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function flus_facturacion_documentos_table_ready(PDO $pdo): bool
{
    return flus_table_exists($pdo, 'documentos_comerciales') && flus_table_exists($pdo, 'documento_items');
}

function flus_facturacion_documento_buscar(PDO $pdo, int $documentoId): ?array
{
    if ($documentoId <= 0 || !flus_facturacion_documentos_table_ready($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM documentos_comerciales WHERE id = ? LIMIT 1');
    $st->execute([$documentoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    return is_array($row) ? $row : null;
}

function flus_facturacion_documento_buscar_por_request_uid(PDO $pdo, string $requestUid): ?array
{
    $requestUid = trim($requestUid);
    if ($requestUid === '' || !flus_facturacion_documentos_table_ready($pdo)) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM documentos_comerciales WHERE request_uid = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$requestUid]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    return is_array($row) ? $row : null;
}

function flus_facturacion_documento_items_fetch(PDO $pdo, int $documentoId): array
{
    if ($documentoId <= 0 || !flus_facturacion_documentos_table_ready($pdo)) {
        return [];
    }

    $st = $pdo->prepare('
        SELECT
            codigo,
            descripcion AS nombre,
            cantidad,
            precio_unitario AS precio,
            subtotal,
            iva_porcentaje
        FROM documento_items
        WHERE documento_id = ?
        ORDER BY id ASC
    ');
    $st->execute([$documentoId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function flus_facturacion_documento_items_normalizar_payload(array $items): array
{
    $normalized = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $descripcion = trim((string)($item['descripcion'] ?? $item['nombre'] ?? ''));
        if ($descripcion === '') {
            continue;
        }

        $normalized[] = [
            'codigo' => $item['codigo'] ?? null,
            'descripcion' => $descripcion,
            'cantidad' => (float)($item['cantidad'] ?? 0),
            'precio_unitario' => round((float)($item['precio_unitario'] ?? $item['precio'] ?? 0), 2),
            'subtotal' => round((float)($item['subtotal'] ?? 0), 2),
            'iva_porcentaje' => round((float)($item['iva_porcentaje'] ?? 21), 2),
        ];
    }

    return $normalized;
}

function flus_facturacion_documento_crear_manual(PDO $pdo, int $clienteId, array $items, array $meta = [], array $opciones = []): int
{
    if (!flus_facturacion_documentos_table_ready($pdo)) {
        throw new RuntimeException(
            'Faltan las tablas documentales de facturación manual. Aplica primero la migracion migrations/017_facturacion_documentos_manual.sql.'
        );
    }

    $requestUid = trim((string)($opciones['request_uid'] ?? ''));
    if ($requestUid !== '') {
        $existing = flus_facturacion_documento_buscar_por_request_uid($pdo, $requestUid);
        if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
            return (int)$existing['id'];
        }
    }

    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) {
        $pdo->beginTransaction();
    }

    try {
        $timestamp = date('Y-m-d H:i:s');
        $total = 0.0;
        foreach ($items as $item) {
            $total += (float)($item['subtotal'] ?? 0);
        }
        $total = round($total, 2);

        $documentoId = flus_facturacion_insert_dynamic($pdo, 'documentos_comerciales', [
            'request_uid' => $requestUid !== '' ? $requestUid : null,
            'tipo_documento' => 'FACTURA_MANUAL',
            'origen' => 'MANUAL',
            'estado' => 'PENDIENTE',
            'cliente_id' => $clienteId > 0 ? $clienteId : null,
            'venta_id' => isset($opciones['venta_id']) && (int)$opciones['venta_id'] > 0 ? (int)$opciones['venta_id'] : null,
            'nota' => trim((string)($meta['nota'] ?? 'Factura manual sin caja')) ?: 'Factura manual sin caja',
            'medio_pago' => trim((string)($meta['medio_pago'] ?? 'FACTURA_MANUAL')) ?: 'FACTURA_MANUAL',
            'total' => $total,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        foreach ($items as $item) {
            flus_facturacion_insert_dynamic($pdo, 'documento_items', [
                'documento_id' => $documentoId,
                'codigo' => $item['codigo'] ?? null,
                'descripcion' => $item['descripcion'],
                'cantidad' => $item['cantidad'],
                'precio_unitario' => $item['precio_unitario'],
                'subtotal' => $item['subtotal'],
                'iva_porcentaje' => $item['iva_porcentaje'],
                'created_at' => $timestamp,
            ]);
        }

        if ($ownsTx) {
            $pdo->commit();
        }

        return $documentoId;
    } catch (Throwable $e) {
        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($requestUid !== '') {
            $existing = flus_facturacion_documento_buscar_por_request_uid($pdo, $requestUid);
            if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
                return (int)$existing['id'];
            }
        }

        throw $e;
    }
}

function flus_facturacion_documento_actualizar_venta(PDO $pdo, int $documentoId, int $ventaId): void
{
    if ($documentoId <= 0 || $ventaId <= 0 || !flus_facturacion_documentos_table_ready($pdo)) {
        return;
    }

    $documento = flus_facturacion_documento_buscar($pdo, $documentoId);
    $ventaActual = (int)($documento['venta_id'] ?? 0);
    if ($ventaActual > 0 && $ventaActual !== $ventaId) {
        return;
    }

    $st = $pdo->prepare('UPDATE documentos_comerciales SET venta_id = ?, updated_at = ? WHERE id = ?');
    $st->execute([$ventaId, date('Y-m-d H:i:s'), $documentoId]);
}

function flus_facturacion_documento_actualizar_estado(PDO $pdo, int $documentoId, string $estado): void
{
    if ($documentoId <= 0 || !flus_facturacion_documentos_table_ready($pdo)) {
        return;
    }

    $st = $pdo->prepare('UPDATE documentos_comerciales SET estado = ?, updated_at = ? WHERE id = ?');
    $st->execute([trim($estado) !== '' ? trim($estado) : 'PENDIENTE', date('Y-m-d H:i:s'), $documentoId]);
}

function flus_facturacion_documento_actualizar_cabecera(PDO $pdo, int $documentoId, array $data): void
{
    if ($documentoId <= 0 || !flus_facturacion_documentos_table_ready($pdo)) {
        return;
    }

    $updates = [];
    if (array_key_exists('cliente_id', $data) && flus_column_exists($pdo, 'documentos_comerciales', 'cliente_id')) {
        $clienteId = (int)($data['cliente_id'] ?? 0);
        $updates['cliente_id'] = $clienteId > 0 ? $clienteId : null;
    }
    if (array_key_exists('nota', $data) && flus_column_exists($pdo, 'documentos_comerciales', 'nota')) {
        $nota = trim((string)($data['nota'] ?? ''));
        $updates['nota'] = $nota !== '' ? $nota : null;
    }

    if ($updates === []) {
        return;
    }

    $sets = [];
    $params = [':id' => $documentoId];
    foreach ($updates as $col => $value) {
        $sets[] = "`{$col}` = :{$col}";
        $params[':' . $col] = $value;
    }

    if (flus_column_exists($pdo, 'documentos_comerciales', 'updated_at')) {
        $sets[] = '`updated_at` = :updated_at';
        $params[':updated_at'] = date('Y-m-d H:i:s');
    }

    $sql = 'UPDATE documentos_comerciales SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $st = $pdo->prepare($sql);
    $st->execute($params);
}

function flus_facturacion_documento_vincular_venta(PDO $pdo, int $documentoId, int $ventaId): void
{
    if ($documentoId <= 0 || $ventaId <= 0 || !flus_facturacion_documentos_table_ready($pdo) || !flus_table_exists($pdo, 'ventas')) {
        throw new RuntimeException('No se puede vincular la venta al documento.');
    }

    $documento = flus_facturacion_documento_buscar($pdo, $documentoId);
    if (!is_array($documento)) {
        throw new RuntimeException('El documento comercial no existe.');
    }
    if (flus_facturacion_documento_estado_bloqueado((string)($documento['estado'] ?? ''))) {
        throw new RuntimeException('El documento esta anulado o cancelado.');
    }

    $stVenta = $pdo->prepare('SELECT * FROM ventas WHERE id = ? LIMIT 1');
    $stVenta->execute([$ventaId]);
    $venta = $stVenta->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!is_array($venta)) {
        throw new RuntimeException('La venta indicada no existe.');
    }

    $clienteDocumento = (int)($documento['cliente_id'] ?? 0);
    if ($clienteDocumento <= 0) {
        throw new RuntimeException('Vincula primero un cliente al documento.');
    }

    $clienteVenta = (int)($venta['cliente_id'] ?? 0);
    if ($clienteVenta > 0 && $clienteVenta !== $clienteDocumento) {
        throw new RuntimeException('La venta pertenece a otro cliente y no se puede vincular a este documento.');
    }

    if ($clienteVenta <= 0 && flus_column_exists($pdo, 'ventas', 'cliente_id')) {
        $stUpdateVenta = $pdo->prepare('UPDATE ventas SET cliente_id = ? WHERE id = ?');
        $stUpdateVenta->execute([$clienteDocumento, $ventaId]);
    }

    $nuevoEstado = (string)($documento['estado'] ?? 'PENDIENTE');
    $tipoDocumento = flus_facturacion_documento_tipo_normalizar((string)($documento['tipo_documento'] ?? ''));
    if ($tipoDocumento === 'PRESUPUESTO') {
        $nuevoEstado = 'CONVERTIDO_VENTA';
    }

    $payload = [
        'venta_id' => $ventaId,
    ];
    if (flus_column_exists($pdo, 'documentos_comerciales', 'estado')) {
        $payload['estado'] = $nuevoEstado;
    }

    $sets = ['`venta_id` = :venta_id'];
    $params = [
        ':venta_id' => $ventaId,
        ':id' => $documentoId,
    ];
    if (isset($payload['estado'])) {
        $sets[] = '`estado` = :estado';
        $params[':estado'] = $payload['estado'];
    }
    if (flus_column_exists($pdo, 'documentos_comerciales', 'updated_at')) {
        $sets[] = '`updated_at` = :updated_at';
        $params[':updated_at'] = date('Y-m-d H:i:s');
    }

    $stDocumento = $pdo->prepare('UPDATE documentos_comerciales SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $stDocumento->execute($params);
}

function flus_facturacion_facturas_require_venta(PDO $pdo): bool
{
    if (!flus_table_exists($pdo, 'facturas') || !flus_column_exists($pdo, 'facturas', 'venta_id')) {
        return false;
    }

    $meta = flus_column_metadata($pdo, 'facturas', 'venta_id');
    if (!is_array($meta)) {
        return false;
    }

    return strtoupper(trim((string)($meta['IS_NULLABLE'] ?? 'YES'))) === 'NO';
}

function flus_facturacion_documento_acciones(PDO $pdo, int $documentoId): array
{
    $documento = flus_facturacion_documento_buscar($pdo, $documentoId);
    if (!is_array($documento)) {
        return [
            'tiene_cliente' => false,
            'puede_generar_remito' => false,
            'motivo_generar_remito' => 'Documento no encontrado.',
            'puede_generar_venta' => false,
            'motivo_generar_venta' => 'Documento no encontrado.',
            'puede_vincular_venta' => false,
            'motivo_vincular_venta' => 'Documento no encontrado.',
            'puede_emitir_factura' => false,
            'motivo_emitir_factura' => 'Documento no encontrado.',
            'siguiente_accion_label' => 'Sin accion disponible',
            'impacto_operativo' => 'El documento por si solo no impacta stock ni caja.',
            'remito' => null,
            'venta' => null,
            'factura' => null,
        ];
    }

    $tipo = flus_facturacion_documento_tipo_normalizar((string)($documento['tipo_documento'] ?? ''));
    $estado = strtoupper(trim((string)($documento['estado'] ?? 'PENDIENTE')));
    $tieneCliente = (int)($documento['cliente_id'] ?? 0) > 0;
    $bloqueado = flus_facturacion_documento_estado_bloqueado($estado);
    $ventaId = (int)($documento['venta_id'] ?? 0);
    $ventaRequeridaParaFacturar = flus_facturacion_facturas_require_venta($pdo);
    $venta = null;
    if ($ventaId > 0 && flus_table_exists($pdo, 'ventas')) {
        $stVenta = $pdo->prepare('SELECT * FROM ventas WHERE id = ? LIMIT 1');
        $stVenta->execute([$ventaId]);
        $venta = $stVenta->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $ventaVinculadaValida = $ventaId > 0 && is_array($venta);
    $ventaVinculoRoto = $ventaId > 0 && !is_array($venta);

    $factura = flus_facturacion_documento_factura_vinculada($pdo, $documentoId);
    $remito = $tipo === 'PRESUPUESTO'
        ? flus_facturacion_documento_buscar_hijo_por_tipo($pdo, $documentoId, 'REMITO')
        : null;

    $puedeGenerarRemito = $tipo === 'PRESUPUESTO' && $tieneCliente && !$bloqueado && !is_array($remito) && !is_array($factura);
    $motivoGenerarRemito = $puedeGenerarRemito ? '' : match (true) {
        $tipo !== 'PRESUPUESTO' => 'Solo los presupuestos pueden generar remito.',
        !$tieneCliente => 'Agrega un cliente antes de generar remito.',
        $bloqueado => 'El documento esta anulado o cancelado.',
        is_array($remito) => 'Este presupuesto ya tiene un remito generado.',
        is_array($factura) => 'El documento ya tiene una factura asociada.',
        default => 'No corresponde generar remito para este documento.',
    };

    $puedeGenerarVenta = $tieneCliente && !$bloqueado && !is_array($factura) && $ventaId <= 0;
    $motivoGenerarVenta = $puedeGenerarVenta ? '' : match (true) {
        !$tieneCliente => 'Agrega un cliente antes de generar una venta.',
        $bloqueado => 'El documento esta anulado o cancelado.',
        is_array($factura) => 'El documento ya tiene una factura asociada.',
        $ventaId > 0 => 'El documento ya tiene una venta vinculada.',
        default => 'No corresponde generar una venta para este documento.',
    };

    $puedeVincularVenta = $tieneCliente && !$bloqueado && !is_array($factura) && $ventaId <= 0;
    $motivoVincularVenta = $puedeVincularVenta ? '' : match (true) {
        !$tieneCliente => 'Agrega un cliente antes de vincular una venta.',
        $bloqueado => 'El documento esta anulado o cancelado.',
        is_array($factura) => 'El documento ya tiene una factura asociada.',
        $ventaId > 0 => 'El documento ya tiene una venta vinculada.',
        default => 'No corresponde vincular una venta a este documento.',
    };

    $puedeEmitirFactura = $tieneCliente
        && !$bloqueado
        && !is_array($factura)
        && !$ventaVinculoRoto
        && (!$ventaRequeridaParaFacturar || $ventaVinculadaValida);
    $motivoEmitirFactura = $puedeEmitirFactura ? '' : match (true) {
        !$tieneCliente => 'Vincula un cliente antes de emitir la factura.',
        $bloqueado => 'El documento esta anulado o cancelado.',
        is_array($factura) => 'El documento ya tiene una factura asociada.',
        $ventaVinculoRoto => 'La venta vinculada ya no existe. Vincula una venta valida antes de facturar.',
        $ventaRequeridaParaFacturar && !$ventaVinculadaValida => 'Genera o vincula una venta antes de emitir la factura desde este documento.',
        default => 'No corresponde emitir factura para este documento.',
    };

    $siguienteAccion = 'Sin accion disponible';
    $impactoOperativo = 'El documento por si solo no impacta stock ni caja.';
    if (is_array($factura)) {
        $siguienteAccion = 'Ver factura';
        $impactoOperativo = 'La operacion fiscal ya fue registrada y este documento queda como trazabilidad comercial.';
    } elseif (is_array($venta)) {
        $siguienteAccion = 'Ver venta';
        $impactoOperativo = 'La venta vinculada es la que impacta stock, caja y operatoria real.';
    } elseif (!$tieneCliente) {
        $siguienteAccion = 'Completar cliente';
        $impactoOperativo = 'Sin cliente, el documento queda solo como borrador documental.';
    } elseif ($tipo === 'PRESUPUESTO' && is_array($remito)) {
        $siguienteAccion = 'Emitir factura o abrir remito';
        $impactoOperativo = 'El remito ya existe; el siguiente cierre operativo suele ser facturar o continuar desde la venta.';
    } elseif ($tipo === 'PRESUPUESTO') {
        $siguienteAccion = 'Generar remito o venta';
        $impactoOperativo = 'El presupuesto ordena la trazabilidad comercial; la operacion real empieza al generar remito, venta o factura.';
    } elseif ($tipo === 'REMITO') {
        if ($ventaRequeridaParaFacturar && !$ventaVinculadaValida) {
            $siguienteAccion = 'Generar o vincular venta';
            $impactoOperativo = 'En esta instalacion la factura requiere una venta valida vinculada. Primero genera o vincula la venta y despues emite la factura.';
        } else {
            $siguienteAccion = 'Emitir factura o vincular venta';
            $impactoOperativo = 'El remito mantiene trazabilidad documental; la venta o la factura son las que cierran la operacion.';
        }
    }

    return [
        'tiene_cliente' => $tieneCliente,
        'puede_generar_remito' => $puedeGenerarRemito,
        'motivo_generar_remito' => $motivoGenerarRemito,
        'puede_generar_venta' => $puedeGenerarVenta,
        'motivo_generar_venta' => $motivoGenerarVenta,
        'puede_vincular_venta' => $puedeVincularVenta,
        'motivo_vincular_venta' => $motivoVincularVenta,
        'puede_emitir_factura' => $puedeEmitirFactura,
        'motivo_emitir_factura' => $motivoEmitirFactura,
        'siguiente_accion_label' => $siguienteAccion,
        'impacto_operativo' => $impactoOperativo,
        'remito' => $remito,
        'venta' => $venta,
        'factura' => $factura,
    ];
}

function flus_facturacion_documento_tipo_normalizar(string $tipoDocumento): string
{
    $tipo = strtoupper(trim($tipoDocumento));
    $permitidos = ['FACTURA_MANUAL', 'RECIBO', 'PRESUPUESTO', 'REMITO'];
    return in_array($tipo, $permitidos, true) ? $tipo : 'FACTURA_MANUAL';
}

function flus_facturacion_documento_estado_bloqueado(string $estado): bool
{
    return in_array(strtoupper(trim($estado)), ['ANULADO', 'CANCELADO'], true);
}

function flus_facturacion_documento_estado_inicial(string $tipoDocumento): string
{
    return match (flus_facturacion_documento_tipo_normalizar($tipoDocumento)) {
        'REMITO', 'RECIBO' => 'EMITIDO',
        'PRESUPUESTO' => 'PENDIENTE',
        default => 'PENDIENTE',
    };
}

function flus_facturacion_documento_crear(PDO $pdo, string $tipoDocumento, int $clienteId, array $items, array $meta = [], array $opciones = []): int
{
    if (!flus_facturacion_documentos_table_ready($pdo)) {
        throw new RuntimeException(
            'Faltan las tablas documentales. Aplica primero la migracion migrations/017_facturacion_documentos_manual.sql.'
        );
    }

    $tipo = flus_facturacion_documento_tipo_normalizar($tipoDocumento);
    $requestUid = trim((string)($opciones['request_uid'] ?? ''));
    if ($requestUid !== '') {
        $existing = flus_facturacion_documento_buscar_por_request_uid($pdo, $requestUid);
        if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
            return (int)$existing['id'];
        }
    }

    $ownsTx = !$pdo->inTransaction();
    if ($ownsTx) {
        $pdo->beginTransaction();
    }

    try {
        $timestamp = date('Y-m-d H:i:s');
        $total = 0.0;
        foreach ($items as $item) {
            $total += (float)($item['subtotal'] ?? 0);
        }
        $total = round($total, 2);

        $payload = [
            'request_uid' => $requestUid !== '' ? $requestUid : null,
            'tipo_documento' => $tipo,
            'origen' => trim((string)($opciones['origen'] ?? 'MANUAL')) ?: 'MANUAL',
            'estado' => trim((string)($opciones['estado'] ?? flus_facturacion_documento_estado_inicial($tipo))) ?: flus_facturacion_documento_estado_inicial($tipo),
            'cliente_id' => $clienteId > 0 ? $clienteId : null,
            'venta_id' => isset($opciones['venta_id']) && (int)$opciones['venta_id'] > 0 ? (int)$opciones['venta_id'] : null,
            'nota' => trim((string)($meta['nota'] ?? '')) ?: null,
            'medio_pago' => trim((string)($meta['medio_pago'] ?? $tipo)) ?: $tipo,
            'total' => $total,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        if (flus_column_exists($pdo, 'documentos_comerciales', 'documento_origen_id')) {
            $payload['documento_origen_id'] = isset($opciones['documento_origen_id']) && (int)$opciones['documento_origen_id'] > 0
                ? (int)$opciones['documento_origen_id']
                : null;
        }

        $documentoId = flus_facturacion_insert_dynamic($pdo, 'documentos_comerciales', $payload);

        foreach ($items as $item) {
            flus_facturacion_insert_dynamic($pdo, 'documento_items', [
                'documento_id' => $documentoId,
                'codigo' => $item['codigo'] ?? null,
                'descripcion' => $item['descripcion'],
                'cantidad' => $item['cantidad'],
                'precio_unitario' => $item['precio_unitario'],
                'subtotal' => $item['subtotal'],
                'iva_porcentaje' => $item['iva_porcentaje'],
                'created_at' => $timestamp,
            ]);
        }

        if ($ownsTx) {
            $pdo->commit();
        }

        return $documentoId;
    } catch (Throwable $e) {
        if ($ownsTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($requestUid !== '') {
            $existing = flus_facturacion_documento_buscar_por_request_uid($pdo, $requestUid);
            if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
                return (int)$existing['id'];
            }
        }

        throw $e;
    }
}

function flus_facturacion_documento_buscar_hijo_por_tipo(PDO $pdo, int $documentoOrigenId, string $tipoDocumento): ?array
{
    if ($documentoOrigenId <= 0 || !flus_facturacion_documentos_table_ready($pdo) || !flus_column_exists($pdo, 'documentos_comerciales', 'documento_origen_id')) {
        return null;
    }

    $tipo = flus_facturacion_documento_tipo_normalizar($tipoDocumento);
    $st = $pdo->prepare('SELECT * FROM documentos_comerciales WHERE documento_origen_id = ? AND tipo_documento = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$documentoOrigenId, $tipo]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    return is_array($row) ? $row : null;
}

function flus_facturacion_documento_hijos(PDO $pdo, int $documentoOrigenId): array
{
    if ($documentoOrigenId <= 0 || !flus_facturacion_documentos_table_ready($pdo) || !flus_column_exists($pdo, 'documentos_comerciales', 'documento_origen_id')) {
        return [];
    }

    $st = $pdo->prepare('SELECT * FROM documentos_comerciales WHERE documento_origen_id = ? ORDER BY id DESC');
    $st->execute([$documentoOrigenId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function flus_facturacion_documento_factura_vinculada(PDO $pdo, int $documentoId): ?array
{
    if ($documentoId <= 0 || !flus_table_exists($pdo, 'facturas') || !flus_column_exists($pdo, 'facturas', 'documento_id')) {
        return null;
    }

    $st = $pdo->prepare('SELECT * FROM facturas WHERE documento_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$documentoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    return is_array($row) ? $row : null;
}

function flus_facturacion_documento_relaciones(PDO $pdo, int $documentoId): array
{
    $documento = flus_facturacion_documento_buscar($pdo, $documentoId);
    if (!is_array($documento)) {
        return [
            'documento' => null,
            'origen' => null,
            'hijos' => [],
            'factura' => null,
        ];
    }

    $origenId = (int)($documento['documento_origen_id'] ?? 0);

    return [
        'documento' => $documento,
        'origen' => $origenId > 0 ? flus_facturacion_documento_buscar($pdo, $origenId) : null,
        'hijos' => flus_facturacion_documento_hijos($pdo, $documentoId),
        'factura' => flus_facturacion_documento_factura_vinculada($pdo, $documentoId),
    ];
}

function flus_facturacion_documento_clonar(PDO $pdo, int $documentoOrigenId, string $tipoDestino, array $meta = [], array $opciones = []): int
{
    $origen = flus_facturacion_documento_buscar($pdo, $documentoOrigenId);
    if (!is_array($origen)) {
        throw new RuntimeException('El documento origen no existe.');
    }
    if (flus_facturacion_documento_estado_bloqueado((string)($origen['estado'] ?? ''))) {
        throw new RuntimeException('El documento origen esta anulado o cancelado.');
    }

    $tipo = flus_facturacion_documento_tipo_normalizar($tipoDestino);
    if (!empty($opciones['reusar_existente'])) {
        $existing = flus_facturacion_documento_buscar_hijo_por_tipo($pdo, $documentoOrigenId, $tipo);
        if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
            return (int)$existing['id'];
        }
    }

    $items = flus_facturacion_documento_items_normalizar_payload(flus_facturacion_documento_items_fetch($pdo, $documentoOrigenId));
    if ($items === []) {
        throw new RuntimeException('El documento origen no tiene items para clonar.');
    }

    $clienteId = (int)($origen['cliente_id'] ?? 0);
    $notaBase = trim((string)($meta['nota'] ?? ''));
    if ($notaBase === '') {
        $notaOrigen = trim((string)($origen['nota'] ?? ''));
        $notaBase = ($tipo === 'REMITO' ? 'Remito generado desde documento #' : 'Documento generado desde documento #') . $documentoOrigenId;
        if ($notaOrigen !== '') {
            $notaBase .= ' - ' . $notaOrigen;
        }
    }

    $requestUid = trim((string)($opciones['request_uid'] ?? ''));
    if ($requestUid === '') {
        $requestUid = flus_facturacion_uuid_v4();
    }

    $nuevoId = flus_facturacion_documento_crear($pdo, $tipo, $clienteId, $items, [
        'nota' => $notaBase,
        'medio_pago' => trim((string)($meta['medio_pago'] ?? ($origen['medio_pago'] ?? $tipo))) ?: $tipo,
    ], [
        'request_uid' => $requestUid,
        'origen' => trim((string)($opciones['origen'] ?? ($origen['tipo_documento'] ?? 'DOCUMENTO'))) ?: 'DOCUMENTO',
        'estado' => trim((string)($opciones['estado'] ?? flus_facturacion_documento_estado_inicial($tipo))) ?: flus_facturacion_documento_estado_inicial($tipo),
        'venta_id' => (int)($opciones['venta_id'] ?? ($origen['venta_id'] ?? 0)) > 0 ? (int)($opciones['venta_id'] ?? ($origen['venta_id'] ?? 0)) : null,
        'documento_origen_id' => $documentoOrigenId,
    ]);

    if (strtoupper(trim((string)($origen['tipo_documento'] ?? ''))) === 'PRESUPUESTO' && $tipo === 'REMITO') {
        flus_facturacion_documento_actualizar_estado($pdo, $documentoOrigenId, 'REMITADO');
    }

    return $nuevoId;
}

function flus_facturacion_documento_convertir_a_venta_manual(PDO $pdo, int $documentoId, array $meta = []): int
{
    $documento = flus_facturacion_documento_buscar($pdo, $documentoId);
    if (!is_array($documento)) {
        throw new RuntimeException('El documento comercial no existe.');
    }

    $tipo = flus_facturacion_documento_tipo_normalizar((string)($documento['tipo_documento'] ?? ''));
    if ($tipo !== 'PRESUPUESTO') {
        throw new RuntimeException('Solo los presupuestos se pueden convertir a venta en esta fase.');
    }
    if (flus_facturacion_documento_estado_bloqueado((string)($documento['estado'] ?? ''))) {
        throw new RuntimeException('El presupuesto esta anulado o cancelado.');
    }

    $ventaExistente = (int)($documento['venta_id'] ?? 0);
    if ($ventaExistente > 0) {
        return $ventaExistente;
    }

    $items = flus_facturacion_documento_items_normalizar_payload(flus_facturacion_documento_items_fetch($pdo, $documentoId));
    if ($items === []) {
        throw new RuntimeException('El presupuesto no tiene items para convertir.');
    }

    $clienteId = (int)($documento['cliente_id'] ?? 0);
    $ventaId = flus_facturacion_crear_venta_manual($pdo, $clienteId, $items, [
        'nota' => trim((string)($meta['nota'] ?? ($documento['nota'] ?? 'Presupuesto convertido a venta'))) ?: 'Presupuesto convertido a venta',
        'medio_pago' => trim((string)($meta['medio_pago'] ?? 'PRESUPUESTO')) ?: 'PRESUPUESTO',
    ]);

    flus_facturacion_documento_actualizar_venta($pdo, $documentoId, $ventaId);
    flus_facturacion_documento_actualizar_estado($pdo, $documentoId, 'CONVERTIDO_VENTA');

    return $ventaId;
}

function flus_facturacion_normalize_manual_items(array $items): array
{
    $normalized = [];
    $allowedIva = [0.0, 2.5, 5.0, 10.5, 21.0, 27.0];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $descripcion = trim((string)($item['descripcion'] ?? ''));
        if ($descripcion === '') {
            continue;
        }

        $cantidad = round((float)($item['cantidad'] ?? 0), 3);
        $precio = round((float)($item['precio'] ?? $item['precio_unitario'] ?? 0), 2);
        $codigo = trim((string)($item['codigo'] ?? ''));
        $iva = round((float)($item['iva_porcentaje'] ?? 21), 2);

        if ($cantidad <= 0) {
            throw new RuntimeException('Cada item manual debe tener una cantidad mayor a 0.');
        }
        if ($precio < 0) {
            throw new RuntimeException('Cada item manual debe tener un precio valido.');
        }
        if (!in_array($iva, $allowedIva, true)) {
            throw new RuntimeException('La alicuota IVA del item manual no es valida.');
        }

        $normalized[] = [
            'codigo' => $codigo !== '' ? $codigo : null,
            'descripcion' => $descripcion,
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
            'subtotal' => round($cantidad * $precio, 2),
            'iva_porcentaje' => $iva,
        ];
    }

    if ($normalized === []) {
        throw new RuntimeException('Debes cargar al menos un item para la factura manual.');
    }

    return $normalized;
}

function flus_facturacion_consumidor_final(PDO $pdo): ?array
{
    if (!flus_table_exists($pdo, 'clientes')) {
        return null;
    }

    $nombreExpr = flus_column_exists($pdo, 'clientes', 'nombre') ? 'nombre' : 'NULL';
    $order = flus_column_exists($pdo, 'clientes', 'id') ? ' ORDER BY id DESC' : '';

    if ($nombreExpr === 'NULL') {
        return null;
    }

    $sql = 'SELECT * FROM clientes WHERE UPPER(' . $nombreExpr . ') = ?';
    if (flus_column_exists($pdo, 'clientes', 'activo')) {
        $sql .= ' AND activo = 1';
    }
    $sql .= $order . ' LIMIT 1';

    $st = $pdo->prepare($sql);
    $st->execute(['CONSUMIDOR FINAL']);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function flus_facturacion_asegurar_consumidor_final(PDO $pdo): ?array
{
    $cliente = flus_facturacion_consumidor_final($pdo);
    if ($cliente !== null) {
        return $cliente;
    }

    $data = [
        'nombre' => 'Consumidor Final',
        'cond_iva' => 'CF',
        'activo' => 1,
        'creado_en' => date('Y-m-d H:i:s'),
    ];

    $clienteId = flus_facturacion_insert_dynamic($pdo, 'clientes', $data);
    $st = $pdo->prepare('SELECT * FROM clientes WHERE id = ? LIMIT 1');
    $st->execute([$clienteId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function flus_facturacion_resolver_cliente(PDO $pdo, int $clienteId): array
{
    if ($clienteId > 0) {
        if (!flus_table_exists($pdo, 'clientes')) {
            throw new RuntimeException('La tabla clientes no existe.');
        }

        $sql = 'SELECT * FROM clientes WHERE id = ?';
        if (flus_column_exists($pdo, 'clientes', 'activo')) {
            $sql .= ' AND activo = 1';
        }
        $sql .= ' LIMIT 1';

        $st = $pdo->prepare($sql);
        $st->execute([$clienteId]);
        $cliente = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($cliente === null) {
            throw new RuntimeException('Cliente no encontrado.');
        }

        return [
            'cliente_id' => (int)($cliente['id'] ?? $clienteId),
            'cliente' => $cliente,
            'consumidor_final' => false,
        ];
    }

    $cliente = flus_facturacion_consumidor_final($pdo);
    if ($cliente !== null) {
        return [
            'cliente_id' => (int)($cliente['id'] ?? 0),
            'cliente' => $cliente,
            'consumidor_final' => true,
        ];
    }

    return [
        'cliente_id' => 0,
        'cliente' => null,
        'consumidor_final' => true,
    ];
}

function flus_facturacion_resolver_cliente_padron(PDO $pdo, array $lookup): array
{
    if (!flus_table_exists($pdo, 'clientes')) {
        throw new RuntimeException('La tabla clientes no existe.');
    }

    require_once __DIR__ . '/../public/includes/CuitValidator.php';

    $cuitRaw = trim((string)($lookup['cuit'] ?? ''));
    $cuit = class_exists('CuitValidator') ? CuitValidator::limpiar($cuitRaw) : preg_replace('/\D+/', '', $cuitRaw);
    if ($cuit === '' || strlen($cuit) !== 11) {
        throw new RuntimeException('Debes ingresar un CUIT/CUIL valido para consultar ARCA.');
    }
    if (class_exists('CuitValidator') && !CuitValidator::validar($cuit)) {
        $detalle = CuitValidator::obtenerError($cuitRaw);
        throw new RuntimeException($detalle !== '' ? $detalle : 'El CUIT/CUIL consultado no es valido.');
    }

    $nombre = trim((string)($lookup['nombre'] ?? ''));
    if ($nombre === '') {
        throw new RuntimeException('ARCA no devolvio nombre o razon social para ese CUIT/CUIL.');
    }

    $condIva = strtoupper(trim((string)($lookup['cond_iva'] ?? '')));
    if (!in_array($condIva, ['RI', 'MT', 'EX', 'CF'], true)) {
        $condIva = 'CF';
    }

    $direccion = trim((string)($lookup['direccion'] ?? ''));
    $tipoCliente = strtoupper(trim((string)($lookup['tipo_cliente'] ?? 'MINORISTA')));
    if (!in_array($tipoCliente, ['MINORISTA', 'MAYORISTA', 'CORPORATIVO'], true)) {
        $tipoCliente = 'MINORISTA';
    }

    $cuitFormatted = class_exists('CuitValidator') ? (CuitValidator::formatear($cuit) ?? $cuit) : $cuit;

    $st = $pdo->prepare("SELECT * FROM clientes WHERE REPLACE(REPLACE(COALESCE(cuit, ''), '-', ''), ' ', '') = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$cuit]);
    $cliente = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($cliente !== null) {
        $updates = [];
        if (flus_column_exists($pdo, 'clientes', 'nombre') && trim((string)($cliente['nombre'] ?? '')) === '') {
            $updates['nombre'] = $nombre;
        }
        if (flus_column_exists($pdo, 'clientes', 'cuit')) {
            $cuitActual = preg_replace('/\D+/', '', (string)($cliente['cuit'] ?? ''));
            if ($cuitActual === '' || $cuitActual === $cuit) {
                $updates['cuit'] = $cuitFormatted;
            }
        }
        if (flus_column_exists($pdo, 'clientes', 'cond_iva') && trim((string)($cliente['cond_iva'] ?? '')) === '') {
            $updates['cond_iva'] = $condIva;
        }
        if ($direccion !== '' && flus_column_exists($pdo, 'clientes', 'direccion') && trim((string)($cliente['direccion'] ?? '')) === '') {
            $updates['direccion'] = $direccion;
        }
        if (flus_column_exists($pdo, 'clientes', 'tipo_cliente') && trim((string)($cliente['tipo_cliente'] ?? '')) === '') {
            $updates['tipo_cliente'] = $tipoCliente;
        }

        if ($updates !== []) {
            $sets = [];
            $params = [':id' => (int)$cliente['id']];
            foreach ($updates as $col => $value) {
                $sets[] = "`{$col}` = :{$col}";
                $params[':' . $col] = $value;
            }
            $sql = 'UPDATE clientes SET ' . implode(', ', $sets) . ' WHERE id = :id';
            $up = $pdo->prepare($sql);
            $up->execute($params);

            $st = $pdo->prepare('SELECT * FROM clientes WHERE id = ? LIMIT 1');
            $st->execute([(int)$cliente['id']]);
            $cliente = $st->fetch(PDO::FETCH_ASSOC) ?: $cliente;
        }

        return [
            'cliente_id' => (int)($cliente['id'] ?? 0),
            'cliente' => $cliente,
            'consumidor_final' => false,
        ];
    }

    $clienteId = flus_facturacion_insert_dynamic($pdo, 'clientes', [
        'nombre' => $nombre,
        'cuit' => $cuitFormatted,
        'cond_iva' => $condIva,
        'tipo_cliente' => $tipoCliente,
        'direccion' => $direccion !== '' ? $direccion : null,
        'activo' => 1,
        'creado_en' => date('Y-m-d H:i:s'),
    ]);

    $st = $pdo->prepare('SELECT * FROM clientes WHERE id = ? LIMIT 1');
    $st->execute([$clienteId]);
    $cliente = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    return [
        'cliente_id' => $clienteId,
        'cliente' => $cliente,
        'consumidor_final' => false,
    ];
}

function flus_facturacion_cliente_lookup_post(array $source): ?array
{
    $activo = trim((string)($source['cliente_lookup_activo'] ?? '0')) === '1';
    if (!$activo) {
        return null;
    }

    $cuit = trim((string)($source['cliente_lookup_cuit'] ?? ''));
    $nombre = trim((string)($source['cliente_lookup_nombre'] ?? ''));
    if ($cuit === '' || $nombre === '') {
        return null;
    }

    return [
        'cuit' => $cuit,
        'nombre' => $nombre,
        'cond_iva' => trim((string)($source['cliente_lookup_cond_iva'] ?? '')),
        'direccion' => trim((string)($source['cliente_lookup_direccion'] ?? '')),
        'tipo_cliente' => trim((string)($source['cliente_lookup_tipo_cliente'] ?? 'MINORISTA')),
        'estado' => trim((string)($source['cliente_lookup_estado'] ?? '')),
    ];
}

function flus_facturacion_cliente_lookup_confirmado(array $source): bool
{
    return trim((string)($source['cliente_lookup_confirmado'] ?? '0')) === '1';
}

function flus_facturacion_resolver_cliente_desde_input(PDO $pdo, array $source, array $options = []): array
{
    $mensajeVacio = trim((string)($options['mensaje_vacio'] ?? 'Debes seleccionar un cliente, Consumidor Final o consultar un CUIT/CUIL.'));
    $mensajeInvalido = trim((string)($options['mensaje_invalido'] ?? 'El cliente seleccionado no es valido.'));
    $mensajeConfirmacionLookup = trim((string)($options['mensaje_lookup_confirmacion'] ?? 'Confirma que quieres emitir con los datos consultados en ARCA. Si no los vas a usar, descartalos y sigue con el cliente seleccionado.'));
    $clienteSeleccionadoRaw = trim((string)($source['cliente_id'] ?? ''));

    $result = [
        'cliente_id' => null,
        'cliente_raw' => $clienteSeleccionadoRaw,
        'resolved_cliente' => null,
        'errors' => [],
    ];

    $clienteLookup = flus_facturacion_cliente_lookup_post($source);
    if ($clienteLookup !== null) {
        if (!flus_facturacion_cliente_lookup_confirmado($source)) {
            $result['errors'][] = $mensajeConfirmacionLookup;
            return $result;
        }

        try {
            $resolvedCliente = flus_facturacion_resolver_cliente_padron($pdo, $clienteLookup);
            $result['cliente_id'] = (int)($resolvedCliente['cliente_id'] ?? 0);
            $result['resolved_cliente'] = $resolvedCliente;
        } catch (Throwable $e) {
            $result['errors'][] = $e->getMessage();
        }

        return $result;
    }

    if ($clienteSeleccionadoRaw === '') {
        $result['errors'][] = $mensajeVacio;
        return $result;
    }

    if ($clienteSeleccionadoRaw !== '0' && !ctype_digit($clienteSeleccionadoRaw)) {
        $result['errors'][] = $mensajeInvalido;
        return $result;
    }

    $clienteId = $clienteSeleccionadoRaw === '0' ? 0 : (int)$clienteSeleccionadoRaw;
    try {
        $resolvedCliente = flus_facturacion_resolver_cliente($pdo, $clienteId);
        $result['cliente_id'] = (int)($resolvedCliente['cliente_id'] ?? $clienteId);
        $result['resolved_cliente'] = $resolvedCliente;
    } catch (Throwable $e) {
        $result['errors'][] = $clienteId > 0 ? $mensajeInvalido : $e->getMessage();
    }

    return $result;
}

function flus_facturacion_manual_retry_fingerprint(int $clienteId, array $items, array $meta = [], array $opciones = []): string
{
    $rows = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $rows[] = [
            'codigo' => trim((string)($item['codigo'] ?? '')),
            'descripcion' => trim((string)($item['descripcion'] ?? '')),
            'cantidad' => round((float)($item['cantidad'] ?? 0), 3),
            'precio' => round((float)($item['precio'] ?? $item['precio_unitario'] ?? 0), 2),
            'iva_porcentaje' => round((float)($item['iva_porcentaje'] ?? 21), 2),
            'subtotal' => round((float)($item['subtotal'] ?? 0), 2),
        ];
    }

    $payload = [
        'cliente_id' => max(0, $clienteId),
        'items' => $rows,
    ];

    return sha1((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function flus_facturacion_manual_retry_state_bucket(): array
{
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        $_SESSION = [];
    }

    $bucket = $_SESSION['flus_facturacion_manual_retry'] ?? [];
    return is_array($bucket) ? $bucket : [];
}

function flus_facturacion_manual_retry_state_guardar(
    string $requestUid,
    int $ventaId,
    int $clienteId,
    array $items,
    string $estadoFiscal = 'PENDIENTE_ENVIO',
    ?int $facturaId = null
): void {
    $requestUid = trim($requestUid);
    if ($requestUid === '') {
        return;
    }

    if (!isset($_SESSION) || !is_array($_SESSION)) {
        $_SESSION = [];
    }

    $fingerprint = flus_facturacion_manual_retry_fingerprint($clienteId, $items);
    $_SESSION['flus_facturacion_manual_retry'][$fingerprint] = [
        'request_uid' => $requestUid,
        'venta_id' => max(0, $ventaId),
        'factura_id' => $facturaId !== null ? max(0, $facturaId) : 0,
        'estado_fiscal' => trim($estadoFiscal) !== '' ? trim($estadoFiscal) : 'PENDIENTE_ENVIO',
        'updated_at' => date('Y-m-d H:i:s'),
    ];
}

function flus_facturacion_manual_retry_state_es_reutilizable(string $estadoFiscal): bool
{
    $estado = strtoupper(trim($estadoFiscal));
    if ($estado === 'APROBADA') {
        $estado = 'AUTORIZADA';
    }

    return in_array($estado, ['PENDIENTE_ENVIO', 'ERROR_TRANSITORIO', 'ERROR_POST_ARCA', 'AUTORIZADA', 'RECUPERADA'], true);
}

function flus_facturacion_manual_retry_state_buscar(int $clienteId, array $items): ?array
{
    $fingerprint = flus_facturacion_manual_retry_fingerprint($clienteId, $items);
    $bucket = flus_facturacion_manual_retry_state_bucket();
    $row = $bucket[$fingerprint] ?? null;
    if (!is_array($row)) {
        return null;
    }

    $requestUid = trim((string)($row['request_uid'] ?? ''));
    if ($requestUid === '') {
        return null;
    }

    $estadoFiscal = (string)($row['estado_fiscal'] ?? 'PENDIENTE_ENVIO');
    if (!flus_facturacion_manual_retry_state_es_reutilizable($estadoFiscal)) {
        return null;
    }

    $row['request_uid'] = $requestUid;
    $row['venta_id'] = (int)($row['venta_id'] ?? 0);
    $row['factura_id'] = (int)($row['factura_id'] ?? 0);
    $row['estado_fiscal'] = $estadoFiscal;
    return $row;
}

function flus_facturacion_manual_resolver_base_existente(PDO $pdo, $repo, string $requestUid, int $clienteId, array $items): array
{
    $requestUid = trim($requestUid);
    $base = [
        'factura' => null,
        'documento' => null,
        'factura_id' => 0,
        'documento_id' => 0,
        'venta_id' => 0,
    ];

    if ($requestUid === '') {
        return $base;
    }

    if (is_object($repo) && method_exists($repo, 'findFacturaByRequestUid')) {
        $factura = $repo->findFacturaByRequestUid($requestUid);
        if (is_array($factura)) {
            $base['factura'] = $factura;
            $base['factura_id'] = (int)($factura['id'] ?? 0);
            $base['documento_id'] = (int)($factura['documento_id'] ?? 0);
            $base['venta_id'] = (int)($factura['venta_id'] ?? 0);
        }
    }

    $documento = null;
    if ($base['documento_id'] > 0) {
        $documento = flus_facturacion_documento_buscar($pdo, $base['documento_id']);
    }
    if (!is_array($documento)) {
        $documento = flus_facturacion_documento_buscar_por_request_uid($pdo, $requestUid);
    }
    if (is_array($documento)) {
        $base['documento'] = $documento;
        $base['documento_id'] = (int)($documento['id'] ?? 0);
        if ($base['venta_id'] <= 0) {
            $base['venta_id'] = (int)($documento['venta_id'] ?? 0);
        }
    }

    $retryState = flus_facturacion_manual_retry_state_buscar($clienteId, $items);
    if (is_array($retryState) && trim((string)($retryState['request_uid'] ?? '')) === $requestUid) {
        if ($base['venta_id'] <= 0) {
            $base['venta_id'] = (int)($retryState['venta_id'] ?? 0);
        }
        if ($base['factura_id'] <= 0) {
            $base['factura_id'] = (int)($retryState['factura_id'] ?? 0);
        }
    }

    if ($base['factura_id'] > 0 && !is_array($base['factura']) && is_object($repo) && method_exists($repo, 'findFacturaById')) {
        $factura = $repo->findFacturaById($base['factura_id']);
        if (is_array($factura)) {
            $base['factura'] = $factura;
            $base['documento_id'] = max($base['documento_id'], (int)($factura['documento_id'] ?? 0));
            $base['venta_id'] = max($base['venta_id'], (int)($factura['venta_id'] ?? 0));
        }
    }

    if ($base['documento_id'] > 0 && !is_array($base['documento'])) {
        $base['documento'] = flus_facturacion_documento_buscar($pdo, $base['documento_id']);
    }

    return $base;
}

function flus_facturacion_factura_detalle_items_fetch(PDO $pdo, array $factura): array
{
    $facturaId = (int)($factura['id'] ?? 0);
    $documentoId = (int)($factura['documento_id'] ?? 0);
    $ventaId = (int)($factura['venta_id'] ?? 0);
    $itemRows = [];

    if ($facturaId > 0 && flus_table_exists($pdo, 'factura_items')) {
        $usaProductos = flus_table_exists($pdo, 'productos');
        $joinProductos = $usaProductos ? 'LEFT JOIN productos p ON p.id = fi.producto_id' : '';
        $codigoExpr = $usaProductos && flus_column_exists($pdo, 'productos', 'codigo')
            ? 'COALESCE(fi.codigo_snapshot, p.codigo)'
            : 'fi.codigo_snapshot';
        $descripcionExpr = $usaProductos && flus_column_exists($pdo, 'productos', 'nombre')
            ? 'COALESCE(fi.descripcion_snapshot, p.nombre)'
            : 'fi.descripcion_snapshot';
        $ivaExpr = 'COALESCE(fi.iva_porcentaje, 21)';

        $sqlFacturaItems = "
          SELECT
            fi.*,
            {$codigoExpr} AS codigo,
            {$descripcionExpr} AS descripcion,
            fi.precio_unitario_bruto AS precio_unitario,
            fi.subtotal_total AS subtotal,
            {$ivaExpr} AS iva_porcentaje
          FROM factura_items fi
          {$joinProductos}
          WHERE fi.factura_id = ?
          ORDER BY COALESCE(fi.linea_orden, fi.id) ASC, fi.id ASC
        ";
        $stFacturaItems = $pdo->prepare($sqlFacturaItems);
        $stFacturaItems->execute([$facturaId]);
        $itemRows = $stFacturaItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($itemRows === [] && $documentoId > 0) {
        $itemRows = flus_facturacion_documento_items_fetch($pdo, $documentoId);
    }

    if ($itemRows === [] && $ventaId > 0 && flus_table_exists($pdo, 'venta_items') && flus_table_exists($pdo, 'productos')) {
        $sqlItems = '
          SELECT vi.*, p.codigo, p.nombre, p.iva AS producto_iva
          FROM venta_items vi
          JOIN productos p ON p.id = vi.producto_id
          WHERE vi.venta_id = ?
          ORDER BY vi.id ASC
        ';
        $stmtItems = $pdo->prepare($sqlItems);
        $stmtItems->execute([$ventaId]);
        $itemRows = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    if ($itemRows === [] && $ventaId > 0) {
        $itemRows = flus_facturacion_manual_items_fetch($pdo, $ventaId);
    }

    return $itemRows;
}

function flus_facturacion_crear_venta_manual(PDO $pdo, int $clienteId, array $items, array $meta = []): int
{
    if (!flus_table_exists($pdo, 'ventas')) {
        throw new RuntimeException('La tabla ventas no existe.');
    }

    flus_facturacion_ensure_manual_items_table($pdo);

    $total = 0.0;
    foreach ($items as $item) {
        $total += (float)($item['subtotal'] ?? 0);
    }
    $total = round($total, 2);
    $timestamp = date('Y-m-d H:i:s');

    $ventaId = flus_facturacion_insert_dynamic($pdo, 'ventas', [
        'fecha' => $timestamp,
        'total' => $total,
        'descuento_total' => 0,
        'recargo_total' => 0,
        'medio_pago' => trim((string)($meta['medio_pago'] ?? 'FACTURA_MANUAL')) ?: 'FACTURA_MANUAL',
        'monto_pagado' => $total,
        'vuelto' => 0,
        'nota' => trim((string)($meta['nota'] ?? 'Factura manual sin caja')) ?: 'Factura manual sin caja',
        'cliente_id' => $clienteId > 0 ? $clienteId : null,
        'estado' => 'EMITIDA',
        'facturada' => 0,
        'uuid' => flus_facturacion_uuid_v4(),
    ]);

    foreach ($items as $item) {
        flus_facturacion_insert_dynamic($pdo, 'factura_manual_items', [
            'venta_id' => $ventaId,
            'codigo' => $item['codigo'] ?? null,
            'descripcion' => $item['descripcion'],
            'cantidad' => $item['cantidad'],
            'precio_unitario' => $item['precio_unitario'],
            'subtotal' => $item['subtotal'],
            'iva_porcentaje' => $item['iva_porcentaje'],
            'created_at' => $timestamp,
        ]);
    }

    return $ventaId;
}

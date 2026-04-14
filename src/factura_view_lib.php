<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/facturacion_lib.php';
require_once __DIR__ . '/facturacion_manual_lib.php';
require_once __DIR__ . '/cobranzas_lib.php';

function flus_factura_view_fetch_factura(PDO $pdo, int $facturaId): ?array
{
    if ($facturaId <= 0 || !flus_table_exists($pdo, 'facturas')) {
        return null;
    }

    $sql = '
      SELECT
        f.*,
        v.fecha AS venta_fecha,
        v.total AS venta_total,
        v.medio_pago AS venta_medio_pago,
        v.nota AS venta_nota,
        c.nombre AS cliente_nombre,
        c.cuit AS cliente_cuit,
        c.cond_iva AS cliente_cond_iva,
        c.direccion AS cliente_direccion
      FROM facturas f
      LEFT JOIN ventas v ON v.id = f.venta_id
      LEFT JOIN clientes c ON c.id = f.cliente_id
      WHERE f.id = ?
      LIMIT 1
    ';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$facturaId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    return is_array($row) ? $row : null;
}

function flus_factura_view_fetch_fiscal_evento_arca(PDO $pdo, string $fiscalRequestUid): ?array
{
    $fiscalRequestUid = trim($fiscalRequestUid);
    if ($fiscalRequestUid === '' || !flus_table_exists($pdo, 'factura_eventos_arca')) {
        return null;
    }

    try {
        $stEventoArca = $pdo->prepare('SELECT * FROM factura_eventos_arca WHERE request_uid = ? LIMIT 1');
        $stEventoArca->execute([$fiscalRequestUid]);
        $row = $stEventoArca->fetch(PDO::FETCH_ASSOC) ?: null;
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function flus_factura_view_fetch_venta_fiscal(PDO $pdo, int $ventaId): ?array
{
    if ($ventaId <= 0 || !flus_table_exists($pdo, 'venta_fiscal')) {
        return null;
    }

    try {
        $stFiscal = $pdo->prepare('SELECT * FROM venta_fiscal WHERE venta_id = ? LIMIT 1');
        $stFiscal->execute([$ventaId]);
        $row = $stFiscal->fetch(PDO::FETCH_ASSOC) ?: null;
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function flus_factura_view_fetch_documentos(PDO $pdo, array $factura): array
{
    $documentoComercial = null;
    $documentoComercialOrigen = null;
    $documentoId = (int)($factura['documento_id'] ?? 0);

    if ($documentoId > 0) {
        $documentoComercial = flus_facturacion_documento_buscar($pdo, $documentoId);
        if (is_array($documentoComercial) && (int)($documentoComercial['documento_origen_id'] ?? 0) > 0) {
            $documentoComercialOrigen = flus_facturacion_documento_buscar($pdo, (int)$documentoComercial['documento_origen_id']);
        }
    }

    return [
        'documento_comercial' => $documentoComercial,
        'documento_comercial_origen' => $documentoComercialOrigen,
    ];
}

function flus_factura_view_fetch_config_empresa(PDO $pdo): ?array
{
    try {
        if (!flus_table_exists($pdo, 'config_facturacion')) {
            return null;
        }

        $orderCfg = flus_column_exists($pdo, 'config_facturacion', 'id') ? ' ORDER BY id DESC' : '';

        if (flus_column_exists($pdo, 'config_facturacion', 'activo')) {
            $stCfg = $pdo->query('SELECT * FROM config_facturacion WHERE activo = 1' . $orderCfg . ' LIMIT 1');
            $row = $stCfg ? ($stCfg->fetch(PDO::FETCH_ASSOC) ?: null) : null;
            if (is_array($row)) {
                return $row;
            }
        }

        $stCfg = $pdo->query('SELECT * FROM config_facturacion' . $orderCfg . ' LIMIT 1');
        $row = $stCfg ? ($stCfg->fetch(PDO::FETCH_ASSOC) ?: null) : null;
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function flus_factura_view_load(PDO $pdo, int $facturaId): ?array
{
    $factura = flus_factura_view_fetch_factura($pdo, $facturaId);
    if (!is_array($factura)) {
        return null;
    }

    $estadoFiscal = flus_facturacion_estado_fiscal_normalizar((string)($factura['estado_fiscal'] ?? 'NO_APLICA'));
    $fiscalRequestUid = trim((string)($factura['fiscal_request_uid'] ?? ''));
    $fiscalEventoArca = flus_factura_view_fetch_fiscal_evento_arca($pdo, $fiscalRequestUid);
    $fiscalData = flus_factura_view_fetch_venta_fiscal($pdo, (int)($factura['venta_id'] ?? 0));
    $itemRows = flus_facturacion_factura_detalle_items_fetch($pdo, $factura);
    $items = factura_normalizar_items($itemRows, $factura);
    $documentos = flus_factura_view_fetch_documentos($pdo, $factura);

    return [
        'factura' => $factura,
        'estado_fiscal' => $estadoFiscal,
        'estado_fiscal_label' => flus_facturacion_estado_fiscal_label($estadoFiscal),
        'fiscal_request_uid' => $fiscalRequestUid,
        'fiscal_intentos' => max(0, (int)($factura['fiscal_intentos'] ?? 0)),
        'fiscal_error_code' => trim((string)($factura['fiscal_error_code'] ?? '')),
        'fiscal_error_message' => trim((string)($factura['fiscal_error_message'] ?? '')),
        'fiscal_requested_at' => trim((string)($factura['fiscal_requested_at'] ?? '')),
        'fiscal_approved_at' => trim((string)($factura['fiscal_approved_at'] ?? '')),
        'envio_ultimo_canal' => trim((string)($factura['envio_ultimo_canal'] ?? '')),
        'envio_ultimo_estado' => trim((string)($factura['envio_ultimo_estado'] ?? '')),
        'envio_ultimo_destino' => trim((string)($factura['envio_ultimo_destino'] ?? '')),
        'envio_ultimo_at' => trim((string)($factura['envio_ultimo_at'] ?? '')),
        'envio_ultimo_error' => trim((string)($factura['envio_ultimo_error'] ?? '')),
        'fiscal_detalle_operativo' => flus_facturacion_estado_fiscal_detalle_operativo($estadoFiscal),
        'fiscal_regularizable' => flus_facturacion_estado_fiscal_regularizable($estadoFiscal),
        'fiscal_evento_arca' => $fiscalEventoArca,
        'arca_resultado' => trim((string)($fiscalEventoArca['resultado'] ?? '')),
        'arca_operacion' => trim((string)($fiscalEventoArca['operacion'] ?? '')),
        'arca_modo' => trim((string)($fiscalEventoArca['modo'] ?? '')),
        'arca_at' => trim((string)($fiscalEventoArca['finished_at'] ?? $fiscalEventoArca['created_at'] ?? '')),
        'arca_error' => trim((string)($fiscalEventoArca['error_message'] ?? '')),
        'fiscal_data' => $fiscalData,
        'item_rows' => $itemRows,
        'items' => $items,
        'resumen_fiscal' => factura_resumen_fiscal($items, $factura),
        'cobranza_resumen' => flus_cobranzas_resumen_para_factura($pdo, $factura),
        'recibos_asociados' => flus_cobranzas_fetch_receipts_by_factura($pdo, $facturaId, (int)($factura['documento_id'] ?? 0)),
        'documento_comercial' => $documentos['documento_comercial'],
        'documento_comercial_origen' => $documentos['documento_comercial_origen'],
        'config_empresa' => flus_factura_view_fetch_config_empresa($pdo),
    ];
}

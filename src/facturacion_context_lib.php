<?php
declare(strict_types=1);

function flus_facturacion_request_uid_from_context(array $context, array $opciones = []): string
{
    $provided = trim((string)($opciones['request_uid'] ?? ''));
    if ($provided !== '') {
        return $provided;
    }

    $seed = implode('|', [
        !empty($opciones['origen_manual']) ? 'FACTURA_MANUAL' : 'FACTURA_VENTA',
        'venta:' . (int)($context['venta']['id'] ?? 0),
        'cliente:' . (int)($context['cliente_id_fiscal'] ?? 0),
        'tipo_cbte:' . (int)($context['tipo_cbte'] ?? 0),
        'pto:' . (int)($context['punto_venta'] ?? 0),
        'modo:' . (string)($context['modo_operacion'] ?? 'demo'),
        'concepto:' . (int)($context['concepto'] ?? 1),
        'total:' . number_format((float)($context['importes']['total'] ?? 0), 2, '.', ''),
    ]);

    return flus_facturacion_uuid_from_seed($seed);
}

function flus_facturacion_request_payload(array $context): array
{
    return [
        'venta_id' => (int)($context['venta']['id'] ?? 0),
        'documento_id' => (int)($context['documento']['id'] ?? 0) > 0 ? (int)$context['documento']['id'] : null,
        'cliente_id' => (int)($context['cliente_id_fiscal'] ?? 0),
        'tipo_cbte' => (int)($context['tipo_cbte'] ?? 0),
        'tipo' => (string)($context['tipo_str'] ?? ''),
        'punto_venta' => (int)($context['punto_venta'] ?? 0),
        'numero' => (int)($context['numero'] ?? 0),
        'modo' => (string)($context['modo_operacion'] ?? 'demo'),
        'concepto' => (int)($context['concepto'] ?? 1),
        'importe_total' => round((float)($context['importes']['total'] ?? 0), 2),
        'importe_neto' => round((float)($context['importes']['neto'] ?? 0), 2),
        'importe_iva' => round((float)($context['importes']['iva'] ?? 0), 2),
        'importe_exento' => round((float)($context['importes']['exento'] ?? 0), 2),
        'importe_no_gravado' => round((float)($context['importes']['no_gravado'] ?? 0), 2),
        'tipo_doc' => (int)($context['doc_data']['tipo'] ?? 0),
        'nro_doc' => (string)($context['doc_data']['numero'] ?? ''),
        'condicion_iva_receptor_id' => (int)($context['comprobante']['condicion_iva_receptor_id'] ?? 0),
        'origen_manual' => !empty($context['origen_manual']),
    ];
}

function flus_facturacion_request_payload_normalize(array $payload): array
{
    $docNumero = preg_replace('/\D+/', '', (string)($payload['nro_doc'] ?? $payload['doc_numero'] ?? ''));

    return [
        'venta_id' => (int)($payload['venta_id'] ?? 0),
        'documento_id' => (int)($payload['documento_id'] ?? 0),
        'cliente_id' => (int)($payload['cliente_id'] ?? 0),
        'tipo_cbte' => (int)($payload['tipo_cbte'] ?? 0),
        'tipo' => strtoupper(trim((string)($payload['tipo'] ?? ''))),
        'punto_venta' => (int)($payload['punto_venta'] ?? 0),
        'numero' => (int)($payload['numero'] ?? 0),
        'modo' => strtolower(trim((string)($payload['modo'] ?? ''))),
        'concepto' => (int)($payload['concepto'] ?? 1),
        'importe_total' => round((float)($payload['importe_total'] ?? $payload['total'] ?? 0), 2),
        'importe_neto' => round((float)($payload['importe_neto'] ?? 0), 2),
        'importe_iva' => round((float)($payload['importe_iva'] ?? 0), 2),
        'importe_exento' => round((float)($payload['importe_exento'] ?? 0), 2),
        'importe_no_gravado' => round((float)($payload['importe_no_gravado'] ?? 0), 2),
        'tipo_doc' => (int)($payload['tipo_doc'] ?? $payload['doc_tipo'] ?? 0),
        'nro_doc' => (string)$docNumero,
        'condicion_iva_receptor_id' => (int)($payload['condicion_iva_receptor_id'] ?? 0),
        'origen_manual' => !empty($payload['origen_manual']),
    ];
}

function flus_facturacion_snapshot_payload_desde_factura(array $factura, array $evento = []): array
{
    $base = [
        'venta_id' => (int)($factura['venta_id'] ?? 0),
        'documento_id' => (int)($factura['documento_id'] ?? 0),
        'cliente_id' => (int)($factura['cliente_id'] ?? 0),
        'tipo_cbte' => (int)($factura['tipo_cbte'] ?? 0),
        'tipo' => (string)($factura['tipo'] ?? ''),
        'punto_venta' => (int)($factura['punto_venta'] ?? 0),
        'numero' => (int)($factura['numero'] ?? 0),
        'importe_total' => round((float)($factura['total'] ?? 0), 2),
        'importe_neto' => round((float)($factura['importe_neto'] ?? 0), 2),
        'importe_iva' => round((float)($factura['importe_iva'] ?? 0), 2),
        'importe_exento' => round((float)($factura['importe_exento'] ?? 0), 2),
        'importe_no_gravado' => round((float)($factura['importe_no_gravado'] ?? 0), 2),
        'tipo_doc' => (int)($factura['doc_tipo'] ?? 0),
        'nro_doc' => (string)($factura['doc_numero'] ?? ''),
        'condicion_iva_receptor_id' => (int)($factura['condicion_iva_receptor_id'] ?? 0),
        'modo' => (string)($factura['modo'] ?? ''),
        'concepto' => 1,
        'origen_manual' => false,
    ];

    $requestJson = flus_facturacion_json_decode_assoc((string)($evento['request_json'] ?? ''));
    if ($requestJson !== []) {
        $base = array_replace($base, $requestJson);
    }

    return flus_facturacion_request_payload_normalize($base);
}

function flus_facturacion_opciones_regularizacion(array $opciones, array $snapshotPayload, array $factura): array
{
    foreach ([
        'numero_preferido' => (int)($snapshotPayload['numero'] ?? $factura['numero'] ?? 0),
        'punto_venta_preferido' => (int)($snapshotPayload['punto_venta'] ?? $factura['punto_venta'] ?? 0),
        'tipo_cbte' => (int)($snapshotPayload['tipo_cbte'] ?? $factura['tipo_cbte'] ?? 0),
        'modo' => trim((string)($snapshotPayload['modo'] ?? $factura['modo'] ?? '')),
        'concepto' => (int)($snapshotPayload['concepto'] ?? 0),
    ] as $key => $value) {
        if (($key === 'modo' && $value !== '') || ($key !== 'modo' && (int)$value > 0)) {
            $opciones[$key] = $value;
        }
    }

    return $opciones;
}

function flus_facturacion_request_payload_diff(array $expected, array $actual): array
{
    $expected = flus_facturacion_request_payload_normalize($expected);
    $actual = flus_facturacion_request_payload_normalize($actual);
    $labels = [
        'venta_id' => 'venta',
        'documento_id' => 'documento',
        'cliente_id' => 'cliente',
        'tipo_cbte' => 'tipo de comprobante',
        'tipo' => 'tipo',
        'punto_venta' => 'punto de venta',
        'numero' => 'numero',
        'modo' => 'modo',
        'concepto' => 'concepto',
        'importe_total' => 'importe total',
        'importe_neto' => 'importe neto',
        'importe_iva' => 'importe IVA',
        'importe_exento' => 'importe exento',
        'importe_no_gravado' => 'importe no gravado',
        'tipo_doc' => 'tipo de documento',
        'nro_doc' => 'numero de documento',
        'condicion_iva_receptor_id' => 'condicion IVA receptor',
        'origen_manual' => 'origen manual',
    ];

    $diff = [];
    foreach ($labels as $key => $label) {
        if (($expected[$key] ?? null) !== ($actual[$key] ?? null)) {
            $diff[] = $label;
        }
    }

    return $diff;
}

function flus_facturacion_resultado_normalizado(?array $response): ?array
{
    if (!is_array($response) || $response === []) {
        return null;
    }

    $cae = trim((string)($response['cae'] ?? ''));
    if ($cae === '') {
        return null;
    }

    $numero = (int)($response['numero'] ?? $response['CbteNro'] ?? 0);
    $vencimiento = flus_facturacion_normalizar_cae_vto((string)($response['vencimiento'] ?? $response['cae_vto'] ?? $response['CAEFchVto'] ?? ''));

    return [
        'cae' => $cae,
        'vencimiento' => $vencimiento,
        'numero' => $numero,
    ];
}

function flus_facturacion_actualizar_cliente_venta_si_corresponde(PDO $pdo, array $venta, int $clienteIdFiscal): void
{
    if ($clienteIdFiscal <= 0 || !flus_column_exists($pdo, 'ventas', 'cliente_id')) {
        return;
    }

    $ventaId = (int)($venta['id'] ?? 0);
    if ($ventaId <= 0 || (int)($venta['cliente_id'] ?? 0) === $clienteIdFiscal) {
        return;
    }

    $st = $pdo->prepare('UPDATE ventas SET cliente_id = ? WHERE id = ?');
    $st->execute([$clienteIdFiscal, $ventaId]);
}

function flus_facturacion_importes_desde_items(array $items, float $fallbackTotal, int $tipoCbte): array
{
    $total = round($fallbackTotal > 0 ? $fallbackTotal : array_reduce($items, static function (float $carry, array $item): float {
        return $carry + (float)($item['subtotal'] ?? 0);
    }, 0.0), 2);
    $esFacturaC = in_array($tipoCbte, [11, 12, 13], true);

    if ($esFacturaC) {
        return [
            'total' => $total,
            'neto' => $total,
            'iva' => 0.0,
            'exento' => 0.0,
            'no_gravado' => 0.0,
            'iva_detalle' => [],
        ];
    }

    if ($items === []) {
        $neto = round($total / 1.21, 2);
        $iva = round($total - $neto, 2);
        return [
            'total' => $total,
            'neto' => $neto,
            'iva' => $iva,
            'exento' => 0.0,
            'no_gravado' => 0.0,
            'iva_detalle' => [['id' => 5, 'base' => $neto, 'importe' => $iva]],
        ];
    }

    $neto = 0.0;
    $iva = 0.0;
    $ivaDetalleMap = [];

    foreach ($items as $item) {
        $pct = (float)($item['iva_porcentaje'] ?? 21);
        $subtotal = (float)($item['subtotal'] ?? 0);

        if ($pct <= 0) {
            $neto += $subtotal;
            continue;
        }

        $baseImp = $subtotal / (1 + $pct / 100);
        $impIva = $subtotal - $baseImp;
        $neto += $baseImp;
        $iva += $impIva;

        $alicuotaId = obtenerIdAlicuotaAfip($pct);
        $ivaKey = (string)$alicuotaId;
        if (!isset($ivaDetalleMap[$ivaKey])) {
            $ivaDetalleMap[$ivaKey] = [
                'id' => $alicuotaId,
                'base' => 0.0,
                'importe' => 0.0,
            ];
        }
        $ivaDetalleMap[$ivaKey]['base'] += $baseImp;
        $ivaDetalleMap[$ivaKey]['importe'] += $impIva;
    }

    $ivaDetalle = array_map(static function (array $item): array {
        $item['base'] = round((float)$item['base'], 2);
        $item['importe'] = round((float)$item['importe'], 2);
        return $item;
    }, array_values($ivaDetalleMap));

    return [
        'total' => $total,
        'neto' => round($neto, 2),
        'iva' => round($iva, 2),
        'exento' => 0.0,
        'no_gravado' => 0.0,
        'iva_detalle' => $ivaDetalle,
    ];
}

function flus_facturacion_factura_header_base(array $context, string $estadoFiscal = 'PENDIENTE_ENVIO'): array
{
    $timestamp = date('Y-m-d H:i:s');

    return [
        'venta_id' => (int)($context['venta']['id'] ?? 0) > 0 ? (int)($context['venta']['id'] ?? 0) : null,
        'documento_id' => (int)($context['documento']['id'] ?? 0) > 0 ? (int)$context['documento']['id'] : null,
        'cliente_id' => (int)($context['cliente_id_fiscal'] ?? 0) > 0 ? (int)$context['cliente_id_fiscal'] : null,
        'naturaleza' => 'FACTURA',
        'tipo' => (string)($context['tipo_str'] ?? ''),
        'tipo_cbte' => (int)($context['tipo_cbte'] ?? 0),
        'punto_venta' => (int)($context['punto_venta'] ?? 0),
        'numero' => (int)($context['numero'] ?? 0),
        'fecha' => $timestamp,
        'importe_neto' => round((float)($context['importes']['neto'] ?? 0), 2),
        'importe_iva' => round((float)($context['importes']['iva'] ?? 0), 2),
        'importe_exento' => round((float)($context['importes']['exento'] ?? 0), 2),
        'importe_no_gravado' => round((float)($context['importes']['no_gravado'] ?? 0), 2),
        'total' => round((float)($context['importes']['total'] ?? 0), 2),
        'cae' => null,
        'cae_vto' => null,
        'estado' => 'PENDIENTE',
        'modo' => (string)($context['modo_factura'] ?? 'demo'),
        'doc_tipo' => (int)($context['doc_data']['tipo'] ?? 0) ?: null,
        'doc_numero' => (string)($context['doc_data']['numero'] ?? '') ?: null,
        'condicion_iva_receptor_id' => $context['comprobante']['condicion_iva_receptor_id'] ?? null,
        'moneda_id' => 'PES',
        'moneda_cotiz' => 1,
        'creado_en' => $timestamp,
        'estado_fiscal' => $estadoFiscal,
        'fiscal_request_uid' => (string)($context['request_uid'] ?? ''),
        'fiscal_intentos' => 0,
        'fiscal_error_code' => null,
        'fiscal_error_message' => null,
        'fiscal_requested_at' => null,
        'fiscal_approved_at' => null,
    ];
}

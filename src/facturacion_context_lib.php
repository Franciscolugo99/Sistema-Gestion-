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
        'origen_manual' => !empty($context['origen_manual']),
    ];
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

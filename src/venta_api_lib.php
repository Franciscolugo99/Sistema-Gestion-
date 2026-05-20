<?php
declare(strict_types=1);

require_once __DIR__ . '/recargo_horario.php';

if (!class_exists('FlusVentaDomainException')) {
    final class FlusVentaDomainException extends RuntimeException
    {
        public function __construct(
            string $message,
            private string $errorCode = 'VALIDATION_ERROR',
            private int $statusCode = 422
        ) {
            parent::__construct($message);
        }

        public function errorCode(): string
        {
            return $this->errorCode;
        }

        public function statusCode(): int
        {
            return $this->statusCode;
        }
    }
}

function flus_venta_fail(string $message, string $errorCode = 'VALIDATION_ERROR', int $statusCode = 422): never
{
    throw new FlusVentaDomainException($message, $errorCode, $statusCode);
}

function flus_venta_parse_request_inputs(array $body): array
{
    $itemsIn = $body['items'] ?? null;
    if (is_string($itemsIn)) {
        $itemsIn = json_decode($itemsIn, true);
    }

    $descGlobalRaw = $body['desc_global'] ?? null;
    if (is_string($descGlobalRaw)) {
        $descGlobalRaw = json_decode($descGlobalRaw, true);
    }

    $pagosIn = $body['pagos'] ?? null;
    if (is_string($pagosIn)) {
        $pagosIn = json_decode($pagosIn, true);
    }

    return [
        'items_in' => $itemsIn,
        'desc_global_req' => parse_desc_global($descGlobalRaw),
        'pagos_in' => $pagosIn,
    ];
}

function flus_venta_aggregate_items(array $itemsIn): array
{
    $agg = [];
    foreach ($itemsIn as $it) {
        if (!is_array($it)) {
            continue;
        }

        $pid = (int)($it['id'] ?? $it['producto_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }

        $cant = parse_num($it['cantidad'] ?? 0);
        if ($cant <= 0) {
            continue;
        }

        $precioManual = !empty($it['precio_manual']);
        $precioReq = ($precioManual && isset($it['precio'])) ? parse_num($it['precio']) : 0.0;
        if (!isset($agg[$pid])) {
            $agg[$pid] = [
                'producto_id' => $pid,
                'cantidad' => 0.0,
                'precio_req' => $precioReq,
            ];
        }

        $agg[$pid]['cantidad'] += $cant;
        if ($precioReq > 0) {
            $agg[$pid]['precio_req'] = $precioReq;
        }
    }

    return array_values($agg);
}

function flus_venta_build_items_snapshot(PDO $pdo, array $items, bool $puedeCambiarPrecio, ?array $descGlobalReq, int $userId): array
{
    $promos = obtenerPromosActivas($pdo);
    $stmtP = $pdo->prepare("
      SELECT id, nombre, precio, stock, activo, es_pesable
      FROM productos
      WHERE id = :id
      FOR UPDATE
    ");

    $srvItems = [];
    $totalProductos = 0.0;
    $recargoHorario = flus_recargo_horario_estado($pdo);

    foreach ($items as $it) {
        $pid = (int)$it['producto_id'];
        $cant = (float)$it['cantidad'];

        $stmtP->execute([':id' => $pid]);
        $p = $stmtP->fetch(PDO::FETCH_ASSOC);

        if (!$p) {
            flus_venta_fail("Producto #{$pid} no existe", 'PRODUCTO_NO_EXISTE', 422);
        }
        if ((int)$p['activo'] !== 1) {
            flus_venta_fail("Producto inactivo: {$p['nombre']}", 'PRODUCTO_INACTIVO', 409);
        }

        $esPesable = ((int)($p['es_pesable'] ?? 0) === 1);
        if (!$esPesable) {
            if (abs($cant - round($cant)) > 0.00001) {
                flus_venta_fail("Cantidad inválida para {$p['nombre']} (no es pesable)", 'CANTIDAD_INVALIDA', 422);
            }
            $cant = (float)(int)round($cant);
        }

        $stock = (float)($p['stock'] ?? 0);
        $eps = $esPesable ? 0.0005 : 0.0;
        if ($cant > $stock + $eps) {
            flus_venta_fail("Stock insuficiente para {$p['nombre']} (disponible: {$stock}, solicitado: {$cant})", 'STOCK_INSUFICIENTE', 409);
        }

        $precioLista = flus_recargo_horario_aplicar_precio((float)$p['precio'], $recargoHorario);
        $precioActual = $precioLista;
        if ($puedeCambiarPrecio) {
            $pr = (float)$it['precio_req'];
            if ($pr > 0) {
                $precioActual = $pr;
            }
            if ($precioActual <= 0) {
                flus_venta_fail("Precio inválido para {$p['nombre']}", 'PRECIO_INVALIDO', 422);
            }
        }

        $totalProductos += $cant;
        $srvItems[] = [
            'producto_id' => $pid,
            'cantidad' => $cant,
            'precio_lista' => $precioLista,
            'precio_actual' => $precioActual,
            'nombre' => (string)$p['nombre'],
            'es_pesable' => $esPesable ? 1 : 0,
        ];
    }

    $calc = calcular_totales_con_promos($srvItems, $promos);
    $srvItems = $calc['items'];

    $totalBruto = (float)$calc['total_bruto'];
    $totalNetoSinGlobal = (float)$calc['total_neto'];
    $descTotalSinGlobal = (float)$calc['descuento_total'];
    $descGlobalMonto = calc_desc_global($totalNetoSinGlobal, $descGlobalReq);
    $totalNetoFinal = round(max(0.0, $totalNetoSinGlobal - $descGlobalMonto), 2);
    $descTotalFinal = round($descTotalSinGlobal + $descGlobalMonto, 2);

    if ($descGlobalMonto > 0.00001) {
        $tipo = $descGlobalReq['tipo'] ?? 'monto';
        $val = (float)($descGlobalReq['valor'] ?? 0);
        $calc['promos_aplicadas'][] = [
            'promo_id' => 0,
            'promo_tipo' => 'DESC_GLOBAL',
            'promo_nombre' => 'Descuento total',
            'descripcion' => ($tipo === 'porcentaje') ? ($val . '%') : ('-$' . number_format($val, 2, ',', '.')),
            'descuento_monto' => round($descGlobalMonto, 2),
            'meta' => ['tipo' => $tipo, 'valor' => $val, 'aplicado_por_user_id' => $userId],
        ];
    }

    return [
        'srv_items' => $srvItems,
        'calc' => $calc,
        'total_productos' => $totalProductos,
        'total_bruto' => $totalBruto,
        'total_neto_final' => $totalNetoFinal,
        'descuento_total_final' => $descTotalFinal,
        'desc_global_monto' => $descGlobalMonto,
    ];
}

function flus_venta_prepare_payment_data(array $body, mixed $pagosIn, float $totalNetoFinal): array
{
    $pagosValidos = [];
    $ccClienteId = (int)($body['cc_cliente_id'] ?? 0);

    if (is_array($pagosIn) && count($pagosIn) > 0) {
        foreach ($pagosIn as $p) {
            if (!is_array($p)) {
                continue;
            }
            $pm = norm_medio_pago((string)($p['medio'] ?? 'EFECTIVO'));
            $mx = parse_num($p['monto'] ?? 0);
            if ($mx <= 0) {
                continue;
            }
            $pagosValidos[] = ['medio' => $pm, 'monto' => round($mx, 2)];
        }
    }

    if ($pagosValidos === []) {
        $medioLegacy = norm_medio_pago((string)($body['medio_pago'] ?? 'EFECTIVO'));
        $montoLegacy = parse_num($body['monto_pagado'] ?? 0);
        if ($montoLegacy <= 0) {
            $montoLegacy = $totalNetoFinal;
        }
        $pagosValidos[] = ['medio' => $medioLegacy, 'monto' => round($montoLegacy, 2)];
    }

    $pagosCC = [];
    $pagosCaja = [];
    $montoCC = 0.0;
    foreach ($pagosValidos as $pg) {
        if ($pg['medio'] === 'CC') {
            $pagosCC[] = $pg;
            $montoCC += (float)$pg['monto'];
            continue;
        }
        $pagosCaja[] = $pg;
    }

    return [
        'pagos_validos' => $pagosValidos,
        'pagos_cc' => $pagosCC,
        'pagos_caja' => $pagosCaja,
        'monto_cc' => round($montoCC, 2),
        'cc_cliente_id' => $ccClienteId,
    ];
}

function flus_venta_validate_cc_payment(PDO $pdo, int $ccClienteId, float $montoCC): array
{
    $ccCtrl = null;
    $ccCheck = null;
    $ccInfo = null;

    if ($montoCC <= 0) {
        return [
            'cc_ctrl' => $ccCtrl,
            'cc_check' => $ccCheck,
            'cc_info' => $ccInfo,
        ];
    }

    if ($ccClienteId <= 0) {
        flus_venta_fail('Debe seleccionar un cliente para pagar a Cuenta Corriente', 'CC_CLIENTE_REQUERIDO', 422);
    }
    if (!function_exists('user_has_permission') || !user_has_permission('registrar_cargo_cc')) {
        flus_venta_fail('No tiene permiso para vender a Cuenta Corriente', 'CC_SIN_PERMISO', 403);
    }

    $ccCtrl = new CuentaCorrienteController($pdo);
    $ccCheck = $ccCtrl->verificarDisponibilidad($ccClienteId, $montoCC);
    if (!($ccCheck['ok'] ?? false)) {
        $excede = $ccCheck['excede'] ?? false;
        $puedeAutorizar = function_exists('user_has_permission') && user_has_permission('vender_excedido_cc');
        if ($excede && !$puedeAutorizar) {
            $disponible = $ccCheck['disponible'] ?? 0;
            throw new RuntimeException(
                'El cliente excede su límite de crédito. Disponible: $' . number_format((float)$disponible, 2, ',', '.')
            );
        }
    }

    $ccCliente = $ccCtrl->getClienteCC($ccClienteId);
    if ($ccCliente) {
        $ccInfo = [
            'cliente_id' => $ccClienteId,
            'cliente_nombre' => $ccCliente['nombre'] ?? '',
            'saldo_anterior' => (float)($ccCliente['cc_saldo'] ?? 0),
            'monto_cargado' => $montoCC,
        ];
    }

    return [
        'cc_ctrl' => $ccCtrl,
        'cc_check' => $ccCheck,
        'cc_info' => $ccInfo,
    ];
}

function flus_venta_resolve_payment_totals(array $pagosValidos, array $pagosCaja, float $totalNetoFinal): array
{
    $totalPagado = 0.0;
    $tieneEfectivo = false;
    foreach ($pagosValidos as $pg) {
        $totalPagado += (float)$pg['monto'];
        if ($pg['medio'] === 'EFECTIVO') {
            $tieneEfectivo = true;
        }
    }
    $totalPagado = round($totalPagado, 2);

    if ($totalPagado + 1e-6 < $totalNetoFinal) {
        flus_venta_fail('Pago insuficiente', 'PAGO_INSUFICIENTE', 422);
    }

    $efectivoCaja = 0.0;
    foreach ($pagosCaja as $pg) {
        if ($pg['medio'] === 'EFECTIVO') {
            $efectivoCaja += (float)$pg['monto'];
        }
    }

    if (!$tieneEfectivo && $totalPagado > $totalNetoFinal + 0.01) {
        flus_venta_fail('Sobrepago sin efectivo (no se puede dar vuelto)', 'SOBREPAGO_SIN_EFECTIVO', 409);
    }

    $vuelto = ($efectivoCaja > 0) ? round(max(0.0, $totalPagado - $totalNetoFinal), 2) : 0.0;
    if ($vuelto > 0.009 && $efectivoCaja + 0.0001 < $vuelto) {
        flus_venta_fail('El vuelto supera el efectivo ingresado', 'VUELTO_INVALIDO', 422);
    }

    $pagosCajaCobranza = $pagosCaja;
    $pagosOrdenados = $pagosCaja;
    if ($pagosOrdenados !== []) {
        usort($pagosOrdenados, fn(array $a, array $b): int => $b['monto'] <=> $a['monto']);
        $medio = (string)$pagosOrdenados[0]['medio'];
    } else {
        $medio = 'CC';
    }

    return [
        'total_pagado' => $totalPagado,
        'vuelto' => $vuelto,
        'medio' => $medio,
        'monto_pagado' => $totalPagado,
        'pagos_caja_cobranza' => $pagosCajaCobranza,
    ];
}

function flus_venta_insert_record(PDO $pdo, int $userId, int $cajaId, float $totalNetoFinal, float $totalBruto, float $descTotalFinal, string $medio, float $montoPagado, float $vuelto, int $ccClienteId, float $montoCC): int
{
    $ventaData = [
        'user_id' => ($userId > 0 ? $userId : null),
        'caja_id' => $cajaId,
        'total' => $totalNetoFinal,
        'total_bruto' => $totalBruto,
        'descuento_total' => $descTotalFinal,
        'medio_pago' => $medio,
        'monto_pagado' => $montoPagado,
        'vuelto' => $vuelto,
        'estado' => 'EMITIDA',
    ];

    if ($ccClienteId > 0 && has_col($pdo, 'ventas', 'cliente_id')) {
        $ventaData['cliente_id'] = $ccClienteId;
    }
    if ($montoCC > 0 && has_col($pdo, 'ventas', 'monto_cc')) {
        $ventaData['monto_cc'] = $montoCC;
    }

    return insert_dynamic($pdo, 'ventas', $ventaData);
}

function flus_venta_register_cc_charge(PDO $pdo, ?CuentaCorrienteController $ccCtrl, ?array $ccCheck, ?array $ccInfo, int $ccClienteId, float $montoCC, int $userId, int $ventaId, int $cajaId, int $terminalId): array
{
    $ccMovimientoId = null;
    if ($montoCC <= 0 || $ccClienteId <= 0) {
        return [
            'cc_movimiento_id' => $ccMovimientoId,
            'cc_info' => $ccInfo,
        ];
    }

    $ccCtrl = $ccCtrl ?? new CuentaCorrienteController($pdo);
    $autorizadoPor = null;
    if (($ccCheck['excede'] ?? false) && function_exists('user_has_permission') && user_has_permission('vender_excedido_cc')) {
        $autorizadoPor = $userId;
    }

    $ccResult = $ccCtrl->registrarCargo(
        $ccClienteId,
        $montoCC,
        $userId,
        $ventaId,
        "Venta #{$ventaId}",
        $autorizadoPor,
        ['caja_id' => $cajaId, 'terminal_id' => $terminalId]
    );

    if (!($ccResult['success'] ?? false)) {
        flus_venta_fail((string)($ccResult['error'] ?? 'Error al registrar cargo en cuenta corriente'), 'CC_REGISTRO_ERROR', 409);
    }

    $ccMovimientoId = $ccResult['movimiento_id'] ?? null;
    if ($ccInfo) {
        $ccInfo['saldo_posterior'] = $ccResult['saldo_posterior'] ?? null;
    }

    return [
        'cc_movimiento_id' => $ccMovimientoId,
        'cc_info' => $ccInfo,
    ];
}

function flus_venta_store_payment_rows(PDO $pdo, int $ventaId, array $pagosValidos, int $ccClienteId, mixed $ccMovimientoId): void
{
    if (
        !has_col($pdo, 'venta_pagos', 'venta_id') ||
        !has_col($pdo, 'venta_pagos', 'medio_pago') ||
        !has_col($pdo, 'venta_pagos', 'monto')
    ) {
        return;
    }

    $tieneColCC = has_col($pdo, 'venta_pagos', 'cc_cliente_id');
    $tieneColCCMov = has_col($pdo, 'venta_pagos', 'cc_movimiento_id');

    foreach ($pagosValidos as $pg) {
        $insertPago = [
            'venta_id' => $ventaId,
            'medio_pago' => $pg['medio'],
            'monto' => $pg['monto'],
        ];

        if ($pg['medio'] === 'CC') {
            if ($tieneColCC) {
                $insertPago['cc_cliente_id'] = $ccClienteId;
            }
            if ($tieneColCCMov) {
                $insertPago['cc_movimiento_id'] = $ccMovimientoId;
            }
        }

        insert_dynamic($pdo, 'venta_pagos', $insertPago);
    }
}

function flus_venta_register_sale_cobranzas(PDO $pdo, int $ventaId, int $ccClienteId, int $cajaId, int $userId, array $pagosCajaCobranza): void
{
    if (!flus_cobranzas_tables_ready($pdo)) {
        return;
    }

    $lineaCobranza = 0;
    foreach ($pagosCajaCobranza as $pg) {
        $lineaCobranza++;
        flus_cobranzas_register_sale_payment($pdo, [
            'venta_id' => $ventaId,
            'cliente_id' => $ccClienteId > 0 ? $ccClienteId : null,
            'caja_id' => $cajaId,
            'medio_pago' => (string)$pg['medio'],
            'monto' => (float)$pg['monto'],
            'linea' => $lineaCobranza,
            'created_by' => $userId,
            'observaciones' => 'Cobro registrado desde venta de mostrador',
        ]);
    }
}

function flus_venta_store_items_and_stock(PDO $pdo, int $ventaId, array $srvItems): void
{
    foreach ($srvItems as $it) {
        $pid = (int)$it['producto_id'];
        $cant = (float)$it['cantidad'];
        $neto = (float)$it['neto'];
        $lista = (float)$it['precio_lista'];
        $desc = (float)$it['descuento'];
        $precioUnitFinal = ($cant > 0) ? round($neto / $cant, 2) : 0.0;

        insert_dynamic($pdo, 'venta_items', [
            'venta_id' => $ventaId,
            'producto_id' => $pid,
            'cantidad' => $cant,
            'precio' => $precioUnitFinal,
            'subtotal' => $neto,
            'precio_unit_original' => $lista,
            'descuento_monto' => $desc,
            'precio_unit_final' => $precioUnitFinal,
        ]);

        $st = $pdo->prepare('UPDATE productos SET stock = stock - :c WHERE id = :id');
        $st->execute([':c' => $cant, ':id' => $pid]);

        insert_dynamic($pdo, 'movimientos_stock', [
            'producto_id' => $pid,
            'tipo' => 'VENTA',
            'cantidad' => $cant,
            'venta_id' => $ventaId,
            'referencia_venta_id' => $ventaId,
            'comentario' => null,
            'fecha' => date('Y-m-d H:i:s'),
        ]);
    }
}

function flus_venta_store_applied_promos(PDO $pdo, int $ventaId, array $promosAplicadas): void
{
    if ($promosAplicadas === []) {
        return;
    }

    $cut = static function (string $s, int $n): string {
        return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n);
    };

    foreach ($promosAplicadas as $p) {
        if (!is_array($p)) {
            continue;
        }

        $promoId = isset($p['promo_id']) ? (int)$p['promo_id'] : null;
        $tipo = trim((string)($p['promo_tipo'] ?? ''));
        $nombre = trim((string)($p['promo_nombre'] ?? ''));
        $descTxt = trim((string)($p['descripcion'] ?? ''));
        $monto = (float)($p['descuento_monto'] ?? 0);
        $meta = $p['meta'] ?? null;
        if ($tipo === '' || $nombre === '' || $monto <= 0) {
            continue;
        }

        insert_dynamic($pdo, 'venta_promos', [
            'venta_id' => $ventaId,
            'promo_id' => ($promoId && $promoId > 0) ? $promoId : null,
            'promo_tipo' => $cut($tipo, 20),
            'promo_nombre' => $cut($nombre, 120),
            'descripcion' => ($descTxt !== '') ? $cut($descTxt, 255) : null,
            'descuento_monto' => round($monto, 2),
            'meta' => ($meta === null) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }
}

function flus_venta_build_response(int $ventaId, float $totalNetoFinal, float $totalBruto, float $descTotalFinal, string $medio, float $montoPagado, float $vuelto, float $descGlobalMonto, array $pagosValidos, float $montoCC, ?array $ccInfo): array
{
    $respuesta = [
        'venta_id' => $ventaId,
        'total' => $totalNetoFinal,
        'total_bruto' => $totalBruto,
        'descuento_total' => $descTotalFinal,
        'medio_pago' => $medio,
        'monto_pagado' => $montoPagado,
        'vuelto' => $vuelto,
        'desc_global_monto' => $descGlobalMonto,
        'pagos' => $pagosValidos,
        'total_pagado' => $montoPagado,
    ];

    if ($montoCC > 0) {
        $respuesta['monto_cc'] = $montoCC;
        $respuesta['cc'] = $ccInfo;
    }

    return $respuesta;
}


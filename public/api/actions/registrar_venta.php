<?php
declare(strict_types=1);
// public/api/actions/registrar_venta.php

require_once FLUS_ROOT . '/src/cloud_sync_lib.php';

$pdo = $pdo ?? getPDO();
$user = current_user();
$userId = (int)($user['id'] ?? 0);

require_terminal_lock_json();

$ventaRequest = flus_venta_parse_request_inputs($body);
$itemsIn = $ventaRequest['items_in'];
$descGlobalReq = $ventaRequest['desc_global_req'];
$pagosIn = $ventaRequest['pagos_in'];
$requestUid = flus_venta_request_uid_from_body($body);
flus_venta_require_request_uid_storage($pdo, $requestUid);

$ventaExistente = flus_venta_find_by_request_uid($pdo, $requestUid);
if ($ventaExistente !== null) {
  json_ok(flus_venta_build_idempotent_response($pdo, $ventaExistente));
}

if (!is_array($itemsIn) || !$itemsIn) {
  json_fail('Ticket vacío', 422);
}

$puedeCambiarPrecio = function_exists('user_has_permission') && user_has_permission('caja_modificar_precio');
if ($descGlobalReq !== null && !$puedeCambiarPrecio) {
  json_fail('No tiene permiso para aplicar descuentos', 403);
}

$items = flus_venta_aggregate_items($itemsIn);
if (!$items) {
  json_fail('Items inválidos', 422);
}

$terminalId = (int)($_SESSION['terminal_id'] ?? current_terminal_id());
$caja = caja_get_abierta($pdo, $terminalId);
$cajaId = (int)($caja['id'] ?? 0);
if ($cajaId <= 0) {
  json_fail('No hay caja abierta', 409);
}
if (!caja_user_can_operar_turno($caja, $userId)) {
  json_fail('Esta caja fue abierta por ' . caja_turno_owner_label($caja) . '. Cerrá ese turno o cambiá de terminal para vender.', 409, ['error_code' => 'CAJA_TURNO_AJENO']);
}

try {
  $pdo->beginTransaction();

  $cajaBloqueada = caja_lock_session_for_update($pdo, $cajaId);
  if (!$cajaBloqueada || !caja_session_is_open($cajaBloqueada['fecha_cierre'] ?? null)) {
    flus_venta_fail('La caja fue cerrada antes de completar la venta.', 'CAJA_CERRADA', 409);
  }
  if (!caja_user_can_operar_turno($cajaBloqueada, $userId)) {
    flus_venta_fail('El turno de caja ya no pertenece a este usuario.', 'CAJA_TURNO_AJENO', 409);
  }

  $snapshot = flus_venta_build_items_snapshot($pdo, $items, $puedeCambiarPrecio, $descGlobalReq, $userId);
  $srvItems = $snapshot['srv_items'];
  $calc = $snapshot['calc'];
  $totalProductos = $snapshot['total_productos'];
  $totalBruto = $snapshot['total_bruto'];
  $totalNetoFinal = $snapshot['total_neto_final'];
  $descTotalFinal = $snapshot['descuento_total_final'];
  $descGlobalMonto = $snapshot['desc_global_monto'];
  $ajustePrecioTotal = (float)($snapshot['ajuste_precio_total'] ?? 0);
  $ajustePrecioRedondeoTotal = (float)($snapshot['ajuste_precio_redondeo_total'] ?? 0);

  $paymentData = flus_venta_prepare_payment_data($body, $pagosIn, $totalNetoFinal);
  $pagosValidos = $paymentData['pagos_validos'];
  $pagosCaja = $paymentData['pagos_caja'];
  $montoCC = $paymentData['monto_cc'];
  $ccClienteId = $paymentData['cc_cliente_id'];

  $ccValidation = flus_venta_validate_cc_payment($pdo, $ccClienteId, $montoCC);
  $ccCtrl = $ccValidation['cc_ctrl'];
  $ccCheck = $ccValidation['cc_check'];
  $ccInfo = $ccValidation['cc_info'];

  $paymentTotals = flus_venta_resolve_payment_totals($pagosValidos, $pagosCaja, $totalNetoFinal);
  $vuelto = $paymentTotals['vuelto'];
  $medio = $paymentTotals['medio'];
  $montoPagado = $paymentTotals['monto_pagado'];
  $pagosCajaCobranza = $paymentTotals['pagos_caja_cobranza'];
  $mpQr = flus_venta_validate_mp_qr_payment($body, $pagosCaja, $totalNetoFinal);
  $mpPoint = flus_venta_validate_mp_point_payment($body, $pagosCaja, $totalNetoFinal);
  flus_venta_assert_mp_payment_ids_available($pdo, [$mpQr, $mpPoint]);

  $ventaId = flus_venta_insert_record(
    $pdo,
    $userId,
    $cajaId,
    $totalNetoFinal,
    $totalBruto,
    $descTotalFinal,
    $medio,
    $montoPagado,
    $vuelto,
    $ccClienteId,
    $montoCC,
    $ajustePrecioTotal,
    $ajustePrecioRedondeoTotal,
    $requestUid
  );

  $ccCharge = flus_venta_register_cc_charge(
    $pdo,
    $ccCtrl,
    $ccCheck,
    $ccInfo,
    $ccClienteId,
    $montoCC,
    $userId,
    $ventaId,
    $cajaId,
    $terminalId
  );
  $ccMovimientoId = $ccCharge['cc_movimiento_id'];
  $ccInfo = $ccCharge['cc_info'];

  flus_venta_store_payment_rows($pdo, $ventaId, $pagosValidos, $ccClienteId, $ccMovimientoId, [
    'mp_qr' => $mpQr,
    'mp_point' => $mpPoint,
  ]);
  flus_venta_register_sale_cobranzas($pdo, $ventaId, $ccClienteId, $cajaId, $userId, $pagosCajaCobranza);
  flus_venta_store_items_and_stock($pdo, $ventaId, $srvItems);
  flus_venta_store_applied_promos($pdo, $ventaId, $calc['promos_aplicadas'] ?? []);

  update_caja_venta_totales($pdo, $cajaId, $totalNetoFinal, $totalProductos);
  foreach ($pagosCaja as $pg) {
    update_caja_medio_delta($pdo, $cajaId, $pg['medio'], (float)$pg['monto']);
  }

  if ($vuelto > 0.00001) {
    update_caja_medio_delta($pdo, $cajaId, 'EFECTIVO', -$vuelto);
  }

  $pdo->commit();

  $response = flus_venta_build_response(
    $ventaId,
    $totalNetoFinal,
    $totalBruto,
    $descTotalFinal,
    $medio,
    $montoPagado,
    $vuelto,
    $descGlobalMonto,
    $pagosValidos,
    $montoCC,
    $ccInfo
  );
  if ($mpQr !== null) {
    $response['mp_qr'] = $mpQr;
  }
  if ($mpPoint !== null) {
    $response['mp_point'] = $mpPoint;
  }
  if ($requestUid !== null) {
    $response['request_uid'] = $requestUid;
  }

  flus_cloud_sync_enqueue_sale($pdo, [
    'venta_id' => $ventaId,
    'request_uid' => $requestUid,
    'fecha' => date('Y-m-d H:i:s'),
    'caja_id' => $cajaId,
    'terminal_id' => $terminalId,
    'user_id' => $userId,
    'total' => $totalNetoFinal,
    'total_bruto' => $totalBruto,
    'descuento_total' => $descTotalFinal,
    'ajuste_precio_total' => $ajustePrecioTotal,
    'ajuste_precio_redondeo_total' => $ajustePrecioRedondeoTotal,
    'medio_pago' => $medio,
    'monto_pagado' => $montoPagado,
    'vuelto' => $vuelto,
    'monto_cc' => $montoCC,
    'pagos' => $pagosValidos,
    'items_count' => count($srvItems),
    'items' => array_map(static function (array $item): array {
      return [
        'producto_id' => (int)($item['id'] ?? $item['producto_id'] ?? 0),
        'codigo' => (string)($item['codigo'] ?? ''),
        'nombre' => (string)($item['nombre'] ?? ''),
        'cantidad' => (float)($item['cantidad'] ?? 0),
        'precio' => round((float)($item['precio'] ?? 0), 2),
        'subtotal' => round((float)($item['subtotal'] ?? 0), 2),
      ];
    }, $srvItems),
  ]);

  json_ok($response);
} catch (FlusVentaDomainException $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  json_fail($e->getMessage(), $e->statusCode(), ['error_code' => $e->errorCode()]);
} catch (PDOException $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  if (flus_venta_duplicate_key_exception($e) && $requestUid !== null) {
    $ventaExistente = flus_venta_find_by_request_uid($pdo, $requestUid);
    if ($ventaExistente !== null) {
      json_ok(flus_venta_build_idempotent_response($pdo, $ventaExistente));
    }
  }
  error_log("Error DB en registrar_venta: " . $e->getMessage() . " | User: {$userId} | Caja: {$cajaId}");
  json_fail('No se pudo registrar la venta.', 500, ['error_code' => 'INTERNAL_ERROR']);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  error_log("Error en registrar_venta: " . $e->getMessage() . " | User: {$userId} | Caja: {$cajaId}");
  json_fail('No se pudo registrar la venta.', 500, ['error_code' => 'INTERNAL_ERROR']);
}

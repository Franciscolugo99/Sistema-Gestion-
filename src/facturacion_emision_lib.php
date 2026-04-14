<?php
declare(strict_types=1);

function flus_facturacion_ejecutar_envio_arca(PDO $pdo, array $context, array $opciones = []): array
{
    $emitCallback = $opciones['emit_callback'] ?? null;
    if (is_callable($emitCallback)) {
        $resultado = $emitCallback($context);
        if (!is_array($resultado)) {
            throw new RuntimeException('Emit callback invalido.');
        }
        $resultado['raw_request'] = $resultado['raw_request'] ?? ($context['comprobante'] ?? []);
        $resultado['raw_response'] = $resultado['raw_response'] ?? $resultado;
        return $resultado;
    }

    $comprobante = $context['comprobante'];
    $modoOperacion = (string)($context['modo_operacion'] ?? 'demo');
    $modoDemo = !empty($context['modo_demo']);

    if ($modoDemo) {
        return [
            'cae' => 'DEMO' . str_pad((string)($comprobante['numero'] ?? 0), 14, '0', STR_PAD_LEFT),
            'vencimiento' => date('Y-m-d', strtotime('+10 days')),
            'numero' => (int)($comprobante['numero'] ?? 0),
            'raw_request' => $comprobante,
            'raw_response' => ['demo' => true, 'numero' => (int)($comprobante['numero'] ?? 0)],
        ];
    }

    $envEsperado = flus_facturacion_arca_env_esperado($modoOperacion);
    $envActual = flus_facturacion_arca_env_actual();
    if ($envEsperado !== '' && $envActual !== $envEsperado) {
        throw new RuntimeException('El modo ' . flus_facturacion_modo_label($modoOperacion) . ' requiere FLUS_ARCA_ENV=' . strtoupper($envEsperado) . ' pero hoy esta en ' . strtoupper($envActual) . '.');
    }

    flus_facturacion_arca_assert_emitible($pdo, $modoOperacion);

    require_once __DIR__ . '/../public/includes/ArcaWsfe.php';

    $ultimoAfip = ArcaWsfe::getUltimoAutorizado((int)$comprobante['punto_venta'], (int)$comprobante['tipo_cbte']);
    if ($ultimoAfip !== null) {
        $comprobante['numero'] = max((int)$comprobante['numero'], $ultimoAfip + 1);
    } elseif (flus_facturacion_arca_is_availability_error(ArcaWsfe::getLastError())) {
        flus_facturacion_arca_status_write($pdo, 'unavailable', $modoOperacion, (string)ArcaWsfe::getLastError());
        throw new RuntimeException(flus_facturacion_arca_emision_bloqueada_message());
    }

    flus_facturacion_assert_facturas_scope_compatible($pdo, (int)$comprobante['punto_venta'], (string)($context['tipo_str'] ?? ''), (int)$comprobante['numero'], $modoOperacion);

    $resultado = ArcaWsfe::solicitarCAE($comprobante);
    if (!$resultado) {
        $lastError = (string)(ArcaWsfe::getLastError() ?: '');
        if (strpos($lastError, '10016') !== false || stripos($lastError, 'ya fue') !== false) {
            $consulta = ArcaWsfe::consultarComprobante((int)$comprobante['punto_venta'], (int)$comprobante['tipo_cbte'], (int)$comprobante['numero']);
            $normalizadoConsulta = flus_facturacion_resultado_normalizado(is_array($consulta) ? $consulta : []);
            if ($normalizadoConsulta !== null) {
                flus_facturacion_arca_status_write($pdo, 'available', $modoOperacion, '');
                return [
                    'cae' => $normalizadoConsulta['cae'],
                    'vencimiento' => $normalizadoConsulta['vencimiento'],
                    'numero' => $normalizadoConsulta['numero'] > 0 ? $normalizadoConsulta['numero'] : (int)$comprobante['numero'],
                    'raw_request' => $comprobante,
                    'raw_response' => $consulta,
                ];
            }
        }

        if (flus_facturacion_arca_is_availability_error($lastError)) {
            flus_facturacion_arca_status_write($pdo, 'unavailable', $modoOperacion, $lastError);
        }
        throw new RuntimeException(flus_facturacion_humanizar_error_arca($lastError));
    }

    flus_facturacion_arca_status_write($pdo, 'available', $modoOperacion, '');
    $resultado['raw_request'] = $comprobante;
    $resultado['raw_response'] = ArcaWsfe::getLastResponse() ?? $resultado;
    return $resultado;
}

function flus_facturacion_finalizar_factura_autorizada(PDO $pdo, FacturaFiscalRepository $repo, array $factura, array $context, array $resultado, array $meta = []): int
{
    $normalizado = flus_facturacion_resultado_normalizado($resultado);
    if ($normalizado === null) {
        throw new RuntimeException('No se pudo normalizar la respuesta de ARCA para finalizar la factura.');
    }

    $facturaId = (int)($factura['id'] ?? 0);
    if ($facturaId <= 0) {
        throw new RuntimeException('Factura local inexistente para finalizar.');
    }

    $requestUid = trim((string)($context['request_uid'] ?? $factura['fiscal_request_uid'] ?? ''));
    $ventaId = (int)($factura['venta_id'] ?? $context['venta']['id'] ?? 0);
    $intentoNo = max(1, (int)($factura['fiscal_intentos'] ?? 0));
    $timestamp = date('Y-m-d H:i:s');
    $estadoFinal = !empty($meta['recovered']) ? 'RECUPERADA' : 'AUTORIZADA';

    try {
        $pdo->beginTransaction();

        $facturaLocked = $repo->lockFacturaById($facturaId);
        if ($facturaLocked === []) {
            throw new RuntimeException('La factura local ya no existe.');
        }
        if ($ventaId > 0) {
            $repo->lockVenta($ventaId);
        }

        $numero = (int)($normalizado['numero'] ?? 0);
        if ($numero <= 0) {
            $numero = (int)($context['numero'] ?? $facturaLocked['numero'] ?? 0);
        }

        $repo->updateFactura($facturaId, [
            'cliente_id' => (int)($context['cliente_id_fiscal'] ?? 0) > 0 ? (int)$context['cliente_id_fiscal'] : null,
            'naturaleza' => 'FACTURA',
            'tipo' => (string)($context['tipo_str'] ?? $facturaLocked['tipo'] ?? ''),
            'tipo_cbte' => (int)($context['tipo_cbte'] ?? 0),
            'punto_venta' => (int)($context['punto_venta'] ?? 0),
            'numero' => $numero,
            'importe_neto' => round((float)($context['importes']['neto'] ?? 0), 2),
            'importe_iva' => round((float)($context['importes']['iva'] ?? 0), 2),
            'importe_exento' => round((float)($context['importes']['exento'] ?? 0), 2),
            'importe_no_gravado' => round((float)($context['importes']['no_gravado'] ?? 0), 2),
            'total' => round((float)($context['importes']['total'] ?? 0), 2),
            'cae' => (string)$normalizado['cae'],
            'cae_vto' => flus_facturacion_normalizar_cae_vto((string)$normalizado['vencimiento']),
            'estado' => 'EMITIDA',
            'modo' => (string)($context['modo_factura'] ?? 'demo'),
            'doc_tipo' => (int)($context['doc_data']['tipo'] ?? 0) ?: null,
            'doc_numero' => (string)($context['doc_data']['numero'] ?? '') ?: null,
            'condicion_iva_receptor_id' => $context['comprobante']['condicion_iva_receptor_id'] ?? null,
            'moneda_id' => 'PES',
            'moneda_cotiz' => 1,
            'estado_fiscal' => $estadoFinal,
            'fiscal_request_uid' => $requestUid !== '' ? $requestUid : null,
            'fiscal_intentos' => $intentoNo,
            'fiscal_error_code' => null,
            'fiscal_error_message' => null,
            'fiscal_requested_at' => $facturaLocked['fiscal_requested_at'] ?? $timestamp,
            'fiscal_approved_at' => $timestamp,
        ]);

        if ($ventaId > 0) {
            flus_facturacion_upsert_venta_fiscal($pdo, $ventaId, [
                'punto_venta' => (int)($context['punto_venta'] ?? 0),
                'tipo_cbte' => (int)($context['tipo_cbte'] ?? 0),
                'numero' => $numero,
                'cae' => (string)$normalizado['cae'],
                'cae_vto' => (string)$normalizado['vencimiento'],
                'moneda_id' => 'PES',
                'moneda_cotiz' => 1,
            ]);

            if (flus_column_exists($pdo, 'ventas', 'facturada')) {
                $st = $pdo->prepare('UPDATE ventas SET facturada = 1 WHERE id = ?');
                $st->execute([$ventaId]);
            }
        }

        if (!empty($context['modo_demo']) && flus_column_exists($pdo, 'config_facturacion', 'proximo_numero')) {
            $configId = (int)($context['config']['id'] ?? 0);
            if ($configId > 0) {
                $stCfg = $pdo->prepare('UPDATE config_facturacion SET proximo_numero = GREATEST(COALESCE(proximo_numero, 1), :nuevo) WHERE id = :id');
                $stCfg->execute([
                    ':nuevo' => $numero + 1,
                    ':id' => $configId,
                ]);
            }
        }

        if ($requestUid !== '') {
            $repo->updateArcaEventResult($requestUid, [
                'venta_id' => $ventaId > 0 ? $ventaId : null,
                'cliente_id' => (int)($context['cliente_id_fiscal'] ?? 0) > 0 ? (int)$context['cliente_id_fiscal'] : null,
                'factura_id' => $facturaId,
                'operacion' => flus_facturacion_evento_operacion($context),
                'resultado' => 'OK',
                'intento_no' => $intentoNo,
                'modo' => (string)($context['modo_operacion'] ?? 'demo'),
                'error_code' => null,
                'error_message' => null,
                'request_json' => json_encode($meta['raw_request'] ?? $context['comprobante'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'response_json' => json_encode($meta['raw_response'] ?? $resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'finished_at' => $timestamp,
            ]);
        }

        flus_cobranzas_link_factura_from_sale(
            $pdo,
            $ventaId,
            $facturaId,
            (int)($context['documento']['id'] ?? 0) > 0 ? (int)$context['documento']['id'] : null
        );
        flus_cobranzas_link_receipt_factura_from_documento(
            $pdo,
            (int)($context['documento']['id'] ?? 0),
            $facturaId
        );

        $pdo->commit();
        return $facturaId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($requestUid !== '') {
            try {
                $repo->updateArcaEventResult($requestUid, [
                    'venta_id' => $ventaId > 0 ? $ventaId : null,
                    'cliente_id' => (int)($context['cliente_id_fiscal'] ?? 0) > 0 ? (int)$context['cliente_id_fiscal'] : null,
                    'factura_id' => $facturaId,
                    'operacion' => flus_facturacion_evento_operacion($context),
                    'resultado' => 'OK',
                    'intento_no' => $intentoNo,
                    'modo' => (string)($context['modo_operacion'] ?? 'demo'),
                    'error_code' => 'ERROR_POST_ARCA',
                    'error_message' => $e->getMessage(),
                    'request_json' => json_encode($meta['raw_request'] ?? $context['comprobante'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'response_json' => json_encode($meta['raw_response'] ?? $resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (Throwable $ignored) {
            }
        }

        try {
            $repo->updateFacturaFiscalState($facturaId, 'ERROR_POST_ARCA', [
                'fiscal_error_code' => 'ERROR_POST_ARCA',
                'fiscal_error_message' => $e->getMessage(),
            ]);
        } catch (Throwable $ignored) {
        }

        throw new RuntimeException('ARCA autorizo el comprobante pero FLUS no pudo cerrar la registracion local. Reintenta para recovery simple. Detalle: ' . $e->getMessage(), 0, $e);
    }
}

function flus_facturacion_intentar_recovery_simple(PDO $pdo, FacturaFiscalRepository $repo, array $factura, array $context): ?int
{
    $requestUid = trim((string)($factura['fiscal_request_uid'] ?? $context['request_uid'] ?? ''));
    if ($requestUid === '') {
        return null;
    }

    $evento = $repo->findArcaEventByRequestUid($requestUid);
    if (is_array($evento)) {
        $response = flus_facturacion_json_decode_assoc((string)($evento['response_json'] ?? ''));
        $normalizado = flus_facturacion_resultado_normalizado($response);
        if ($normalizado !== null) {
            return flus_facturacion_finalizar_factura_autorizada($pdo, $repo, $factura, $context, $normalizado, [
                'raw_request' => flus_facturacion_json_decode_assoc((string)($evento['request_json'] ?? '')),
                'raw_response' => $response,
                'recovered' => true,
                'recovery_source' => 'EVENTO_ARCA',
            ]);
        }
    }

    if (!empty($context['modo_demo'])) {
        return null;
    }

    $numero = (int)($factura['numero'] ?? $context['numero'] ?? 0);
    if ($numero <= 0) {
        return null;
    }

    require_once __DIR__ . '/../public/includes/ArcaWsfe.php';
    $consulta = ArcaWsfe::consultarComprobante((int)($context['punto_venta'] ?? 0), (int)($context['tipo_cbte'] ?? 0), $numero);
    $normalizado = flus_facturacion_resultado_normalizado(is_array($consulta) ? $consulta : []);
    if ($normalizado === null) {
        return null;
    }

    if ($requestUid !== '') {
        $repo->updateArcaEventResult($requestUid, [
            'venta_id' => (int)($factura['venta_id'] ?? 0) > 0 ? (int)$factura['venta_id'] : null,
            'cliente_id' => (int)($context['cliente_id_fiscal'] ?? 0) > 0 ? (int)$context['cliente_id_fiscal'] : null,
            'factura_id' => (int)($factura['id'] ?? 0) > 0 ? (int)$factura['id'] : null,
            'operacion' => flus_facturacion_evento_operacion($context),
            'resultado' => 'OK',
            'modo' => (string)($context['modo_operacion'] ?? 'demo'),
            'response_json' => json_encode($consulta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
    }

    return flus_facturacion_finalizar_factura_autorizada($pdo, $repo, $factura, $context, $normalizado, [
        'raw_request' => $context['comprobante'] ?? [],
        'raw_response' => $consulta,
        'recovered' => true,
        'recovery_source' => 'CONSULTA_ARCA',
    ]);
}

function flus_facturacion_procesar_factura_registrada(PDO $pdo, array $registro, array $opciones = []): int
{
    $repo = flus_facturacion_fiscal_repository($pdo);
    $factura = is_array($registro['factura'] ?? null) ? $registro['factura'] : [];
    $context = is_array($registro['context'] ?? null) ? $registro['context'] : [];

    $facturaId = (int)($factura['id'] ?? 0);
    if ($facturaId <= 0) {
        throw new RuntimeException('No se pudo registrar la factura local.');
    }

    $estadoFiscal = flus_facturacion_estado_fiscal_normalizar((string)($factura['estado_fiscal'] ?? (($factura['cae'] ?? '') !== '' ? 'AUTORIZADA' : 'NO_APLICA')));
    if ($estadoFiscal === 'AUTORIZADA' || trim((string)($factura['cae'] ?? '')) !== '') {
        return $facturaId;
    }

    if ($estadoFiscal === 'RECHAZADA') {
        $msg = trim((string)($factura['fiscal_error_message'] ?? ''));
        throw new RuntimeException($msg !== '' ? $msg : 'La factura ya fue rechazada por ARCA.');
    }

    $recovered = flus_facturacion_intentar_recovery_simple($pdo, $repo, $factura, $context);
    if ($recovered !== null) {
        return $recovered;
    }

    if ($estadoFiscal === 'ERROR_POST_ARCA') {
        throw new RuntimeException('La factura quedo en ERROR_POST_ARCA. FLUS no la reenviara automaticamente a ARCA: primero hay que regularizarla o confirmar manualmente el resultado remoto.');
    }

    $requestUid = trim((string)($context['request_uid'] ?? $factura['fiscal_request_uid'] ?? ''));
    $intentoNo = max(1, (int)($factura['fiscal_intentos'] ?? 0) + 1);
    $timestamp = date('Y-m-d H:i:s');
    $ventaId = (int)($factura['venta_id'] ?? $context['venta']['id'] ?? 0);

    $repo->updateFactura($facturaId, [
        'cliente_id' => (int)($context['cliente_id_fiscal'] ?? 0) > 0 ? (int)$context['cliente_id_fiscal'] : null,
        'tipo' => (string)($context['tipo_str'] ?? ''),
        'tipo_cbte' => (int)($context['tipo_cbte'] ?? 0),
        'punto_venta' => (int)($context['punto_venta'] ?? 0),
        'numero' => (int)($factura['numero'] ?? $context['numero'] ?? 0),
        'importe_neto' => round((float)($context['importes']['neto'] ?? 0), 2),
        'importe_iva' => round((float)($context['importes']['iva'] ?? 0), 2),
        'importe_exento' => round((float)($context['importes']['exento'] ?? 0), 2),
        'importe_no_gravado' => round((float)($context['importes']['no_gravado'] ?? 0), 2),
        'total' => round((float)($context['importes']['total'] ?? 0), 2),
        'estado' => 'PENDIENTE',
        'estado_fiscal' => 'PENDIENTE_ENVIO',
        'fiscal_request_uid' => $requestUid !== '' ? $requestUid : null,
        'fiscal_intentos' => $intentoNo,
        'fiscal_requested_at' => $timestamp,
        'fiscal_error_code' => null,
        'fiscal_error_message' => null,
    ]);

    $requestPayload = flus_facturacion_request_payload($context);
    $requestJson = json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $existingEvent = $requestUid !== '' ? $repo->findArcaEventByRequestUid($requestUid) : null;
    if ($requestUid !== '') {
        if ($existingEvent === null) {
            $repo->insertArcaEvent([
                'venta_id' => $ventaId > 0 ? $ventaId : null,
                'cliente_id' => (int)($context['cliente_id_fiscal'] ?? 0) > 0 ? (int)$context['cliente_id_fiscal'] : null,
                'factura_id' => $facturaId,
                'request_uid' => $requestUid,
                'operacion' => flus_facturacion_evento_operacion($context),
                'resultado' => 'PENDIENTE',
                'intento_no' => $intentoNo,
                'modo' => (string)($context['modo_operacion'] ?? 'demo'),
                'request_json' => $requestJson,
                'created_at' => $timestamp,
            ]);
        } else {
            $repo->updateArcaEventResult($requestUid, [
                'venta_id' => $ventaId > 0 ? $ventaId : null,
                'cliente_id' => (int)($context['cliente_id_fiscal'] ?? 0) > 0 ? (int)$context['cliente_id_fiscal'] : null,
                'factura_id' => $facturaId,
                'operacion' => flus_facturacion_evento_operacion($context),
                'resultado' => 'PENDIENTE',
                'intento_no' => $intentoNo,
                'modo' => (string)($context['modo_operacion'] ?? 'demo'),
                'error_code' => null,
                'error_message' => null,
                'request_json' => $requestJson,
                'finished_at' => null,
            ]);
        }
    }

    try {
        $resultado = flus_facturacion_ejecutar_envio_arca($pdo, $context, $opciones);
    } catch (Throwable $e) {
        $message = trim((string)$e->getMessage());
        $estadoError = flus_facturacion_estado_fiscal_por_error($message);
        $errorCode = flus_facturacion_error_code($message);

        if ($requestUid !== '') {
            $repo->updateArcaEventResult($requestUid, [
                'venta_id' => $ventaId > 0 ? $ventaId : null,
                'cliente_id' => (int)($context['cliente_id_fiscal'] ?? 0) > 0 ? (int)$context['cliente_id_fiscal'] : null,
                'factura_id' => $facturaId,
                'operacion' => flus_facturacion_evento_operacion($context),
                'resultado' => 'ERROR',
                'intento_no' => $intentoNo,
                'modo' => (string)($context['modo_operacion'] ?? 'demo'),
                'error_code' => $errorCode,
                'error_message' => $message,
                'request_json' => $requestJson,
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $repo->updateFacturaFiscalState($facturaId, $estadoError, [
            'estado' => $estadoError === 'RECHAZADA' ? 'RECHAZADA' : 'PENDIENTE',
            'fiscal_intentos' => $intentoNo,
            'fiscal_error_code' => $errorCode,
            'fiscal_error_message' => $message,
            'fiscal_requested_at' => $timestamp,
        ]);
        throw $e;
    }

    return flus_facturacion_finalizar_factura_autorizada($pdo, $repo, $factura + ['fiscal_intentos' => $intentoNo], $context, $resultado, [
        'raw_request' => $resultado['raw_request'] ?? ($context['comprobante'] ?? []),
        'raw_response' => $resultado['raw_response'] ?? $resultado,
    ]);
}

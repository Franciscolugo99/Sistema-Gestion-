<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/facturacion_lib.php';
require_once __DIR__ . '/../../../src/venta_anulaciones_lib.php';

final class DbAnulacionFiscalCoordinator implements AnulacionFiscalCoordinator
{
    public function __construct(private PDO $pdo, private FacturaFiscalRepository $repository, private NotaCreditoService $notaCreditoService)
    {
    }

    public function procesarTotal(int $ventaId, int $usuarioId, string $motivo, array $options = []): AnulacionFiscalOutcome
    {
        $requestUid = $options['request_uid'] ?? $this->uuid4();
        $facturaOrigenIdOption = max(0, (int)($options['factura_origen_id'] ?? $options['factura_id'] ?? 0));
        $anulacionId = 0;
        $facturaOrigenId = null;
        $itemsRestantes = [];
        $venta = [];

        // Idempotencia: si ya existe una anulación con este request_uid, retornar su estado
        // sin crear un duplicado. Cubre doble-click, retry por timeout y reenvío manual.
        $existing = $this->repository->findVentaAnulacionByRequestUid($requestUid);
        if ($existing !== null) {
            $out = new AnulacionFiscalOutcome();
            $out->ventaId          = $ventaId;
            $out->ventaAnulacionId = (int)$existing['id'];
            $out->estado           = (string)($existing['estado'] ?? 'PENDIENTE');
            $out->estadoFiscal     = (string)($existing['estado_fiscal'] ?? 'PENDIENTE');
            $out->ncFacturaId      = (int)($existing['nc_factura_id'] ?? 0) ?: null;
            $out->requestUid       = $requestUid;
            $out->message          = 'Solicitud ya procesada (idempotente). Estado: ' . $out->estadoFiscal;
            return $out;
        }

        // TX1: crear anulacion pendiente + snapshot
        $this->pdo->beginTransaction();
        try {
            $venta = $this->repository->lockVenta($ventaId);
            if (!$venta) {
                throw new RuntimeException('Venta no encontrada.');
            }
            $facturaOrigen = $this->resolveFacturaOrigen($ventaId, $facturaOrigenIdOption);

            if (strtoupper((string)($venta['estado'] ?? 'EMITIDA')) === 'ANULADA') {
                throw new RuntimeException('La venta ya esta anulada.');
            }
            $facturaOrigenId = (int)($facturaOrigen['id'] ?? 0) ?: null;

            // Bloquear solo si hay una anulación total fiscal en curso o ya aplicada.
            // NO bloquear si el intento anterior fue rechazado por ARCA o cancelado:
            // en esos casos el operador debe poder reintentar.
            // Alineado con el check de procesarParcial que usa estados_fiscal "en curso".
            $stDup = $this->pdo->prepare("
                SELECT id FROM venta_anulaciones
                WHERE venta_id = ?
                  AND tipo = 'TOTAL'
                  AND COALESCE(requiere_nc, 0) = 1
                  AND estado <> 'CANCELADA'
                  AND COALESCE(estado_fiscal, 'NO_APLICA') IN (
                      'PENDIENTE', 'ENVIANDO', 'APROBADA_PENDIENTE_APLICACION', 'APLICADA'
                  )
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
            ");
            $stDup->execute([$ventaId]);
            $dup = $stDup->fetchColumn();
            if ($dup !== false) {
                throw new RuntimeException('Ya existe una anulacion fiscal total pendiente o aplicada para esta venta.');
            }

            $ventaItems = flus_venta_items_cargar($this->pdo, $ventaId);
            $yaAnulado = flus_venta_items_anulados_map($this->pdo, $ventaId);
            $itemsRestantes = flus_venta_items_restantes($ventaItems, $yaAnulado);

            // Batch-carga IVA de productos para el snapshot — una query por todos los items.
            $productoIdsTotal = array_filter(array_map(
                static fn(array $r): int => (int)(($r['item'] ?? [])['producto_id'] ?? 0),
                $itemsRestantes
            ));
            $ivaPctMap = $this->fetchProductosIvaPct(array_values($productoIdsTotal));

            $anulacionId = flus_facturacion_insert_dynamic($this->pdo, 'venta_anulaciones', [
                'venta_id' => $ventaId,
                'tipo' => 'TOTAL',
                'estado' => 'PENDIENTE',
                'motivo' => $motivo !== '' ? mb_substr($motivo, 0, 255) : null,
                'monto_bruto' => round((float)($venta['total'] ?? 0), 2),
                'monto_neto' => round((float)($facturaOrigen['importe_neto'] ?? $venta['total'] ?? 0), 2),
                'monto_iva' => round((float)($facturaOrigen['importe_iva'] ?? 0), 2),
                'monto_total' => round((float)($facturaOrigen['total'] ?? $venta['total'] ?? 0), 2),
                'requiere_nc' => 1,
                'factura_origen_id' => $facturaOrigenId,
                'estado_fiscal' => 'PENDIENTE',
                'fiscal_request_uid' => $requestUid,
                'fiscal_intentos' => 1,
                'fiscal_requested_at' => date('Y-m-d H:i:s'),
                'anulado_por' => $usuarioId > 0 ? $usuarioId : null,
                'anulado_en' => date('Y-m-d H:i:s'),
            ]);

            foreach ($itemsRestantes as $itemId => $row) {
                $item = $row['item'] ?? [];
                $qty = round((float)($row['cantidad_restante'] ?? 0), 3);
                $cantidadOriginal = (float)($item['cantidad'] ?? 0);
                $precioUnit = (float)($item['precio_unit_final'] ?? $item['precio'] ?? 0);
                if ($precioUnit <= 0 && $cantidadOriginal > 0) {
                    $precioUnit = round((float)($item['subtotal'] ?? 0) / $cantidadOriginal, 2);
                }
                $subtotalSnapshot = round((float)($item['subtotal'] ?? 0), 2);
                $subtotalAnulado = round($precioUnit * $qty, 2);
                // Resolución de IVA: primero mapa batch (sin N+1), luego columna legacy,
                // finalmente 0.0 si el item no tiene producto vinculado.
                $productoIdItem = (int)($item['producto_id'] ?? 0);
                $ivaSnapshotTotal = $productoIdItem > 0
                    ? ($ivaPctMap[$productoIdItem] ?? (float)($item['iva_porcentaje'] ?? 0.0))
                    : (float)($item['iva_porcentaje'] ?? 0.0);
                flus_facturacion_insert_dynamic($this->pdo, 'venta_anulacion_items', [
                    'anulacion_id' => $anulacionId,
                    'venta_item_id' => $itemId,
                    'producto_id' => (int)($item['producto_id'] ?? 0),
                    'cantidad_anulada' => $qty,
                    'precio_unitario_snapshot' => $precioUnit,
                    'descuento_monto_snapshot' => round((float)($item['descuento_monto'] ?? 0), 2),
                    'iva_porcentaje_snapshot' => round($ivaSnapshotTotal, 2),
                    'subtotal_snapshot' => $subtotalSnapshot,
                    'subtotal_anulado' => $subtotalAnulado,
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // Fuera TX: llamar ARCA / demo
        $resultadoNc = $this->notaCreditoService->emitir(EmitirNotaCreditoCommand::fromArray([
            'venta_id' => $ventaId,
            'factura_origen_id' => $facturaOrigenId,
            'venta_anulacion_id' => $anulacionId,
            'usuario_id' => $usuarioId,
            'scope' => 'TOTAL',
            'modo' => (string)($options['modo'] ?? ''),
            'request_uid' => $requestUid,
        ]));

        // TX2: persistencia minima local
        $ncFacturaId = null;
        $this->pdo->beginTransaction();
        try {
            if ($resultadoNc->rejected) {
                $this->repository->insertArcaEvent([
                    'venta_anulacion_id' => $anulacionId,
                    'request_uid' => $requestUid,
                    'operacion' => 'NC_TOTAL',
                    'resultado' => 'ERROR',
                    'intento_no' => 1,
                    'modo' => (string)($options['modo'] ?? 'demo'),
                    'error_code' => $resultadoNc->errorCode,
                    'error_message' => $resultadoNc->errorMessage,
                    'request_json' => json_encode($resultadoNc->rawRequest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'response_json' => json_encode($resultadoNc->rawResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => date('Y-m-d H:i:s'),
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);
                $this->repository->updateVentaAnulacionFiscalState($anulacionId, 'RECHAZADA', [
                    'fiscal_error_code' => $resultadoNc->errorCode,
                    'fiscal_error_message' => $resultadoNc->errorMessage,
                ]);
                $this->pdo->commit();

                $out = new AnulacionFiscalOutcome();
                $out->ventaId = $ventaId;
                $out->ventaAnulacionId = $anulacionId;
                $out->estado = 'PENDIENTE';
                $out->estadoFiscal = 'RECHAZADA';
                $out->requestUid = $requestUid;
                $out->message = $resultadoNc->errorMessage;
                return $out;
            }

            $ncFacturaId = $this->repository->insertFactura($resultadoNc->facturaHeader);
            $this->repository->insertFacturaItems($ncFacturaId, $resultadoNc->facturaItems);
            $this->repository->insertArcaEvent([
                'venta_anulacion_id' => $anulacionId,
                'factura_id' => $ncFacturaId,
                'request_uid' => $requestUid,
                'operacion' => 'NC_TOTAL',
                'resultado' => 'OK',
                'intento_no' => 1,
                'modo' => (string)($resultadoNc->facturaHeader['modo'] ?? $options['modo'] ?? 'demo'),
                'request_json' => json_encode($resultadoNc->rawRequest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'response_json' => json_encode($resultadoNc->rawResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => date('Y-m-d H:i:s'),
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $this->repository->updateVentaAnulacionLinkage($anulacionId, $facturaOrigenId, $ncFacturaId, true, true);
            $this->repository->updateVentaAnulacionFiscalState($anulacionId, 'APROBADA_PENDIENTE_APLICACION', [
                'factura_origen_id' => $facturaOrigenId,
                'nc_factura_id' => $ncFacturaId,
                'fiscal_approved_at' => date('Y-m-d H:i:s'),
                'fiscal_error_code' => null,
                'fiscal_error_message' => null,
            ]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // TX3: aplicar comercialmente
        try {
            $this->pdo->beginTransaction();
            $venta = $this->repository->lockVenta($ventaId);
            $this->repository->lockVentaAnulacion($anulacionId);

            if ($itemsRestantes !== []) {
                $comBase = "Anulación fiscal total venta #{$ventaId}" . ($motivo !== '' ? (": " . mb_substr($motivo, 0, 180)) : '');
                flus_venta_stock_reponer_items($this->pdo, $itemsRestantes, $ventaId, $usuarioId, $comBase);
            }

            $ccTotalOriginal = flus_venta_cc_total_original($this->pdo, $ventaId);
            if ($ccTotalOriginal > 0) {
                flus_venta_cc_revertir_monto($this->pdo, $venta, $ventaId, $ccTotalOriginal, $usuarioId, 'NC total venta #' . $ventaId);
            }

            $sets = ["estado = 'ANULADA'"];
            $params = [':id' => $ventaId];
            if (flus_column_exists($this->pdo, 'ventas', 'anulado_en')) {
                $sets[] = 'anulado_en = NOW()';
            }
            if ($usuarioId > 0 && flus_column_exists($this->pdo, 'ventas', 'anulado_por')) {
                $sets[] = 'anulado_por = :anulado_por';
                $params[':anulado_por'] = $usuarioId;
            }
            if ($motivo !== '' && flus_column_exists($this->pdo, 'ventas', 'anulado_motivo')) {
                $sets[] = 'anulado_motivo = :anulado_motivo';
                $params[':anulado_motivo'] = mb_substr($motivo, 0, 255);
            }
            $st = $this->pdo->prepare('UPDATE ventas SET ' . implode(', ', $sets) . ' WHERE id = :id');
            $st->execute($params);

            $stVa = $this->pdo->prepare("UPDATE venta_anulaciones SET estado = 'CONFIRMADA' WHERE id = ?");
            $stVa->execute([$anulacionId]);
            $this->repository->updateVentaAnulacionFiscalState($anulacionId, 'APLICADA', [
                'fiscal_applied_at' => date('Y-m-d H:i:s'),
            ]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->pdo->beginTransaction();
            try {
                $this->repository->updateVentaAnulacionFiscalState($anulacionId, 'ERROR_POST_ARCA', [
                    'fiscal_error_code' => 'ERROR_POST_ARCA',
                    'fiscal_error_message' => $e->getMessage(),
                ]);
                $this->pdo->commit();
            } catch (Throwable $ignored) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            }
            throw $e;
        }

        $out = new AnulacionFiscalOutcome();
        $out->ventaId = $ventaId;
        $out->ventaAnulacionId = $anulacionId;
        $out->estado = 'CONFIRMADA';
        $out->estadoFiscal = 'APLICADA';
        $out->ncFacturaId = $ncFacturaId;
        $out->requestUid = $requestUid;
        $out->message = 'NC total emitida y anulacion aplicada.';
        return $out;
    }

    public function procesarParcial(int $ventaId, array $items, int $usuarioId, string $motivo, array $options = []): AnulacionFiscalOutcome
    {
        $requestUid = $options['request_uid'] ?? $this->uuid4();
        $facturaOrigenIdOption = max(0, (int)($options['factura_origen_id'] ?? $options['factura_id'] ?? 0));
        $requestedItems = $this->normalizePartialRequestItems($items);

        if ($requestedItems === []) {
            throw new RuntimeException('Debes indicar al menos un item con cantidad válida para la anulación parcial fiscal.');
        }

        // Idempotencia: misma cobertura que procesarTotal.
        $existing = $this->repository->findVentaAnulacionByRequestUid($requestUid);
        if ($existing !== null) {
            $out = new AnulacionFiscalOutcome();
            $out->ventaId          = $ventaId;
            $out->ventaAnulacionId = (int)$existing['id'];
            $out->estado           = (string)($existing['estado'] ?? 'PENDIENTE');
            $out->estadoFiscal     = (string)($existing['estado_fiscal'] ?? 'PENDIENTE');
            $out->ncFacturaId      = (int)($existing['nc_factura_id'] ?? 0) ?: null;
            $out->requestUid       = $requestUid;
            $out->message          = 'Solicitud ya procesada (idempotente). Estado: ' . $out->estadoFiscal;
            return $out;
        }

        $anulacionId = 0;
        $facturaOrigenId = null;
        $venta = [];
        $ventaItems = [];
        $usesManualPartialItems = false;
        $nuevoEstadoVenta = 'PARCIALMENTE_ANULADA';

        // TX1: crear venta_anulacion pendiente + snapshot parcial
        $this->pdo->beginTransaction();
        try {
            $venta = $this->repository->lockVenta($ventaId);
            if (!$venta) {
                throw new RuntimeException('Venta no encontrada.');
            }

            $facturaOrigen = $this->resolveFacturaOrigen($ventaId, $facturaOrigenIdOption);
            if (!$facturaOrigen) {
                throw new RuntimeException('No se encontró la factura original asociada a la venta.');
            }

            if (strtoupper((string)($venta['estado'] ?? 'EMITIDA')) === 'ANULADA') {
                throw new RuntimeException('La venta ya está anulada.');
            }

            $facturaOrigenId = (int)($facturaOrigen['id'] ?? 0) ?: null;

            $stOpen = $this->pdo->prepare("
                SELECT id
                FROM venta_anulaciones
                WHERE venta_id = ?
                  AND COALESCE(requiere_nc, 0) = 1
                  AND estado <> 'CANCELADA'
                  AND COALESCE(estado_fiscal, 'NO_APLICA') IN ('PENDIENTE', 'ENVIANDO', 'APROBADA_PENDIENTE_APLICACION')
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
            ");
            $stOpen->execute([$ventaId]);
            if ($stOpen->fetchColumn() !== false) {
                throw new RuntimeException('Ya existe una anulación fiscal en curso para esta venta. Finaliza o resuelve esa primero.');
            }

            $ventaItems = flus_venta_items_cargar($this->pdo, $ventaId);
            if ($ventaItems === []) {
                $ventaItems = $this->manualLegacyItemsForVenta($ventaId);
                $usesManualPartialItems = $ventaItems !== [];
            }
            if ($ventaItems === []) {
                throw new RuntimeException('La venta no tiene items disponibles para generar snapshot parcial.');
            }

            $yaAnulado = $usesManualPartialItems
                ? $this->fetchNcCreditedQtyMap((int)$facturaOrigenId)
                : flus_venta_items_anulados_map($this->pdo, $ventaId);
            $restantes = flus_venta_items_restantes($ventaItems, $yaAnulado);

            // Batch-carga IVA para todos los items solicitados — una query, sin N+1.
            $productoIdsParcial = array_filter(array_map(
                static fn(array $req): int => (int)(($restantes[(int)$req['item_id']]['item'] ?? [])['producto_id'] ?? 0),
                $requestedItems
            ));
            $ivaPctMapParcial = $this->fetchProductosIvaPct(array_values($productoIdsParcial));

            $montoBruto = 0.0;
            $anulacionId = flus_facturacion_insert_dynamic($this->pdo, 'venta_anulaciones', [
                'venta_id' => $ventaId,
                'tipo' => 'PARCIAL',
                'estado' => 'PENDIENTE',
                'motivo' => $motivo !== '' ? mb_substr($motivo, 0, 255) : null,
                'monto_bruto' => 0.00,
                'monto_neto' => 0.00,
                'monto_iva' => 0.00,
                'monto_total' => 0.00,
                'requiere_nc' => 1,
                'factura_origen_id' => $facturaOrigenId,
                'estado_fiscal' => 'PENDIENTE',
                'fiscal_request_uid' => $requestUid,
                'fiscal_intentos' => 1,
                'fiscal_requested_at' => date('Y-m-d H:i:s'),
                'anulado_por' => $usuarioId > 0 ? $usuarioId : null,
                'anulado_en' => date('Y-m-d H:i:s'),
            ]);

            foreach ($requestedItems as $req) {
                $itemId = (int)$req['item_id'];
                $qty = round((float)$req['cantidad'], 3);

                if (!isset($restantes[$itemId])) {
                    throw new RuntimeException('El item #' . $itemId . ' ya no tiene saldo comercial disponible para anular.');
                }

                $restante = round((float)($restantes[$itemId]['cantidad_restante'] ?? 0), 3);
                if ($qty > ($restante + 0.0009)) {
                    throw new RuntimeException(
                        'La cantidad solicitada para el item #' . $itemId
                        . ' supera el saldo comercial disponible (' . $restante . ').'
                    );
                }

                $item = $restantes[$itemId]['item'] ?? [];
                $cantidadOriginal = (float)($item['cantidad'] ?? 0);
                $precioUnit = (float)($item['precio_unit_final'] ?? $item['precio'] ?? 0);

                if ($precioUnit <= 0 && $cantidadOriginal > 0) {
                    $precioUnit = round((float)($item['subtotal'] ?? 0) / $cantidadOriginal, 6);
                }

                $subtotalSnapshot = round((float)($item['subtotal'] ?? 0), 2);
                $subtotalAnulado = round($precioUnit * $qty, 2);
                $montoBruto += $subtotalAnulado;

                $productoIdParcial = (int)($item['producto_id'] ?? 0);
                $ivaSnapshotParcial = $productoIdParcial > 0
                    ? ($ivaPctMapParcial[$productoIdParcial] ?? (float)($item['iva_porcentaje'] ?? 0.0))
                    : (float)($item['iva_porcentaje'] ?? 0.0);

                if ($itemId > 0) {
                    flus_facturacion_insert_dynamic($this->pdo, 'venta_anulacion_items', [
                        'anulacion_id' => $anulacionId,
                        'venta_item_id' => $itemId,
                        'producto_id' => $productoIdParcial ?: null,
                        'cantidad_anulada' => $qty,
                        'precio_unitario_snapshot' => round($precioUnit, 6),
                        'descuento_monto_snapshot' => round((float)($item['descuento_monto'] ?? 0), 2),
                        'iva_porcentaje_snapshot' => round($ivaSnapshotParcial, 2),
                        'subtotal_snapshot' => $subtotalSnapshot,
                        'subtotal_anulado' => $subtotalAnulado,
                    ]);
                }
            }

            $this->updateVentaAnulacionMontos($anulacionId, [
                'monto_bruto' => round($montoBruto, 2),
                'monto_total' => round($montoBruto, 2),
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // fuera de TX: emitir NC parcial
        $resultadoNc = $this->notaCreditoService->emitir(EmitirNotaCreditoCommand::fromArray([
            'venta_id' => $ventaId,
            'factura_origen_id' => $facturaOrigenId,
            'venta_anulacion_id' => $anulacionId,
            'usuario_id' => $usuarioId,
            'scope' => 'PARTIAL',
            'modo' => (string)($options['modo'] ?? ''),
            'request_uid' => $requestUid,
            'partial_items' => $requestedItems,
        ]));

        // TX2: persistencia mínima local
        $ncFacturaId = null;
        $this->pdo->beginTransaction();
        try {
            if ($resultadoNc->rejected) {
                $this->repository->insertArcaEvent([
                    'venta_anulacion_id' => $anulacionId,
                    'request_uid' => $requestUid,
                    'operacion' => 'NC_PARCIAL',
                    'resultado' => 'ERROR',
                    'intento_no' => 1,
                    'modo' => (string)($options['modo'] ?? 'demo'),
                    'error_code' => $resultadoNc->errorCode,
                    'error_message' => $resultadoNc->errorMessage,
                    'request_json' => json_encode($resultadoNc->rawRequest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'response_json' => json_encode($resultadoNc->rawResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => date('Y-m-d H:i:s'),
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);

                $this->repository->updateVentaAnulacionFiscalState($anulacionId, 'RECHAZADA', [
                    'fiscal_error_code' => $resultadoNc->errorCode,
                    'fiscal_error_message' => $resultadoNc->errorMessage,
                ]);

                $this->pdo->commit();

                $out = new AnulacionFiscalOutcome();
                $out->ventaId = $ventaId;
                $out->ventaAnulacionId = $anulacionId;
                $out->estado = 'PENDIENTE';
                $out->estadoFiscal = 'RECHAZADA';
                $out->requestUid = $requestUid;
                $out->message = $resultadoNc->errorMessage;
                return $out;
            }

            $modoFactura = (string)($resultadoNc->facturaHeader['modo'] ?? $options['modo'] ?? 'demo');
            $puntoVenta = (int)($resultadoNc->facturaHeader['punto_venta'] ?? 0);
            $tipoStrNc = (string)($resultadoNc->facturaHeader['tipo'] ?? '');
            $numeroNc = (int)($resultadoNc->facturaHeader['numero'] ?? 0);

            if ($puntoVenta > 0 && $tipoStrNc !== '' && $numeroNc > 0) {
                flus_facturacion_assert_facturas_scope_compatible($this->pdo, $puntoVenta, $tipoStrNc, $numeroNc, $modoFactura);
            }

            $ncFacturaId = $this->repository->insertFactura($resultadoNc->facturaHeader);
            $this->repository->insertFacturaItems($ncFacturaId, $resultadoNc->facturaItems);

            $this->repository->insertArcaEvent([
                'venta_anulacion_id' => $anulacionId,
                'factura_id' => $ncFacturaId,
                'request_uid' => $requestUid,
                'operacion' => 'NC_PARCIAL',
                'resultado' => 'OK',
                'intento_no' => 1,
                'modo' => $modoFactura,
                'request_json' => json_encode($resultadoNc->rawRequest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'response_json' => json_encode($resultadoNc->rawResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => date('Y-m-d H:i:s'),
                'finished_at' => date('Y-m-d H:i:s'),
            ]);

            $this->repository->updateVentaAnulacionLinkage($anulacionId, $facturaOrigenId, $ncFacturaId, true, true);
            $this->repository->updateVentaAnulacionFiscalState($anulacionId, 'APROBADA_PENDIENTE_APLICACION', [
                'factura_origen_id' => $facturaOrigenId,
                'nc_factura_id' => $ncFacturaId,
                'fiscal_approved_at' => date('Y-m-d H:i:s'),
                'fiscal_error_code' => null,
                'fiscal_error_message' => null,
            ]);

            $this->updateVentaAnulacionMontos($anulacionId, [
                'monto_neto' => round((float)($resultadoNc->facturaHeader['importe_neto'] ?? 0), 2),
                'monto_iva' => round((float)($resultadoNc->facturaHeader['importe_iva'] ?? 0), 2),
                'monto_total' => round((float)($resultadoNc->facturaHeader['total'] ?? 0), 2),
                'monto_bruto' => round((float)($resultadoNc->facturaHeader['total'] ?? 0), 2),
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // TX3: aplicar comercialmente sólo si la NC fue aprobada y persistida
        try {
            $this->pdo->beginTransaction();

            $venta = $this->repository->lockVenta($ventaId);
            $this->repository->lockVentaAnulacion($anulacionId);

            $snapshotRows = flus_venta_anulacion_items_cargar($this->pdo, $anulacionId);
            $stockItems = [];
            foreach ($snapshotRows as $row) {
                $ventaItemId = (int)($row['venta_item_id'] ?? 0);
                if ($ventaItemId <= 0 || !isset($ventaItems[$ventaItemId])) {
                    continue;
                }

                $stockItems[] = [
                    'item' => $ventaItems[$ventaItemId],
                    'cantidad' => round((float)($row['cantidad_anulada'] ?? 0), 3),
                ];
            }

            if ($stockItems !== []) {
                $comentario = "Anulación fiscal parcial venta #{$ventaId}" . ($motivo !== '' ? (': ' . mb_substr($motivo, 0, 180)) : '');
                flus_venta_stock_reponer_items($this->pdo, $stockItems, $ventaId, $usuarioId, $comentario);
            }

            $montoNc = round((float)($resultadoNc->facturaHeader['total'] ?? 0), 2);
            if ($montoNc > 0) {
                flus_venta_cc_revertir_monto($this->pdo, $venta, $ventaId, $montoNc, $usuarioId, 'NC parcial venta #' . $ventaId);
            }

            $this->pdo->prepare("UPDATE venta_anulaciones SET estado = 'CONFIRMADA' WHERE id = ?")->execute([$anulacionId]);

            $restantes = flus_venta_items_restantes(
                $ventaItems,
                $usesManualPartialItems
                    ? $this->fetchNcCreditedQtyMap((int)$facturaOrigenId)
                    : flus_venta_items_anulados_map($this->pdo, $ventaId)
            );
            $nuevoEstadoVenta = $restantes === [] ? 'ANULADA' : 'PARCIALMENTE_ANULADA';

            $sets = ['estado = :estado'];
            $params = [
                ':estado' => $nuevoEstadoVenta,
                ':id' => $ventaId,
            ];

            if ($nuevoEstadoVenta === 'ANULADA' && flus_column_exists($this->pdo, 'ventas', 'anulado_en')) {
                $sets[] = 'anulado_en = NOW()';
            }

            if ($nuevoEstadoVenta === 'ANULADA' && $usuarioId > 0 && flus_column_exists($this->pdo, 'ventas', 'anulado_por')) {
                $sets[] = 'anulado_por = :anulado_por';
                $params[':anulado_por'] = $usuarioId;
            }

            if ($nuevoEstadoVenta === 'ANULADA' && $motivo !== '' && flus_column_exists($this->pdo, 'ventas', 'anulado_motivo')) {
                $sets[] = 'anulado_motivo = :anulado_motivo';
                $params[':anulado_motivo'] = mb_substr($motivo, 0, 255);
            }

            $stVenta = $this->pdo->prepare('UPDATE ventas SET ' . implode(', ', $sets) . ' WHERE id = :id');
            $stVenta->execute($params);

            $this->repository->updateVentaAnulacionFiscalState($anulacionId, 'APLICADA', [
                'fiscal_applied_at' => date('Y-m-d H:i:s'),
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $this->pdo->beginTransaction();
            try {
                $this->repository->updateVentaAnulacionFiscalState($anulacionId, 'ERROR_POST_ARCA', [
                    'fiscal_error_code' => 'ERROR_POST_ARCA',
                    'fiscal_error_message' => $e->getMessage(),
                ]);
                $this->pdo->commit();
            } catch (Throwable $ignored) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            }

            throw $e;
        }

        $out = new AnulacionFiscalOutcome();
        $out->ventaId = $ventaId;
        $out->ventaAnulacionId = $anulacionId;
        $out->estado = 'CONFIRMADA';
        $out->estadoFiscal = 'APLICADA';
        $out->ncFacturaId = $ncFacturaId;
        $out->requestUid = $requestUid;
        $out->message = $nuevoEstadoVenta === 'ANULADA'
            ? 'NC parcial emitida y la venta quedó totalmente anulada.'
            : 'NC parcial emitida y anulación parcial aplicada.';

        return $out;
    }

    private function resolveFacturaOrigen(int $ventaId, int $facturaOrigenId): array
    {
        if ($facturaOrigenId <= 0) {
            throw new RuntimeException('Debes indicar explicitamente la factura origen para la NC.');
        }

        $facturaOrigen = $this->repository->findFacturaById($facturaOrigenId);
        if (!$facturaOrigen) {
            throw new RuntimeException('No se encontro la factura origen seleccionada.');
        }

        if ((int)($facturaOrigen['venta_id'] ?? 0) !== $ventaId) {
            throw new RuntimeException('La factura origen seleccionada no corresponde a la venta indicada.');
        }

        if (!flus_facturacion_factura_apta_para_nc($facturaOrigen)) {
            throw new RuntimeException('La factura origen debe estar autorizada fiscalmente y con CAE valido antes de emitir una NC.');
        }

        return $facturaOrigen;
    }

    /**
     * @param array<int,mixed> $items
     * @return array<int,array{item_id:int,cantidad:float}>
     */
    private function normalizePartialRequestItems(array $items): array
    {
        $acc = [];

        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }

            $itemId = (int)($row['item_id'] ?? $row['itemId'] ?? 0);
            $cantidad = round((float)($row['cantidad'] ?? 0), 3);

            if ($itemId === 0 || $cantidad <= 0) {
                continue;
            }

            if (!isset($acc[$itemId])) {
                $acc[$itemId] = 0.0;
            }

            $acc[$itemId] = round($acc[$itemId] + $cantidad, 3);
        }

        ksort($acc, SORT_NUMERIC);

        $out = [];
        foreach ($acc as $itemId => $cantidad) {
            if ($cantidad <= 0) {
                continue;
            }

            $out[] = [
                'item_id' => (int)$itemId,
                'cantidad' => round((float)$cantidad, 3),
            ];
        }

        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function manualLegacyItemsForVenta(int $ventaId): array
    {
        $manualRows = flus_facturacion_manual_items_fetch($this->pdo, $ventaId);
        if ($manualRows === []) {
            return [];
        }

        $items = [];
        foreach ($manualRows as $row) {
            $manualId = (int)($row['id'] ?? 0);
            $key = $this->manualLegacyItemId($manualId);
            if ($key === 0) {
                continue;
            }

            $items[$key] = [
                'id' => $key,
                'producto_id' => null,
                'descripcion' => (string)($row['descripcion'] ?? $row['nombre'] ?? ('Item manual #' . $manualId)),
                'cantidad' => round((float)($row['cantidad'] ?? 0), 3),
                'precio' => round((float)($row['precio_unitario'] ?? $row['precio'] ?? 0), 6),
                'precio_unit_final' => round((float)($row['precio_unitario'] ?? $row['precio'] ?? 0), 6),
                'subtotal' => round((float)($row['subtotal'] ?? 0), 2),
                'descuento_monto' => 0.0,
                'iva_porcentaje' => round((float)($row['iva_porcentaje'] ?? 0), 2),
            ];
        }

        return $items;
    }

    private function manualLegacyItemId(int $manualItemId): int
    {
        return $manualItemId > 0 ? -$manualItemId : 0;
    }

    /**
     * @return array<int,float>
     */
    private function fetchNcCreditedQtyMap(int $facturaOrigenId): array
    {
        if (
            $facturaOrigenId <= 0
            || !flus_table_exists($this->pdo, 'facturas')
            || !flus_table_exists($this->pdo, 'factura_items')
            || !flus_column_exists($this->pdo, 'facturas', 'factura_asociada_id')
        ) {
            return [];
        }

        $sql = "
            SELECT fi.venta_item_id, COALESCE(SUM(fi.cantidad), 0) AS qty
            FROM factura_items fi
            INNER JOIN facturas f ON f.id = fi.factura_id
            WHERE f.factura_asociada_id = ?
              AND fi.venta_item_id IS NOT NULL
        ";

        if (flus_column_exists($this->pdo, 'facturas', 'naturaleza')) {
            $sql .= " AND f.naturaleza = 'NC'";
        }

        if (flus_column_exists($this->pdo, 'facturas', 'estado')) {
            $sql .= " AND COALESCE(f.estado, 'EMITIDA') <> 'ANULADA'";
        }

        $sql .= ' GROUP BY fi.venta_item_id';

        $st = $this->pdo->prepare($sql);
        $st->execute([$facturaOrigenId]);

        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int)$row['venta_item_id']] = round((float)$row['qty'], 3);
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $patch
     */
    private function updateVentaAnulacionMontos(int $ventaAnulacionId, array $patch): void
    {
        if ($ventaAnulacionId <= 0 || !flus_table_exists($this->pdo, 'venta_anulaciones')) {
            return;
        }

        $allowed = ['monto_bruto', 'monto_neto', 'monto_iva', 'monto_total'];
        $sets = [];
        $params = [':id' => $ventaAnulacionId];

        foreach ($allowed as $col) {
            if (!array_key_exists($col, $patch) || !flus_column_exists($this->pdo, 'venta_anulaciones', $col)) {
                continue;
            }

            $sets[] = "`{$col}` = :{$col}";
            $params[':' . $col] = $patch[$col];
        }

        if ($sets === []) {
            return;
        }

        $sql = 'UPDATE venta_anulaciones SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
    }

    /**
     * Carga la alícuota IVA real de un conjunto de productos en una sola query.
     * Evita el N+1 cuando se construyen snapshots de anulación con múltiples items.
     *
     * @param  int[]                $productoIds
     * @return array<int,float>     mapa productoId => iva_porcentaje
     */
    private function fetchProductosIvaPct(array $productoIds): array
    {
        if (
            $productoIds === []
            || !flus_table_exists($this->pdo, 'productos')
            || !flus_column_exists($this->pdo, 'productos', 'iva_porcentaje')
        ) {
            return [];
        }

        $ids = array_unique(array_filter(array_map('intval', $productoIds)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare(
            "SELECT id, iva_porcentaje FROM productos WHERE id IN ({$placeholders})"
        );
        $st->execute($ids);

        $map = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int)$row['id']] = round((float)$row['iva_porcentaje'], 2);
        }

        return $map;
    }

    private function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

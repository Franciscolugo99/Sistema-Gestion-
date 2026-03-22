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
        $anulacionId = 0;
        $facturaOrigenId = null;
        $itemsRestantes = [];
        $venta = [];

        // TX1: crear anulacion pendiente + snapshot
        $this->pdo->beginTransaction();
        try {
            $venta = $this->repository->lockVenta($ventaId);
            if (!$venta) throw new RuntimeException('Venta no encontrada.');
            if ((int)($venta['facturada'] ?? 0) !== 1) throw new RuntimeException('La venta no esta facturada.');
            if (strtoupper((string)($venta['estado'] ?? 'EMITIDA')) === 'ANULADA') throw new RuntimeException('La venta ya esta anulada.');

            $facturaOrigen = $this->repository->findFacturaOrigenByVentaId($ventaId);
            if (!$facturaOrigen) throw new RuntimeException('No se encontro la factura original.');
            $facturaOrigenId = (int)($facturaOrigen['id'] ?? 0) ?: null;

            $stDup = $this->pdo->prepare("SELECT id FROM venta_anulaciones WHERE venta_id = ? AND tipo = 'TOTAL' AND requiere_nc = 1 AND estado <> 'CANCELADA' ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $stDup->execute([$ventaId]);
            $dup = $stDup->fetchColumn();
            if ($dup !== false) throw new RuntimeException('Ya existe una anulacion fiscal total pendiente o aplicada para esta venta.');

            $ventaItems = flus_venta_items_cargar($this->pdo, $ventaId);
            $yaAnulado = flus_venta_items_anulados_map($this->pdo, $ventaId);
            $itemsRestantes = flus_venta_items_restantes($ventaItems, $yaAnulado);

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
                flus_facturacion_insert_dynamic($this->pdo, 'venta_anulacion_items', [
                    'anulacion_id' => $anulacionId,
                    'venta_item_id' => $itemId,
                    'producto_id' => (int)($item['producto_id'] ?? 0),
                    'cantidad_anulada' => $qty,
                    'precio_unitario_snapshot' => $precioUnit,
                    'descuento_monto_snapshot' => round((float)($item['descuento_monto'] ?? 0), 2),
                    'iva_porcentaje_snapshot' => 0.0,
                    'subtotal_snapshot' => $subtotalSnapshot,
                    'subtotal_anulado' => $subtotalAnulado,
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        // Fuera TX: llamar ARCA / demo
        $resultadoNc = $this->notaCreditoService->emitir(EmitirNotaCreditoCommand::fromArray([
            'venta_id' => $ventaId,
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
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
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
            if (flus_column_exists($this->pdo, 'ventas', 'anulado_en')) $sets[] = 'anulado_en = NOW()';
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
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            $this->pdo->beginTransaction();
            try {
                $this->repository->updateVentaAnulacionFiscalState($anulacionId, 'ERROR_POST_ARCA', [
                    'fiscal_error_code' => 'ERROR_POST_ARCA',
                    'fiscal_error_message' => $e->getMessage(),
                ]);
                $this->pdo->commit();
            } catch (Throwable $ignored) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
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
        throw new RuntimeException('NC parcial queda pendiente para la Fase 3.');
    }

    private function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

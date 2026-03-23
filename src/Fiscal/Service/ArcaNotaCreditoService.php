<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/facturacion_lib.php';
require_once __DIR__ . '/../../../src/venta_anulaciones_lib.php';
require_once __DIR__ . '/../../../public/includes/ArcaWsfe.php';
require_once __DIR__ . '/../../../public/includes/ArcaWsaa.php';

final class ArcaNotaCreditoService implements NotaCreditoService
{
    public function __construct(private PDO $pdo, private FacturaFiscalRepository $repository)
    {
    }

    public function emitir(EmitirNotaCreditoCommand $command): EmitirNotaCreditoResult
    {
        $ventaId = $command->ventaId;
        $scope = strtoupper(trim($command->scope)) === 'PARTIAL' ? 'PARTIAL' : 'TOTAL';

        $facturaOrigen = $this->repository->findFacturaOrigenByVentaId($ventaId);
        if (!$facturaOrigen) {
            return EmitirNotaCreditoResult::rejected(
                $command->requestUid,
                $scope,
                'FACTURA_ORIGEN_NO_ENCONTRADA',
                'No se encontró la factura original asociada a la venta.'
            );
        }

        $venta = $this->fetchVenta($ventaId);
        if (!$venta) {
            return EmitirNotaCreditoResult::rejected(
                $command->requestUid,
                $scope,
                'VENTA_NO_ENCONTRADA',
                'No se encontró la venta a anular fiscalmente.'
            );
        }

        $cliente = $this->fetchCliente((int)($facturaOrigen['cliente_id'] ?? $venta['cliente_id'] ?? 0));
        $tipoCbteOrigen = $this->resolverTipoCbteOrigen($facturaOrigen);
        $tipoCbteNc = $this->mapTipoNc($tipoCbteOrigen);

        if ($tipoCbteNc <= 0) {
            return EmitirNotaCreditoResult::rejected(
                $command->requestUid,
                $scope,
                'TIPO_CBTE_ORIGEN_INVALIDO',
                'No se pudo mapear el tipo de comprobante original a Nota de Crédito.'
            );
        }

        $modoOperacion = flus_facturacion_normalizar_modo((string)($facturaOrigen['modo'] ?? $command->modo ?: 'demo'));
        $tipoStrNc = obtenerNombreTipoComprobante($tipoCbteNc);
        $puntoVenta = (int)($facturaOrigen['punto_venta'] ?? 0);
        $numero = max(1, flus_facturacion_numero_local_siguiente($this->pdo, $puntoVenta, $tipoStrNc, $modoOperacion));

        if ($modoOperacion !== 'demo') {
            flus_facturacion_arca_assert_emitible($this->pdo, $modoOperacion);

            $ultimo = ArcaWsfe::getUltimoAutorizado($puntoVenta, $tipoCbteNc);
            if ($ultimo !== null) {
                $numero = max($numero, $ultimo + 1);
            }
        }

        try {
            $payload = $scope === 'PARTIAL'
                ? $this->buildPartialFacturaPayload($facturaOrigen, $venta, $command, $tipoCbteNc)
                : $this->buildTotalFacturaPayload($facturaOrigen, $ventaId, $command, $tipoCbteNc);
        } catch (Throwable $e) {
            return EmitirNotaCreditoResult::rejected(
                $command->requestUid,
                $scope,
                'NC_BUILD_ERROR',
                $e->getMessage()
            );
        }

        $header = $this->buildFacturaHeader(
            $facturaOrigen,
            $venta,
            $cliente,
            $tipoCbteNc,
            $numero,
            $modoOperacion,
            $command->ventaAnulacionId,
            $payload['totals']
        );
        $items = $payload['items'];
        $request = $this->buildRequest($facturaOrigen, $cliente, $tipoCbteOrigen, $tipoCbteNc, $numero, $header, $items);

        if ($modoOperacion === 'demo') {
            $header['cae'] = 'DEMO' . str_pad((string)$numero, 14, '0', STR_PAD_LEFT);
            $header['cae_vto'] = date('Y-m-d', strtotime('+10 days'));

            return EmitirNotaCreditoResult::approved(
                $command->requestUid,
                $scope,
                $header,
                $items,
                $request,
                ['demo' => true]
            );
        }

        try {
            $rawResponse = $this->solicitarCaeNc($request);
        } catch (Throwable $e) {
            return EmitirNotaCreditoResult::rejected(
                $command->requestUid,
                $scope,
                'ARCA_ERROR',
                flus_facturacion_humanizar_error_arca($e->getMessage())
            );
        }

        $cae = (string)($rawResponse['cae'] ?? '');
        if ($cae === '') {
            return EmitirNotaCreditoResult::rejected(
                $command->requestUid,
                $scope,
                'ARCA_RECHAZADA',
                flus_facturacion_humanizar_error_arca((string)($rawResponse['error'] ?? 'Comprobante rechazado por ARCA.'))
            );
        }

        $header['numero'] = (int)($rawResponse['numero'] ?? $numero);
        $header['cae'] = $cae;
        $header['cae_vto'] = flus_facturacion_normalizar_cae_vto((string)($rawResponse['vencimiento'] ?? ''));

        return EmitirNotaCreditoResult::approved(
            $command->requestUid,
            $scope,
            $header,
            $items,
            $request,
            $rawResponse
        );
    }

    private function fetchVenta(int $ventaId): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM ventas WHERE id = ? LIMIT 1');
        $st->execute([$ventaId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return is_array($row) ? $row : null;
    }

    private function fetchCliente(int $clienteId): ?array
    {
        if ($clienteId <= 0 || !flus_table_exists($this->pdo, 'clientes')) {
            return null;
        }
        $st = $this->pdo->prepare('SELECT * FROM clientes WHERE id = ? LIMIT 1');
        $st->execute([$clienteId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return is_array($row) ? $row : null;
    }

    private function resolverTipoCbteOrigen(array $facturaOrigen): int
    {
        $tipoCbte = (int)($facturaOrigen['tipo_cbte'] ?? 0);
        if ($tipoCbte > 0) {
            return $tipoCbte;
        }
        $map = ['FA' => 1, 'FB' => 6, 'FC' => 11, 'NCA' => 3, 'NCB' => 8, 'NCC' => 13];
        return $map[strtoupper(trim((string)($facturaOrigen['tipo'] ?? '')))] ?? 0;
    }

    private function mapTipoNc(int $tipoCbteOrigen): int
    {
        return match ($tipoCbteOrigen) {
            1 => 3,
            6 => 8,
            11 => 13,
            default => 0,
        };
    }

    private function buildFacturaHeader(
        array $facturaOrigen,
        array $venta,
        ?array $cliente,
        int $tipoCbteNc,
        int $numero,
        string $modoOperacion,
        int $ventaAnulacionId,
        array $totals
    ): array {
        $tipoStrNc = obtenerNombreTipoComprobante($tipoCbteNc);
        $ts = date('Y-m-d H:i:s');
        $doc = determinarDocumentoCliente($cliente, $cliente === null);
        $condIva = determinarCondicionIvaReceptorAfip($cliente, $cliente === null);

        return [
            'venta_id' => (int)$venta['id'],
            'venta_anulacion_id' => $ventaAnulacionId,
            'factura_asociada_id' => (int)($facturaOrigen['id'] ?? 0),
            'cliente_id' => (int)($facturaOrigen['cliente_id'] ?? $venta['cliente_id'] ?? 0) ?: null,
            'naturaleza' => 'NC',
            'tipo' => $tipoStrNc,
            'tipo_cbte' => $tipoCbteNc,
            'punto_venta' => (int)$facturaOrigen['punto_venta'],
            'numero' => $numero,
            'fecha' => $ts,
            'importe_neto' => round((float)($totals['neto'] ?? 0), 2),
            'importe_iva' => round((float)($totals['iva'] ?? 0), 2),
            'importe_exento' => round((float)($totals['exento'] ?? 0), 2),
            'importe_no_gravado' => round((float)($totals['no_gravado'] ?? 0), 2),
            'total' => round((float)($totals['total'] ?? 0), 2),
            'estado' => 'EMITIDA',
            'modo' => flus_facturacion_facturas_modo_value($this->pdo, $modoOperacion),
            'creado_en' => $ts,
            'comprobante_asoc_tipo_cbte' => $this->resolverTipoCbteOrigen($facturaOrigen),
            'comprobante_asoc_punto_venta' => (int)$facturaOrigen['punto_venta'],
            'comprobante_asoc_numero' => (int)$facturaOrigen['numero'],
            'comprobante_asoc_cuit' => flus_facturacion_cuit_emisor([]),
            'doc_tipo' => $doc['tipo'],
            'doc_numero' => $doc['numero'],
            'condicion_iva_receptor_id' => $condIva,
            'moneda_id' => (string)($facturaOrigen['moneda_id'] ?? 'PES'),
            'moneda_cotiz' => (float)($facturaOrigen['moneda_cotiz'] ?? 1),
        ];
    }

    private function buildFacturaItems(array $facturaOrigen, int $ventaId, EmitirNotaCreditoCommand $command, int $tipoCbteNc): array
    {
        $existing = $this->repository->findFacturaItems((int)($facturaOrigen['id'] ?? 0));
        if ($existing !== []) {
            $out = [];
            foreach ($existing as $idx => $row) {
                $out[] = [
                    'linea_orden' => $idx + 1,
                    'origen_tipo' => 'ANULACION',
                    'snapshot_source' => (string)($row['snapshot_source'] ?? 'ORIGINAL'),
                    'venta_item_id' => $row['venta_item_id'] ?? null,
                    'venta_anulacion_item_id' => null,
                    'producto_id' => $row['producto_id'] ?? null,
                    'codigo_snapshot' => $row['codigo_snapshot'] ?? null,
                    'descripcion_snapshot' => $row['descripcion_snapshot'] ?? ('NC total venta #' . $ventaId),
                    'cantidad' => round((float)($row['cantidad'] ?? 1), 3),
                    'precio_unitario_bruto' => round((float)($row['precio_unitario_bruto'] ?? 0), 6),
                    'descuento_total' => (float)($row['descuento_total'] ?? 0),
                    'iva_porcentaje' => round((float)($row['iva_porcentaje'] ?? 0), 2),
                    'neto_gravado' => (float)($row['neto_gravado'] ?? 0),
                    'iva_importe' => (float)($row['iva_importe'] ?? 0),
                    'subtotal_total' => (float)($row['subtotal_total'] ?? 0),
                ];
            }

            return $out;
        }

        if (!$command->rebuildOriginalItemsIfMissing) {
            throw new RuntimeException('La factura original no tiene factura_items y la reconstrucción automática está deshabilitada.');
        }

        $manual = flus_facturacion_manual_items_fetch($this->pdo, $ventaId);
        if ($manual !== []) {
            $out = [];
            foreach ($manual as $idx => $row) {
                $subtotal = round((float)($row['subtotal'] ?? 0), 6);
                $ivaPct = in_array($tipoCbteNc, [13], true) ? 0.0 : round((float)($row['iva_porcentaje'] ?? 0), 2);
                $neto = $ivaPct > 0 ? ($subtotal / (1 + $ivaPct / 100)) : $subtotal;
                $iva = $subtotal - $neto;

                $out[] = [
                    'linea_orden' => $idx + 1,
                    'origen_tipo' => 'ANULACION',
                    'snapshot_source' => 'RECONSTRUIDO',
                    'venta_item_id' => null,
                    'venta_anulacion_item_id' => null,
                    'producto_id' => null,
                    'codigo_snapshot' => (string)($row['codigo'] ?? ''),
                    'descripcion_snapshot' => (string)($row['descripcion'] ?? 'NC venta #' . $ventaId),
                    'cantidad' => round((float)($row['cantidad'] ?? 1), 3),
                    'precio_unitario_bruto' => round((float)($row['precio_unitario'] ?? 0), 6),
                    'descuento_total' => 0.0,
                    'iva_porcentaje' => $ivaPct,
                    'neto_gravado' => $neto,
                    'iva_importe' => $iva,
                    'subtotal_total' => $subtotal,
                ];
            }

            $this->assertLegacyRebuildWithinTolerance($out, $facturaOrigen, $command->legacyTolerance);
            return $out;
        }

        $itemsVenta = flus_venta_items_cargar($this->pdo, $ventaId);
        if ($itemsVenta === []) {
            throw new RuntimeException('La factura original no tiene factura_items y no hay venta_items suficientes para reconstruirla.');
        }

        $out = [];
        $i = 1;
        foreach ($itemsVenta as $itemId => $row) {
            $subtotal = round((float)($row['subtotal'] ?? 0), 6);
            $qty = max(0.001, round((float)($row['cantidad'] ?? 1), 3));
            $unit = round((float)($row['precio_unit_final'] ?? $row['precio'] ?? ($subtotal / $qty)), 6);

            $ivaPct = in_array($tipoCbteNc, [13], true)
                ? 0.0
                : $this->resolveIvaPctForVentaItem((int)($row['producto_id'] ?? 0), $facturaOrigen);

            $neto = $ivaPct > 0 ? ($subtotal / (1 + $ivaPct / 100)) : $subtotal;
            $iva = $subtotal - $neto;

            $out[] = [
                'linea_orden' => $i++,
                'origen_tipo' => 'ANULACION',
                'snapshot_source' => 'RECONSTRUIDO',
                'venta_item_id' => (int)$itemId,
                'venta_anulacion_item_id' => null,
                'producto_id' => $row['producto_id'] ?? null,
                'codigo_snapshot' => null,
                'descripcion_snapshot' => (string)($row['descripcion'] ?? 'NC venta #' . $ventaId),
                'cantidad' => $qty,
                'precio_unitario_bruto' => $unit,
                'descuento_total' => round((float)($row['descuento_monto'] ?? 0), 6),
                'iva_porcentaje' => $ivaPct,
                'neto_gravado' => $neto,
                'iva_importe' => $iva,
                'subtotal_total' => $subtotal,
            ];
        }

        $this->assertLegacyRebuildWithinTolerance($out, $facturaOrigen, $command->legacyTolerance);
        return $out;
    }

    private function resolveIvaPctForVentaItem(int $productoId, array $facturaOrigen): float
    {
        if ($productoId > 0 && flus_table_exists($this->pdo, 'productos') && flus_column_exists($this->pdo, 'productos', 'iva_porcentaje')) {
            $st = $this->pdo->prepare('SELECT iva_porcentaje FROM productos WHERE id = ? LIMIT 1');
            $st->execute([$productoId]);
            $pct = $st->fetchColumn();
            if ($pct !== false) {
                return round((float)$pct, 2);
            }
        }

        $inferido = $this->inferLegacySingleIvaPct($facturaOrigen);
        if ($inferido === null) {
            throw new RuntimeException(
                'La factura original no tiene factura_items y no se pudo inferir con seguridad la alícuota IVA real de los items legacy.'
            );
        }

        return $inferido;
    }

    private function buildRequest(
        array $facturaOrigen,
        ?array $cliente,
        int $tipoCbteOrigen,
        int $tipoCbteNc,
        int $numero,
        array $header,
        array $items
    ): array {
        $doc = determinarDocumentoCliente($cliente, $cliente === null);
        $condIva = determinarCondicionIvaReceptorAfip($cliente, $cliente === null);

        $req = [
            'tipo_cbte' => $tipoCbteNc,
            'punto_venta' => (int)$header['punto_venta'],
            'numero' => $numero,
            'concepto' => 1,
            'tipo_doc' => $doc['tipo'],
            'nro_doc' => $doc['numero'],
            'fecha' => date('Y-m-d'),
            'importe_total' => (float)$header['total'],
            'importe_neto' => (float)$header['importe_neto'],
            'importe_iva' => (float)$header['importe_iva'],
            'importe_exento' => (float)$header['importe_exento'],
            'importe_no_gravado' => (float)$header['importe_no_gravado'],
            'moneda_id' => (string)$header['moneda_id'],
            'moneda_cotiz' => (float)$header['moneda_cotiz'],
            'condicion_iva_receptor_id' => $condIva,
            'cbtes_asoc' => [[
                'tipo' => $tipoCbteOrigen,
                'pto_vta' => (int)$facturaOrigen['punto_venta'],
                'nro' => (int)$facturaOrigen['numero'],
                'cuit' => flus_facturacion_cuit_emisor([]),
            ]],
        ];

        if ((float)$header['importe_iva'] > 0) {
            $req['iva'] = $this->groupIvaForRequest($items);
        }

        return $req;
    }

    private function groupIvaForRequest(array $items): array
    {
        $map = [];
        foreach ($items as $it) {
            $pct = round((float)($it['iva_porcentaje'] ?? 0), 1);
            if ($pct <= 0) {
                continue;
            }
            $id = obtenerIdAlicuotaAfip($pct);
            $key = (string)$id;
            if (!isset($map[$key])) {
                $map[$key] = ['id' => $id, 'base' => 0.0, 'importe' => 0.0];
            }
            $map[$key]['base'] += (float)($it['neto_gravado'] ?? 0);
            $map[$key]['importe'] += (float)($it['iva_importe'] ?? 0);
        }
        foreach ($map as &$row) {
            $row['base'] = round($row['base'], 2);
            $row['importe'] = round($row['importe'], 2);
        }
        unset($row);
        return array_values($map);
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,totals:array<string,float>}
     */
    private function buildTotalFacturaPayload(array $facturaOrigen, int $ventaId, EmitirNotaCreditoCommand $command, int $tipoCbteNc): array
    {
        $items = $this->buildFacturaItems($facturaOrigen, $ventaId, $command, $tipoCbteNc);
        return $this->finalizeLines($items);
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,totals:array<string,float>}
     */
    private function buildPartialFacturaPayload(array $facturaOrigen, array $venta, EmitirNotaCreditoCommand $command, int $tipoCbteNc): array
    {
        if ($command->partialItems === []) {
            throw new RuntimeException('Debe indicar al menos un item para la NC parcial.');
        }

        $baseItems = $this->buildFacturaItems($facturaOrigen, (int)$venta['id'], $command, $tipoCbteNc);

        $baseByVentaItem = [];
        foreach ($baseItems as $row) {
            $ventaItemId = (int)($row['venta_item_id'] ?? 0);
            if ($ventaItemId > 0) {
                $baseByVentaItem[$ventaItemId] = $row;
            }
        }

        if ($baseByVentaItem === []) {
            throw new RuntimeException(
                'La factura original no tiene líneas fiscales vinculadas a venta_item_id. No es seguro emitir NC parcial automática.'
            );
        }

        $creditedQtyMap = $this->fetchNcCreditedQtyMap((int)($facturaOrigen['id'] ?? 0));
        $snapshotMap = $this->fetchVentaAnulacionItemMap($command->ventaAnulacionId);

        $out = [];
        foreach ($command->partialItems as $req) {
            $ventaItemId = (int)$req['item_id'];
            $cantidadSolicitada = round((float)$req['cantidad'], 3);

            if (!isset($baseByVentaItem[$ventaItemId])) {
                throw new RuntimeException('El item #' . $ventaItemId . ' no está disponible para NC parcial fiscal segura.');
            }

            $base = $baseByVentaItem[$ventaItemId];
            $cantidadOriginal = round((float)($base['cantidad'] ?? 0), 3);
            if ($cantidadOriginal <= 0) {
                throw new RuntimeException('El item #' . $ventaItemId . ' tiene una cantidad original inválida para NC parcial.');
            }

            $cantidadYaAcreditada = round((float)($creditedQtyMap[$ventaItemId] ?? 0), 3);
            $cantidadDisponible = round($cantidadOriginal - $cantidadYaAcreditada, 3);

            if ($cantidadDisponible <= 0.0009) {
                throw new RuntimeException('El item #' . $ventaItemId . ' ya no tiene saldo fiscal disponible para acreditar.');
            }

            if ($cantidadSolicitada > ($cantidadDisponible + 0.0009)) {
                throw new RuntimeException(
                    'La cantidad solicitada para el item #' . $ventaItemId
                    . ' supera el saldo fiscal disponible (' . $cantidadDisponible . ').'
                );
            }

            $cantidadAplicada = min($cantidadSolicitada, $cantidadDisponible);
            $factor = $cantidadAplicada / $cantidadOriginal;

            $out[] = [
                'linea_orden' => count($out) + 1,
                'origen_tipo' => 'ANULACION',
                'snapshot_source' => (string)($base['snapshot_source'] ?? 'ORIGINAL'),
                'venta_item_id' => $ventaItemId,
                'venta_anulacion_item_id' => $snapshotMap[$ventaItemId]['id'] ?? null,
                'producto_id' => $base['producto_id'] ?? ($snapshotMap[$ventaItemId]['producto_id'] ?? null),
                'codigo_snapshot' => $base['codigo_snapshot'] ?? null,
                'descripcion_snapshot' => $base['descripcion_snapshot'] ?? ('NC parcial venta #' . (int)$venta['id']),
                'cantidad' => round($cantidadAplicada, 3),
                'precio_unitario_bruto' => round((float)($base['precio_unitario_bruto'] ?? 0), 6),
                'descuento_total' => (float)($base['descuento_total'] ?? 0) * $factor,
                'iva_porcentaje' => round((float)($base['iva_porcentaje'] ?? 0), 2),
                'neto_gravado' => (float)($base['neto_gravado'] ?? 0) * $factor,
                'iva_importe' => (float)($base['iva_importe'] ?? 0) * $factor,
                'subtotal_total' => (float)($base['subtotal_total'] ?? 0) * $factor,
            ];
        }

        if ($out === []) {
            throw new RuntimeException('No quedaron líneas válidas para emitir la NC parcial.');
        }

        return $this->finalizeLines($out);
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array{items:array<int,array<string,mixed>>,totals:array<string,float>}
     */
    private function finalizeLines(array $items): array
    {
        $out = [];
        $neto = 0.0;
        $iva = 0.0;
        $exento = 0.0;
        $noGravado = 0.0;
        $total = 0.0;

        foreach (array_values($items) as $idx => $row) {
            $subtotal = round((float)($row['subtotal_total'] ?? 0), 2);
            $ivaImporte = round((float)($row['iva_importe'] ?? 0), 2);
            $netoGravado = round((float)($row['neto_gravado'] ?? 0), 2);

            $delta = round($subtotal - ($netoGravado + $ivaImporte), 2);
            if (abs($delta) <= 0.02) {
                $netoGravado = round($netoGravado + $delta, 2);
            }

            $line = $row;
            $line['linea_orden'] = $idx + 1;
            $line['cantidad'] = round((float)($row['cantidad'] ?? 0), 3);
            $line['precio_unitario_bruto'] = round((float)($row['precio_unitario_bruto'] ?? 0), 6);
            $line['descuento_total'] = round((float)($row['descuento_total'] ?? 0), 2);
            $line['iva_porcentaje'] = round((float)($row['iva_porcentaje'] ?? 0), 2);
            $line['neto_gravado'] = $netoGravado;
            $line['iva_importe'] = $ivaImporte;
            $line['subtotal_total'] = $subtotal;

            $out[] = $line;

            $neto += $netoGravado;
            $iva += $ivaImporte;
            $total += $subtotal;
        }

        return [
            'items' => $out,
            'totals' => [
                'neto' => round($neto, 2),
                'iva' => round($iva, 2),
                'exento' => round($exento, 2),
                'no_gravado' => round($noGravado, 2),
                'total' => round($total, 2),
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchVentaAnulacionItemMap(int $ventaAnulacionId): array
    {
        if ($ventaAnulacionId <= 0 || !flus_table_exists($this->pdo, 'venta_anulacion_items')) {
            return [];
        }

        $st = $this->pdo->prepare('SELECT * FROM venta_anulacion_items WHERE anulacion_id = ? ORDER BY id ASC');
        $st->execute([$ventaAnulacionId]);

        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $ventaItemId = (int)($row['venta_item_id'] ?? 0);
            if ($ventaItemId > 0) {
                $map[$ventaItemId] = $row;
            }
        }

        return $map;
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

        $sql .= " GROUP BY fi.venta_item_id";

        $st = $this->pdo->prepare($sql);
        $st->execute([$facturaOrigenId]);

        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int)$row['venta_item_id']] = round((float)$row['qty'], 3);
        }

        return $map;
    }

    private function inferLegacySingleIvaPct(array $facturaOrigen): ?float
    {
        $neto = round((float)($facturaOrigen['importe_neto'] ?? 0), 2);
        $iva = round((float)($facturaOrigen['importe_iva'] ?? 0), 2);

        if ($iva <= 0.0009) {
            return 0.0;
        }

        if ($neto <= 0.0009) {
            return null;
        }

        $efectiva = round(($iva / $neto) * 100, 2);
        foreach ([2.5, 5.0, 10.5, 21.0, 27.0] as $alicuota) {
            if (abs($efectiva - $alicuota) <= 0.20) {
                return $alicuota;
            }
        }

        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function assertLegacyRebuildWithinTolerance(array $items, array $facturaOrigen, float $tolerance): void
    {
        $final = $this->finalizeLines($items);
        $totals = $final['totals'];

        $checks = [
            'total' => [(float)($facturaOrigen['total'] ?? 0), (float)($totals['total'] ?? 0)],
            'neto' => [(float)($facturaOrigen['importe_neto'] ?? 0), (float)($totals['neto'] ?? 0)],
            'iva' => [(float)($facturaOrigen['importe_iva'] ?? 0), (float)($totals['iva'] ?? 0)],
        ];

        foreach ($checks as $label => [$esperado, $reconstruido]) {
            if (abs(round($esperado - $reconstruido, 2)) > $tolerance) {
                throw new RuntimeException(
                    'La factura original no tiene factura_items y la reconstrucción legacy no cerró dentro de tolerancia para '
                    . $label . ' (esperado ' . round($esperado, 2) . ', reconstruido ' . round($reconstruido, 2) . ').'
                );
            }
        }
    }

    private function solicitarCaeNc(array $comprobante): array
    {
        $env = flus_facturacion_arca_env_actual();
        $wsdl = $env === 'homo'
            ? 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL'
            : 'https://servicios1.afip.gov.ar/wsfev1/service.asmx?WSDL';
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => defined('FLUS_ARCA_SSL_VERIFY') ? (bool)FLUS_ARCA_SSL_VERIFY : true,
                'verify_peer_name' => defined('FLUS_ARCA_SSL_VERIFY') ? (bool)FLUS_ARCA_SSL_VERIFY : true,
            ]
        ]);
        $client = new SoapClient($wsdl, [
            'trace' => true,
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_BOTH,
            'connection_timeout' => 30,
            'stream_context' => $ctx,
            'soap_version' => SOAP_1_2,
        ]);
        $ta = ArcaWsaa::getTA('wsfe');
        if (!$ta) {
            throw new RuntimeException(ArcaWsaa::getLastError() ?: 'No se pudo obtener TA para WSFE.');
        }
        $auth = [
            'Token' => $ta['token'],
            'Sign' => $ta['sign'],
            'Cuit' => preg_replace('/\D/', '', (string)FLUS_ARCA_CUIT),
        ];
        $detalle = [
            'Concepto' => (int)$comprobante['concepto'],
            'DocTipo' => (int)$comprobante['tipo_doc'],
            'DocNro' => (int)preg_replace('/\D/', '', (string)$comprobante['nro_doc']),
            'CbteDesde' => (int)$comprobante['numero'],
            'CbteHasta' => (int)$comprobante['numero'],
            'CbteFch' => date('Ymd', strtotime((string)$comprobante['fecha'])),
            'ImpTotal' => round((float)$comprobante['importe_total'], 2),
            'ImpTotConc' => round((float)$comprobante['importe_no_gravado'], 2),
            'ImpNeto' => round((float)$comprobante['importe_neto'], 2),
            'ImpOpEx' => round((float)$comprobante['importe_exento'], 2),
            'ImpIVA' => round((float)$comprobante['importe_iva'], 2),
            'ImpTrib' => 0,
            'MonId' => (string)$comprobante['moneda_id'],
            'MonCotiz' => (float)$comprobante['moneda_cotiz'],
            'CondicionIVAReceptorId' => (int)$comprobante['condicion_iva_receptor_id'],
        ];
        if (!empty($comprobante['iva'])) {
            $detalle['Iva'] = ['AlicIva' => array_map(static fn(array $row) => [
                'Id' => (int)$row['id'],
                'BaseImp' => round((float)$row['base'], 2),
                'Importe' => round((float)$row['importe'], 2),
            ], $comprobante['iva'])];
        }
        if (!empty($comprobante['cbtes_asoc'])) {
            $detalle['CbtesAsoc'] = ['CbteAsoc' => array_map(static fn(array $row) => [
                'Tipo' => (int)$row['tipo'],
                'PtoVta' => (int)$row['pto_vta'],
                'Nro' => (int)$row['nro'],
                'Cuit' => isset($row['cuit']) && $row['cuit'] !== '' ? (int)preg_replace('/\D/', '', (string)$row['cuit']) : null,
            ], $comprobante['cbtes_asoc'])];
        }
        $params = [
            'Auth' => $auth,
            'FeCAEReq' => [
                'FeCabReq' => ['CantReg' => 1, 'PtoVta' => (int)$comprobante['punto_venta'], 'CbteTipo' => (int)$comprobante['tipo_cbte']],
                'FeDetReq' => ['FECAEDetRequest' => $detalle],
            ],
        ];
        $result = $client->FECAESolicitar($params);
        $arr = json_decode(json_encode($result), true) ?: [];
        $det = $arr['FECAESolicitarResult']['FeDetResp']['FECAEDetResponse'] ?? null;
        if (is_array($det) && array_is_list($det)) {
            $det = $det[0] ?? null;
        }
        if (!is_array($det)) {
            return ['error' => 'Respuesta vacía de ARCA.'];
        }
        if (($det['Resultado'] ?? '') !== 'A' || empty($det['CAE'])) {
            $obs = $det['Observaciones']['Obs'] ?? null;
            if (is_array($obs) && array_is_list($obs)) {
                $obs = $obs[0] ?? null;
            }
            $msg = is_array($obs) ? trim('[' . ($obs['Code'] ?? '') . '] ' . ($obs['Msg'] ?? '')) : 'Comprobante rechazado por ARCA.';
            return ['error' => $msg, 'raw' => $arr];
        }
        return [
            'cae' => (string)$det['CAE'],
            'vencimiento' => (string)($det['CAEFchVto'] ?? ''),
            'numero' => (int)($det['CbteDesde'] ?? $comprobante['numero']),
            'raw' => $arr,
        ];
    }
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/../../facturacion_lib.php';
require_once __DIR__ . '/../../facturacion_manual_lib.php';
require_once __DIR__ . '/../../venta_anulaciones_lib.php';
require_once __DIR__ . '/../../db_schema.php';
require_once __DIR__ . '/../../../public/includes/ArcaWsaa.php';
require_once __DIR__ . '/../../../public/includes/ArcaWsfe.php';

final class FlusNotaCreditoTotalService implements NotaCreditoService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function emitir(EmitirNotaCreditoCommand $command): EmitirNotaCreditoResult
    {
        if ($command->scope !== 'TOTAL') {
            return EmitirNotaCreditoResult::rejected(
                $command->requestUid,
                $command->scope,
                'UNSUPPORTED_SCOPE',
                'La Fase 2 solo soporta Nota de Credito total.'
            );
        }

        try {
            $facturaOrigen = $this->resolverFacturaOrigen($command->ventaId);
            $tipoCbteOriginal = $this->resolverTipoCbteOriginal($facturaOrigen);
            $tipoCbteNc = $this->mapearTipoNc($tipoCbteOriginal);
            $tipoStrNc = obtenerNombreTipoComprobante($tipoCbteNc);

            $modoOperacion = flus_facturacion_normalizar_modo((string)($facturaOrigen['modo'] ?? $command->modo ?? 'demo'));
            $modoFactura = flus_facturacion_facturas_modo_value($this->pdo, $modoOperacion);
            $puntoVenta = max(1, (int)($facturaOrigen['punto_venta'] ?? 1));
            $numero = max(1, flus_facturacion_numero_local_siguiente($this->pdo, $puntoVenta, $tipoStrNc, $modoOperacion));

            if ($modoOperacion !== 'demo') {
                flus_facturacion_assert_facturas_scope_compatible($this->pdo, $puntoVenta, $tipoStrNc, $numero, $modoOperacion);

                $envEsperado = flus_facturacion_arca_env_esperado($modoOperacion);
                $envActual = flus_facturacion_arca_env_actual();
                if ($envEsperado !== '' && $envActual !== $envEsperado) {
                    throw new RuntimeException(
                        'El modo ' . flus_facturacion_modo_label($modoOperacion)
                        . ' requiere FLUS_ARCA_ENV=' . strtoupper($envEsperado)
                        . ' pero hoy esta en ' . strtoupper($envActual) . '.'
                    );
                }

                $ultimoAutorizado = ArcaWsfe::getUltimoAutorizado($puntoVenta, $tipoCbteNc);
                if ($ultimoAutorizado !== null) {
                    $numero = max($numero, $ultimoAutorizado + 1);
                }
            }

            $clienteInfo = $this->resolverClienteFactura($facturaOrigen);
            $importes = $this->resolverImportesFactura($facturaOrigen);
            $items = $this->resolverItemsNcTotal((int)$facturaOrigen['id'], $command->ventaId, $command->ventaAnulacionId, $facturaOrigen, $importes);
            $ivaDetalle = $this->resolverIvaDetalle($items, $importes, $tipoCbteNc);

            $docTipo = isset($facturaOrigen['doc_tipo']) && (int)$facturaOrigen['doc_tipo'] > 0
                ? (int)$facturaOrigen['doc_tipo']
                : (int)$clienteInfo['doc_tipo'];
            $docNumero = trim((string)($facturaOrigen['doc_numero'] ?? ''));
            if ($docNumero === '') {
                $docNumero = (string)$clienteInfo['doc_numero'];
            }
            $condicionIvaReceptorId = isset($facturaOrigen['condicion_iva_receptor_id']) && (int)$facturaOrigen['condicion_iva_receptor_id'] > 0
                ? (int)$facturaOrigen['condicion_iva_receptor_id']
                : (int)$clienteInfo['condicion_iva_receptor_id'];

            $cuitEmisor = flus_facturacion_cuit_emisor([]);
            $request = [
                'tipo_cbte' => $tipoCbteNc,
                'punto_venta' => $puntoVenta,
                'numero' => $numero,
                'concepto' => 1,
                'fecha' => date('Y-m-d'),
                'tipo_doc' => $docTipo,
                'nro_doc' => $docNumero,
                'condicion_iva_receptor_id' => $condicionIvaReceptorId,
                'importe_total' => $importes['total'],
                'importe_neto' => $importes['neto'],
                'importe_iva' => $importes['iva'],
                'importe_exento' => $importes['exento'],
                'importe_no_gravado' => $importes['no_gravado'],
                'moneda_id' => (string)($facturaOrigen['moneda_id'] ?? 'PES'),
                'moneda_cotiz' => isset($facturaOrigen['moneda_cotiz']) ? (float)$facturaOrigen['moneda_cotiz'] : 1.0,
                'cbtes_asoc' => [[
                    'tipo' => $tipoCbteOriginal,
                    'punto_venta' => (int)($facturaOrigen['punto_venta'] ?? 0),
                    'numero' => (int)($facturaOrigen['numero'] ?? 0),
                    'cuit' => $cuitEmisor,
                ]],
            ];

            if ($ivaDetalle !== []) {
                $request['iva'] = $ivaDetalle;
            }

            $cae = null;
            $caeVto = null;
            $rawResponse = [];

            if ($modoOperacion === 'demo') {
                $cae = 'DEMO' . str_pad((string)$numero, 14, '0', STR_PAD_LEFT);
                $caeVto = date('Y-m-d', strtotime('+10 days'));
                $rawResponse = [
                    'demo' => true,
                    'cae' => $cae,
                    'vencimiento' => $caeVto,
                    'numero' => $numero,
                ];
            } else {
                $resultado = $this->solicitarCaeNc($request);
                if ($resultado === null) {
                    return EmitirNotaCreditoResult::rejected(
                        $command->requestUid,
                        $command->scope,
                        $this->extraerCodigoError($this->lastError),
                        flus_facturacion_humanizar_error_arca($this->lastError)
                    );
                }

                $cae = (string)($resultado['cae'] ?? '');
                $caeVto = flus_facturacion_normalizar_cae_vto((string)($resultado['vencimiento'] ?? ''));
                $numero = (int)($resultado['numero'] ?? $numero);
                $rawResponse = $resultado['raw_response'] ?? $resultado;
            }

            $timestamp = date('Y-m-d H:i:s');
            $header = [
                'venta_id' => $command->ventaId,
                'cliente_id' => isset($facturaOrigen['cliente_id']) ? (int)$facturaOrigen['cliente_id'] : null,
                'naturaleza' => 'NC',
                'tipo' => $tipoStrNc,
                'tipo_cbte' => $tipoCbteNc,
                'venta_anulacion_id' => $command->ventaAnulacionId,
                'factura_asociada_id' => (int)$facturaOrigen['id'],
                'comprobante_asoc_tipo_cbte' => $tipoCbteOriginal,
                'comprobante_asoc_punto_venta' => (int)($facturaOrigen['punto_venta'] ?? 0),
                'comprobante_asoc_numero' => (int)($facturaOrigen['numero'] ?? 0),
                'comprobante_asoc_cuit' => $cuitEmisor !== '' ? $cuitEmisor : null,
                'doc_tipo' => $docTipo,
                'doc_numero' => $docNumero,
                'condicion_iva_receptor_id' => $condicionIvaReceptorId,
                'punto_venta' => $puntoVenta,
                'numero' => $numero,
                'fecha' => $timestamp,
                'importe_neto' => $importes['neto'],
                'importe_iva' => $importes['iva'],
                'importe_exento' => $importes['exento'],
                'importe_no_gravado' => $importes['no_gravado'],
                'moneda_id' => (string)($request['moneda_id'] ?? 'PES'),
                'moneda_cotiz' => (float)($request['moneda_cotiz'] ?? 1),
                'total' => $importes['total'],
                'cae' => $cae,
                'cae_vto' => $caeVto,
                'estado' => 'EMITIDA',
                'modo' => $modoFactura,
                'creado_en' => $timestamp,
            ];

            return EmitirNotaCreditoResult::approved(
                $command->requestUid,
                $command->scope,
                $header,
                $items,
                $request,
                $rawResponse
            );
        } catch (Throwable $e) {
            return EmitirNotaCreditoResult::rejected(
                $command->requestUid,
                $command->scope,
                'NC_TOTAL_ERROR',
                $e->getMessage()
            );
        }
    }

    private ?string $lastError = null;

    private function resolverFacturaOrigen(int $ventaId): array
    {
        $sql = 'SELECT * FROM facturas WHERE venta_id = ?';
        if (flus_column_exists($this->pdo, 'facturas', 'naturaleza')) {
            $sql .= " AND naturaleza = 'FACTURA'";
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';

        $st = $this->pdo->prepare($sql);
        $st->execute([$ventaId]);
        $factura = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($factura === []) {
            throw new RuntimeException('No se encontro la factura original para la venta indicada.');
        }
        return $factura;
    }

    private function resolverTipoCbteOriginal(array $facturaOrigen): int
    {
        $tipoCbte = (int)($facturaOrigen['tipo_cbte'] ?? 0);
        if ($tipoCbte > 0) {
            return $tipoCbte;
        }

        return match (strtoupper(trim((string)($facturaOrigen['tipo'] ?? '')))) {
            'FA' => ArcaWsfe::FACTURA_A,
            'FB' => ArcaWsfe::FACTURA_B,
            'FC' => ArcaWsfe::FACTURA_C,
            default => 0,
        };
    }

    private function mapearTipoNc(int $tipoCbteOriginal): int
    {
        return match ($tipoCbteOriginal) {
            ArcaWsfe::FACTURA_A => ArcaWsfe::NOTA_CREDITO_A,
            ArcaWsfe::FACTURA_B => ArcaWsfe::NOTA_CREDITO_B,
            ArcaWsfe::FACTURA_C => ArcaWsfe::NOTA_CREDITO_C,
            default => throw new RuntimeException('El tipo de comprobante original no soporta NC total en esta fase.'),
        };
    }

    private function resolverClienteFactura(array $facturaOrigen): array
    {
        $clienteId = isset($facturaOrigen['cliente_id']) ? (int)$facturaOrigen['cliente_id'] : 0;
        $resuelto = flus_facturacion_resolver_cliente($this->pdo, $clienteId);
        $cliente = is_array($resuelto['cliente'] ?? null) ? $resuelto['cliente'] : null;
        $consumidorFinal = !empty($resuelto['consumidor_final']);

        $doc = determinarDocumentoCliente($cliente, $consumidorFinal);
        return [
            'cliente' => $cliente,
            'doc_tipo' => (int)$doc['tipo'],
            'doc_numero' => (string)$doc['numero'],
            'condicion_iva_receptor_id' => determinarCondicionIvaReceptorAfip($cliente, $consumidorFinal),
        ];
    }

    private function resolverImportesFactura(array $facturaOrigen): array
    {
        $total = round((float)($facturaOrigen['total'] ?? 0), 2);
        $neto = round((float)($facturaOrigen['importe_neto'] ?? 0), 2);
        $iva = round((float)($facturaOrigen['importe_iva'] ?? 0), 2);
        $exento = round((float)($facturaOrigen['importe_exento'] ?? 0), 2);
        $noGravado = round((float)($facturaOrigen['importe_no_gravado'] ?? 0), 2);

        if ($neto <= 0 && $iva <= 0 && $total > 0) {
            $tipoCbteOriginal = $this->resolverTipoCbteOriginal($facturaOrigen);
            if (in_array($tipoCbteOriginal, [ArcaWsfe::FACTURA_C, ArcaWsfe::NOTA_CREDITO_C], true)) {
                $neto = $total;
            } else {
                $neto = round($total / 1.21, 2);
                $iva = round($total - $neto, 2);
            }
        }

        return [
            'total' => $total,
            'neto' => $neto,
            'iva' => $iva,
            'exento' => $exento,
            'no_gravado' => $noGravado,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function resolverItemsNcTotal(int $facturaOrigenId, int $ventaId, int $ventaAnulacionId, array $facturaOrigen, array $importes): array
    {
        if (flus_table_exists($this->pdo, 'factura_items')) {
            $st = $this->pdo->prepare('SELECT * FROM factura_items WHERE factura_id = ? ORDER BY linea_orden ASC, id ASC');
            $st->execute([$facturaOrigenId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($rows !== []) {
                $mapAnulacionItems = $this->mapVentaAnulacionItems($ventaAnulacionId);
                $items = [];
                foreach ($rows as $idx => $row) {
                    $ventaItemId = isset($row['venta_item_id']) ? (int)$row['venta_item_id'] : 0;
                    $items[] = [
                        'linea_orden' => (int)($row['linea_orden'] ?? ($idx + 1)),
                        'origen_tipo' => 'ANULACION',
                        'snapshot_source' => 'ORIGINAL',
                        'venta_item_id' => $ventaItemId > 0 ? $ventaItemId : null,
                        'venta_anulacion_item_id' => $ventaItemId > 0 ? ($mapAnulacionItems[$ventaItemId] ?? null) : null,
                        'producto_id' => isset($row['producto_id']) ? (int)$row['producto_id'] : null,
                        'codigo_snapshot' => $row['codigo_snapshot'] ?? null,
                        'descripcion_snapshot' => (string)($row['descripcion_snapshot'] ?? 'Sin descripcion'),
                        'cantidad' => round((float)($row['cantidad'] ?? 0), 3),
                        'precio_unitario_bruto' => round((float)($row['precio_unitario_bruto'] ?? 0), 6),
                        'descuento_total' => round((float)($row['descuento_total'] ?? 0), 6),
                        'iva_porcentaje' => round((float)($row['iva_porcentaje'] ?? 0), 2),
                        'neto_gravado' => round((float)($row['neto_gravado'] ?? 0), 6),
                        'iva_importe' => round((float)($row['iva_importe'] ?? 0), 6),
                        'subtotal_total' => round((float)($row['subtotal_total'] ?? 0), 6),
                    ];
                }
                return $items;
            }
        }

        $manualItems = flus_facturacion_manual_items_fetch($this->pdo, $ventaId);
        if ($manualItems !== []) {
            return $this->reconstruirItemsDesdeManual($manualItems, $importes);
        }

        return $this->reconstruirItemsDesdeVenta($ventaId, $ventaAnulacionId, $importes);
    }

    /**
     * @return array<int,int>
     */
    private function mapVentaAnulacionItems(int $ventaAnulacionId): array
    {
        if ($ventaAnulacionId <= 0 || !flus_table_exists($this->pdo, 'venta_anulacion_items')) {
            return [];
        }

        $st = $this->pdo->prepare('SELECT id, venta_item_id FROM venta_anulacion_items WHERE anulacion_id = ?');
        $st->execute([$ventaAnulacionId]);
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $ventaItemId = (int)($row['venta_item_id'] ?? 0);
            if ($ventaItemId > 0) {
                $map[$ventaItemId] = (int)($row['id'] ?? 0);
            }
        }
        return $map;
    }

    /**
     * @param array<int,array<string,mixed>> $manualItems
     * @return array<int,array<string,mixed>>
     */
    private function reconstruirItemsDesdeManual(array $manualItems, array $importes): array
    {
        $items = [];
        foreach ($manualItems as $idx => $row) {
            $cantidad = round((float)($row['cantidad'] ?? 0), 3);
            $subtotal = round((float)($row['subtotal'] ?? 0), 6);
            $precio = round((float)($row['precio'] ?? 0), 6);
            $ivaPct = round((float)($row['iva_porcentaje'] ?? 21), 2);
            $neto = $ivaPct > 0 ? round($subtotal / (1 + ($ivaPct / 100)), 6) : $subtotal;
            $iva = round($subtotal - $neto, 6);
            $items[] = [
                'linea_orden' => $idx + 1,
                'origen_tipo' => 'ANULACION',
                'snapshot_source' => 'RECONSTRUIDO',
                'venta_item_id' => null,
                'venta_anulacion_item_id' => null,
                'producto_id' => null,
                'codigo_snapshot' => $row['codigo'] ?? null,
                'descripcion_snapshot' => (string)($row['nombre'] ?? $row['descripcion'] ?? 'Item'),
                'cantidad' => $cantidad,
                'precio_unitario_bruto' => $precio,
                'descuento_total' => 0,
                'iva_porcentaje' => $ivaPct,
                'neto_gravado' => $neto,
                'iva_importe' => $iva,
                'subtotal_total' => $subtotal,
            ];
        }
        return $this->reconciliarItemsConHeader($items, $importes);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function reconstruirItemsDesdeVenta(int $ventaId, int $ventaAnulacionId, array $importes): array
    {
        if (!flus_table_exists($this->pdo, 'venta_items')) {
            return [];
        }

        $ivaProductoExpr = '21';
        if (flus_table_exists($this->pdo, 'productos')) {
            if (flus_column_exists($this->pdo, 'productos', 'iva_porcentaje')) {
                $ivaProductoExpr = 'COALESCE(p.iva_porcentaje, 21)';
            } elseif (flus_column_exists($this->pdo, 'productos', 'iva')) {
                $ivaProductoExpr = 'COALESCE(p.iva, 21)';
            }
        }

        $selectDesc = flus_table_exists($this->pdo, 'productos') && flus_column_exists($this->pdo, 'productos', 'nombre')
            ? 'COALESCE(p.nombre, CONCAT("Item #", vi.id))'
            : 'CONCAT("Item #", vi.id)';
        $selectCode = flus_table_exists($this->pdo, 'productos') && flus_column_exists($this->pdo, 'productos', 'codigo')
            ? 'p.codigo'
            : 'NULL';
        $joinProductos = flus_table_exists($this->pdo, 'productos')
            ? 'LEFT JOIN productos p ON p.id = vi.producto_id'
            : '';

        $st = $this->pdo->prepare(
            "SELECT vi.*, {$selectDesc} AS producto_nombre, {$selectCode} AS producto_codigo, {$ivaProductoExpr} AS producto_iva
             FROM venta_items vi
             {$joinProductos}
             WHERE vi.venta_id = ?
             ORDER BY vi.id ASC"
        );
        $st->execute([$ventaId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $mapAnulacionItems = $this->mapVentaAnulacionItems($ventaAnulacionId);

        $items = [];
        foreach ($rows as $idx => $row) {
            $cantidad = round((float)($row['cantidad'] ?? 0), 3);
            $subtotal = isset($row['subtotal']) ? round((float)$row['subtotal'], 6) : round((float)($row['precio'] ?? 0) * $cantidad, 6);
            $precioUnit = 0.0;
            foreach (['precio_unit_final', 'precio'] as $k) {
                if (isset($row[$k]) && $row[$k] !== null && $row[$k] !== '') {
                    $precioUnit = round((float)$row[$k], 6);
                    break;
                }
            }
            if ($precioUnit <= 0 && $cantidad > 0) {
                $precioUnit = round($subtotal / $cantidad, 6);
            }
            $ivaPct = 0.0;
            if (isset($row['iva_porcentaje']) && $row['iva_porcentaje'] !== null && $row['iva_porcentaje'] !== '') {
                $ivaPct = round((float)$row['iva_porcentaje'], 2);
            } else {
                $ivaPct = round((float)($row['producto_iva'] ?? 21), 2);
            }
            $neto = $ivaPct > 0 ? round($subtotal / (1 + ($ivaPct / 100)), 6) : $subtotal;
            $iva = round($subtotal - $neto, 6);
            $ventaItemId = (int)($row['id'] ?? 0);

            $items[] = [
                'linea_orden' => $idx + 1,
                'origen_tipo' => 'ANULACION',
                'snapshot_source' => 'RECONSTRUIDO',
                'venta_item_id' => $ventaItemId > 0 ? $ventaItemId : null,
                'venta_anulacion_item_id' => $ventaItemId > 0 ? ($mapAnulacionItems[$ventaItemId] ?? null) : null,
                'producto_id' => isset($row['producto_id']) ? (int)$row['producto_id'] : null,
                'codigo_snapshot' => $row['producto_codigo'] ?? null,
                'descripcion_snapshot' => (string)($row['producto_nombre'] ?? ('Item #' . $ventaItemId)),
                'cantidad' => $cantidad,
                'precio_unitario_bruto' => $precioUnit,
                'descuento_total' => isset($row['descuento_monto']) ? round((float)$row['descuento_monto'], 6) : 0.0,
                'iva_porcentaje' => $ivaPct,
                'neto_gravado' => $neto,
                'iva_importe' => $iva,
                'subtotal_total' => $subtotal,
            ];
        }

        return $this->reconciliarItemsConHeader($items, $importes);
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function reconciliarItemsConHeader(array $items, array $importes): array
    {
        if ($items === []) {
            return [];
        }

        $sumTotal = 0.0;
        foreach ($items as $row) {
            $sumTotal += (float)($row['subtotal_total'] ?? 0);
        }
        $sumTotal = round($sumTotal, 2);
        $deltaTotal = round(((float)$importes['total']) - $sumTotal, 2);
        if (abs($deltaTotal) > 0 && abs($deltaTotal) <= 0.02) {
            $last = count($items) - 1;
            $items[$last]['subtotal_total'] = round((float)$items[$last]['subtotal_total'] + $deltaTotal, 6);
            $ivaPct = (float)($items[$last]['iva_porcentaje'] ?? 0);
            if ($ivaPct > 0) {
                $neto = round((float)$items[$last]['subtotal_total'] / (1 + ($ivaPct / 100)), 6);
                $items[$last]['neto_gravado'] = $neto;
                $items[$last]['iva_importe'] = round((float)$items[$last]['subtotal_total'] - $neto, 6);
            } else {
                $items[$last]['neto_gravado'] = round((float)$items[$last]['subtotal_total'], 6);
                $items[$last]['iva_importe'] = 0.0;
            }
        }

        return $items;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array{id:int,base:float,importe:float}>
     */
    private function resolverIvaDetalle(array $items, array $importes, int $tipoCbteNc): array
    {
        if (in_array($tipoCbteNc, [ArcaWsfe::NOTA_CREDITO_C, ArcaWsfe::FACTURA_C], true) || (float)$importes['iva'] <= 0) {
            return [];
        }

        $map = [];
        foreach ($items as $item) {
            $ivaPct = round((float)($item['iva_porcentaje'] ?? 0), 2);
            if ($ivaPct <= 0) {
                continue;
            }
            $alicuotaId = obtenerIdAlicuotaAfip($ivaPct);
            $key = (string)$alicuotaId;
            if (!isset($map[$key])) {
                $map[$key] = ['id' => $alicuotaId, 'base' => 0.0, 'importe' => 0.0];
            }
            $map[$key]['base'] += (float)($item['neto_gravado'] ?? 0);
            $map[$key]['importe'] += (float)($item['iva_importe'] ?? 0);
        }

        if ($map === []) && (float)$importes['iva'] > 0) {
            return [[
                'id' => 5,
                'base' => round((float)$importes['neto'], 2),
                'importe' => round((float)$importes['iva'], 2),
            ]];
        }

        return array_values(array_map(static function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'base' => round((float)$row['base'], 2),
                'importe' => round((float)$row['importe'], 2),
            ];
        }, $map));
    }

    private function solicitarCaeNc(array $comprobante): ?array
    {
        $this->lastError = null;

        $client = $this->getSoapClient();
        if (!$client instanceof SoapClient) {
            return null;
        }

        $auth = $this->getAuthArray();
        if ($auth === null) {
            return null;
        }

        try {
            $req = $this->buildFeCaeRequest($comprobante);
            if ($req === null) {
                return null;
            }

            $result = $client->FECAESolicitar([
                'Auth' => $auth,
                'FeCAEReq' => $req,
            ]);

            $arr = $this->toArray($result);
            $det = $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse ?? null;
            if (is_array($det)) {
                $det = $det[0] ?? null;
            }
            if (!$det) {
                $this->lastError = $this->extractErrors($result->FECAESolicitarResult ?? null);
                return null;
            }

            $resultado = (string)($det->Resultado ?? '');
            $cae = (string)($det->CAE ?? '');
            if ($resultado !== 'A' || $cae === '') {
                $this->lastError = $this->extractObservaciones($det) ?: $this->extractErrors($result->FECAESolicitarResult ?? null);
                if ($this->lastError === '') {
                    $this->lastError = 'Comprobante rechazado por ARCA.';
                }
                return null;
            }

            return [
                'cae' => $cae,
                'vencimiento' => (string)($det->CAEFchVto ?? ''),
                'resultado' => $resultado,
                'numero' => (int)($det->CbteDesde ?? $comprobante['numero'] ?? 0),
                'raw_response' => $arr,
            ];
        } catch (SoapFault $e) {
            $this->lastError = 'SOAP Fault: ' . $e->getMessage();
            return null;
        } catch (Throwable $e) {
            $this->lastError = 'Error WSFE: ' . $e->getMessage();
            return null;
        }
    }

    private function getSoapClient(): ?SoapClient
    {
        if (!extension_loaded('soap')) {
            $this->lastError = 'Extensión SOAP no habilitada en PHP.';
            return null;
        }

        $env = defined('FLUS_ARCA_ENV') ? (string)FLUS_ARCA_ENV : 'prod';
        $wsdl = ($env === 'homo')
            ? 'https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL'
            : 'https://servicios1.afip.gov.ar/wsfev1/service.asmx?WSDL';

        try {
            $ctx = stream_context_create([
                'ssl' => [
                    'verify_peer' => defined('FLUS_ARCA_SSL_VERIFY') ? (bool)FLUS_ARCA_SSL_VERIFY : true,
                    'verify_peer_name' => defined('FLUS_ARCA_SSL_VERIFY') ? (bool)FLUS_ARCA_SSL_VERIFY : true,
                ],
            ]);

            return new SoapClient($wsdl, [
                'trace' => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_BOTH,
                'connection_timeout' => 30,
                'stream_context' => $ctx,
                'soap_version' => SOAP_1_2,
            ]);
        } catch (Throwable $e) {
            $this->lastError = 'No se pudo conectar al WSFE: ' . $e->getMessage();
            return null;
        }
    }

    private function getAuthArray(): ?array
    {
        $ta = ArcaWsaa::getTA('wsfe');
        if (!$ta) {
            $this->lastError = ArcaWsaa::getLastError() ?: 'No se pudo obtener TA para WSFE.';
            return null;
        }

        $cuit = defined('FLUS_ARCA_CUIT') ? preg_replace('/\D+/', '', (string)FLUS_ARCA_CUIT) : '';
        if ($cuit === '') {
            $this->lastError = 'Falta configurar FLUS_ARCA_CUIT (CUIT del emisor).';
            return null;
        }

        return [
            'Token' => $ta['token'],
            'Sign' => $ta['sign'],
            'Cuit' => $cuit,
        ];
    }

    private function buildFeCaeRequest(array $c): ?array
    {
        $tipoCbte = (int)($c['tipo_cbte'] ?? 0);
        $ptoVta = (int)($c['punto_venta'] ?? 0);
        $numero = (int)($c['numero'] ?? 0);
        if ($tipoCbte <= 0 || $ptoVta <= 0 || $numero <= 0) {
            $this->lastError = 'Faltan datos obligatorios para la NC total.';
            return null;
        }

        $fechaFmt = preg_replace('/\D+/', '', (string)($c['fecha'] ?? date('Ymd')));
        if (!is_string($fechaFmt) || strlen($fechaFmt) !== 8) {
            $fechaFmt = date('Ymd');
        }

        $detalle = [
            'Concepto' => (int)($c['concepto'] ?? ArcaWsfe::CONCEPTO_PRODUCTOS),
            'DocTipo' => (int)($c['tipo_doc'] ?? ArcaWsfe::DOC_SIN_IDENTIFICAR),
            'DocNro' => (int)preg_replace('/\D+/', '', (string)($c['nro_doc'] ?? '0')),
            'CbteDesde' => $numero,
            'CbteHasta' => $numero,
            'CbteFch' => $fechaFmt,
            'ImpTotal' => round((float)($c['importe_total'] ?? 0), 2),
            'ImpTotConc' => round((float)($c['importe_no_gravado'] ?? 0), 2),
            'ImpNeto' => round((float)($c['importe_neto'] ?? 0), 2),
            'ImpOpEx' => round((float)($c['importe_exento'] ?? 0), 2),
            'ImpIVA' => round((float)($c['importe_iva'] ?? 0), 2),
            'ImpTrib' => 0,
            'MonId' => (string)($c['moneda_id'] ?? 'PES'),
            'MonCotiz' => (float)($c['moneda_cotiz'] ?? 1),
            'CondicionIVAReceptorId' => (int)($c['condicion_iva_receptor_id'] ?? ArcaWsfe::IVA_CONSUMIDOR_FINAL),
        ];

        $cbtesAsoc = $c['cbtes_asoc'] ?? [];
        if (is_array($cbtesAsoc) && $cbtesAsoc !== []) {
            $asoc = [];
            foreach ($cbtesAsoc as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $entry = [
                    'Tipo' => (int)($row['tipo'] ?? 0),
                    'PtoVta' => (int)($row['punto_venta'] ?? 0),
                    'Nro' => (int)($row['numero'] ?? 0),
                ];
                $cuit = preg_replace('/\D+/', '', (string)($row['cuit'] ?? ''));
                if ($cuit !== '') {
                    $entry['Cuit'] = (int)$cuit;
                }
                if ($entry['Tipo'] > 0 && $entry['PtoVta'] > 0 && $entry['Nro'] > 0) {
                    $asoc[] = $entry;
                }
            }
            if ($asoc !== []) {
                $detalle['CbtesAsoc'] = ['CbteAsoc' => $asoc];
            }
        }

        $ivaDetalle = $c['iva'] ?? [];
        if (is_array($ivaDetalle) && $ivaDetalle !== []) {
            $alicuotas = [];
            foreach ($ivaDetalle as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $alicuotas[] = [
                    'Id' => (int)($item['id'] ?? 5),
                    'BaseImp' => round((float)($item['base'] ?? 0), 2),
                    'Importe' => round((float)($item['importe'] ?? 0), 2),
                ];
            }
            if ($alicuotas !== []) {
                $detalle['Iva'] = ['AlicIva' => $alicuotas];
            }
        }

        return [
            'FeCabReq' => [
                'CantReg' => 1,
                'PtoVta' => $ptoVta,
                'CbteTipo' => $tipoCbte,
            ],
            'FeDetReq' => [
                'FECAEDetRequest' => $detalle,
            ],
        ];
    }

    private function extractErrors(mixed $result): string
    {
        if (!$result) {
            return 'Respuesta vacía de ARCA.';
        }

        $errors = [];
        $err = $result->Errors->Err ?? null;
        if ($err) {
            $items = is_array($err) ? $err : [$err];
            foreach ($items as $e) {
                $code = (string)($e->Code ?? '');
                $msg = (string)($e->Msg ?? '');
                $errors[] = trim("[$code] $msg");
            }
        }
        return implode(' | ', $errors);
    }

    private function extractObservaciones(mixed $det): string
    {
        $obs = [];
        $obsArr = $det->Observaciones->Obs ?? null;
        if ($obsArr) {
            $items = is_array($obsArr) ? $obsArr : [$obsArr];
            foreach ($items as $o) {
                $code = (string)($o->Code ?? '');
                $msg = (string)($o->Msg ?? '');
                $obs[] = trim("[$code] $msg");
            }
        }
        return implode(' | ', $obs);
    }

    /**
     * @return array<string,mixed>
     */
    private function toArray(mixed $obj): array
    {
        if ($obj === null) {
            return [];
        }
        return json_decode(json_encode($obj), true) ?: [];
    }

    private function extraerCodigoError(?string $message): ?string
    {
        $msg = trim((string)$message);
        if ($msg === '') {
            return null;
        }
        if (preg_match('/\[(\d+)\]/', $msg, $m) === 1) {
            return $m[1];
        }
        return 'ARCA_REJECTED';
    }
}

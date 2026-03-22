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
        $facturaOrigen = $this->repository->findFacturaOrigenByVentaId($ventaId);
        if (!$facturaOrigen) {
            return EmitirNotaCreditoResult::rejected($command->requestUid, $command->scope, 'FACTURA_ORIGEN_NO_ENCONTRADA', 'No se encontro la factura original asociada a la venta.');
        }

        $venta = $this->fetchVenta($ventaId);
        if (!$venta) {
            return EmitirNotaCreditoResult::rejected($command->requestUid, $command->scope, 'VENTA_NO_ENCONTRADA', 'No se encontro la venta a anular fiscalmente.');
        }

        $cliente = $this->fetchCliente((int)($facturaOrigen['cliente_id'] ?? $venta['cliente_id'] ?? 0));
        $tipoCbteOrigen = $this->resolverTipoCbteOrigen($facturaOrigen);
        $tipoCbteNc = $this->mapTipoNc($tipoCbteOrigen);
        if ($tipoCbteNc <= 0) {
            return EmitirNotaCreditoResult::rejected($command->requestUid, $command->scope, 'TIPO_CBTE_ORIGEN_INVALIDO', 'No se pudo mapear el tipo de comprobante original a Nota de Credito.');
        }

        $modoOperacion = flus_facturacion_normalizar_modo((string)($facturaOrigen['modo'] ?? $command->modo ?: 'demo'));
        $tipoStrNc = obtenerNombreTipoComprobante($tipoCbteNc);
        $puntoVenta = (int)($facturaOrigen['punto_venta'] ?? 0);
        $numero = max(1, flus_facturacion_numero_local_siguiente($this->pdo, $puntoVenta, $tipoStrNc, $modoOperacion));

        if ($modoOperacion !== 'demo') {
            $ultimo = ArcaWsfe::getUltimoAutorizado($puntoVenta, $tipoCbteNc);
            if ($ultimo !== null) {
                $numero = max($numero, $ultimo + 1);
            }
        }

        $header = $this->buildFacturaHeader($facturaOrigen, $venta, $cliente, $tipoCbteNc, $numero, $modoOperacion, $command->ventaAnulacionId);
        $items = $this->buildFacturaItems($facturaOrigen, $ventaId, $tipoCbteNc);
        $request = $this->buildRequest($facturaOrigen, $cliente, $tipoCbteOrigen, $tipoCbteNc, $numero, $header, $modoOperacion);

        if ($modoOperacion === 'demo') {
            $header['cae'] = 'DEMO' . str_pad((string)$numero, 14, '0', STR_PAD_LEFT);
            $header['cae_vto'] = date('Y-m-d', strtotime('+10 days'));
            return EmitirNotaCreditoResult::approved($command->requestUid, $command->scope, $header, $items, $request, ['demo' => true]);
        }

        $rawResponse = [];
        try {
            $rawResponse = $this->solicitarCaeNc($request);
        } catch (Throwable $e) {
            return EmitirNotaCreditoResult::rejected($command->requestUid, $command->scope, 'ARCA_ERROR', flus_facturacion_humanizar_error_arca($e->getMessage()));
        }

        $cae = (string)($rawResponse['cae'] ?? '');
        if ($cae === '') {
            return EmitirNotaCreditoResult::rejected($command->requestUid, $command->scope, 'ARCA_RECHAZADA', flus_facturacion_humanizar_error_arca((string)($rawResponse['error'] ?? 'Comprobante rechazado por ARCA.')));
        }

        $header['numero'] = (int)($rawResponse['numero'] ?? $numero);
        $header['cae'] = $cae;
        $header['cae_vto'] = flus_facturacion_normalizar_cae_vto((string)($rawResponse['vencimiento'] ?? ''));

        return EmitirNotaCreditoResult::approved($command->requestUid, $command->scope, $header, $items, $request, $rawResponse);
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
        if ($clienteId <= 0 || !flus_table_exists($this->pdo, 'clientes')) return null;
        $st = $this->pdo->prepare('SELECT * FROM clientes WHERE id = ? LIMIT 1');
        $st->execute([$clienteId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        return is_array($row) ? $row : null;
    }

    private function resolverTipoCbteOrigen(array $facturaOrigen): int
    {
        $tipoCbte = (int)($facturaOrigen['tipo_cbte'] ?? 0);
        if ($tipoCbte > 0) return $tipoCbte;
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

    private function buildFacturaHeader(array $facturaOrigen, array $venta, ?array $cliente, int $tipoCbteNc, int $numero, string $modoOperacion, int $ventaAnulacionId): array
    {
        $tipoStrNc = obtenerNombreTipoComprobante($tipoCbteNc);
        $ts = date('Y-m-d H:i:s');
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
            'importe_neto' => round((float)($facturaOrigen['importe_neto'] ?? 0), 2),
            'importe_iva' => round((float)($facturaOrigen['importe_iva'] ?? 0), 2),
            'importe_exento' => round((float)($facturaOrigen['importe_exento'] ?? 0), 2),
            'importe_no_gravado' => round((float)($facturaOrigen['importe_no_gravado'] ?? 0), 2),
            'total' => round((float)($facturaOrigen['total'] ?? $venta['total'] ?? 0), 2),
            'estado' => 'EMITIDA',
            'modo' => flus_facturacion_facturas_modo_value($this->pdo, $modoOperacion),
            'creado_en' => $ts,
            'comprobante_asoc_tipo_cbte' => $this->resolverTipoCbteOrigen($facturaOrigen),
            'comprobante_asoc_punto_venta' => (int)$facturaOrigen['punto_venta'],
            'comprobante_asoc_numero' => (int)$facturaOrigen['numero'],
            'comprobante_asoc_cuit' => flus_facturacion_cuit_emisor([]),
            'doc_tipo' => determinarDocumentoCliente($cliente, $cliente === null)['tipo'],
            'doc_numero' => determinarDocumentoCliente($cliente, $cliente === null)['numero'],
            'condicion_iva_receptor_id' => determinarCondicionIvaReceptorAfip($cliente, $cliente === null),
            'moneda_id' => (string)($facturaOrigen['moneda_id'] ?? 'PES'),
            'moneda_cotiz' => (float)($facturaOrigen['moneda_cotiz'] ?? 1),
        ];
    }

    private function buildFacturaItems(array $facturaOrigen, int $ventaId, int $tipoCbteNc): array
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
                    'producto_id' => $row['producto_id'] ?? null,
                    'codigo_snapshot' => $row['codigo_snapshot'] ?? null,
                    'descripcion_snapshot' => $row['descripcion_snapshot'] ?? ('NC total venta #' . $ventaId),
                    'cantidad' => (float)($row['cantidad'] ?? 1),
                    'precio_unitario_bruto' => (float)($row['precio_unitario_bruto'] ?? 0),
                    'descuento_total' => (float)($row['descuento_total'] ?? 0),
                    'iva_porcentaje' => (float)($row['iva_porcentaje'] ?? 0),
                    'neto_gravado' => (float)($row['neto_gravado'] ?? 0),
                    'iva_importe' => (float)($row['iva_importe'] ?? 0),
                    'subtotal_total' => (float)($row['subtotal_total'] ?? 0),
                ];
            }
            return $out;
        }

        $manual = flus_facturacion_manual_items_fetch($this->pdo, $ventaId);
        if ($manual !== []) {
            $out = [];
            foreach ($manual as $idx => $row) {
                $subtotal = round((float)($row['subtotal'] ?? 0), 2);
                $ivaPct = in_array($tipoCbteNc, [13], true) ? 0.0 : round((float)($row['iva_porcentaje'] ?? 21), 2);
                $neto = $ivaPct > 0 ? round($subtotal / (1 + $ivaPct / 100), 2) : $subtotal;
                $iva = round($subtotal - $neto, 2);
                $out[] = [
                    'linea_orden' => $idx + 1,
                    'origen_tipo' => 'ANULACION',
                    'snapshot_source' => 'RECONSTRUIDO',
                    'codigo_snapshot' => (string)($row['codigo'] ?? ''),
                    'descripcion_snapshot' => (string)($row['descripcion'] ?? 'NC total venta #' . $ventaId),
                    'cantidad' => (float)($row['cantidad'] ?? 1),
                    'precio_unitario_bruto' => (float)($row['precio_unitario'] ?? 0),
                    'descuento_total' => 0.0,
                    'iva_porcentaje' => $ivaPct,
                    'neto_gravado' => $neto,
                    'iva_importe' => $iva,
                    'subtotal_total' => $subtotal,
                ];
            }
            return $out;
        }

        $itemsVenta = flus_venta_items_cargar($this->pdo, $ventaId);
        $out = [];
        $i = 1;
        foreach ($itemsVenta as $itemId => $row) {
            $subtotal = round((float)($row['subtotal'] ?? 0), 2);
            $qty = max(0.001, (float)($row['cantidad'] ?? 1));
            $unit = round((float)($row['precio_unit_final'] ?? $row['precio'] ?? ($subtotal / $qty)), 6);
            $ivaPct = 0.0;
            if (in_array($tipoCbteNc, [3,8], true)) {
                $ivaPct = $this->resolveIvaPctForVentaItem((int)($row['producto_id'] ?? 0), $facturaOrigen, $subtotal);
            }
            $neto = $ivaPct > 0 ? round($subtotal / (1 + $ivaPct / 100), 2) : $subtotal;
            $iva = round($subtotal - $neto, 2);
            $out[] = [
                'linea_orden' => $i++,
                'origen_tipo' => 'ANULACION',
                'snapshot_source' => 'RECONSTRUIDO',
                'venta_item_id' => $itemId,
                'producto_id' => $row['producto_id'] ?? null,
                'codigo_snapshot' => null,
                'descripcion_snapshot' => (string)($row['descripcion'] ?? 'NC total venta #' . $ventaId),
                'cantidad' => $qty,
                'precio_unitario_bruto' => $unit,
                'descuento_total' => 0.0,
                'iva_porcentaje' => $ivaPct,
                'neto_gravado' => $neto,
                'iva_importe' => $iva,
                'subtotal_total' => $subtotal,
            ];
        }
        if ($out === []) {
            $total = round((float)($facturaOrigen['total'] ?? 0), 2);
            $neto = round((float)($facturaOrigen['importe_neto'] ?? $total), 2);
            $iva = round((float)($facturaOrigen['importe_iva'] ?? 0), 2);
            $ivaPct = $neto > 0 && $iva > 0 ? round(($iva / $neto) * 100, 2) : 0.0;
            $out[] = [
                'linea_orden' => 1,
                'origen_tipo' => 'ANULACION',
                'snapshot_source' => 'RECONSTRUIDO',
                'descripcion_snapshot' => 'NC total venta #' . $ventaId,
                'cantidad' => 1,
                'precio_unitario_bruto' => $total,
                'descuento_total' => 0.0,
                'iva_porcentaje' => $ivaPct,
                'neto_gravado' => $neto,
                'iva_importe' => $iva,
                'subtotal_total' => $total,
            ];
        }
        return $out;
    }

    private function resolveIvaPctForVentaItem(int $productoId, array $facturaOrigen, float $subtotal): float
    {
        if ($productoId > 0 && flus_table_exists($this->pdo, 'productos') && flus_column_exists($this->pdo, 'productos', 'iva_porcentaje')) {
            $st = $this->pdo->prepare('SELECT iva_porcentaje FROM productos WHERE id = ? LIMIT 1');
            $st->execute([$productoId]);
            $pct = $st->fetchColumn();
            if ($pct !== false) return round((float)$pct, 2);
        }
        $neto = (float)($facturaOrigen['importe_neto'] ?? 0);
        $iva = (float)($facturaOrigen['importe_iva'] ?? 0);
        return ($neto > 0 && $iva > 0) ? round(($iva / $neto) * 100, 2) : 21.0;
    }

    private function buildRequest(array $facturaOrigen, ?array $cliente, int $tipoCbteOrigen, int $tipoCbteNc, int $numero, array $header, string $modoOperacion): array
    {
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
            $req['iva'] = $this->groupIvaForRequest($this->buildFacturaItems($facturaOrigen, (int)$facturaOrigen['venta_id'], $tipoCbteNc));
        }
        return $req;
    }

    private function groupIvaForRequest(array $items): array
    {
        $map = [];
        foreach ($items as $it) {
            $pct = round((float)($it['iva_porcentaje'] ?? 0), 1);
            if ($pct <= 0) continue;
            $id = obtenerIdAlicuotaAfip($pct);
            $key = (string)$id;
            if (!isset($map[$key])) $map[$key] = ['id' => $id, 'base' => 0.0, 'importe' => 0.0];
            $map[$key]['base'] += (float)($it['neto_gravado'] ?? 0);
            $map[$key]['importe'] += (float)($it['iva_importe'] ?? 0);
        }
        foreach ($map as &$row) {
            $row['base'] = round($row['base'], 2);
            $row['importe'] = round($row['importe'], 2);
        }
        return array_values($map);
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
        if (is_array($det) && array_is_list($det)) $det = $det[0] ?? null;
        if (!is_array($det)) {
            return ['error' => 'Respuesta vacia de ARCA.'];
        }
        if (($det['Resultado'] ?? '') !== 'A' || empty($det['CAE'])) {
            $obs = $det['Observaciones']['Obs'] ?? null;
            if (is_array($obs) && array_is_list($obs)) $obs = $obs[0] ?? null;
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

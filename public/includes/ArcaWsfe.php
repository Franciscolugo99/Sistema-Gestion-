<?php
// public/includes/ArcaWsfe.php
declare(strict_types=1);

/**
 * ARCA/AFIP WSFE - Webservice de Facturación Electrónica
 * 
 * Implementa los métodos principales del WS FEv1:
 * - FECAESolicitar: Solicita CAE para un comprobante
 * - FECompUltimoAutorizado: Obtiene último número autorizado
 * - FEParamGetTiposCbte: Tipos de comprobante
 * - FEParamGetTiposIva: Alícuotas de IVA
 * - FEParamGetPtosVenta: Puntos de venta habilitados
 * 
 * URLs WSFE:
 * - Homologación: https://wswhomo.afip.gov.ar/wsfev1/service.asmx?WSDL
 * - Producción:   https://servicios1.afip.gov.ar/wsfev1/service.asmx?WSDL
 * 
 * Tipos de comprobante más usados:
 * - 1: Factura A
 * - 6: Factura B
 * - 11: Factura C
 * - 3: Nota de Crédito A
 * - 8: Nota de Crédito B
 * - 13: Nota de Crédito C
 */
final class ArcaWsfe
{
    /** @var string|null */
    private static ?string $lastError = null;
    
    /** @var array|null Último response para debug */
    private static ?array $lastResponse = null;

    // Tipos de comprobante
    public const FACTURA_A = 1;
    public const FACTURA_B = 6;
    public const FACTURA_C = 11;
    public const NOTA_CREDITO_A = 3;
    public const NOTA_CREDITO_B = 8;
    public const NOTA_CREDITO_C = 13;
    public const NOTA_DEBITO_A = 2;
    public const NOTA_DEBITO_B = 7;
    public const NOTA_DEBITO_C = 12;
    
    // Tipos de documento
    public const DOC_CUIT = 80;
    public const DOC_CUIL = 86;
    public const DOC_CDI = 87;
    public const DOC_DNI = 96;
    public const DOC_PASAPORTE = 94;
    public const DOC_SIN_IDENTIFICAR = 99;
    
    // Conceptos
    public const CONCEPTO_PRODUCTOS = 1;
    public const CONCEPTO_SERVICIOS = 2;
    public const CONCEPTO_PRODUCTOS_SERVICIOS = 3;
    
    // Condiciones de IVA del receptor
    public const IVA_RESPONSABLE_INSCRIPTO = 1;
    public const IVA_MONOTRIBUTISTA = 6;
    public const IVA_EXENTO = 4;
    public const IVA_CONSUMIDOR_FINAL = 5;

    public static function getLastError(): ?string
    {
        return self::$lastError;
    }
    
    public static function getLastResponse(): ?array
    {
        return self::$lastResponse;
    }

    /**
     * Obtiene el último número de comprobante autorizado
     */
    public static function getUltimoAutorizado(int $puntoVenta, int $tipoCbte): ?int
    {
        self::$lastError = null;
        self::$lastResponse = null;

        $client = self::getSoapClient();
        if (!$client) return null;

        $auth = self::getAuthArray();
        if (!$auth) return null;

        try {
            $params = [
                'Auth' => $auth,
                'PtoVta' => $puntoVenta,
                'CbteTipo' => $tipoCbte,
            ];

            $result = $client->FECompUltimoAutorizado($params);
            self::$lastResponse = self::toArray($result);
            
            $cbteNro = $result->FECompUltimoAutorizadoResult->CbteNro ?? null;
            
            if ($cbteNro === null) {
                self::$lastError = self::extractErrors($result->FECompUltimoAutorizadoResult ?? null);
                return null;
            }

            return (int)$cbteNro;

        } catch (SoapFault $e) {
            self::$lastError = 'SOAP Fault: ' . $e->getMessage();
            return null;
        } catch (Throwable $e) {
            self::$lastError = 'Error WSFE: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * Solicita CAE para un comprobante
     * 
     * @param array $comprobante Datos del comprobante:
     *   - tipo_cbte: int (1=FA, 6=FB, 11=FC, etc.)
     *   - punto_venta: int
     *   - numero: int (número del comprobante)
     *   - concepto: int (1=Productos, 2=Servicios, 3=Ambos)
     *   - tipo_doc: int (80=CUIT, 96=DNI, 99=Sin identificar)
     *   - nro_doc: string (número de documento del cliente)
     *   - fecha: string (Y-m-d)
     *   - importe_total: float
     *   - importe_neto: float (base imponible)
     *   - importe_iva: float
     *   - importe_exento: float (opcional)
     *   - importe_no_gravado: float (opcional)
     *   - moneda_id: string ('PES' = pesos)
     *   - moneda_cotiz: float (1 para pesos)
     *   - iva: array (opcional) - detalle de IVA por alícuota
     *   - cond_iva_receptor: int (1=RI, 4=Exento, 5=CF, 6=Monotributo)
     * 
     * @return array|null ['cae' => string, 'vencimiento' => string] o null si falla
     */
    public static function solicitarCAE(array $comprobante): ?array
    {
        self::$lastError = null;
        self::$lastResponse = null;

        $client = self::getSoapClient();
        if (!$client) return null;

        $auth = self::getAuthArray();
        if (!$auth) return null;

        try {
            // Construir request
            $req = self::buildFECAERequest($comprobante);
            if (!$req) return null;

            $params = [
                'Auth' => $auth,
                'FeCAEReq' => $req,
            ];

            $result = $client->FECAESolicitar($params);
            self::$lastResponse = self::toArray($result);

            // Procesar respuesta
            $feDetResp = $result->FECAESolicitarResult->FeDetResp->FECAEDetResponse ?? null;
            
            if (!$feDetResp) {
                self::$lastError = self::extractErrors($result->FECAESolicitarResult ?? null);
                return null;
            }

            // Puede ser array o único objeto
            if (is_array($feDetResp)) {
                $det = $feDetResp[0] ?? null;
            } else {
                $det = $feDetResp;
            }

            if (!$det) {
                self::$lastError = 'Respuesta vacía de AFIP.';
                return null;
            }

            $resultado = (string)($det->Resultado ?? '');
            $cae = (string)($det->CAE ?? '');
            $vto = (string)($det->CAEFchVto ?? '');

            if ($resultado !== 'A' || $cae === '') {
                // Rechazado
                $obs = self::extractObservaciones($det);
                self::$lastError = $obs ?: 'Comprobante rechazado por AFIP.';
                return null;
            }

            return [
                'cae' => $cae,
                'vencimiento' => $vto, // formato YYYYMMDD
                'resultado' => $resultado,
                'numero' => (int)($det->CbteDesde ?? $comprobante['numero'] ?? 0),
            ];

        } catch (SoapFault $e) {
            self::$lastError = 'SOAP Fault: ' . $e->getMessage();
            return null;
        } catch (Throwable $e) {
            self::$lastError = 'Error WSFE: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * Obtiene los puntos de venta habilitados
     */
    public static function getPuntosVenta(): ?array
    {
        self::$lastError = null;

        $client = self::getSoapClient();
        if (!$client) return null;

        $auth = self::getAuthArray();
        if (!$auth) return null;

        try {
            $result = $client->FEParamGetPtosVenta(['Auth' => $auth]);
            self::$lastResponse = self::toArray($result);

            $lista = $result->FEParamGetPtosVentaResult->ResultGet->PtoVenta ?? null;
            if (!$lista) {
                return [];
            }

            $ptos = [];
            $items = is_array($lista) ? $lista : [$lista];
            foreach ($items as $p) {
                $ptos[] = [
                    'numero' => (int)($p->Nro ?? 0),
                    'bloqueado' => (string)($p->Bloqueado ?? 'N'),
                    'fecha_baja' => (string)($p->FchBaja ?? ''),
                    'emision_tipo' => (string)($p->EmisionTipo ?? ''),
                ];
            }

            return $ptos;

        } catch (Throwable $e) {
            self::$lastError = 'Error obteniendo puntos de venta: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * Obtiene tipos de comprobante disponibles
     */
    public static function getTiposComprobante(): ?array
    {
        self::$lastError = null;

        $client = self::getSoapClient();
        if (!$client) return null;

        $auth = self::getAuthArray();
        if (!$auth) return null;

        try {
            $result = $client->FEParamGetTiposCbte(['Auth' => $auth]);
            
            $lista = $result->FEParamGetTiposCbteResult->ResultGet->CbteTipo ?? null;
            if (!$lista) return [];

            $tipos = [];
            $items = is_array($lista) ? $lista : [$lista];
            foreach ($items as $t) {
                $tipos[] = [
                    'id' => (int)($t->Id ?? 0),
                    'desc' => (string)($t->Desc ?? ''),
                    'fecha_desde' => (string)($t->FchDesde ?? ''),
                    'fecha_hasta' => (string)($t->FchHasta ?? ''),
                ];
            }

            return $tipos;

        } catch (Throwable $e) {
            self::$lastError = 'Error obteniendo tipos de comprobante: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * Determina el tipo de comprobante según condición IVA del emisor y receptor
     * 
     * @param string $condIvaEmisor 'RI' (Responsable Inscripto) o 'MT' (Monotributista)
     * @param string $condIvaReceptor 'RI', 'MT', 'EX', 'CF' (Consumidor Final)
     * @return int Tipo de comprobante (1, 6, 11, etc.)
     */
    public static function determinarTipoComprobante(string $condIvaEmisor, string $condIvaReceptor): int
    {
        $condIvaEmisor = strtoupper(trim($condIvaEmisor));
        $condIvaReceptor = strtoupper(trim($condIvaReceptor));

        // Monotributista siempre emite C
        if ($condIvaEmisor === 'MT' || $condIvaEmisor === 'MONOTRIBUTISTA') {
            return self::FACTURA_C;
        }

        // Responsable Inscripto
        if ($condIvaEmisor === 'RI' || $condIvaEmisor === 'RESPONSABLE INSCRIPTO') {
            // A otro RI -> Factura A
            if ($condIvaReceptor === 'RI' || $condIvaReceptor === 'RESPONSABLE INSCRIPTO') {
                return self::FACTURA_A;
            }
            // A cualquier otro -> Factura B
            return self::FACTURA_B;
        }

        // Default: Factura C (para exentos, etc.)
        return self::FACTURA_C;
    }

    /**
     * Determina el tipo de documento según el valor
     */
    public static function determinarTipoDocumento(?string $cuit, ?string $dni): array
    {
        // Si tiene CUIT válido, usar CUIT
        if ($cuit && strlen(preg_replace('/\D/', '', $cuit)) >= 11) {
            return [
                'tipo' => self::DOC_CUIT,
                'numero' => preg_replace('/\D/', '', $cuit),
            ];
        }

        // Si tiene DNI
        if ($dni && strlen(preg_replace('/\D/', '', $dni)) >= 7) {
            return [
                'tipo' => self::DOC_DNI,
                'numero' => preg_replace('/\D/', '', $dni),
            ];
        }

        // Consumidor final sin identificar
        return [
            'tipo' => self::DOC_SIN_IDENTIFICAR,
            'numero' => '0',
        ];
    }

    // =========================================================================
    // MÉTODOS PRIVADOS
    // =========================================================================

    private static function getSoapClient(): ?SoapClient
    {
        if (!extension_loaded('soap')) {
            self::$lastError = 'Extensión SOAP no habilitada en PHP.';
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
                ]
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
            self::$lastError = 'No se pudo conectar al WSFE: ' . $e->getMessage();
            return null;
        }
    }

    private static function getAuthArray(): ?array
    {
        require_once __DIR__ . '/ArcaWsaa.php';

        $ta = ArcaWsaa::getTA('wsfe');
        if (!$ta) {
            self::$lastError = ArcaWsaa::getLastError() ?: 'No se pudo obtener TA para WSFE.';
            return null;
        }

        $cuit = defined('FLUS_ARCA_CUIT') ? (string)FLUS_ARCA_CUIT : '';
        if ($cuit === '') {
            self::$lastError = 'Falta configurar FLUS_ARCA_CUIT (CUIT del emisor).';
            return null;
        }

        return [
            'Token' => $ta['token'],
            'Sign' => $ta['sign'],
            'Cuit' => preg_replace('/\D/', '', $cuit),
        ];
    }

    private static function buildFECAERequest(array $c): ?array
    {
        $tipoCbte = (int)($c['tipo_cbte'] ?? 0);
        $ptoVta = (int)($c['punto_venta'] ?? 0);
        $numero = (int)($c['numero'] ?? 0);
        $concepto = (int)($c['concepto'] ?? self::CONCEPTO_PRODUCTOS);
        $tipoDoc = (int)($c['tipo_doc'] ?? self::DOC_SIN_IDENTIFICAR);
        $nroDoc = (string)($c['nro_doc'] ?? '0');
        $fecha = (string)($c['fecha'] ?? date('Y-m-d'));
        
        $impTotal = (float)($c['importe_total'] ?? 0);
        $impNeto = (float)($c['importe_neto'] ?? $impTotal);
        $impIva = (float)($c['importe_iva'] ?? 0);
        $impExento = (float)($c['importe_exento'] ?? 0);
        $impNoGrav = (float)($c['importe_no_gravado'] ?? 0);
        
        $monedaId = (string)($c['moneda_id'] ?? 'PES');
        $monedaCotiz = (float)($c['moneda_cotiz'] ?? 1);

        if ($tipoCbte <= 0 || $ptoVta <= 0 || $numero <= 0) {
            self::$lastError = 'Faltan datos obligatorios: tipo_cbte, punto_venta, numero.';
            return null;
        }

        // Formatear fecha como YYYYMMDD
        $fechaFmt = str_replace('-', '', $fecha);
        if (strlen($fechaFmt) !== 8) {
            $fechaFmt = date('Ymd');
        }

        $detalle = [
            'Concepto' => $concepto,
            'DocTipo' => $tipoDoc,
            'DocNro' => (int)preg_replace('/\D/', '', $nroDoc),
            'CbteDesde' => $numero,
            'CbteHasta' => $numero,
            'CbteFch' => $fechaFmt,
            'ImpTotal' => round($impTotal, 2),
            'ImpTotConc' => round($impNoGrav, 2), // No gravado
            'ImpNeto' => round($impNeto, 2),
            'ImpOpEx' => round($impExento, 2),
            'ImpIVA' => round($impIva, 2),
            'ImpTrib' => 0, // Otros tributos
            'MonId' => $monedaId,
            'MonCotiz' => $monedaCotiz,
        ];

        // Si es concepto servicios o mixto, agregar fechas
        if ($concepto >= 2) {
            $detalle['FchServDesde'] = $fechaFmt;
            $detalle['FchServHasta'] = $fechaFmt;
            $detalle['FchVtoPago'] = $fechaFmt;
        }

        // Detalle de IVA (obligatorio si hay IVA)
        if ($impIva > 0) {
            $iva = $c['iva'] ?? null;
            if (!$iva) {
                // Asumir 21% si no viene detallado
                $iva = [[
                    'id' => 5, // 21%
                    'base' => $impNeto,
                    'importe' => $impIva,
                ]];
            }
            
            $ivaArr = [];
            foreach ($iva as $item) {
                $ivaArr[] = [
                    'Id' => (int)($item['id'] ?? 5),
                    'BaseImp' => round((float)($item['base'] ?? $impNeto), 2),
                    'Importe' => round((float)($item['importe'] ?? $impIva), 2),
                ];
            }
            
            $detalle['Iva'] = ['AlicIva' => $ivaArr];
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

    private static function extractErrors($result): string
    {
        if (!$result) return 'Respuesta vacía de AFIP.';

        $errors = [];

        // Errors
        $err = $result->Errors->Err ?? null;
        if ($err) {
            $items = is_array($err) ? $err : [$err];
            foreach ($items as $e) {
                $code = (string)($e->Code ?? '');
                $msg = (string)($e->Msg ?? '');
                $errors[] = trim("[$code] $msg");
            }
        }

        return implode(' | ', $errors) ?: 'Error desconocido de AFIP.';
    }

    private static function extractObservaciones($det): string
    {
        $obs = [];

        // Observaciones
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

    private static function toArray($obj): array
    {
        if ($obj === null) return [];
        return json_decode(json_encode($obj), true) ?: [];
    }
}
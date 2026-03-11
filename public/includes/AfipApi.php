<?php
// public/includes/AfipApi.php
declare(strict_types=1);

/**
 * AFIP/ARCA - Consulta de contribuyente por CUIT.
 *
 * IMPORTANTE:
 * - El viejo endpoint REST público "soa.afip.gob.ar/sr-padron/v2/persona/{cuit}" hoy puede devolver 404.
 * - La alternativa soportada es el WS SOAP "ws_sr_constancia_inscripcion" (personaServiceA5) con WSAA.
 *
 * Requisitos para que funcione:
 * 1) Certificado + clave (PEM) de ARCA (prod/homo) y autorización del servicio.
 * 2) Configurar constantes:
 *    - FLUS_ARCA_ENV = 'prod' o 'homo'
 *    - FLUS_ARCA_CERT_PEM, FLUS_ARCA_KEY_PEM, (opcional) FLUS_ARCA_KEY_PASS
 *    - FLUS_ARCA_REP_CUIT (CUIT representada / la que figura en relations del TA)
 */
final class AfipApi
{
    /** @var string|null */
    private static ?string $lastError = null;

    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * @return array|null Datos normalizados o null.
     */
    public static function consultarCuit(string $cuit): ?array
    {
        self::$lastError = null;
        require_once __DIR__ . '/CuitValidator.php';

        if (!CuitValidator::validar($cuit)) {
            self::$lastError = 'CUIT inválido.';
            return null;
        }

        $cuitLimpio = CuitValidator::limpiar($cuit);

        // Camino soportado (SOAP + WSAA)
        $datos = self::consultarConstanciaInscripcionSOAP($cuitLimpio);
        if ($datos) return $datos;

        // Si no hay datos, lastError ya explica por qué
        return null;
    }

    private static function consultarConstanciaInscripcionSOAP(string $cuit): ?array
    {
        require_once __DIR__ . '/ArcaWsaa.php';
        require_once __DIR__ . '/CuitValidator.php';

        if (!extension_loaded('soap')) {
            self::$lastError = 'Extensión SOAP no habilitada en PHP (extension=soap).';
            return null;
        }

        $serviceId = 'ws_sr_constancia_inscripcion';
        $ta = ArcaWsaa::getTA($serviceId);
        if (!$ta) {
            $wsaaError = ArcaWsaa::getLastError() ?: 'No se pudo obtener TA de WSAA.';
            if (stripos($wsaaError, 'Computador no autorizado a acceder al servicio') !== false) {
                self::$lastError = 'El certificado responde con WSAA, pero no tiene autorizado el servicio de padron ARCA (ws_sr_constancia_inscripcion) para este CUIT. Debes habilitar esa relacion en ARCA y volver a intentar.';
            } else {
                self::$lastError = $wsaaError;
            }
            return null;
        }

        $repCuit = defined('FLUS_ARCA_REP_CUIT') ? (string)FLUS_ARCA_REP_CUIT : '';
        if ($repCuit === '') {
            self::$lastError = 'Falta configurar FLUS_ARCA_REP_CUIT (CUIT representada).';
            return null;
        }

        $env = defined('FLUS_ARCA_ENV') ? (string)FLUS_ARCA_ENV : 'prod';
        $wsdl = ($env === 'homo')
            ? 'https://awshomo.arca.gov.ar/sr-padron/webservices/personaServiceA5?WSDL'
            : 'https://aws.arca.gov.ar/sr-padron/webservices/personaServiceA5?WSDL';

        try {
            $ctx = stream_context_create([
                'ssl' => [
                    'verify_peer' => defined('FLUS_ARCA_SSL_VERIFY') ? (bool)FLUS_ARCA_SSL_VERIFY : true,
                    'verify_peer_name' => defined('FLUS_ARCA_SSL_VERIFY') ? (bool)FLUS_ARCA_SSL_VERIFY : true,
                    'allow_self_signed' => false,
                ]
            ]);

            $client = new SoapClient($wsdl, [
                'trace' => false,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_BOTH,
                'connection_timeout' => 12,
                'stream_context' => $ctx,
            ]);

            // método recomendado: getPersona_v2
            $params = [
                'token' => $ta['token'],
                'sign' => $ta['sign'],
                'cuitRepresentada' => (string)CuitValidator::limpiar($repCuit),
                'idPersona' => $cuit,
            ];

            $resp = $client->getPersona_v2($params);
            $personaReturn = $resp->personaReturn ?? null;
            if (!$personaReturn) {
                self::$lastError = 'Respuesta SOAP sin personaReturn.';
                return null;
            }

            // Errores (cuando CUIT no existe, etc.)
            $errConst = $personaReturn->errorConstancia ?? null;
            if ($errConst) {
                $msg = self::soapErrorToString($errConst);
                self::$lastError = $msg !== '' ? $msg : 'ARCA no devolvió constancia para ese CUIT.';
                return null;
            }

            $dg  = self::toArray($personaReturn->datosGenerales ?? null);
            $drg = self::toArray($personaReturn->datosRegimenGeneral ?? null);
            $dm  = self::toArray($personaReturn->datosMonotributo ?? null);

            $nombre = self::buildNombre($dg);
            if ($nombre === '') {
                self::$lastError = 'ARCA respondió sin nombre/razón social.';
                return null;
            }

            $dom = self::toArray($dg['domicilioFiscal'] ?? null);
            $direccion = self::formatDireccion($dom);

            $actividad = self::pickActividad($drg);
            $condIva   = self::inferCondIva($drg, $dm);

            $tipoPersona = strtoupper(trim((string)($dg['tipoPersona'] ?? '')));
            $tipoContrib = (str_contains($tipoPersona, 'JUR')) ? 'JURIDICA' : ((str_contains($tipoPersona, 'FIS')) ? 'FISICA' : '');

            return [
                'nombre' => $nombre,
                'cuit' => CuitValidator::formatear($cuit) ?? $cuit,
                'cond_iva' => $condIva,
                'direccion' => $direccion,
                'tipo_contribuyente' => $tipoContrib,
                'actividad' => $actividad,
                'estado' => (string)($dg['estadoClave'] ?? ''),
            ];

        } catch (SoapFault $sf) {
            self::$lastError = 'SOAP Fault: ' . ($sf->faultstring ?? 'Error SOAP');
            return null;
        } catch (Throwable $e) {
            self::$lastError = 'Error consultando ARCA (SOAP): ' . $e->getMessage();
            return null;
        }
    }

    private static function soapErrorToString($err): string
    {
        // errorConstancia puede ser objeto o string
        if (is_string($err)) return trim($err);
        if (is_object($err)) {
            // suele traer codigo+descripcion
            $a = self::toArray($err);
            $code = trim((string)($a['codigo'] ?? $a['id'] ?? ''));
            $desc = trim((string)($a['descripcion'] ?? $a['mensaje'] ?? ''));
            return trim(($code !== '' ? "$code: " : '') . $desc);
        }
        return '';
    }

    private static function toArray($v): array
    {
        if ($v === null) return [];
        if (is_array($v)) return $v;
        if (is_object($v)) {
            // SoapVar / stdClass
            return json_decode(json_encode($v, JSON_UNESCAPED_UNICODE), true) ?: [];
        }
        return [];
    }

    private static function buildNombre(array $dg): string
    {
        // Jurídica
        $rs = trim((string)($dg['razonSocial'] ?? ''));
        if ($rs !== '') return $rs;

        // Física
        $ape = trim((string)($dg['apellido'] ?? ''));
        $nom = trim((string)($dg['nombre'] ?? ''));
        $full = trim($ape . ' ' . $nom);
        if ($full !== '') return $full;

        // Fallback
        return trim((string)($dg['denominacion'] ?? $nom));
    }

    private static function pickActividad(array $drg): string
    {
        $act = $drg['actividad'] ?? null;
        if ($act === null) return '';

        // puede ser objeto, array o lista
        if (is_object($act)) {
            $a = self::toArray($act);
            return trim((string)($a['descripcionActividad'] ?? $a['descripcion'] ?? ''));
        }

        if (is_array($act)) {
            // lista de actividades
            if (isset($act['descripcionActividad']) || isset($act['descripcion'])) {
                return trim((string)($act['descripcionActividad'] ?? $act['descripcion'] ?? ''));
            }
            foreach ($act as $row) {
                $a = self::toArray($row);
                $d = trim((string)($a['descripcionActividad'] ?? $a['descripcion'] ?? ''));
                if ($d !== '') return $d;
            }
        }

        return '';
    }

    private static function inferCondIva(array $drg, array $dm): string
    {
        // Monotributo: si hay datosMonotributo no vacíos
        if (!empty($dm)) {
            return 'MT';
        }

        // Regimen general: mirar impuestos
        $imp = $drg['impuesto'] ?? $drg['impuestos'] ?? null;
        if ($imp !== null) {
            $list = [];
            if (is_object($imp) || (is_array($imp) && (isset($imp['descripcionImpuesto']) || isset($imp['descripcion'])))) {
                $list = [ $imp ];
            } elseif (is_array($imp)) {
                $list = $imp;
            }

            foreach ($list as $row) {
                $a = self::toArray($row);
                $desc = strtoupper(trim((string)($a['descripcionImpuesto'] ?? $a['descripcion'] ?? '')));
                if ($desc === '') continue;

                if (str_contains($desc, 'IVA') && str_contains($desc, 'EXENT')) return 'EX';
                if (str_contains($desc, 'IVA')) return 'RI';
            }
        }

        // Si no pudimos inferir, devolvemos vacío (UI puede mostrar "Sin especificar")
        return '';
    }

    private static function formatDireccion(array $dom): string
    {
        // Estructura típica: direccion, localidad, descripcionProvincia, codPostal
        $dir = trim((string)($dom['direccion'] ?? ''));
        if ($dir === '') {
            $calle = trim((string)($dom['calle'] ?? $dom['nombreCalle'] ?? ''));
            $num   = trim((string)($dom['numero'] ?? $dom['numeroCalle'] ?? ''));
            $dir = trim($calle . ' ' . $num);
        }

        $loc = trim((string)($dom['localidad'] ?? $dom['descripcionLocalidad'] ?? $dom['nombreLocalidad'] ?? ''));
        $prov = trim((string)($dom['descripcionProvincia'] ?? $dom['provincia'] ?? ''));
        $cp = trim((string)($dom['codPostal'] ?? $dom['codigoPostal'] ?? ''));

        $parts = array_filter([
            $dir,
            $loc,
            $prov,
            $cp !== '' ? ('CP ' . $cp) : ''
        ], fn($x) => trim((string)$x) !== '');

        return implode(', ', $parts);
    }
}


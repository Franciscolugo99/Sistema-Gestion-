<?php
declare(strict_types=1);

/**
 * Normaliza el modo fiscal de la app.
 */
function flus_facturacion_normalizar_modo(?string $raw): string
{
    $modo = strtolower(trim((string)$raw));

    if (in_array($modo, ['produccion', 'prod'], true)) {
        return 'produccion';
    }

    if (in_array($modo, ['homologacion', 'homo', 'testing', 'test'], true)) {
        return 'homologacion';
    }

    return 'demo';
}

/**
 * Obtiene el modo efectivo para la operacion actual.
 */
function flus_facturacion_modo_actual(array $config = [], array $opciones = []): string
{
    if (isset($opciones['modo'])) {
        return flus_facturacion_normalizar_modo((string)$opciones['modo']);
    }

    try {
        $pdo = getPDO();
        $persistido = trim((string)config_get($pdo, 'facturacion_modo', ''));
        if ($persistido !== '') {
            return flus_facturacion_normalizar_modo($persistido);
        }
    } catch (Throwable $e) {
        // fallback al valor de config_facturacion si app_config no esta disponible
    }

    return flus_facturacion_normalizar_modo((string)($config['modo'] ?? 'demo'));
}

/**
 * Indica si el modo necesita conexion real con ARCA.
 */
function flus_facturacion_modo_requires_arca(string $modo): bool
{
    return flus_facturacion_normalizar_modo($modo) !== 'demo';
}

/**
 * Etiqueta amigable para UI.
 */
function flus_facturacion_modo_label(string $modo): string
{
    return match (flus_facturacion_normalizar_modo($modo)) {
        'homologacion' => 'Homologacion',
        'produccion' => 'Produccion',
        default => 'Demo',
    };
}

function flus_facturacion_arca_emision_bloqueada_message(): string
{
    return 'No se puede emitir ahora porque ARCA no responde.';
}

function flus_facturacion_arca_status_label(string $status): string
{
    return match ($status) {
        'available' => 'ARCA disponible',
        'not_required' => 'ARCA no requerida',
        'unknown' => 'Estado ARCA sin verificar',
        default => 'ARCA no disponible',
    };
}

function flus_facturacion_arca_is_availability_error(?string $raw): bool
{
    $message = trim((string) $raw);
    if ($message === '') {
        return false;
    }

    if (preg_match('/\[\d+\]/', $message) === 1) {
        return false;
    }

    $normalized = function_exists('mb_strtolower')
        ? mb_strtolower($message, 'UTF-8')
        : strtolower($message);

    foreach ([
        'soap fault',
        'soap-error: parsing wsdl',
        'parsing wsdl',
        'couldn\'t load from',
        'failed to load external entity',
        'no se pudo conectar al wsfe',
        'no es posible conectar con el servidor remoto',
        'could not connect to server',
        'error wsfe',
        'error invocando wsaa',
        'timeout',
        'timed out',
        'actively refused',
        'tcp connect',
    ] as $needle) {
        if (str_contains($normalized, $needle)) {
            return true;
        }
    }

    return false;
}

function flus_facturacion_arca_preflight_error(array $preflight): string
{
    foreach ((array) ($preflight['items'] ?? []) as $item) {
        if (($item['status'] ?? '') !== 'error') {
            continue;
        }

        $label = trim((string) ($item['label'] ?? 'ARCA'));
        $value = trim((string) ($item['value'] ?? ''));
        $hint = trim((string) ($item['hint'] ?? ''));

        $parts = array_values(array_filter([$label, $value !== '' ? $value : null, $hint !== '' ? $hint : null]));
        if ($parts !== []) {
            return implode(' - ', $parts);
        }
    }

    $warnings = array_values(array_filter(array_map('strval', (array) ($preflight['warnings'] ?? []))));
    if ($warnings !== []) {
        return trim($warnings[0]);
    }

    return 'Sin verificacion reciente. Usa "Probar conexion con ARCA".';
}

/**
 * Valor compatible para columnas legacy enum(''demo'',''produccion'').
 */
function flus_facturacion_modo_db_value(string $modo): string
{
    return flus_facturacion_normalizar_modo($modo) === 'demo' ? 'demo' : 'produccion';
}

/**
 * Ambiente ARCA esperado para cada modo.
 */
function flus_facturacion_arca_env_esperado(string $modo): string
{
    return match (flus_facturacion_normalizar_modo($modo)) {
        'homologacion' => 'homo',
        'produccion' => 'prod',
        default => '',
    };
}

/**
 * Ambiente ARCA actualmente configurado.
 */
function flus_facturacion_arca_env_actual(): string
{
    $env = strtolower(trim((string)(defined('FLUS_ARCA_ENV') ? FLUS_ARCA_ENV : 'prod')));
    return $env === 'homo' ? 'homo' : 'prod';
}

/**
 * Normaliza el modo, con demo como fallback seguro.
 */
function flus_facturacion_modo_demo(array $config, array $opciones): bool
{
    return flus_facturacion_modo_actual($config, $opciones) === 'demo';
}

/**
 * Normaliza fecha de vencimiento CAE para guardar y mostrar consistente.
 */
function flus_facturacion_normalizar_cae_vto(?string $caeVto): ?string
{
    if ($caeVto === null) {
        return null;
    }

    $caeVto = trim($caeVto);
    if ($caeVto === '') {
        return null;
    }

    if (preg_match('/^\d{8}$/', $caeVto) === 1) {
        $dt = DateTime::createFromFormat('Ymd', $caeVto);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    $ts = strtotime($caeVto);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }

    return $caeVto;
}

/**
 * Estados fiscales acotados para factura común.
 */
function flus_facturacion_estado_fiscal_normalizar(?string $raw): string
{
    $estado = strtoupper(trim((string)$raw));
    $allowed = ['NO_APLICA', 'PENDIENTE_ENVIO', 'ERROR_TRANSITORIO', 'ERROR_POST_ARCA', 'AUTORIZADA', 'RECUPERADA', 'RECHAZADA'];
    return in_array($estado, $allowed, true) ? $estado : 'NO_APLICA';
}

function flus_facturacion_estado_fiscal_label(string $raw): string
{
    return match (flus_facturacion_estado_fiscal_normalizar($raw)) {
        'PENDIENTE_ENVIO' => 'Pendiente de envío',
        'ERROR_TRANSITORIO' => 'Error transitorio',
        'ERROR_POST_ARCA' => 'Error post-ARCA',
        'AUTORIZADA' => 'Autorizada',
        'RECUPERADA' => 'Recuperada',
        'RECHAZADA' => 'Rechazada',
        default => 'No aplica',
    };
}

function flus_facturacion_estado_fiscal_requiere_intervencion(?string $raw): bool
{
    return in_array(
        flus_facturacion_estado_fiscal_normalizar($raw),
        ['PENDIENTE_ENVIO', 'ERROR_TRANSITORIO', 'ERROR_POST_ARCA'],
        true
    );
}

function flus_facturacion_estado_fiscal_regularizable(?string $raw): bool
{
    return flus_facturacion_estado_fiscal_requiere_intervencion($raw);
}

function flus_facturacion_estado_fiscal_detalle_operativo(?string $raw): string
{
    return match (flus_facturacion_estado_fiscal_normalizar($raw)) {
        'PENDIENTE_ENVIO' => 'Registrada localmente y pendiente de envío o confirmación ante ARCA.',
        'ERROR_TRANSITORIO' => 'Falló el envío o la disponibilidad de ARCA. Se puede reintentar en forma segura.',
        'ERROR_POST_ARCA' => 'ARCA pudo haber autorizado el comprobante, pero FLUS no cerró la registración local. Requiere regularización sin reenvío automático.',
        'RECUPERADA' => 'La factura quedó regularizada desde trazas/eventos sin duplicar emisión.',
        'AUTORIZADA' => 'La factura quedó autorizada y cerrada localmente.',
        'RECHAZADA' => 'ARCA rechazó el comprobante. Revisa los datos antes de volver a emitir.',
        default => 'Sin incidencia fiscal pendiente.',
    };
}

function flus_facturacion_evento_arca_resultado_label(?string $raw): string
{
    return match (strtoupper(trim((string)$raw))) {
        'PENDIENTE' => 'Pendiente',
        'OK' => 'Confirmado',
        'ERROR' => 'Error',
        default => 'Sin traza visible',
    };
}

function flus_facturacion_evento_arca_operacion_label(?string $raw): string
{
    return match (strtoupper(trim((string)$raw))) {
        'FACTURA_VENTA' => 'Factura desde venta',
        'FACTURA_MANUAL' => 'Factura manual',
        'FACTURA_RECOVERY' => 'Recovery factura',
        'NC_TOTAL' => 'NC total',
        'NC_PARCIAL' => 'NC parcial',
        'CONSULTA' => 'Consulta',
        'RECOVERY' => 'Recovery',
        default => trim((string)$raw),
    };
}

function flus_facturacion_error_es_transitorio(?string $raw): bool
{
    $message = trim((string)$raw);
    if ($message === '') {
        return false;
    }

    if (flus_facturacion_arca_is_availability_error($message)) {
        return true;
    }

    $normalized = function_exists('mb_strtolower')
        ? mb_strtolower($message, 'UTF-8')
        : strtolower($message);

    foreach (['soap fault', 'timeout', 'tempor', 'transitor', 'connection', 'network', 'unavailable', 'wsfe', 'wsaa'] as $needle) {
        if (str_contains($normalized, $needle)) {
            return true;
        }
    }

    return false;
}

function flus_facturacion_estado_fiscal_por_error(?string $raw): string
{
    return flus_facturacion_error_es_transitorio($raw) ? 'ERROR_TRANSITORIO' : 'RECHAZADA';
}

function flus_facturacion_error_code(?string $raw): string
{
    $message = trim((string)$raw);
    if ($message === '') {
        return 'ARCA_ERROR';
    }

    if (preg_match('/\b(\d{4,})\b/', $message, $matches) === 1) {
        return (string)$matches[1];
    }

    return flus_facturacion_error_es_transitorio($message) ? 'TRANSIENT' : 'ARCA_ERROR';
}

function flus_facturacion_uuid_from_seed(string $seed): string
{
    $hex = substr(sha1($seed), 0, 32);
    $timeHi = (hexdec(substr($hex, 12, 4)) & 0x0fff) | 0x5000;
    $clock = (hexdec(substr($hex, 16, 4)) & 0x3fff) | 0x8000;

    return sprintf(
        '%s-%s-%04x-%04x-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        $timeHi,
        $clock,
        substr($hex, 20, 12)
    );
}

function flus_facturacion_request_uid_manual(int $clienteId, array $items, array $meta = [], array $opciones = []): string
{
    $provided = trim((string)($opciones['request_uid'] ?? ''));
    if ($provided !== '') {
        return $provided;
    }

    $retryState = flus_facturacion_manual_retry_state_buscar($clienteId, $items);
    if (is_array($retryState) && trim((string)($retryState['request_uid'] ?? '')) !== '') {
        return (string)$retryState['request_uid'];
    }

    $fingerprint = flus_facturacion_manual_retry_fingerprint($clienteId, $items, $meta, $opciones);
    return flus_facturacion_uuid_from_seed('FACTURA_MANUAL|' . $fingerprint);
}

function flus_facturacion_json_decode_assoc(?string $raw): array
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return [];
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function flus_facturacion_evento_operacion(array $opciones = []): string
{
    return !empty($opciones['origen_manual']) ? 'FACTURA_MANUAL' : 'FACTURA_VENTA';
}

<?php
declare(strict_types=1);

function flus_facturacion_arca_status_read(PDO $pdo): array
{
    $status = trim((string) config_get($pdo, 'facturacion_arca_status', ''));
    $status = in_array($status, ['available', 'unavailable', 'not_required', 'unknown'], true) ? $status : 'unknown';

    return [
        'status' => $status,
        'mode' => flus_facturacion_normalizar_modo((string) config_get($pdo, 'facturacion_arca_status_mode', 'demo')),
        'last_error' => trim((string) config_get($pdo, 'facturacion_arca_last_error', '')),
        'checked_at' => trim((string) config_get($pdo, 'facturacion_arca_checked_at', '')),
    ];
}

function flus_facturacion_arca_status_write(PDO $pdo, string $status, string $modo, ?string $lastError = null): array
{
    $status = in_array($status, ['available', 'unavailable', 'not_required', 'unknown'], true) ? $status : 'unknown';
    $modo = flus_facturacion_normalizar_modo($modo);
    $checkedAt = date('Y-m-d H:i:s');
    $lastError = trim((string) $lastError);

    config_set($pdo, 'facturacion_arca_status', $status);
    config_set($pdo, 'facturacion_arca_status_mode', $modo);
    config_set($pdo, 'facturacion_arca_last_error', $lastError);
    config_set($pdo, 'facturacion_arca_checked_at', $checkedAt);

    $canEmit = in_array($status, ['available', 'not_required'], true);

    return [
        'status' => $status,
        'label' => flus_facturacion_arca_status_label($status),
        'mode' => $modo,
        'required' => $status !== 'not_required',
        'available' => $status === 'available',
        'can_emit' => $canEmit,
        'last_error' => $lastError,
        'checked_at' => $checkedAt,
    ];
}

function flus_facturacion_arca_status_current(PDO $pdo, ?string $modoEsperado = null, bool $forceProbe = false): array
{
    $modo = flus_facturacion_normalizar_modo($modoEsperado ?? (string) config_get($pdo, 'facturacion_modo', 'demo'));
    $facturacionActiva = flus_facturacion_habilitada($pdo);
    $requiereArca = $facturacionActiva && flus_facturacion_modo_requires_arca($modo);

    if (!$requiereArca) {
        return [
            'status' => 'not_required',
            'label' => flus_facturacion_arca_status_label('not_required'),
            'mode' => $modo,
            'required' => false,
            'available' => true,
            'can_emit' => true,
            'last_error' => '',
            'checked_at' => '',
        ];
    }

    $preflight = flus_facturacion_preflight_arca($modo);
    if (!($preflight['ok'] ?? false)) {
        $lastError = flus_facturacion_arca_preflight_error($preflight);
        if ($forceProbe) {
            return flus_facturacion_arca_status_write($pdo, 'unavailable', $modo, $lastError);
        }

        return [
            'status' => 'unavailable',
            'label' => flus_facturacion_arca_status_label('unavailable'),
            'mode' => $modo,
            'required' => true,
            'available' => false,
            'can_emit' => false,
            'last_error' => $lastError,
            'checked_at' => '',
        ];
    }

    $cached = flus_facturacion_arca_status_read($pdo);
    if (!$forceProbe && $cached['status'] !== 'unknown' && $cached['mode'] === $modo) {
        return [
            'status' => $cached['status'],
            'label' => flus_facturacion_arca_status_label($cached['status']),
            'mode' => $modo,
            'required' => true,
            'available' => $cached['status'] === 'available',
            'can_emit' => $cached['status'] === 'available',
            'last_error' => (string) $cached['last_error'],
            'checked_at' => (string) $cached['checked_at'],
        ];
    }

    if (!$forceProbe) {
        return [
            'status' => 'unknown',
            'label' => flus_facturacion_arca_status_label('unknown'),
            'mode' => $modo,
            'required' => true,
            'available' => false,
            'can_emit' => false,
            'last_error' => 'Sin verificacion reciente. Usa "Probar conexion con ARCA".',
            'checked_at' => '',
        ];
    }

    $resultado = verificarConexionAfip();
    if (!empty($resultado['conectado'])) {
        return flus_facturacion_arca_status_write($pdo, 'available', $modo, '');
    }

    return flus_facturacion_arca_status_write(
        $pdo,
        'unavailable',
        $modo,
        trim((string) ($resultado['mensaje'] ?? 'No se pudo validar la conexion con ARCA.'))
    );
}

function flus_facturacion_arca_assert_emitible(PDO $pdo, string $modo): void
{
    if (!flus_facturacion_modo_requires_arca($modo)) {
        return;
    }

    $estado = flus_facturacion_arca_status_current($pdo, $modo, true);
    if (!empty($estado['can_emit'])) {
        return;
    }

    $detalle = trim((string) ($estado['last_error'] ?? ''));
    throw new RuntimeException(
        flus_facturacion_humanizar_error_arca($detalle !== '' ? $detalle : flus_facturacion_arca_emision_bloqueada_message())
    );
}

function flus_facturacion_preflight_emision(PDO $pdo, ?array $config = null, array $opciones = []): array
{
    $config = is_array($config) ? $config : flus_facturacion_config_activa($pdo, false);
    $modo = $config !== null
        ? flus_facturacion_modo_actual($config, $opciones)
        : flus_facturacion_normalizar_modo((string)($opciones['modo'] ?? config_get($pdo, 'facturacion_modo', 'demo')));
    $requiereArca = flus_facturacion_modo_requires_arca($modo);

    $items = [];

    $items[] = [
        'key' => 'config_activa',
        'label' => 'Configuracion activa',
        'status' => $config !== null ? 'ok' : 'error',
        'value' => $config !== null ? 'Detectada' : 'Pendiente',
        'hint' => $config !== null ? 'Se encontro un punto de venta activo para emitir.' : 'Completa Configuracion de Facturacion antes de emitir.',
    ];

    if ($config === null) {
        return [
            'ok' => false,
            'modo' => $modo,
            'requiere_arca' => $requiereArca,
            'items' => $items,
            'warnings' => [],
            'arca' => null,
        ];
    }

    $puntoVenta = max(0, (int)($config['punto_venta'] ?? 0));
    $razonSocial = trim((string)($config['razon_social'] ?? config_get($pdo, 'business_name', '')));
    $cuit = flus_facturacion_normalizar_doc((string)($config['cuit'] ?? config_get($pdo, 'business_cuit', '')));
    $domicilio = trim((string)($config['domicilio'] ?? config_get($pdo, 'business_address', '')));
    $condIva = strtoupper(trim((string)($config['cond_iva'] ?? '')));
    $iibb = trim((string)config_get($pdo, 'business_iibb', ''));
    $inicioActividades = trim((string)config_get($pdo, 'business_inicio_actividades', ''));
    $proximoNumero = max(0, (int)($config['proximo_numero'] ?? 0));

    $items[] = [
        'key' => 'modo',
        'label' => 'Modo de facturacion',
        'status' => in_array($modo, ['demo', 'homologacion', 'produccion'], true) ? 'ok' : 'error',
        'value' => flus_facturacion_modo_label($modo),
        'hint' => $requiereArca ? 'La emision fiscal usa ARCA en este modo.' : 'En demo no se emite contra ARCA.',
    ];
    $items[] = [
        'key' => 'punto_venta',
        'label' => 'Punto de venta',
        'status' => $puntoVenta > 0 ? 'ok' : 'error',
        'value' => $puntoVenta > 0 ? str_pad((string)$puntoVenta, 4, '0', STR_PAD_LEFT) : 'Pendiente',
        'hint' => 'Debe coincidir con un punto de venta habilitado en ARCA.',
    ];
    $items[] = [
        'key' => 'razon_social',
        'label' => 'Razon social',
        'status' => $razonSocial !== '' ? 'ok' : ($requiereArca ? 'error' : 'warning'),
        'value' => $razonSocial !== '' ? $razonSocial : 'Pendiente',
        'hint' => 'Se usa en el encabezado fiscal del comprobante.',
    ];
    $items[] = [
        'key' => 'cuit',
        'label' => 'CUIT emisor local',
        'status' => strlen($cuit) === 11 ? 'ok' : ($requiereArca ? 'error' : 'warning'),
        'value' => $cuit !== '' ? $cuit : 'Pendiente',
        'hint' => 'Debe coincidir con la configuracion fiscal activa.',
    ];
    $items[] = [
        'key' => 'domicilio',
        'label' => 'Domicilio fiscal',
        'status' => $domicilio !== '' ? 'ok' : ($requiereArca ? 'error' : 'warning'),
        'value' => $domicilio !== '' ? $domicilio : 'Pendiente',
        'hint' => 'Se imprime en la representacion del comprobante.',
    ];
    $items[] = [
        'key' => 'cond_iva',
        'label' => 'Condicion IVA emisor',
        'status' => in_array($condIva, ['RI', 'MT', 'EX'], true) ? 'ok' : 'error',
        'value' => $condIva !== '' ? $condIva : 'Pendiente',
        'hint' => 'Se usa para resolver el tipo de comprobante.',
    ];
    $items[] = [
        'key' => 'numeracion_local',
        'label' => 'Proximo numero local',
        'status' => $proximoNumero > 0 ? 'ok' : 'error',
        'value' => $proximoNumero > 0 ? (string)$proximoNumero : 'Pendiente',
        'hint' => $requiereArca
            ? 'Si cambiaste modo o punto de venta, sincroniza antes de emitir.'
            : 'En demo se usa numeracion local de trabajo.',
    ];

    $warnings = [];
    if ($requiereArca && $iibb === '') {
        $warnings[] = 'Falta cargar Ingresos Brutos en la configuracion general.';
    }
    if ($requiereArca && $inicioActividades === '') {
        $warnings[] = 'Falta cargar inicio de actividades en la configuracion general.';
    }

    $arcaEstado = flus_facturacion_arca_status_current($pdo, $modo, false);
    $items[] = [
        'key' => 'arca',
        'label' => 'Estado ARCA',
        'status' => !empty($arcaEstado['can_emit']) ? 'ok' : ($requiereArca ? 'error' : 'warning'),
        'value' => (string)($arcaEstado['label'] ?? 'ARCA no disponible'),
        'hint' => trim((string)($arcaEstado['last_error'] ?? '')) !== ''
            ? trim((string)$arcaEstado['last_error'])
            : (trim((string)($arcaEstado['checked_at'] ?? '')) !== ''
                ? 'Ultima verificacion: ' . (string)$arcaEstado['checked_at']
                : 'Usa "Probar conexion con ARCA" antes de emitir.'),
    ];

    $ok = true;
    foreach ($items as $item) {
        if (($item['status'] ?? 'ok') === 'error') {
            $ok = false;
            break;
        }
    }

    return [
        'ok' => $ok,
        'modo' => $modo,
        'requiere_arca' => $requiereArca,
        'items' => $items,
        'warnings' => $warnings,
        'arca' => $arcaEstado,
    ];
}

function flus_facturacion_preflight_emision_error(array $preflight): string
{
    foreach ((array)($preflight['items'] ?? []) as $item) {
        if (($item['status'] ?? '') !== 'error') {
            continue;
        }

        $label = trim((string)($item['label'] ?? 'Preflight'));
        $value = trim((string)($item['value'] ?? ''));
        $hint = trim((string)($item['hint'] ?? ''));
        $parts = array_values(array_filter([
            $label,
            $value !== '' ? $value : null,
            $hint !== '' ? $hint : null,
        ]));
        if ($parts !== []) {
            return implode(' - ', $parts);
        }
    }

    $warnings = array_values(array_filter(array_map('strval', (array)($preflight['warnings'] ?? []))));
    if ($warnings !== []) {
        return $warnings[0];
    }

    return 'La emision fiscal no esta lista para producir comprobantes.';
}

function flus_facturacion_assert_preflight_emision(PDO $pdo, ?array $config = null, array $opciones = []): array
{
    $preflight = flus_facturacion_preflight_emision($pdo, $config, $opciones);
    if (!($preflight['ok'] ?? false)) {
        throw new RuntimeException(flus_facturacion_preflight_emision_error($preflight));
    }

    if (!empty($preflight['requiere_arca'])) {
        flus_facturacion_arca_assert_emitible($pdo, (string)($preflight['modo'] ?? 'demo'));
    }

    return $preflight;
}

function flus_facturacion_humanizar_error_arca(?string $raw): string
{
    $message = trim((string)$raw);
    if ($message === '') {
        return 'ARCA no devolvio detalle del error. Intenta nuevamente en unos minutos.';
    }

    if (str_starts_with($message, 'Error de AFIP: ')) {
        $message = substr($message, strlen('Error de AFIP: '));
    }

    if (flus_facturacion_arca_is_availability_error($message)) {
        return flus_facturacion_arca_emision_bloqueada_message();
    }

    if (preg_match('/\[(\d+)\]/', $message, $matches) === 1) {
        $code = $matches[1];
        return match ($code) {
            '10016' => 'ARCA informo que ese numero de comprobante ya existe. FLUS puede reintentar con el siguiente numero disponible.',
            '10022' => 'ARCA rechazo el comprobante porque el IVA debe informarse una sola vez por alicuota. Revisa los items y vuelve a emitir.',
            default => 'ARCA rechazo el comprobante: ' . $message,
        };
    }

    if (stripos($message, 'No se pudo obtener TA') !== false || stripos($message, 'autentic') !== false) {
        return 'No se pudo autenticar con ARCA. Revisa certificado, clave privada y CUIT configurados.';
    }

    if (stripos($message, 'SOAP Fault') !== false || stripos($message, 'No se pudo conectar al WSFE') !== false || stripos($message, 'Error WSFE') !== false) {
        return 'No se pudo conectar con ARCA en este momento. Intenta nuevamente en unos minutos.';
    }

    if (stripos($message, 'Falta configurar FLUS_ARCA_CUIT') !== false) {
        return 'Falta configurar el CUIT del emisor para operar con ARCA.';
    }

    if (stripos($message, 'FLUS_ARCA_ENV') !== false) {
        return 'La configuracion del entorno ARCA no coincide con el modo de facturacion seleccionado.';
    }

    return 'ARCA rechazo el comprobante: ' . $message;
}

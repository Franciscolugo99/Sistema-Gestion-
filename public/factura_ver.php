<?php
// public/factura_ver.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/db_schema.php';
require_once __DIR__ . '/../src/facturacion_lib.php';
require_once __DIR__ . '/../src/facturacion_manual_lib.php';
require_once __DIR__ . '/../src/factura_view_lib.php';
require_once __DIR__ . '/../src/cobranzas_lib.php';

$requestedFacturaId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$pdfToken = trim((string)($_GET['pdf_token'] ?? ''));
$pdfMode = isset($_GET['pdf']) && $_GET['pdf'] === '1';
$pdfTokenValid = $pdfMode && flus_factura_pdf_token_validate($pdfToken, $requestedFacturaId);
$autoPrint = $pdfMode && $pdfTokenValid && isset($_GET['autoprint']) && $_GET['autoprint'] === '1';

if (!$pdfTokenValid) {
    require_login();
    require_any_permission(['ver_facturacion', 'emitir_factura']);
}

function factura_tipo_letra(string $tipo): string
{
    if (preg_match('/([ABCME])$/i', trim($tipo), $m) === 1) {
        return strtoupper((string)$m[1]);
    }
    return 'X';
}

function factura_tipo_cbte_id(string $tipo): int
{
    return match (strtoupper(trim($tipo))) {
        'FA' => 1,
        'NDA' => 2,
        'NCA' => 3,
        'FB' => 6,
        'NDB' => 7,
        'NCB' => 8,
        'FC' => 11,
        'NDC' => 12,
        'NCC' => 13,
        default => 0,
    };
}

function factura_formatear_cuit(?string $value): string
{
    $digits = preg_replace('/\D+/', '', (string)$value);
    if (strlen($digits) === 11) {
        return substr($digits, 0, 2) . '-' . substr($digits, 2, 8) . '-' . substr($digits, 10, 1);
    }
    return trim((string)$value);
}

function factura_valor_no_demo(?string $value): string
{
    $clean = trim((string)$value);
    if ($clean === '') {
        return '';
    }

    $normalized = strtoupper($clean);
    if (in_array($normalized, ['MI KIOSCO DEMO', 'KIOSCO XYZ'], true)) {
        return '';
    }
    if (str_contains($normalized, 'SIEMPRE VIVA')) {
        return '';
    }

    return $clean;
}

function factura_logo_src(?string $value): ?string
{
    $raw = trim(str_replace('\\', '/', (string)$value));
    if ($raw === '') {
        return null;
    }
    if (preg_match('~^https?://~i', $raw) === 1) {
        return $raw;
    }
    if (str_starts_with($raw, '/')) {
        return $raw;
    }
    return $raw;
}

function factura_supported_iva_rates(): array
{
    return [27.0, 21.0, 10.5, 5.0, 2.5, 0.0];
}

function factura_normalizar_iva(float $rate): float
{
    $best = 21.0;
    $delta = INF;
    foreach (factura_supported_iva_rates() as $candidate) {
        $diff = abs($candidate - $rate);
        if ($diff < $delta) {
            $delta = $diff;
            $best = $candidate;
        }
    }
    return $delta <= 0.26 ? $best : round($rate, 2);
}

function factura_rate_label(float $rate): string
{
    $formatted = number_format($rate, ($rate === floor($rate)) ? 0 : 1, ',', '.');
    return $formatted . '%';
}

function factura_guess_item_iva(array $factura, array $rows): float
{
    foreach ($rows as $row) {
        foreach (['iva_porcentaje', 'producto_iva', 'iva'] as $key) {
            if (isset($row[$key]) && $row[$key] !== null && $row[$key] !== '') {
                return factura_normalizar_iva((float)$row[$key]);
            }
        }
    }

    $neto = isset($factura['importe_neto']) ? (float)$factura['importe_neto'] : 0.0;
    $iva = isset($factura['importe_iva']) ? (float)$factura['importe_iva'] : 0.0;
    if ($neto > 0 && $iva >= 0) {
        return factura_normalizar_iva(($iva / $neto) * 100);
    }

    return 21.0;
}

function factura_normalizar_items(array $rows, array $factura): array
{
    $fallbackIva = factura_guess_item_iva($factura, $rows);
    $items = [];

    foreach ($rows as $row) {
        $cantidad = (float)($row['cantidad'] ?? 0);
        if ($cantidad <= 0) {
            $cantidad = 1.0;
        }

        $subtotal = (float)($row['subtotal'] ?? 0);
        $precioUnit = 0.0;
        foreach (['precio_unitario', 'precio_unit_final', 'precio'] as $key) {
            if (isset($row[$key]) && $row[$key] !== null && $row[$key] !== '') {
                $precioUnit = (float)$row[$key];
                break;
            }
        }
        if ($precioUnit <= 0 && $cantidad > 0) {
            $precioUnit = $subtotal / $cantidad;
        }
        if ($subtotal <= 0 && $precioUnit > 0) {
            $subtotal = $precioUnit * $cantidad;
        }

        $ivaPct = null;
        foreach (['iva_porcentaje', 'producto_iva', 'iva'] as $key) {
            if (isset($row[$key]) && $row[$key] !== null && $row[$key] !== '') {
                $ivaPct = (float)$row[$key];
                break;
            }
        }
        $ivaPct = factura_normalizar_iva($ivaPct ?? $fallbackIva);
        $base = $ivaPct > 0 ? $subtotal / (1 + ($ivaPct / 100)) : $subtotal;
        $ivaImporte = $subtotal - $base;

        $items[] = [
            'codigo' => trim((string)($row['codigo'] ?? '')),
            'descripcion' => trim((string)($row['descripcion'] ?? $row['nombre'] ?? 'Sin descripcion')),
            'cantidad' => $cantidad,
            'precio_unitario' => round($precioUnit, 2),
            'subtotal' => round($subtotal, 2),
            'iva_pct' => $ivaPct,
            'neto' => round($base, 2),
            'iva_importe' => round($ivaImporte, 2),
        ];
    }

    return $items;
}

function factura_resumen_fiscal(array $items, array $factura): array
{
    $rates = [];
    $netoGravado = 0.0;
    $exento = 0.0;
    $ivaTotal = 0.0;
    $total = 0.0;

    foreach ($items as $item) {
        $rate = (float)($item['iva_pct'] ?? 0);
        $neto = (float)($item['neto'] ?? 0);
        $iva = (float)($item['iva_importe'] ?? 0);
        $subtotal = (float)($item['subtotal'] ?? 0);
        $total += $subtotal;

        if ($rate <= 0) {
            $exento += $subtotal;
            continue;
        }

        $key = sprintf('%.2f', $rate);
        if (!isset($rates[$key])) {
            $rates[$key] = [
                'rate' => $rate,
                'neto' => 0.0,
                'iva' => 0.0,
                'total' => 0.0,
            ];
        }
        $rates[$key]['neto'] += $neto;
        $rates[$key]['iva'] += $iva;
        $rates[$key]['total'] += $subtotal;
        $netoGravado += $neto;
        $ivaTotal += $iva;
    }

    if ($items === []) {
        $netoGravado = (float)($factura['importe_neto'] ?? 0);
        $ivaTotal = (float)($factura['importe_iva'] ?? 0);
        $exento = (float)($factura['importe_exento'] ?? 0);
        $total = (float)($factura['total'] ?? 0);
        if ($netoGravado > 0 || $ivaTotal > 0) {
            $rate = factura_normalizar_iva($ivaTotal > 0 ? (($ivaTotal / $netoGravado) * 100) : 21.0);
            $rates[sprintf('%.2f', $rate)] = [
                'rate' => $rate,
                'neto' => $netoGravado,
                'iva' => $ivaTotal,
                'total' => $netoGravado + $ivaTotal,
            ];
        }
    }

    $rows = array_values($rates);
    usort($rows, static fn(array $a, array $b): int => $b['rate'] <=> $a['rate']);

    return [
        'neto_gravado' => round($netoGravado, 2),
        'exento' => round($exento, 2),
        'iva_total' => round($ivaTotal, 2),
        'subtotal' => round($netoGravado + $exento, 2),
        'total' => round($total > 0 ? $total : (float)($factura['total'] ?? 0), 2),
        'rates' => $rows,
    ];
}

function factura_qr_verify_url(string $baseUrl, string $payloadB64): string
{
    $baseUrl = trim($baseUrl);
    if ($baseUrl === '') {
        $baseUrl = 'https://www.arca.gob.ar/fe/qr/';
    }

    if (str_contains($baseUrl, '?p=')) {
        return $baseUrl . rawurlencode($payloadB64);
    }

    return rtrim($baseUrl, '/') . '/?p=' . rawurlencode($payloadB64);
}

function factura_qr_data(array $factura, string $fechaIso, string $empresaCuit, string $clienteCuit, string $cae, int $tipoCbte, PDO $pdo): ?array
{
    $emisorDigits = preg_replace('/\D+/', '', $empresaCuit);
    $clienteDigits = preg_replace('/\D+/', '', $clienteCuit);
    if ($emisorDigits === '' || $cae === '' || str_starts_with($cae, 'DEMO') || $tipoCbte <= 0) {
        return null;
    }

    $payload = [
        'ver' => 1,
        'fecha' => $fechaIso,
        'cuit' => (int)$emisorDigits,
        'ptoVta' => (int)($factura['punto_venta'] ?? 0),
        'tipoCmp' => $tipoCbte,
        'nroCmp' => (int)($factura['numero'] ?? 0),
        'importe' => round((float)($factura['total'] ?? 0), 2),
        'moneda' => 'PES',
        'ctz' => 1,
        'tipoDocRec' => strlen($clienteDigits) === 11 ? 80 : 99,
        'nroDocRec' => strlen($clienteDigits) === 11 ? (int)$clienteDigits : 0,
        'tipoCodAut' => 'E',
        'codAut' => (int)$cae,
    ];

    $b64 = base64_encode((string)json_encode($payload, JSON_UNESCAPED_SLASHES));
    $verifyUrl = factura_qr_verify_url((string)config_get($pdo, 'qr_base_url', 'https://www.arca.gob.ar/fe/qr/'), $b64);

    return [
        'verify_url' => $verifyUrl,
        'image_url' => 'https://quickchart.io/qr?size=320&margin=1&format=png&ecLevel=H&text=' . rawurlencode($verifyUrl),
    ];
}

function factura_numero_letras_masc(int $number): string
{
    $units = [
        0 => 'CERO', 1 => 'UN', 2 => 'DOS', 3 => 'TRES', 4 => 'CUATRO', 5 => 'CINCO', 6 => 'SEIS', 7 => 'SIETE', 8 => 'OCHO', 9 => 'NUEVE',
        10 => 'DIEZ', 11 => 'ONCE', 12 => 'DOCE', 13 => 'TRECE', 14 => 'CATORCE', 15 => 'QUINCE', 16 => 'DIECISEIS', 17 => 'DIECISIETE',
        18 => 'DIECIOCHO', 19 => 'DIECINUEVE', 20 => 'VEINTE', 21 => 'VEINTIUN', 22 => 'VEINTIDOS', 23 => 'VEINTITRES', 24 => 'VEINTICUATRO',
        25 => 'VEINTICINCO', 26 => 'VEINTISEIS', 27 => 'VEINTISIETE', 28 => 'VEINTIOCHO', 29 => 'VEINTINUEVE',
    ];
    $tens = [3 => 'TREINTA', 4 => 'CUARENTA', 5 => 'CINCUENTA', 6 => 'SESENTA', 7 => 'SETENTA', 8 => 'OCHENTA', 9 => 'NOVENTA'];
    $hundreds = [1 => 'CIENTO', 2 => 'DOSCIENTOS', 3 => 'TRESCIENTOS', 4 => 'CUATROCIENTOS', 5 => 'QUINIENTOS', 6 => 'SEISCIENTOS', 7 => 'SETECIENTOS', 8 => 'OCHOCIENTOS', 9 => 'NOVECIENTOS'];

    if ($number < 30) {
        return $units[$number] ?? '';
    }
    if ($number < 100) {
        $ten = intdiv($number, 10);
        $rest = $number % 10;
        return $rest === 0 ? $tens[$ten] : $tens[$ten] . ' Y ' . factura_numero_letras_masc($rest);
    }
    if ($number === 100) {
        return 'CIEN';
    }
    if ($number < 1000) {
        $hundred = intdiv($number, 100);
        $rest = $number % 100;
        return $rest === 0 ? $hundreds[$hundred] : $hundreds[$hundred] . ' ' . factura_numero_letras_masc($rest);
    }
    if ($number < 1000000) {
        $thousands = intdiv($number, 1000);
        $rest = $number % 1000;
        $prefix = $thousands === 1 ? 'MIL' : factura_numero_letras_masc($thousands) . ' MIL';
        return $rest === 0 ? $prefix : $prefix . ' ' . factura_numero_letras_masc($rest);
    }
    if ($number < 1000000000) {
        $millions = intdiv($number, 1000000);
        $rest = $number % 1000000;
        $prefix = $millions === 1 ? 'UN MILLON' : factura_numero_letras_masc($millions) . ' MILLONES';
        return $rest === 0 ? $prefix : $prefix . ' ' . factura_numero_letras_masc($rest);
    }

    return (string)$number;
}

function factura_importe_en_letras(float $amount): string
{
    $amount = round($amount, 2);
    $entero = (int)floor($amount);
    $centavos = (int)round(($amount - $entero) * 100);
    $letras = factura_numero_letras_masc($entero);
    return 'SON: PESOS ' . $letras . ' CON ' . str_pad((string)$centavos, 2, '0', STR_PAD_LEFT) . '/100.';
}

function factura_caja_abierta_actual(PDO $pdo): int
{
    $sessionCajaId = (int)($_SESSION['caja_id'] ?? 0);
    if ($sessionCajaId > 0) {
        return $sessionCajaId;
    }

    $terminalId = function_exists('current_terminal_id') ? current_terminal_id() : (int)($_SESSION['terminal_id'] ?? 0);
    if ($terminalId <= 0 || !flus_table_exists($pdo, 'caja_sesiones')) {
        return 0;
    }

    try {
        $st = $pdo->prepare("
            SELECT id FROM caja_sesiones
            WHERE terminal_id = ?
              AND (fecha_cierre IS NULL OR fecha_cierre = '0000-00-00 00:00:00')
            ORDER BY id DESC
            LIMIT 1
        ");
        $st->execute([$terminalId]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

$facturacionHabilitada = config_get($pdo, 'facturacion_habilitada', '0') === '1';
if (!$facturacionHabilitada) {
    header('Location: index.php');
    exit;
}

$id = $requestedFacturaId;
if ($id <= 0) {
    http_response_code(400);
    flus_abort(400, 'ID de factura invalido');
}

$viewData = flus_factura_view_load($pdo, $id);
if (!is_array($viewData)) {
    http_response_code(404);
    flus_abort(404, 'Factura no encontrada');
}

$factura = $viewData['factura'];
$estadoFiscal = (string)$viewData['estado_fiscal'];
$estadoFiscalLabel = (string)$viewData['estado_fiscal_label'];
$fiscalRequestUid = (string)$viewData['fiscal_request_uid'];
$fiscalIntentos = (int)$viewData['fiscal_intentos'];
$fiscalErrorCode = (string)$viewData['fiscal_error_code'];
$fiscalErrorMessage = (string)$viewData['fiscal_error_message'];
$fiscalRequestedAt = (string)$viewData['fiscal_requested_at'];
$fiscalApprovedAt = (string)$viewData['fiscal_approved_at'];
$fiscalCerradaAt = (string)($viewData['fiscal_cerrada_at'] ?? '');
$fiscalCierreMotivo = (string)($viewData['fiscal_cierre_motivo'] ?? '');
$envioUltimoCanal = (string)$viewData['envio_ultimo_canal'];
$envioUltimoEstado = (string)$viewData['envio_ultimo_estado'];
$envioUltimoDestino = (string)$viewData['envio_ultimo_destino'];
$envioUltimoAt = (string)$viewData['envio_ultimo_at'];
$envioUltimoError = (string)$viewData['envio_ultimo_error'];
$fiscalDetalleOperativo = (string)$viewData['fiscal_detalle_operativo'];
$fiscalRegularizable = (bool)$viewData['fiscal_regularizable'];
$fiscalEventoArca = $viewData['fiscal_evento_arca'];
$arcaResultado = (string)$viewData['arca_resultado'];
$arcaOperacion = (string)$viewData['arca_operacion'];
$arcaModo = (string)$viewData['arca_modo'];
$arcaAt = (string)$viewData['arca_at'];
$arcaError = (string)$viewData['arca_error'];
$fiscalData = $viewData['fiscal_data'];
$itemRows = $viewData['item_rows'];
$items = $viewData['items'];
$resumenFiscal = $viewData['resumen_fiscal'];
$cobranzaResumen = $viewData['cobranza_resumen'];
$recibosAsociados = $viewData['recibos_asociados'];
$documentoComercial = $viewData['documento_comercial'];
$documentoComercialOrigen = $viewData['documento_comercial_origen'];
$configEmpresa = $viewData['config_empresa'];

$mapCondIva = [
    'RI' => 'Responsable Inscripto',
    'MT' => 'Monotributo',
    'EX' => 'Exento',
    'CF' => 'Consumidor Final',
];

$empresaNombre = factura_valor_no_demo($configEmpresa['razon_social'] ?? '');
if ($empresaNombre === '') {
    $empresaNombre = factura_valor_no_demo(config_get($pdo, 'business_name', ''));
}
if ($empresaNombre === '') {
    $empresaNombre = 'Completar emisor';
}

$empresaCuitRaw = trim((string)($configEmpresa['cuit'] ?? ''));
if ($empresaCuitRaw === '' && defined('FLUS_ARCA_CUIT')) {
    $empresaCuitRaw = (string)FLUS_ARCA_CUIT;
}
if ($empresaCuitRaw === '') {
    $empresaCuitRaw = (string)config_get($pdo, 'business_cuit', '');
}
$empresaCUIT = factura_formatear_cuit($empresaCuitRaw);
if ($empresaCUIT === '') {
    $empresaCUIT = '-';
}

$empresaCondRaw = strtoupper(trim((string)($configEmpresa['cond_iva'] ?? '')));
if ($empresaCondRaw === '') {
    $empresaCondRaw = str_ends_with(strtoupper((string)($factura['tipo'] ?? '')), 'C') ? 'MT' : 'RI';
}
$empresaIVA = $mapCondIva[$empresaCondRaw] ?? 'Responsable Inscripto';

$empresaDireccion = factura_valor_no_demo($configEmpresa['domicilio'] ?? '');
if ($empresaDireccion === '') {
    $empresaDireccion = factura_valor_no_demo(config_get($pdo, 'business_address', ''));
}
if ($empresaDireccion === '') {
    $empresaDireccion = '-';
}

$empresaIIBB = trim((string)config_get($pdo, 'business_iibb', '')) ?: '-';
$empresaInicio = trim((string)config_get($pdo, 'business_inicio_actividades', '')) ?: '-';
$empresaLogo = factura_logo_src((string)config_get($pdo, 'business_logo_url', ''));

$empresaPendientes = [];
if ($empresaNombre === 'Completar emisor') {
    $empresaPendientes[] = 'razon social';
}
if ($empresaDireccion === '-') {
    $empresaPendientes[] = 'domicilio fiscal';
}
if ($empresaIIBB === '-') {
    $empresaPendientes[] = 'ingresos brutos';
}
if ($empresaInicio === '-') {
    $empresaPendientes[] = 'inicio de actividades';
}
$empresaDatosCompletos = $empresaPendientes === [];

$tipo = (string)($factura['tipo'] ?? '');
$tipoCbte = factura_tipo_cbte_id($tipo);
$modoFactura = flus_facturacion_normalizar_modo((string)($factura['modo'] ?? 'demo'));
$cae = trim((string)($factura['cae'] ?? ''));
if ($cae === '' && is_array($fiscalData)) {
    $cae = trim((string)($fiscalData['cae'] ?? ''));
}
$caeVtoRaw = trim((string)($factura['cae_vto'] ?? ''));
if ($caeVtoRaw === '' && is_array($fiscalData)) {
    $caeVtoRaw = trim((string)($fiscalData['cae_vto'] ?? ''));
}

if (($cae === '' || $caeVtoRaw === '') && $modoFactura !== 'demo') {
    require_once __DIR__ . '/includes/ArcaWsfe.php';

    if ($tipoCbte > 0) {
        $remoto = ArcaWsfe::consultarComprobante((int)$factura['punto_venta'], $tipoCbte, (int)$factura['numero']);
        if (is_array($remoto)) {
            if ($cae === '') {
                $cae = trim((string)($remoto['cae'] ?? ''));
            }
            if ($caeVtoRaw === '') {
                $caeVtoRaw = trim((string)($remoto['cae_vto'] ?? ''));
            }

            try {
                if ((($cae !== '') || ($caeVtoRaw !== '')) && flus_column_exists($pdo, 'facturas', 'cae') && flus_column_exists($pdo, 'facturas', 'cae_vto')) {
                    $stSave = $pdo->prepare('UPDATE facturas SET cae = ?, cae_vto = ? WHERE id = ?');
                    $stSave->execute([
                        $cae !== '' ? $cae : null,
                        flus_facturacion_normalizar_cae_vto($caeVtoRaw !== '' ? $caeVtoRaw : null),
                        (int)$factura['id'],
                    ]);
                }

                flus_facturacion_upsert_venta_fiscal($pdo, (int)$factura['venta_id'], [
                    'punto_venta' => (int)$factura['punto_venta'],
                    'tipo_cbte' => $tipoCbte,
                    'numero' => (int)$factura['numero'],
                    'cae' => $cae,
                    'cae_vto' => $caeVtoRaw,
                    'moneda_id' => 'PES',
                    'moneda_cotiz' => 1,
                ]);
            } catch (Throwable $e) {
                // Mostrar > persistir.
            }
        }
    }
}

$fechaRaw = (string)($factura['creado_en'] ?? $factura['venta_fecha'] ?? '');
$fechaTs = $fechaRaw !== '' ? strtotime($fechaRaw) : false;
$fechaFmt = $fechaTs !== false ? date('d/m/Y H:i:s', $fechaTs) : '-';
$fechaCorta = $fechaTs !== false ? date('d/m/Y', $fechaTs) : '-';
$fechaEmisionQr = $fechaTs !== false ? date('Y-m-d', $fechaTs) : date('Y-m-d');

if ($caeVtoRaw !== '') {
    if (preg_match('/^\d{8}$/', $caeVtoRaw) === 1) {
        $dtCae = DateTime::createFromFormat('Ymd', $caeVtoRaw);
        $caeVto = $dtCae ? $dtCae->format('d/m/Y') : $caeVtoRaw;
    } else {
        $tsCae = strtotime($caeVtoRaw);
        $caeVto = $tsCae !== false ? date('d/m/Y', $tsCae) : $caeVtoRaw;
    }
} else {
    $caeVto = '00/00/0000';
}
if ($cae === '') {
    $cae = '00000000000000';
}

if ($modoFactura === 'produccion' && flus_facturacion_arca_env_actual() === 'homo' && !str_starts_with($cae, 'DEMO')) {
    $modoFactura = 'homologacion';
}
$modoFacturaLabel = flus_facturacion_modo_label($modoFactura);
$esDemo = $modoFactura === 'demo' || str_starts_with($cae, 'DEMO');
$footerModo = $esDemo
    ? 'CAE de demostracion'
    : ($modoFactura === 'homologacion' ? 'Datos autorizados por ARCA en homologacion' : 'Datos fiscales informados por AFIP/ARCA');

$clienteNombre = trim((string)($factura['cliente_nombre'] ?? '')) ?: 'Consumidor Final';
$clienteCuit = factura_formatear_cuit((string)($factura['cliente_cuit'] ?? '')) ?: '-';
$clienteCondIva = $mapCondIva[strtoupper(trim((string)($factura['cliente_cond_iva'] ?? 'CF')))] ?? ((string)($factura['cliente_cond_iva'] ?? 'Consumidor Final'));
$clienteDireccion = trim((string)($factura['cliente_direccion'] ?? '')) ?: '-';
$condVentaRaw = trim((string)($factura['venta_medio_pago'] ?? ''));
$condVentaNorm = strtoupper(str_replace([' ', '-'], '_', $condVentaRaw));
$mostrarCondVenta = $condVentaNorm !== '' && !in_array($condVentaNorm, ['FACTURA_MANUAL', 'FACTURA'], true);
$condVenta = $mostrarCondVenta ? $condVentaRaw : '';
$notaVenta = trim((string)($factura['venta_nota'] ?? ''));
$notaVentaNormalizada = function_exists('mb_strtolower') ? mb_strtolower($notaVenta, 'UTF-8') : strtolower($notaVenta);
$observacionesTexto = $notaVenta;
$observacionesEsDefault = $observacionesTexto === '' || in_array($notaVentaNormalizada, ['factura manual', 'factura', 'factura manual sin caja', 'venta'], true);
if ($observacionesEsDefault) {
    $observacionesTexto = 'Comprobante generado por FLUS';
}
$mostrarObservaciones = !$observacionesEsDefault;
$mostrarBloquePago = $mostrarCondVenta || $mostrarObservaciones;
$bottomGridClass = $mostrarBloquePago ? 'factura-bottom-grid' : 'factura-bottom-grid factura-bottom-grid--single';
$letra = factura_tipo_letra($tipo);
$importeEnLetras = factura_importe_en_letras((float)$resumenFiscal['total']);
$qrData = $esDemo ? null : factura_qr_data($factura, $fechaEmisionQr, $empresaCuitRaw, (string)($factura['cliente_cuit'] ?? ''), $cae, $tipoCbte, $pdo);
$itemsCount = count($items);
$pageClasses = ['page-wrap', 'factura-page'];
if ($itemsCount <= 8) {
    $pageClasses[] = 'factura-page--airy';
} elseif ($itemsCount > 30) {
    $pageClasses[] = 'factura-page--dense';
} else {
    $pageClasses[] = 'factura-page--filled';
}

$esNc = strtoupper((string)($factura['naturaleza'] ?? '')) === 'NC'
    || str_starts_with(strtoupper($tipo), 'NC');
$comprobanteTitulo = $esNc ? 'Nota de credito' : 'Factura';
$comprobanteNumero = sprintf('%04d-%08d', (int)$factura['punto_venta'], (int)$factura['numero']);
$topbarLabel = $esNc ? 'Nota de credito emitida' : 'Comprobante fiscal';
$backHref = $esNc ? 'facturacion_nc.php' : 'facturacion.php';
$backLabel = $esNc ? 'Volver a notas de credito' : 'Volver a facturacion';
$cobroSaldo = round((float)($cobranzaResumen['saldo'] ?? 0), 2);
$cobroTotal = round((float)($cobranzaResumen['total'] ?? $resumenFiscal['total'] ?? 0), 2);
$cobroTotalOriginal = round((float)($cobranzaResumen['total_original'] ?? $resumenFiscal['total'] ?? $cobroTotal), 2);
$cobroTotalNc = round((float)($cobranzaResumen['total_nc'] ?? 0), 2);
$cobroNcCount = (int)($cobranzaResumen['nc_count'] ?? 0);
$cobroCobrado = round((float)($cobranzaResumen['cobrado'] ?? 0), 2);
$cobroEstado = (string)($cobranzaResumen['estado'] ?? 'SIN_COBRAR');
$cobroLabel = (string)($cobranzaResumen['label'] ?? 'Sin cobrar');
$cajaAbiertaId = !$pdfMode ? factura_caja_abierta_actual($pdo) : 0;
$puedeRegistrarCobro = !$pdfMode
    && ($cobranzaResumen['receipts_ready'] ?? false)
    && ($cobranzaResumen['cobrable'] ?? false)
    && $cobroSaldo > 0.009
    && function_exists('user_has_permission')
    && user_has_permission('registrar_pago_cc');
$pageTitle = $comprobanteTitulo . ' ' . h($tipo) . ' ' . sprintf('%04d-%08d', (int)$factura['punto_venta'], (int)$factura['numero']);
$printHref = 'factura_ver.php?id=' . (int)($factura['id'] ?? 0)
    . '&pdf=1&autoprint=1&pdf_token='
    . rawurlencode(flus_factura_pdf_token_create((int)($factura['id'] ?? 0), time() + 300));
$currentSection = 'facturacion';
$breadcrumbs = $esNc
    ? [
        ['label' => 'Facturación', 'url' => 'facturacion.php'],
        ['label' => 'Notas de crédito', 'url' => 'facturacion_nc.php'],
        ['label' => $comprobanteTitulo . ' ' . $tipo . ' ' . sprintf('%04d-%08d', (int)$factura['punto_venta'], (int)$factura['numero']), 'url' => null],
    ]
    : [
        ['label' => 'Facturación', 'url' => 'facturacion.php'],
        ['label' => $comprobanteTitulo . ' ' . $tipo . ' ' . sprintf('%04d-%08d', (int)$factura['punto_venta'], (int)$factura['numero']), 'url' => null],
    ];
$extraCss = ['assets/css/factura.css?v=9'];
$bodyClass = 'factura-view';
$inlineJs = !$pdfMode ? <<<'JS'
(function () {
  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); return; }
    document.addEventListener('DOMContentLoaded', fn);
  }
  function csrf() {
    if (window.getCsrfToken) return window.getCsrfToken() || '';
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? (meta.getAttribute('content') || '') : '';
  }
  function uid() {
    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
    return 'fac-cobro-' + Date.now() + '-' + Math.random().toString(16).slice(2);
  }
  function notify(message, isError) {
    if (window.Notif && typeof window.Notif[isError ? 'error' : 'success'] === 'function') {
      window.Notif[isError ? 'error' : 'success'](message);
      return;
    }
    if (window.showToast) {
      window.showToast(message, isError ? 'error' : 'success');
      return;
    }
  }
  ready(function () {
    var modal = document.getElementById('facturaCobroModal');
    var openButtons = document.querySelectorAll('[data-open-cobro]');
    var closeButtons = modal ? modal.querySelectorAll('[data-close-cobro]') : [];
    var form = document.getElementById('facturaCobroForm');
    if (!modal || openButtons.length === 0 || !form) return;

    function openModal() {
      modal.hidden = false;
      var amount = modal.querySelector('input[name="monto"]');
      if (amount) amount.focus();
    }
    function closeModal() {
      modal.hidden = true;
    }

    openButtons.forEach(function (btn) { btn.addEventListener('click', openModal); });
    closeButtons.forEach(function (btn) { btn.addEventListener('click', closeModal); });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && !modal.hidden) closeModal();
    });

    form.addEventListener('submit', async function (ev) {
      ev.preventDefault();
      var submit = form.querySelector('[type="submit"]');
      var fd = new FormData(form);
      fd.set('action', 'registrar_cobro_factura');
      fd.set('csrf_token', csrf());
      if (!fd.get('request_uid')) fd.set('request_uid', uid());
      if (submit) submit.disabled = true;

      try {
        var response = await fetch('api/factura_cobranza_api.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf() },
          body: fd
        });
        var data = await response.json().catch(function () { return null; });
        if (!response.ok || !data || data.success !== true) {
          throw new Error((data && (data.error || data.message)) || 'No se pudo registrar el cobro.');
        }
        notify('Cobro registrado. Recargando factura...', false);
        window.setTimeout(function () { window.location.reload(); }, 550);
      } catch (err) {
        notify(err && err.message ? err.message : 'No se pudo registrar el cobro.', true);
        if (submit) submit.disabled = false;
      }
    });
  });
}());
JS : '';

if ($pdfMode) {
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h($pageTitle) ?></title>
  <link rel="stylesheet" href="assets/css/factura.css?v=9">
</head>
<body class="<?= h($bodyClass) ?>">
<?php
} else {
    require __DIR__ . '/partials/header.php';
}
?>
<div class="<?= h(implode(' ', $pageClasses)) ?>">
  <div class="factura-shell">
    <div class="factura-topbar no-print">
      <div class="factura-topbar-main">
        <a href="<?= h($backHref) ?>" class="link-back-print"><?= h($backLabel) ?></a>
        <div class="factura-topbar-context">
          <span><?= h($topbarLabel) ?></span>
          <strong><?= h($tipo) ?> <?= h($comprobanteNumero) ?></strong>
          <small><?= h($estadoFiscalLabel) ?>, <?= h($modoFacturaLabel) ?></small>
        </div>
      </div>
      <div class="factura-topbar-actions">
        <?php if ($puedeRegistrarCobro): ?>
          <button type="button" class="btn btn-secondary" data-open-cobro>Registrar cobro</button>
        <?php endif; ?>
        <a href="factura_pdf.php?id=<?= (int)$id ?>" class="btn btn-secondary">PDF</a>
        <a href="<?= h($printHref) ?>" class="btn btn-primary btn-print" target="_blank" rel="noopener">Imprimir</a>
      </div>
    </div>

    <?php if (!$pdfTokenValid && !empty($_GET['pdf_error'])): ?>
      <div class="factura-alerta-emisor no-print">
        <div class="factura-alerta-titulo">No se pudo generar el PDF</div>
        <div class="factura-alerta-texto"><?= h((string)$_GET['pdf_error']) ?></div>
      </div>
    <?php endif; ?>

    <?php if (!$empresaDatosCompletos): ?>
      <div class="factura-alerta-emisor no-print">
        <div class="factura-alerta-titulo">Faltan datos del emisor para completar el encabezado fiscal</div>
        <div class="factura-alerta-texto">
          Completa <?= h(implode(', ', $empresaPendientes)) ?> en
          <a href="facturacion_config.php">Configuracion de facturacion</a>.
        </div>
      </div>
    <?php endif; ?>

    <header class="factura-hero">
      <section class="hero-box hero-box--brand">
        <div class="brand-topline">FLUS</div>
        <div class="brand-tagline">Sistema de Gestion Comercial</div>
        <div class="brand-shell">
          <div class="brand-logo-zone">
            <?php if ($empresaLogo !== null): ?>
              <img src="<?= h($empresaLogo) ?>" alt="Logo empresa" class="brand-logo-image" referrerpolicy="no-referrer">
            <?php else: ?>
              <div class="brand-logo-fallback">FLUS</div>
            <?php endif; ?>
          </div>
          <div class="brand-data">
            <div class="brand-name"><?= h($empresaNombre) ?></div>
            <div><strong>CUIT:</strong> <?= h($empresaCUIT) ?></div>
            <div><strong>IVA:</strong> <?= h($empresaIVA) ?></div>
            <div><strong>Domicilio:</strong> <?= h($empresaDireccion) ?></div>
            <div><strong>Ingresos Brutos:</strong> <?= h($empresaIIBB) ?></div>
            <div><strong>Inicio de actividades:</strong> <?= h($empresaInicio) ?></div>
          </div>
        </div>
      </section>

      <section class="hero-box hero-box--letter">
        <div class="letter-badge"><?= h($letra) ?></div>
        <div class="letter-code">Cod. <?= sprintf('%02d', max(1, $tipoCbte)) ?></div>
      </section>

      <section class="hero-box hero-box--meta">
        <div class="meta-main-title"><?= h($comprobanteTitulo) ?></div>
        <div class="meta-main-number">Nro. <?= sprintf('%04d-%08d', (int)$factura['punto_venta'], (int)$factura['numero']) ?></div>
        <div class="meta-list">
          <div><strong>Fecha:</strong> <?= h($fechaCorta) ?></div>
          <div><strong>Punto de venta:</strong> <?= sprintf('%04d', (int)$factura['punto_venta']) ?></div>
          <div><strong>Comprobante:</strong> <?= sprintf('%08d', (int)$factura['numero']) ?></div>
          <div><strong>Moneda:</strong> Pesos Argentinos</div>
          <div><strong>Modo:</strong> <?= h($modoFacturaLabel) ?></div>
          <div><strong>Original</strong></div>
        </div>
      </section>
    </header>

    <section class="factura-box factura-box--client">
      <div class="box-title">Datos del cliente</div>
      <div class="client-grid">
        <div><strong>Cliente:</strong> <?= h($clienteNombre) ?></div>
        <div><strong>CUIT / DNI:</strong> <?= h($clienteCuit) ?></div>
        <div><strong>Cond. IVA:</strong> <?= h($clienteCondIva) ?></div>
        <?php if ($mostrarCondVenta): ?>
          <div><strong>Forma de pago:</strong> <?= h($condVenta) ?></div>
        <?php endif; ?>
        <div class="client-grid__full"><strong>Domicilio:</strong> <?= h($clienteDireccion) ?></div>
      </div>
    </section>

    <section class="factura-box factura-box--detail">
      <div class="box-title">Detalle de productos / servicios</div>
      <table class="tabla-detalle">
        <thead>
          <tr>
            <th class="detail-col--code">Codigo</th>
            <th class="num detail-col--qty">Cant.</th>
            <th>Descripcion</th>
            <th class="num detail-col--tax">IVA</th>
            <th class="num detail-col--price">P. unitario</th>
            <th class="num detail-col--amount">Importe</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($items !== []): ?>
            <?php foreach ($items as $item): ?>
              <tr>
                <td><?= h($item['codigo'] !== '' ? $item['codigo'] : '-') ?></td>
                <td class="num"><?= h(format_qty($item['cantidad'])) ?></td>
                <td><?= h($item['descripcion']) ?></td>
                <td class="num"><?= h(factura_rate_label((float)$item['iva_pct'])) ?></td>
                <td class="num"><?= money($item['precio_unitario']) ?></td>
                <td class="num"><?= money($item['subtotal']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="6" class="empty-cell">No hay items asociados a esta venta.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
      <div class="detail-spacer" aria-hidden="true"></div>
    </section>

    <section class="factura-financial-grid">
      <section class="factura-box factura-box--words">
        <div class="box-title">Importe en letras</div>
        <div class="amount-words"><?= h($importeEnLetras) ?></div>
      </section>

      <section class="factura-box factura-box--totals">
        <table class="tabla-totales">
          <tbody>
            <tr>
              <th>Neto gravado</th>
              <td><?= money($resumenFiscal['neto_gravado']) ?></td>
            </tr>
            <?php if ($resumenFiscal['exento'] > 0): ?>
              <tr>
                <th>Exento / 0%</th>
                <td><?= money($resumenFiscal['exento']) ?></td>
              </tr>
            <?php endif; ?>
            <tr>
              <th>Subtotal</th>
              <td><?= money($resumenFiscal['subtotal']) ?></td>
            </tr>
            <?php foreach ($resumenFiscal['rates'] as $rate): ?>
              <tr>
                <th>IVA <?= h(factura_rate_label((float)$rate['rate'])) ?></th>
                <td><?= money($rate['iva']) ?></td>
              </tr>
            <?php endforeach; ?>
            <tr>
              <th>IVA total</th>
              <td><?= money($resumenFiscal['iva_total']) ?></td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <th>Total</th>
              <td><?= money($resumenFiscal['total']) ?></td>
            </tr>
          </tfoot>
        </table>
      </section>
    </section>

    <section class="<?= h($bottomGridClass) ?>">
      <?php if ($mostrarBloquePago): ?>
        <section class="factura-box factura-box--payment">
          <div class="box-title">Datos complementarios</div>
          <div class="payment-stack">
            <?php if ($mostrarCondVenta): ?>
              <div><strong>Medio de pago:</strong> <?= h($condVenta) ?></div>
            <?php endif; ?>
            <div><strong>Fecha de emision:</strong> <?= h($fechaFmt) ?></div>
            <?php if ($mostrarObservaciones): ?>
              <div><strong>Observaciones:</strong> <?= h($observacionesTexto) ?></div>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if (!$pdfMode): ?>
        <?php if (is_array($documentoComercial)): ?>
          <section class="factura-box factura-box--payment no-print">
            <div class="box-title">Documento comercial asociado</div>
            <div class="payment-stack">
              <div><strong>Documento:</strong> <a href="documento_comercial.php?id=<?= (int)$documentoComercial['id'] ?>"><?= h((string)($documentoComercial['tipo_documento'] ?? 'Documento')) ?> #<?= (int)$documentoComercial['id'] ?></a></div>
              <div><strong>Estado documental:</strong> <?= h((string)($documentoComercial['estado'] ?? 'PENDIENTE')) ?></div>
              <?php if ((int)($documentoComercial['venta_id'] ?? 0) > 0): ?>
                <div><strong>Venta vinculada:</strong> <a href="venta_detalle.php?id=<?= (int)$documentoComercial['venta_id'] ?>">Venta #<?= (int)$documentoComercial['venta_id'] ?></a></div>
              <?php endif; ?>
              <?php if (is_array($documentoComercialOrigen)): ?>
                <div><strong>Origen:</strong> <a href="documento_comercial.php?id=<?= (int)$documentoComercialOrigen['id'] ?>"><?= h((string)($documentoComercialOrigen['tipo_documento'] ?? 'Documento')) ?> #<?= (int)$documentoComercialOrigen['id'] ?></a></div>
              <?php endif; ?>
            </div>
          </section>
        <?php endif; ?>
        <section class="factura-box factura-box--payment no-print">
          <div class="box-title">Estado de cobro</div>
          <div class="payment-stack factura-cobro-status">
            <div class="factura-cobro-status__row">
              <span class="factura-cobro-status__label">Estado actual</span>
              <span class="badge <?= h($cobroEstado === 'COBRADA' ? 'badge-info' : ($cobroEstado === 'PARCIAL' ? 'badge-warning' : 'badge-secondary')) ?>"><?= h($cobroLabel) ?></span>
            </div>
            <div class="factura-cobro-status__grid">
              <div><span>Total</span><strong><?= money($cobroTotalOriginal) ?></strong></div>
              <div><span>NC</span><strong><?= money($cobroTotalNc) ?></strong></div>
              <div><span>Neto</span><strong><?= money($cobroTotal) ?></strong></div>
              <div><span>Cobrado</span><strong><?= money($cobroCobrado) ?></strong></div>
              <div><span>Saldo</span><strong><?= money($cobroSaldo) ?></strong></div>
            </div>
            <?php if ($cobroNcCount > 0): ?>
              <div class="text-muted factura-cobro-note"><?= number_format($cobroNcCount) ?> nota<?= $cobroNcCount === 1 ? '' : 's' ?> de credito aplicada<?= $cobroNcCount === 1 ? '' : 's' ?> al saldo.</div>
            <?php endif; ?>
            <?php if ($puedeRegistrarCobro): ?>
              <button type="button" class="btn-mini" data-open-cobro>Registrar cobro</button>
            <?php elseif ($cobroEstado === 'SIN_TABLAS'): ?>
              <span class="text-muted factura-cobro-note">Falta aplicar el esquema de cobranzas y recibos.</span>
            <?php elseif (!($cobranzaResumen['cobrable'] ?? false)): ?>
              <span class="text-muted factura-cobro-note">Este comprobante no requiere registro de cobro desde factura.</span>
            <?php endif; ?>
          </div>
        </section>
        <?php if ($recibosAsociados !== []): ?>
          <section class="factura-box factura-box--payment no-print">
            <div class="box-title">Cobros / recibos asociados</div>
            <div class="payment-stack factura-receipt-list">
              <?php
                $_tipoLabels = [
                    'SALDO_CC'  => ['Saldo CC',  'badge-info'],
                    'FACTURA'   => ['Factura',   'badge-warning'],
                    'DOCUMENTO' => ['Documento', 'badge-secondary'],
                    'VENTA'     => ['Venta',     'badge-secondary'],
                ];
              ?>
              <?php foreach ($recibosAsociados as $_recibo): ?>
                <?php
                  $_reciboDocId    = (int)($_recibo['recibo_documento_id'] ?? 0);
                  $_reciboFactId   = (int)($_recibo['factura_id'] ?? 0);
                  $_cobranzaId     = (int)($_recibo['cobranza_id'] ?? 0);
                  $_aplicacionId   = (int)($_recibo['recibo_aplicacion_id'] ?? 0);
                  $_ccMovimientoId = (int)($_recibo['cc_movimiento_id'] ?? 0);
                  $_cajaMovimientoId = (int)($_recibo['caja_movimiento_id'] ?? 0);
                  $_tipoApl        = trim((string)($_recibo['tipo_aplicacion'] ?? 'SALDO_CC'));
                  $_tipoMeta       = $_tipoLabels[$_tipoApl] ?? [$_tipoApl, 'badge-secondary'];
                  $_montoApl       = (float)($_recibo['monto_aplicado'] ?? 0);
                  $_medioPago      = strtoupper(trim((string)($_recibo['medio_pago'] ?? '')));
                  $_referencia     = trim((string)($_recibo['referencia'] ?? ''));
                  $_nota           = trim((string)($_recibo['nota'] ?? ''));
                  $_createdAt      = trim((string)($_recibo['created_at'] ?? ''));
                  $_fechaFmt       = '';
                  if ($_createdAt !== '') {
                      $_ts = strtotime($_createdAt);
                      $_fechaFmt = $_ts !== false ? date('d/m/Y H:i', $_ts) : $_createdAt;
                  }
                ?>
                <article class="factura-receipt">
                <div class="factura-receipt__summary">
                  <strong>
                    <?php if ($_reciboFactId > 0): ?>
                      <a href="factura_ver.php?id=<?= $_reciboFactId ?>" title="Ver factura asociada al recibo">Factura asociada #<?= $_reciboFactId ?></a>
                    <?php elseif ($_reciboDocId > 0): ?>
                      Recibo doc #<?= $_reciboDocId ?>
                    <?php else: ?>
                      Recibo (pendiente de documento)
                    <?php endif; ?>
                  </strong>
                  <span class="badge <?= h($_tipoMeta[1]) ?>"><?= h($_tipoMeta[0]) ?></span>
                  <span class="factura-receipt__amount"><?= money($_montoApl) ?></span>
                  <?php if ($_medioPago !== ''): ?>
                    <span class="text-muted factura-receipt__method"><?= h($_medioPago) ?></span>
                  <?php endif; ?>
                  <?php if ($_fechaFmt !== ''): ?>
                    <span class="text-muted factura-receipt__date"><?= h($_fechaFmt) ?></span>
                  <?php endif; ?>
                </div>
                <div class="factura-receipt__trace">
                  <?php if ($_cobranzaId > 0): ?>
                    <span class="text-muted">Cobranza #<?= $_cobranzaId ?></span>
                  <?php endif; ?>
                  <?php if ($_aplicacionId > 0): ?>
                    <span class="text-muted">Aplicación #<?= $_aplicacionId ?></span>
                  <?php endif; ?>
                  <?php if ($_ccMovimientoId > 0): ?>
                    <span class="text-muted">CC mov. #<?= $_ccMovimientoId ?></span>
                  <?php endif; ?>
                  <?php if ($_cajaMovimientoId > 0): ?>
                    <span class="text-muted">Caja mov. #<?= $_cajaMovimientoId ?></span>
                  <?php endif; ?>
                </div>
                <?php if ($_referencia !== ''): ?>
                  <div class="factura-receipt__detail"><span class="text-muted">Ref:</span> <?= h($_referencia) ?></div>
                <?php endif; ?>
                <?php if ($_nota !== '' && $_nota !== 'Recibo de cobranza'): ?>
                  <div class="factura-receipt__detail"><span class="text-muted">Nota:</span> <?= h($_nota) ?></div>
                <?php endif; ?>
                <?php if (!empty($factura['cliente_id'])): ?>
                  <div class="factura-receipt__account">
                    <a href="cuenta_corriente_cliente.php?id=<?= (int)$factura['cliente_id'] ?>" class="text-muted">Ver cuenta corriente del cliente</a>
                  </div>
                <?php endif; ?>
                </article>
              <?php endforeach; ?>
            </div>
          </section>
        <?php elseif (flus_cobranzas_receipts_ready($pdo)): ?>
          <section class="factura-box factura-box--payment factura-box--empty no-print">
            <div class="box-title">Cobros / recibos asociados</div>
            <div class="payment-stack">
              <span class="text-muted factura-empty-copy">No hay recibos registrados para esta factura.</span>
              <?php if (!empty($factura['cliente_id'])): ?>
                <div class="factura-empty-action">
                  <a href="cuenta_corriente_cliente.php?id=<?= (int)$factura['cliente_id'] ?>">Ver cuenta corriente del cliente</a>
                </div>
              <?php endif; ?>
            </div>
          </section>
        <?php endif; ?>
      <?php endif; ?>

      <?php if (!$pdfMode && flus_facturacion_estado_fiscal_requiere_intervencion($estadoFiscal) && $fiscalCerradaAt === ''): ?>
        <section class="factura-box factura-box--payment factura-box--warning no-print">
          <div class="box-title">Incidencia fiscal</div>
          <div class="payment-stack">
            <div><strong><?= h($estadoFiscalLabel) ?>:</strong> <?= h($fiscalDetalleOperativo) ?></div>
            <?php if ($fiscalErrorMessage !== ''): ?>
              <div><strong>Ultimo error:</strong> <?= h($fiscalErrorMessage) ?></div>
            <?php endif; ?>
            <div><a href="facturacion_recovery.php?factura_id=<?= (int)$factura['id'] ?>" class="btn-mini btn-mini--danger">Regularizar factura</a></div>
          </div>
        </section>
      <?php endif; ?>

      <section class="factura-box factura-box--fiscal">
        <div class="box-title">Datos fiscales</div>
        <div class="fiscal-stack">
          <?php if (!$pdfMode): ?>
            <div class="fiscal-row--internal"><strong>Estado fiscal:</strong> <?= h($estadoFiscalLabel) ?></div>
          <?php endif; ?>
          <div><strong>CAE:</strong> <?= h($cae) ?></div>
          <div><strong>Vto. CAE:</strong> <?= h($caeVto) ?></div>
          <?php if (!$pdfMode && $fiscalRequestUid !== ''): ?>
            <div class="fiscal-row--internal"><strong>Request UID:</strong> <span class="mono"><?= h($fiscalRequestUid) ?></span></div>
          <?php endif; ?>
          <?php if (!$pdfMode && $fiscalIntentos > 0): ?>
            <div class="fiscal-row--internal"><strong>Intentos:</strong> <?= (int)$fiscalIntentos ?></div>
          <?php endif; ?>
          <?php if (!$pdfMode && $fiscalRequestedAt !== ''): ?>
            <div class="fiscal-row--internal"><strong>Solicitado:</strong> <?= h($fiscalRequestedAt) ?></div>
          <?php endif; ?>
          <?php if (!$pdfMode && $fiscalApprovedAt !== ''): ?>
            <div class="fiscal-row--internal"><strong>Aprobado:</strong> <?= h($fiscalApprovedAt) ?></div>
          <?php endif; ?>
          <?php if (!$pdfMode && $fiscalCerradaAt !== ''): ?>
            <div class="fiscal-row--internal"><strong>Incidencia cerrada:</strong> <?= h($fiscalCerradaAt) ?><?= $fiscalCierreMotivo !== '' ? ' · ' . h($fiscalCierreMotivo) : '' ?></div>
          <?php endif; ?>
          <?php if (!$pdfMode && ($fiscalErrorCode !== '' || $fiscalErrorMessage !== '')): ?>
            <div class="fiscal-row--internal"><strong>Error fiscal:</strong> <?= h(trim($fiscalErrorCode . ' ' . $fiscalErrorMessage)) ?></div>
          <?php endif; ?>
          <?php if (!$pdfMode && $arcaResultado !== ''): ?>
            <div class="fiscal-row--internal"><strong>Ultima interaccion ARCA:</strong> <?= h(flus_facturacion_evento_arca_resultado_label($arcaResultado)) ?><?= $arcaOperacion !== '' ? ' · ' . h(flus_facturacion_evento_arca_operacion_label($arcaOperacion)) : '' ?><?= $arcaModo !== '' ? ' · ' . h(flus_facturacion_modo_label($arcaModo)) : '' ?><?= $arcaAt !== '' ? ' · ' . h($arcaAt) : '' ?></div>
          <?php endif; ?>
          <?php if (!$pdfMode && $arcaError !== '' && $arcaError !== $fiscalErrorMessage): ?>
            <div class="fiscal-row--internal"><strong>Traza ARCA:</strong> <?= h($arcaError) ?></div>
          <?php endif; ?>
          <?php if (!$pdfMode && $fiscalDetalleOperativo !== ''): ?>
            <div class="fiscal-row--internal"><strong>Operativamente:</strong> <?= h($fiscalDetalleOperativo) ?></div>
          <?php endif; ?>
          <?php if (!$pdfMode && ($envioUltimoCanal !== '' || $envioUltimoEstado !== '' || $envioUltimoDestino !== '' || $envioUltimoAt !== '' || $envioUltimoError !== '')): ?>
            <div class="fiscal-row--internal"><strong>Ultimo envio comercial:</strong> <?= h($envioUltimoCanal !== '' ? $envioUltimoCanal : 'Canal no informado') ?><?= $envioUltimoEstado !== '' ? ' · ' . h($envioUltimoEstado) : '' ?><?= $envioUltimoDestino !== '' ? ' · ' . h($envioUltimoDestino) : '' ?><?= $envioUltimoAt !== '' ? ' · ' . h($envioUltimoAt) : '' ?></div>
          <?php endif; ?>
          <?php if (!$pdfMode && $envioUltimoError !== ''): ?>
            <div class="fiscal-row--internal"><strong>Error de envio comercial:</strong> <?= h($envioUltimoError) ?></div>
          <?php endif; ?>
          <div class="fiscal-note<?= $pdfMode ? '' : ' fiscal-note--internal' ?>"><?= h($footerModo) ?></div>
          <?php if ($qrData !== null): ?>
            <div class="qr-area">
              <img src="<?= h($qrData['image_url']) ?>" alt="QR AFIP" class="factura-qr-image" referrerpolicy="no-referrer">
              <a href="<?= h($qrData['verify_url']) ?>" target="_blank" rel="noopener noreferrer" class="qr-link">Verificar comprobante</a>
            </div>
          <?php else: ?>
            <div class="qr-area qr-area--empty">
              <div class="qr-placeholder">QR AFIP</div>
              <div class="fiscal-note">No disponible en demo o sin CAE real.</div>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </section>

    <footer class="factura-footer">
      <div class="factura-footer-line factura-footer-line--muted">Este comprobante fue generado por FLUS</div>
      <div class="factura-footer-line factura-footer-line--muted">Sistema de Gestion Comercial</div>
    </footer>
  </div>
</div>

<?php if ($puedeRegistrarCobro): ?>
  <div id="facturaCobroModal" class="factura-cobro-modal no-print" hidden>
    <div class="factura-cobro-modal__backdrop" data-close-cobro></div>
    <div class="factura-cobro-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="facturaCobroTitle">
      <div class="factura-cobro-modal__head">
        <div>
          <div class="factura-cobro-modal__eyebrow">Cobranza</div>
          <h3 id="facturaCobroTitle">Registrar cobro de factura</h3>
        </div>
        <button type="button" class="factura-cobro-modal__close" data-close-cobro aria-label="Cerrar">x</button>
      </div>
      <form id="facturaCobroForm" class="factura-cobro-form">
        <input type="hidden" name="factura_id" value="<?= (int)$factura['id'] ?>">
        <input type="hidden" name="request_uid" value="">
        <div class="factura-cobro-form__summary">
          <span>Saldo pendiente</span>
          <strong><?= money($cobroSaldo) ?></strong>
        </div>
        <label>
          <span>Monto</span>
          <input type="number" name="monto" min="0.01" max="<?= h(number_format($cobroSaldo, 2, '.', '')) ?>" step="0.01" value="<?= h(number_format($cobroSaldo, 2, '.', '')) ?>" required>
        </label>
        <label>
          <span>Medio de pago</span>
          <select name="medio_pago" required>
            <option value="EFECTIVO">Efectivo</option>
            <option value="MP">Mercado Pago</option>
            <option value="DEBITO">Debito</option>
            <option value="CREDITO">Credito</option>
            <option value="TRANSFERENCIA">Transferencia</option>
            <option value="MODO">Modo</option>
            <option value="QR">QR</option>
          </select>
        </label>
        <label>
          <span>Referencia</span>
          <input type="text" name="referencia" maxlength="120" placeholder="Operacion, alias o nota corta">
        </label>
        <label>
          <span>Observaciones</span>
          <textarea name="observaciones" rows="3" maxlength="255" placeholder="Detalle interno del cobro"></textarea>
        </label>
        <label class="factura-cobro-form__check">
          <input type="checkbox" name="registrar_caja" value="1" <?= $cajaAbiertaId > 0 ? 'checked' : 'disabled' ?>>
          <span><?= $cajaAbiertaId > 0 ? 'Registrar movimiento en caja abierta #' . (int)$cajaAbiertaId : 'Sin caja abierta en esta terminal' ?></span>
        </label>
        <div class="factura-cobro-form__hint">El recibo es interno de FLUS; no se envia a ARCA.</div>
        <div class="factura-cobro-form__actions">
          <button type="button" class="btn btn-secondary" data-close-cobro>Cancelar</button>
          <button type="submit" class="btn btn-primary">Registrar cobro</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php if ($pdfMode): ?>
<?php if ($autoPrint): ?>
<script>
  (function () {
    var fired = false;
    var triggerPrint = function () {
      if (fired) {
        return;
      }
      fired = true;
      window.setTimeout(function () {
        window.print();
      }, 120);
    };

    var images = Array.prototype.slice.call(document.images || []);
    if (images.length === 0) {
      window.addEventListener('load', triggerPrint, { once: true });
      window.setTimeout(triggerPrint, 900);
      return;
    }

    var pending = 0;
    images.forEach(function (img) {
      if (img.complete && img.naturalWidth > 0) {
        return;
      }
      pending += 1;
      var settle = function () {
        pending -= 1;
        if (pending <= 0) {
          triggerPrint();
        }
      };
      img.addEventListener('load', settle, { once: true });
      img.addEventListener('error', settle, { once: true });
    });

    window.addEventListener('load', function () {
      if (pending <= 0) {
        triggerPrint();
      }
    }, { once: true });
    window.setTimeout(triggerPrint, 1500);
  }());
</script>
<?php endif; ?>
</body>
</html>
<?php else: ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
<?php endif; ?>

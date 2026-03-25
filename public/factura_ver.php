<?php
// public/factura_ver.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/db_schema.php';
require_once __DIR__ . '/../src/facturacion_lib.php';
require_once __DIR__ . '/../src/facturacion_manual_lib.php';
require_once __DIR__ . '/../src/cobranzas_lib.php';

$requestedFacturaId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$pdfToken = trim((string)($_GET['pdf_token'] ?? ''));
$pdfMode = isset($_GET['pdf']) && $_GET['pdf'] === '1';
$pdfTokenValid = $pdfMode && flus_factura_pdf_token_validate($pdfToken, $requestedFacturaId);

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

$sql = '
  SELECT
    f.*, 
    v.fecha AS venta_fecha,
    v.total AS venta_total,
    v.medio_pago AS venta_medio_pago,
    v.nota AS venta_nota,
    c.nombre AS cliente_nombre,
    c.cuit AS cliente_cuit,
    c.cond_iva AS cliente_cond_iva,
    c.direccion AS cliente_direccion
  FROM facturas f
  LEFT JOIN ventas v ON v.id = f.venta_id
  LEFT JOIN clientes c ON c.id = f.cliente_id
  WHERE f.id = ?
  LIMIT 1
';
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$factura = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$factura) {
    http_response_code(404);
    flus_abort(404, 'Factura no encontrada');
}

$estadoFiscal = flus_facturacion_estado_fiscal_normalizar((string)($factura['estado_fiscal'] ?? 'NO_APLICA'));
$estadoFiscalLabel = flus_facturacion_estado_fiscal_label($estadoFiscal);
$fiscalRequestUid = trim((string)($factura['fiscal_request_uid'] ?? ''));
$fiscalIntentos = max(0, (int)($factura['fiscal_intentos'] ?? 0));
$fiscalErrorCode = trim((string)($factura['fiscal_error_code'] ?? ''));
$fiscalErrorMessage = trim((string)($factura['fiscal_error_message'] ?? ''));
$fiscalRequestedAt = trim((string)($factura['fiscal_requested_at'] ?? ''));
$fiscalApprovedAt = trim((string)($factura['fiscal_approved_at'] ?? ''));
$envioUltimoCanal = trim((string)($factura['envio_ultimo_canal'] ?? ''));
$envioUltimoEstado = trim((string)($factura['envio_ultimo_estado'] ?? ''));
$envioUltimoDestino = trim((string)($factura['envio_ultimo_destino'] ?? ''));
$envioUltimoAt = trim((string)($factura['envio_ultimo_at'] ?? ''));
$envioUltimoError = trim((string)($factura['envio_ultimo_error'] ?? ''));
$fiscalDetalleOperativo = flus_facturacion_estado_fiscal_detalle_operativo($estadoFiscal);
$fiscalRegularizable = flus_facturacion_estado_fiscal_regularizable($estadoFiscal);
$fiscalEventoArca = null;
if ($fiscalRequestUid !== '' && flus_table_exists($pdo, 'factura_eventos_arca')) {
    try {
        $stEventoArca = $pdo->prepare('SELECT * FROM factura_eventos_arca WHERE request_uid = ? LIMIT 1');
        $stEventoArca->execute([$fiscalRequestUid]);
        $fiscalEventoArca = $stEventoArca->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $fiscalEventoArca = null;
    }
}
$arcaResultado = trim((string)($fiscalEventoArca['resultado'] ?? ''));
$arcaOperacion = trim((string)($fiscalEventoArca['operacion'] ?? ''));
$arcaModo = trim((string)($fiscalEventoArca['modo'] ?? ''));
$arcaAt = trim((string)($fiscalEventoArca['finished_at'] ?? $fiscalEventoArca['created_at'] ?? ''));
$arcaError = trim((string)($fiscalEventoArca['error_message'] ?? ''));

$fiscalData = null;
if (flus_table_exists($pdo, 'venta_fiscal')) {
    try {
        $stFiscal = $pdo->prepare('SELECT * FROM venta_fiscal WHERE venta_id = ? LIMIT 1');
        $stFiscal->execute([(int)$factura['venta_id']]);
        $fiscalData = $stFiscal->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $fiscalData = null;
    }
}

$itemRows = flus_facturacion_factura_detalle_items_fetch($pdo, $factura);

$items = factura_normalizar_items($itemRows, $factura);
$resumenFiscal = factura_resumen_fiscal($items, $factura);
$recibosAsociados = flus_cobranzas_fetch_receipts_by_factura($pdo, $id, (int)($factura['documento_id'] ?? 0));
$documentoComercial = null;
$documentoComercialOrigen = null;
if ((int)($factura['documento_id'] ?? 0) > 0) {
    $documentoComercial = flus_facturacion_documento_buscar($pdo, (int)$factura['documento_id']);
    if (is_array($documentoComercial) && (int)($documentoComercial['documento_origen_id'] ?? 0) > 0) {
        $documentoComercialOrigen = flus_facturacion_documento_buscar($pdo, (int)$documentoComercial['documento_origen_id']);
    }
}

$configEmpresa = null;
try {
    if (flus_table_exists($pdo, 'config_facturacion')) {
        $orderCfg = flus_column_exists($pdo, 'config_facturacion', 'id') ? ' ORDER BY id DESC' : '';

        if (flus_column_exists($pdo, 'config_facturacion', 'activo')) {
            $stCfg = $pdo->query('SELECT * FROM config_facturacion WHERE activo = 1' . $orderCfg . ' LIMIT 1');
            $configEmpresa = $stCfg ? ($stCfg->fetch(PDO::FETCH_ASSOC) ?: null) : null;
        }

        if ($configEmpresa === null) {
            $stCfg = $pdo->query('SELECT * FROM config_facturacion' . $orderCfg . ' LIMIT 1');
            $configEmpresa = $stCfg ? ($stCfg->fetch(PDO::FETCH_ASSOC) ?: null) : null;
        }
    }
} catch (Throwable $e) {
    $configEmpresa = null;
}

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
$pageTitle = $comprobanteTitulo . ' ' . h($tipo) . ' ' . sprintf('%04d-%08d', (int)$factura['punto_venta'], (int)$factura['numero']);
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
$extraCss = ['assets/css/factura.css?v=7'];
$bodyClass = 'factura-view';

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
  <link rel="stylesheet" href="assets/css/factura.css?v=7">
</head>
<body class="<?= h($bodyClass) ?>">
<?php
} else {
    require __DIR__ . '/partials/header.php';
}
?>
<style>
  @media print {
    .fiscal-row--internal,
    .fiscal-note--internal {
      display: none !important;
    }
  }
</style>

<div class="<?= h(implode(' ', $pageClasses)) ?>">
  <div class="factura-shell">
    <div class="factura-topbar no-print">
      <a href="facturacion.php" class="link-back-print">Volver a facturacion</a>
      <div class="factura-topbar-actions">
        <a href="factura_pdf.php?id=<?= (int)$id ?>" class="btn btn-secondary">PDF</a>
        <button class="btn btn-primary btn-print" onclick="window.print()">Imprimir</button>
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
            <th style="width: 15%;">Codigo</th>
            <th style="width: 9%;" class="num">Cant.</th>
            <th>Descripcion</th>
            <th style="width: 10%;" class="num">IVA</th>
            <th style="width: 15%;" class="num">P. unitario</th>
            <th style="width: 18%;" class="num">Importe</th>
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
        <?php if ($recibosAsociados !== []): ?>
          <section class="factura-box factura-box--payment no-print">
            <div class="box-title">Cobros / recibos asociados</div>
            <div class="payment-stack">
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
                <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.25rem">
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
                  <span style="font-weight:600"><?= money($_montoApl) ?></span>
                  <?php if ($_medioPago !== ''): ?>
                    <span class="text-muted" style="font-size:.85em"><?= h($_medioPago) ?></span>
                  <?php endif; ?>
                  <?php if ($_fechaFmt !== ''): ?>
                    <span class="text-muted" style="font-size:.82em"><?= h($_fechaFmt) ?></span>
                  <?php endif; ?>
                </div>
                <div style="font-size:.82em;display:flex;gap:.75rem;flex-wrap:wrap">
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
                  <div style="font-size:.85em"><span class="text-muted">Ref:</span> <?= h($_referencia) ?></div>
                <?php endif; ?>
                <?php if ($_nota !== '' && $_nota !== 'Recibo de cobranza'): ?>
                  <div style="font-size:.85em"><span class="text-muted">Nota:</span> <?= h($_nota) ?></div>
                <?php endif; ?>
                <?php if (!empty($factura['cliente_id'])): ?>
                  <div style="font-size:.82em;margin-top:.15rem">
                    <a href="cuenta_corriente_cliente.php?id=<?= (int)$factura['cliente_id'] ?>" class="text-muted">Ver cuenta corriente del cliente</a>
                  </div>
                <?php endif; ?>
                <hr style="margin:.4rem 0;opacity:.35">
              <?php endforeach; ?>
            </div>
          </section>
        <?php elseif (flus_cobranzas_receipts_ready($pdo)): ?>
          <section class="factura-box factura-box--payment no-print" style="opacity:.7">
            <div class="box-title">Cobros / recibos asociados</div>
            <div class="payment-stack">
              <span class="text-muted" style="font-size:.88em">No hay recibos registrados para esta factura.</span>
              <?php if (!empty($factura['cliente_id'])): ?>
                <div style="font-size:.82em;margin-top:.4rem">
                  <a href="cuenta_corriente_cliente.php?id=<?= (int)$factura['cliente_id'] ?>">Ver cuenta corriente del cliente</a>
                </div>
              <?php endif; ?>
            </div>
          </section>
        <?php endif; ?>
      <?php endif; ?>

      <?php if (!$pdfMode && flus_facturacion_estado_fiscal_requiere_intervencion($estadoFiscal)): ?>
        <section class="factura-box factura-box--payment no-print" style="border-color:var(--color-warning,#f59e0b);">
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

<?php if ($pdfMode): ?>
</body>
</html>
<?php else: ?>
<?php require __DIR__ . '/partials/footer.php'; ?>
<?php endif; ?>















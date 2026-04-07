<?php
// public/facturacion_config.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/db_schema.php';
require_once __DIR__ . '/../src/facturacion_lib.php';

require_login();
require_permission('administrar_config');

$pdo = getPDO();

function factcfg_table_ready(PDO $pdo): bool
{
    return flus_table_exists($pdo, 'config_facturacion');
}

function factcfg_valid_cond_iva(?string $raw): string
{
    $cond = strtoupper(trim((string)$raw));
    return in_array($cond, ['RI', 'MT', 'EX'], true) ? $cond : 'MT';
}

function factcfg_valid_mode(?string $raw): string
{
    return flus_facturacion_normalizar_modo($raw);
}

function factcfg_non_demo_value(?string $raw): string
{
    $value = trim((string)$raw);
    if ($value === '') {
        return '';
    }

    $normalized = strtoupper($value);
    if (in_array($normalized, ['MI KIOSCO DEMO', 'KIOSCO XYZ'], true)) {
        return '';
    }
    if (str_contains($normalized, 'SIEMPRE VIVA')) {
        return '';
    }

    return $value;
}

function factcfg_store_logo_upload(array $file): string
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo subir el logo. Codigo de error: ' . $error);
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 2 * 1024 * 1024) {
        throw new RuntimeException('El logo debe ser una imagen de hasta 2 MB.');
    }

    $originalName = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
    if (!in_array($ext, $allowed, true)) {
        throw new RuntimeException('Formato de logo no permitido. Usa PNG, JPG, WEBP o GIF.');
    }

    $targetDir = __DIR__ . '/uploads/logos';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException('No se pudo crear la carpeta para guardar logos.');
    }

    $safeExt = $ext === 'jpeg' ? 'jpg' : $ext;
    $filename = 'logo-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $safeExt;
    $targetPath = $targetDir . '/' . $filename;
    if (!move_uploaded_file((string)($file['tmp_name'] ?? ''), $targetPath)) {
        throw new RuntimeException('No se pudo guardar el logo subido.');
    }

    return 'uploads/logos/' . $filename;
}

function factcfg_legacy_types(string $condIva): array
{
    if (in_array($condIva, ['MT', 'EX'], true)) {
        return [
            'tipo_comprobante' => 'FC',
            'tipo_default' => 'C',
        ];
    }

    return [
        'tipo_comprobante' => 'FA',
        'tipo_default' => 'A',
    ];
}

function factcfg_cond_iva_from_row(array $row): string
{
    $cond = factcfg_valid_cond_iva((string)($row['cond_iva'] ?? ''));
    if ($cond !== 'MT' || isset($row['cond_iva'])) {
        return $cond;
    }

    $legacy = strtoupper(trim((string)($row['tipo_comprobante'] ?? $row['tipo_default'] ?? '')));
    if (in_array($legacy, ['C', 'FC', 'NCC', 'NDC'], true)) {
        return 'MT';
    }
    if ($legacy !== '') {
        return 'RI';
    }

    return 'MT';
}

function factcfg_filter_columns(PDO $pdo, array $data): array
{
    $schema = flus_current_db($pdo);
    if ($schema === '' || !flus_table_exists($pdo, 'config_facturacion', $schema)) {
        return [];
    }

    $colsSet = flus_columns_set($pdo, $schema, 'config_facturacion');
    $filtered = [];

    foreach ($data as $col => $value) {
        $col = (string)$col;
        if (isset($colsSet[$col])) {
            $filtered[$col] = $value;
        }
    }

    return $filtered;
}

function factcfg_insert(PDO $pdo, array $data): int
{
    $filtered = factcfg_filter_columns($pdo, $data);
    if ($filtered === []) {
        throw new RuntimeException('La tabla config_facturacion no tiene columnas compatibles para guardar la configuracion.');
    }

    $cols = [];
    $placeholders = [];
    $params = [];

    foreach ($filtered as $col => $value) {
        $cols[] = "`{$col}`";
        $placeholders[] = ':' . $col;
        $params[':' . $col] = $value;
    }

    $sql = sprintf(
        'INSERT INTO config_facturacion (%s) VALUES (%s)',
        implode(', ', $cols),
        implode(', ', $placeholders)
    );

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return (int)$pdo->lastInsertId();
}

function factcfg_update_by_id(PDO $pdo, int $id, array $data): void
{
    $filtered = factcfg_filter_columns($pdo, $data);
    unset($filtered['id']);

    if ($filtered === []) {
        return;
    }

    $sets = [];
    $params = [':id' => $id];
    foreach ($filtered as $col => $value) {
        $sets[] = "`{$col}` = :{$col}";
        $params[':' . $col] = $value;
    }

    $sql = 'UPDATE config_facturacion SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $st = $pdo->prepare($sql);
    $st->execute($params);
}

function factcfg_fetch_current(PDO $pdo, bool $forUpdate = false): ?array
{
    if (!factcfg_table_ready($pdo)) {
        return null;
    }

    $order = flus_column_exists($pdo, 'config_facturacion', 'id') ? ' ORDER BY id DESC' : '';
    $lock = $forUpdate ? ' FOR UPDATE' : '';

    if (flus_column_exists($pdo, 'config_facturacion', 'activo')) {
        $st = $pdo->query('SELECT * FROM config_facturacion WHERE activo = 1' . $order . ' LIMIT 1' . $lock);
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        if ($row) {
            return $row;
        }
    }

    $st = $pdo->query('SELECT * FROM config_facturacion' . $order . ' LIMIT 1' . $lock);
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    return $row ?: null;
}

function factcfg_fetch_by_punto_venta(PDO $pdo, int $puntoVenta, bool $forUpdate = false): ?array
{
    if (!factcfg_table_ready($pdo) || !flus_column_exists($pdo, 'config_facturacion', 'punto_venta')) {
        return null;
    }

    $order = flus_column_exists($pdo, 'config_facturacion', 'id') ? ' ORDER BY id DESC' : '';
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $st = $pdo->prepare('SELECT * FROM config_facturacion WHERE punto_venta = ?' . $order . ' LIMIT 1' . $lock);
    $st->execute([$puntoVenta]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function factcfg_defaults(PDO $pdo, array $row = []): array
{
    $condIva = factcfg_cond_iva_from_row($row);
    $modo = factcfg_valid_mode((string)($row['modo'] ?? 'demo'));
    $cuit = trim((string)($row['cuit'] ?? ''));
    if ($cuit === '' && defined('FLUS_ARCA_CUIT')) {
        $cuit = (string)FLUS_ARCA_CUIT;
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'punto_venta' => max(1, (int)($row['punto_venta'] ?? 1)),
        'cond_iva' => $condIva,
        'modo' => $modo,
        'proximo_numero' => max(1, (int)($row['proximo_numero'] ?? 1)),
        'razon_social' => factcfg_non_demo_value((string)($row['razon_social'] ?? config_get($pdo, 'business_name', ''))),
        'cuit' => $cuit,
        'domicilio' => factcfg_non_demo_value((string)($row['domicilio'] ?? config_get($pdo, 'business_address', ''))),
        'logo_url' => trim((string)config_get($pdo, 'business_logo_url', '')),
        'iibb' => trim((string)config_get($pdo, 'business_iibb', '')),
        'inicio_actividades' => trim((string)config_get($pdo, 'business_inicio_actividades', '')),
    ];
}

function factcfg_humanize_arca_status_error(?string $raw): string
{
    $message = trim((string)$raw);
    if ($message === '') {
        return 'Sin novedades';
    }

    $normalized = function_exists('mb_strtolower')
        ? mb_strtolower($message, 'UTF-8')
        : strtolower($message);

    if (
        str_contains($normalized, 'parsing wsdl')
        || str_contains($normalized, "couldn't load from")
        || str_contains($normalized, 'failed to load external entity')
    ) {
        return 'WSAA no accesible desde esta red';
    }

    if (
        str_contains($normalized, 'error invocando wsaa')
        || str_contains($normalized, 'logincms')
    ) {
        return 'No se pudo abrir conexion con WSAA';
    }

    if (str_contains($normalized, 'timeout') || str_contains($normalized, 'timed out')) {
        return 'WSAA no respondio a tiempo';
    }

    if (
        str_contains($normalized, 'no se pudo conectar al wsfe')
        || str_contains($normalized, 'error wsfe')
    ) {
        return 'WSFE no accesible desde esta red';
    }

    $friendly = flus_facturacion_humanizar_error_arca($message);
    if ($friendly === flus_facturacion_arca_emision_bloqueada_message()) {
        return 'No se pudo conectar con ARCA';
    }

    return $friendly;
}

function factcfg_format_checked_at(?string $raw): string
{
    $value = trim((string)$raw);
    if ($value === '') {
        return 'Sin verificacion reciente';
    }

    $ts = strtotime($value);
    return $ts === false ? $value : date('d/m/Y H:i', $ts);
}

$configArcaPath = __DIR__ . '/../src/config_arca.php';
$configArcaExists = file_exists($configArcaPath);
if ($configArcaExists) {
    require_once $configArcaPath;
}

$mensaje = '';
$error = '';
$errorDetail = '';
$tableReady = factcfg_table_ready($pdo);
$facturacionHabilitada = flus_facturacion_habilitada($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_verify($token)) {
        $error = 'Token CSRF invalido.';
    } else {
        $accion = (string)($_POST['accion'] ?? '');

        if ($accion === 'guardar_config') {
            if (!$tableReady) {
                $error = 'La tabla config_facturacion no existe. Aplica primero la migracion de facturacion.';
            } else {
                $puntoVenta = (int)($_POST['punto_venta'] ?? 1);
                $condIva = factcfg_valid_cond_iva((string)($_POST['cond_iva'] ?? 'MT'));
                $modo = factcfg_valid_mode((string)($_POST['modo'] ?? 'demo'));
                $razonSocial = trim((string)($_POST['razon_social'] ?? ''));
                $cuit = preg_replace('/\D+/', '', (string)($_POST['cuit'] ?? ''));
                $domicilio = trim((string)($_POST['domicilio'] ?? ''));
                $iibb = trim((string)($_POST['iibb'] ?? ''));
                $inicioActividades = trim((string)($_POST['inicio_actividades'] ?? ''));
                $logoUrl = trim((string)($_POST['logo_url'] ?? ''));
                $printItemLimit = (int)($_POST['print_item_limit'] ?? flus_facturacion_print_item_limit($pdo));
                $logoSubido = factcfg_store_logo_upload($_FILES['logo_file'] ?? []);
                if ($logoSubido !== '') {
                    $logoUrl = $logoSubido;
                }

                if ($puntoVenta < 1 || $puntoVenta > 99999) {
                    $puntoVenta = 1;
                }
                $printItemLimit = max(1, min(200, $printItemLimit));

                $legacy = factcfg_legacy_types($condIva);
                $timestamp = date('Y-m-d H:i:s');
                $data = [
                    'punto_venta' => $puntoVenta,
                    'cond_iva' => $condIva,
                    'modo' => flus_facturacion_modo_db_value($modo),
                    'razon_social' => $razonSocial !== '' ? $razonSocial : null,
                    'cuit' => $cuit !== '' ? $cuit : null,
                    'domicilio' => $domicilio !== '' ? $domicilio : null,
                    'activo' => 1,
                    'tipo_comprobante' => $legacy['tipo_comprobante'],
                    'tipo_default' => $legacy['tipo_default'],
                    'descripcion' => 'Punto de venta ' . $puntoVenta,
                    'actualizado_en' => $timestamp,
                ];

                try {
                    $pdo->beginTransaction();

                    $actual = factcfg_fetch_current($pdo, true);
                    $porPuntoVenta = factcfg_fetch_by_punto_venta($pdo, $puntoVenta, true);
                    $target = $porPuntoVenta ?: $actual;

                    if (flus_column_exists($pdo, 'config_facturacion', 'activo')) {
                        $pdo->exec('UPDATE config_facturacion SET activo = 0');
                    }

                    $hasId = flus_column_exists($pdo, 'config_facturacion', 'id');
                    if ($target && $hasId && isset($target['id'])) {
                        factcfg_update_by_id($pdo, (int)$target['id'], $data);
                    } else {
                        $data['creado_en'] = $timestamp;
                        factcfg_insert($pdo, $data);
                    }

                    config_set($pdo, 'facturacion_modo', $modo);
                    config_set($pdo, 'business_name', $razonSocial);
                    config_set($pdo, 'business_cuit', $cuit);
                    config_set($pdo, 'business_address', $domicilio);
                    config_set($pdo, 'business_iibb', $iibb);
                    config_set($pdo, 'business_inicio_actividades', $inicioActividades);
                    config_set($pdo, 'business_logo_url', $logoUrl);
                    config_set($pdo, 'facturacion_print_item_limit', (string)$printItemLimit);
                    $pdo->commit();
                    config_clear_cache();

                    if (!$facturacionHabilitada || !flus_facturacion_modo_requires_arca($modo)) {
                        flus_facturacion_arca_status_write($pdo, 'not_required', $modo, '');
                    } else {
                        flus_facturacion_arca_status_write($pdo, 'unknown', $modo, 'Sin verificacion reciente. Usa "Probar conexion con ARCA".');
                    }

                    $mensaje = 'Configuracion guardada correctamente.';
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = 'Error guardando la configuracion: ' . $e->getMessage();
                }
            }
        }

        if ($accion === 'test_conexion') {
            $configActual = factcfg_defaults($pdo, factcfg_fetch_current($pdo, false) ?? []);
            $modoActual = flus_facturacion_modo_actual($configActual);

            if (!$facturacionHabilitada || !flus_facturacion_modo_requires_arca($modoActual)) {
                $mensaje = 'ARCA no requerida para la configuracion actual.';
            } else {
                $estadoArca = flus_facturacion_arca_status_current($pdo, $modoActual, true);
                if (!empty($estadoArca['available'])) {
                    $mensaje = 'ARCA disponible.';
                    if (!empty($estadoArca['checked_at'])) {
                        $mensaje .= ' (Chequeado: ' . factcfg_format_checked_at((string)$estadoArca['checked_at']) . ')';
                    }
                } else {
                    $error = 'ARCA no responde en este momento. No se puede emitir factura ahora.';
                    $errorDetail = trim((string)($estadoArca['last_error'] ?? ''));
                }
            }
        }

        if ($accion === 'sincronizar_numero') {
            if (!$tableReady) {
                $error = 'La tabla config_facturacion no existe. Aplica primero la migracion de facturacion.';
            } else {
                $puntoVenta = (int)($_POST['punto_venta'] ?? 1);
                $tipoCbte = (int)($_POST['tipo_cbte'] ?? 11);

                try {
                    require_once __DIR__ . '/includes/ArcaWsfe.php';
                    $ultimo = ArcaWsfe::getUltimoAutorizado($puntoVenta, $tipoCbte);

                    if ($ultimo === null) {
                        $error = 'Error consultando AFIP: ' . (ArcaWsfe::getLastError() ?: 'No se pudo obtener el ultimo numero autorizado.');
                    } elseif (!flus_column_exists($pdo, 'config_facturacion', 'proximo_numero')) {
                        $mensaje = 'AFIP respondio correctamente. Ultimo autorizado: ' . $ultimo . '. Este esquema no tiene la columna proximo_numero, por eso no se guardo el valor local.';
                    } else {
                        $proximo = $ultimo + 1;
                        $target = factcfg_fetch_by_punto_venta($pdo, $puntoVenta, false) ?: factcfg_fetch_current($pdo, false);

                        if ($target && flus_column_exists($pdo, 'config_facturacion', 'id') && isset($target['id'])) {
                            factcfg_update_by_id($pdo, (int)$target['id'], [
                                'proximo_numero' => $proximo,
                                'actualizado_en' => date('Y-m-d H:i:s'),
                            ]);
                        } elseif (flus_column_exists($pdo, 'config_facturacion', 'punto_venta')) {
                            $st = $pdo->prepare('UPDATE config_facturacion SET proximo_numero = ? WHERE punto_venta = ?');
                            $st->execute([$proximo, $puntoVenta]);
                        }

                        $mensaje = 'Sincronizado correctamente. Ultimo autorizado: ' . $ultimo . '. Proximo local: ' . $proximo . '.';
                    }
                } catch (Throwable $e) {
                    $error = 'Error sincronizando numeracion: ' . $e->getMessage();
                }
            }
        }
    }
}

$configRow = null;
if ($tableReady) {
    try {
        $configRow = factcfg_fetch_current($pdo, false);
    } catch (Throwable $e) {
        $error = $error !== '' ? $error : 'No se pudo leer la configuracion actual: ' . $e->getMessage();
    }
}

$config = factcfg_defaults($pdo, $configRow ?? []);
$printItemLimit = flus_facturacion_print_item_limit($pdo);
$configModo = flus_facturacion_modo_actual($config);
$configModoLabel = flus_facturacion_modo_label($configModo);
$modoNoDemo = flus_facturacion_modo_requires_arca($configModo);
$arcaEstado = flus_facturacion_arca_status_current($pdo, $configModo, false);
$arcaStatusClass = match ((string)($arcaEstado['status'] ?? 'unavailable')) {
    'available' => 'ok',
    'not_required' => 'warning',
    'unknown' => 'warning',
    default => 'error',
};
$arcaLastErrorTechnical = trim((string)($arcaEstado['last_error'] ?? ''));
$arcaLastErrorSummary = factcfg_humanize_arca_status_error($arcaLastErrorTechnical);
$arcaHasTechnicalDetail = $arcaLastErrorTechnical !== '' && $arcaLastErrorTechnical !== $arcaLastErrorSummary;
$arcaLastErrorClass = $arcaLastErrorTechnical === '' ? 'ok' : 'warning';
$arcaCheckedAtRaw = trim((string)($arcaEstado['checked_at'] ?? ''));
$arcaCheckedAtLabel = factcfg_format_checked_at($arcaCheckedAtRaw);
$arcaCheckedAtClass = $arcaCheckedAtRaw !== '' ? 'ok' : 'warning';
$preflightArca = flus_facturacion_preflight_arca($configModo);
$emitPreflight = flus_facturacion_preflight_emision($pdo, $configRow ?? null, ['modo' => $configModo]);
$emitReadyClass = ($emitPreflight['ok'] ?? false) ? 'ok' : 'error';
$emitReadyLabel = ($emitPreflight['ok'] ?? false) ? 'Listo para emitir' : 'Bloqueado';
$soapOk = extension_loaded('soap');
$opensslOk = extension_loaded('openssl');
$pageTitle = 'Configuracion de Facturacion';
$currentSection = 'facturacion';
$breadcrumbs = [
    ['label' => 'Facturación', 'url' => 'facturacion.php'],
    ['label' => 'Configuración', 'url' => null],
];
$extraCss = ['assets/css/facturacion_config.css?v=2'];

require __DIR__ . '/partials/header.php';
?>

<div class="panel factura-config-panel">
    <header class="page-header">
        <div>
            <h1 class="page-title">Configuracion de Facturacion</h1>
            <p class="page-sub">Prepara la emision electronica y deja la base lista para crecer con menos fragilidad.</p>
        </div>
        <a href="facturacion.php" class="v-btn v-btn--ghost">Volver</a>
    </header>

    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-success"><?= h($mensaje) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-error">
            <div><?= h($error) ?></div>
            <?php if ($errorDetail !== ''): ?>
                <details style="margin-top:8px;">
                    <summary>Ver detalle tecnico</summary>
                    <div class="muted" style="margin-top:8px; overflow-wrap:anywhere; word-break:break-word;">
                        <?= nl2br(h($errorDetail)) ?>
                    </div>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$tableReady): ?>
        <div class="alert alert-error">La tabla <code>config_facturacion</code> no existe todavia. Aplica la migracion de facturacion antes de guardar datos.</div>
    <?php endif; ?>

    <section class="config-section">
        <h2 class="section-title">Estado del sistema</h2>

        <div class="status-grid">
            <div class="status-item <?= $facturacionHabilitada ? 'ok' : 'warning' ?>">
                <span class="status-icon"><?= $facturacionHabilitada ? 'ON' : 'OFF' ?></span>
                <span class="status-label">Modulo de facturacion</span>
                <span class="status-value"><?= $facturacionHabilitada ? 'Habilitado' : 'Deshabilitado' ?></span>
            </div>

            <div class="status-item <?= h($arcaStatusClass) ?>">
                <span class="status-icon"><?= ($arcaEstado['status'] ?? '') === 'available' ? 'OK' : (($arcaEstado['status'] ?? '') === 'not_required' ? 'N/A' : (($arcaEstado['status'] ?? '') === 'unknown' ? 'INFO' : 'ERR')) ?></span>
                <span class="status-label">Estado ARCA</span>
                <span class="status-value"><?= h((string)($arcaEstado['label'] ?? 'ARCA no disponible')) ?></span>
            </div>

            <div class="status-item <?= $arcaLastErrorClass ?>">
                <span class="status-icon"><?= $arcaLastErrorTechnical === '' ? 'OK' : 'INFO' ?></span>
                <span class="status-label">Ultimo error</span>
                <span class="status-value"><?= h($arcaLastErrorSummary) ?></span>
                <?php if ($arcaHasTechnicalDetail): ?>
                    <details style="margin-top:8px;">
                        <summary>Ver detalle tecnico</summary>
                        <div class="muted" style="margin-top:8px; overflow-wrap:anywhere; word-break:break-word;">
                            <?= nl2br(h($arcaLastErrorTechnical)) ?>
                        </div>
                    </details>
                <?php endif; ?>
            </div>

            <div class="status-item <?= $arcaCheckedAtClass ?>">
                <span class="status-icon"><?= $arcaCheckedAtRaw !== '' ? 'OK' : 'INFO' ?></span>
                <span class="status-label">Ultima verificacion</span>
                <span class="status-value"><?= h($arcaCheckedAtLabel) ?></span>
            </div>

            <div class="status-item <?= $soapOk ? 'ok' : 'error' ?>">
                <span class="status-icon"><?= $soapOk ? 'OK' : 'NO' ?></span>
                <span class="status-label">Extension SOAP</span>
                <span class="status-value"><?= $soapOk ? 'Habilitada' : 'No instalada' ?></span>
            </div>

            <div class="status-item <?= $opensslOk ? 'ok' : 'error' ?>">
                <span class="status-icon"><?= $opensslOk ? 'OK' : 'NO' ?></span>
                <span class="status-label">Extension OpenSSL</span>
                <span class="status-value"><?= $opensslOk ? 'Habilitada' : 'No instalada' ?></span>
            </div>

            <?php foreach ($preflightArca['items'] as $preflightItem): ?>
                <div class="status-item <?= h((string)($preflightItem['status'] ?? 'warning')) ?>">
                    <span class="status-icon"><?= ($preflightItem['status'] ?? 'warning') === 'ok' ? 'OK' : (($preflightItem['status'] ?? 'warning') === 'error' ? 'ERR' : 'WARN') ?></span>
                    <span class="status-label"><?= h((string)($preflightItem['label'] ?? 'Estado')) ?></span>
                    <span class="status-value"><?= h((string)($preflightItem['value'] ?? '-')) ?></span>
                </div>
            <?php endforeach; ?>

            <div class="status-item <?= $tableReady ? 'ok' : 'warning' ?>">
                <span class="status-icon"><?= $tableReady ? 'OK' : 'WARN' ?></span>
                <span class="status-label">Tabla config_facturacion</span>
                <span class="status-value"><?= $tableReady ? 'Disponible' : 'Pendiente de migracion' ?></span>
            </div>

            <div class="status-item <?= $configModo === 'demo' ? 'warning' : 'ok' ?>">
                <span class="status-icon"><?= $configModo === 'produccion' ? 'PROD' : ($configModo === 'homologacion' ? 'HOMO' : 'DEMO') ?></span>
                <span class="status-label">Modo actual</span>
                <span class="status-value"><?= h($configModoLabel) ?></span>
            </div>

            <div class="status-item <?= h($emitReadyClass) ?>">
                <span class="status-icon"><?= ($emitPreflight['ok'] ?? false) ? 'OK' : 'ERR' ?></span>
                <span class="status-label">Preflight de emision</span>
                <span class="status-value"><?= h($emitReadyLabel) ?></span>
            </div>
        </div>

        <?php if ($configArcaExists && $soapOk && $opensslOk && $facturacionHabilitada && $modoNoDemo): ?>
            <form method="post" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= h((string)($_SESSION['csrf_token'] ?? '')) ?>">
                <input type="hidden" name="accion" value="test_conexion">
                <button type="submit" class="v-btn v-btn--outline">Probar conexion con ARCA</button>
            </form>
        <?php endif; ?>

        <?php foreach ($preflightArca['warnings'] as $warning): ?>
            <p class="section-desc"><?= h($warning) ?></p>
        <?php endforeach; ?>

        <?php if (!($emitPreflight['ok'] ?? false)): ?>
            <div class="alert alert-error" style="margin-top:12px;">
                <div><strong>Emision bloqueada:</strong> <?= h(flus_facturacion_preflight_emision_error($emitPreflight)) ?></div>
            </div>
        <?php endif; ?>

        <?php foreach ((array)($emitPreflight['warnings'] ?? []) as $emitWarning): ?>
            <p class="section-desc"><?= h((string)$emitWarning) ?></p>
        <?php endforeach; ?>
    </section>

    <section class="config-section">
        <h2 class="section-title">Configuracion general</h2>

        <form method="post" class="config-form" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= h((string)($_SESSION['csrf_token'] ?? '')) ?>">
            <input type="hidden" name="accion" value="guardar_config">

            <div class="form-grid">
                <div class="form-group">
                    <label for="razon_social">Razon social o nombre</label>
                    <input
                        type="text"
                        id="razon_social"
                        name="razon_social"
                        value="<?= h($config['razon_social']) ?>"
                        placeholder="Ej: Mi Kiosco Demo"
                    >
                </div>

                <div class="form-group">
                    <label for="cuit">CUIT</label>
                    <input
                        type="text"
                        id="cuit"
                        name="cuit"
                        value="<?= h($config['cuit']) ?>"
                        placeholder="20-12345678-9"
                    >
                </div>

                <div class="form-group full-width">
                    <label for="domicilio">Domicilio fiscal</label>
                    <input
                        type="text"
                        id="domicilio"
                        name="domicilio"
                        value="<?= h($config['domicilio']) ?>"
                        placeholder="Ej: San Martin 123, Mendoza"
                    >
                </div>
                <div class="form-group full-width">
                    <label for="logo_url">Logo de la empresa</label>
                    <input
                        type="text"
                        id="logo_url"
                        name="logo_url"
                        value="<?= h($config['logo_url']) ?>"
                        placeholder="Ej: img/logo_factura.png o https://sitio.com/logo.png"
                    >
                    <small>Usa una ruta publica dentro de <code>public/</code>, una URL completa o sube un archivo abajo.</small>
                    <input
                        type="file"
                        id="logo_file"
                        name="logo_file"
                        accept=".png,.jpg,.jpeg,.webp,.gif,image/png,image/jpeg,image/webp,image/gif"
                    >
                    <small>Formatos permitidos: PNG, JPG, WEBP o GIF. Tamano maximo: 2 MB.</small>
                    <?php if ($config['logo_url'] !== ''): ?>
                        <div style="margin-top:10px;">
                            <img src="<?= h($config['logo_url']) ?>" alt="Logo actual" style="max-width:220px;max-height:90px;object-fit:contain;border:1px solid #d0d7e2;padding:8px;background:#fff;">
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="iibb">Ingresos brutos</label>
                    <input
                        type="text"
                        id="iibb"
                        name="iibb"
                        value="<?= h($config['iibb']) ?>"
                        placeholder="Ej: CM 901-234567-8"
                    >
                </div>

                <div class="form-group">
                    <label for="inicio_actividades">Inicio de actividades</label>
                    <input
                        type="text"
                        id="inicio_actividades"
                        name="inicio_actividades"
                        value="<?= h($config['inicio_actividades']) ?>"
                        placeholder="Ej: 01/03/2026"
                    >
                </div>

                <div class="form-group">
                    <label for="punto_venta">Punto de venta</label>
                    <input
                        type="number"
                        id="punto_venta"
                        name="punto_venta"
                        value="<?= (int)$config['punto_venta'] ?>"
                        min="1"
                        max="99999"
                    >
                    <small>Numero habilitado en AFIP.</small>
                </div>

                <div class="form-group">
                    <label for="cond_iva">Condicion IVA del emisor</label>
                    <select id="cond_iva" name="cond_iva">
                        <option value="RI" <?= $config['cond_iva'] === 'RI' ? 'selected' : '' ?>>Responsable inscripto</option>
                        <option value="MT" <?= $config['cond_iva'] === 'MT' ? 'selected' : '' ?>>Monotributista</option>
                        <option value="EX" <?= $config['cond_iva'] === 'EX' ? 'selected' : '' ?>>Exento</option>
                    </select>
                    <small>Se usa para resolver el tipo de comprobante con el cliente.</small>
                </div>

                <div class="form-group">
                    <label for="modo">Modo de facturacion</label>
                    <select id="modo" name="modo">
                        <option value="demo" <?= $configModo === 'demo' ? 'selected' : '' ?>>Demo</option>
                        <option value="homologacion" <?= $configModo === 'homologacion' ? 'selected' : '' ?>>Homologacion</option>
                        <option value="produccion" <?= $configModo === 'produccion' ? 'selected' : '' ?>>Produccion</option>
                    </select>
                    <small>Demo no sale a ARCA. Homologacion prueba WSAA/WSFE sin valor fiscal real. Produccion emite comprobantes reales.</small>
                </div>

                <div class="form-group">
                    <label for="print_item_limit">Limite operativo de items</label>
                    <input
                        type="number"
                        id="print_item_limit"
                        name="print_item_limit"
                        value="<?= (int)$printItemLimit ?>"
                        min="1"
                        max="200"
                    >
                    <small>Controla la impresion en una sola hoja. No es una regla fiscal.</small>
                </div>

                <div class="form-group">
                    <label>Proximo numero</label>
                    <div class="numero-display">
                        <span class="numero-valor"><?= (int)$config['proximo_numero'] ?></span>
                    </div>
                    <small>En demo se actualiza automaticamente. En ARCA conviene sincronizarlo a demanda.</small>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="v-btn v-btn--primary" <?= !$tableReady ? 'disabled' : '' ?>>Guardar configuracion</button>
            </div>
        </form>
    </section>

    <?php if ($configArcaExists && $facturacionHabilitada && $configModo !== 'demo' && $tableReady): ?>
        <section class="config-section">
            <h2 class="section-title">Sincronizar numeracion</h2>
            <p class="section-desc">Consulta a ARCA el ultimo numero autorizado en <?= h($configModoLabel) ?> y alinea el consecutivo local.</p>

            <form method="post" class="sync-form">
                <input type="hidden" name="csrf_token" value="<?= h((string)($_SESSION['csrf_token'] ?? '')) ?>">
                <input type="hidden" name="accion" value="sincronizar_numero">
                <input type="hidden" name="punto_venta" value="<?= (int)$config['punto_venta'] ?>">

                <div class="form-row">
                    <label for="tipo_cbte">Tipo de comprobante</label>
                    <select id="tipo_cbte" name="tipo_cbte">
                        <option value="11">Factura C</option>
                        <option value="6">Factura B</option>
                        <option value="1">Factura A</option>
                    </select>
                    <button type="submit" class="v-btn v-btn--outline">Sincronizar</button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="config-section instructions">
        <h2 class="section-title">Pasos para homologacion y produccion</h2>

        <div class="instruction-steps">
            <div class="step">
                <span class="step-number">1</span>
                <div class="step-content">
                    <h4>Genera el certificado WSASS</h4>
                    <p>Entra a AFIP/ARCA, crea el certificado para testing y autoriza WSFE antes de intentar homologacion.</p>
                </div>
            </div>

            <div class="step">
                <span class="step-number">2</span>
                <div class="step-content">
                    <h4>Prepara los PEM</h4>
                    <p>Si recibes archivos .crt y .key, conviertelos a formato .pem con OpenSSL.</p>
                </div>
            </div>

            <div class="step">
                <span class="step-number">3</span>
                <div class="step-content">
                    <h4>Crea el archivo de configuracion</h4>
                    <p>Copia <code>src/config_arca.example.php</code> a <code>src/config_arca.php</code> y completa los datos del emisor.</p>
                </div>
            </div>

            <div class="step">
                <span class="step-number">4</span>
                <div class="step-content">
                    <h4>Guarda los certificados</h4>
                    <p>Ubica los .pem dentro de <code>storage/certs/</code> y revisa las rutas cargadas en la configuracion.</p>
                </div>
            </div>

            <div class="step">
                <span class="step-number">5</span>
                <div class="step-content">
                    <h4>Prueba la conexion</h4>
                    <p>Guarda el modo en Homologacion, revisa que FLUS_ARCA_ENV quede en homo y usa el boton de prueba para validar WSAA/WSFE.</p>
                </div>
            </div>

            <div class="step">
                <span class="step-number">6</span>
                <div class="step-content">
                    <h4>Pasa a produccion</h4>
                    <p>Cuando homologacion responda bien, cambia certificados y FLUS_ARCA_ENV a prod, luego pasa Flus a Produccion y sincroniza numeracion.</p>
                </div>
            </div>
        </div>
    </section>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

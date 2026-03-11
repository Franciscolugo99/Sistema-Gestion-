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

function factcfg_defaults(array $row = []): array
{
    $condIva = factcfg_cond_iva_from_row($row);
    $modo = factcfg_valid_mode((string)($row['modo'] ?? 'demo'));

    return [
        'id' => (int)($row['id'] ?? 0),
        'punto_venta' => max(1, (int)($row['punto_venta'] ?? 1)),
        'cond_iva' => $condIva,
        'modo' => $modo,
        'proximo_numero' => max(1, (int)($row['proximo_numero'] ?? 1)),
        'razon_social' => trim((string)($row['razon_social'] ?? '')),
        'cuit' => trim((string)($row['cuit'] ?? '')),
        'domicilio' => trim((string)($row['domicilio'] ?? '')),
    ];
}

$configArcaPath = __DIR__ . '/../src/config_arca.php';
$configArcaExists = file_exists($configArcaPath);
if ($configArcaExists) {
    require_once $configArcaPath;
}

$mensaje = '';
$error = '';
$tableReady = factcfg_table_ready($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
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

                if ($puntoVenta < 1 || $puntoVenta > 99999) {
                    $puntoVenta = 1;
                }

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
                    $pdo->commit();
                    config_clear_cache();
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
            require_once __DIR__ . '/../src/facturacion_lib.php';
            $resultado = verificarConexionAfip();
            if (!empty($resultado['conectado'])) {
                $mensaje = (string)($resultado['mensaje'] ?? 'Conexion exitosa.');
                if (!empty($resultado['detalles']['ambiente'])) {
                    $mensaje .= ' (Ambiente: ' . $resultado['detalles']['ambiente'] . ')';
                }
            } else {
                $error = (string)($resultado['mensaje'] ?? 'No se pudo conectar con AFIP.');
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

$config = factcfg_defaults($configRow ?? []);
$configModo = flus_facturacion_modo_actual($config);
$configModoLabel = flus_facturacion_modo_label($configModo);
$modoNoDemo = flus_facturacion_modo_requires_arca($configModo);
$preflightArca = flus_facturacion_preflight_arca($configModo);
$soapOk = extension_loaded('soap');
$opensslOk = extension_loaded('openssl');
$pageTitle = 'Configuracion de Facturacion';
$currentSection = 'facturacion';
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
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if (!$tableReady): ?>
        <div class="alert alert-error">La tabla <code>config_facturacion</code> no existe todavia. Aplica la migracion de facturacion antes de guardar datos.</div>
    <?php endif; ?>

    <section class="config-section">
        <h2 class="section-title">Estado del sistema</h2>

        <div class="status-grid">
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
        </div>

        <?php if ($configArcaExists && $soapOk && $opensslOk): ?>
            <form method="post" class="inline-form">
                <input type="hidden" name="csrf_token" value="<?= h((string)($_SESSION['csrf_token'] ?? '')) ?>">
                <input type="hidden" name="accion" value="test_conexion">
                <button type="submit" class="v-btn v-btn--outline">Probar conexion con ARCA</button>
            </form>
        <?php endif; ?>

        <?php foreach ($preflightArca['warnings'] as $warning): ?>
            <p class="section-desc"><?= h($warning) ?></p>
        <?php endforeach; ?>
    </section>

    <section class="config-section">
        <h2 class="section-title">Configuracion general</h2>

        <form method="post" class="config-form">
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
                        placeholder="Av. Siempre Viva 123"
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
                    <label>Proximo numero</label>
                    <div class="numero-display">
                        <span class="numero-valor"><?= (int)$config['proximo_numero'] ?></span>
                    </div>
                    <small>Si la tabla lo soporta, se actualiza desde la sincronizacion.</small>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="v-btn v-btn--primary" <?= !$tableReady ? 'disabled' : '' ?>>Guardar configuracion</button>
            </div>
        </form>
    </section>

    <?php if ($configArcaExists && $configModo !== 'demo' && $tableReady): ?>
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
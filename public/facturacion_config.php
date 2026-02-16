<?php
// public/facturacion_config.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('administrar_config');

$pdo = getPDO();

// Cargar config_arca si existe
$configArcaPath = __DIR__ . '/../src/config_arca.php';
$configArcaExists = file_exists($configArcaPath);
if ($configArcaExists) {
    require_once $configArcaPath;
}

// Mensajes
$mensaje = '';
$error = '';

// Procesar POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $error = 'Token CSRF inválido.';
    } else {
        $accion = (string)($_POST['accion'] ?? '');
        
        if ($accion === 'guardar_config') {
            $puntoVenta = (int)($_POST['punto_venta'] ?? 1);
            $condIva = (string)($_POST['cond_iva'] ?? 'MT');
            $modo = (string)($_POST['modo'] ?? 'demo');
            $razonSocial = trim((string)($_POST['razon_social'] ?? ''));
            $cuit = preg_replace('/\D/', '', (string)($_POST['cuit'] ?? ''));
            $domicilio = trim((string)($_POST['domicilio'] ?? ''));
            
            // P1: Validación server-side de ENUMs
            $modo = in_array($modo, ['demo', 'produccion'], true) ? $modo : 'demo';
            $condIva = in_array($condIva, ['RI', 'MT', 'EX'], true) ? $condIva : 'MT';
            
            if ($puntoVenta < 1 || $puntoVenta > 99999) {
                $puntoVenta = 1;
            }
            
            try {
                $pdo->beginTransaction();
                
                // P0: Desactivar TODAS las configs antes de guardar
                // Esto asegura que solo haya UNA config activa
                $pdo->exec("UPDATE config_facturacion SET activo = 0");
                
                $st = $pdo->prepare("
                    INSERT INTO config_facturacion 
                        (punto_venta, cond_iva, modo, razon_social, cuit, domicilio, activo)
                    VALUES 
                        (:pv, :cond, :modo, :razon, :cuit, :dom, 1)
                    ON DUPLICATE KEY UPDATE
                        cond_iva = VALUES(cond_iva),
                        modo = VALUES(modo),
                        razon_social = VALUES(razon_social),
                        cuit = VALUES(cuit),
                        domicilio = VALUES(domicilio),
                        activo = 1,
                        actualizado_en = NOW()
                ");
                $st->execute([
                    ':pv' => $puntoVenta,
                    ':cond' => $condIva,
                    ':modo' => $modo,
                    ':razon' => $razonSocial,
                    ':cuit' => $cuit,
                    ':dom' => $domicilio,
                ]);
                
                $pdo->commit();
                $mensaje = '✅ Configuración guardada correctamente.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Error guardando: ' . $e->getMessage();
            }
        }
        
        if ($accion === 'test_conexion') {
            require_once __DIR__ . '/../src/facturacion_lib.php';
            $resultado = verificarConexionAfip();
            if ($resultado['conectado']) {
                $mensaje = '✅ ' . $resultado['mensaje'];
                if (!empty($resultado['detalles'])) {
                    $mensaje .= ' (Ambiente: ' . ($resultado['detalles']['ambiente'] ?? '?') . ')';
                }
            } else {
                $error = '❌ ' . $resultado['mensaje'];
            }
        }
        
        if ($accion === 'sincronizar_numero') {
            $puntoVenta = (int)($_POST['punto_venta'] ?? 1);
            $tipoCbte = (int)($_POST['tipo_cbte'] ?? 11);
            
            require_once __DIR__ . '/includes/ArcaWsfe.php';
            $ultimo = ArcaWsfe::getUltimoAutorizado($puntoVenta, $tipoCbte);
            
            if ($ultimo !== null) {
                $proximo = $ultimo + 1;
                $st = $pdo->prepare("
                    UPDATE config_facturacion 
                    SET proximo_numero = ? 
                    WHERE punto_venta = ?
                ");
                $st->execute([$proximo, $puntoVenta]);
                $mensaje = "✅ Sincronizado. Último autorizado: $ultimo. Próximo: $proximo";
            } else {
                $error = '❌ Error: ' . (ArcaWsfe::getLastError() ?: 'No se pudo obtener el último número');
            }
        }
    }
}

// Cargar configuración actual
$config = null;
try {
    $st = $pdo->query("SELECT * FROM config_facturacion WHERE activo = 1 ORDER BY id LIMIT 1");
    $config = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // Tabla no existe, se creará con la migración
}

// Defaults
$config = $config ?: [
    'punto_venta' => 1,
    'cond_iva' => 'MT',
    'modo' => 'demo',
    'proximo_numero' => 1,
    'razon_social' => '',
    'cuit' => '',
    'domicilio' => '',
];

// Verificar extensiones PHP
$soapOk = extension_loaded('soap');
$opensslOk = extension_loaded('openssl');

$pageTitle = 'Configuración de Facturación';
$currentSection = 'facturacion';
$extraCss = ['assets/css/facturacion_config.css'];

require __DIR__ . '/partials/header.php';
?>

<div class="panel factura-config-panel">
    
    <header class="page-header">
        <div>
            <h1 class="page-title">⚙️ Configuración de Facturación</h1>
            <p class="page-sub">Configurá la conexión con AFIP/ARCA para emitir comprobantes electrónicos.</p>
        </div>
        <a href="facturacion.php" class="v-btn v-btn--ghost">← Volver</a>
    </header>

    <?php if ($mensaje): ?>
        <div class="alert alert-success"><?= h($mensaje) ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <!-- Estado del sistema -->
    <section class="config-section">
        <h2 class="section-title">Estado del Sistema</h2>
        
        <div class="status-grid">
            <div class="status-item <?= $soapOk ? 'ok' : 'error' ?>">
                <span class="status-icon"><?= $soapOk ? '✅' : '❌' ?></span>
                <span class="status-label">Extensión SOAP</span>
                <span class="status-value"><?= $soapOk ? 'Habilitada' : 'No instalada' ?></span>
            </div>
            
            <div class="status-item <?= $opensslOk ? 'ok' : 'error' ?>">
                <span class="status-icon"><?= $opensslOk ? '✅' : '❌' ?></span>
                <span class="status-label">Extensión OpenSSL</span>
                <span class="status-value"><?= $opensslOk ? 'Habilitada' : 'No instalada' ?></span>
            </div>
            
            <div class="status-item <?= $configArcaExists ? 'ok' : 'warning' ?>">
                <span class="status-icon"><?= $configArcaExists ? '✅' : '⚠️' ?></span>
                <span class="status-label">Archivo config_arca.php</span>
                <span class="status-value"><?= $configArcaExists ? 'Configurado' : 'No existe' ?></span>
            </div>
            
            <div class="status-item <?= ($config['modo'] ?? 'demo') === 'produccion' ? 'ok' : 'warning' ?>">
                <span class="status-icon"><?= ($config['modo'] ?? 'demo') === 'produccion' ? '🟢' : '🟡' ?></span>
                <span class="status-label">Modo actual</span>
                <span class="status-value"><?= ($config['modo'] ?? 'demo') === 'produccion' ? 'PRODUCCIÓN' : 'DEMO' ?></span>
            </div>
        </div>

        <?php if ($configArcaExists && $soapOk && $opensslOk): ?>
        <form method="post" class="inline-form">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="accion" value="test_conexion">
            <button type="submit" class="v-btn v-btn--outline">🔌 Probar conexión con AFIP</button>
        </form>
        <?php endif; ?>
    </section>

    <!-- Configuración general -->
    <section class="config-section">
        <h2 class="section-title">Configuración General</h2>
        
        <form method="post" class="config-form">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="accion" value="guardar_config">
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="razon_social">Razón Social / Nombre</label>
                    <input type="text" id="razon_social" name="razon_social" 
                           value="<?= h($config['razon_social'] ?? '') ?>"
                           placeholder="Ej: KIOSCO DON PEDRO">
                </div>
                
                <div class="form-group">
                    <label for="cuit">CUIT</label>
                    <input type="text" id="cuit" name="cuit" 
                           value="<?= h($config['cuit'] ?? '') ?>"
                           placeholder="20-12345678-9">
                </div>
                
                <div class="form-group full-width">
                    <label for="domicilio">Domicilio Fiscal</label>
                    <input type="text" id="domicilio" name="domicilio" 
                           value="<?= h($config['domicilio'] ?? '') ?>"
                           placeholder="Av. Siempre Viva 123, Ciudad">
                </div>
                
                <div class="form-group">
                    <label for="punto_venta">Punto de Venta</label>
                    <input type="number" id="punto_venta" name="punto_venta" 
                           value="<?= (int)($config['punto_venta'] ?? 1) ?>"
                           min="1" max="99999">
                    <small>Número habilitado en AFIP</small>
                </div>
                
                <div class="form-group">
                    <label for="cond_iva">Condición IVA (Emisor)</label>
                    <select id="cond_iva" name="cond_iva">
                        <option value="RI" <?= ($config['cond_iva'] ?? '') === 'RI' ? 'selected' : '' ?>>Responsable Inscripto</option>
                        <option value="MT" <?= ($config['cond_iva'] ?? '') === 'MT' ? 'selected' : '' ?>>Monotributista</option>
                        <option value="EX" <?= ($config['cond_iva'] ?? '') === 'EX' ? 'selected' : '' ?>>Exento</option>
                    </select>
                    <small>Determina qué tipo de factura emitís (A, B o C)</small>
                </div>
                
                <div class="form-group">
                    <label for="modo">Modo de Facturación</label>
                    <select id="modo" name="modo">
                        <option value="demo" <?= ($config['modo'] ?? 'demo') === 'demo' ? 'selected' : '' ?>>🟡 DEMO (sin AFIP)</option>
                        <option value="produccion" <?= ($config['modo'] ?? '') === 'produccion' ? 'selected' : '' ?>>🟢 PRODUCCIÓN (AFIP real)</option>
                    </select>
                    <small>En modo DEMO se genera CAE ficticio</small>
                </div>
                
                <div class="form-group">
                    <label>Próximo Número</label>
                    <div class="numero-display">
                        <span class="numero-valor"><?= (int)($config['proximo_numero'] ?? 1) ?></span>
                    </div>
                    <small>Se sincroniza automáticamente con AFIP</small>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="v-btn v-btn--primary">💾 Guardar Configuración</button>
            </div>
        </form>
    </section>

    <!-- Sincronizar con AFIP -->
    <?php if ($configArcaExists && ($config['modo'] ?? 'demo') === 'produccion'): ?>
    <section class="config-section">
        <h2 class="section-title">Sincronizar Numeración</h2>
        <p class="section-desc">Consultá a AFIP el último número autorizado y sincronizá la numeración local.</p>
        
        <form method="post" class="sync-form">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
            <input type="hidden" name="accion" value="sincronizar_numero">
            <input type="hidden" name="punto_venta" value="<?= (int)($config['punto_venta'] ?? 1) ?>">
            
            <div class="form-row">
                <label for="tipo_cbte">Tipo de Comprobante:</label>
                <select id="tipo_cbte" name="tipo_cbte">
                    <option value="11">Factura C</option>
                    <option value="6">Factura B</option>
                    <option value="1">Factura A</option>
                </select>
                <button type="submit" class="v-btn v-btn--outline">🔄 Sincronizar</button>
            </div>
        </form>
    </section>
    <?php endif; ?>

    <!-- Instrucciones -->
    <section class="config-section instructions">
        <h2 class="section-title">📋 Instrucciones para Producción</h2>
        
        <div class="instruction-steps">
            <div class="step">
                <span class="step-number">1</span>
                <div class="step-content">
                    <h4>Obtener certificado digital</h4>
                    <p>Ingresá a <a href="https://www.afip.gob.ar" target="_blank">AFIP</a> con clave fiscal y generá un certificado para "wsfe" en ARCA - Autogestión de certificados.</p>
                </div>
            </div>
            
            <div class="step">
                <span class="step-number">2</span>
                <div class="step-content">
                    <h4>Convertir a formato PEM</h4>
                    <p>Si te dan .crt y .key, convertilos a .pem con OpenSSL.</p>
                </div>
            </div>
            
            <div class="step">
                <span class="step-number">3</span>
                <div class="step-content">
                    <h4>Crear archivo de configuración</h4>
                    <p>Copiá <code>src/config_arca.example.php</code> a <code>src/config_arca.php</code> y completá con tus datos.</p>
                </div>
            </div>
            
            <div class="step">
                <span class="step-number">4</span>
                <div class="step-content">
                    <h4>Ubicar certificados</h4>
                    <p>Guardá los archivos .pem en <code>storage/certs/</code> y configurá las rutas en config_arca.php.</p>
                </div>
            </div>
            
            <div class="step">
                <span class="step-number">5</span>
                <div class="step-content">
                    <h4>Probar conexión</h4>
                    <p>Usá el botón "Probar conexión con AFIP" para verificar que todo funcione.</p>
                </div>
            </div>
            
            <div class="step">
                <span class="step-number">6</span>
                <div class="step-content">
                    <h4>Cambiar a producción</h4>
                    <p>Cuando todo funcione, cambiá el modo a "PRODUCCIÓN" y empezá a facturar.</p>
                </div>
            </div>
        </div>
    </section>

</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
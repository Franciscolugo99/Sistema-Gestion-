<?php
// public/factura_nueva.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/facturacion_lib.php';

require_login();
require_permission('emitir_factura');

$facturacionHabilitada = flus_facturacion_habilitada($pdo);
if (!$facturacionHabilitada) {
    header('Location: index.php');
    exit;
}


$ventaId = isset($_GET['venta_id']) ? (int)$_GET['venta_id'] : 0;
if ($ventaId <= 0) {
    header('Location: ventas.php');
    exit;
}

if (!flus_table_exists($pdo, 'ventas')) {
    header('Location: ventas.php');
    exit;
}

$stVenta = $pdo->prepare('
    SELECT id, fecha, total
    FROM ventas
    WHERE id = ?
    LIMIT 1
');
$stVenta->execute([$ventaId]);
$venta = $stVenta->fetch(PDO::FETCH_ASSOC);

if (!$venta) {
    header('Location: ventas.php');
    exit;
}

$facturaExistenteId = flus_facturacion_factura_existente_id($pdo, $ventaId);
if ($facturaExistenteId !== null && !isset($_GET['force'])) {
    header('Location: factura_ver.php?id=' . $facturaExistenteId);
    exit;
}

$config = flus_facturacion_config_activa($pdo);
$cfgError = $config ? null : 'Falta configurar la facturacion (config_facturacion).';
$modoFacturacionActual = is_array($config) ? flus_facturacion_modo_actual($config) : 'demo';
$arcaEstado = flus_facturacion_arca_status_current($pdo, $modoFacturacionActual, false);
$arcaEmitWarning = is_array($config)
    && !empty($arcaEstado['required'])
    && (($arcaEstado['status'] ?? 'unknown') === 'unavailable' || ($arcaEstado['status'] ?? 'unknown') === 'unknown');
$lookupArcaEnv = flus_facturacion_arca_env_actual();
$lookupArcaEnvLabel = $lookupArcaEnv === 'homo' ? 'Homologacion' : 'Produccion';
$clientes = flus_facturacion_clientes_disponibles($pdo);
$itemLimit = flus_facturacion_print_item_limit($pdo);
$itemCountVenta = flus_facturacion_count_items_venta($pdo, $ventaId);
$itemCountExceeded = $itemCountVenta > $itemLimit;
$errores = [];
$factErrorFlash = trim((string)($_GET['fact_error'] ?? ''));
if ($factErrorFlash !== '') {
    $errores[] = $factErrorFlash;
}
$clienteSeleccionadoRaw = (string)($_POST['cliente_id'] ?? '');
$clienteLookupUi = [
    'activo' => (string)($_POST['cliente_lookup_activo'] ?? '0'),
    'confirmado' => (string)($_POST['cliente_lookup_confirmado'] ?? '0'),
    'editor' => (string)($_POST['cliente_lookup_editor'] ?? '0'),
    'cuit' => trim((string)($_POST['cliente_lookup_cuit'] ?? '')),
    'nombre' => trim((string)($_POST['cliente_lookup_nombre'] ?? '')),
    'cond_iva' => trim((string)($_POST['cliente_lookup_cond_iva'] ?? '')),
    'direccion' => trim((string)($_POST['cliente_lookup_direccion'] ?? '')),
    'tipo_cliente' => trim((string)($_POST['cliente_lookup_tipo_cliente'] ?? 'MINORISTA')),
    'estado' => trim((string)($_POST['cliente_lookup_estado'] ?? '')),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $cfgError === null) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errores[] = 'Sesion vencida (CSRF). Actualiza la pagina e intenta de nuevo.';
    }

    if ($itemCountExceeded) {
        $errores[] = flus_facturacion_print_item_limit_message($itemCountVenta, $itemLimit);
    }

    $clienteResult = flus_facturacion_resolver_cliente_desde_input($pdo, $_POST, [
        'mensaje_vacio' => 'Tienes que seleccionar un cliente, Consumidor Final o consultar un CUIT/CUIL.',
        'mensaje_invalido' => 'El cliente seleccionado no es valido.',
        'mensaje_lookup_confirmacion' => 'Confirma que quieres emitir con los datos consultados en ARCA. Si no los vas a usar, descartalos y sigue con el cliente seleccionado.',
    ]);
    $clienteId = $clienteResult['cliente_id'];
    $resolvedCliente = is_array($clienteResult['resolved_cliente']) ? $clienteResult['resolved_cliente'] : null;
    foreach ((array)($clienteResult['errors'] ?? []) as $errorCliente) {
        $errores[] = (string)$errorCliente;
    }

    if ($errores === [] && $clienteId !== null) {
        try {
            $opciones = [];
            if ($resolvedCliente !== null) {
                $opciones['resolved_cliente'] = $resolvedCliente;
            }
            $facturaId = crearFacturaDesdeVenta($ventaId, $clienteId, $opciones);
            header('Location: factura_ver.php?id=' . $facturaId);
            exit;
        } catch (Throwable $e) {
            $facturaExistenteId = flus_facturacion_factura_existente_id($pdo, $ventaId);
            if ($facturaExistenteId !== null) {
                header('Location: factura_ver.php?id=' . $facturaExistenteId);
                exit;
            }

            $errores[] = 'Error al emitir la factura: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Nueva factura';
$currentSection = 'facturacion';
$extraCss = ['assets/css/facturacion.css?v=8'];
$extraJs = ['assets/js/facturacion_cliente_lookup.js?v=3'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap">
  <div class="panel fact-panel">

    <header class="page-header with-back">
      <div class="page-header-left">
        <a href="facturacion.php" class="link-back">&larr; Volver a facturacion</a>
        <h1 class="page-title">Nueva factura</h1>
        <p class="page-sub">
          Emitir comprobante a partir de la venta #<?= (int)$venta['id'] ?>.
        </p>
      </div>
    </header>

    <?php if ($cfgError !== null): ?>
      <div class="alert alert-error" style="margin-top:12px;">
        <?= h($cfgError) ?>
      </div>
    <?php endif; ?>

    <?php if ($arcaEmitWarning): ?>
      <div class="alert alert-warning" style="margin-top:12px;">
        <strong><?= h((string)($arcaEstado['label'] ?? 'ARCA no disponible')) ?>:</strong>
        <?= h((string)($arcaEstado['last_error'] ?? 'No hay verificacion reciente. Usa "Probar conexion con ARCA" antes de emitir.')) ?>
      </div>
    <?php endif; ?>

    <section class="fact-venta-resumen">
      <h2 class="sub-title-page">Resumen de venta</h2>
      <div class="fact-venta-grid">
        <div>
          <div class="muted">Venta</div>
          <div>#<?= (int)$venta['id'] ?></div>
        </div>
        <div>
          <div class="muted">Fecha</div>
          <div><?= h((string)$venta['fecha']) ?></div>
        </div>
        <div>
          <div class="muted">Total</div>
          <div class="mono"><?= money_ar((float)$venta['total']) ?></div>
        </div>
        <div>
          <div class="muted">Punto de venta</div>
          <?php if ($config !== null): ?>
            <?php $modoCfg = flus_facturacion_modo_label(flus_facturacion_modo_actual($config)); ?>
            <div>PV <?= str_pad((string)($config['punto_venta'] ?? 1), 4, '0', STR_PAD_LEFT) ?> - <?= h($modoCfg) ?></div>
          <?php else: ?>
            <div class="muted">Sin configuracion</div>
          <?php endif; ?>
        </div>
      </div>
      <p class="muted" style="margin-top:10px;">
        Puedes emitir para un cliente registrado o para Consumidor Final.
      </p>
      <p class="muted" style="margin-top:6px;">
        Limite operativo de impresion: hasta <?= (int)$itemLimit ?> items por factura de una hoja. Esta venta tiene <?= (int)$itemCountVenta ?>.
      </p>
      <?php if ($itemCountExceeded): ?>
        <div class="alert alert-error" style="margin-top:10px;">
          <?= h(flus_facturacion_print_item_limit_message($itemCountVenta, $itemLimit)) ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="fact-form-section" style="margin-top:18px;">
      <h2 class="sub-title-page">Datos del cliente</h2>

      <form method="post" class="fact-form" data-facturacion-cliente-form="1">
        <?= csrf_field() ?>
        <input type="hidden" name="venta_id" value="<?= (int)$ventaId ?>">

        <?php if ($errores !== []): ?>
          <div class="msg msg-visible msg-error" style="margin:12px 0;">
            <ul>
              <?php foreach ($errores as $e): ?>
                <li><?= h($e) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <div class="fact-form-grid">
          <div class="ff-field ff-field-wide">
            <label>Cliente</label>
            <select name="cliente_id" required <?= $cfgError !== null ? 'disabled' : '' ?> data-lookup-select>
              <option value="">Seleccionar cliente...</option>
              <option value="0" <?= $clienteSeleccionadoRaw === '0' ? 'selected' : '' ?>>Consumidor Final</option>
              <?php foreach ($clientes as $cli): ?>
                <option
                  value="<?= (int)$cli['id'] ?>"
                  <?= ($clienteSeleccionadoRaw !== '' && ctype_digit($clienteSeleccionadoRaw) && (int)$clienteSeleccionadoRaw === (int)$cli['id']) ? 'selected' : '' ?>
                >
                  <?= h((string)($cli['nombre'] ?? 'Cliente')) ?>
                  <?php if (!empty($cli['cuit'])): ?>
                    (<?= h((string)$cli['cuit']) ?>)
                  <?php endif; ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="muted" style="margin-top:6px;">Si consultas un CUIT/CUIL del padron, ese receptor tendra prioridad al emitir.</div>
          </div>

          <div class="ff-field ff-field-wide fact-lookup-card" data-facturacion-cliente-lookup data-lookup-env="<?= h($lookupArcaEnv) ?>">
            <label>Consultar por CUIT / CUIL</label>
            <div class="fact-lookup-inline">
              <input type="text" name="cliente_lookup_cuit" value="<?= h($clienteLookupUi['cuit']) ?>" placeholder="20-12345678-9" <?= $cfgError !== null ? 'disabled' : '' ?> data-lookup-cuit>
              <button type="button" class="btn btn-secondary" <?= $cfgError !== null ? 'disabled' : '' ?> data-lookup-btn>Consultar ARCA</button>
            </div>
            <div class="fact-field-help muted">La consulta de padron sale por ARCA en <?= strtolower(h($lookupArcaEnvLabel)) ?>.</div>
            <?php if ($lookupArcaEnv === 'homo'): ?>
              <div class="fact-field-help is-warning">En homologacion, ARCA puede devolver contribuyentes de prueba que no coinciden con produccion.</div>
            <?php endif; ?>
            <input type="hidden" name="cliente_lookup_activo" value="<?= h($clienteLookupUi['activo']) ?>" data-lookup-activo>
            <input type="hidden" name="cliente_lookup_tipo_cliente" value="<?= h($clienteLookupUi['tipo_cliente']) ?>" data-lookup-tipo-cliente>
            <input type="hidden" name="cliente_lookup_editor" value="<?= h($clienteLookupUi['editor']) ?>" data-lookup-editor-state>
            <div class="fact-lookup-result <?= $clienteLookupUi['activo'] === '1' ? 'is-visible' : '' ?>" data-lookup-result>
              <input type="hidden" name="cliente_lookup_estado" value="<?= h($clienteLookupUi['estado']) ?>" data-lookup-estado>
              <div class="fact-lookup-summary">
                <div class="fact-lookup-summary-main">
                  <div class="fact-lookup-kicker">Receptor a usar</div>
                  <div class="fact-lookup-name" data-lookup-display-nombre>
                    <?= h($clienteLookupUi['nombre'] !== '' ? $clienteLookupUi['nombre'] : 'Completa los datos del receptor') ?>
                  </div>
                  <div class="fact-lookup-cuit" data-lookup-display-cuit><?= h($clienteLookupUi['cuit']) ?></div>
                </div>
                <div class="fact-lookup-meta">
                  <span class="fact-lookup-pill <?= $clienteLookupUi['cond_iva'] === '' ? 'is-empty' : '' ?>" data-lookup-display-cond-iva>
                    <?= h($clienteLookupUi['cond_iva'] !== '' ? $clienteLookupUi['cond_iva'] : 'Cond. IVA pendiente') ?>
                  </span>
                  <span class="fact-lookup-pill <?= $clienteLookupUi['estado'] === '' ? 'is-empty' : '' ?>" data-lookup-display-estado>
                    <?= h($clienteLookupUi['estado'] !== '' ? $clienteLookupUi['estado'] : 'Padron sin estado') ?>
                  </span>
                </div>
                <div class="fact-lookup-address <?= $clienteLookupUi['direccion'] === '' ? 'is-empty' : '' ?>" data-lookup-display-direccion>
                  <?= h($clienteLookupUi['direccion'] !== '' ? $clienteLookupUi['direccion'] : 'Domicilio fiscal pendiente') ?>
                </div>
              </div>
              <div class="fact-lookup-tools">
                <button type="button" class="btn btn-secondary" data-lookup-toggle-editor>
                  <?= $clienteLookupUi['editor'] === '1' ? 'Ocultar edicion manual' : 'Completar o editar a mano' ?>
                </button>
                <button type="button" class="btn btn-secondary" data-lookup-clear>Descartar datos ARCA</button>
              </div>
              <div class="fact-lookup-editor <?= $clienteLookupUi['editor'] === '1' ? 'is-visible' : '' ?>" data-lookup-editor>
                <div class="fact-form-grid">
                  <div class="ff-field ff-field-wide">
                    <label>Razon social</label>
                    <input type="text" name="cliente_lookup_nombre" value="<?= h($clienteLookupUi['nombre']) ?>" placeholder="Completa razon social si ARCA no la trajo" data-lookup-nombre>
                  </div>
                  <div class="ff-field">
                    <label>Condicion IVA</label>
                    <select name="cliente_lookup_cond_iva" data-lookup-cond-iva>
                      <option value="" <?= $clienteLookupUi['cond_iva'] === '' ? 'selected' : '' ?>>Elegir condicion IVA...</option>
                      <?php foreach (['RI' => 'Responsable Inscripto', 'MT' => 'Monotributo', 'EX' => 'Exento', 'CF' => 'Consumidor Final'] as $condKey => $condLabel): ?>
                        <option value="<?= h($condKey) ?>" <?= strtoupper($clienteLookupUi['cond_iva']) === $condKey ? 'selected' : '' ?>><?= h($condLabel) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="ff-field ff-field-wide">
                    <label>Domicilio fiscal</label>
                    <input type="text" name="cliente_lookup_direccion" value="<?= h($clienteLookupUi['direccion']) ?>" placeholder="Completa domicilio si hace falta" data-lookup-direccion>
                  </div>
                </div>
              </div>
              <div class="fact-lookup-confirm">
                <label class="fact-lookup-check">
                  <input
                    type="checkbox"
                    name="cliente_lookup_confirmado"
                    value="1"
                    <?= $clienteLookupUi['confirmado'] === '1' ? 'checked' : '' ?>
                    data-lookup-confirm
                  >
                  <span>Usar estos datos de ARCA al emitir y completar/actualizar el cliente en FLUS.</span>
                </label>
              </div>
              <div class="fact-lookup-status muted" data-lookup-status>
                Si confirmas este bloque, FLUS emitira con estos datos aunque arriba haya otro cliente seleccionado.
              </div>
            </div>
          </div>
        </div>

        <div class="pf-actions" style="margin-top:18px;">
          <button type="submit" class="btn btn-primary" <?= ($cfgError !== null || $itemCountExceeded) ? 'disabled' : '' ?>>
            Emitir factura
          </button>

          <a href="facturacion.php" class="btn btn-secondary">
            Cancelar
          </a>
        </div>

      </form>
    </section>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php
// public/factura_nueva.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/facturacion_lib.php';

require_login();
require_permission('emitir_factura');

$facturacionHabilitada = config_get($pdo, 'facturacion_habilitada', '0') === '1';
if (!$facturacionHabilitada) {
    header('Location: index.php');
    exit;
}

$pdo = getPDO();

function factura_nueva_buscar_existente(PDO $pdo, int $ventaId): ?int
{
    if (!flus_table_exists($pdo, 'facturas') || !flus_column_exists($pdo, 'facturas', 'venta_id')) {
        return null;
    }

    $st = $pdo->prepare('
        SELECT id
        FROM facturas
        WHERE venta_id = ?
        ORDER BY id DESC
        LIMIT 1
    ');
    $st->execute([$ventaId]);
    $facturaId = $st->fetchColumn();

    return $facturaId !== false ? (int)$facturaId : null;
}

function factura_nueva_config(PDO $pdo): ?array
{
    if (!flus_table_exists($pdo, 'config_facturacion')) {
        return null;
    }

    $order = flus_column_exists($pdo, 'config_facturacion', 'id') ? ' ORDER BY id DESC' : '';

    if (flus_column_exists($pdo, 'config_facturacion', 'activo')) {
        $st = $pdo->query('SELECT * FROM config_facturacion WHERE activo = 1' . $order . ' LIMIT 1');
        $row = $st ? ($st->fetch(PDO::FETCH_ASSOC) ?: null) : null;
        if ($row !== null) {
            return $row;
        }
    }

    $st = $pdo->query('SELECT * FROM config_facturacion' . $order . ' LIMIT 1');
    return $st ? ($st->fetch(PDO::FETCH_ASSOC) ?: null) : null;
}

function factura_nueva_clientes(PDO $pdo): array
{
    if (!flus_table_exists($pdo, 'clientes')) {
        return [];
    }

    $nombreExpr = flus_column_exists($pdo, 'clientes', 'nombre') ? 'nombre' : 'CONCAT("Cliente #", id)';
    $cuitExpr = flus_column_exists($pdo, 'clientes', 'cuit') ? 'cuit' : 'NULL';
    $condIvaExpr = flus_column_exists($pdo, 'clientes', 'cond_iva') ? 'cond_iva' : 'NULL';
    $where = flus_column_exists($pdo, 'clientes', 'activo') ? 'WHERE activo = 1' : '';

    $sql = "
        SELECT id, {$nombreExpr} AS nombre, {$cuitExpr} AS cuit, {$condIvaExpr} AS cond_iva
        FROM clientes
        {$where}
        ORDER BY nombre ASC
    ";

    $st = $pdo->query($sql);
    return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function factura_nueva_cliente_valido(PDO $pdo, int $clienteId): bool
{
    if (!flus_table_exists($pdo, 'clientes')) {
        return false;
    }

    $sql = 'SELECT id FROM clientes WHERE id = ?';
    if (flus_column_exists($pdo, 'clientes', 'activo')) {
        $sql .= ' AND activo = 1';
    }
    $sql .= ' LIMIT 1';

    $st = $pdo->prepare($sql);
    $st->execute([$clienteId]);
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
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

$facturaExistenteId = factura_nueva_buscar_existente($pdo, $ventaId);
if ($facturaExistenteId !== null && !isset($_GET['force'])) {
    header('Location: factura_ver.php?id=' . $facturaExistenteId);
    exit;
}

$config = factura_nueva_config($pdo);
$cfgError = $config ? null : 'Falta configurar la facturacion (config_facturacion).';
$clientes = factura_nueva_clientes($pdo);
$itemLimit = flus_facturacion_print_item_limit();
$itemCountVenta = flus_facturacion_count_items_venta($pdo, $ventaId);
$itemCountExceeded = $itemCountVenta > $itemLimit;
$errores = [];
$clienteSeleccionadoRaw = (string)($_POST['cliente_id'] ?? '');
$clienteLookupUi = [
    'activo' => (string)($_POST['cliente_lookup_activo'] ?? '0'),
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

    $clienteId = null;
    $resolvedCliente = null;
    $clienteLookup = flus_facturacion_cliente_lookup_post($_POST);
    if ($clienteLookup !== null) {
        try {
            $resolvedCliente = flus_facturacion_resolver_cliente_padron($pdo, $clienteLookup);
            $clienteId = (int)($resolvedCliente['cliente_id'] ?? 0);
        } catch (Throwable $e) {
            $errores[] = $e->getMessage();
        }
    } elseif ($clienteSeleccionadoRaw === '') {
        $errores[] = 'Tienes que seleccionar un cliente, Consumidor Final o consultar un CUIT/CUIL.';
    } elseif ($clienteSeleccionadoRaw === '0') {
        $clienteId = 0;
    } elseif (!ctype_digit($clienteSeleccionadoRaw)) {
        $errores[] = 'El cliente seleccionado no es valido.';
    } else {
        $clienteId = (int)$clienteSeleccionadoRaw;
        if (!factura_nueva_cliente_valido($pdo, $clienteId)) {
            $errores[] = 'El cliente seleccionado no es valido.';
        }
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
            $facturaExistenteId = factura_nueva_buscar_existente($pdo, $ventaId);
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
$extraCss = ['assets/css/facturacion.css?v=1'];
$extraJs = ['assets/js/facturacion_cliente_lookup.js'];

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

          <div class="ff-field ff-field-wide fact-lookup-card" data-facturacion-cliente-lookup>
            <label>Consultar por CUIT / CUIL</label>
            <div class="fact-lookup-inline">
              <input type="text" name="cliente_lookup_cuit" value="<?= h($clienteLookupUi['cuit']) ?>" placeholder="20-12345678-9" <?= $cfgError !== null ? 'disabled' : '' ?> data-lookup-cuit>
              <button type="button" class="btn btn-secondary" <?= $cfgError !== null ? 'disabled' : '' ?> data-lookup-btn>Consultar ARCA</button>
            </div>
            <input type="hidden" name="cliente_lookup_activo" value="<?= h($clienteLookupUi['activo']) ?>" data-lookup-activo>
            <input type="hidden" name="cliente_lookup_tipo_cliente" value="<?= h($clienteLookupUi['tipo_cliente']) ?>" data-lookup-tipo-cliente>
            <div class="fact-lookup-result <?= $clienteLookupUi['activo'] === '1' ? 'is-visible' : '' ?>" data-lookup-result>
              <div class="fact-form-grid">
                <div class="ff-field ff-field-wide">
                  <label>Razon social</label>
                  <input type="text" name="cliente_lookup_nombre" value="<?= h($clienteLookupUi['nombre']) ?>" readonly data-lookup-nombre>
                </div>
                <div class="ff-field">
                  <label>Condicion IVA</label>
                  <input type="text" name="cliente_lookup_cond_iva" value="<?= h($clienteLookupUi['cond_iva']) ?>" readonly data-lookup-cond-iva>
                </div>
                <div class="ff-field">
                  <label>Estado padron</label>
                  <input type="text" name="cliente_lookup_estado" value="<?= h($clienteLookupUi['estado']) ?>" readonly data-lookup-estado>
                </div>
                <div class="ff-field ff-field-wide">
                  <label>Domicilio fiscal</label>
                  <input type="text" name="cliente_lookup_direccion" value="<?= h($clienteLookupUi['direccion']) ?>" readonly data-lookup-direccion>
                </div>
              </div>
              <div class="fact-lookup-status muted" data-lookup-status>
                Si estos datos estan cargados, se usaran al emitir esta factura.
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

        <?php if ($errores !== []): ?>
          <div class="msg msg-visible msg-error" style="margin-top:12px;">
            <ul>
              <?php foreach ($errores as $e): ?>
                <li><?= h($e) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </form>
    </section>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>



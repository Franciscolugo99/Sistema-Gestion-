<?php
// public/factura_manual.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/facturacion_lib.php';

require_login();
require_permission('emitir_factura');

if (!flus_facturacion_habilitada($pdo)) {
    header('Location: index.php');
    exit;
}

function factura_manual_config(PDO $pdo): ?array
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

function factura_manual_clientes(PDO $pdo): array
{
    if (!flus_table_exists($pdo, 'clientes')) {
        return [];
    }

    $nombreExpr = flus_column_exists($pdo, 'clientes', 'nombre') ? 'nombre' : 'CONCAT("Cliente #", id)';
    $cuitExpr = flus_column_exists($pdo, 'clientes', 'cuit') ? 'cuit' : 'NULL';
    $where = flus_column_exists($pdo, 'clientes', 'activo') ? 'WHERE activo = 1' : '';

    $sql = "
        SELECT id, {$nombreExpr} AS nombre, {$cuitExpr} AS cuit
        FROM clientes
        {$where}
        ORDER BY nombre ASC
    ";

    $st = $pdo->query($sql);
    return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function factura_manual_default_rows(int $count = 6): array
{
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'codigo' => '',
            'descripcion' => '',
            'cantidad' => $i === 0 ? '1' : '',
            'precio' => '',
            'iva_porcentaje' => '21',
        ];
    }

    return $rows;
}

function factura_manual_rows_from_post(): array
{
    $codigos = (array)($_POST['item_codigo'] ?? []);
    $descripciones = (array)($_POST['item_descripcion'] ?? []);
    $cantidades = (array)($_POST['item_cantidad'] ?? []);
    $precios = (array)($_POST['item_precio'] ?? []);
    $ivas = (array)($_POST['item_iva'] ?? []);
    $count = max(count($codigos), count($descripciones), count($cantidades), count($precios), count($ivas), 6);

    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'codigo' => trim((string)($codigos[$i] ?? '')),
            'descripcion' => trim((string)($descripciones[$i] ?? '')),
            'cantidad' => trim((string)($cantidades[$i] ?? '')),
            'precio' => trim((string)($precios[$i] ?? '')),
            'iva_porcentaje' => trim((string)($ivas[$i] ?? '21')),
        ];
    }

    return $rows;
}

function factura_manual_preview_total(array $rows): float
{
    $total = 0.0;
    foreach ($rows as $row) {
        $descripcion = trim((string)($row['descripcion'] ?? ''));
        if ($descripcion === '') {
            continue;
        }

        $cantidad = (float)($row['cantidad'] ?? 0);
        $precio = (float)($row['precio'] ?? 0);
        if ($cantidad <= 0 || $precio < 0) {
            continue;
        }

        $total += $cantidad * $precio;
    }

    return round($total, 2);
}

function factura_manual_print_item_count(array $rows): int
{
    $count = 0;
    foreach ($rows as $row) {
        if (trim((string)($row['descripcion'] ?? '')) !== '') {
            $count++;
        }
    }
    return $count;
}

$config = factura_manual_config($pdo);
$cfgError = $config ? null : 'Falta configurar la facturacion (config_facturacion).';
$modoConfigLabel = flus_facturacion_modo_label(is_array($config) ? flus_facturacion_modo_actual($config) : 'demo');
$clientes = factura_manual_clientes($pdo);
$rows = $_SERVER['REQUEST_METHOD'] === 'POST' ? factura_manual_rows_from_post() : factura_manual_default_rows();
$itemLimit = flus_facturacion_print_item_limit();
$itemCountPreview = factura_manual_print_item_count($rows);
$itemCountExceeded = $itemCountPreview > $itemLimit;
$errores = [];
$clienteSeleccionadoRaw = (string)($_POST['cliente_id'] ?? '0');
$clienteLookupUi = [
    'activo' => (string)($_POST['cliente_lookup_activo'] ?? '0'),
    'cuit' => trim((string)($_POST['cliente_lookup_cuit'] ?? '')),
    'nombre' => trim((string)($_POST['cliente_lookup_nombre'] ?? '')),
    'cond_iva' => trim((string)($_POST['cliente_lookup_cond_iva'] ?? '')),
    'direccion' => trim((string)($_POST['cliente_lookup_direccion'] ?? '')),
    'tipo_cliente' => trim((string)($_POST['cliente_lookup_tipo_cliente'] ?? 'MINORISTA')),
    'estado' => trim((string)($_POST['cliente_lookup_estado'] ?? '')),
];
$nota = trim((string)($_POST['nota'] ?? 'Factura manual'));
$concepto = (int)($_POST['concepto'] ?? 1);
if (!in_array($concepto, [1, 2, 3], true)) {
    $concepto = 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $cfgError === null) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errores[] = 'Sesion vencida (CSRF). Actualiza la pagina e intenta de nuevo.';
    }

    if ($itemCountExceeded) {
        $errores[] = flus_facturacion_print_item_limit_message($itemCountPreview, $itemLimit);
    }

    $resolvedCliente = null;
    $clienteLookup = flus_facturacion_cliente_lookup_post($_POST);
    if ($clienteLookup !== null) {
        try {
            $resolvedCliente = flus_facturacion_resolver_cliente_padron($pdo, $clienteLookup);
        } catch (Throwable $e) {
            $errores[] = $e->getMessage();
        }
    } else {
        if ($clienteSeleccionadoRaw === '') {
            $errores[] = 'Debes seleccionar un cliente, Consumidor Final o consultar un CUIT/CUIL.';
        } elseif ($clienteSeleccionadoRaw !== '0' && !ctype_digit($clienteSeleccionadoRaw)) {
            $errores[] = 'El cliente seleccionado no es valido.';
        }
    }

    if ($errores === []) {
        try {
            $clienteId = $resolvedCliente !== null
                ? (int)($resolvedCliente['cliente_id'] ?? 0)
                : ($clienteSeleccionadoRaw === '0' ? 0 : (int)$clienteSeleccionadoRaw);

            $opciones = [
                'concepto' => $concepto,
            ];
            if ($resolvedCliente !== null) {
                $opciones['resolved_cliente'] = $resolvedCliente;
            }

            $facturaId = crearFacturaManual([
                'cliente_id' => $clienteId,
                'nota' => $nota,
                'items' => array_map(static function (array $row): array {
                    return [
                        'codigo' => $row['codigo'],
                        'descripcion' => $row['descripcion'],
                        'cantidad' => $row['cantidad'] === '' ? 0 : (float)$row['cantidad'],
                        'precio' => $row['precio'] === '' ? 0 : (float)$row['precio'],
                        'iva_porcentaje' => $row['iva_porcentaje'] === '' ? 21 : (float)$row['iva_porcentaje'],
                    ];
                }, $rows),
                'opciones' => $opciones,
            ]);
            header('Location: factura_ver.php?id=' . $facturaId);
            exit;
        } catch (Throwable $e) {
            $errores[] = $e->getMessage();
        }
    }
}

$totalPreview = factura_manual_preview_total($rows);
$pageTitle = 'Factura manual';
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
        <h1 class="page-title">Factura manual</h1>
        <p class="page-sub">Genera una factura sin partir de una venta de caja. No impacta stock.</p>
      </div>
    </header>

    <?php if ($cfgError !== null): ?>
      <div class="alert alert-error" style="margin-top:12px;"><?= h($cfgError) ?></div>
    <?php endif; ?>

    <?php if ($errores !== []): ?>
      <div class="msg msg-visible msg-error" style="margin:12px 0;">
        <ul>
          <?php foreach ($errores as $error): ?>
            <li><?= h($error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" class="fact-form" data-facturacion-cliente-form="1">
      <?= csrf_field() ?>

      <section class="fact-form-section">
        <h2 class="sub-title-page">Cabecera</h2>
        <div class="fact-form-grid">
          <div class="ff-field ff-field-wide">
            <label>Cliente</label>
            <select name="cliente_id" required <?= $cfgError !== null ? 'disabled' : '' ?> data-lookup-select>
              <option value="0" <?= $clienteSeleccionadoRaw === '0' ? 'selected' : '' ?>>Consumidor Final</option>
              <?php foreach ($clientes as $cli): ?>
                <option value="<?= (int)$cli['id'] ?>" <?= ($clienteSeleccionadoRaw !== '0' && ctype_digit($clienteSeleccionadoRaw) && (int)$clienteSeleccionadoRaw === (int)$cli['id']) ? 'selected' : '' ?>>
                  <?= h((string)($cli['nombre'] ?? 'Cliente')) ?><?= !empty($cli['cuit']) ? ' (' . h((string)$cli['cuit']) . ')' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="muted" style="margin-top:6px;">Tambien puedes consultar ARCA por CUIT/CUIL y usar esos datos al emitir.</div>
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
                Si estos datos estan cargados, tendran prioridad sobre el selector al emitir la factura.
              </div>
            </div>
          </div>

          <div class="ff-field">
            <label>Concepto</label>
            <select name="concepto" <?= $cfgError !== null ? 'disabled' : '' ?>>
              <option value="1" <?= $concepto === 1 ? 'selected' : '' ?>>Productos</option>
              <option value="2" <?= $concepto === 2 ? 'selected' : '' ?>>Servicios</option>
              <option value="3" <?= $concepto === 3 ? 'selected' : '' ?>>Productos y servicios</option>
            </select>
          </div>

          <div class="ff-field ff-field-wide">
            <label>Nota interna</label>
            <input type="text" name="nota" maxlength="255" value="<?= h($nota) ?>" placeholder="Ej: servicio tecnico, venta institucional" <?= $cfgError !== null ? 'disabled' : '' ?>>
          </div>
        </div>
      </section>

      <section class="fact-form-section" style="margin-top:18px;">
        <h2 class="sub-title-page">Items</h2>
        <div class="table-wrapper">
          <table class="mov-table fact-table">
            <thead>
              <tr>
                <th>Codigo</th>
                <th>Descripcion</th>
                <th>Cantidad</th>
                <th>Precio unitario</th>
                <th>IVA</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $idx => $row): ?>
                <tr>
                  <td><input type="text" name="item_codigo[]" value="<?= h((string)$row['codigo']) ?>" placeholder="Opcional" <?= $cfgError !== null ? 'disabled' : '' ?>></td>
                  <td><input type="text" name="item_descripcion[]" value="<?= h((string)$row['descripcion']) ?>" placeholder="Detalle del item" <?= $cfgError !== null ? 'disabled' : '' ?>></td>
                  <td><input type="number" name="item_cantidad[]" min="0.001" step="0.001" value="<?= h((string)$row['cantidad']) ?>" <?= $cfgError !== null ? 'disabled' : '' ?>></td>
                  <td><input type="number" name="item_precio[]" min="0" step="0.01" value="<?= h((string)$row['precio']) ?>" <?= $cfgError !== null ? 'disabled' : '' ?>></td>
                  <td>
                    <select name="item_iva[]" <?= $cfgError !== null ? 'disabled' : '' ?>>
                      <?php foreach ([0, 2.5, 5, 10.5, 21, 27] as $ivaOpt): ?>
                        <option value="<?= $ivaOpt ?>" <?= (string)$row['iva_porcentaje'] === (string)$ivaOpt ? 'selected' : '' ?>><?= $ivaOpt ?>%</option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="muted" style="margin-top:10px;">Puedes dejar filas vacias. Se usaran solo las que tengan descripcion.</p>
      </section>
      <section class="fact-venta-resumen" style="margin-top:18px;">
        <h2 class="sub-title-page">Resumen</h2>
        <div class="fact-venta-grid">
          <div>
            <div class="muted">Total estimado</div>
            <div class="mono"><?= money_ar($totalPreview) ?></div>
          </div>
          <div>
            <div class="muted">Punto de venta</div>
            <div><?= $config ? 'PV ' . str_pad((string)($config['punto_venta'] ?? 1), 4, '0', STR_PAD_LEFT) : '-' ?></div>
          </div>
          <div>
            <div class="muted">Modo</div>
            <div><?= h($modoConfigLabel) ?></div>
          </div>
          <div>
            <div class="muted">Items imprimibles</div>
            <div><?= (int)$itemCountPreview ?> / <?= (int)$itemLimit ?></div>
          </div>
        </div>
        <p class="muted" style="margin-top:10px;">Limite operativo: hasta <?= (int)$itemLimit ?> items por factura de una hoja.</p>
        <?php if ($itemCountExceeded): ?>
          <div class="alert alert-error" style="margin-top:10px;">
            <?= h(flus_facturacion_print_item_limit_message($itemCountPreview, $itemLimit)) ?>
          </div>
        <?php endif; ?>
      </section>

      <div class="pf-actions" style="margin-top:18px;">
        <button type="submit" class="btn btn-primary" <?= ($cfgError !== null || $itemCountExceeded) ? 'disabled' : '' ?>>Emitir factura manual</button>
        <a href="facturacion.php" class="btn btn-secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

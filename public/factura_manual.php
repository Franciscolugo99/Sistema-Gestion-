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

function factura_manual_default_rows(int $count = 3): array
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
    $count = max(count($codigos), count($descripciones), count($cantidades), count($precios), count($ivas), 3);

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

$config = flus_facturacion_config_activa($pdo);
$cfgError = $config ? null : 'Falta configurar la facturacion (config_facturacion).';
$modoConfigLabel = flus_facturacion_modo_label(is_array($config) ? flus_facturacion_modo_actual($config) : 'demo');
$lookupArcaEnv = flus_facturacion_arca_env_actual();
$lookupArcaEnvLabel = $lookupArcaEnv === 'homo' ? 'Homologacion' : 'Produccion';
$clientes = flus_facturacion_clientes_disponibles($pdo);
$rows = $_SERVER['REQUEST_METHOD'] === 'POST' ? factura_manual_rows_from_post() : factura_manual_default_rows();
$itemLimit = flus_facturacion_print_item_limit($pdo);
$itemCountPreview = factura_manual_print_item_count($rows);
$itemCountExceeded = $itemCountPreview > $itemLimit;
$errores = [];
$clienteSeleccionadoRaw = (string)($_POST['cliente_id'] ?? '0');
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
$nota = trim((string)($_POST['nota'] ?? 'Factura manual sin caja'));
$manualRequestUid = trim((string)($_POST['request_uid'] ?? ''));
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

    $clienteResult = flus_facturacion_resolver_cliente_desde_input($pdo, $_POST, [
        'mensaje_vacio' => 'Debes seleccionar un cliente, Consumidor Final o consultar un CUIT/CUIL.',
        'mensaje_invalido' => 'El cliente seleccionado no es valido.',
        'mensaje_lookup_confirmacion' => 'Confirma que quieres emitir con los datos consultados en ARCA. Si no los vas a usar, descartalos y sigue con el cliente seleccionado.',
    ]);
    $resolvedCliente = is_array($clienteResult['resolved_cliente']) ? $clienteResult['resolved_cliente'] : null;
    foreach ((array)($clienteResult['errors'] ?? []) as $errorCliente) {
        $errores[] = (string)$errorCliente;
    }

    if ($errores === []) {
        try {
            $clienteId = (int)($clienteResult['cliente_id'] ?? 0);

            $opciones = [
                'concepto' => $concepto,
            ];
            if ($resolvedCliente !== null) {
                $opciones['resolved_cliente'] = $resolvedCliente;
            }

            $manualItemsPayload = array_map(static function (array $row): array {
                return [
                    'codigo' => $row['codigo'],
                    'descripcion' => $row['descripcion'],
                    'cantidad' => $row['cantidad'] === '' ? 0 : (float)$row['cantidad'],
                    'precio' => $row['precio'] === '' ? 0 : (float)$row['precio'],
                    'iva_porcentaje' => $row['iva_porcentaje'] === '' ? 21 : (float)$row['iva_porcentaje'],
                ];
            }, $rows);

            $manualRequestUid = flus_facturacion_request_uid_manual($clienteId, $manualItemsPayload, [
                'nota' => $nota,
                'medio_pago' => 'FACTURA_MANUAL',
            ], $opciones + ['request_uid' => $manualRequestUid]);
            $opciones['request_uid'] = $manualRequestUid;

            $facturaId = crearFacturaManual([
                'cliente_id' => $clienteId,
                'nota' => $nota,
                'items' => $manualItemsPayload,
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
$itemProgressPct = max(8, min(100, (int)round(($itemCountPreview / max(1, $itemLimit)) * 100)));
$pageTitle = 'Factura manual';
$currentSection = 'facturacion';
$breadcrumbs = [
    ['label' => 'Facturación', 'url' => 'facturacion.php'],
    ['label' => 'Factura manual', 'url' => null],
];
$extraCss = ['assets/css/facturacion.css?v=18'];
$extraJs = ['assets/js/facturacion_cliente_lookup.js?v=7', 'assets/js/factura_manual.js?v=5'];
require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap">
  <div class="panel fact-panel fact-manual-screen">
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

    <form
      method="post"
      class="fact-form fact-manual-form"
      data-facturacion-cliente-form="1"
      data-factura-manual-form="1"
      data-product-search-url="api/index.php?action=buscar_productos"
      data-fm-disabled="<?= $cfgError !== null ? '1' : '0' ?>"
    >
      <?= csrf_field() ?>
      <input type="hidden" name="request_uid" value="<?= h($manualRequestUid) ?>">

      <div class="fact-manual-layout fact-manual-layout--mock">
        <section class="fact-manual-main">
          <article class="fact-card fact-card-receptor fact-card-receptor--mock">
            <div class="fact-card-head">
              <div>
                <div class="fact-card-kicker">Cabecera</div>
                <h3 class="fact-card-title">Receptor</h3>
              </div>
            </div>

            <div class="fact-card-body fact-card-body-cabecera">
              <div class="ff-field fact-cabecera-concepto">
                <label>Concepto</label>
                <select name="concepto" <?= $cfgError !== null ? 'disabled' : '' ?>>
                  <option value="1" <?= $concepto === 1 ? 'selected' : '' ?>>Productos</option>
                  <option value="2" <?= $concepto === 2 ? 'selected' : '' ?>>Servicios</option>
                  <option value="3" <?= $concepto === 3 ? 'selected' : '' ?>>Productos y servicios</option>
                </select>
              </div>

              <div class="fact-receptor-workspace">
                <div class="fact-receptor-empty fact-receptor-empty--inline" data-receptor-empty hidden>
                  <strong>Sin receptor aplicado</strong>
                  <span>Usa el panel derecho para buscar en ARCA o elegir un cliente de FLUS.</span>
                </div>

                <div class="fact-receptor-selected fact-receptor-selected--workspace" data-receptor-selected>
                  <div class="fact-form-grid">
                    <div class="ff-field ff-field-wide">
                      <label>Razon social / nombre</label>
                      <input type="text" value="<?= h($clienteLookupUi['nombre']) ?>" placeholder="Nombre del receptor" data-receptor-mirror-nombre>
                    </div>
                    <div class="ff-field">
                      <label>CUIT / CUIL</label>
                      <input type="text" value="<?= h($clienteLookupUi['cuit']) ?>" placeholder="20-12345678-9" data-receptor-mirror-cuit>
                    </div>
                    <div class="ff-field">
                      <label>Condicion IVA</label>
                      <select data-receptor-mirror-cond-iva>
                        <option value="">Elegir condicion IVA...</option>
                        <?php foreach (['RI' => 'Responsable Inscripto', 'MT' => 'Monotributo', 'EX' => 'Exento', 'CF' => 'Consumidor Final'] as $condKey => $condLabel): ?>
                          <option value="<?= h($condKey) ?>" <?= strtoupper($clienteLookupUi['cond_iva']) === $condKey ? 'selected' : '' ?>><?= h($condLabel) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="ff-field ff-field-wide">
                      <label>Domicilio</label>
                      <input type="text" value="<?= h($clienteLookupUi['direccion']) ?>" placeholder="Domicilio del receptor" data-receptor-mirror-direccion>
                    </div>
                  </div>
                  <div class="fact-receptor-workspace__foot">
                    <div class="fact-receptor-applied-note muted" data-receptor-applied-note>
                      El receptor se completa desde FLUS o ARCA y luego puedes ajustarlo antes de emitir.
                    </div>
                    <button type="button" class="btn btn-secondary" data-receptor-clear hidden>Limpiar</button>
                  </div>
                </div>
              </div>
            </div>
          </article>

          <article class="fact-card fact-card-items-shell">
            <div class="fact-card-head">
              <div>
                <div class="fact-card-kicker">Items</div>
                <h3 class="fact-card-title">Detalle de factura</h3>
              </div>
              <div class="fact-card-head-right">
                <span class="fact-head-meta"><span data-fm-count><?= (int)$itemCountPreview ?></span> items</span>
              </div>
            </div>

            <div class="fact-card-body fact-card-body-items">
              <div class="fact-manual-toolbar">
                <div class="fact-manual-search">
                  <label for="factManualBuscar">Buscar producto del sistema</label>
                  <div class="fact-manual-search-inline">
                    <input
                      type="search"
                      id="factManualBuscar"
                      placeholder="Escribe nombre o codigo para agregar rapido"
                      autocomplete="off"
                      <?= $cfgError !== null ? 'disabled' : '' ?>
                      data-fm-product-search
                    >
                    <button type="button" class="btn btn-secondary" <?= $cfgError !== null ? 'disabled' : '' ?> data-fm-add-row>+ Agregar fila</button>
                  </div>
                  <div class="fact-manual-suggestions" data-fm-search-results hidden></div>
                </div>

                <div class="fact-manual-toolbar-sep">o</div>

                <div class="fact-manual-code">
                  <label for="factManualCodigo">Cargar por codigo</label>
                  <div class="fact-manual-search-inline">
                    <input
                      type="text"
                      id="factManualCodigo"
                      placeholder="Escanea o escribe el codigo y presiona Enter"
                      autocomplete="off"
                      <?= $cfgError !== null ? 'disabled' : '' ?>
                      data-fm-code-search
                    >
                    <button type="button" class="btn btn-secondary" <?= $cfgError !== null ? 'disabled' : '' ?> data-fm-code-btn>Agregar</button>
                  </div>
                </div>
              </div>

              <div class="fact-manual-status muted" data-fm-status></div>

              <div class="table-wrapper fact-items-card">
                <table class="mov-table fact-table fact-manual-table">
                  <thead>
                    <tr>
                      <th>Codigo</th>
                      <th>Descripcion</th>
                      <th>Cantidad</th>
                      <th>Precio unit.</th>
                      <th>IVA</th>
                      <th>Subtotal</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody data-fm-items-body>
                    <?php foreach ($rows as $row): ?>
                      <tr class="fact-manual-row" data-fm-row>
                        <td><input type="text" name="item_codigo[]" value="<?= h((string)$row['codigo']) ?>" placeholder="Codigo" <?= $cfgError !== null ? 'disabled' : '' ?> data-fm-code></td>
                        <td><input type="text" name="item_descripcion[]" value="<?= h((string)$row['descripcion']) ?>" placeholder="Detalle del item" <?= $cfgError !== null ? 'disabled' : '' ?> data-fm-description></td>
                        <td><input type="number" name="item_cantidad[]" min="0.001" step="0.001" value="<?= h((string)$row['cantidad']) ?>" <?= $cfgError !== null ? 'disabled' : '' ?> data-fm-qty></td>
                        <td><input type="number" name="item_precio[]" min="0" step="0.01" value="<?= h((string)$row['precio']) ?>" <?= $cfgError !== null ? 'disabled' : '' ?> data-fm-price></td>
                        <td>
                          <select name="item_iva[]" <?= $cfgError !== null ? 'disabled' : '' ?> data-fm-iva>
                            <?php foreach ([0, 2.5, 5, 10.5, 21, 27] as $ivaOpt): ?>
                              <option value="<?= $ivaOpt ?>" <?= (string)$row['iva_porcentaje'] === (string)$ivaOpt ? 'selected' : '' ?>><?= $ivaOpt ?> %</option>
                            <?php endforeach; ?>
                          </select>
                        </td>
                        <td class="t-right"><div class="fact-manual-subtotal" data-fm-subtotal><?= money_ar(((float)($row['cantidad'] ?: 0)) * ((float)($row['precio'] ?: 0))) ?></div></td>
                        <td class="fact-manual-row-actions"><button type="button" class="btn btn-ghost btn-compact" <?= $cfgError !== null ? 'disabled' : '' ?> data-fm-remove-row data-fm-remove-mode="icon" aria-label="Quitar fila">&times;</button></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <div class="fact-items-footer">
                <p class="muted fact-manual-help">Puedes dejar filas vacias. Solo se usan las que tengan descripcion.</p>
                <div class="fact-items-progress">
                  <div class="fact-items-progress-bar"><span style="width: <?= $itemProgressPct ?>%;"></span></div>
                  <div class="fact-items-counter"><span data-fm-count><?= (int)$itemCountPreview ?></span> / <?= (int)$itemLimit ?></div>
                </div>
              </div>
            </div>
          </article>
        </section>

        <aside class="fact-manual-sidebar">
          <article class="fact-card fact-card-source" data-facturacion-cliente-lookup data-lookup-env="<?= h($lookupArcaEnv) ?>">
            <div class="fact-card-head fact-card-head--compact">
              <div>
                <div class="fact-card-kicker">ARCA - <?= h($lookupArcaEnvLabel) ?></div>
                <h3 class="fact-card-title">Buscar receptor</h3>
              </div>
            </div>
            <div class="fact-card-body fact-card-body-source">
              <div class="fact-receptor-mode fact-receptor-mode--sidebar" data-receptor-mode>
                <button type="button" class="fact-receptor-mode__btn" data-receptor-mode-btn="selector">Cliente de FLUS</button>
                <button type="button" class="fact-receptor-mode__btn" data-receptor-mode-btn="lookup">Buscar en ARCA</button>
              </div>

              <div data-receptor-panel="selector">
                <div class="ff-field">
                  <label>Cliente</label>
                  <select name="cliente_id" required <?= $cfgError !== null ? 'disabled' : '' ?> data-lookup-select>
                    <option value="0" <?= $clienteSeleccionadoRaw === '0' ? 'selected' : '' ?>>Consumidor Final</option>
                    <?php foreach ($clientes as $cli): ?>
                      <option
                        value="<?= (int)$cli['id'] ?>"
                        data-cliente-nombre="<?= h((string)($cli['nombre'] ?? 'Cliente')) ?>"
                        data-cliente-cuit="<?= h((string)($cli['cuit'] ?? '')) ?>"
                        data-cliente-cond-iva="<?= h((string)($cli['cond_iva'] ?? '')) ?>"
                        <?= ($clienteSeleccionadoRaw !== '0' && ctype_digit($clienteSeleccionadoRaw) && (int)$clienteSeleccionadoRaw === (int)$cli['id']) ? 'selected' : '' ?>
                      >
                        <?= h((string)($cli['nombre'] ?? 'Cliente')) ?><?= !empty($cli['cuit']) ? ' (' . h((string)$cli['cuit']) . ')' : '' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="fact-field-help muted">Usa un cliente ya cargado en FLUS para completar el receptor.</div>
              </div>

              <div data-receptor-panel="lookup">
                <div class="fact-lookup-inline fact-lookup-inline--sidebar">
                  <input type="text" name="cliente_lookup_cuit" value="<?= h($clienteLookupUi['cuit']) ?>" placeholder="20-12345678-9" <?= $cfgError !== null ? 'disabled' : '' ?> data-lookup-cuit>
                  <button type="button" class="btn btn-primary" <?= $cfgError !== null ? 'disabled' : '' ?> data-lookup-btn>Consultar</button>
                </div>
                <div class="fact-field-help muted">Consulta el padron de ARCA en <?= strtolower(h($lookupArcaEnvLabel)) ?> y luego aplica esos datos al receptor.</div>
                <?php if ($lookupArcaEnv === 'homo'): ?>
                  <div class="fact-field-help is-warning">En homologacion, ARCA puede devolver contribuyentes de prueba que no coinciden con produccion.</div>
                <?php endif; ?>

                <input type="hidden" name="cliente_lookup_activo" value="<?= h($clienteLookupUi['activo']) ?>" data-lookup-activo>
                <input type="hidden" name="cliente_lookup_tipo_cliente" value="<?= h($clienteLookupUi['tipo_cliente']) ?>" data-lookup-tipo-cliente>
                <input type="hidden" name="cliente_lookup_editor" value="<?= h($clienteLookupUi['editor']) ?>" data-lookup-editor-state>
                <input type="hidden" name="cliente_lookup_estado" value="<?= h($clienteLookupUi['estado']) ?>" data-lookup-estado>
                <input type="hidden" name="cliente_lookup_nombre" value="<?= h($clienteLookupUi['nombre']) ?>" data-lookup-nombre>
                <input type="hidden" name="cliente_lookup_cond_iva" value="<?= h($clienteLookupUi['cond_iva']) ?>" data-lookup-cond-iva>
                <input type="hidden" name="cliente_lookup_direccion" value="<?= h($clienteLookupUi['direccion']) ?>" data-lookup-direccion>
                <input type="checkbox" name="cliente_lookup_confirmado" value="1" <?= $clienteLookupUi['confirmado'] === '1' ? 'checked' : '' ?> data-lookup-confirm hidden>

                <div class="fact-lookup-result <?= $clienteLookupUi['activo'] === '1' ? 'is-visible' : '' ?>" data-lookup-result>
                  <div class="fact-lookup-summary">
                    <div class="fact-lookup-name" data-lookup-display-nombre>
                      <?= h($clienteLookupUi['nombre'] !== '' ? $clienteLookupUi['nombre'] : 'Completa los datos del receptor') ?>
                    </div>
                    <div class="fact-lookup-cuit" data-lookup-display-cuit><?= h($clienteLookupUi['cuit']) ?></div>
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

                  <div class="fact-lookup-actions">
                    <button type="button" class="btn btn-primary btn-lookup-apply" data-lookup-apply>Usar estos datos como receptor</button>
                    <button type="button" class="btn btn-ghost" data-lookup-clear>Limpiar</button>
                  </div>
                  <div class="fact-lookup-status muted" data-lookup-status>
                    Aplica los datos para cargarlos en el receptor y luego puedes editarlos.
                  </div>
                </div>
              </div>
            </div>
          </article>

          <article class="fact-card fact-card-summary">
            <div class="fact-card-head fact-card-head--compact">
              <div>
                <div class="fact-card-kicker">Resumen</div>
                <h3 class="fact-card-title">Total estimado</h3>
              </div>
            </div>
            <div class="fact-summary-total" data-fm-total><?= money_ar($totalPreview) ?></div>
            <div class="fact-summary-grid">
              <div class="fact-summary-item">
                <span class="fact-summary-label">Items imprimibles</span>
                <strong><span data-fm-count><?= (int)$itemCountPreview ?></span> / <?= (int)$itemLimit ?></strong>
              </div>
              <div class="fact-summary-item">
                <span class="fact-summary-label">Punto de venta</span>
                <strong><?= $config ? 'PV ' . str_pad((string)($config['punto_venta'] ?? 1), 4, '0', STR_PAD_LEFT) : '-' ?></strong>
              </div>
              <div class="fact-summary-item">
                <span class="fact-summary-label">Modo</span>
                <strong><?= h($modoConfigLabel) ?></strong>
              </div>
              <div class="fact-summary-item">
                <span class="fact-summary-label">Fecha</span>
                <strong><?= h(date('d/m/Y')) ?></strong>
              </div>
              <div class="fact-summary-item">
                <span class="fact-summary-label">Receptor</span>
                <strong data-receptor-summary><?= $clienteSeleccionadoRaw === '0' ? 'Consumidor Final' : 'Cliente identificado' ?></strong>
              </div>
            </div>
            <p class="muted fact-summary-help">Modo <?= strtolower($modoConfigLabel) ?>. Limite operativo de <?= (int)$itemLimit ?> items por hoja.</p>
            <?php if ($itemCountExceeded): ?>
              <div class="alert alert-error" style="margin-top:10px;">
                <?= h(flus_facturacion_print_item_limit_message($itemCountPreview, $itemLimit)) ?>
              </div>
            <?php endif; ?>
          </article>

          <article class="fact-card fact-card-note-side">
            <div class="fact-card-head fact-card-head--compact">
              <div>
                <div class="fact-card-kicker">Interno</div>
                <h3 class="fact-card-title">Nota interna</h3>
              </div>
            </div>
            <div class="ff-field">
              <label>No aparece en la factura</label>
              <textarea name="nota" maxlength="255" rows="4" placeholder="Ej: venta institucional" <?= $cfgError !== null ? 'disabled' : '' ?>><?= h($nota) ?></textarea>
            </div>
          </article>

          <article class="fact-card fact-card-actions">
            <div class="pf-actions fact-summary-actions">
              <button type="submit" class="btn btn-primary" <?= ($cfgError !== null || $itemCountExceeded) ? 'disabled' : '' ?>>Emitir factura manual</button>
              <a href="facturacion.php" class="btn btn-secondary">Cancelar</a>
            </div>
          </article>
        </aside>
      </div>
    </form>
  </div>
</div>

<template id="facturaManualRowTemplate">
  <tr class="fact-manual-row" data-fm-row>
    <td><input type="text" name="item_codigo[]" value="" placeholder="Codigo" data-fm-code></td>
    <td><input type="text" name="item_descripcion[]" value="" placeholder="Detalle del item" data-fm-description></td>
    <td><input type="number" name="item_cantidad[]" min="0.001" step="0.001" value="1" data-fm-qty></td>
    <td><input type="number" name="item_precio[]" min="0" step="0.01" value="" data-fm-price></td>
    <td>
      <select name="item_iva[]" data-fm-iva>
        <?php foreach ([0, 2.5, 5, 10.5, 21, 27] as $ivaOpt): ?>
          <option value="<?= $ivaOpt ?>" <?= (float)$ivaOpt === 21.0 ? 'selected' : '' ?>><?= $ivaOpt ?> %</option>
        <?php endforeach; ?>
      </select>
    </td>
    <td class="t-right"><div class="fact-manual-subtotal" data-fm-subtotal><?= money_ar(0) ?></div></td>
    <td class="fact-manual-row-actions"><button type="button" class="btn btn-ghost btn-compact" data-fm-remove-row data-fm-remove-mode="icon" aria-label="Quitar fila">&times;</button></td>
  </tr>
</template>

<?php require __DIR__ . '/partials/footer.php'; ?>

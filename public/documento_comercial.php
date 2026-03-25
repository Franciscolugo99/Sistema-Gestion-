<?php
// public/documento_comercial.php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/facturacion_lib.php';

require_login();
require_any_permission(['ver_facturacion', 'emitir_factura']);

function documento_comercial_default_rows(int $count = 5): array
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

function documento_comercial_rows_from_post(): array
{
    $codigos = (array)($_POST['item_codigo'] ?? []);
    $descripciones = (array)($_POST['item_descripcion'] ?? []);
    $cantidades = (array)($_POST['item_cantidad'] ?? []);
    $precios = (array)($_POST['item_precio'] ?? []);
    $ivas = (array)($_POST['item_iva'] ?? []);
    $count = max(count($codigos), count($descripciones), count($cantidades), count($precios), count($ivas), 5);

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

function documento_comercial_preview_total(array $rows): float
{
    $total = 0.0;
    foreach ($rows as $row) {
        if (trim((string)($row['descripcion'] ?? '')) === '') {
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

function documento_comercial_venta_existe(PDO $pdo, int $ventaId): bool
{
    if ($ventaId <= 0 || !flus_table_exists($pdo, 'ventas')) {
        return false;
    }
    $st = $pdo->prepare('SELECT * FROM ventas WHERE id = ? LIMIT 1');
    $st->execute([$ventaId]);
    return (bool)($st->fetch(PDO::FETCH_ASSOC) ?: null);
}

function documento_comercial_cliente_nombre(array $clientes, int $clienteId): string
{
    foreach ($clientes as $cliente) {
        if ((int)($cliente['id'] ?? 0) === $clienteId) {
            return trim((string)($cliente['nombre'] ?? '')) ?: 'Cliente #' . $clienteId;
        }
    }

    return $clienteId > 0 ? 'Cliente #' . $clienteId : 'Sin cliente';
}

$canEmitir = function_exists('user_has_permission') && user_has_permission('emitir_factura');
$facturacionHabilitada = flus_facturacion_habilitada($pdo);
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$tipoSolicitado = strtoupper(trim((string)($_GET['tipo'] ?? $_POST['tipo_documento'] ?? 'PRESUPUESTO')));
if (!in_array($tipoSolicitado, ['PRESUPUESTO', 'REMITO'], true)) {
    $tipoSolicitado = 'PRESUPUESTO';
}

$clientes = flus_facturacion_clientes_disponibles($pdo);
$errores = [];
$rows = $_SERVER['REQUEST_METHOD'] === 'POST' ? documento_comercial_rows_from_post() : documento_comercial_default_rows();
$nota = trim((string)($_POST['nota'] ?? ''));
$clienteIdForm = (int)($_POST['cliente_id'] ?? 0);
$requestUid = trim((string)($_POST['request_uid'] ?? ''));
$ventaIdLink = (int)($_POST['venta_id_link'] ?? 0);
$documento = $id > 0 ? flus_facturacion_documento_buscar($pdo, $id) : null;

if ($id > 0 && !is_array($documento)) {
    http_response_code(404);
    flus_abort(404, 'Documento comercial no encontrado');
}

if ($documento && $nota === '') {
    $nota = trim((string)($documento['nota'] ?? ''));
}
if ($documento && $clienteIdForm <= 0) {
    $clienteIdForm = (int)($documento['cliente_id'] ?? 0);
}
if ($requestUid === '') {
    $requestUid = flus_facturacion_uuid_v4();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEmitir) {
        $errores[] = 'No tienes permisos para modificar documentos comerciales.';
    } elseif (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $errores[] = 'Sesion vencida (CSRF). Actualiza la pagina e intenta de nuevo.';
    } elseif (!flus_facturacion_documentos_table_ready($pdo)) {
        $errores[] = 'Faltan las tablas documentales. Aplica primero la migracion 017.';
    } else {
        $action = trim((string)($_POST['action'] ?? 'guardar_documento'));

        try {
            if ($action === 'guardar_documento') {
                $items = flus_facturacion_normalize_manual_items(array_map(static function (array $row): array {
                    return [
                        'codigo' => $row['codigo'],
                        'descripcion' => $row['descripcion'],
                        'cantidad' => $row['cantidad'] === '' ? 0 : (float)$row['cantidad'],
                        'precio' => $row['precio'] === '' ? 0 : (float)$row['precio'],
                        'iva_porcentaje' => $row['iva_porcentaje'] === '' ? 21 : (float)$row['iva_porcentaje'],
                    ];
                }, $rows));

                $nuevoId = flus_facturacion_documento_crear($pdo, $tipoSolicitado, $clienteIdForm, $items, [
                    'nota' => $nota !== '' ? $nota : ($tipoSolicitado === 'PRESUPUESTO' ? 'Presupuesto generado en FLUS' : 'Remito generado en FLUS'),
                    'medio_pago' => $tipoSolicitado,
                ], [
                    'request_uid' => $requestUid,
                    'origen' => 'MANUAL',
                ]);

                header('Location: documento_comercial.php?id=' . $nuevoId);
                exit;
            }

            if (!$documento) {
                throw new RuntimeException('Documento comercial no encontrado.');
            }

            if ($action === 'actualizar_documento_base') {
                flus_facturacion_documento_actualizar_cabecera($pdo, (int)$documento['id'], [
                    'cliente_id' => (int)($_POST['cliente_id_edit'] ?? $clienteIdForm),
                    'nota' => trim((string)($_POST['nota_edit'] ?? $nota)),
                ]);
                header('Location: documento_comercial.php?id=' . (int)$documento['id'] . '&ok=actualizado');
                exit;
            }

            $accionesDocumento = flus_facturacion_documento_acciones($pdo, (int)$documento['id']);

            if ($action === 'crear_remito') {
                if (!$accionesDocumento['puede_generar_remito']) {
                    throw new RuntimeException((string)($accionesDocumento['motivo_generar_remito'] ?? 'No se puede generar remito para este documento.'));
                }
                $remitoId = flus_facturacion_documento_clonar($pdo, (int)$documento['id'], 'REMITO', [], [
                    'reusar_existente' => false,
                ]);
                header('Location: documento_comercial.php?id=' . $remitoId);
                exit;
            }

            if ($action === 'crear_venta') {
                if (!$accionesDocumento['puede_generar_venta']) {
                    throw new RuntimeException((string)($accionesDocumento['motivo_generar_venta'] ?? 'No se puede generar una venta para este documento.'));
                }
                $ventaId = flus_facturacion_documento_convertir_a_venta_manual($pdo, (int)$documento['id']);
                header('Location: documento_comercial.php?id=' . (int)$documento['id'] . '&ok=venta&venta_id=' . $ventaId);
                exit;
            }

            if ($action === 'vincular_venta') {
                if (!$accionesDocumento['puede_vincular_venta']) {
                    throw new RuntimeException((string)($accionesDocumento['motivo_vincular_venta'] ?? 'No se puede vincular una venta a este documento.'));
                }
                if (!documento_comercial_venta_existe($pdo, $ventaIdLink)) {
                    throw new RuntimeException('La venta indicada no existe.');
                }
                flus_facturacion_documento_vincular_venta($pdo, (int)$documento['id'], $ventaIdLink);
                header('Location: documento_comercial.php?id=' . (int)$documento['id'] . '&ok=vinculo');
                exit;
            }

            if ($action === 'emitir_factura') {
                if (!$accionesDocumento['puede_emitir_factura']) {
                    throw new RuntimeException((string)($accionesDocumento['motivo_emitir_factura'] ?? 'No se puede emitir factura para este documento.'));
                }
                if (!$facturacionHabilitada) {
                    throw new RuntimeException('La facturacion fiscal esta desactivada en esta instalacion.');
                }
                $clienteIdFactura = (int)($documento['cliente_id'] ?? 0);
                if ($clienteIdFactura <= 0) {
                    throw new RuntimeException('Vincula un cliente al documento antes de facturarlo.');
                }
                $facturaId = emitirFacturaDesdeDocumento((int)$documento['id'], $clienteIdFactura, []);
                header('Location: factura_ver.php?id=' . $facturaId);
                exit;
            }
        } catch (Throwable $e) {
            $errores[] = $e->getMessage();
        }
    }
}

$documento = $id > 0 ? flus_facturacion_documento_buscar($pdo, $id) : $documento;
$relaciones = $documento ? flus_facturacion_documento_relaciones($pdo, (int)$documento['id']) : ['origen' => null, 'hijos' => [], 'factura' => null];
$documentoItems = $documento ? flus_facturacion_documento_items_fetch($pdo, (int)$documento['id']) : [];
$accionesDocumento = $documento ? flus_facturacion_documento_acciones($pdo, (int)$documento['id']) : null;

if ($documento && $documentoItems !== [] && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $rows = array_map(static function (array $item): array {
        return [
            'codigo' => (string)($item['codigo'] ?? ''),
            'descripcion' => (string)($item['nombre'] ?? ''),
            'cantidad' => (string)($item['cantidad'] ?? ''),
            'precio' => (string)($item['precio'] ?? ''),
            'iva_porcentaje' => (string)($item['iva_porcentaje'] ?? '21'),
        ];
    }, $documentoItems);
}

$pageTitle = $documento
    ? 'Documento ' . (string)($documento['tipo_documento'] ?? 'comercial') . ' #' . (int)$documento['id']
    : ($tipoSolicitado === 'REMITO' ? 'Nuevo remito' : 'Nuevo presupuesto');
$currentSection = 'facturacion';
$breadcrumbs = [
    ['label' => 'Facturación', 'url' => 'facturacion.php'],
    ['label' => 'Documentos comerciales', 'url' => 'documentos_comerciales.php'],
    ['label' => $documento ? ((string)($documento['tipo_documento'] ?? 'Documento') . ' #' . (int)$documento['id']) : ($tipoSolicitado === 'REMITO' ? 'Nuevo remito' : 'Nuevo presupuesto'), 'url' => null],
];
$extraCss = ['assets/css/facturacion.css?v=18'];
require __DIR__ . '/partials/header.php';

$previewTotal = $documento ? (float)($documento['total'] ?? 0) : documento_comercial_preview_total($rows);
$tipoActual = $documento ? flus_facturacion_documento_tipo_normalizar((string)($documento['tipo_documento'] ?? '')) : $tipoSolicitado;
$facturaRelacionada = is_array($relaciones['factura'] ?? null) ? $relaciones['factura'] : null;
$documentoOrigen = is_array($relaciones['origen'] ?? null) ? $relaciones['origen'] : null;
$documentoHijos = is_array($relaciones['hijos'] ?? null) ? $relaciones['hijos'] : [];
$ok = trim((string)($_GET['ok'] ?? ''));
$clienteActualNombre = $documento ? documento_comercial_cliente_nombre($clientes, (int)($documento['cliente_id'] ?? 0)) : '';
$remitoRelacionado = is_array($accionesDocumento['remito'] ?? null) ? $accionesDocumento['remito'] : null;
$ventaRelacionada = is_array($accionesDocumento['venta'] ?? null) ? $accionesDocumento['venta'] : null;
$siguienteAccionLabel = (string)($accionesDocumento['siguiente_accion_label'] ?? 'Sin acción disponible');
$impactoOperativo = (string)($accionesDocumento['impacto_operativo'] ?? 'El documento por sí solo no impacta stock ni caja.');
?>

<div class="page-wrap facturacion-page">
  <div class="panel fact-panel fact-manual-screen">
    <header class="page-header with-back">
      <div class="page-header-left">
        <a href="documentos_comerciales.php" class="link-back">&larr; Volver a documentos comerciales</a>
        <h1 class="page-title"><?= h($documento ? ((string)($documento['tipo_documento'] ?? 'Documento') . ' #' . (int)$documento['id']) : ($tipoActual === 'REMITO' ? 'Nuevo remito' : 'Nuevo presupuesto')) ?></h1>
        <p class="page-sub">
          <?php if ($documento): ?>
            El documento sirve como trazabilidad comercial. La operación real solo ocurre al vincular una venta o emitir factura cuando corresponda.
          <?php else: ?>
            Puedes guardarlo sin cliente como borrador documental. Para generar remito, venta o factura después, primero deberá tener cliente válido.
          <?php endif; ?>
        </p>
      </div>
    </header>

    <?php if ($ok === 'venta' && !empty($_GET['venta_id'])): ?>
      <div class="alert alert-success" style="margin-bottom:12px;">Se generó la venta #<?= (int)$_GET['venta_id'] ?> y quedó vinculada al documento. El impacto operativo quedó en esa venta.</div>
    <?php elseif ($ok === 'vinculo'): ?>
      <div class="alert alert-success" style="margin-bottom:12px;">La venta quedó vinculada al documento.</div>
    <?php elseif ($ok === 'actualizado'): ?>
      <div class="alert alert-success" style="margin-bottom:12px;">La cabecera del documento se actualizó correctamente.</div>
    <?php endif; ?>

    <?php foreach ($errores as $error): ?>
      <div class="alert alert-error" style="margin-bottom:12px;"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php if ($documento && is_array($accionesDocumento)): ?>
      <div class="alert <?= !empty($accionesDocumento['tiene_cliente']) ? 'alert-success' : 'alert-error' ?>" style="margin-bottom:12px;">
        <strong><?= h($siguienteAccionLabel) ?>.</strong>
        <?= h($impactoOperativo) ?>
        <?php if (empty($accionesDocumento['tiene_cliente'])): ?>
          Agrega un cliente para habilitar remito, venta, factura o vínculo con venta existente.
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (!$documento): ?>
      <form method="post" class="fact-form fact-manual-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="guardar_documento">
        <input type="hidden" name="tipo_documento" value="<?= h($tipoActual) ?>">
        <input type="hidden" name="request_uid" value="<?= h($requestUid) ?>">

        <div class="fact-manual-layout fact-manual-layout--mock">
          <section class="fact-manual-main">
            <article class="fact-card fact-card-receptor fact-card-receptor--mock">
              <div class="fact-card-head">
                <div>
                  <div class="fact-card-kicker">Cabecera</div>
                  <h3 class="fact-card-title">Datos del documento</h3>
                </div>
              </div>
              <div class="fact-card-body fact-card-body-cabecera">
                <div class="fact-form-grid">
                  <div class="ff-field">
                    <label>Tipo documental</label>
                    <input type="text" value="<?= h($tipoActual) ?>" readonly>
                  </div>
                  <div class="ff-field">
                    <label>Cliente</label>
                    <select name="cliente_id">
                      <option value="0">Sin cliente / consumidor final</option>
                      <?php foreach ($clientes as $cli): ?>
                        <option value="<?= (int)$cli['id'] ?>" <?= $clienteIdForm === (int)$cli['id'] ? 'selected' : '' ?>><?= h((string)($cli['nombre'] ?? 'Cliente')) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="ff-field ff-field-wide">
                    <label>Nota</label>
                    <input type="text" name="nota" value="<?= h($nota) ?>" placeholder="Observación interna del documento">
                  </div>
                </div>
              </div>
            </article>

            <article class="fact-card">
              <div class="fact-card-head">
                <div>
                  <div class="fact-card-kicker">Items</div>
                  <h3 class="fact-card-title">Detalle</h3>
                </div>
              </div>
              <div class="table-wrapper">
                <table class="mov-table fact-table">
                  <thead>
                    <tr>
                      <th>Código</th>
                      <th>Descripción</th>
                      <th>Cant.</th>
                      <th>Precio</th>
                      <th>IVA</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($rows as $idx => $row): ?>
                    <tr>
                      <td><input type="text" name="item_codigo[]" value="<?= h((string)$row['codigo']) ?>"></td>
                      <td><input type="text" name="item_descripcion[]" value="<?= h((string)$row['descripcion']) ?>"></td>
                      <td><input type="number" step="0.001" min="0" name="item_cantidad[]" value="<?= h((string)$row['cantidad']) ?>"></td>
                      <td><input type="number" step="0.01" min="0" name="item_precio[]" value="<?= h((string)$row['precio']) ?>"></td>
                      <td>
                        <select name="item_iva[]">
                          <?php foreach ([0, 2.5, 5, 10.5, 21, 27] as $iva): ?>
                            <option value="<?= h((string)$iva) ?>" <?= (string)$row['iva_porcentaje'] === (string)$iva ? 'selected' : '' ?>><?= h((string)$iva) ?>%</option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </article>
          </section>

          <aside class="fact-manual-sidebar">
            <article class="fact-sidebar-card">
              <h3>Resumen</h3>
              <div class="fact-sidebar-card__metric">
                <span>Total estimado</span>
                <strong><?= money_ar($previewTotal) ?></strong>
              </div>
              <p class="fact-sidebar-card__text">Si lo guardas sin cliente quedará como borrador. Para pasar a operación real, luego deberás asignarle un cliente.</p>
            </article>

            <div class="fact-actions fact-actions--sidebar">
              <button type="submit" class="btn btn-primary"><?= $tipoActual === 'REMITO' ? 'Guardar remito' : 'Guardar presupuesto' ?></button>
              <a href="documentos_comerciales.php" class="btn btn-secondary">Cancelar</a>
            </div>
          </aside>
        </div>
      </form>
    <?php else: ?>
      <section class="fact-kpi-grid" aria-label="Resumen documental">
        <article class="fact-kpi-card">
          <span class="fact-kpi-card__label">Tipo</span>
          <strong class="fact-kpi-card__value"><?= h($tipoActual) ?></strong>
          <span class="fact-kpi-card__help">ID #<?= (int)$documento['id'] ?></span>
        </article>
        <article class="fact-kpi-card">
          <span class="fact-kpi-card__label">Estado</span>
          <strong class="fact-kpi-card__value"><?= h((string)($documento['estado'] ?? 'PENDIENTE')) ?></strong>
          <span class="fact-kpi-card__help">Origen <?= h((string)($documento['origen'] ?? 'MANUAL')) ?></span>
        </article>
        <article class="fact-kpi-card">
          <span class="fact-kpi-card__label">Cliente operativo</span>
          <strong class="fact-kpi-card__value"><?= !empty($accionesDocumento['tiene_cliente']) ? h($clienteActualNombre) : 'BORRADOR' ?></strong>
          <span class="fact-kpi-card__help"><?= !empty($accionesDocumento['tiene_cliente']) ? 'Listo para operar' : 'Sin cliente: solo documental' ?></span>
        </article>
        <article class="fact-kpi-card">
          <span class="fact-kpi-card__label">Siguiente acción</span>
          <strong class="fact-kpi-card__value"><?= h($siguienteAccionLabel) ?></strong>
          <span class="fact-kpi-card__help"><?= h($impactoOperativo) ?></span>
        </article>
      </section>

      <div class="fact-manual-layout fact-manual-layout--mock">
        <section class="fact-manual-main">
          <article class="fact-card fact-card-receptor fact-card-receptor--mock">
            <div class="fact-card-head">
              <div>
                <div class="fact-card-kicker">Cabecera</div>
                <h3 class="fact-card-title">Documento</h3>
              </div>
            </div>
            <div class="fact-card-body fact-card-body-cabecera">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$documento['id'] ?>">
                <input type="hidden" name="action" value="actualizar_documento_base">
                <div class="fact-form-grid">
                  <div class="ff-field">
                    <label>Cliente</label>
                    <select name="cliente_id_edit">
                      <option value="0">Sin cliente / borrador</option>
                      <?php foreach ($clientes as $cli): ?>
                        <option value="<?= (int)$cli['id'] ?>" <?= (int)($documento['cliente_id'] ?? 0) === (int)$cli['id'] ? 'selected' : '' ?>><?= h((string)($cli['nombre'] ?? 'Cliente')) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="ff-field"><label>Creado</label><input type="text" readonly value="<?= h((string)($documento['created_at'] ?? '')) ?>"></div>
                  <div class="ff-field ff-field-wide"><label>Nota</label><input type="text" name="nota_edit" value="<?= h((string)($documento['nota'] ?? '')) ?>"></div>
                </div>
                <div style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                  <button type="submit" class="btn btn-secondary">Guardar cabecera</button>
                  <span class="fact-cell-sub">Usa este bloque para sacar el documento de borrador sin rehacerlo.</span>
                </div>
              </form>
            </div>
          </article>

          <article class="fact-card">
            <div class="fact-card-head">
              <div>
                <div class="fact-card-kicker">Items</div>
                <h3 class="fact-card-title">Detalle documental</h3>
              </div>
            </div>
            <div class="table-wrapper">
              <table class="mov-table fact-table">
                <thead>
                  <tr>
                    <th>Código</th>
                    <th>Descripción</th>
                    <th class="t-right">Cant.</th>
                    <th class="t-right">Precio</th>
                    <th class="t-right">IVA</th>
                    <th class="t-right">Subtotal</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($documentoItems as $item): ?>
                  <tr>
                    <td><?= h((string)($item['codigo'] ?? '')) ?></td>
                    <td><?= h((string)($item['nombre'] ?? '')) ?></td>
                    <td class="t-right"><?= h((string)($item['cantidad'] ?? '')) ?></td>
                    <td class="t-right"><?= money_ar((float)($item['precio'] ?? 0)) ?></td>
                    <td class="t-right"><?= h((string)($item['iva_porcentaje'] ?? '21')) ?>%</td>
                    <td class="t-right"><?= money_ar((float)($item['subtotal'] ?? 0)) ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </article>

          <article class="fact-card">
            <div class="fact-card-head">
              <div>
                <div class="fact-card-kicker">Trazabilidad</div>
                <h3 class="fact-card-title">Relaciones del documento</h3>
              </div>
            </div>
            <div class="fact-card-body">
              <div class="fact-form-grid">
                <div class="ff-field">
                  <label>Documento origen</label>
                  <div>
                    <?php if ($documentoOrigen): ?>
                      <a href="documento_comercial.php?id=<?= (int)$documentoOrigen['id'] ?>" class="fact-link-inline"><?= h((string)($documentoOrigen['tipo_documento'] ?? 'Documento')) ?> #<?= (int)$documentoOrigen['id'] ?></a>
                    <?php else: ?>
                      <span class="fact-cell-sub">Sin origen documental</span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="ff-field">
                  <label>Venta</label>
                  <div>
                    <?php if ((int)($documento['venta_id'] ?? 0) > 0): ?>
                      <a href="venta_detalle.php?id=<?= (int)$documento['venta_id'] ?>" class="fact-link-inline">Venta #<?= (int)$documento['venta_id'] ?></a>
                    <?php else: ?>
                      <span class="fact-cell-sub">Sin venta vinculada</span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="ff-field">
                  <label>Factura</label>
                  <div>
                    <?php if ($facturaRelacionada): ?>
                      <a href="factura_ver.php?id=<?= (int)$facturaRelacionada['id'] ?>" class="fact-link-inline">Factura #<?= (int)$facturaRelacionada['id'] ?></a>
                    <?php else: ?>
                      <span class="fact-cell-sub">Sin factura asociada</span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="ff-field">
                  <label>Operación real</label>
                  <div><span class="fact-cell-sub"><?= h($impactoOperativo) ?></span></div>
                </div>
                <div class="ff-field ff-field-wide">
                  <label>Documentos derivados</label>
                  <div>
                    <?php if ($documentoHijos === []): ?>
                      <span class="fact-cell-sub">Todavía no hay documentos derivados.</span>
                    <?php else: ?>
                      <?php foreach ($documentoHijos as $hijo): ?>
                        <div><a href="documento_comercial.php?id=<?= (int)$hijo['id'] ?>" class="fact-link-inline"><?= h((string)($hijo['tipo_documento'] ?? 'Documento')) ?> #<?= (int)$hijo['id'] ?></a> <span class="fact-cell-sub">(<?= h((string)($hijo['estado'] ?? 'PENDIENTE')) ?>)</span></div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          </article>
        </section>

        <aside class="fact-manual-sidebar">
          <article class="fact-sidebar-card">
            <h3>Acciones</h3>
            <p class="fact-sidebar-card__text">FLUS muestra solo la siguiente acción válida y bloquea los pasos que ya no corresponden.</p>
          </article>

          <article class="fact-sidebar-card">
            <h3>Estado operativo</h3>
            <div class="fact-sidebar-card__metric">
              <span>Próximo paso</span>
              <strong><?= h($siguienteAccionLabel) ?></strong>
            </div>
            <p class="fact-sidebar-card__text"><?= h($impactoOperativo) ?></p>
          </article>

          <div class="fact-actions fact-actions--sidebar" style="display:flex;flex-direction:column;gap:10px;">
            <?php if ($ventaRelacionada): ?>
              <a href="venta_detalle.php?id=<?= (int)$ventaRelacionada['id'] ?>" class="btn btn-secondary" style="text-align:center;">Ver venta #<?= (int)$ventaRelacionada['id'] ?></a>
            <?php elseif ($tipoActual === 'PRESUPUESTO' && !empty($accionesDocumento['puede_generar_venta'])): ?>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$documento['id'] ?>">
                <input type="hidden" name="action" value="crear_venta">
                <button type="submit" class="btn btn-secondary" style="width:100%;">Generar venta vinculada</button>
              </form>
            <?php else: ?>
              <div class="fact-cell-sub"><?= h((string)($accionesDocumento['motivo_generar_venta'] ?? '')) ?></div>
            <?php endif; ?>

            <?php if ($tipoActual === 'PRESUPUESTO'): ?>
              <?php if ($remitoRelacionado): ?>
                <a href="documento_comercial.php?id=<?= (int)$remitoRelacionado['id'] ?>" class="btn btn-secondary" style="text-align:center;">Ver remito #<?= (int)$remitoRelacionado['id'] ?></a>
                <div class="fact-cell-sub">El remito ya fue generado para este presupuesto.</div>
              <?php elseif (!empty($accionesDocumento['puede_generar_remito'])): ?>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="id" value="<?= (int)$documento['id'] ?>">
                  <input type="hidden" name="action" value="crear_remito">
                  <button type="submit" class="btn btn-secondary" style="width:100%;">Generar remito</button>
                </form>
              <?php else: ?>
                <div class="fact-cell-sub"><?= h((string)($accionesDocumento['motivo_generar_remito'] ?? '')) ?></div>
              <?php endif; ?>
            <?php endif; ?>

            <?php if ($facturaRelacionada): ?>
              <a href="factura_ver.php?id=<?= (int)$facturaRelacionada['id'] ?>" class="btn btn-primary" style="text-align:center;">Ver factura #<?= (int)$facturaRelacionada['id'] ?></a>
              <div class="fact-cell-sub">Ya no corresponde volver a emitir otra factura desde este documento.</div>
            <?php else: ?>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$documento['id'] ?>">
                <input type="hidden" name="action" value="emitir_factura">
                <button type="submit" class="btn btn-primary" style="width:100%;" <?= empty($accionesDocumento['puede_emitir_factura']) ? 'disabled' : '' ?>>Emitir factura</button>
              </form>
              <?php if (empty($accionesDocumento['puede_emitir_factura'])): ?>
                <div class="fact-cell-sub"><?= h((string)($accionesDocumento['motivo_emitir_factura'] ?? '')) ?></div>
              <?php endif; ?>
            <?php endif; ?>

            <?php if (empty($ventaRelacionada)): ?>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int)$documento['id'] ?>">
                <input type="hidden" name="action" value="vincular_venta">
                <label for="venta_id_link" class="fact-cell-sub">Vincular venta existente</label>
                <input id="venta_id_link" type="number" name="venta_id_link" min="1" step="1" class="input-search" placeholder="Venta #" value="">
                <button type="submit" class="btn btn-secondary" style="width:100%;margin-top:8px;" <?= empty($accionesDocumento['puede_vincular_venta']) ? 'disabled' : '' ?>>Guardar vínculo</button>
              </form>
              <?php if (empty($accionesDocumento['puede_vincular_venta'])): ?>
                <div class="fact-cell-sub"><?= h((string)($accionesDocumento['motivo_vincular_venta'] ?? '')) ?></div>
              <?php else: ?>
                <div class="fact-cell-sub">Solo se aceptan ventas del mismo cliente. Si la venta no tiene cliente, FLUS la completa con el del documento.</div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </aside>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

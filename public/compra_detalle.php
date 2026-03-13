<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once FLUS_ROOT . '/src/compras_helpers.php';
require_login();
require_permission('editar_stock');

$id = max(0, (int)($_GET['id'] ?? 0));
if ($id <= 0) {
  http_response_code(400);
  flus_abort(400, 'ID invalido');
}

$origen = strtolower(trim((string)($_GET['origen'] ?? '')));
$backHref = 'compras.php';
$backLabel = 'Volver a compras';

if ($origen === 'movimientos') {
  $backHref = 'movimientos.php';
  $backLabel = 'Volver a movimientos';
}

$stmt = $pdo->prepare("
  SELECT c.*, p.nombre AS proveedor_nombre
  FROM compras c
  LEFT JOIN proveedores p ON p.id = c.proveedor_id
  WHERE c.id = ?
  LIMIT 1
");
$stmt->execute([$id]);
$compra = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$compra) {
  http_response_code(404);
  flus_abort(404, 'Compra no encontrada');
}

$stmtItems = $pdo->prepare("
  SELECT ci.*, p.nombre, p.codigo, p.es_pesable, p.unidad_venta
  FROM compra_items ci
  JOIN productos p ON p.id = ci.producto_id
  WHERE ci.compra_id = ?
  ORDER BY ci.id ASC
");
$stmtItems->execute([$id]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];

$itemsMetrics = flus_compra_items_metrics($items);
$totals = flus_compra_totals((array)$compra, $itemsMetrics);

$itemsView = [];
foreach ($itemsMetrics['items'] as $index => $itemMetrics) {
  $it = $items[$index] ?? [];
  $itemsView[] = [
    'codigo' => (string)($it['codigo'] ?? ''),
    'nombre' => (string)($it['nombre'] ?? 'Producto'),
    'cantidad_fmt' => (
      ((bool)($itemMetrics['es_pesable'] ?? false))
        ? number_format((float)($itemMetrics['cantidad'] ?? 0), 3, ',', '.')
        : number_format((float)($itemMetrics['cantidad'] ?? 0), 0, ',', '.')
    ) . ' ' . (string)($itemMetrics['unidad'] ?? 'UNIDAD'),
    'costo_fmt' => money((float)($itemMetrics['costo_unitario'] ?? 0)),
    'subtotal_fmt' => money((float)($itemMetrics['subtotal'] ?? 0)),
    'descuento_fmt' => (float)($itemMetrics['descuento_monto'] ?? 0) > 0
      ? '-' . money((float)($itemMetrics['descuento_monto'] ?? 0))
      : '-',
    'neto_fmt' => money((float)($itemMetrics['neto'] ?? 0)),
  ];
}

$cantidadTotal = (float)($totals['cantidad_total'] ?? 0.0);
$descuentoItemsTotal = (float)($totals['descuento_items_total'] ?? 0.0);
$totalBruto = (float)($totals['total_bruto'] ?? 0.0);
$descuentoGlobal = (float)($totals['descuento_global'] ?? 0.0);
$totalCompra = (float)($totals['total_compra'] ?? 0.0);
$totalNeto = (float)($totals['total_neto'] ?? 0.0);
$totalIva = (float)($totals['total_iva'] ?? 0.0);

$descuentoGlobalTipo = (string)($totals['descuento_global_tipo'] ?? 'MONTO');
$descuentoGlobalValor = (float)($totals['descuento_global_valor'] ?? 0.0);
$descuentoGlobalDetalle = '';
if ($descuentoGlobal > 0) {
  if ($descuentoGlobalTipo === 'PORC') {
    $descuentoGlobalDetalle = rtrim(rtrim(number_format($descuentoGlobalValor, 2, ',', '.'), '0'), ',') . '%';
  } else {
    $descuentoGlobalDetalle = money($descuentoGlobalValor);
  }
}

$fechaRaw = trim((string)($compra['fecha'] ?? ''));
$fechaFmt = $fechaRaw !== '' ? date('d/m/Y', strtotime($fechaRaw)) : '-';
$proveedor = trim((string)($compra['proveedor_nombre'] ?? ''));
$tipoComp = trim((string)($compra['tipo_comp'] ?? ''));
$nroComp = trim((string)($compra['nro_comp'] ?? ''));
$observacion = trim((string)($compra['obs'] ?? ''));
$estado = strtoupper(trim((string)($compra['estado'] ?? 'BORRADOR')));
if (!in_array($estado, ['BORRADOR', 'CONFIRMADA', 'ANULADA'], true)) {
  $estado = 'BORRADOR';
}

$estadoClass = strtolower($estado);
$estadoTexto = match ($estado) {
  'CONFIRMADA' => 'Esta compra ya impacto el stock y los costos.',
  'ANULADA' => 'La compra fue anulada y ya no debe tomarse como vigente.',
  default => 'Es un borrador: todavia no impacto stock.',
};

$actionHref = 'compras.php?detalle=' . $id;
$actionLabel = 'Ver en compras';
if ($estado === 'BORRADOR') {
  $actionHref = 'compras.php?editar=' . $id;
  $actionLabel = 'Editar borrador';
}

$pageTitle = 'Compra #' . $id . ' - FLUS';
$currentSection = 'compras';
$extraCss = ['assets/css/compra_detalle.css'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap compra-page">
  <div class="panel compra-panel">
    <header class="compra-header">
      <div class="compra-header__main">
        <div class="compra-header__top">
          <a href="<?= h($backHref) ?>" class="link-back"><?= h($backLabel) ?></a>
          <a href="compras.php" class="compra-link-muted">Listado de compras</a>
        </div>

        <h1 class="compra-title">COMPRA #<?= (int)$id ?></h1>

        <div class="compra-meta">
          <span class="compra-badge compra-badge--<?= h($estadoClass) ?>"><?= h($estado) ?></span>
          <span><?= h($fechaFmt) ?></span>
          <span><?= h($proveedor !== '' ? $proveedor : 'Proveedor sin nombre') ?></span>
          <?php if ($tipoComp !== '' || $nroComp !== ''): ?>
            <span><?= h(trim($tipoComp . ' ' . $nroComp)) ?></span>
          <?php endif; ?>
        </div>
      </div>

      <div class="compra-actions">
        <a href="<?= h($actionHref) ?>" class="btn btn-secondary"><?= h($actionLabel) ?></a>
      </div>
    </header>

    <section class="compra-hero">
      <div class="compra-kpis">
        <article class="compra-kpi">
          <span class="compra-kpi__label">Bruto</span>
          <strong class="compra-kpi__value"><?= h(money($totalBruto)) ?></strong>
        </article>

        <article class="compra-kpi">
          <span class="compra-kpi__label">Desc. items</span>
          <strong class="compra-kpi__value"><?= h(money($descuentoItemsTotal)) ?></strong>
        </article>

        <article class="compra-kpi">
          <span class="compra-kpi__label">Desc. global</span>
          <strong class="compra-kpi__value"><?= h(money($descuentoGlobal)) ?></strong>
          <?php if ($descuentoGlobalDetalle !== ''): ?>
            <span class="compra-kpi__hint"><?= h($descuentoGlobalDetalle) ?></span>
          <?php endif; ?>
        </article>

        <article class="compra-kpi compra-kpi--total">
          <span class="compra-kpi__label">Total</span>
          <strong class="compra-kpi__value"><?= h(money($totalCompra)) ?></strong>
        </article>
      </div>

      <aside class="compra-sidecard">
        <div class="compra-sidecard__row">
          <span>Items</span>
          <strong><?= count($itemsView) ?></strong>
        </div>
        <div class="compra-sidecard__row">
          <span>Cantidad total</span>
          <strong><?= h(number_format($cantidadTotal, 3, ',', '.')) ?></strong>
        </div>
        <div class="compra-sidecard__row">
          <span>Total neto</span>
          <strong><?= h(money($totalNeto)) ?></strong>
        </div>
        <?php if ($totalIva > 0): ?>
          <div class="compra-sidecard__row">
            <span>IVA</span>
            <strong><?= h(money($totalIva)) ?></strong>
          </div>
        <?php endif; ?>
      </aside>
    </section>

    <section class="compra-note compra-note--<?= h($estadoClass) ?>">
      <p><?= h($estadoTexto) ?></p>
      <?php if ($observacion !== ''): ?>
        <p><strong>Observacion:</strong> <?= h($observacion) ?></p>
      <?php endif; ?>
    </section>

    <section class="compra-card">
      <div class="compra-card__header">
        <div>
          <h2>Detalle de productos</h2>
          <p>Se muestra el bruto por linea, el descuento del item y el neto resultante.</p>
        </div>
      </div>

      <div class="table-wrapper compra-table-wrap">
        <table class="compra-table">
          <thead>
            <tr>
              <th>Producto</th>
              <th class="right">Cantidad</th>
              <th class="right">Costo unit.</th>
              <th class="right">Bruto</th>
              <th class="right">Desc. item</th>
              <th class="right">Neto item</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$itemsView): ?>
              <tr>
                <td colspan="6" class="empty-cell">No hay items cargados en esta compra.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($itemsView as $item): ?>
                <tr>
                  <td>
                    <strong><?= h($item['nombre']) ?></strong>
                    <div class="compra-product-code"><?= h($item['codigo'] !== '' ? $item['codigo'] : 'Sin codigo') ?></div>
                  </td>
                  <td class="right"><?= h($item['cantidad_fmt']) ?></td>
                  <td class="right"><?= h($item['costo_fmt']) ?></td>
                  <td class="right"><?= h($item['subtotal_fmt']) ?></td>
                  <td class="right"><?= h($item['descuento_fmt']) ?></td>
                  <td class="right"><strong><?= h($item['neto_fmt']) ?></strong></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="5" class="right">Total compra</td>
              <td class="right"><strong><?= h(money($totalCompra)) ?></strong></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

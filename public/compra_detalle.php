<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
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

$itemsView = [];
$cantidadTotal = 0.0;
$brutoCalculado = 0.0;
$descuentoItemsTotal = 0.0;

foreach ($items as $it) {
  $unidad = strtoupper(trim((string)($it['unidad_venta'] ?? 'UNIDAD')));
  $esPesable = (int)($it['es_pesable'] ?? 0) === 1
    || in_array($unidad, ['KG', 'G', 'LT', 'ML'], true);

  $cantidad = (float)($it['cantidad'] ?? 0);
  $costoUnitario = (float)($it['costo_unitario'] ?? 0);
  $subtotal = (float)($it['subtotal'] ?? ($cantidad * $costoUnitario));

  $descuentoTipo = strtoupper(trim((string)($it['descuento_tipo'] ?? 'MONTO')));
  if (!in_array($descuentoTipo, ['MONTO', 'PORC'], true)) {
    $descuentoTipo = 'MONTO';
  }

  $descuentoPorc = max(0.0, (float)($it['descuento_porc'] ?? 0));
  if ($descuentoPorc > 100) {
    $descuentoPorc = 100.0;
  }

  $descuentoMonto = max(0.0, (float)($it['descuento'] ?? 0));
  if ($descuentoTipo === 'PORC' && $subtotal > 0) {
    $descuentoMonto = round($subtotal * ($descuentoPorc / 100.0), 2);
  }
  if ($descuentoMonto > $subtotal) {
    $descuentoMonto = $subtotal;
  }

  $cantidadTotal += $cantidad;
  $brutoCalculado += $subtotal;
  $descuentoItemsTotal += $descuentoMonto;

  $itemsView[] = [
    'codigo' => (string)($it['codigo'] ?? ''),
    'nombre' => (string)($it['nombre'] ?? 'Producto'),
    'cantidad_fmt' => ($esPesable ? number_format($cantidad, 3, ',', '.') : number_format($cantidad, 0, ',', '.')) . ' ' . $unidad,
    'costo_fmt' => money($costoUnitario),
    'subtotal_fmt' => money($subtotal),
    'descuento_fmt' => $descuentoMonto > 0 ? '-' . money($descuentoMonto) : '-',
    'neto_fmt' => money(max(0.0, $subtotal - $descuentoMonto)),
  ];
}

$brutoCalculado = round($brutoCalculado, 2);
$descuentoItemsTotal = round($descuentoItemsTotal, 2);

$totalBruto = round((float)($compra['total_bruto'] ?? 0), 2);
if ($totalBruto <= 0 && $brutoCalculado > 0) {
  $totalBruto = $brutoCalculado;
}
if ($totalBruto <= 0) {
  $totalBruto = round((float)($compra['total'] ?? 0), 2);
}

$descuentoGlobal = round((float)($compra['descuento_total'] ?? 0), 2);
$totalCompra = round((float)($compra['total'] ?? ($totalBruto - $descuentoItemsTotal - $descuentoGlobal)), 2);
$totalNeto = round((float)($compra['total_neto'] ?? $totalCompra), 2);
$totalIva = round((float)($compra['total_iva'] ?? 0), 2);

$descuentoGlobalTipo = strtoupper(trim((string)($compra['descuento_tipo'] ?? 'MONTO')));
$descuentoGlobalValor = (float)($compra['descuento_valor'] ?? 0);
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

$actionHref = 'compras.php?q=' . $id;
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
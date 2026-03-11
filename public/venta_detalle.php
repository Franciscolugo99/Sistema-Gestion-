<?php
// public/venta_detalle.php
declare(strict_types=1);

require_once __DIR__ . '/../src/db_helpers.php';
require_once __DIR__ . '/../src/facturacion_manual_lib.php';

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('ver_reportes');


/* =========================
   ID
========================= */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  http_response_code(400);
  flus_abort(400, "ID inválido");
}

/* =========================
   Venta
========================= */
$stmt = $pdo->prepare("
  SELECT v.*, u.username AS anulado_por_username
  FROM ventas v
  LEFT JOIN users u ON u.id = v.anulado_por
  WHERE v.id = ?
  LIMIT 1
");
$stmt->execute([$id]);
$venta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$venta) {
  http_response_code(404);
  flus_abort(404, "Venta no encontrada");
}

/* =========================
   Pagos (split payments)
========================= */
$hasVentaPagos = has_table($pdo, 'venta_pagos');
$pagos = [];
$pagadoTotal = 0.0;

if ($hasVentaPagos) {
  $stPag = $pdo->prepare("SELECT medio_pago, monto FROM venta_pagos WHERE venta_id = ? ORDER BY monto DESC, id ASC");
  $stPag->execute([$id]);
  $pagos = $stPag->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if ($pagos) {
  foreach ($pagos as $p) $pagadoTotal += (float)($p['monto'] ?? 0);
  $pagadoTotal = round($pagadoTotal, 2);
} else {
  $pagadoTotal = round((float)($venta['monto_pagado'] ?? $venta['total'] ?? 0), 2);
}

/* Medio a mostrar: MIXTO si hay más de un pago */
$medioShow = (string)($venta['medio_pago'] ?? 'SIN_ESPECIFICAR');
$medioTitle = '';

if ($pagos) {
  $mediosUnicos = [];
  foreach ($pagos as $p) {
    $m = strtoupper(trim((string)($p['medio_pago'] ?? '')));
    if ($m !== '') $mediosUnicos[$m] = true;
  }
  $lista = implode('+', array_keys($mediosUnicos));
  $medioShow = (count($mediosUnicos) > 1) ? 'MIXTO' : ($lista !== '' ? $lista : $medioShow);
  $medioTitle = (count($mediosUnicos) > 1) ? $lista : '';
}

/* =========================
   Items
========================= */
$stmt = $pdo->prepare("
  SELECT vi.*, p.codigo, p.nombre
  FROM venta_items vi
  JOIN productos p ON p.id = vi.producto_id
  WHERE vi.venta_id = ?
  ORDER BY vi.id ASC
");
$stmt->execute([$id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
if ($items === []) {
  $items = flus_facturacion_manual_items_fetch($pdo, $id);
}

/* =========================
   Promos aplicadas (si existe venta_promos)
========================= */
$promos = [];
$promosTotal = 0.0;
$hasVentaPromos = has_table($pdo, 'venta_promos');

if ($hasVentaPromos) {
  $st = $pdo->prepare("
    SELECT promo_tipo, promo_nombre, descripcion, descuento_monto, meta
    FROM venta_promos
    WHERE venta_id = ?
    ORDER BY id ASC
  ");
  $st->execute([$id]);
  $promos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($promos as $p) $promosTotal += (float)($p['descuento_monto'] ?? 0);
  $promosTotal = round($promosTotal, 2);
}

/* =========================
   Totales calculados desde items
========================= */
$brutoCalc = 0.0;
$netoCalc  = 0.0;
$descCalc  = 0.0;

foreach ($items as $it) {
  $cant = (float)($it['cantidad'] ?? 0);

  $puOriginal = ($it['precio_unit_original'] ?? null) !== null
    ? (float)$it['precio_unit_original']
    : (float)($it['precio'] ?? 0);

  $subtotal = ($it['subtotal'] ?? null) !== null
    ? (float)$it['subtotal']
    : (float)($it['precio'] ?? 0) * $cant;

  $descLinea = ($it['descuento_monto'] ?? null) !== null ? (float)$it['descuento_monto'] : 0.0;

  $brutoCalc += ($puOriginal * $cant);
  $netoCalc  += $subtotal;
  $descCalc  += $descLinea;
}

$brutoCalc = round($brutoCalc, 2);
$netoCalc  = round($netoCalc, 2);
$descCalc  = round($descCalc, 2);

$totalVenta = round((float)($venta['total'] ?? 0), 2);

/* =========================
   Factura vinculada
========================= */
$stmt = $pdo->prepare("
  SELECT f.*
  FROM facturas f
  WHERE f.venta_id = ?
  ORDER BY f.id DESC
  LIMIT 1
");
$stmt->execute([$id]);
$factura = $stmt->fetch(PDO::FETCH_ASSOC);

/* =========================
   Config facturacion
========================= */
$facturacionActiva = config_get($pdo, 'facturacion_habilitada', '0') === '1';
$configFact = null;
if ($facturacionActiva && has_table($pdo, 'config_facturacion')) {
  try {
    $stmt = $pdo->query("
      SELECT *
      FROM config_facturacion
      WHERE activo = 1
      ORDER BY id DESC
      LIMIT 1
    ");
    $configFact = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;

    if (!$configFact) {
      $stmt = $pdo->query("
        SELECT *
        FROM config_facturacion
        ORDER BY id DESC
        LIMIT 1
      ");
      $configFact = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    }
  } catch (Throwable $e) {
    $configFact = null;
  }
}
$facturacionConfigurada = $configFact !== null;
$ventaEstado = function_exists('flus_normalize_sale_status')
  ? flus_normalize_sale_status($venta['estado'] ?? null)
  : strtoupper((string)($venta['estado'] ?? 'EMITIDA'));
$ventaAnulada = function_exists('flus_sale_is_annulled')
  ? flus_sale_is_annulled($venta)
  : ($ventaEstado === 'ANULADA');
$ventaPuedeAnular = function_exists('flus_sale_can_be_annulled')
  ? flus_sale_can_be_annulled($venta)
  : !$ventaAnulada;
$ventaPuedeAnular = $ventaPuedeAnular && function_exists('user_has_permission') && user_has_permission('anular_venta');

/* =========================
   Header común
========================= */
$pageTitle      = "Venta #$id - FLUS";
$currentSection = "ventas";
$extraCss       = ['assets/css/venta_detalle.css?v=2'];
$extraJs        = ['assets/js/venta_anular.js'];

require __DIR__ . '/partials/header.php';
?>

<?php if (!empty($_GET['fact_ok'])): ?>
  <div class="msg msg-ok" style="margin-bottom:10px;">Factura emitida correctamente.</div>
<?php endif; ?>

<?php if (!empty($_GET['fact_error'])): ?>
  <div class="msg msg-error" style="margin-bottom:10px;"><?= h($_GET['fact_error']) ?></div>
<?php endif; ?>

<div class="page-wrap venta-page">

  <div class="panel venta-panel">

    <div class="venta-header">
      <div class="venta-header-left">
        <h1 class="venta-title">VENTA #<?= h((string)$id) ?></h1>
        <a href="ventas.php" class="link-back">← Volver a ventas</a>
      </div>

      <div class="venta-header-right">
        <div class="venta-resumen">

          <div class="venta-resumen-item">
            <span class="label">Fecha</span>
            <span class="value"><?= h($venta['fecha'] ?? '') ?></span>
          </div>

          <div class="venta-resumen-item">
            <span class="label">Medio de pago</span>
            <span class="value">
              <?php
                $mpClass = strtolower(preg_replace('/[^a-z0-9_]+/i', '', $medioShow));
              ?>
              <span class="badge-medio badge-medio-<?= h($mpClass) ?>" <?= $medioTitle ? 'title="'.h($medioTitle).'"' : '' ?>>
                <?= h($medioShow) ?>
              </span>
              <?php if ($medioTitle): ?>
                <span class="muted" style="margin-left:8px;"><?= h($medioTitle) ?></span>
              <?php endif; ?>
            </span>
          </div>

          <div class="venta-resumen-item">
            <span class="label">Estado</span>
            <span class="value">
              <?php if ($ventaAnulada): ?>
                <span class="badge badge-danger">ANULADA</span>
              <?php else: ?>
                <span class="badge badge-success"><?= h((string)$ventaEstado) ?></span>
              <?php endif; ?>
            </span>
          </div>

          <?php if ($ventaAnulada): ?>
            <div class="venta-resumen-item">
              <span class="label">Anulada</span>
              <span class="value">
                <?= h((string)($venta['anulado_en'] ?? '')) ?>
                <?php if (!empty($venta['anulado_por_username'])): ?>
                  · por <?= h((string)$venta['anulado_por_username']) ?>
                <?php endif; ?>
                <?php if (!empty($venta['anulado_motivo'])): ?>
                  <span class="muted">· <?= h((string)$venta['anulado_motivo']) ?></span>
                <?php endif; ?>
              </span>
            </div>
          <?php endif; ?>

          <?php if ($brutoCalc > 0 && ($descCalc > 0.009 || $promosTotal > 0.009)): ?>
            <div class="venta-resumen-item">
              <span class="label">Bruto</span>
              <span class="value"><?= money($brutoCalc) ?></span>
            </div>
            <div class="venta-resumen-item">
              <span class="label">Descuento</span>
              <span class="value"><?= money(max($descCalc, $promosTotal)) ?></span>
            </div>
          <?php endif; ?>

          <div class="venta-resumen-item">
            <span class="label">Total</span>
            <span class="value monto-total"><?= money($totalVenta) ?></span>
          </div>

          <div class="venta-resumen-item">
            <span class="label">Pagado</span>
            <span class="value"><?= money($pagadoTotal) ?></span>
          </div>

          <div class="venta-resumen-item">
            <span class="label">Vuelto</span>
            <span class="value"><?= money($venta['vuelto'] ?? 0) ?></span>
          </div>

          <?php if ($pagos): ?>
            <div class="venta-resumen-item" style="grid-column: 1 / -1;">
              <span class="label">Detalle de pagos</span>
              <span class="value">
                <?php foreach ($pagos as $p): ?>
                  <?php
                    $m = strtoupper(trim((string)($p['medio_pago'] ?? '')));
                    $mon = (float)($p['monto'] ?? 0);
                  ?>
                  <span class="badge" style="margin-right:6px;"><?= h($m ?: 'PAGO') ?>: <?= money($mon) ?></span>
                <?php endforeach; ?>
              </span>
            </div>
          <?php endif; ?>

        </div>

        <div class="venta-acciones">
          <?php if ($factura): ?>
            <div class="factura-info">
              <span class="badge badge-pill badge-green">Facturada</span>

              <div class="factura-text">
                <div>
                  Comprobante:
                  <strong>
                    <?= h($factura['tipo'] ?? '') ?>
                    <?= sprintf('%04d-%08d', (int)($factura['punto_venta'] ?? 0), (int)($factura['numero'] ?? 0)) ?>
                  </strong>
                </div>

                <div class="factura-links">
                  <a href="facturacion.php?venta_id=<?= (int)$id ?>" class="btn btn-secondary btn-sm">
                    Ver en facturacion
                  </a>
                </div>
              </div>
            </div>

          <?php else: ?>

            <?php if ($facturacionActiva && $facturacionConfigurada): ?>
              <?php if ($ventaAnulada): ?>
                <span class="venta-hint"><strong>Venta anulada:</strong> no se puede emitir factura.</span>
              <?php else: ?>
                <a href="factura_nueva.php?venta_id=<?= (int)$id ?>" class="btn btn-primary">Emitir factura</a>
              <?php endif; ?>
            <?php elseif ($facturacionActiva): ?>
              <span class="venta-hint">
                Para emitir factura configura primero un punto de venta en
                <strong>Facturacion &gt; Configuracion</strong>.
              </span>
            <?php else: ?>
              <span class="venta-hint">Facturacion desactivada para este comercio.</span>
            <?php endif; ?>

            <?php if ($ventaPuedeAnular): ?>
              <button type="button" class="btn btn-danger" id="btnAnularVenta" data-venta-id="<?= (int)$id ?>">
                Anular venta
              </button>
            <?php endif; ?>

          <?php endif; ?>
        </div>

      </div>
    </div>
  </div>

  <?php if ($hasVentaPromos && !empty($promos)): ?>
    <div class="panel">
      <div class="venta-detalle-header">
        <h2>Promociones / Descuentos aplicados</h2>
      </div>

      <div class="table-wrapper">
        <table class="venta-table">
          <thead>
            <tr>
              <th>Tipo</th>
              <th>Nombre</th>
              <th>Detalle</th>
              <th class="right">Descuento</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($promos as $p): ?>
              <?php
                $tipo  = (string)($p['promo_tipo'] ?? '');
                $nom   = (string)($p['promo_nombre'] ?? '');
                $desc  = (string)($p['descripcion'] ?? '');
                $monto = (float)($p['descuento_monto'] ?? 0);
              ?>
              <tr>
                <td><?= h($tipo) ?></td>
                <td><?= h($nom) ?></td>
                <td><?= h($desc) ?></td>
                <td class="right"><?= money($monto) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <th colspan="3" class="right">Total descuentos</th>
              <th class="right"><?= money($promosTotal) ?></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <div class="panel">
    <div class="venta-detalle-header">
      <h2>Productos de la venta</h2>
    </div>

    <div class="table-wrapper">
      <table class="venta-table">
        <thead>
          <tr>
            <th>Código</th>
            <th>Producto</th>
            <th class="right">Cant.</th>
            <th class="right">Precio</th>
            <th class="right">Subtotal</th>
          </tr>
        </thead>

        <tbody>
          <?php if ($items): ?>
            <?php foreach ($items as $it): ?>
              <tr>
                <td><?= h($it['codigo'] ?? '') ?></td>
                <td><?= h($it['nombre'] ?? '') ?></td>
                <td class="right"><?= h(format_qty($it['cantidad'] ?? 0)) ?></td>
                <td class="right"><?= money($it['precio'] ?? 0) ?></td>
                <td class="right"><?= money($it['subtotal'] ?? ((float)($it['precio'] ?? 0) * (float)($it['cantidad'] ?? 0))) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="5" class="empty-cell">Esta venta no tiene productos registrados.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

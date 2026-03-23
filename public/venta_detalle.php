<?php
// public/venta_detalle.php
declare(strict_types=1);

require_once __DIR__ . '/../src/db_helpers.php';
require_once __DIR__ . '/../src/facturacion_manual_lib.php';
require_once __DIR__ . '/../src/venta_anulaciones_lib.php';

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('ver_reportes');

/* =========================
   ID
========================= */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  http_response_code(400);
  flus_abort(400, 'ID invalido');
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
  flus_abort(404, 'Venta no encontrada');
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
  foreach ($pagos as $p) {
    $pagadoTotal += (float)($p['monto'] ?? 0);
  }
  $pagadoTotal = round($pagadoTotal, 2);
} else {
  $pagadoTotal = round((float)($venta['monto_pagado'] ?? $venta['total'] ?? 0), 2);
}

/* Medio a mostrar: MIXTO si hay mas de un pago */
$medioShow = (string)($venta['medio_pago'] ?? 'SIN_ESPECIFICAR');
$medioTitle = '';

if ($pagos) {
  $mediosUnicos = [];
  foreach ($pagos as $p) {
    $m = strtoupper(trim((string)($p['medio_pago'] ?? '')));
    if ($m !== '') {
      $mediosUnicos[$m] = true;
    }
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

$anulacionesItemsMap = flus_venta_items_anulados_map($pdo, $id);
$historialAnulaciones = flus_venta_anulaciones_historial($pdo, $id);
$hayAnulaciones = $historialAnulaciones !== [];
$montoAnuladoTotal = 0.0;
$cantidadAnuladaTotal = 0.0;
$lineasConAnulacion = 0;
$hayItemsAnulables = false;
foreach ($items as $it) {
  if (!empty($it['id'])) {
    $hayItemsAnulables = true;
    break;
  }
}
foreach ($historialAnulaciones as $anulacion) {
  $montoAnuladoTotal += (float)($anulacion['monto_total'] ?? 0);
  $cantidadAnuladaTotal += (float)($anulacion['cantidad_total_anulada'] ?? 0);
}
foreach ($anulacionesItemsMap as $cantidadAnuladaItem) {
  if ((float)$cantidadAnuladaItem > 0.0009) {
    $lineasConAnulacion++;
  }
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

  foreach ($promos as $p) {
    $promosTotal += (float)($p['descuento_monto'] ?? 0);
  }
  $promosTotal = round($promosTotal, 2);
}

/* =========================
   Totales calculados desde items
========================= */
$brutoCalc = 0.0;
$netoCalc = 0.0;
$descCalc = 0.0;

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
  $netoCalc += $subtotal;
  $descCalc += $descLinea;
}

$brutoCalc = round($brutoCalc, 2);
$netoCalc = round($netoCalc, 2);
$descCalc = round($descCalc, 2);

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
$ventaEstadoLabel = match ($ventaEstado) {
  'PARCIALMENTE_ANULADA' => 'Parcialmente anulada',
  'ANULADA' => 'Anulada',
  'EMITIDA' => 'Emitida',
  default => ucwords(strtolower(str_replace('_', ' ', $ventaEstado))),
};
$ventaEstadoBadgeClass = match ($ventaEstado) {
  'ANULADA' => 'badge-danger',
  'PARCIALMENTE_ANULADA' => 'badge-warning',
  default => 'badge-success',
};
$ventaAnulada = function_exists('flus_sale_is_annulled')
  ? flus_sale_is_annulled($venta)
  : ($ventaEstado === 'ANULADA');
$ventaPuedeAnular = function_exists('flus_sale_can_be_annulled')
  ? flus_sale_can_be_annulled($venta)
  : !$ventaAnulada;
$ventaPuedeAnular = $ventaPuedeAnular && function_exists('user_has_permission') && user_has_permission('anular_venta');
$puedeAnularItems = !$ventaAnulada
  && ((int)($venta['facturada'] ?? 0) === 0)
  && flus_venta_anulaciones_habilitadas($pdo)
  && function_exists('user_has_permission')
  && user_has_permission('anular_items_venta')
  && $hayItemsAnulables;
$montoAnuladoTotal = round($montoAnuladoTotal, 2);
$cantidadAnuladaTotal = round($cantidadAnuladaTotal, 3);
$netoVigente = max(0, round($totalVenta - $montoAnuladoTotal, 2));
$metricCards = [
  ['label' => 'Total', 'value' => money($totalVenta), 'class' => ''],
];
if ($hayAnulaciones) {
  $metricCards[] = ['label' => 'Devuelto', 'value' => money($montoAnuladoTotal), 'class' => 'devuelto'];
  $metricCards[] = ['label' => 'Neto vigente', 'value' => money($netoVigente), 'class' => $netoVigente <= 0.009 ? 'neto-cero' : 'neto-vigente'];
}
$metricCards[] = ['label' => 'Pagado', 'value' => money($pagadoTotal), 'class' => ''];
if ($pagos) {
  $detallePagos = [];
  foreach ($pagos as $p) {
    $m = strtoupper(trim((string)($p['medio_pago'] ?? '')));
    $detallePagos[] = '<span class="badge">' . h($m ?: 'PAGO') . ': ' . money((float)($p['monto'] ?? 0)) . '</span>';
  }
  $metricCards[] = ['label' => 'Detalle', 'value_html' => implode('', $detallePagos), 'class' => 'metric-card-detail'];
}

/* =========================
   Header comun
========================= */
$pageTitle = "Venta #$id - FLUS";
$currentSection = 'ventas';
$extraCss = ['assets/css/venta_detalle.css?v=5'];
$extraJs = ['assets/js/venta_anular.js', 'assets/js/venta_anular_items.js?v=2'];

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
      <div class="venta-header-main">
        <div class="venta-header-copy">
          <a href="ventas.php" class="link-back">&larr; Volver a ventas</a>
          <h1 class="venta-title">VENTA #<?= h((string)$id) ?></h1>

          <?php $mpClass = strtolower(preg_replace('/[^a-z0-9_]+/i', '', $medioShow)); ?>
          <div class="venta-meta-line">
            <span class="venta-meta-text"><?= h($venta['fecha'] ?? '') ?></span>
            <span class="venta-meta-dot">&middot;</span>
            <span class="badge-medio badge-medio-<?= h($mpClass) ?>" <?= $medioTitle ? 'title="' . h($medioTitle) . '"' : '' ?>>
              <?= h($medioShow) ?>
            </span>
            <span class="venta-meta-dot">&middot;</span>
            <span class="badge <?= h($ventaEstadoBadgeClass) ?>"><?= h($ventaEstadoLabel) ?></span>
          </div>

          <?php if ($ventaAnulada): ?>
            <div class="anulacion-nota">
              <div class="anulacion-nota-top">
                <?php if (!empty($venta['anulado_motivo'])): ?>
                  <span class="anulacion-nota-label">Motivo:</span>
                  <span class="anulacion-nota-text"><?= h((string)$venta['anulado_motivo']) ?></span>
                <?php else: ?>
                  <span class="anulacion-nota-text">Venta anulada sin motivo informado.</span>
                <?php endif; ?>
              </div>
              <div class="anulacion-nota-quien">
                <?= h((string)($venta['anulado_por_username'] ?? 'Sistema')) ?>
                <?php if (!empty($venta['anulado_en'])): ?>
                  &middot; <?= h((string)$venta['anulado_en']) ?>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($brutoCalc > 0 || $descCalc > 0.009 || $promosTotal > 0.009 || (float)($venta['vuelto'] ?? 0) > 0.009): ?>
            <div class="venta-inline-meta">
              <?php if ($brutoCalc > 0): ?>
                <span>Bruto <?= money($brutoCalc) ?></span>
              <?php endif; ?>
              <?php if ($descCalc > 0.009 || $promosTotal > 0.009): ?>
                <span>Descuento <?= money(max($descCalc, $promosTotal)) ?></span>
              <?php endif; ?>
              <?php if ((float)($venta['vuelto'] ?? 0) > 0.009): ?>
                <span>Vuelto <?= money($venta['vuelto'] ?? 0) ?></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <div class="metric-row <?= count($metricCards) >= 4 ? 'metric-row-wide' : 'metric-row-compact' ?>">
            <?php foreach ($metricCards as $card): ?>
              <div class="metric-card <?= !empty($card['class']) ? h((string)$card['class']) : '' ?>">
                <span class="metric-label"><?= h((string)$card['label']) ?></span>
                <?php if (isset($card['value_html'])): ?>
                  <div class="metric-value metric-value-html"><?= $card['value_html'] ?></div>
                <?php else: ?>
                  <strong class="metric-value <?= !empty($card['class']) ? h((string)$card['class']) : '' ?>"><?= h((string)$card['value']) ?></strong>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="venta-acciones">
          <?php if ($factura): ?>
            <div class="factura-info">
              <span class="badge badge-success">Facturada</span>

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
                  <a href="facturacion_nc.php?factura_id=<?= (int)($factura['id'] ?? 0) ?>" class="btn btn-primary btn-sm">
                    Gestionar NC
                  </a>
                </div>
              </div>
            </div>
          <?php else: ?>
            <?php if ($facturacionActiva && $facturacionConfigurada && !$ventaAnulada): ?>
              <a href="factura_nueva.php?venta_id=<?= (int)$id ?>" class="btn btn-primary">Emitir factura</a>
            <?php endif; ?>

            <?php if ($ventaPuedeAnular): ?>
              <button type="button" class="btn btn-danger" id="btnAnularVenta" data-venta-id="<?= (int)$id ?>">
                Anular venta
              </button>
            <?php endif; ?>

            <?php if ($puedeAnularItems): ?>
              <button type="button" class="btn btn-secondary" id="btnAnularItems" data-venta-id="<?= (int)$id ?>">
                Anular items
              </button>
            <?php endif; ?>

            <?php if ($facturacionActiva && $facturacionConfigurada && $ventaAnulada): ?>
              <span class="venta-hint"><strong>Venta anulada:</strong> no se puede emitir factura.</span>
            <?php elseif ($facturacionActiva && !$facturacionConfigurada): ?>
              <span class="venta-hint">
                Para emitir factura configura primero un punto de venta en
                <strong>Facturacion &gt; Configuracion</strong>.
              </span>
            <?php elseif (!$facturacionActiva): ?>
              <span class="venta-hint">Facturacion desactivada para este comercio.</span>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if ($hayAnulaciones): ?>
    <div class="panel">
      <div class="section-title">Resumen de anulaciones</div>

      <div class="anulacion-kpis">
        <div class="anulacion-kpi">
          <span class="anulacion-kpi-label">Estado actual</span>
          <strong class="anulacion-kpi-value"><?= h($ventaEstadoLabel) ?></strong>
          <span class="anulacion-kpi-help"><?= count($historialAnulaciones) ?> movimiento(s) registrado(s)</span>
        </div>

        <div class="anulacion-kpi">
          <span class="anulacion-kpi-label">Monto devuelto</span>
          <strong class="anulacion-kpi-value"><?= money($montoAnuladoTotal) ?></strong>
          <span class="anulacion-kpi-help">Sobre un total original de <?= money($totalVenta) ?></span>
        </div>

        <div class="anulacion-kpi">
          <span class="anulacion-kpi-label">Neto vigente</span>
          <strong class="anulacion-kpi-value"><?= money($netoVigente) ?></strong>
          <span class="anulacion-kpi-help">Importe que sigue activo luego de las devoluciones</span>
        </div>

        <div class="anulacion-kpi">
          <span class="anulacion-kpi-label">Items afectados</span>
          <strong class="anulacion-kpi-value"><?= h((string)$lineasConAnulacion) ?></strong>
          <span class="anulacion-kpi-help"><?= h(format_qty($cantidadAnuladaTotal)) ?> unidad(es) anuladas en total</span>
        </div>
      </div>
    </div>

    <div class="panel">
      <div class="section-title">Historial de anulaciones</div>

      <div class="anulacion-history">
        <?php foreach ($historialAnulaciones as $anulacion): ?>
          <?php
            $tipoAnulacion = strtoupper((string)($anulacion['tipo'] ?? 'PARCIAL'));
            $tipoLabel = $tipoAnulacion === 'TOTAL' ? 'Anulacion total' : 'Anulacion parcial';
            $tipoBadgeClass = $tipoAnulacion === 'TOTAL' ? 'badge-danger' : 'badge-warning';
          ?>
          <article class="anulacion-card">
            <div class="anulacion-card-head">
              <div class="anulacion-card-head-main">
                <span class="badge <?= h($tipoBadgeClass) ?>"><?= h($tipoLabel) ?></span>
                <strong class="anulacion-card-title"><?= h((string)($anulacion['anulado_en'] ?? '')) ?></strong>
              </div>
              <div class="anulacion-card-total"><?= money($anulacion['monto_total'] ?? 0) ?></div>
            </div>

            <div class="anulacion-card-meta">
              <?php if (!empty($anulacion['anulado_por_username'])): ?>
                <span>Por <?= h((string)$anulacion['anulado_por_username']) ?></span>
              <?php endif; ?>
              <?php if (!empty($anulacion['motivo'])): ?>
                <span>Motivo: <?= h((string)$anulacion['motivo']) ?></span>
              <?php endif; ?>
              <span><?= h((string)($anulacion['lineas_afectadas'] ?? 0)) ?> linea(s)</span>
              <span><?= h(format_qty($anulacion['cantidad_total_anulada'] ?? 0)) ?> unidad(es)</span>
            </div>

            <div class="table-wrapper">
              <table class="venta-table venta-table-compact">
                <thead>
                  <tr>
                    <th>Producto</th>
                    <th class="right">Cant. anulada</th>
                    <th class="right">Subtotal</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach (($anulacion['items'] ?? []) as $anulacionItem): ?>
                    <?php
                      $productoNombre = trim((string)($anulacionItem['producto_nombre'] ?? ''));
                      $productoCodigo = trim((string)($anulacionItem['producto_codigo'] ?? ''));
                    ?>
                    <tr>
                      <td class="col-nombre">
                        <?= h($productoNombre !== '' ? $productoNombre : ('Producto #' . (int)($anulacionItem['producto_id'] ?? 0))) ?>
                        <?php if ($productoCodigo !== ''): ?>
                          <span class="table-note">Cod. <?= h($productoCodigo) ?></span>
                        <?php endif; ?>
                      </td>
                      <td class="right"><?= h(format_qty($anulacionItem['cantidad_anulada'] ?? 0)) ?></td>
                      <td class="right col-monto"><?= money($anulacionItem['subtotal_anulado'] ?? 0) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($hasVentaPromos && !empty($promos)): ?>
    <div class="panel">
      <div class="section-title">Promociones / Descuentos aplicados</div>

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
                $tipo = (string)($p['promo_tipo'] ?? '');
                $nom = (string)($p['promo_nombre'] ?? '');
                $desc = (string)($p['descripcion'] ?? '');
                $monto = (float)($p['descuento_monto'] ?? 0);
              ?>
              <tr>
                <td><?= h($tipo) ?></td>
                <td class="col-nombre"><?= h($nom) ?></td>
                <td><?= h($desc) ?></td>
                <td class="right col-monto"><?= money($monto) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <th colspan="3" class="right">Total descuentos</th>
              <th class="right col-monto"><?= money($promosTotal) ?></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <div class="panel">
    <div class="section-title">Productos de la venta</div>

    <div class="table-wrapper">
      <table class="venta-table" id="tabla-items-venta">
        <thead>
          <tr>
            <th>Codigo</th>
            <th>Producto</th>
            <th class="right">Vendida</th>
            <th class="right">Devuelta</th>
            <th class="right">Pendiente</th>
            <th class="right">Precio</th>
            <th class="right">Subtotal</th>
          </tr>
        </thead>

        <tbody>
          <?php if ($items): ?>
            <?php foreach ($items as $it): ?>
              <?php
                $itemId = (int)($it['id'] ?? 0);
                $cantidadOriginal = (float)($it['cantidad'] ?? 0);
                $cantidadAnulada = (float)($anulacionesItemsMap[$itemId] ?? 0);
                $cantidadDisponible = max(0, round($cantidadOriginal - $cantidadAnulada, 3));
                $precioUnitario = (float)($it['precio_unit_final'] ?? $it['precio'] ?? 0);
                if ($precioUnitario <= 0 && $cantidadOriginal > 0) {
                  $precioUnitario = round((float)($it['subtotal'] ?? 0) / $cantidadOriginal, 2);
                }
                $estadoItemLabel = null;
                $estadoItemClass = '';
                if ($cantidadAnulada > 0.0009 && $cantidadDisponible <= 0.0009) {
                  $estadoItemLabel = 'Devuelto total';
                  $estadoItemClass = 'item-status-full';
                } elseif ($cantidadAnulada > 0.0009) {
                  $estadoItemLabel = 'Devuelto parcial';
                  $estadoItemClass = 'item-status-partial';
                }
              ?>
              <tr
                class="<?= $estadoItemClass !== '' ? h($estadoItemClass) : '' ?>"
                data-item-id="<?= $itemId ?>"
                data-nombre="<?= h((string)($it['nombre'] ?? '')) ?>"
                data-cantidad-disp="<?= h((string)$cantidadDisponible) ?>"
                data-precio="<?= h((string)$precioUnitario) ?>"
              >
                <td class="col-codigo"><?= h($it['codigo'] ?? '') ?></td>
                <td class="col-nombre">
                  <?= h($it['nombre'] ?? '') ?>
                  <?php if ($estadoItemLabel !== null): ?>
                    <span class="table-note <?= h($estadoItemClass) ?>"><?= h($estadoItemLabel) ?></span>
                  <?php endif; ?>
                </td>
                <td class="right"><?= h(format_qty($it['cantidad'] ?? 0)) ?></td>
                <td class="right"><?= $cantidadAnulada > 0 ? h(format_qty($cantidadAnulada)) : '0' ?></td>
                <td class="right"><?= h(format_qty($cantidadDisponible)) ?></td>
                <td class="right col-monto"><?= money($it['precio'] ?? 0) ?></td>
                <td class="right col-monto"><?= money($it['subtotal'] ?? ((float)($it['precio'] ?? 0) * (float)($it['cantidad'] ?? 0))) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" class="empty-cell">Esta venta no tiene productos registrados.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php
// public/venta_detalle.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
require_permission('ver_reportes');

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php';

$pdo = getPDO();

/* =========================
   FALLBACK HELPERS (por si helpers.php no los tiene)
========================= */
if (!function_exists('h')) {
  function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('money')) {
  function money($n): string {
    return '$' . number_format((float)$n, 2, ',', '.');
  }
}

if (!function_exists('format_qty')) {
  function format_qty($n): string {
    $v = (float)$n;
    if (abs($v - round($v)) < 0.0000001) return number_format($v, 0, ',', '.');
    $s = number_format($v, 3, ',', '.');
    $s = rtrim($s, '0');
    $s = rtrim($s, ',');
    return $s;
  }
}

/* =========================
   ID
========================= */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  http_response_code(400);
  die("ID inválido");
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
  die("Venta no encontrada");
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

/* =========================
   Promos aplicadas (si existe tabla venta_promos)
========================= */
$promos = [];
$promosTotal = 0.0;
$hasVentaPromos = false;

try {
  $hasVentaPromos = (bool)$pdo->query("
    SELECT 1
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'venta_promos'
    LIMIT 1
  ")->fetchColumn();
} catch (Throwable $e) {
  $hasVentaPromos = false;
}

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
   - Bruto: precio_unit_original * cantidad (si existe), sino precio*cantidad
   - Neto : subtotal (si existe), sino precio*cantidad
   - Desc : suma descuento_monto (si existe), sino bruto - neto
========================= */
$brutoCalc = 0.0;
$netoCalc  = 0.0;
$descCalc  = 0.0;

foreach ($items as $it) {
  $cant = (float)($it['cantidad'] ?? 0);

  $puOriginal = null;
  if (array_key_exists('precio_unit_original', $it) && $it['precio_unit_original'] !== null) {
    $puOriginal = (float)$it['precio_unit_original'];
  } else {
    $puOriginal = (float)($it['precio'] ?? 0);
  }

  $subtotal = null;
  if (array_key_exists('subtotal', $it) && $it['subtotal'] !== null) {
    $subtotal = (float)$it['subtotal'];
  } else {
    $subtotal = (float)($it['precio'] ?? 0) * $cant;
  }

  $descLinea = 0.0;
  if (array_key_exists('descuento_monto', $it) && $it['descuento_monto'] !== null) {
    $descLinea = (float)$it['descuento_monto'];
  }

  $brutoCalc += ($puOriginal * $cant);
  $netoCalc  += $subtotal;
  $descCalc  += $descLinea;
}

$brutoCalc = round($brutoCalc, 2);
$netoCalc  = round($netoCalc, 2);
$descCalc  = round($descCalc, 2);

// Autoridad final: ventas.total (si mañana agregás recargos/ajustes)
$totalVenta = round((float)($venta['total'] ?? 0), 2);

/* =========================
   Factura vinculada (si existe)
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
   Config facturación (habilitada?)
========================= */
$stmt = $pdo->query("
  SELECT *
  FROM config_facturacion
  WHERE activo = 1
  ORDER BY id ASC
  LIMIT 1
");
$configFact = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
$facturacionHabilitada = (bool)$configFact;

/* =========================
   Header común
========================= */
$pageTitle      = "Venta #$id - FLUS";
$currentSection = "ventas";
$extraCss       = ['assets/css/venta_detalle.css?v=1'];
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

  <!-- PANEL PRINCIPAL -->
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
                $mp = (string)($venta['medio_pago'] ?? 'SIN_ESPECIFICAR');
                $mpClass = strtolower(preg_replace('/[^a-z0-9_]+/i', '', $mp));
              ?>
              <span class="badge-medio badge-medio-<?= h($mpClass) ?>">
                <?= h($mp) ?>
              </span>
            </span>
          </div>

          <div class="venta-resumen-item">
            <span class="label">Estado</span>
            <span class="value">
              <?php if (strtoupper((string)($venta['estado'] ?? 'EMITIDA')) === 'ANULADA'): ?>
                <span class="badge badge-danger">ANULADA</span>
              <?php else: ?>
                <span class="badge badge-success">EMITIDA</span>
              <?php endif; ?>
            </span>
          </div>

          <?php if (strtoupper((string)($venta['estado'] ?? 'EMITIDA')) === 'ANULADA'): ?>
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
            <span class="value"><?= money($venta['monto_pagado'] ?? 0) ?></span>
          </div>

          <div class="venta-resumen-item">
            <span class="label">Vuelto</span>
            <span class="value"><?= money($venta['vuelto'] ?? 0) ?></span>
          </div>

        </div>

        <!-- ACCIONES / FACTURACIÓN -->
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
                    Ver en facturación
                  </a>
                </div>
              </div>
            </div>

          <?php elseif ($facturacionHabilitada): ?>

            <?php if (strtoupper((string)($venta['estado'] ?? 'EMITIDA')) === 'ANULADA'): ?>
              <span class="venta-hint"><strong>Venta anulada:</strong> no se puede emitir factura.</span>
            <?php else: ?>
              <a href="factura_nueva.php?venta_id=<?= (int)$id ?>" class="btn btn-primary">Emitir factura</a>
            <?php endif; ?>

            <?php if (strtoupper((string)($venta['estado'] ?? 'EMITIDA')) !== 'ANULADA' && function_exists('user_has_permission') && user_has_permission('anular_venta')): ?>
              <button type="button" class="btn btn-danger" id="btnAnularVenta" data-venta-id="<?= (int)$id ?>">
                Anular venta
              </button>
            <?php endif; ?>

          <?php else: ?>

            <span class="venta-hint">
              Para emitir factura configurá primero un punto de venta en
              <strong>Facturación &gt; Configuración</strong>.
            </span>

          <?php endif; ?>
        </div>

      </div>
    </div>
  </div>

  <!-- PROMOS APLICADAS -->
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

  <!-- DETALLE PRODUCTOS -->
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

<?php
// public/ticket.php
declare(strict_types=1);

require_once __DIR__ . '/../src/db_helpers.php';

require_once __DIR__ . '/bootstrap.php';
require_login();
require_any_permission(['realizar_ventas','ver_reportes']);

$pdo = getPDO();

/* =========================
   Ticket compacto (flags)
========================= */
$TICKET_SHOW_CODE          = false;  // ❌ no imprimir códigos (ni barcode)
$TICKET_SHOW_PROMO_DETAILS = false;  // ❌ no listar promos una por una
$TICKET_SHOW_UNIT_PRICE_UN = false;  // UN: si cant=1, no muestra PU (ticket más corto)

/* =========================
   Params
========================= */
$ventaId = (int)($_GET['venta_id'] ?? 0);
if ($ventaId <= 0) $ventaId = (int)($_GET['id'] ?? 0);
if ($ventaId <= 0) {
  http_response_code(400);
  flus_abort(400, 'ID de venta inválido.');
}

$paper = (string)($_GET['paper'] ?? '80');
$paper = ($paper === '58') ? '58' : '80';

$autoPrint = ((string)($_GET['autoprint'] ?? '') === '1');

/* =========================
   Helpers
========================= */
if (!function_exists('fmt_money_ticket')) {
  function fmt_money_ticket($n): string {
    return '$' . number_format((float)$n, 2, ',', '.');
  }
}
if (!function_exists('fmt_qty_ticket')) {
  function fmt_qty_ticket($n, int $dec = 3): string {
    return number_format((float)$n, $dec, ',', '.');
  }
}

function _legacy_has_table(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
  $st->execute([$table]);
  return (bool)$st->fetchColumn();
}

function _legacy_has_col(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
  $st->execute([$table, $col]);
  return (bool)$st->fetchColumn();
}

/**
 * Normaliza unidad (para lógica)
 * - UN / KG / LT / G / ML
 */
function norm_unit(string $u, bool $pesable): string {
  $u = strtoupper(trim($u));
  if ($u === '') return $pesable ? 'KG' : 'UN';

  if (in_array($u, ['UNIDAD','UNIDADES','UNID','UN'], true)) return 'UN';
  if (in_array($u, ['KG','KILO','KILOS','KGS'], true)) return 'KG';
  if (in_array($u, ['LT','LITRO','LITROS','L'], true)) return 'LT';
  if (in_array($u, ['G','GR','GRAMO','GRAMOS'], true)) return 'G';
  if (in_array($u, ['ML','MILI','MILILITRO','MILILITROS'], true)) return 'ML';

  return mb_strimwidth($u, 0, 4, '', 'UTF-8');
}

/**
 * Unidad (para mostrar)
 */
function unit_disp(string $u): string {
  $u = strtoupper(trim($u));
  return match ($u) {
    'UN' => 'UN',
    'KG' => 'kg',
    'LT' => 'l',
    'G'  => 'g',
    'ML' => 'ml',
    default => $u,
  };
}

/**
 * Sufijo para PU en ticket
 * - G/ML: precio por 100 g / 100 ml
 */
function unit_price_suffix(string $u, bool $pesable): string {
  if (!$pesable) return '';
  $u = strtoupper(trim($u));
  return match ($u) {
    'G'  => '/100 g',
    'ML' => '/100 ml',
    'KG' => '/kg',
    'LT' => '/l',
    default => '/' . $u,
  };
}

function label_medio_pago(string $m): string {
  $m = strtoupper(trim($m));
  if ($m === 'MP') return 'Mercado Pago';
  if ($m === 'DEBITO') return 'Débito';
  if ($m === 'CREDITO') return 'Crédito';
  return 'Efectivo';
}

/* =========================
   Config
========================= */
$bizName = config_get($pdo, 'business_name', 'KIOSCO');
$cuit    = config_get($pdo, 'business_cuit', '');
$addr    = config_get($pdo, 'business_address', '');
$phone   = config_get($pdo, 'business_phone', '');
$footer  = config_get($pdo, 'ticket_footer', 'Gracias por su compra');

/* =========================
   Query venta
========================= */
$selectUser  = (has_column($pdo, 'ventas', 'user_id') && has_table($pdo, 'users'));
$selectBruto = has_column($pdo, 'ventas', 'total_bruto');
$selectDescT = has_column($pdo, 'ventas', 'descuento_total');

$sqlVenta = "
  SELECT
    v.id, v.fecha, v.total, v.medio_pago, v.monto_pagado, v.vuelto, v.nota, v.caja_id, c.fecha_apertura
    " . ($selectBruto ? ", v.total_bruto" : "") . "
    " . ($selectDescT ? ", v.descuento_total" : "") . "
    " . ($selectUser ? ", u.username AS cajero" : "") . "
  FROM ventas v
  LEFT JOIN caja_sesiones c ON v.caja_id = c.id
  " . ($selectUser ? "LEFT JOIN users u ON u.id = v.user_id" : "") . "
  WHERE v.id = :id
  LIMIT 1
";
$stmt = $pdo->prepare($sqlVenta);
$stmt->execute([':id' => $ventaId]);
$venta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$venta) {
  http_response_code(404);
  flus_abort(400, 'Venta no encontrada.');
}

/* =========================
   Items
========================= */
$sqlItems = "
  SELECT
    p.codigo, p.nombre, p.unidad_venta, p.es_pesable,
    vi.cantidad, vi.precio, vi.precio_unit_original, vi.precio_unit_final,
    vi.descuento_monto, vi.subtotal
  FROM venta_items vi
  LEFT JOIN productos p ON vi.producto_id = p.id
  WHERE vi.venta_id = :id
  ORDER BY vi.id ASC
";
$stmtIt = $pdo->prepare($sqlItems);
$stmtIt->execute([':id' => $ventaId]);
$items = $stmtIt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* =========================
   Promos (para total desc)
========================= */
$promos = [];
$descPromos = 0.0;

if (has_table($pdo, 'venta_promos')) {
  $stP = $pdo->prepare("
    SELECT promo_tipo, promo_nombre, descripcion, descuento_monto
    FROM venta_promos
    WHERE venta_id = :id
    ORDER BY id ASC
  ");
  $stP->execute([':id' => $ventaId]);
  $promos = $stP->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($promos as $pr) {
    $descPromos += (float)($pr['descuento_monto'] ?? 0);
  }
  $descPromos = round($descPromos, 2);
}

/* =========================
   Totales fallback
========================= */
$brutoCalc = 0.0;
$descItems = 0.0;

foreach ($items as $it) {
  $cantidad = (float)($it['cantidad'] ?? 0);

  $puOriginal = ($it['precio_unit_original'] !== null)
    ? (float)$it['precio_unit_original']
    : (float)($it['precio'] ?? 0);

  $descLinea = (float)($it['descuento_monto'] ?? 0);

  $brutoCalc += $puOriginal * $cantidad;
  $descItems += $descLinea;
}

$brutoCalc = round($brutoCalc, 2);
$descItems = round($descItems, 2);

$totalNeto = round((float)($venta['total'] ?? 0), 2);

$brutoTotal = ($selectBruto && $venta['total_bruto'] !== null)
  ? round((float)$venta['total_bruto'], 2)
  : $brutoCalc;

$descMostrar = ($descPromos > 0.00001) ? $descPromos : $descItems;
if ($selectDescT && $venta['descuento_total'] !== null && $descMostrar < 0.00001) {
  $descMostrar = round((float)$venta['descuento_total'], 2);
}

/* =========================
   Pagos (split) + fallback
========================= */
$pagos = [];
if (has_table($pdo, 'venta_pagos')) {
  $stPag = $pdo->prepare("
    SELECT medio_pago, monto
    FROM venta_pagos
    WHERE venta_id = ?
    ORDER BY id ASC
  ");
  $stPag->execute([$ventaId]);
  $pagos = $stPag->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

if (!$pagos) {
  $pagos = [[
    'medio_pago' => (string)($venta['medio_pago'] ?? 'EFECTIVO'),
    'monto'      => (float)($venta['monto_pagado'] ?? ($venta['total'] ?? 0)),
  ]];
}

$pagadoTotal = 0.0;
foreach ($pagos as $p) $pagadoTotal += (float)($p['monto'] ?? 0);
$pagadoTotal = round($pagadoTotal, 2);

$vuelto = round((float)($venta['vuelto'] ?? 0), 2);

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ticket #<?= (int)$venta['id'] ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="assets/css/ticket.css">

<?php if ($autoPrint): ?>
<script>
window.addEventListener('load', () => {
  setTimeout(() => {
    window.print();
    setTimeout(() => window.close(), 300);
  }, 250);
});
</script>
<?php endif; ?>
</head>

<body data-paper="<?= htmlspecialchars($paper) ?>" data-autoprint="<?= $autoPrint ? '1' : '0' ?>">

<div class="ticket">

  <!-- ENCABEZADO -->
  <div class="t-center">
    <div class="brand"><?= htmlspecialchars($bizName) ?></div>
    <?php if ($cuit): ?>
      <div class="sub">CUIT <?= htmlspecialchars($cuit) ?></div>
    <?php endif; ?>
    <?php if ($addr): ?>
      <div class="sub"><?= htmlspecialchars($addr) ?></div>
    <?php endif; ?>
    <?php if ($phone): ?>
      <div class="sub">Tel: <?= htmlspecialchars($phone) ?></div>
    <?php endif; ?>
  </div>

  <div class="sep"></div>

  <!-- INFO TICKET -->
  <div style="font-size:13px; margin-bottom:10px;">
    <div><strong>Ticket #<?= (int)$venta['id'] ?></strong></div>
    <div><?= date('d/m/Y H:i', strtotime((string)$venta['fecha'])) ?></div>
    <?php if (!empty($venta['caja_id'])): ?>
      <div>
        Caja #<?= (int)$venta['caja_id'] ?>
        <?php if (!empty($venta['cajero'])): ?>
          - <?= htmlspecialchars($venta['cajero']) ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="sep soft"></div>

  <!-- ITEMS -->
  <div class="thead">
    <div>Producto</div>
    <div class="t-right">Cant</div>
    <div class="t-right">Importe</div>
  </div>

  <?php foreach ($items as $it):
    $cantidad = (float)($it['cantidad'] ?? 0);
    $subtotal = (float)($it['subtotal'] ?? 0);

    $puOriginal = ($it['precio_unit_original'] !== null) ? (float)$it['precio_unit_original'] : (float)($it['precio'] ?? 0);
    $puFinal    = ($it['precio_unit_final'] !== null) ? (float)$it['precio_unit_final'] : (float)($it['precio'] ?? 0);

    $descLinea  = (float)($it['descuento_monto'] ?? 0);

    $nombreFull = (string)($it['nombre'] ?? '');
    $codigo     = (string)($it['codigo'] ?? '');

    $isPesable  = ((int)($it['es_pesable'] ?? 0) === 1);
    $unidadNorm = norm_unit((string)($it['unidad_venta'] ?? ''), $isPesable);
    $unidadTxt  = unit_disp($unidadNorm);

    // ===== Cantidad a mostrar =====
    // Importante: en G/ML tu sistema usa "unidad interna = 100" (packs de 100g/100ml).
    // Por eso: 3 => 300g y el precio es por 100g.
    if ($isPesable && ($unidadNorm === 'G' || $unidadNorm === 'ML')) {
      $qtyShow = (int)round($cantidad * 100);         // gramos/ml reales
      $cantTxt = number_format((float)$qtyShow, 0, ',', '.') . ' ' . $unidadTxt;
    } elseif ($isPesable) {
      // KG/LT: si es entero, no imprimimos 3 decimales
      $isInt = abs($cantidad - round($cantidad)) < 0.0005;
      $cantTxt = $isInt
        ? ((string)(int)round($cantidad) . ' ' . $unidadTxt)
        : (fmt_qty_ticket($cantidad, 3) . ' ' . $unidadTxt);
    } else {
      $qtyInt = (int)round($cantidad);
      $cantTxt = $qtyInt . ' ' . $unidadTxt;
    }

    // ===== PU / Lista =====
    $puSuffix = unit_price_suffix($unidadNorm, $isPesable);

    if ($isPesable) {
      $precioTxt      = fmt_money_ticket($puFinal) . $puSuffix;
      $precioListaTxt = fmt_money_ticket($puOriginal) . $puSuffix;
    } else {
      $precioTxt      = fmt_money_ticket($puFinal);
      $precioListaTxt = fmt_money_ticket($puOriginal);
    }

    $hayRebajaPrecio = (abs($puFinal - $puOriginal) > 0.009);
    $hayDescLinea    = ($descLinea > 0.009);

    // Mostrar “meta” solo cuando aporta valor (ticket más corto)
    $qtyIntForMeta = (int)round($cantidad);
    $showPU = false;

    if ($isPesable) {
      $showPU = true; // pesables siempre muestran PU
    } else {
      if ($TICKET_SHOW_UNIT_PRICE_UN || $qtyIntForMeta > 1) $showPU = true;
    }

    $showMeta = $showPU || $hayRebajaPrecio || $hayDescLinea;
    $puLine = $showPU ? ('PU: ' . $precioTxt) : '';
  ?>

  <div class="row">
    <div class="prod">
      <div class="name"><?= htmlspecialchars($nombreFull) ?></div>

      <?php if ($showMeta): ?>
      <div class="meta">
        <?php if ($TICKET_SHOW_CODE && $codigo): ?>
          [<?= htmlspecialchars($codigo) ?>]
        <?php endif; ?>

        <?php if ($puLine): ?>
          <?= htmlspecialchars($puLine) ?>
        <?php endif; ?>

        <?php if ($hayRebajaPrecio): ?>
          <br><small style="opacity:0.82">Lista: <?= htmlspecialchars($precioListaTxt) ?></small>
        <?php endif; ?>

        <?php if ($hayDescLinea): ?>
          <br><small style="opacity:0.85">Desc: -<?= fmt_money_ticket($descLinea) ?></small>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="t-right"><?= htmlspecialchars($cantTxt) ?></div>
    <div class="t-right"><?= fmt_money_ticket($subtotal) ?></div>
  </div>

  <?php endforeach; ?>

  <div class="sep soft"></div>

  <!-- TOTALES -->
  <div class="totals">
    <div class="line">
      <strong>Subtotal:</strong>
      <strong><?= fmt_money_ticket($brutoTotal) ?></strong>
    </div>

    <?php if ($descMostrar > 0.009): ?>
      <div class="line">
        <strong>Descuentos:</strong>
        <strong>-<?= fmt_money_ticket($descMostrar) ?></strong>
      </div>

      <?php if ($TICKET_SHOW_PROMO_DETAILS && count($promos) > 0): ?>
        <div style="margin:8px 0; font-size:12px; opacity:0.9;">
          <?php foreach ($promos as $pr):
            $nom = trim((string)($pr['promo_nombre'] ?? 'Promo'));
            $mon = (float)($pr['descuento_monto'] ?? 0);
          ?>
            <div class="line">
              <span>• <?= htmlspecialchars($nom) ?></span>
              <span>-<?= fmt_money_ticket($mon) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="sep"></div>

  <!-- TOTAL FINAL -->
  <div class="totals">
    <div class="line" style="font-size:16px; font-weight:900;">
      <div>TOTAL:</div>
      <div><?= fmt_money_ticket($totalNeto) ?></div>
    </div>
  </div>

  <div class="sep soft"></div>

  <!-- PAGO -->
  <div style="font-size:13px; line-height:1.6;">
    <div class="ticket-pagos">
      <div><strong>Forma de pago:</strong></div>

      <?php foreach ($pagos as $p): ?>
        <div class="line">
          <span><?= htmlspecialchars(label_medio_pago((string)($p['medio_pago'] ?? 'EFECTIVO'))) ?></span>
          <span><?= fmt_money_ticket((float)($p['monto'] ?? 0)) ?></span>
        </div>
      <?php endforeach; ?>

      <div class="line" style="margin-top:6px;">
        <strong>Pagado:</strong>
        <strong><?= fmt_money_ticket($pagadoTotal) ?></strong>
      </div>

      <div class="line">
        <strong>Vuelto:</strong>
        <strong><?= fmt_money_ticket($vuelto) ?></strong>
      </div>
    </div>
  </div>

  <div class="sep"></div>

  <!-- FOOTER -->
  <div class="foot t-center">
    <?= htmlspecialchars($footer) ?>
  </div>

</div>

<!-- TOOLBAR (no se imprime) -->
<div class="toolbar no-print">
  <label class="paper-label">
    <span>Papel:</span>
    <select id="paperSelect">
      <option value="58" <?= $paper === '58' ? 'selected' : '' ?>>58mm</option>
      <option value="80" <?= $paper === '80' ? 'selected' : '' ?>>80mm</option>
    </select>
  </label>
  <button class="btn-print" id="btnPrint">🖨️ Imprimir</button>
</div>

<script>
document.getElementById('paperSelect')?.addEventListener('change', (e) => {
  const p = e.target.value;
  document.body.dataset.paper = p;

  const url = new URL(window.location.href);
  url.searchParams.set('paper', p);
  window.history.replaceState({}, '', url);
});

document.getElementById('btnPrint')?.addEventListener('click', () => {
  window.focus();
  window.print();
});
</script>

</body>
</html>

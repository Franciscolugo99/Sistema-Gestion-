<?php
// public/ticket.php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_login();

// ------------------------------
// Params
// ------------------------------
$ventaId = (int)($_GET['venta_id'] ?? 0);
if ($ventaId <= 0) $ventaId = (int)($_GET['id'] ?? 0);
if ($ventaId <= 0) {
  http_response_code(400);
  die('ID de venta inválido.');
}

$paper = (string)($_GET['paper'] ?? '80');
$paper = ($paper === '58') ? '58' : '80';

$autoPrint = ((string)($_GET['autoprint'] ?? '') === '1');

// ------------------------------
// Helpers
// ------------------------------
if (!function_exists('fmt_money_ticket')) {
  function fmt_money_ticket($n): string {
    return '$' . number_format((float)$n, 2, ',', '.');
  }
}
if (!function_exists('fmt_qty_ticket')) {
  function fmt_qty_ticket($n, int $dec = 2): string {
    return number_format((float)$n, $dec, ',', '.');
  }
}

function has_table(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
  $st->execute([$table]);
  return (bool)$st->fetchColumn();
}

function has_col(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
  $st->execute([$table, $col]);
  return (bool)$st->fetchColumn();
}

function norm_unit(string $u, bool $pesable): string {
  $u = strtoupper(trim($u));
  if ($u === '') return $pesable ? 'KG' : 'UN';
  if (in_array($u, ['UNIDAD','UNIDADES','UNID','UN'], true)) return 'UN';
  if (in_array($u, ['KG','KILO','KILOS','KGS'], true)) return 'KG';
  return mb_strimwidth($u, 0, 4, '', 'UTF-8');
}

// ------------------------------
// Config
// ------------------------------
$bizName = config_get($pdo, 'business_name', 'KIOSCO');
$cuit    = config_get($pdo, 'business_cuit', '');
$addr    = config_get($pdo, 'business_address', '');
$phone   = config_get($pdo, 'business_phone', '');
$footer  = config_get($pdo, 'ticket_footer', 'Gracias por su compra');

// ------------------------------
// Query venta
// ------------------------------
$selectUser = (has_col($pdo, 'ventas', 'user_id') && has_table($pdo, 'users'));

$sqlVenta = "
  SELECT v.id, v.fecha, v.total, v.medio_pago, v.monto_pagado, v.vuelto, v.nota, v.caja_id, c.fecha_apertura
  " . ($selectUser ? ", u.username AS cajero" : "") . "
  FROM ventas v
  LEFT JOIN caja_sesiones c ON v.caja_id = c.id
  " . ($selectUser ? "LEFT JOIN users u ON u.id = v.user_id" : "") . "
  WHERE v.id = :id LIMIT 1
";
$stmt = $pdo->prepare($sqlVenta);
$stmt->execute([':id' => $ventaId]);
$venta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$venta) {
  http_response_code(404);
  die('Venta no encontrada.');
}

// ------------------------------
// Items
// ------------------------------
$sqlItems = "
  SELECT p.codigo, p.nombre, p.unidad_venta, p.es_pesable,
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

// ------------------------------
// Promos
// ------------------------------
$promos = [];
$descPromos = 0.0;

if (has_table($pdo, 'venta_promos')) {
  $stP = $pdo->prepare("SELECT promo_tipo, promo_nombre, descripcion, descuento_monto FROM venta_promos WHERE venta_id = :id ORDER BY id ASC");
  $stP->execute([':id' => $ventaId]);
  $promos = $stP->fetchAll(PDO::FETCH_ASSOC) ?: [];
  
  foreach ($promos as $pr) {
    $descPromos += (float)($pr['descuento_monto'] ?? 0);
  }
  $descPromos = round($descPromos, 2);
}

// ------------------------------
// Totales
// ------------------------------
$brutoTotal = 0.0;
$descItems  = 0.0;

foreach ($items as $it) {
  $cantidad = (float)($it['cantidad'] ?? 0);
  $puOriginal = ($it['precio_unit_original'] !== null) ? (float)$it['precio_unit_original'] : (float)($it['precio'] ?? 0);
  $descLinea = (float)($it['descuento_monto'] ?? 0);
  
  $brutoTotal += $puOriginal * $cantidad;
  $descItems  += $descLinea;
}

$brutoTotal = round($brutoTotal, 2);
$descItems  = round($descItems, 2);
$totalNeto  = round((float)$venta['total'], 2);
$descMostrar = ($descPromos > 0.00001) ? $descPromos : $descItems;

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
  <div style="font-size:13px; margin-bottom:12px;">
    <div><strong>Ticket #<?= (int)$venta['id'] ?></strong></div>
    <div><?= date('d/m/Y H:i', strtotime((string)$venta['fecha'])) ?></div>
    <?php if (!empty($venta['caja_id'])): ?>
      <div>Caja #<?= (int)$venta['caja_id'] ?>
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
    $puFinal = ($it['precio_unit_final'] !== null) ? (float)$it['precio_unit_final'] : (float)($it['precio'] ?? 0);
    $descLinea = (float)($it['descuento_monto'] ?? 0);
    
    $nombreFull = (string)($it['nombre'] ?? '');
    $codigo = (string)($it['codigo'] ?? '');
    
    $isPesable = ((int)($it['es_pesable'] ?? 0) === 1);
    $unidad = norm_unit((string)($it['unidad_venta'] ?? ''), $isPesable);
    
    if ($isPesable) {
      $cantTxt = fmt_qty_ticket($cantidad, 2) . ' ' . $unidad;
      $precioTxt = fmt_money_ticket($puFinal) . '/' . $unidad;
    } else {
      $cantInt = (int)round($cantidad);
      $cantTxt = $cantInt . ' ' . $unidad;
      $precioTxt = fmt_money_ticket($puFinal);
    }
  ?>
  
  <div class="row">
    <div class="prod">
      <div class="name"><?= htmlspecialchars($nombreFull) ?></div>
      <div class="meta">
        <?php if ($codigo): ?>[<?= htmlspecialchars($codigo) ?>] <?php endif; ?>
        <?= htmlspecialchars($cantTxt) ?> × <?= htmlspecialchars($precioTxt) ?>
        
        <?php if (abs($puFinal - $puOriginal) > 0.009): ?>
          <br><small style="opacity:0.8">Desc. manual: -<?= fmt_money_ticket(($cantidad * $puOriginal) - ($cantidad * $puFinal)) ?></small>
        <?php endif; ?>
      </div>
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
      
      <!-- Descuentos detallados -->
      <?php if (count($promos) > 0): ?>
        <div style="margin:10px 0; padding:8px 0; border-top:1px dashed rgba(0,0,0,0.2); font-size:12px;">
          <div style="margin-bottom:6px; opacity:0.9;"><strong>Descuentos aplicados:</strong></div>
          <?php foreach ($promos as $pr): 
            $nom = trim((string)($pr['promo_nombre'] ?? 'Promo'));
            $des = trim((string)($pr['descripcion'] ?? ''));
            $mon = (float)($pr['descuento_monto'] ?? 0);
          ?>
          <div class="line" style="padding:3px 0;">
            <span style="padding-left:8px; opacity:0.85;">
              • <?= htmlspecialchars($nom) ?>
              <?php if ($des): ?>(<?= htmlspecialchars($des) ?>)<?php endif; ?>
            </span>
            <span>-<?= fmt_money_ticket($mon) ?></span>
          </div>
          <?php endforeach; ?>
          
          <div class="line" style="border-top:1px solid rgba(0,0,0,0.15); margin-top:6px; padding-top:6px;">
            <strong style="padding-left:8px;">Total desc:</strong>
            <strong>-<?= fmt_money_ticket($descMostrar) ?></strong>
          </div>
        </div>
      <?php else: ?>
        <div class="line">
          <strong>Descuentos:</strong>
          <strong>-<?= fmt_money_ticket($descMostrar) ?></strong>
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
    <?php 
      $medio = strtoupper((string)($venta['medio_pago'] ?? ''));
      $medioNombre = $medio;
      if ($medio === 'MP') $medioNombre = 'Mercado Pago';
      elseif ($medio === 'DEBITO') $medioNombre = 'Débito';
      elseif ($medio === 'CREDITO') $medioNombre = 'Crédito';
    ?>
    <div><strong>Medio de pago:</strong> <?= htmlspecialchars($medioNombre) ?></div>
    <div><strong>Pagado:</strong> <?= fmt_money_ticket($venta['monto_pagado'] ?? 0) ?></div>
    <div><strong>Vuelto:</strong> <?= fmt_money_ticket($venta['vuelto'] ?? 0) ?></div>
  </div>

  <?php if (!empty($venta['nota'])): ?>
    <div class="sep soft"></div>
    <div style="font-size:12px; opacity:0.85;">
      <strong>Nota:</strong> <?= htmlspecialchars(trim((string)$venta['nota'])) ?>
    </div>
  <?php endif; ?>

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
// Cambiar tamaño papel
document.getElementById('paperSelect')?.addEventListener('change', (e) => {
  const p = e.target.value;
  document.body.dataset.paper = p;
  
  const url = new URL(window.location.href);
  url.searchParams.set('paper', p);
  window.history.replaceState({}, '', url);
});

// Botón imprimir
document.getElementById('btnPrint')?.addEventListener('click', () => {
  window.focus();
  window.print();
});
</script>

</body>
</html>
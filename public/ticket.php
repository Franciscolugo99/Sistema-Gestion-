<?php
// public/ticket.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php';

$pdo = getPDO();

// ------------------------------
// Params
// ------------------------------
$ventaId = (int)($_GET['venta_id'] ?? 0);
if ($ventaId <= 0) $ventaId = (int)($_GET['id'] ?? 0); // compat caja.js
if ($ventaId <= 0) {
  http_response_code(400);
  die('ID de venta inválido.');
}

$paper = (string)($_GET['paper'] ?? '80');
$paper = ($paper === '58') ? '58' : '80';

$autoPrint = ((string)($_GET['autoprint'] ?? '') === '1');

// ------------------------------
// Helpers locales del ticket (evita redeclare)
// ------------------------------
if (!function_exists('fmt_money_ticket')) {
  function fmt_money_ticket($n): string {
    return '$ ' . number_format((float)$n, 2, ',', '.');
  }
}
if (!function_exists('fmt_qty_ticket')) {
  function fmt_qty_ticket($n, int $dec = 3): string {
    return number_format((float)$n, $dec, ',', '.');
  }
}

// ------------------------------
// Helpers DB schema (no romper si falta algo)
// ------------------------------
function has_table(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
    LIMIT 1
  ");
  $st->execute([$table]);
  return (bool)$st->fetchColumn();
}
function has_col(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
    LIMIT 1
  ");
  $st->execute([$table, $col]);
  return (bool)$st->fetchColumn();
}

// ------------------------------
// Config negocio (desde DB)
// ------------------------------
$bizName = config_get($pdo, 'business_name', 'KIOSCO');
$cuit    = config_get($pdo, 'business_cuit', '');
$addr    = config_get($pdo, 'business_address', '');
$phone   = config_get($pdo, 'business_phone', '');
$footer  = config_get($pdo, 'ticket_footer', 'Gracias por su compra');

// ------------------------------
// Layout por papel
// ------------------------------
if ($paper === '58') {
  $ticketWidthPx = 200;
  $nameWidth     = 14;
  $qtyWidth      = 10;
  $subWidth      = 12;
  $lineWidth     = 32;
} else {
  $ticketWidthPx = 280;
  $nameWidth     = 22;
  $qtyWidth      = 12;
  $subWidth      = 14;
  $lineWidth     = 42;
}
$hr = str_repeat('-', $lineWidth);

// ------------------------------
// Helpers de impresión (no cortar líneas)
// ------------------------------
function norm_unit(string $u, bool $pesable): string {
  $u = strtoupper(trim($u));
  if ($u === '') return $pesable ? 'KG' : 'UN';

  if (in_array($u, ['UNIDAD','UNIDADES','UNID','UN'], true)) return 'UN';
  if (in_array($u, ['KG','KILO','KILOS','KGS'], true)) return 'KG';

  if (mb_strlen($u, 'UTF-8') > 4) $u = mb_strimwidth($u, 0, 4, '', 'UTF-8');
  return $u;
}

function print_wrapped(string $s, int $lineWidth): void {
  $s = rtrim($s);
  if ($s === '') { echo "\n"; return; }

  if (mb_strlen($s, 'UTF-8') <= $lineWidth) {
    echo $s . "\n";
    return;
  }

  $rest = $s;
  while ($rest !== '') {
    $chunk = mb_strimwidth($rest, 0, $lineWidth, '', 'UTF-8');
    echo rtrim($chunk) . "\n";
    $rest = ltrim(mb_substr($rest, mb_strlen($chunk, 'UTF-8'), null, 'UTF-8'));
  }
}

// ==============================
// 1) CABECERA DE LA VENTA
// ==============================
$selectUser = (has_col($pdo, 'ventas', 'user_id') && has_table($pdo, 'users'));

$sqlVenta = "
  SELECT
    v.id, v.fecha, v.total, v.medio_pago, v.monto_pagado, v.vuelto, v.nota,
    v.caja_id,
    c.fecha_apertura
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
  die('Venta no encontrada.');
}

// ==============================
// 2) ITEMS DE LA VENTA
// ==============================
$sqlItems = "
  SELECT
    p.codigo,
    p.nombre,
    p.unidad_venta,
    p.es_pesable,
    vi.cantidad,
    vi.precio,
    vi.precio_unit_original,
    vi.precio_unit_final,
    vi.descuento_monto,
    vi.subtotal
  FROM venta_items vi
  LEFT JOIN productos p ON vi.producto_id = p.id
  WHERE vi.venta_id = :id
  ORDER BY vi.id ASC
";
$stmtIt = $pdo->prepare($sqlItems);
$stmtIt->execute([':id' => $ventaId]);
$items = $stmtIt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ==============================
// 3) PROMOS APLICADAS (venta_promos) - si existe
// ==============================
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

// ==============================
// 4) TOTALES (desde items)
// ==============================
$brutoTotal = 0.0;
$descItems  = 0.0;

foreach ($items as $it) {
  $cantidad = (float)($it['cantidad'] ?? 0);

  $puOriginal = ($it['precio_unit_original'] !== null)
    ? (float)$it['precio_unit_original']
    : (float)($it['precio'] ?? 0);

  $descLinea = (float)($it['descuento_monto'] ?? 0);

  $brutoTotal += $puOriginal * $cantidad;
  $descItems  += $descLinea;
}

$brutoTotal = round($brutoTotal, 2);
$descItems  = round($descItems, 2);

// Autoridad: ventas.total
$totalNeto = round((float)$venta['total'], 2);

// Descuento a mostrar:
// - si existe venta_promos, usamos eso (auditoría real)
// - si no, fallback a descuento por items
$descMostrar = ($descPromos > 0.00001) ? $descPromos : $descItems;

?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Ticket #<?= (int)$venta['id'] ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
*{box-sizing:border-box}
body{
  font-family:"Fira Mono","Consolas",monospace;
  font-size: <?= $paper === '58' ? '12px' : '13px' ?>;
  margin:0; padding:0; background:#fff;
}
.ticket{
  width: <?= (int)$ticketWidthPx ?>px;
  margin:0 auto;
  white-space:pre;
}
@media print{
  body{margin:0}
  .ticket{margin:0 auto}
}
</style>

<?php if ($autoPrint): ?>
<script>
window.addEventListener('load', () => {
  window.print();
  setTimeout(() => window.close(), 600);
});
</script>
<?php endif; ?>
</head>

<body>
<pre class="ticket"><?php
// Encabezado
echo trim((string)($bizName ?: "KIOSCO")) . "\n";
if ($cuit)  echo "CUIT " . trim((string)$cuit) . "\n";
if ($addr)  echo trim((string)$addr) . "\n";
if ($phone) echo "Tel: " . trim((string)$phone) . "\n";
echo "\n";

echo "TICKET #".(int)$venta['id']."\n";
echo date('Y-m-d H:i:s', strtotime((string)$venta['fecha']))."\n";

if (!empty($venta['cajero'])) {
  print_wrapped("Cajero: " . (string)$venta['cajero'], $lineWidth);
}

if (!empty($venta['caja_id'])) {
  $line = "Caja: #".(int)$venta['caja_id'];
  if (!empty($venta['fecha_apertura'])) {
    $line .= " (apertura ".date('Y-m-d H:i', strtotime((string)$venta['fecha_apertura'])).")";
  }
  print_wrapped($line, $lineWidth);
}

echo $hr."\n";
echo str_pad("Prod", $nameWidth)." ".str_pad("Cant", $qtyWidth, ' ', STR_PAD_LEFT)." ".str_pad("Importe", $subWidth, ' ', STR_PAD_LEFT)."\n";
echo $hr."\n";

// Items
foreach ($items as $it) {
  $cantidad = (float)($it['cantidad'] ?? 0);
  $subtotal = (float)($it['subtotal'] ?? 0);

  $puOriginal = ($it['precio_unit_original'] !== null)
    ? (float)$it['precio_unit_original']
    : (float)($it['precio'] ?? 0);

  $puFinal = ($it['precio_unit_final'] !== null)
    ? (float)$it['precio_unit_final']
    : (float)($it['precio'] ?? 0);

  $descLinea = (float)($it['descuento_monto'] ?? 0);

  $nombreFull = (string)($it['nombre'] ?? '');
  $nombre = mb_strimwidth($nombreFull, 0, $nameWidth, '…', 'UTF-8');

  $isPesable = ((int)($it['es_pesable'] ?? 0) === 1);
  $unidadRaw = (string)($it['unidad_venta'] ?? '');
  $unidad    = norm_unit($unidadRaw, $isPesable);

  $cantTxt = $isPesable
    ? fmt_qty_ticket($cantidad, 3).' '.$unidad
    : number_format($cantidad, 0, ',', '.').' '.$unidad;

  $subTxt = fmt_money_ticket($subtotal);

  echo str_pad($nombre, $nameWidth)." ".
       str_pad($cantTxt, $qtyWidth, ' ', STR_PAD_LEFT)." ".
       str_pad($subTxt, $subWidth, ' ', STR_PAD_LEFT)."\n";

  // línea detalle: "codigo  x precio"
  $codigo = (string)($it['codigo'] ?? '');
  if ($codigo !== '') {
    $plShow = fmt_money_ticket($puOriginal);
    $pfShow = fmt_money_ticket($puFinal);

    // Ej: "  779....  2 x $ 1.200,00"
    $line1 = "  {$codigo}  " . fmt_qty_ticket($cantidad, $isPesable ? 3 : 0) . " x {$pfShow}";
    print_wrapped($line1, $lineWidth);

    // Si hay diferencia contra lista, lo mostramos tipo súper
    if (abs($puFinal - $puOriginal) > 0.009) {
      print_wrapped("           Lista: {$plShow}  Final: {$pfShow}", $lineWidth);
    }

    // Si hay descuento en la línea
    if ($descLinea > 0.009) {
      print_wrapped("           Descuento item: -".fmt_money_ticket($descLinea), $lineWidth);
    }
  }
}

echo $hr."\n";

// Promos (resumen pro)
if (is_array($promos) && count($promos) > 0) {
  print_wrapped("DESCUENTOS / PROMOS:", $lineWidth);
  foreach ($promos as $pr) {
    $nom = trim((string)($pr['promo_nombre'] ?? 'Promo'));
    $tip = trim((string)($pr['promo_tipo'] ?? ''));
    $des = trim((string)($pr['descripcion'] ?? ''));
    $mon = (float)($pr['descuento_monto'] ?? 0);

    $label = $nom;
    if ($tip !== '') $label = "{$nom} ({$tip})";
    if ($des !== '') $label .= " - {$des}";

    print_wrapped(" - {$label}", $lineWidth);
    print_wrapped("   Ahorro: -".fmt_money_ticket($mon), $lineWidth);
  }
  echo $hr."\n";
}

// Totales (formato típico)
print_wrapped("SUBTOTAL: ".fmt_money_ticket($brutoTotal), $lineWidth);
if ($descMostrar > 0.009) {
  print_wrapped("DESCUENTOS: -".fmt_money_ticket($descMostrar), $lineWidth);
}
print_wrapped("TOTAL: ".fmt_money_ticket($totalNeto), $lineWidth);
echo $hr."\n";

$medio = strtoupper((string)($venta['medio_pago'] ?? ''));
print_wrapped("Medio: {$medio}", $lineWidth);
print_wrapped("Pago: ".fmt_money_ticket($venta['monto_pagado'] ?? 0), $lineWidth);
print_wrapped("Vuelto: ".fmt_money_ticket($venta['vuelto'] ?? 0), $lineWidth);

if (!empty($venta['nota'])) {
  echo $hr."\n";
  print_wrapped("Nota: ".trim((string)$venta['nota']), $lineWidth);
}

echo $hr."\n";
print_wrapped(trim((string)($footer ?: "Gracias por su compra")), $lineWidth);
?></pre>
</body>
</html>

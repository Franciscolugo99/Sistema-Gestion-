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

  // si es muy largo, recortamos
  if (mb_strlen($u, 'UTF-8') > 4) $u = mb_strimwidth($u, 0, 4, '', 'UTF-8');
  return $u;
}

function print_wrapped(string $s, int $lineWidth): void {
  $s = rtrim($s);
  if ($s === '') { echo "\n"; return; }

  // si entra, listo
  if (mb_strlen($s, 'UTF-8') <= $lineWidth) {
    echo $s . "\n";
    return;
  }

  // partir en pedazos para que nunca "pise" el ancho
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
$sqlVenta = "
  SELECT
    v.id, v.fecha, v.total, v.medio_pago, v.monto_pagado, v.vuelto, v.nota,
    v.caja_id,
    c.fecha_apertura
  FROM ventas v
  LEFT JOIN caja_sesiones c ON v.caja_id = c.id
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
$items = $stmtIt->fetchAll(PDO::FETCH_ASSOC);

// ==============================
// 3) TOTALES (desde items)
// ==============================
$brutoTotal = 0.0;
$descTotal  = 0.0;

foreach ($items as $it) {
  $cantidad = (float)($it['cantidad'] ?? 0);
  $subtotal = (float)($it['subtotal'] ?? 0);

  $puOriginal = ($it['precio_unit_original'] !== null)
    ? (float)$it['precio_unit_original']
    : (float)($it['precio'] ?? 0);

  $descLinea = (float)($it['descuento_monto'] ?? 0);

  $brutoTotal += $puOriginal * $cantidad;
  $descTotal  += $descLinea;
}

$brutoTotal = round($brutoTotal, 2);
$descTotal  = round($descTotal, 2);

// Autoridad: ventas.total (por si mañana hay recargos/ajustes)
$totalNeto  = round((float)$venta['total'], 2);

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

echo "Ticket #".(int)$venta['id']."\n";
echo date('Y-m-d H:i:s', strtotime((string)$venta['fecha']))."\n";

if (!empty($venta['caja_id'])) {
  echo "Caja: #".(int)$venta['caja_id'];
  if (!empty($venta['fecha_apertura'])) {
    echo " (apertura ".date('Y-m-d H:i', strtotime((string)$venta['fecha_apertura'])).")";
  }
  echo "\n";
}

echo $hr."\n";
echo str_pad("Prod", $nameWidth)." ".str_pad("Cant", $qtyWidth, ' ', STR_PAD_LEFT)." ".str_pad("Subt", $subWidth, ' ', STR_PAD_LEFT)."\n";
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

  // Detalle por código (en 2 líneas para que NUNCA se corte)
  $codigo = (string)($it['codigo'] ?? '');
  if ($codigo !== '') {

    $plShow = fmt_money_ticket($puOriginal);
    $pfShow = fmt_money_ticket($puFinal);
    $dlShow = fmt_money_ticket($descLinea);

    // Línea 1: código + lista
    print_wrapped("  {$codigo}  L {$plShow}/{$unidad}", $lineWidth);

    // Línea 2: final + desc (solo si aplica)
    if ($descLinea > 0.009 || abs($puFinal - $puOriginal) > 0.009) {
      // si no hay desc pero hay final distinto, igual mostramos F
      $line2 = "           F {$pfShow}/{$unidad}";
      if ($descLinea > 0.009) $line2 .= "  D {$dlShow}";
      print_wrapped($line2, $lineWidth);
    }
  }
}

echo $hr."\n";

// Totales
if ($descTotal > 0.009) {
  echo "Bruto          ".str_pad(fmt_money_ticket($brutoTotal), $subWidth, ' ', STR_PAD_LEFT)."\n";
  echo "Descuento      ".str_pad('- '.fmt_money_ticket($descTotal), $subWidth, ' ', STR_PAD_LEFT)."\n";
}
echo "TOTAL A COBRAR ".str_pad(fmt_money_ticket($totalNeto), $subWidth, ' ', STR_PAD_LEFT)."\n";
echo $hr."\n";

$medio = strtoupper((string)($venta['medio_pago'] ?? ''));
echo "Medio          {$medio}\n";
echo "Pago           ".fmt_money_ticket($venta['monto_pagado'] ?? 0)."\n";
echo "Vuelto         ".fmt_money_ticket($venta['vuelto'] ?? 0)."\n";

if (!empty($venta['nota'])) {
  echo $hr."\n";
  echo "Nota: ".trim((string)$venta['nota'])."\n";
}

echo $hr."\n";
echo trim((string)($footer ?: "Gracias por su compra")) . "\n";
?></pre>
</body>
</html>

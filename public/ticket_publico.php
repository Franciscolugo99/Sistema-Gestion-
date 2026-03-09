<?php
// public/ticket_publico.php - Acceso público a tickets con token de seguridad
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/* =========================
   Validar parámetros
========================= */
$id = (int)($_GET['id'] ?? 0);
$token = trim((string)($_GET['token'] ?? ''));
$ts = (int)($_GET['ts'] ?? 0);
$paper = in_array($_GET['paper'] ?? '80', ['58', '80']) ? $_GET['paper'] : '80';

if ($id <= 0 || $token === '' || $ts <= 0 || !ctype_xdigit($token) || !in_array(strlen($token), [16, 32], true)) {
  flus_abort(400, 'Parámetros inválidos');
}

/* =========================
   Validar token
========================= */
function getAppSecret(): string {
  if (!defined('APP_SECRET')) {
    throw new RuntimeException('APP_SECRET no está definido. Configurá un secreto fuerte para habilitar tickets públicos.');
  }
  $secret = (string)APP_SECRET;
  if (strlen($secret) < 16 || $secret === 'flus-default-secret-change-me' || strpos($secret, 'change-me') !== false) {
    throw new RuntimeException('APP_SECRET es débil o es un placeholder. Configurá un secreto fuerte (>= 16 chars) para habilitar tickets públicos.');
  }
  return $secret;
}

function ticketTokenTtlSeconds(): int {
  if (defined('TICKET_TOKEN_TTL_SECONDS')) {
    $v = (int)TICKET_TOKEN_TTL_SECONDS;
    return $v > 0 ? $v : 7 * 24 * 60 * 60;
  }
  return 7 * 24 * 60 * 60;
}

function validateTicketToken(int $ventaId, int $ts, string $token): bool {
  $now = time();
  if ($ts > ($now + 300)) return false; // no futuro
  if (($now - $ts) > ticketTokenTtlSeconds()) return false;

  try {
    $h = hash_hmac('sha256', "ticket-{$ventaId}-{$ts}", getAppSecret());
    $expected32 = substr($h, 0, 32);
    $expected16 = substr($h, 0, 16);
  } catch (Throwable $e) {
    return false;
  }
  return hash_equals($expected32, $token) || hash_equals($expected16, $token);
}

if (!validateTicketToken($id, $ts, $token)) {
  flus_abort(403, 'Token inválido o expirado');
}

/* =========================
   Obtener venta
========================= */
$pdo = getPDO();

$stmt = $pdo->prepare("
  SELECT v.*, 
         COALESCE(c.nombre, 'Consumidor Final') as cliente_nombre,
         c.documento as cliente_documento
  FROM ventas v
  LEFT JOIN clientes c ON c.id = v.cliente_id
  WHERE v.id = ?
  LIMIT 1
");
$stmt->execute([$id]);
$venta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$venta) {
  flus_abort(404, 'Venta no encontrada');
}

$ventaAnulada = function_exists('flus_sale_is_annulled')
  ? flus_sale_is_annulled($venta)
  : (strtoupper((string)($venta['estado'] ?? 'EMITIDA')) === 'ANULADA');

/* =========================
   Obtener items
========================= */
$stmt = $pdo->prepare("
  SELECT vi.cantidad, vi.precio, vi.subtotal, p.nombre, p.codigo
  FROM venta_items vi
  JOIN productos p ON p.id = vi.producto_id
  WHERE vi.venta_id = ?
  ORDER BY vi.id
");
$stmt->execute([$id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   Obtener config del negocio
========================= */
$config = [
  'business_name' => 'Mi Negocio',
  'business_address' => '',
  'business_phone' => '',
  'business_cuit' => '',
  'ticket_footer' => 'Gracias por su compra'
];

try {
  $st = $pdo->query("SELECT k, v FROM app_config");
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    if (isset($config[$row['k']])) {
      $config[$row['k']] = $row['v'];
    }
  }
} catch (Exception $e) {}

/* =========================
   Medios de pago
========================= */
$medioPago = $venta['medio_pago'] ?? 'N/A';
try {
  $st = $pdo->prepare("SELECT 1 FROM venta_pagos WHERE venta_id = ? LIMIT 1");
  $st->execute([$id]);
  if ($st->fetchColumn()) {
    $st = $pdo->prepare("SELECT GROUP_CONCAT(DISTINCT UPPER(medio_pago) SEPARATOR ' + ') FROM venta_pagos WHERE venta_id = ?");
    $st->execute([$id]);
    $medioPago = $st->fetchColumn() ?: $medioPago;
  }
} catch (Exception $e) {}

/* =========================
   Helpers
========================= */
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n) { return '$ ' . number_format((float)$n, 2, ',', '.'); }

$ticketNum = str_pad((string)$id, 6, '0', STR_PAD_LEFT);
$fecha = date('d/m/Y H:i', strtotime($venta['fecha']));
$width = $paper === '58' ? '48mm' : '72mm';
$fontSize = $paper === '58' ? '10px' : '12px';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Ticket #<?= $ticketNum ?></title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    
    body {
      font-family: 'Courier New', monospace;
      font-size: <?= $fontSize ?>;
      background: #f5f5f5;
      min-height: 100vh;
      display: flex;
      justify-content: center;
      padding: 20px;
    }
    
    .ticket-container {
      background: white;
      width: <?= $width ?>;
      max-width: 100%;
      padding: 12px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    .header {
      text-align: center;
      border-bottom: 1px dashed #333;
      padding-bottom: 10px;
      margin-bottom: 10px;
    }
    
    .business-name {
      font-size: 1.3em;
      font-weight: bold;
      margin-bottom: 4px;
    }
    
    .business-info {
      font-size: 0.85em;
      color: #555;
      line-height: 1.4;
    }
    
    .ticket-num {
      font-size: 1.1em;
      font-weight: bold;
      margin: 10px 0;
    }
    
    .meta {
      font-size: 0.9em;
      margin-bottom: 10px;
    }
    
    .meta-row {
      display: flex;
      justify-content: space-between;
      margin: 2px 0;
    }
    
    .items {
      border-top: 1px dashed #333;
      border-bottom: 1px dashed #333;
      padding: 10px 0;
      margin: 10px 0;
    }
    
    .item {
      margin: 6px 0;
    }
    
    .item-name {
      font-weight: 500;
    }
    
    .item-detail {
      display: flex;
      justify-content: space-between;
      font-size: 0.9em;
      color: #555;
      margin-left: 10px;
    }
    
    .totals {
      text-align: right;
      padding: 10px 0;
      border-bottom: 1px dashed #333;
    }
    
    .total-row {
      display: flex;
      justify-content: space-between;
      margin: 4px 0;
    }
    
    .total-final {
      font-size: 1.4em;
      font-weight: bold;
      margin-top: 8px;
      padding-top: 8px;
      border-top: 2px solid #333;
    }
    
    .footer {
      text-align: center;
      margin-top: 15px;
      font-size: 0.85em;
      color: #555;
    }
    
    .footer p {
      margin: 4px 0;
    }
    
    .estado-anulada {
      background: #fecaca;
      color: #b91c1c;
      text-align: center;
      padding: 8px;
      font-weight: bold;
      margin: 10px 0;
      border-radius: 4px;
    }
    
    @media print {
      body { background: white; padding: 0; }
      .ticket-container { box-shadow: none; }
    }
    
    @media (max-width: 400px) {
      body { padding: 10px; }
      .ticket-container { width: 100%; }
    }
  </style>
</head>
<body>
  <div class="ticket-container">
    <!-- Header -->
    <div class="header">
      <div class="business-name"><?= h($config['business_name']) ?></div>
      <div class="business-info">
        <?php if ($config['business_address']): ?>
          <?= h($config['business_address']) ?><br>
        <?php endif; ?>
        <?php if ($config['business_phone']): ?>
          Tel: <?= h($config['business_phone']) ?><br>
        <?php endif; ?>
        <?php if ($config['business_cuit']): ?>
          CUIT: <?= h($config['business_cuit']) ?>
        <?php endif; ?>
      </div>
    </div>
    
    <!-- Ticket Number -->
    <div class="ticket-num">TICKET #<?= $ticketNum ?></div>
    
    <!-- Estado anulada -->
    <?php if ($ventaAnulada): ?>
      <div class="estado-anulada">*** ANULADA ***</div>
    <?php endif; ?>
    
    <!-- Meta -->
    <div class="meta">
      <div class="meta-row">
        <span>Fecha:</span>
        <span><?= $fecha ?></span>
      </div>
      <div class="meta-row">
        <span>Cliente:</span>
        <span><?= h($venta['cliente_nombre']) ?></span>
      </div>
      <div class="meta-row">
        <span>Medio:</span>
        <span><?= h($medioPago) ?></span>
      </div>
    </div>
    
    <!-- Items -->
    <div class="items">
      <?php foreach ($items as $item): 
        $cant = (float)$item['cantidad'];
        $cantStr = ($cant == (int)$cant) ? (int)$cant : number_format($cant, 3, ',', '');
      ?>
        <div class="item">
          <div class="item-name"><?= h($item['nombre']) ?></div>
          <div class="item-detail">
            <span><?= $cantStr ?> x <?= money($item['precio']) ?></span>
            <span><?= money($item['subtotal']) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    
    <!-- Totals -->
    <div class="totals">
      <?php 
      $descuento = (float)($venta['descuento_total'] ?? $venta['descuento_monto'] ?? 0);
      $subtotal = (float)$venta['total'] + $descuento;
      ?>
      
      <?php if ($descuento > 0): ?>
        <div class="total-row">
          <span>Subtotal:</span>
          <span><?= money($subtotal) ?></span>
        </div>
        <div class="total-row" style="color:#22c55e;">
          <span>Descuento:</span>
          <span>-<?= money($descuento) ?></span>
        </div>
      <?php endif; ?>
      
      <div class="total-row total-final">
        <span>TOTAL:</span>
        <span><?= money($venta['total']) ?></span>
      </div>
    </div>
    
    <!-- Footer -->
    <div class="footer">
      <?php if ($config['ticket_footer']): ?>
        <p><strong><?= h($config['ticket_footer']) ?></strong></p>
      <?php endif; ?>
      <p style="margin-top:10px; font-size:0.8em; color:#888;">
        Ticket generado el <?= date('d/m/Y H:i') ?>
      </p>
    </div>
  </div>
</body>
</html>

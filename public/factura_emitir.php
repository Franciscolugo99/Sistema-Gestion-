<?php
// public/factura_emitir.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/../src/facturacion_lib.php';

$pdo = getPDO();

$ventaId = isset($_GET['venta_id']) ? (int)$_GET['venta_id'] : 0;
if ($ventaId <= 0) {
  header('Location: ventas.php');
  exit;
}

/**
 * ✅ MODO RECOMENDADO:
 * Si no viene cliente_id, mandamos a la pantalla para elegir cliente (factura_nueva.php)
 * así evitás el cliente fijo 1.
 */
$clienteId = isset($_GET['cliente_id']) ? (int)$_GET['cliente_id'] : 0;
if ($clienteId <= 0) {
  header('Location: factura_nueva.php?venta_id=' . $ventaId);
  exit;
}

try {
  // 1) Validar venta existe
  $st = $pdo->prepare("SELECT id FROM ventas WHERE id = ? LIMIT 1");
  $st->execute([$ventaId]);
  if (!$st->fetch()) {
    throw new Exception("Venta inexistente.");
  }

  // 2) Evitar duplicar facturas por refresh / doble click
  $st = $pdo->prepare("
    SELECT id
    FROM facturas
    WHERE venta_id = ?
    ORDER BY id DESC
    LIMIT 1
  ");
  $st->execute([$ventaId]);
  $ya = $st->fetch(PDO::FETCH_ASSOC);

  if ($ya) {
    // Ya está facturada: volver al detalle con OK (sin volver a emitir)
    header("Location: venta_detalle.php?id={$ventaId}&fact_ok=1");
    exit;
  }

  // 3) Validar cliente existe y activo
  $st = $pdo->prepare("SELECT id FROM clientes WHERE id = ? AND activo = 1 LIMIT 1");
  $st->execute([$clienteId]);
  if (!$st->fetch()) {
    throw new Exception("Cliente inválido o inactivo.");
  }

  // 4) Emitir usando tu lib
  $facturaId = crearFacturaDesdeVenta($ventaId, $clienteId);

  // Volvemos al detalle de la venta con mensaje OK
  header("Location: venta_detalle.php?id={$ventaId}&fact_ok=1");
  exit;

} catch (Throwable $e) {
  $msg = $e->getMessage();
  header("Location: venta_detalle.php?id={$ventaId}&fact_error=" . urlencode($msg));
  exit;
}

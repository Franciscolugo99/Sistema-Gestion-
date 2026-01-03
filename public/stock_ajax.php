<?php
// public/stock_ajax.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('editar_stock');

header('Content-Type: application/json; charset=utf-8');

$pdo = getPDO();

function json_ok(array $data = []): void {
  echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}
function json_fail(string $msg, int $code = 400): void {
  http_response_code($code);
  echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_fail('Método no permitido', 405);

  if (!function_exists('csrf_verify') || !csrf_verify($_POST['csrf_token'] ?? null)) {
    json_fail('CSRF inválido. Recargá y probá de nuevo.', 403);
  }

  $action = (string)($_POST['action'] ?? '');
  if ($action !== 'ajustar') json_fail('Acción no válida', 400);

  $producto_id = (int)($_POST['producto_id'] ?? 0);
  $tipo        = (string)($_POST['tipo'] ?? '');
  $cantidad    = (float)($_POST['cantidad'] ?? 0);
  $motivo      = trim((string)($_POST['motivo'] ?? ''));

  if ($producto_id <= 0) throw new Exception('ID de producto inválido');

  $tipos_permitidos = ['entrada','salida','ajuste_pos','ajuste_neg','perdida'];
  if (!in_array($tipo, $tipos_permitidos, true)) throw new Exception('Tipo inválido');

  if ($cantidad <= 0) throw new Exception('La cantidad debe ser mayor a 0');

  // Normalizar tipo → tipo_mov BD + cambio
  $tipo_mov = 'AJUSTE_POSITIVO';
  $cambio   = +$cantidad;

  switch ($tipo) {
    case 'entrada':
    case 'ajuste_pos':
      $tipo_mov = 'AJUSTE_POSITIVO';
      $cambio   = +$cantidad;
      break;

    case 'salida':
      $tipo_mov = 'AJUSTE_NEGATIVO';
      $cambio   = -$cantidad;
      if ($motivo === '') $motivo = 'Salida manual';
      break;

    case 'ajuste_neg':
      $tipo_mov = 'AJUSTE_NEGATIVO';
      $cambio   = -$cantidad;
      break;

    case 'perdida':
      $tipo_mov = 'AJUSTE_NEGATIVO';
      $cambio   = -$cantidad;
      if ($motivo === '') $motivo = 'Pérdida/Rotura/Vencimiento';
      break;
  }

  $pdo->beginTransaction();

  // Lock producto
  $stmt = $pdo->prepare("SELECT id, stock, es_pesable FROM productos WHERE id = ? FOR UPDATE");
  $stmt->execute([$producto_id]);
  $p = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$p) throw new Exception('Producto no encontrado');

  $stockActual = (float)$p['stock'];
  $esPesable   = function_exists('is_pesable_row') ? is_pesable_row($p) : (bool)($p['es_pesable'] ?? false);

  $nuevoStock = $stockActual + $cambio;
  if ($nuevoStock < 0) throw new Exception('El stock no puede ser negativo');

  $upd = $pdo->prepare("UPDATE productos SET stock = ? WHERE id = ?");
  $upd->execute([$nuevoStock, $producto_id]);

  // ✅ movimientos_stock: (id, venta_id, fecha, producto_id, tipo, cantidad, referencia_venta_id, referencia_compra_id, comentario)
  $ins = $pdo->prepare("
    INSERT INTO movimientos_stock
      (venta_id, fecha, producto_id, tipo, cantidad, referencia_venta_id, referencia_compra_id, comentario)
    VALUES
      (NULL, NOW(), ?, ?, ?, NULL, NULL, ?)
  ");
  $ins->execute([$producto_id, $tipo_mov, $cantidad, $motivo]);

  $pdo->commit();

  json_ok([
    'message' => 'Stock actualizado correctamente',
    'data' => [
      'stock_anterior' => function_exists('format_qty') ? format_qty($stockActual, $esPesable) : $stockActual,
      'stock_nuevo'    => function_exists('format_qty') ? format_qty($nuevoStock, $esPesable) : $nuevoStock,
      'cambio'         => function_exists('format_qty') ? format_qty($cambio, $esPesable) : $cambio,
    ]
  ]);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  json_fail($e->getMessage(), 400);
}

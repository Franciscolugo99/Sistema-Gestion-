<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../auth.php';
require_login();

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../lib/csrf.php';

function json_ok(array $data = []): void {
  echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
  exit;
}
function json_fail(string $msg, int $code = 400, array $extra = []): void {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
  exit;
}

/** Permiso opcional (si existe tu helper) */
function require_perm(string $perm): void {
  if (function_exists('user_has_permission') && !user_has_permission($perm)) {
    json_fail('No autorizado', 403);
  }
}

/** Leer JSON body (si viene) */
$raw  = file_get_contents('php://input');
$body = [];
if (is_string($raw) && trim($raw) !== '') {
  $j = json_decode($raw, true);
  if (is_array($j)) $body = $j;
}

$action = (string)($_GET['action'] ?? ($body['action'] ?? ($_POST['action'] ?? '')));

try {
  $pdo = getPDO();

  /* =========================================================
     1) Buscar producto por código (solo lectura)
  ========================================================= */
  if ($action === 'buscar_producto') {
    require_perm('realizar_ventas');

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
      json_fail('Método no permitido', 405);
    }

    $codigo = (string)($_GET['codigo'] ?? '');
    $codigo = trim($codigo);

    if ($codigo === '') {
      json_fail('Código inválido', 422);
    }

    $stmt = $pdo->prepare("
      SELECT id, codigo, nombre, precio, stock
      FROM productos
      WHERE codigo = ? AND activo = 1
      LIMIT 1
    ");
    $stmt->execute([$codigo]);
    $prod = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($prod) json_ok(['producto' => $prod]);
    json_fail('Producto no encontrado o inactivo', 404);
  }

  /* =========================================================
     2) Registrar venta desde CAJA (modifica datos => POST + CSRF)
  ========================================================= */
  if ($action === 'registrar_venta') {
    require_perm('realizar_ventas');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
      json_fail('Método no permitido', 405);
    }

    // CSRF: aceptar en header X-CSRF-Token o en JSON/body como "csrf"
    $csrf = (string)($body['csrf'] ?? ($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
    if (!csrf_check($csrf)) {
      json_fail('CSRF inválido o ausente', 403);
    }

    if (!$body) {
      json_fail('JSON inválido', 400);
    }

    $items        = $body['items'] ?? [];
    $medio_pago   = (string)($body['medio_pago'] ?? 'SIN_ESPECIFICAR');
    $monto_pagado = (float)($body['monto_pagado'] ?? 0);

    if (!is_array($items) || empty($items)) {
      json_fail('No hay ítems en la venta', 422);
    }

    // Validaciones mínimas por ítem
    $total = 0.0;
    foreach ($items as $i => $item) {
      if (!is_array($item)) json_fail("Ítem inválido (#$i)", 422);

      $productoId = (int)($item['id'] ?? 0);
      $cantidad   = (int)($item['cantidad'] ?? 0);
      $precio     = (float)($item['precio'] ?? 0);

      if ($productoId <= 0 || $cantidad <= 0 || $precio < 0) {
        json_fail("Datos inválidos en ítem (#$i)", 422);
      }

      $total += $cantidad * $precio;
    }

    // Opcional: redondeo a 2 decimales para evitar basura flotante
    $total = round($total, 2);

    if ($monto_pagado < $total) {
      json_fail('Pago insuficiente', 422);
    }

    $vuelto = round($monto_pagado - $total, 2);

    // --------- Transacción ----------
    $pdo->beginTransaction();

    // Insertar venta
    $stmtVenta = $pdo->prepare("
      INSERT INTO ventas (total, medio_pago, monto_pagado, vuelto)
      VALUES (?, ?, ?, ?)
    ");
    $stmtVenta->execute([$total, $medio_pago, $monto_pagado, $vuelto]);
    $ventaId = (int)$pdo->lastInsertId();

    // Preparar sentencias
    $stmtItem = $pdo->prepare("
      INSERT INTO venta_items (venta_id, producto_id, cantidad, precio, subtotal)
      VALUES (?, ?, ?, ?, ?)
    ");

    $stmtStock = $pdo->prepare("
      UPDATE productos
      SET stock = stock - ?
      WHERE id = ? AND stock >= ?
    ");

    $stmtMov = $pdo->prepare("
      INSERT INTO movimientos_stock (producto_id, tipo, cantidad, referencia_venta_id, comentario)
      VALUES (?, 'VENTA', ?, ?, NULL)
    ");

    foreach ($items as $item) {
      $productoId = (int)$item['id'];
      $cantidad   = (int)$item['cantidad'];
      $precio     = (float)$item['precio'];
      $subtotal   = round($cantidad * $precio, 2);

      // Lock para evitar carreras
      $check = $pdo->prepare("SELECT stock, nombre FROM productos WHERE id = ? FOR UPDATE");
      $check->execute([$productoId]);
      $row = $check->fetch(PDO::FETCH_ASSOC);

      if (!$row) {
        throw new Exception("Producto ID $productoId no existe");
      }
      if ((int)$row['stock'] < $cantidad) {
        throw new Exception("Stock insuficiente para {$row['nombre']} (stock: {$row['stock']}, solicitado: {$cantidad})");
      }

      $stmtItem->execute([$ventaId, $productoId, $cantidad, $precio, $subtotal]);

      $stmtStock->execute([$cantidad, $productoId, $cantidad]);
      if ($stmtStock->rowCount() !== 1) {
        throw new Exception("No se pudo actualizar stock (Producto ID $productoId)");
      }

      $stmtMov->execute([$productoId, $cantidad, $ventaId]);
    }

    $pdo->commit();

    json_ok([
      'venta_id'     => $ventaId,
      'total'        => $total,
      'monto_pagado' => $monto_pagado,
      'vuelto'       => $vuelto
    ]);
  }

  json_fail('Acción no válida', 400);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  json_fail($e->getMessage(), 500);
}

<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../auth.php';
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

/**
 * API: NO redirect HTML, siempre JSON 401
 */
if (!isAuthenticated()) {
  json_fail('No autenticado', 401);
}

/**
 * Permiso obligatorio (fail-closed)
 */
function require_perm(string $perm): void {
  if (!function_exists('user_has_permission')) {
    // Si no existe el helper, es un error de wiring del sistema: mejor cerrar
    json_fail('RBAC no disponible', 500);
  }
  if (!user_has_permission($perm)) {
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
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

try {
  $pdo = getPDO();

  /* =========================================================
     1) Buscar producto por código (solo lectura)
  ========================================================= */
  if ($action === 'buscar_producto') {
    require_perm('realizar_ventas');

    if ($method !== 'GET') {
      json_fail('Método no permitido', 405);
    }

    $codigo = trim((string)($_GET['codigo'] ?? ''));
    if ($codigo === '') {
      json_fail('Código inválido', 422);
    }

    $stmt = $pdo->prepare("
      SELECT id, codigo, nombre, precio, stock, es_pesable, unidad_venta
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

    if ($method !== 'POST') {
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

    $puedeCambiarPrecio = function_exists('user_has_permission') && user_has_permission('caja_modificar_precio');

    // Validación mínima por ítem (NO calculamos total aún con precio cliente)
    foreach ($items as $i => $item) {
      if (!is_array($item)) json_fail("Ítem inválido (#$i)", 422);

      $productoId = (int)($item['id'] ?? 0);

      // cantidad puede ser float (pesables)
      $cantidad = (float)($item['cantidad'] ?? 0);

      if ($productoId <= 0 || $cantidad <= 0) {
        json_fail("Datos inválidos en ítem (#$i)", 422);
      }
    }

    // --------- Transacción ----------
    $pdo->beginTransaction();

    // Insertar venta (lo mínimo). Ideal: guardar user_id/caja_id si tu schema lo tiene
    $stmtVenta = $pdo->prepare("
      INSERT INTO ventas (total, medio_pago, monto_pagado, vuelto)
      VALUES (?, ?, ?, ?)
    ");
    // temporales hasta calcular total real:
    $stmtVenta->execute([0, $medio_pago, 0, 0]);
    $ventaId = (int)$pdo->lastInsertId();

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

    $total = 0.0;

    foreach ($items as $i => $item) {
      $productoId   = (int)$item['id'];
      $cantidad     = (float)$item['cantidad'];

      // Lock para evitar carreras y para traer el precio real
      $check = $pdo->prepare("
        SELECT stock, nombre, precio, es_pesable, activo
        FROM productos
        WHERE id = ? FOR UPDATE
      ");
      $check->execute([$productoId]);
      $row = $check->fetch(PDO::FETCH_ASSOC);

      if (!$row) {
        throw new Exception("Producto ID $productoId no existe");
      }
      if ((int)$row['activo'] !== 1) {
        throw new Exception("Producto inactivo: {$row['nombre']}");
      }

      // Pesable => permitir decimales; no pesable => forzar entero
      $esPesable = ((int)($row['es_pesable'] ?? 0) === 1);
      if (!$esPesable) {
        // evita vender 0.5 unidades de algo que no es pesable
        if (abs($cantidad - round($cantidad)) > 0.00001) {
          throw new Exception("Cantidad inválida para {$row['nombre']} (no es pesable)");
        }
        $cantidad = (float)(int)round($cantidad);
      }

      $stock = (float)$row['stock'];
      if ($stock < $cantidad) {
        throw new Exception("Stock insuficiente para {$row['nombre']} (stock: {$stock}, solicitado: {$cantidad})");
      }

      // Precio: por defecto el de DB
      $precioDb = (float)$row['precio'];

      // Si viene precio del cliente, solo aceptarlo si tiene permiso
      $precioCliente = isset($item['precio']) ? (float)$item['precio'] : $precioDb;
      $precioFinal = $precioDb;

      if ($puedeCambiarPrecio) {
        // Si tiene permiso, se permite precio cliente (con límites si querés)
        $precioFinal = $precioCliente;
        if ($precioFinal < 0) throw new Exception("Precio inválido para {$row['nombre']}");
      } else {
        // Sin permiso, ignoramos el precio del cliente
        $precioFinal = $precioDb;
      }

      $subtotal = round($cantidad * $precioFinal, 2);

      $stmtItem->execute([$ventaId, $productoId, $cantidad, $precioFinal, $subtotal]);

      // bajar stock
      $stmtStock->execute([$cantidad, $productoId, $cantidad]);
      if ($stmtStock->rowCount() !== 1) {
        throw new Exception("No se pudo actualizar stock (Producto ID $productoId)");
      }

      $stmtMov->execute([$productoId, $cantidad, $ventaId]);

      $total += $subtotal;
    }

    $total = round($total, 2);

    if ($monto_pagado < $total) {
      throw new Exception('Pago insuficiente');
    }

    $vuelto = round($monto_pagado - $total, 2);

    // Actualizar venta con total real
    $updVenta = $pdo->prepare("
      UPDATE ventas
      SET total = ?, monto_pagado = ?, vuelto = ?
      WHERE id = ?
    ");
    $updVenta->execute([$total, $monto_pagado, $vuelto, $ventaId]);

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

  // Log interno (no filtrar detalles en prod)
  if (function_exists('logMessage')) {
    logMessage("API index error ({$action}): " . $e->getMessage(), 'error');
  } else {
    error_log("API index error ({$action}): " . $e->getMessage());
  }

  if (defined('APP_ENV') && APP_ENV === 'production') {
    json_fail('Error interno', 500);
  }

  json_fail($e->getMessage(), 500);
}

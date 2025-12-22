<?php
// public/api/index.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../promos_logic.php';

function json_ok(array $data = []): void {
  echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function json_fail(string $msg, int $code = 400, array $extra = []): void {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function api_require_login(): void {
  if (!function_exists('current_user') || !current_user()) {
    json_fail('No autenticado', 401);
  }
}
function api_require_perm(string $perm): void {
  if (!function_exists('user_has_permission')) {
    json_fail('RBAC no disponible', 500);
  }
  if (!user_has_permission($perm)) {
    json_fail('No autorizado', 403);
  }
}

api_require_login();

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
     REGISTRAR VENTA (POST + CSRF)
  ========================================================= */
  if ($action === 'registrar_venta') {
    api_require_perm('realizar_ventas');

    if ($method !== 'POST') json_fail('Método no permitido', 405);

    // CSRF (header X-CSRF-Token o body csrf)
    $csrf = (string)($body['csrf'] ?? ($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')));
    if (!csrf_check($csrf)) json_fail('CSRF inválido o ausente', 403);

    if (!$body) json_fail('JSON inválido', 400);

    $items        = $body['items'] ?? [];
    $medio_pago   = strtoupper((string)($body['medio_pago'] ?? 'EFECTIVO'));
    $monto_pagado = (float)($body['monto_pagado'] ?? 0);
    $cajaId       = (int)($body['caja_id'] ?? 0);

    if (!is_array($items) || empty($items)) json_fail('No hay ítems en la venta', 422);

    $u = current_user();
    $userId = (int)($u['id'] ?? 0);

    // Permiso para modificar precio (fallback razonable)
    $puedeCambiarPrecio =
      function_exists('user_has_permission') && (
        user_has_permission('caja_modificar_precio')
        || user_has_permission('administrar_config')
        || user_has_permission('editar_productos')
      );

    // Validación mínima
    foreach ($items as $i => $item) {
      if (!is_array($item)) json_fail("Ítem inválido (#$i)", 422);
      $productoId = (int)($item['id'] ?? 0);
      $cantidad   = (float)($item['cantidad'] ?? 0);
      if ($productoId <= 0 || $cantidad <= 0) json_fail("Datos inválidos en ítem (#$i)", 422);
    }

    $pdo->beginTransaction();

    // Validar caja_id (si viene) -> debe existir y estar abierta (fecha_cierre NULL)
    if ($cajaId > 0) {
      $stCaja = $pdo->prepare("SELECT id FROM caja_sesiones WHERE id = ? AND fecha_cierre IS NULL LIMIT 1");
      $stCaja->execute([$cajaId]);
      if (!$stCaja->fetchColumn()) {
        throw new Exception("Caja inválida o cerrada (caja_id=$cajaId)");
      }
    } else {
      $cajaId = 0;
    }

    // 1) Traer productos con lock + armar carrito base (precio lista)
    $carrito = []; // para promos_logic
    $manualPriceByPid = []; // precio manual pedido por front (si permitido)
    $sumCantidad = 0.0;

    $stProd = $pdo->prepare("
      SELECT id, nombre, precio, stock, es_pesable, activo
      FROM productos
      WHERE id = ? FOR UPDATE
    ");

    foreach ($items as $i => $item) {
      $pid  = (int)$item['id'];
      $cant = (float)$item['cantidad'];

      $stProd->execute([$pid]);
      $p = $stProd->fetch(PDO::FETCH_ASSOC);

      if (!$p) throw new Exception("Producto ID $pid no existe");
      if ((int)$p['activo'] !== 1) throw new Exception("Producto inactivo: {$p['nombre']}");

      $esPesable = ((int)$p['es_pesable'] === 1);
      if (!$esPesable) {
        if (abs($cant - round($cant)) > 0.00001) {
          throw new Exception("Cantidad inválida para {$p['nombre']} (no es pesable)");
        }
        $cant = (float)(int)round($cant);
      }

      $stock = (float)$p['stock'];
      if ($stock < $cant) {
        throw new Exception("Stock insuficiente para {$p['nombre']} (stock: {$stock}, solicitado: {$cant})");
      }

      $precioLista = (float)$p['precio'];

      // precio manual (solo si tiene permiso) - NO lo aplicamos todavía, lo “apilamos” después de promos
      if ($puedeCambiarPrecio && isset($item['precio'])) {
        $pm = (float)$item['precio'];
        if ($pm > 0) {
          $manualPriceByPid[$pid] = $pm;
        }
      }

      $carrito[] = [
        'producto_id'   => $pid,
        'cantidad'      => $cant,
        'precio_unitario' => $precioLista, // promos y combos sobre lista (consistente con ticket/DB)
      ];

      $sumCantidad += $cant;
    }

    // 2) Aplicar promos/combos (engine backend ya hecho)
    $promos = obtenerPromosActivas($pdo);
    $calc   = aplicarPromosACarrito($carrito, $promos);

    // 3) Aplicar “precio manual” como descuento adicional (solo si baja el final)
    foreach ($calc['items'] as &$it) {
      $pid = (int)$it['producto_id'];
      if (!isset($manualPriceByPid[$pid])) continue;

      $manual = (float)$manualPriceByPid[$pid];
      $orig   = (float)$it['precio_unit_original'];
      $final  = (float)$it['precio_unit_final'];
      $cant   = (float)$it['cantidad'];

      // Solo permitir bajar más (si querés permitir subir, lo hacemos con recargo_total y UI)
      if ($manual > 0 && $manual < ($final - 0.00001)) {
        $nuevoSubtotal = round($manual * $cant, 2);
        $nuevoDesc     = max(0.0, round(($orig * $cant) - $nuevoSubtotal, 2));

        $it['precio_unit_final'] = $manual;
        $it['subtotal']          = $nuevoSubtotal;
        $it['descuento']         = max(0.0, ($orig * $cant) - $nuevoSubtotal);
        $it['descuento_monto']   = $nuevoDesc;
      }
    }
    unset($it);

    // 4) Totales
    $totalBruto = 0.0;
    $descTotal  = 0.0;
    $totalNeto  = 0.0;

    foreach ($calc['items'] as $it) {
      $cant = (float)$it['cantidad'];
      $orig = (float)$it['precio_unit_original'];
      $sub  = (float)$it['subtotal'];
      $desc = (float)$it['descuento_monto'];

      $totalBruto += ($orig * $cant);
      $descTotal  += $desc;
      $totalNeto  += $sub;
    }

    $totalBruto = round($totalBruto, 2);
    $descTotal  = round($descTotal, 2);
    $totalNeto  = round($totalNeto, 2);

    $recargoTotal = 0.0;
    if ($totalNeto > $totalBruto) {
      $recargoTotal = round($totalNeto - $totalBruto, 2);
    }

    // 5) Pago / vuelto
    if ($monto_pagado + 0.0001 < $totalNeto) {
      throw new Exception('Pago insuficiente');
    }
    $vuelto = round(max($monto_pagado - $totalNeto, 0), 2);

    // 6) Insert venta
    $stVenta = $pdo->prepare("
      INSERT INTO ventas (caja_id, total, descuento_total, recargo_total, medio_pago, monto_pagado, vuelto)
      VALUES (:caja_id, :total, :desc, :rec, :mp, :pagado, :vuelto)
    ");
    $stVenta->execute([
      ':caja_id' => ($cajaId > 0 ? $cajaId : null),
      ':total'   => $totalNeto,
      ':desc'    => $descTotal,
      ':rec'     => $recargoTotal,
      ':mp'      => $medio_pago,
      ':pagado'  => $monto_pagado,
      ':vuelto'  => $vuelto,
    ]);
    $ventaId = (int)$pdo->lastInsertId();

    // 7) Insert items + stock + movimientos
    $stItem = $pdo->prepare("
      INSERT INTO venta_items
        (venta_id, producto_id, cantidad, precio, precio_unit_original, descuento_monto, precio_unit_final, subtotal)
      VALUES
        (:vid, :pid, :cant, :precio, :orig, :desc, :final, :sub)
    ");

    $stStock = $pdo->prepare("
      UPDATE productos
      SET stock = stock - :cant
      WHERE id = :pid AND stock >= :cant
    ");

    $stMov = $pdo->prepare("
      INSERT INTO movimientos_stock (venta_id, producto_id, tipo, cantidad, comentario)
      VALUES (:vid, :pid, 'VENTA', :cant, NULL)
    ");

    foreach ($calc['items'] as $it) {
      $pid   = (int)$it['producto_id'];
      $cant  = (float)$it['cantidad'];
      $orig  = (float)$it['precio_unit_original'];
      $final = (float)$it['precio_unit_final'];
      $desc  = (float)$it['descuento_monto'];
      $sub   = (float)$it['subtotal'];

      $stItem->execute([
        ':vid'   => $ventaId,
        ':pid'   => $pid,
        ':cant'  => $cant,
        ':precio'=> $final,   // en tu DB, "precio" suele ser el unitario final
        ':orig'  => $orig,
        ':desc'  => $desc,
        ':final' => $final,
        ':sub'   => $sub,
      ]);

      $stStock->execute([':cant' => $cant, ':pid' => $pid]);
      if ($stStock->rowCount() !== 1) {
        throw new Exception("No se pudo actualizar stock (Producto ID $pid)");
      }

      $stMov->execute([':vid' => $ventaId, ':pid' => $pid, ':cant' => $cant]);
    }

    // 8) Actualizar caja_sesiones (si hay caja abierta)
    if ($cajaId > 0) {
      $campoMP = match ($medio_pago) {
        'EFECTIVO' => 'total_efectivo',
        'MP'       => 'total_mp',
        'DEBITO'   => 'total_debito',
        'CREDITO'  => 'total_credito',
        default    => 'total_efectivo'
      };

      $stCajaUpd = $pdo->prepare("
        UPDATE caja_sesiones
        SET total_ventas    = COALESCE(total_ventas,0) + :importe,
            total_productos = COALESCE(total_productos,0) + :tp,
            $campoMP        = COALESCE($campoMP,0) + :importe
        WHERE id = :id
      ");
      $stCajaUpd->execute([
        ':importe' => $totalNeto,
        ':tp'      => $sumCantidad,
        ':id'      => $cajaId,
      ]);
    }

    $pdo->commit();

    json_ok([
      'venta_id'       => $ventaId,
      'total'          => $totalNeto,
      'bruto'          => $totalBruto,
      'descuento_total'=> $descTotal,
      'recargo_total'  => $recargoTotal,
      'monto_pagado'   => $monto_pagado,
      'vuelto'         => $vuelto,
    ]);
  }

  json_fail('Acción no válida', 400);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }

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

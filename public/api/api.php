<?php
// public/api/api.php
declare(strict_types=1);

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../promos_logic.php';
require_once __DIR__ . '/../../src/audit_lib.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = getPDO();
require_login();

function json_response(array $data, int $status = 200): void {
  http_response_code($status);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/**
 * Leemos el body UNA SOLA VEZ (evita consumir php://input dos veces)
 * y permite "action" por JSON.
 */
$rawInput = '';
$bodyJson = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $rawInput = file_get_contents('php://input') ?: '';
  $tmp = json_decode($rawInput, true);
  if (is_array($tmp)) $bodyJson = $tmp;
}

// action puede venir por GET o por JSON body
$action = (string)($_GET['action'] ?? '');
if ($action === '' && is_array($bodyJson) && isset($bodyJson['action'])) {
  $action = (string)$bodyJson['action'];
}

try {

  /* =========================================================
     DEBUG AUDIT
  ========================================================= */
  if ($action === 'debug_audit') {
    json_response([
      'ok'         => true,
      'has_audit'  => function_exists('audit_log_event'),
      'audit_file' => file_exists(__DIR__ . '/../../src/audit_lib.php'),
    ]);
  }

  /* =========================================================
     BUSCAR PRODUCTO
  ========================================================= */
  if ($action === "buscar_producto") {
    $codigo = trim((string)($_GET["codigo"] ?? ""));
    if ($codigo === "") json_response(["ok"=>false, "error"=>"Código vacío"], 400);

    $sql = "SELECT id,codigo,nombre,precio,stock,activo,es_pesable,unidad_venta
            FROM productos
            WHERE codigo = :cod
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([":cod"=>$codigo]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$p || (int)$p["activo"] !== 1) {
      json_response(["ok"=>false, "error"=>"Producto no encontrado o inactivo"], 404);
    }

    $p["precio"]       = (float)$p["precio"];
    $p["stock"]        = (float)$p["stock"];
    $p["es_pesable"]   = ((int)$p["es_pesable"] === 1);
    $p["unidad_venta"] = $p["unidad_venta"] ?: "UNIDAD";

    json_response(["ok"=>true, "producto"=>$p]);
  }

  /* =========================================================
     LISTAR PROMOS ACTIVAS
  ========================================================= */
  if ($action === "listar_promos_activas") {
    $promos = obtenerPromosActivas($pdo);
    json_response([
      "ok"      => true,
      "simples" => $promos["simples"],
      "combos"  => $promos["combos"]
    ]);
  }

  /* =========================================================
     REGISTRAR VENTA
  ========================================================= */
  if ($action === "registrar_venta") {

    $body = $bodyJson;
    if (!is_array($body)) json_response(["ok"=>false, "error"=>"JSON inválido"], 400);

    $itemsReq    = $body["items"] ?? [];
    $medioPago   = (string)($body["medio_pago"] ?? "EFECTIVO");
    $montoPagado = (float)($body["monto_pagado"] ?? 0);

    if (empty($itemsReq)) json_response(["ok"=>false, "error"=>"Ticket vacío"], 400);

    $allowedMP = ["EFECTIVO","MP","DEBITO","CREDITO"];
    if (!in_array($medioPago, $allowedMP, true)) {
      json_response(["ok"=>false, "error"=>"Medio de pago inválido"], 400);
    }

    // Caja abierta
    $caja = $pdo->query("
      SELECT id
      FROM caja_sesiones
      WHERE fecha_cierre IS NULL
      ORDER BY id DESC
      LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    if (!$caja) json_response(["ok"=>false, "error"=>"No hay una caja abierta"], 400);
    $cajaId = (int)$caja["id"];

    // Sumatoria por producto (por si vienen repetidos)
    $qtyById = [];
    $lineas = [];

    foreach ($itemsReq as $it) {
      $prodId   = (int)($it["id"] ?? 0);
      $cantidad = (float)($it["cantidad"] ?? 0);

      if ($prodId <= 0 || $cantidad <= 0) {
        json_response(["ok"=>false, "error"=>"Ítem inválido"], 400);
      }

      $qtyById[$prodId] = ($qtyById[$prodId] ?? 0) + $cantidad;

      $lineas[] = [
        "id" => $prodId,
        "cantidad" => $cantidad,
        "precio_unitario" => array_key_exists("precio_unitario", $it) ? (float)$it["precio_unitario"] : null,
      ];
    }

    // Lock de productos
    $ids = array_keys($qtyById);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $pdo->beginTransaction();

    // Productos bloqueados para stock consistente
    $stmtP = $pdo->prepare("
      SELECT id, codigo, nombre, precio, stock, es_pesable, activo
      FROM productos
      WHERE id IN ($placeholders)
      FOR UPDATE
    ");
    $stmtP->execute($ids);
    $prods = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($prods as $p) $map[(int)$p['id']] = $p;

    // Validaciones stock/activo/tipo cantidad
    foreach ($qtyById as $pid => $qTotal) {
      if (!isset($map[$pid])) {
        $pdo->rollBack();
        json_response(["ok"=>false, "error"=>"Producto no válido ($pid)"], 400);
      }

      $p = $map[$pid];

      if ((int)$p["activo"] !== 1) {
        $pdo->rollBack();
        json_response(["ok"=>false, "error"=>"Producto inactivo: {$p['nombre']}"], 400);
      }

      $esPesable = ((int)$p["es_pesable"] === 1);
      if (!$esPesable && floor((float)$qTotal) != (float)$qTotal) {
        $pdo->rollBack();
        json_response(["ok"=>false, "error"=>"Cantidad inválida (no pesable): {$p['nombre']}"], 400);
      }

      if ((float)$qTotal > (float)$p["stock"]) {
        $pdo->rollBack();
        json_response(["ok"=>false, "error"=>"Stock insuficiente para {$p['nombre']}"], 400);
      }
    }

    // Construir items reales (precio base + precio manual si tiene permiso)
    $items = [];

    foreach ($lineas as $ln) {
      $p = $map[$ln["id"]];

      $precioBase = (float)$p["precio"];
      $precioU = $precioBase;

      // Si el front manda precio_unitario distinto, exige permiso
      if ($ln["precio_unitario"] !== null && abs($ln["precio_unitario"] - $precioBase) > 0.0001) {
        if (function_exists('user_has_permission') && !user_has_permission('caja_modificar_precio')) {
          $pdo->rollBack();
          json_response(["ok"=>false, "error"=>"No tenés permiso para modificar precios."], 403);
        }
        if ($ln["precio_unitario"] < 0) {
          $pdo->rollBack();
          json_response(["ok"=>false, "error"=>"Precio inválido."], 400);
        }
        $precioU = (float)$ln["precio_unitario"];
      }

      $cantidad = (float)$ln["cantidad"];
      $subtotal = $precioU * $cantidad;

      $items[] = [
        "id"              => (int)$p["id"],
        "cantidad"        => $cantidad,
        "precio_unitario" => $precioU,
        "precioLista"     => $precioBase,
        "subtotal"        => $subtotal,
        "producto"        => $p
      ];
    }

    // Promos
    $promos        = obtenerPromosActivas($pdo);
    $itemsConPromo = aplicarPromosACarrito($items, $promos);

    // Totales
    $totalNeto      = 0.0;
    $descuentoTotal = 0.0;

    foreach ($itemsConPromo as $it) {
      $totalNeto += (float)$it["subtotal"];
      $descuentoTotal += ((float)$it["precioLista"] * (float)$it["cantidad"]) - (float)$it["subtotal"];
    }

    $totalNeto      = round($totalNeto, 2);
    $descuentoTotal = round(max(0.0, $descuentoTotal), 2);
    $montoPagado    = round($montoPagado, 2);

    // Pago
    if ($medioPago !== 'EFECTIVO') {
      $montoPagado = $totalNeto;
      $vuelto = 0.0;
    } else {
      if ($montoPagado + 0.0001 < $totalNeto) {
        $pdo->rollBack();
        json_response(["ok"=>false, "error"=>"Pago insuficiente"], 400);
      }
      $vuelto = round($montoPagado - $totalNeto, 2);
    }

    // Guardar venta
    $hoy = date("Y-m-d H:i:s");

    $stmtVenta = $pdo->prepare("
      INSERT INTO ventas
        (fecha,total,descuento_total,recargo_total,medio_pago,monto_pagado,vuelto,caja_id)
      VALUES
        (:f,:t,:d,:r,:m,:p,:v,:c)
    ");
    $stmtVenta->execute([
      ":f" => $hoy,
      ":t" => $totalNeto,
      ":d" => $descuentoTotal,
      ":r" => 0,
      ":m" => $medioPago,
      ":p" => $montoPagado,
      ":v" => $vuelto,
      ":c" => $cajaId
    ]);

    $ventaId = (int)$pdo->lastInsertId();

    // Insert items (con columnas nuevas)
    $stmtItem = $pdo->prepare("
      INSERT INTO venta_items (
        venta_id, producto_id, cantidad,
        precio,
        precio_unit_original,
        descuento_monto,
        precio_unit_final,
        subtotal
      )
      VALUES (
        :v, :p, :c,
        :pr,
        :puo,
        :dm,
        :puf,
        :s
      )
    ");

    // Descontar stock
    $stmtStock = $pdo->prepare("UPDATE productos SET stock = stock - :c WHERE id = :id");

    // Registrar movimientos de stock (VENTA)
    $stmtMov = $pdo->prepare("
      INSERT INTO movimientos_stock (venta_id, producto_id, tipo, cantidad, comentario)
      VALUES (:vid, :pid, 'VENTA', :cant, :com)
    ");

    $totalProductos = 0.0;

    foreach ($itemsConPromo as $it) {
      $cantidad = (float)$it["cantidad"];
      $subtotal = round((float)$it["subtotal"], 2);

      // Precio lista (original)
      $puOriginal = round((float)$it["precioLista"], 2);

      // Precio unitario final (distribuimos el subtotal en la cantidad)
      $puFinal = ($cantidad > 0) ? round($subtotal / $cantidad, 2) : 0.0;

      // Descuento total por línea
      $descMonto = round(($puOriginal * $cantidad) - $subtotal, 2);
      if ($descMonto < 0) $descMonto = 0.0;

      $stmtItem->execute([
        ":v"   => $ventaId,
        ":p"   => (int)$it["id"],
        ":c"   => $cantidad,
        ":pr"  => $puFinal,
        ":puo" => $puOriginal,
        ":dm"  => $descMonto,
        ":puf" => $puFinal,
        ":s"   => $subtotal
      ]);

      $stmtStock->execute([
        ":c"  => $cantidad,
        ":id" => (int)$it["id"]
      ]);

      $stmtMov->execute([
        ":vid"  => $ventaId,
        ":pid"  => (int)$it["id"],
        ":cant" => $cantidad,
        ":com"  => null
      ]);

      $totalProductos += $cantidad;
    }

    // Actualizar resumen de caja_sesiones
    $campoMP = match ($medioPago) {
      'EFECTIVO' => 'total_efectivo',
      'MP'       => 'total_mp',
      'DEBITO'   => 'total_debito',
      'CREDITO'  => 'total_credito',
      default    => 'total_efectivo'
    };

    $upd = $pdo->prepare("
      UPDATE caja_sesiones
      SET total_ventas     = COALESCE(total_ventas,0) + 1,
          total_productos  = COALESCE(total_productos,0) + :tp,
          $campoMP         = COALESCE($campoMP,0) + :importe
      WHERE id = :id
    ");
    $upd->execute([
      ':tp'      => $totalProductos,
      ':importe' => $totalNeto,
      ':id'      => $cajaId
    ]);

    // Si querés auditar también la venta creada (opcional)
    $u = current_user();
    $userId = (int)($u['id'] ?? 0);

    if (function_exists('audit_log_event')) {
      audit_log_event($pdo, $userId ?: null, 'venta_creada', 'ventas', $ventaId, [
        'importe'     => $totalNeto,
        'medio_pago'  => $medioPago,
        'descuento'   => $descuentoTotal,
        'caja_id'     => $cajaId,
      ]);
    }

    $pdo->commit();

    json_response([
      "ok"       => true,
      "venta_id" => $ventaId,
      "total"    => $totalNeto,
      "vuelto"   => $vuelto
    ]);
  }

  /* =========================================================
     ANULAR VENTA
  ========================================================= */
  if ($action === "anular_venta") {

    require_permission('anular_venta');

    $body = $bodyJson;
    if (!is_array($body)) json_response(["ok"=>false, "error"=>"JSON inválido"], 400);

    $ventaId = (int)($body["venta_id"] ?? 0);
    $motivo  = trim((string)($body["motivo"] ?? ''));

    if ($ventaId <= 0) json_response(["ok"=>false, "error"=>"venta_id inválido"], 400);

    $u = current_user();
    $userId = (int)($u['id'] ?? 0);

    $pdo->beginTransaction();

    // Bloquear venta
    $st = $pdo->prepare("SELECT id, caja_id, medio_pago, total, estado FROM ventas WHERE id = ? FOR UPDATE");
    $st->execute([$ventaId]);
    $venta = $st->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
      $pdo->rollBack();
      json_response(["ok"=>false, "error"=>"Venta no encontrada"], 404);
    }

    $estado = (string)($venta['estado'] ?? 'EMITIDA');
    if (strtoupper($estado) === 'ANULADA') {
      $pdo->rollBack();
      json_response(["ok"=>false, "error"=>"La venta ya está anulada"], 400);
    }

    // Items
    $stI = $pdo->prepare("SELECT producto_id, cantidad FROM venta_items WHERE venta_id = ?");
    $stI->execute([$ventaId]);
    $items = $stI->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$items) {
      $pdo->rollBack();
      json_response(["ok"=>false, "error"=>"La venta no tiene items"], 400);
    }

    $updStock = $pdo->prepare("UPDATE productos SET stock = stock + :c WHERE id = :id");
    $insMov   = $pdo->prepare("
      INSERT INTO movimientos_stock (venta_id, producto_id, tipo, cantidad, comentario)
      VALUES (:vid, :pid, 'ANULACION', :cant, :com)
    ");

    $totalProductos = 0.0;

    foreach ($items as $it) {
      $pid  = (int)$it['producto_id'];
      $cant = (float)$it['cantidad'];

      $updStock->execute([":c" => $cant, ":id" => $pid]);
      $insMov->execute([
        ":vid"  => $ventaId,
        ":pid"  => $pid,
        ":cant" => $cant,
        ":com"  => ($motivo !== '' ? $motivo : null),
      ]);

      $totalProductos += $cant;
    }

    // Marcar venta anulada
    $upV = $pdo->prepare("
      UPDATE ventas
      SET estado = 'ANULADA',
          anulado_en = NOW(),
          anulado_por = :uid,
          anulado_motivo = :mot
      WHERE id = :id
    ");
    $upV->execute([
      ':uid' => $userId ?: null,
      ':mot' => ($motivo !== '' ? $motivo : null),
      ':id'  => $ventaId
    ]);

    // Ajustar resumen de caja_sesiones (si aplica)
    $cajaId  = (int)($venta['caja_id'] ?? 0);
    $medio   = strtoupper((string)($venta['medio_pago'] ?? 'EFECTIVO'));
    $importe = (float)($venta['total'] ?? 0);

    if ($cajaId > 0) {
      $campoMP = match ($medio) {
        'EFECTIVO' => 'total_efectivo',
        'MP'       => 'total_mp',
        'DEBITO'   => 'total_debito',
        'CREDITO'  => 'total_credito',
        default    => 'total_efectivo'
      };

      $updCaja = $pdo->prepare("
        UPDATE caja_sesiones
        SET total_ventas = GREATEST(COALESCE(total_ventas,0) - 1, 0),
            total_productos = GREATEST(COALESCE(total_productos,0) - :tp, 0),
            $campoMP = GREATEST(COALESCE($campoMP,0) - :importe, 0),
            total_anulaciones = COALESCE(total_anulaciones,0) + 1
        WHERE id = :id
      ");
      $updCaja->execute([
        ':tp'      => $totalProductos,
        ':importe' => $importe,
        ':id'      => $cajaId
      ]);
    }

    // Si hay factura asociada, marcarla anulada (no borrar)
    $pdo->prepare("UPDATE facturas SET estado = 'ANULADA' WHERE venta_id = ?")->execute([$ventaId]);

    // Auditoría
    if (function_exists('audit_log_event')) {
      audit_log_event($pdo, $userId ?: null, 'venta_anulada', 'ventas', $ventaId, [
        'motivo'     => $motivo,
        'importe'    => $importe,
        'medio_pago' => $medio,
      ]);
    }

    $pdo->commit();

    json_response(["ok"=>true, "venta_id"=>$ventaId]);
  }

  json_response(["ok"=>false, "error"=>"Acción no válida"], 400);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_response(["ok"=>false, "error"=>$e->getMessage()], 500);
}

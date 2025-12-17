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
   REGISTRAR VENTA (OBSOLETO)
   - Se maneja en /public/api/index.php (CSRF)
========================================================= */
if ($action === "registrar_venta") {
  json_response([
    "ok"    => false,
    "error" => "Endpoint obsoleto. Usar /kiosco/public/api/index.php?action=registrar_venta"
  ], 410);
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

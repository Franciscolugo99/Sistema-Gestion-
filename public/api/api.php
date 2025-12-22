<?php
// public/api/api.php
declare(strict_types=1);

// Blindaje: si algún include imprime warnings/HTML, no rompe el JSON
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../promos_logic.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../../src/audit_lib.php';

$pdo = getPDO();

function api_json(array $data, int $status = 200): void {
  // Si hubo “basura” antes del JSON, loguearla y descartarla
  $junk = ob_get_contents();
  if (is_string($junk) && trim($junk) !== '') {
    error_log('[API] Output inesperado antes del JSON: ' . substr($junk, 0, 800));
  }
  while (ob_get_level() > 0) ob_end_clean();

  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function api_require_login(): void {
  if (!function_exists('current_user') || !current_user()) {
    api_json(['ok' => false, 'error' => 'No autenticado'], 401);
  }
}

function api_require_perm(string $slug): void {
  if (!function_exists('user_has_permission')) {
    api_json(['ok' => false, 'error' => 'RBAC no disponible'], 500);
  }
  if (!user_has_permission($slug)) {
    api_json(['ok' => false, 'error' => 'No autorizado'], 403);
  }
}

function api_require_csrf(array $bodyJson = []): void {
  $csrf = (string)(
    $bodyJson['csrf']
      ?? ($_POST['csrf'] ?? '')
      ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')
  );
  if (!csrf_check($csrf)) {
    api_json(['ok' => false, 'error' => 'CSRF inválido o ausente'], 403);
  }
}

// -------- Leer JSON body una sola vez ----------
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$rawInput = '';
$bodyJson = null;

if ($method === 'POST') {
  $rawInput = file_get_contents('php://input') ?: '';
  $tmp = json_decode($rawInput, true);
  if (is_array($tmp)) $bodyJson = $tmp;
}

$action = (string)($_GET['action'] ?? '');
if ($action === '' && is_array($bodyJson) && isset($bodyJson['action'])) {
  $action = (string)$bodyJson['action'];
}

api_require_login();

// Permisos por acción
$permMap = [
  'buscar_producto'       => 'realizar_ventas',
  'listar_promos_activas' => 'realizar_ventas',
  'anular_venta'          => 'anular_venta',  // OJO: este slug debe existir
  'debug_audit'           => 'ver_auditoria',
];

if (isset($permMap[$action])) {
  api_require_perm($permMap[$action]);
}

try {

  /* =========================================================
     DEBUG AUDIT
  ========================================================= */
  if ($action === 'debug_audit') {
    if (defined('APP_ENV') && APP_ENV === 'production') {
      api_json(['ok' => false, 'error' => 'No disponible'], 404);
    }
    api_json([
      'ok'         => true,
      'has_audit'  => function_exists('audit_log_event'),
      'audit_file' => file_exists(__DIR__ . '/../../src/audit_lib.php'),
    ]);
  }

  /* =========================================================
     BUSCAR PRODUCTO
  ========================================================= */
  if ($action === 'buscar_producto') {
    if ($method !== 'GET') api_json(['ok'=>false, 'error'=>'Método no permitido'], 405);

    $codigo = trim((string)($_GET['codigo'] ?? ''));
    if ($codigo === '') api_json(['ok'=>false, 'error'=>'Código vacío'], 422);

    $stmt = $pdo->prepare("
      SELECT id, codigo, nombre, precio, stock, activo, es_pesable, unidad_venta
      FROM productos
      WHERE codigo = :cod
      LIMIT 1
    ");
    $stmt->execute([':cod' => $codigo]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$p || (int)$p['activo'] !== 1) {
      api_json(['ok'=>false, 'error'=>'Producto no encontrado o inactivo'], 404);
    }

    $p['precio']       = (float)$p['precio'];
    $p['stock']        = (float)$p['stock'];
    $p['es_pesable']   = ((int)$p['es_pesable'] === 1);
    $p['unidad_venta'] = $p['unidad_venta'] ?: 'UNIDAD';

    api_json(['ok'=>true, 'producto'=>$p]);
  }

  /* =========================================================
     LISTAR PROMOS ACTIVAS
  ========================================================= */
  if ($action === 'listar_promos_activas') {
    if ($method !== 'GET') api_json(['ok'=>false, 'error'=>'Método no permitido'], 405);

    $promos = obtenerPromosActivas($pdo);
    api_json([
      'ok'      => true,
      'simples' => $promos['simples'],
      'combos'  => $promos['combos'],
    ]);
  }

  /* =========================================================
     REGISTRAR VENTA (OBSOLETO)
  ========================================================= */
  if ($action === 'registrar_venta') {
    api_json([
      'ok'    => false,
      'error' => 'Endpoint obsoleto. Usar /kiosco/public/api/index.php?action=registrar_venta'
    ], 410);
  }

  /* =========================================================
     ANULAR VENTA (POST + CSRF)
  ========================================================= */
  if ($action === 'anular_venta') {
    if ($method !== 'POST') api_json(['ok'=>false, 'error'=>'Método no permitido'], 405);
    if (!is_array($bodyJson)) api_json(['ok'=>false, 'error'=>'JSON inválido'], 400);

    api_require_csrf($bodyJson);

    $ventaId = (int)($bodyJson['venta_id'] ?? 0);
    $motivo  = trim((string)($bodyJson['motivo'] ?? ''));

    if ($ventaId <= 0) api_json(['ok'=>false, 'error'=>'venta_id inválido'], 422);

    $u = current_user();
    $userId = (int)($u['id'] ?? 0);

    $pdo->beginTransaction();

    // Bloquear venta
    $st = $pdo->prepare("SELECT id, caja_id, medio_pago, total, estado FROM ventas WHERE id = ? FOR UPDATE");
    $st->execute([$ventaId]);
    $venta = $st->fetch(PDO::FETCH_ASSOC);

    if (!$venta) {
      $pdo->rollBack();
      api_json(['ok'=>false, 'error'=>'Venta no encontrada'], 404);
    }

    $estado = strtoupper((string)($venta['estado'] ?? 'EMITIDA'));
    if ($estado === 'ANULADA') {
      $pdo->rollBack();
      api_json(['ok'=>false, 'error'=>'La venta ya está anulada'], 400);
    }

    // Items
    $stI = $pdo->prepare("SELECT producto_id, cantidad FROM venta_items WHERE venta_id = ?");
    $stI->execute([$ventaId]);
    $items = $stI->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$items) {
      $pdo->rollBack();
      api_json(['ok'=>false, 'error'=>'La venta no tiene items'], 400);
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

      $updStock->execute([':c' => $cant, ':id' => $pid]);
      $insMov->execute([
        ':vid'  => $ventaId,
        ':pid'  => $pid,
        ':cant' => $cant,
        ':com'  => ($motivo !== '' ? $motivo : null),
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
      ':uid' => ($userId > 0 ? $userId : null),
      ':mot' => ($motivo !== '' ? $motivo : null),
      ':id'  => $ventaId
    ]);

    // Ajustar caja_sesiones:
    // IMPORTANTE: en tu schema total_ventas es MONTO (no “cantidad de ventas”)
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
        SET total_ventas      = GREATEST(COALESCE(total_ventas,0) - :importe, 0),
            total_productos   = GREATEST(COALESCE(total_productos,0) - :tp, 0),
            $campoMP          = GREATEST(COALESCE($campoMP,0) - :importe, 0),
            total_anulaciones = COALESCE(total_anulaciones,0) + 1
        WHERE id = :id
      ");
      $updCaja->execute([
        ':tp'      => $totalProductos,
        ':importe' => $importe,
        ':id'      => $cajaId
      ]);
    }

    // Factura asociada (si existe)
    $pdo->prepare("UPDATE facturas SET estado = 'ANULADA' WHERE venta_id = ?")->execute([$ventaId]);

    // Auditoría
    if (function_exists('audit_log_event')) {
      audit_log_event($pdo, ($userId > 0 ? $userId : null), 'venta_anulada', 'ventas', $ventaId, [
        'motivo'     => $motivo,
        'importe'    => $importe,
        'medio_pago' => $medio,
      ]);
    }

    $pdo->commit();
    api_json(['ok'=>true, 'venta_id'=>$ventaId]);
  }

  api_json(['ok' => false, 'error' => 'Acción no válida'], 400);

} catch (Throwable $e) {
  if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();

  if (function_exists('logMessage')) {
    logMessage("API error ({$action}): " . $e->getMessage(), 'error');
  } else {
    error_log("API error ({$action}): " . $e->getMessage());
  }

  if (defined('APP_ENV') && APP_ENV === 'production') {
    api_json(['ok'=>false, 'error'=>'Error interno'], 500);
  }
  api_json(['ok'=>false, 'error'=>$e->getMessage()], 500);
}

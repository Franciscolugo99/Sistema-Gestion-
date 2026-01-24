<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
if (!function_exists('insert_dynamic')) { @require_once FLUS_ROOT . '/src/api_helpers.php'; }
if (!function_exists('getPDO')) { require_once __DIR__ . '/../../../src/db_helpers.php'; }
$pdo = $pdo ?? (function_exists('getPDO') ? getPDO() : null);
if (!$pdo instanceof PDO) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'PDO no disponible']); exit; }
if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

/**
 * API action: anular_venta
 * - Marca venta como ANULADA
 * - Guarda motivo / user / fecha si existen columnas
 * - Repone stock (si existen venta_items + productos)
 * - Caja: la anulación se refleja por estado ANULADA (se excluye en caja y cierre)
 *
 * Este archivo es incluido por public/api/index.php (dispatcher actions/{action}.php)
 */

// -------------------------
// Helpers de esquema (robustos)
// -------------------------
if (!function_exists('flus_table_has_column')) {
  function flus_table_has_column(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare("
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
      LIMIT 1
    ");
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
  }
}

if (!function_exists('flus_has_table')) {
  function flus_has_table(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("
      SELECT 1
      FROM INFORMATION_SCHEMA.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
      LIMIT 1
    ");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  }
}

// -------------------------
// Respuestas JSON (usa las del core si existen)
// -------------------------
$__ok = function(array $payload = []) {
  if (function_exists('json_ok')) { json_ok($payload); return; }
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => true] + $payload);
  exit;
};

$__fail = function(string $msg, int $code = 400, array $extra = []) {
  if (function_exists('json_fail')) { json_fail($msg, $code, $extra); return; }
  header('Content-Type: application/json; charset=utf-8', true, $code);
  echo json_encode(['ok' => false, 'error' => $msg] + $extra);
  exit;
};

// -------------------------
// Input (✅ soporta JSON body + POST + GET)
// -------------------------
$raw = file_get_contents('php://input') ?: '';
$json = [];

if ($raw !== '') {
  $tmp = json_decode($raw, true);
  if (is_array($tmp)) $json = $tmp;
}

$in = array_merge($_GET, $_POST, $json);

$ventaId = (int)($in['venta_id'] ?? $in['id'] ?? 0);
$motivo  = trim((string)($in['motivo'] ?? ''));

// Permiso (en tu UI usás 'anular_venta')
if (function_exists('user_has_permission') && !user_has_permission('anular_venta')) {
  $__fail('Sin permiso', 403);
}

if ($ventaId <= 0) $__fail('ID inválido', 400);

// User id best-effort
$userId = null;
if (function_exists('current_user')) {
  $u = current_user();
  if (is_array($u) && isset($u['id'])) $userId = (int)$u['id'];
}
if ($userId === null && isset($_SESSION['user']['id'])) {
  $userId = (int)$_SESSION['user']['id'];
}

try {
  $pdo->beginTransaction();

  // Lock venta
  $st = $pdo->prepare("SELECT * FROM ventas WHERE id = ? FOR UPDATE");
  $st->execute([$ventaId]);
  $venta = $st->fetch(PDO::FETCH_ASSOC);

  if (!$venta) {
    $pdo->rollBack();
    $__fail('Venta no encontrada', 404);
  }

  $estadoActual = strtoupper((string)($venta['estado'] ?? 'EMITIDA'));
  if ($estadoActual === 'ANULADA') {
    $pdo->commit();
    $__ok(['already' => true]);
  }

  // -------------------------
  // 1) Marcar ANULADA (solo columnas que existan)
  // -------------------------
  $sets = ["estado = 'ANULADA'"];
  $bind = [':id' => $ventaId];

  if (flus_table_has_column($pdo, 'ventas', 'anulado_en')) {
    $sets[] = "anulado_en = NOW()";
  }

  if ($userId !== null && flus_table_has_column($pdo, 'ventas', 'anulado_por')) {
    $sets[] = "anulado_por = :anulado_por";
    $bind[':anulado_por'] = $userId;
  }

  if ($motivo !== '' && flus_table_has_column($pdo, 'ventas', 'anulado_motivo')) {
    $mot = mb_substr($motivo, 0, 255);
    $sets[] = "anulado_motivo = :anulado_motivo";
    $bind[':anulado_motivo'] = $mot;
  }

  $sqlUp = "UPDATE ventas SET " . implode(', ', $sets) . " WHERE id = :id";
  $stUp = $pdo->prepare($sqlUp);
  $stUp->execute($bind);

  // -------------------------
  $items = [];

  // 2) Reponer stock (si existen tablas)
  // -------------------------
  if (flus_has_table($pdo, 'venta_items') && flus_has_table($pdo, 'productos')) {
    $stIt = $pdo->prepare("SELECT producto_id, cantidad FROM venta_items WHERE venta_id = ?");
    $stIt->execute([$ventaId]);
    $items = $stIt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($items) {
      $stProd = $pdo->prepare("UPDATE productos SET stock = stock + :qty WHERE id = :pid");
      foreach ($items as $it) {
        $pid = (int)($it['producto_id'] ?? 0);
        $qty = (float)($it['cantidad'] ?? 0);
        if ($pid > 0 && $qty > 0) {
          $stProd->execute([':qty' => $qty, ':pid' => $pid]);
        }
      }
    }
  }


  // -------------------------
  // 2b) Registrar movimientos_stock (para auditoría / módulo Movimientos)
  // -------------------------
  if (flus_has_table($pdo, 'movimientos_stock') && $items) {
    $comBase = "Anulación venta #{$ventaId}" . ($motivo !== '' ? (": " . mb_substr($motivo, 0, 180)) : "");
    foreach ($items as $it) {
      $pid = (int)($it['producto_id'] ?? 0);
      $qty = (float)($it['cantidad'] ?? 0);
      if ($pid > 0 && $qty > 0) {
        // En ventas guardamos cantidad positiva (el signo lo normaliza el visor por tipo)
        if (function_exists('insert_dynamic')) {
          insert_dynamic($pdo, 'movimientos_stock', [
            'producto_id'         => $pid,
            'tipo'                => 'ANULACION',
            'cantidad'            => $qty,
            'venta_id'            => $ventaId,
            'referencia_venta_id' => $ventaId,
            'comentario'          => $comBase,
            'fecha'               => date('Y-m-d H:i:s'),
          ]);
        }
      }
    }
  }

  // -------------------------
  // 3) Caja: no insertamos movimientos manuales.
  // Al anular, la venta pasa a estado ANULADA y queda fuera de los cálculos de caja (cierre y resumen).
  // Eso evita dobles conteos y mantiene el saldo esperado alineado con lo real.
  // -------------------------

  $pdo->commit();
  $__ok(['id' => $ventaId]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $__fail('DB_ERROR', 500, ['detail' => $e->getMessage()]);
}

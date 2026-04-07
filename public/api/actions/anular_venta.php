<?php
declare(strict_types=1);
require_once __DIR__ . '/../../bootstrap.php';
if (!function_exists('insert_dynamic')) { @require_once FLUS_ROOT . '/src/api_helpers.php'; }
if (!function_exists('getPDO')) { require_once __DIR__ . '/../../../src/db_helpers.php'; }
require_once FLUS_ROOT . '/src/venta_anulaciones_lib.php';
require_once FLUS_ROOT . '/src/facturacion_lib.php';
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
    if (function_exists('flus_column_exists')) {
      return (bool)flus_column_exists($pdo, $table, $column);
    }
    if (function_exists('has_column')) {
      return (bool)has_column($pdo, $table, $column);
    }

    return false;
  }
}

if (!function_exists('flus_has_table')) {
  function flus_has_table(PDO $pdo, string $table): bool {
    if (function_exists('flus_table_exists')) {
      return (bool)flus_table_exists($pdo, $table);
    }
    if (function_exists('has_table')) {
      return (bool)has_table($pdo, $table);
    }

    return false;
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

  $ventaFacturada = (int)($venta['facturada'] ?? 0) === 1;
  if (!$ventaFacturada && flus_has_table($pdo, 'facturas') && flus_table_has_column($pdo, 'facturas', 'venta_id')) {
    $sqlFactura = 'SELECT id FROM facturas WHERE venta_id = ?';
    if (flus_table_has_column($pdo, 'facturas', 'naturaleza')) {
      $sqlFactura .= " AND naturaleza = 'FACTURA'";
    }
    $sqlFactura .= ' ORDER BY id DESC LIMIT 1';
    $stFactura = $pdo->prepare($sqlFactura);
    $stFactura->execute([$ventaId]);
    $ventaFacturada = $stFactura->fetchColumn() !== false;
  }
  if ($ventaFacturada) {
    $pdo->rollBack();
    $__fail(
      'La venta ya tiene comprobante fiscal. Debes revertirla por Nota de Credito, no por anulacion comun.',
      409,
      ['error_code' => 'VENTA_FACTURADA']
    );
  }

  $ventaYaAnulada = function_exists('flus_sale_is_annulled')
    ? flus_sale_is_annulled($venta)
    : (strtoupper((string)($venta['estado'] ?? 'EMITIDA')) === 'ANULADA');
  $ccReversa = null;
  $items = [];
  $yaAnulado = flus_venta_items_anulados_map($pdo, $ventaId);
  $ventaItems = flus_venta_items_cargar($pdo, $ventaId);
  $itemsRestantes = flus_venta_items_restantes($ventaItems, $yaAnulado);
  // -------------------------
  // 1) Marcar ANULADA (solo columnas que existan)
  // -------------------------
  if (!$ventaYaAnulada) {
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
  }



  // -------------------------
  // 2) Cuenta Corriente: si la venta tuvo CARGO a CC, crear REVERSA y actualizar saldo (deuda/límite)
  //     - Fuente de verdad: cuenta_corriente_movimientos
  //     - clientes.cc_saldo es cache
  //     - No tocamos caja (CC no entra a caja)
  // -------------------------
  try {
    $tCc = 'cuenta_corriente_movimientos';
    $tCli = 'clientes';
    $tVp  = 'venta_pagos';

    $ccSoportable = (
      flus_has_table($pdo, $tCc)
      && flus_has_table($pdo, $tCli)
      && flus_table_has_column($pdo, $tCc, 'id')
      && flus_table_has_column($pdo, $tCc, 'cliente_id')
      && flus_table_has_column($pdo, $tCc, 'tipo')
      && flus_table_has_column($pdo, $tCc, 'estado')
      && flus_table_has_column($pdo, $tCc, 'monto')
      && flus_table_has_column($pdo, $tCli, 'cc_saldo')
    );

    if ($ccSoportable) {
      $ccTotalOriginal = flus_venta_cc_total_original($pdo, $ventaId);
      if ($ccTotalOriginal > 0) {
        $motBase = "Anulación venta #{$ventaId}";
        if ($motivo !== '') {
          $motBase .= ': ' . (function_exists('mb_substr') ? mb_substr($motivo, 0, 180) : substr($motivo, 0, 180));
        }

        $ccReversa = flus_venta_cc_revertir_monto($pdo, $venta, $ventaId, $ccTotalOriginal, $userId, $motBase);
      }
    }
  } catch (Throwable $ccE) {
    // Cualquier falla de CC anula toda la operación: mantenemos atomicidad.
    throw $ccE;
  }

  if (!$ventaYaAnulada) {
    // -------------------------
    // 3) Reponer stock (si existen tablas)
    // -------------------------
    if ($itemsRestantes) {
      $comBase = "Anulación venta #{$ventaId}" . ($motivo !== '' ? (": " . mb_substr($motivo, 0, 180)) : "");
      flus_venta_stock_reponer_items($pdo, $itemsRestantes, $ventaId, $userId, $comBase);

      foreach ($itemsRestantes as $row) {
        $item = $row['item'] ?? [];
        $items[] = [
          'producto_id' => $item['producto_id'] ?? null,
          'cantidad' => $row['cantidad_restante'] ?? 0,
        ];
      }
    }
  } // end if (!$ventaYaAnulada) — stock restoration

  // -------------------------
  // 4) Caja: no insertamos movimientos manuales.
  // Al anular, la venta pasa a estado ANULADA y queda fuera de los cálculos de caja (cierre y resumen).
  // Eso evita dobles conteos y mantiene el saldo esperado alineado con lo real.
  // -------------------------

  $pdo->commit();
  $payload = ['id' => $ventaId];
  if ($ventaYaAnulada) $payload['already'] = true;
  if (is_array($ccReversa)) $payload['cc_reversa'] = $ccReversa;
  $__ok($payload);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('anular_venta: ' . $e->getMessage());
  $__fail('No se pudo anular la venta.', 500, ['error_code' => 'DB_ERROR']);
}

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

  $ventaYaAnulada = function_exists('flus_sale_is_annulled')
    ? flus_sale_is_annulled($venta)
    : (strtoupper((string)($venta['estado'] ?? 'EMITIDA')) === 'ANULADA');
  $ccReversa = null;
  $items = [];
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
      // Recolectar IDs de movimientos CC vinculados a esta venta
      $movIds = [];

      // 1) Preferido: por venta_id (si la columna existe)
      if (flus_table_has_column($pdo, $tCc, 'venta_id')) {
        $stCC = $pdo->prepare("SELECT id FROM {$tCc} WHERE venta_id = ? AND estado = 'ACTIVO' AND tipo = 'CARGO'");
        $stCC->execute([$ventaId]);
        $movIds = array_map('intval', $stCC->fetchAll(PDO::FETCH_COLUMN) ?: []);
      }

      // 2) Fallback: por venta_pagos.cc_movimiento_id
      if (!$movIds && flus_has_table($pdo, $tVp) && flus_table_has_column($pdo, $tVp, 'venta_id') && flus_table_has_column($pdo, $tVp, 'cc_movimiento_id')) {
        $stVP = $pdo->prepare("SELECT DISTINCT cc_movimiento_id FROM {$tVp} WHERE venta_id = ? AND cc_movimiento_id IS NOT NULL");
        $stVP->execute([$ventaId]);
        $ids = $stVP->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($ids as $x) {
          $ix = (int)$x;
          if ($ix > 0) $movIds[] = $ix;
        }
        $movIds = array_values(array_unique($movIds));
      }

      if ($movIds) {
        $reversados = [];
        $omitidos   = [];

        // Reversa idempotente (si ya está reversado, no duplica)
        foreach ($movIds as $movId) {
          // Lock movimiento
          $stMov = $pdo->prepare("SELECT * FROM {$tCc} WHERE id = ? FOR UPDATE");
          $stMov->execute([$movId]);
          $mov = $stMov->fetch(PDO::FETCH_ASSOC);

          if (!$mov) {
            $omitidos[] = ['movimiento_id' => $movId, 'reason' => 'NO_EXISTE'];
            continue;
          }

          $tipo = strtoupper((string)($mov['tipo'] ?? ''));
          $estado = strtoupper((string)($mov['estado'] ?? ''));
          if ($estado !== 'ACTIVO') {
            $omitidos[] = ['movimiento_id' => $movId, 'reason' => 'NO_ACTIVO'];
            continue;
          }
          if ($tipo === 'REVERSA') {
            $omitidos[] = ['movimiento_id' => $movId, 'reason' => 'ES_REVERSA'];
            continue;
          }

          // Si ya tiene una reversa activa, no duplicar
          if (flus_table_has_column($pdo, $tCc, 'reversa_de_id')) {
            $stChk = $pdo->prepare("SELECT id FROM {$tCc} WHERE reversa_de_id = ? AND estado = 'ACTIVO' LIMIT 1");
            $stChk->execute([$movId]);
            if ($stChk->fetchColumn()) {
              $omitidos[] = ['movimiento_id' => $movId, 'reason' => 'YA_REVERSADO'];
              continue;
            }
          }

          $clienteId = (int)($mov['cliente_id'] ?? 0);
          $monto     = (float)($mov['monto'] ?? 0);

          if ($clienteId <= 0 || $monto <= 0) {
            $omitidos[] = ['movimiento_id' => $movId, 'reason' => 'DATOS_INVALIDOS'];
            continue;
          }

          // Lock cliente
          $stCli = $pdo->prepare("SELECT cc_saldo FROM {$tCli} WHERE id = ? FOR UPDATE");
          $stCli->execute([$clienteId]);
          $cli = $stCli->fetch(PDO::FETCH_ASSOC);
          if (!$cli) {
            $omitidos[] = ['movimiento_id' => $movId, 'reason' => 'CLIENTE_NO_ENCONTRADO'];
            continue;
          }

          $saldoAnterior = (float)($cli['cc_saldo'] ?? 0);

          // Reversa = operación inversa
          if ($tipo === 'CARGO' || $tipo === 'AJUSTE_POS') {
            $saldoPosterior = $saldoAnterior - $monto;
          } elseif ($tipo === 'PAGO' || $tipo === 'AJUSTE_NEG') {
            $saldoPosterior = $saldoAnterior + $monto;
          } else {
            $saldoPosterior = $saldoAnterior;
          }
          $saldoPosterior = round($saldoPosterior, 2);

          $motBase = "Anulación venta #{$ventaId}";
          if ($motivo !== '') {
            $motBase .= ': ' . (function_exists('mb_substr') ? mb_substr($motivo, 0, 180) : substr($motivo, 0, 180));
          }

          // Insertar movimiento REVERSA (sin editar historial)
          $reversaId = insert_dynamic($pdo, $tCc, [
            'cliente_id'     => $clienteId,
            'venta_id'       => $ventaId,
            'tipo'           => 'REVERSA',
            'estado'         => 'ACTIVO',
            'monto'          => $monto,
            'saldo_anterior' => $saldoAnterior,
            'saldo_posterior'=> $saldoPosterior,
            'reversa_de_id'  => $movId,
            'concepto'       => "REVERSA: {$motBase}",
            'created_by'     => $userId,
            'caja_id'        => $venta['caja_id'] ?? null,
            'terminal_id'    => $in['terminal_id'] ?? null,
            'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? null,
          ]);

          // Marcar original ANULADO
          $setMov = ["estado = 'ANULADO'"];
          if (flus_table_has_column($pdo, $tCc, 'updated_at')) $setMov[] = 'updated_at = NOW()';
          $stMk = $pdo->prepare("UPDATE {$tCc} SET " . implode(', ', $setMov) . " WHERE id = ?");
          $stMk->execute([$movId]);

          // Actualizar cache cliente
          $stUpd = $pdo->prepare("UPDATE {$tCli} SET cc_saldo = ? WHERE id = ?");
          $stUpd->execute([$saldoPosterior, $clienteId]);

          // Si se reversó un PAGO (no aplica a ventas, pero lo dejamos robusto)
          if ($tipo === 'PAGO' && flus_table_has_column($pdo, $tCli, 'cc_fecha_ultimo_pago')) {
            $stF = $pdo->prepare("SELECT MAX(DATE(created_at)) FROM {$tCc} WHERE cliente_id = ? AND tipo = 'PAGO' AND estado = 'ACTIVO'");
            $stF->execute([$clienteId]);
            $nuevaFecha = $stF->fetchColumn() ?: null;
            $stUF = $pdo->prepare("UPDATE {$tCli} SET cc_fecha_ultimo_pago = ? WHERE id = ?");
            $stUF->execute([$nuevaFecha, $clienteId]);
          }

          $reversados[] = [
            'movimiento_id' => $movId,
            'reversa_id'    => $reversaId,
            'cliente_id'    => $clienteId,
            'monto'         => $monto,
            'saldo_anterior'=> $saldoAnterior,
            'saldo_posterior'=> $saldoPosterior,
          ];
        }

        if ($reversados || $omitidos) {
          $ccReversa = [
            'venta_id' => $ventaId,
            'reversados' => $reversados,
            'omitidos' => $omitidos,
          ];
        }
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
    // 3b) Registrar movimientos_stock (para auditoría / módulo Movimientos)
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
  $__fail('DB_ERROR', 500, ['detail' => $e->getMessage()]);
}

<?php
// public/caja_lib.php
declare(strict_types=1);

require_once __DIR__ . '/lib/helpers.php';

/**
 * Devuelve la sesión de caja abierta (o null si no hay).
 */
function caja_get_abierta(PDO $pdo): ?array {
  $sql = "SELECT cs.id, cs.user_id, cs.fecha_apertura, cs.fecha_cierre,
                 cs.saldo_inicial, cs.saldo_sistema, cs.saldo_declarado, cs.diferencia,
                 cs.total_ventas, cs.total_efectivo, cs.total_mp, cs.total_debito, cs.total_credito,
                 cs.total_productos, cs.total_anulaciones, cs.notas,
                 u.username
          FROM caja_sesiones cs
          JOIN users u ON u.id = cs.user_id
          WHERE cs.fecha_cierre IS NULL
          ORDER BY cs.id DESC
          LIMIT 1";

  $st = $pdo->query($sql);
  if (!$st) return null;

  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

/**
 * Abre una nueva sesión de caja.
 */
function caja_abrir(PDO $pdo, int $userId, $saldoInicial = 0.0): int {
  $saldoInicial = parse_money_ar($saldoInicial);
  if ($saldoInicial < 0) {
    throw new RuntimeException('Saldo inicial inválido.');
  }

  $pdo->beginTransaction();
  try {
    // Evitar doble apertura
    $st = $pdo->query("
      SELECT id
      FROM caja_sesiones
      WHERE fecha_cierre IS NULL
      ORDER BY id DESC
      LIMIT 1
      FOR UPDATE
    ");
    $abierta = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
    if ($abierta) {
      throw new RuntimeException('Ya hay una caja abierta.');
    }

    $sql = "INSERT INTO caja_sesiones (user_id, fecha_apertura, saldo_inicial)
            VALUES (:uid, NOW(), :saldo)";
    $st2 = $pdo->prepare($sql);
    $st2->execute([
      ':uid'   => $userId,
      ':saldo' => $saldoInicial,
    ]);

    $id = (int)$pdo->lastInsertId();
    $pdo->commit();
    return $id;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

/**
 * Cierre simple (por si lo usás en algún lugar).
 */
function caja_cerrar(PDO $pdo, int $cajaId, $saldoDeclarado, $totalEfectivo): void {
  $saldoDeclarado = parse_money_ar($saldoDeclarado);
  $totalEfectivo  = parse_money_ar($totalEfectivo);

  $st = $pdo->prepare("SELECT saldo_inicial FROM caja_sesiones WHERE id = :id");
  $st->execute([':id' => $cajaId]);
  $saldoInicial = (float)$st->fetchColumn();

  $saldoSistema = $saldoInicial + $totalEfectivo;
  $diferencia   = $saldoDeclarado - $saldoSistema;

  $sql = "UPDATE caja_sesiones
          SET fecha_cierre    = NOW(),
              saldo_sistema   = :sis,
              saldo_declarado = :dec,
              diferencia      = :dif
          WHERE id = :id";
  $st2 = $pdo->prepare($sql);
  $st2->execute([
    ':sis' => $saldoSistema,
    ':dec' => $saldoDeclarado,
    ':dif' => $diferencia,
    ':id'  => $cajaId,
  ]);
}

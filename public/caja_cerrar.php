<?php
// public/caja_cerrar.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/caja_lib.php';

// POS: login + terminal elegido + lock OK
require_pos();

// permiso
require_permission('cerrar_caja');

function caja_is_open($fechaCierre): bool {
  $fc = (string)($fechaCierre ?? '');
  return ($fc === '' || $fc === '0000-00-00 00:00:00');
}

function table_exists(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
  $st->execute([$table]);
  return (bool)$st->fetchColumn();
}

function column_exists(PDO $pdo, string $table, string $column): bool {
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

function flus_norm_medio(?string $m): string {
  $m = strtoupper(trim((string)$m));
  if ($m === '' || $m === 'EFECTIVO' || $m === 'CASH') return 'EFECTIVO';
  if ($m === 'MERCADOPAGO') return 'MP';
  if ($m === 'DEBIT') return 'DEBITO';
  if ($m === 'CREDIT') return 'CREDITO';
  return $m;
}

/**
 * Resumen de movimientos por medio:
 * retorna:
 * [
 *   'EFECTIVO' => ['ingresos'=>x, 'egresos'=>y, 'neto'=>x-y],
 *   'MP' => ...
 * ]
 * Si NO existe caja_movimientos.medio_pago => todo se toma como EFECTIVO (compat).
 */
function flus_mov_resumen_por_medio(PDO $pdo, int $cajaId): array {
  $base = [
    'EFECTIVO' => ['ingresos' => 0.0, 'egresos' => 0.0, 'neto' => 0.0],
    'MP' => ['ingresos' => 0.0, 'egresos' => 0.0, 'neto' => 0.0],
    'DEBITO' => ['ingresos' => 0.0, 'egresos' => 0.0, 'neto' => 0.0],
    'CREDITO' => ['ingresos' => 0.0, 'egresos' => 0.0, 'neto' => 0.0],
    'TRANSFERENCIA' => ['ingresos' => 0.0, 'egresos' => 0.0, 'neto' => 0.0],
    'MODO' => ['ingresos' => 0.0, 'egresos' => 0.0, 'neto' => 0.0],
    'QR' => ['ingresos' => 0.0, 'egresos' => 0.0, 'neto' => 0.0],
  ];

  $hasMedio = false;
  try {
    $stCol = $pdo->query("
      SELECT COUNT(*)
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'caja_movimientos'
        AND COLUMN_NAME = 'medio_pago'
    ");
    $hasMedio = ((int)$stCol->fetchColumn() > 0);
  } catch (Throwable $e) {
    $hasMedio = false;
  }

  if ($hasMedio) {
    $st = $pdo->prepare("
      SELECT
        UPPER(COALESCE(medio_pago,'EFECTIVO')) AS medio,
        COALESCE(SUM(CASE WHEN UPPER(tipo)='INGRESO' THEN monto ELSE 0 END),0) AS ingresos,
        COALESCE(SUM(CASE WHEN UPPER(tipo)='EGRESO'  THEN monto ELSE 0 END),0) AS egresos
      FROM caja_movimientos
      WHERE caja_id = ?
      GROUP BY UPPER(COALESCE(medio_pago,'EFECTIVO'))
    ");
    $st->execute([$cajaId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $m = flus_norm_medio($r['medio'] ?? 'EFECTIVO');
      if (!isset($base[$m])) {
        $base[$m] = ['ingresos' => 0.0, 'egresos' => 0.0, 'neto' => 0.0];
      }
      $ing = (float)($r['ingresos'] ?? 0);
      $egr = (float)($r['egresos'] ?? 0);
      $base[$m]['ingresos'] += $ing;
      $base[$m]['egresos'] += $egr;
      $base[$m]['neto'] += ($ing - $egr);
    }
    return $base;
  }

  // compat legacy (sin medio_pago): todo EFECTIVO
  $st = $pdo->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN UPPER(tipo)='INGRESO' THEN monto ELSE 0 END),0) AS ingresos,
      COALESCE(SUM(CASE WHEN UPPER(tipo)='EGRESO'  THEN monto ELSE 0 END),0) AS egresos
    FROM caja_movimientos
    WHERE caja_id = ?
  ");
  $st->execute([$cajaId]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  $ing = (float)($row['ingresos'] ?? 0);
  $egr = (float)($row['egresos'] ?? 0);
  $base['EFECTIVO']['ingresos'] = $ing;
  $base['EFECTIVO']['egresos'] = $egr;
  $base['EFECTIVO']['neto'] = $ing - $egr;

  return $base;
}

$terminalId = (int)($_SESSION['terminal_id'] ?? current_terminal_id());

/* ----------------------------------------------------
   1) OBTENER ID DE CAJA (GET o última abierta)
---------------------------------------------------- */
$cajaId = (int)($_GET['id'] ?? 0);

if ($cajaId <= 0) {
  $ab = caja_get_abierta($pdo, $terminalId);
  $cajaId = (int)($ab['id'] ?? 0);
}
if ($cajaId <= 0) {
  header('Location: caja.php');
  exit;
}

/* ----------------------------------------------------
   2) LEER SESIÓN DE CAJA + USUARIO
---------------------------------------------------- */
$stmt = $pdo->prepare("
  SELECT cs.*, u.username
  FROM caja_sesiones cs
  JOIN users u ON u.id = cs.user_id
  WHERE cs.id = ?
  LIMIT 1
");
$stmt->execute([$cajaId]);
$caja = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$caja) {
  http_response_code(404);
  echo 'Sesión de caja no encontrada';
  exit;
}

$abierta       = caja_is_open($caja['fecha_cierre'] ?? null);
$saldoInicial  = (float)($caja['saldo_inicial'] ?? 0);
$usernameCaja  = (string)($caja['username'] ?? '—');
$fechaApertura = (string)($caja['fecha_apertura'] ?? '');

/* ----------------------------------------------------
   3) RESUMEN DE VENTAS DEL TURNO (solo EMITIDA/NULL)
      ✅ Split payments: usar venta_pagos si existe.
---------------------------------------------------- */
$porMedio = [
  'EFECTIVO' => 0.0,
  'MP' => 0.0,
  'DEBITO' => 0.0,
  'CREDITO' => 0.0,
  'TRANSFERENCIA' => 0.0,
  'MODO' => 0.0,
  'QR' => 0.0,
];

// ✅ Total ventas SIEMPRE desde ventas.total (no desde pagos)
$stmt = $pdo->prepare("
  SELECT COALESCE(SUM(total),0)
  FROM ventas
  WHERE caja_id = ?
    AND (estado IS NULL OR estado = 'EMITIDA')
");
$stmt->execute([$cajaId]);
$totalVentas = (float)($stmt->fetchColumn() ?: 0.0);

// ¿Existe tabla de pagos?
$hasVentaPagos = table_exists($pdo, 'venta_pagos');

$usoPagos = false;
$totalVuelto = 0.0;

if ($hasVentaPagos) {
  // Sumatoria por medio desde venta_pagos (join a ventas del turno)
  $stP = $pdo->prepare("
    SELECT vp.medio_pago, COALESCE(SUM(vp.monto),0) AS total
    FROM ventas v
    JOIN venta_pagos vp ON vp.venta_id = v.id
    WHERE v.caja_id = ?
      AND (v.estado IS NULL OR v.estado = 'EMITIDA')
    GROUP BY vp.medio_pago
  ");
  $stP->execute([$cajaId]);
  $rows = $stP->fetchAll(PDO::FETCH_ASSOC) ?: [];

  if ($rows) {
    $usoPagos = true;
    foreach ($rows as $row) {
      $medio = flus_norm_medio((string)($row['medio_pago'] ?? ''));
      $total = (float)($row['total'] ?? 0);
      if (isset($porMedio[$medio])) {
        $porMedio[$medio] = round($total, 2);
      }
    }

    // ✅ Vuelto: restar del EFECTIVO para tener efectivo neto real
    $stV = $pdo->prepare("
      SELECT COALESCE(SUM(vuelto),0)
      FROM ventas
      WHERE caja_id = ?
        AND (estado IS NULL OR estado = 'EMITIDA')
    ");
    $stV->execute([$cajaId]);
    $totalVuelto = (float)($stV->fetchColumn() ?: 0.0);

    if ($totalVuelto > 0.00001) {
      $porMedio['EFECTIVO'] = round(max(0.0, $porMedio['EFECTIVO'] - $totalVuelto), 2);
    }
  }
}

if (!$usoPagos) {
  // Fallback legacy: cuando NO hay venta_pagos o no hay filas cargadas
  $stL = $pdo->prepare("
    SELECT medio_pago, COALESCE(SUM(total),0) AS total
    FROM ventas
    WHERE caja_id = ?
      AND (estado IS NULL OR estado = 'EMITIDA')
    GROUP BY medio_pago
  ");
  $stL->execute([$cajaId]);
  foreach ($stL->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $medio = flus_norm_medio((string)($row['medio_pago'] ?? ''));
    $total = (float)($row['total'] ?? 0);
    if (isset($porMedio[$medio])) {
      $porMedio[$medio] = round($total, 2);
    }
  }
}

// Ítems vendidos del turno (solo EMITIDA/NULL)
$stmt = $pdo->prepare("
  SELECT COALESCE(SUM(vi.cantidad),0) AS cant
  FROM ventas v
  JOIN venta_items vi ON vi.venta_id = v.id
  WHERE v.caja_id = ?
    AND (v.estado IS NULL OR v.estado = 'EMITIDA')
");
$stmt->execute([$cajaId]);
$itemsVendidos = (float)($stmt->fetchColumn() ?: 0);

// Anulaciones (conteo)
$stmt = $pdo->prepare("
  SELECT COUNT(*)
  FROM ventas
  WHERE caja_id = ?
    AND estado IS NOT NULL
    AND UPPER(estado) LIKE '%ANUL%'
");
$stmt->execute([$cajaId]);
$totalAnulaciones = (int)($stmt->fetchColumn() ?: 0);

/* ----------------------------------------------------
   3.1) MOVIMIENTOS DE CAJA (por medio si existe medio_pago)
   ✅ saldoSistema SOLO efectivo
---------------------------------------------------- */
$movResumen = flus_mov_resumen_por_medio($pdo, $cajaId);

$movIngresosEfe = (float)($movResumen['EFECTIVO']['ingresos'] ?? 0.0);
$movEgresosEfe  = (float)($movResumen['EFECTIVO']['egresos'] ?? 0.0);
$movNetoEfe     = (float)($movResumen['EFECTIVO']['neto'] ?? 0.0);

// ✅ Efectivo esperado (neto real)
$saldoSistema = $saldoInicial + $porMedio['EFECTIVO'] + $movNetoEfe;

// Totales “esperados” por medio (informativo)
$esperadoPorMedio = [
  'EFECTIVO' => $saldoSistema,
  'MP' => (float)$porMedio['MP'] + (float)($movResumen['MP']['neto'] ?? 0.0),
  'DEBITO' => (float)$porMedio['DEBITO'] + (float)($movResumen['DEBITO']['neto'] ?? 0.0),
  'CREDITO' => (float)$porMedio['CREDITO'] + (float)($movResumen['CREDITO']['neto'] ?? 0.0),
  'TRANSFERENCIA' => (float)$porMedio['TRANSFERENCIA'] + (float)($movResumen['TRANSFERENCIA']['neto'] ?? 0.0),
  'MODO' => (float)$porMedio['MODO'] + (float)($movResumen['MODO']['neto'] ?? 0.0),
  'QR' => (float)$porMedio['QR'] + (float)($movResumen['QR']['neto'] ?? 0.0),
];

/* ----------------------------------------------------
   4) PROCESAR CIERRE (POST)
---------------------------------------------------- */
$errores = [];
$saldoDeclarado = null;
$diferencia = 0.0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if (!$abierta) {
    $errores[] = 'La caja ya estaba cerrada.';
  } else {

    // CSRF
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!csrf_verify($token)) {
      $errores[] = 'Token inválido. Recargá la página e intentá de nuevo.';
    } else {

      $rawSaldo = (string)($_POST['saldo_declarado'] ?? '');
      $saldoDeclarado = parse_money_ar($rawSaldo);
      $notas = trim((string)($_POST['notas'] ?? ''));

      if (trim($rawSaldo) === '') {
        $errores[] = 'Ingresá el saldo contado por el cajero.';
      } elseif ($saldoDeclarado < 0) {
        $errores[] = 'El saldo declarado no puede ser negativo.';
      } else {

        $diferencia = $saldoDeclarado - $saldoSistema;

        $pdo->beginTransaction();
        try {
          $stLock = $pdo->prepare("SELECT fecha_cierre FROM caja_sesiones WHERE id = ? FOR UPDATE");
          $stLock->execute([$cajaId]);
          $fechaCierreActual = $stLock->fetchColumn();

          if (!caja_is_open($fechaCierreActual)) {
            $pdo->rollBack();
            $errores[] = 'No se pudo cerrar la caja: ya estaba cerrada.';
          } else {

            // Update dinámico (compat si faltan columnas nuevas)
            $setParts = [
              "fecha_cierre = NOW()",
              "saldo_sistema = ?",
              "saldo_declarado = ?",
              "diferencia = ?",
              "notas = ?",
              "total_ventas = ?",
              "total_efectivo = ?",
              "total_mp = ?",
              "total_debito = ?",
              "total_credito = ?",
              "total_productos = ?",
              "total_anulaciones = ?",
            ];

            $params = [
              $saldoSistema,
              $saldoDeclarado,
              $diferencia,
              $notas,
              $totalVentas,
              $porMedio['EFECTIVO'],
              $porMedio['MP'],
              $porMedio['DEBITO'],
              $porMedio['CREDITO'],
              $itemsVendidos,
              $totalAnulaciones,
            ];

            if (column_exists($pdo, 'caja_sesiones', 'total_transferencia')) {
              $setParts[] = "total_transferencia = ?";
              $params[] = $porMedio['TRANSFERENCIA'];
            }
            if (column_exists($pdo, 'caja_sesiones', 'total_modo')) {
              $setParts[] = "total_modo = ?";
              $params[] = $porMedio['MODO'];
            }
            if (column_exists($pdo, 'caja_sesiones', 'total_qr')) {
              $setParts[] = "total_qr = ?";
              $params[] = $porMedio['QR'];
            }

            $sqlUpd = "
              UPDATE caja_sesiones
              SET " . implode(",\n                  ", $setParts) . "
              WHERE id = ?
                AND (fecha_cierre IS NULL OR fecha_cierre = '0000-00-00 00:00:00')
            ";

            $params[] = $cajaId;

            $stUpd = $pdo->prepare($sqlUpd);
            $stUpd->execute($params);

            if ($stUpd->rowCount() === 0) {
              $pdo->rollBack();
              $errores[] = 'No se pudo cerrar la caja (ya estaba cerrada o ID inválido).';
            } else {
              $pdo->commit();
              header('Location: caja_historial.php');
              exit;
            }
          }
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          $errores[] = 'Error al cerrar caja: ' . $e->getMessage();
        }
      }
    }
  }
}

/* ----------------------------------------------------
   5) SI YA ESTÁ CERRADA, USAR DATOS GUARDADOS (snapshot)
---------------------------------------------------- */
if (!$abierta) {
  if ($caja['saldo_sistema'] !== null) $saldoSistema = (float)$caja['saldo_sistema'];
  $saldoDeclarado = ($caja['saldo_declarado'] !== null) ? (float)$caja['saldo_declarado'] : 0.0;
  $diferencia     = ($caja['diferencia'] !== null) ? (float)$caja['diferencia'] : 0.0;

  // snapshot de totales guardados (si están)
  if (isset($caja['total_efectivo'])) $porMedio['EFECTIVO'] = (float)$caja['total_efectivo'];
  if (isset($caja['total_mp'])) $porMedio['MP'] = (float)$caja['total_mp'];
  if (isset($caja['total_debito'])) $porMedio['DEBITO'] = (float)$caja['total_debito'];
  if (isset($caja['total_credito'])) $porMedio['CREDITO'] = (float)$caja['total_credito'];
  if (isset($caja['total_transferencia'])) $porMedio['TRANSFERENCIA'] = (float)$caja['total_transferencia'];
  if (isset($caja['total_modo'])) $porMedio['MODO'] = (float)$caja['total_modo'];
  if (isset($caja['total_qr'])) $porMedio['QR'] = (float)$caja['total_qr'];
}

/* ----------------------------------------------------
   6) HEADER GLOBAL
---------------------------------------------------- */
$pageTitle      = 'Cierre de caja - Apertura #' . $cajaId;
$currentSection = 'caja';
$extraCss       = ['assets/css/caja_cerrar.css'];

require __DIR__ . '/partials/header.php';
?>

<div class="panel panel-cierre">

  <header class="cierre-header">
    <div>
      <div class="cierre-badge">Cierre de caja</div>
      <h1 class="cierre-title">Apertura #<?= (int)$cajaId ?></h1>
      <div class="cierre-meta">
        Realizado por
        <span class="strong"><?= h($usernameCaja) ?></span>
        · Desde <?= h($fechaApertura) ?>
        <?php if (!$abierta && !empty($caja['fecha_cierre'])): ?>
          · Hasta <?= h((string)$caja['fecha_cierre']) ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="cierre-status">
      <?php if ($abierta): ?>
        <span class="pill pill-open">Caja abierta</span>
      <?php else: ?>
        <span class="pill pill-closed">Caja cerrada</span>
      <?php endif; ?>
    </div>
  </header>

  <div class="cierre-grid">

    <section class="cierre-card cierre-resumen">
      <h2 class="cierre-section-title">Resumen del turno</h2>
      <ul class="cierre-list">
        <li class="cierre-row">
          <span>Saldo inicial</span>
          <span class="mono"><?= money_ar($saldoInicial) ?></span>
        </li>

        <li class="cierre-row">
          <span>Ventas en efectivo (neto)</span>
          <span class="mono"><?= money_ar($porMedio['EFECTIVO']) ?></span>
        </li>

        <li class="cierre-row">
          <span>Mov. efectivo ingreso</span>
          <span class="mono"><?= money_ar($movIngresosEfe) ?></span>
        </li>
        <li class="cierre-row">
          <span>Mov. efectivo egreso</span>
          <span class="mono"><?= money_ar($movEgresosEfe) ?></span>
        </li>

        <li class="cierre-row">
          <span>Total Mercado Pago</span>
          <span class="mono"><?= money_ar($porMedio['MP']) ?></span>
        </li>
        <li class="cierre-row">
          <span>Mov. MP (neto)</span>
          <span class="mono"><?= money_ar((float)($movResumen['MP']['neto'] ?? 0)) ?></span>
        </li>

        <li class="cierre-row">
          <span>Total Débito</span>
          <span class="mono"><?= money_ar($porMedio['DEBITO']) ?></span>
        </li>
        <li class="cierre-row">
          <span>Mov. Débito (neto)</span>
          <span class="mono"><?= money_ar((float)($movResumen['DEBITO']['neto'] ?? 0)) ?></span>
        </li>

        <li class="cierre-row">
          <span>Total Crédito</span>
          <span class="mono"><?= money_ar($porMedio['CREDITO']) ?></span>
        </li>
        <li class="cierre-row">
          <span>Mov. Crédito (neto)</span>
          <span class="mono"><?= money_ar((float)($movResumen['CREDITO']['neto'] ?? 0)) ?></span>
        </li>

        <li class="cierre-row">
          <span>Total Transferencia</span>
          <span class="mono"><?= money_ar($porMedio['TRANSFERENCIA']) ?></span>
        </li>
        <li class="cierre-row">
          <span>Mov. Transferencia (neto)</span>
          <span class="mono"><?= money_ar((float)($movResumen['TRANSFERENCIA']['neto'] ?? 0)) ?></span>
        </li>

        <li class="cierre-row">
          <span>Total MODO</span>
          <span class="mono"><?= money_ar($porMedio['MODO']) ?></span>
        </li>
        <li class="cierre-row">
          <span>Mov. MODO (neto)</span>
          <span class="mono"><?= money_ar((float)($movResumen['MODO']['neto'] ?? 0)) ?></span>
        </li>

        <li class="cierre-row">
          <span>Total QR</span>
          <span class="mono"><?= money_ar($porMedio['QR']) ?></span>
        </li>
        <li class="cierre-row">
          <span>Mov. QR (neto)</span>
          <span class="mono"><?= money_ar((float)($movResumen['QR']['neto'] ?? 0)) ?></span>
        </li>

        <li class="cierre-row cierre-row-simple">
          <span>Ítems vendidos (cantidad)</span>
          <span class="mono"><?= h((string)$itemsVendidos) ?></span>
        </li>

        <li class="cierre-row cierre-row-simple">
          <span>Anulaciones</span>
          <span class="mono"><?= (int)$totalAnulaciones ?></span>
        </li>
      </ul>
    </section>

    <section class="cierre-card cierre-total">
      <div class="cierre-total-label">Efectivo esperado (sistema)</div>
      <div class="cierre-total-amount"><?= money_ar($saldoSistema) ?></div>
      <div class="cierre-total-sub">
        Saldo inicial + efectivo neto + mov. efectivo netos
      </div>

      <div class="cierre-total-extra">
        <div class="cierre-total-line">
          <span>Esperado MP</span>
          <span class="mono"><?= money_ar((float)($esperadoPorMedio['MP'] ?? 0)) ?></span>
        </div>
        <div class="cierre-total-line">
          <span>Esperado Débito</span>
          <span class="mono"><?= money_ar((float)($esperadoPorMedio['DEBITO'] ?? 0)) ?></span>
        </div>
        <div class="cierre-total-line">
          <span>Esperado Crédito</span>
          <span class="mono"><?= money_ar((float)($esperadoPorMedio['CREDITO'] ?? 0)) ?></span>
        </div>
        <div class="cierre-total-line">
          <span>Esperado Transferencia</span>
          <span class="mono"><?= money_ar((float)($esperadoPorMedio['TRANSFERENCIA'] ?? 0)) ?></span>
        </div>
        <div class="cierre-total-line">
          <span>Esperado MODO</span>
          <span class="mono"><?= money_ar((float)($esperadoPorMedio['MODO'] ?? 0)) ?></span>
        </div>
        <div class="cierre-total-line">
          <span>Esperado QR</span>
          <span class="mono"><?= money_ar((float)($esperadoPorMedio['QR'] ?? 0)) ?></span>
        </div>
      </div>

      <?php if (!$abierta): ?>
        <div class="cierre-total-extra" style="margin-top: 10px;">
          <div class="cierre-total-line">
            <span>Saldo contado por el cajero</span>
            <span class="mono"><?= money_ar((float)($saldoDeclarado ?? 0)) ?></span>
          </div>
          <div class="cierre-total-line">
            <span>Diferencia</span>
            <?php
              $classDif = 'dif-ok';
              if ($diferencia > 0.009)  $classDif = 'dif-pos';
              if ($diferencia < -0.009) $classDif = 'dif-neg';
            ?>
            <span class="mono <?= $classDif ?>"><?= money_ar($diferencia) ?></span>
          </div>
        </div>
      <?php endif; ?>
    </section>

  </div>

  <section class="cierre-card cierre-conteo">
    <h2 class="cierre-section-title">Conteo de caja</h2>

    <?php if (!empty($errores)): ?>
      <div class="cierre-error"><?= h(implode(' ', $errores)) ?></div>
    <?php endif; ?>

    <?php if ($abierta): ?>
      <form method="post" class="cierre-form">
        <?= csrf_field() ?>

        <label for="saldo_declarado" class="cierre-label">
          Saldo contado por el cajero
        </label>

        <div class="cierre-input-row">
          <input
            type="text"
            id="saldo_declarado"
            name="saldo_declarado"
            class="cierre-input"
            placeholder="Ej: 11.200,00"
            autocomplete="off"
            value="<?= $saldoDeclarado !== null ? h(number_format((float)$saldoDeclarado, 2, ',', '.')) : '' ?>"
          >
          <button type="submit" class="btn btn-primary cierre-btn">
            Cerrar caja
          </button>
        </div>

        <label for="notas" class="cierre-label cierre-label-notas">
          Notas (opcional)
        </label>
        <textarea
          id="notas"
          name="notas"
          class="cierre-textarea"
          rows="2"
          placeholder="Observaciones del turno, diferencias, etc."><?= h((string)($_POST['notas'] ?? ($caja['notas'] ?? ''))) ?></textarea>
      </form>

    <?php else: ?>

      <div class="cierre-cerrada-info">
        <div class="cierre-total-line">
          <span>Saldo contado por el cajero</span>
          <span class="mono"><?= money_ar((float)($saldoDeclarado ?? 0)) ?></span>
        </div>

        <div class="cierre-total-line">
          <span>Diferencia</span>
          <?php
            $classDif = 'dif-ok';
            if ($diferencia > 0.009)  $classDif = 'dif-pos';
            if ($diferencia < -0.009) $classDif = 'dif-neg';
          ?>
          <span class="mono <?= $classDif ?>"><?= money_ar($diferencia) ?></span>
        </div>

        <?php if (!empty($caja['notas'])): ?>
          <div class="cierre-notas">
            <?= nl2br(h((string)$caja['notas'])) ?>
          </div>
        <?php endif; ?>
      </div>

    <?php endif; ?>
  </section>

</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

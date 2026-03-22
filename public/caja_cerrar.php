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
  if (function_exists('flus_table_exists')) {
    return (bool)flus_table_exists($pdo, $table);
  }

  if (function_exists('has_table')) {
    return (bool)has_table($pdo, $table);
  }

  return false;
}

function col_exists(PDO $pdo, string $table, string $column): bool
{
    // Evita inyección si alguien pasa nombres raros
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $column)) return false;

    if (function_exists('flus_column_exists')) {
        return (bool)flus_column_exists($pdo, $table, $column);
    }

    if (function_exists('has_column')) {
        return (bool)has_column($pdo, $table, $column);
    }

    return false;
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
   3) TOTALES POR MEDIO DE PAGO
   
   ⚠️ CRÍTICO: Usar los valores ya acumulados en caja_sesiones
   (incluyen ventas + cobros CC del turno).
   NO recalcular desde ventas porque eso pierde los cobros CC.
---------------------------------------------------- */

// Totales por medio YA ACUMULADOS en caja_sesiones (fuente de verdad)
$totEfectivo     = (float)($caja['total_efectivo'] ?? 0);
$totMp           = (float)($caja['total_mp'] ?? 0);
$totDebito       = (float)($caja['total_debito'] ?? 0);
$totCredito      = (float)($caja['total_credito'] ?? 0);
$hasTransferCol  = col_exists($pdo, 'caja_sesiones', 'total_transferencia');
$totTransferencia = $hasTransferCol ? (float)($caja['total_transferencia'] ?? 0) : 0;

// Total ventas (para mostrar, no para calcular caja)
$stmt = $pdo->prepare("
  SELECT COALESCE(SUM(total),0)
  FROM ventas
  WHERE caja_id = ?
    AND (estado IS NULL OR estado <> 'ANULADA')
");
$stmt->execute([$cajaId]);
$totalVentas = (float)($stmt->fetchColumn() ?: 0.0);

// Total vendido a CC (informativo)
$hasMontoCC = col_exists($pdo, 'ventas', 'monto_cc');
$totalVentasCC = 0.0;
if ($hasMontoCC) {
  $stmt = $pdo->prepare("
    SELECT COALESCE(SUM(monto_cc),0)
    FROM ventas
    WHERE caja_id = ?
      AND (estado IS NULL OR estado <> 'ANULADA')
  ");
  $stmt->execute([$cajaId]);
  $totalVentasCC = (float)($stmt->fetchColumn() ?: 0.0);
}

// Ítems vendidos del turno (solo EMITIDA/NULL)
$stmt = $pdo->prepare("
  SELECT COALESCE(SUM(vi.cantidad),0) AS cant
  FROM ventas v
  JOIN venta_items vi ON vi.venta_id = v.id
  WHERE v.caja_id = ?
    AND (v.estado IS NULL OR v.estado <> 'ANULADA')
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
   3.1) MOVIMIENTOS MANUALES DE CAJA (SOLO EFECTIVO)
   
   ⚠️ CRÍTICO: Excluir cobros de CC (ya están en totales)
   y solo contar movimientos manuales en EFECTIVO.
---------------------------------------------------- */

// Detectar columnas para filtrado
$hasCCMovCol = col_exists($pdo, 'caja_movimientos', 'cc_movimiento_id');
$hasMedioPagoMovCol = col_exists($pdo, 'caja_movimientos', 'medio_pago');

// Construir WHERE para excluir cobros CC y filtrar solo efectivo
$whereMovEfectivo = "caja_id = ?";

if ($hasCCMovCol) {
  // Excluir movimientos que son cobros de CC
  $whereMovEfectivo .= " AND (cc_movimiento_id IS NULL OR cc_movimiento_id = 0)";
}

if ($hasMedioPagoMovCol) {
  // Solo efectivo o movimientos viejos sin medio_pago asignado
  $whereMovEfectivo .= " AND (medio_pago IS NULL OR UPPER(medio_pago) = 'EFECTIVO')";
}

$stmt = $pdo->prepare("
  SELECT
    COALESCE(SUM(CASE WHEN UPPER(tipo)='INGRESO' THEN monto ELSE 0 END),0) AS ingresos,
    COALESCE(SUM(CASE WHEN UPPER(tipo)='EGRESO'  THEN monto ELSE 0 END),0) AS egresos
  FROM caja_movimientos
  WHERE {$whereMovEfectivo}
");
$stmt->execute([$cajaId]);
$rowMov = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$movEfectivoIngresos = (float)($rowMov['ingresos'] ?? 0);
$movEfectivoEgresos  = (float)($rowMov['egresos']  ?? 0);

// Totales de TODOS los movimientos (para mostrar en UI)
$stmt = $pdo->prepare("
  SELECT
    COALESCE(SUM(CASE WHEN UPPER(tipo)='INGRESO' THEN monto ELSE 0 END),0) AS ingresos,
    COALESCE(SUM(CASE WHEN UPPER(tipo)='EGRESO'  THEN monto ELSE 0 END),0) AS egresos
  FROM caja_movimientos
  WHERE caja_id = ?
");
$stmt->execute([$cajaId]);
$rowMovAll = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$movIngresos = (float)($rowMovAll['ingresos'] ?? 0);
$movEgresos  = (float)($rowMovAll['egresos']  ?? 0);

/* ----------------------------------------------------
   3.2) SALDO SISTEMA (EFECTIVO ESPERADO)
   
   Fórmula correcta:
   = saldo_inicial
   + total_efectivo (ya incluye ventas efectivo neto + cobros CC efectivo)
   + movimientos manuales efectivo (ingresos - egresos)
   
   ⚠️ NO incluye transferencias, MP, débito, crédito, ni cobros CC por otros medios
---------------------------------------------------- */
$saldoSistema = $saldoInicial + $totEfectivo + $movEfectivoIngresos - $movEfectivoEgresos;

// Para compatibilidad con la UI que muestra $porMedio
$porMedio = [
  'EFECTIVO'      => $totEfectivo,
  'MP'            => $totMp,
  'DEBITO'        => $totDebito,
  'CREDITO'       => $totCredito,
  'TRANSFERENCIA' => $totTransferencia,
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

            // ═══════════════════════════════════════════════════════════════
            // UPDATE DE CIERRE
            // 
            // ⚠️ CRÍTICO: NO pisamos total_efectivo, total_mp, etc.
            // porque ya fueron acumulados correctamente durante el turno
            // (incluyen ventas + cobros de CC).
            // 
            // Solo actualizamos: fecha_cierre, saldo_sistema, saldo_declarado,
            // diferencia, notas, total_ventas, total_productos, total_anulaciones.
            // ═══════════════════════════════════════════════════════════════
            
            $stUpd = $pdo->prepare("
              UPDATE caja_sesiones
              SET
                fecha_cierre      = NOW(),
                saldo_sistema     = ?,
                saldo_declarado   = ?,
                diferencia        = ?,
                notas             = ?,
                total_ventas      = ?,
                total_productos   = ?,
                total_anulaciones = ?
              WHERE id = ?
                AND (fecha_cierre IS NULL OR fecha_cierre = '0000-00-00 00:00:00')
            ");

            $stUpd->execute([
              $saldoSistema,
              $saldoDeclarado,
              $diferencia,
              $notas,
              $totalVentas,
              $itemsVendidos,
              $totalAnulaciones,
              $cajaId,
            ]);

            if ($stUpd->rowCount() === 0) {
              $pdo->rollBack();
              $errores[] = 'No se pudo cerrar la caja (ya estaba cerrada o ID inválido).';
            } else {
              $pdo->commit();
              $canVerHistorialCaja = function_exists('user_has_permission') && user_has_permission('ver_historial_caja');
              $redirectTarget = $canVerHistorialCaja
                ? 'caja_historial.php?ok=' . urlencode('Caja cerrada correctamente.')
                : 'caja.php?ok=' . urlencode('Caja cerrada correctamente.');
              header('Location: ' . $redirectTarget);
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
   5) SI YA ESTÁ CERRADA, USAR DATOS GUARDADOS
---------------------------------------------------- */
if (!$abierta) {
  if ($caja['saldo_sistema'] !== null) $saldoSistema = (float)$caja['saldo_sistema'];
  $saldoDeclarado = ($caja['saldo_declarado'] !== null) ? (float)$caja['saldo_declarado'] : 0.0;
  $diferencia     = ($caja['diferencia'] !== null) ? (float)$caja['diferencia'] : 0.0;
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
          <span>Total Efectivo (ventas + cobros CC)</span>
          <span class="mono"><?= money_ar($porMedio['EFECTIVO']) ?></span>
        </li>

        <li class="cierre-row cierre-row-simple">
          <span>Mov. manuales efectivo (ingreso)</span>
          <span class="mono"><?= money_ar($movEfectivoIngresos) ?></span>
        </li>
        <li class="cierre-row cierre-row-simple">
          <span>Mov. manuales efectivo (egreso)</span>
          <span class="mono"><?= money_ar($movEfectivoEgresos) ?></span>
        </li>

        <li class="cierre-row">
          <span>Total Mercado Pago</span>
          <span class="mono"><?= money_ar($porMedio['MP']) ?></span>
        </li>
        <li class="cierre-row">
          <span>Total Débito</span>
          <span class="mono"><?= money_ar($porMedio['DEBITO']) ?></span>
        </li>
        <li class="cierre-row">
          <span>Total Crédito</span>
          <span class="mono"><?= money_ar($porMedio['CREDITO']) ?></span>
        </li>
        <li class="cierre-row">
          <span>Total Transferencia</span>
          <span class="mono"><?= money_ar($porMedio['TRANSFERENCIA']) ?></span>
        </li>

        <?php if ($totalVentasCC > 0): ?>
        <li class="cierre-row" style="border-top: 1px dashed var(--border-color, #444); padding-top: 8px; margin-top: 8px;">
          <span>📋 Ventas a Cuenta Corriente</span>
          <span class="mono" style="color: var(--warning-color, #fbbf24);"><?= money_ar($totalVentasCC) ?></span>
        </li>
        <?php endif; ?>

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
        Saldo inicial + efectivo acumulado + mov. manuales efectivo
      </div>

      <?php if (!$abierta): ?>
        <div class="cierre-total-extra">
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

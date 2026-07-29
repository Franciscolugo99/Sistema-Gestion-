<?php
// public/caja_cerrar.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/caja_lib.php';
require_once __DIR__ . '/../src/venta_anulaciones_lib.php';
require_once FLUS_ROOT . '/src/cloud_sync_lib.php';

// permiso
require_permission('cerrar_caja');
// POS: login + terminal elegido + lock OK
require_pos();

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

final class FlusCajaCierreValidationException extends RuntimeException {}

function caja_cierre_recalcular_criticos(
  PDO $pdo,
  array $caja,
  int $cajaId,
  string $anulacionesJoinVentas,
  string $importeVigenteExpr,
  string $anulacionesItemsJoin,
  string $cantidadVigenteExpr,
  string $whereMovEfectivo
): array {
  $st = $pdo->prepare("\n    SELECT COALESCE(SUM($importeVigenteExpr), 0)\n    FROM ventas v\n    $anulacionesJoinVentas\n    WHERE v.caja_id = ?\n      AND (v.estado IS NULL OR v.estado <> 'ANULADA')\n  ");
  $st->execute([$cajaId]);
  $totalVentas = (float)($st->fetchColumn() ?: 0);

  $st = $pdo->prepare("\n    SELECT COALESCE(SUM($cantidadVigenteExpr), 0)\n    FROM ventas v\n    JOIN venta_items vi ON vi.venta_id = v.id\n    $anulacionesItemsJoin\n    WHERE v.caja_id = ?\n      AND (v.estado IS NULL OR v.estado <> 'ANULADA')\n  ");
  $st->execute([$cajaId]);
  $itemsVendidos = (float)($st->fetchColumn() ?: 0);

  $st = $pdo->prepare("\n    SELECT COUNT(*)\n    FROM ventas\n    WHERE caja_id = ?\n      AND estado IS NOT NULL\n      AND UPPER(estado) LIKE '%ANUL%'\n  ");
  $st->execute([$cajaId]);
  $totalAnulaciones = (int)($st->fetchColumn() ?: 0);

  $st = $pdo->prepare("\n    SELECT\n      COALESCE(SUM(CASE WHEN UPPER(tipo) = 'INGRESO' THEN monto ELSE 0 END), 0) AS ingresos,\n      COALESCE(SUM(CASE WHEN UPPER(tipo) = 'EGRESO' THEN monto ELSE 0 END), 0) AS egresos\n    FROM caja_movimientos\n    WHERE {$whereMovEfectivo}\n  ");
  $st->execute([$cajaId]);
  $movimientos = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  $saldoInicial = (float)($caja['saldo_inicial'] ?? 0);
  $totalEfectivo = (float)($caja['total_efectivo'] ?? 0);
  $ingresos = (float)($movimientos['ingresos'] ?? 0);
  $egresos = (float)($movimientos['egresos'] ?? 0);

  return [
    'saldo_sistema' => $saldoInicial + $totalEfectivo + $ingresos - $egresos,
    'total_ventas' => $totalVentas,
    'total_productos' => $itemsVendidos,
    'total_anulaciones' => $totalAnulaciones,
  ];
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

$currentUserId = (int)($user['id'] ?? 0);
if (!caja_user_can_cerrar_turno($caja, $currentUserId)) {
  http_response_code(403);
  echo 'No podes cerrar un turno abierto por ' . h(caja_turno_owner_label($caja)) . '.';
  exit;
}

$cierrePorSupervisor = !caja_turno_es_del_usuario($caja, $currentUserId);
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
$hasCierreMotivoCol = col_exists($pdo, 'caja_sesiones', 'cierre_motivo');
$hasCerradoPorCol = col_exists($pdo, 'caja_sesiones', 'cerrado_por_user_id');
$hasFondoSiguienteCol = col_exists($pdo, 'caja_sesiones', 'cierre_fondo_siguiente');
$hasRetiroEfectivoCol = col_exists($pdo, 'caja_sesiones', 'cierre_retiro_efectivo');
$anulacionesJoinVentas = flus_venta_anulaciones_totales_join_sql($pdo, 'v', 'vaa');
$montoAnuladoVentasExpr = $anulacionesJoinVentas !== '' ? 'COALESCE(vaa.monto_anulado_total, 0)' : '0';
$importeVigenteExpr = flus_venta_importe_vigente_expr_sql('v.total', $montoAnuladoVentasExpr);

// Total ventas (para mostrar, no para calcular caja)
$stmt = $pdo->prepare("
  SELECT COALESCE(SUM($importeVigenteExpr),0)
  FROM ventas v
  $anulacionesJoinVentas
  WHERE v.caja_id = ?
    AND (v.estado IS NULL OR v.estado <> 'ANULADA')
");
$stmt->execute([$cajaId]);
$totalVentas = (float)($stmt->fetchColumn() ?: 0.0);

// Total vendido a CC (informativo)
$hasMontoCC = col_exists($pdo, 'ventas', 'monto_cc');
$totalVentasCC = 0.0;
if ($hasMontoCC) {
  $montoCCVigenteExpr = flus_venta_cc_vigente_expr_sql('COALESCE(v.monto_cc, 0)', 'v.total', $montoAnuladoVentasExpr);
  $stmt = $pdo->prepare("
    SELECT COALESCE(SUM($montoCCVigenteExpr),0)
    FROM ventas v
    $anulacionesJoinVentas
    WHERE v.caja_id = ?
      AND (v.estado IS NULL OR v.estado <> 'ANULADA')
  ");
  $stmt->execute([$cajaId]);
  $totalVentasCC = (float)($stmt->fetchColumn() ?: 0.0);
}

// Ítems vendidos del turno (solo EMITIDA/NULL)
$anulacionesItemsJoin = flus_venta_items_anulados_join_sql($pdo, 'vi', 'vaix');
$cantidadAnuladaItemsExpr = $anulacionesItemsJoin !== '' ? 'COALESCE(vaix.cantidad_anulada_total, 0)' : '0';
$cantidadVigenteExpr = flus_venta_cantidad_vigente_expr_sql('vi.cantidad', $cantidadAnuladaItemsExpr);
$stmt = $pdo->prepare("
  SELECT COALESCE(SUM($cantidadVigenteExpr),0) AS cant
  FROM ventas v
  JOIN venta_items vi ON vi.venta_id = v.id
  $anulacionesItemsJoin
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
      $motivoCierre = strtoupper(trim((string)($_POST['cierre_motivo'] ?? 'CAMBIO_TURNO')));
      $motivosValidos = ['CAMBIO_TURNO', 'FIN_DIA', 'CORTE_PARCIAL', 'CIERRE_FORZADO'];
      if (!in_array($motivoCierre, $motivosValidos, true)) {
        $motivoCierre = 'CAMBIO_TURNO';
      }
      if (trim($rawSaldo) === '') {
        $errores[] = 'Ingresá el saldo contado por el cajero.';
      } elseif ($saldoDeclarado < 0) {
        $errores[] = 'El saldo declarado no puede ser negativo.';
      } else {

        $pdo->beginTransaction();
        try {
          $cajaBloqueada = caja_lock_session_for_update($pdo, $cajaId);
          if (!$cajaBloqueada || !caja_session_is_open($cajaBloqueada['fecha_cierre'] ?? null)) {
            throw new FlusCajaCierreValidationException('No se pudo cerrar la caja: ya estaba cerrada.');
          }

          $criticos = caja_cierre_recalcular_criticos(
            $pdo,
            $cajaBloqueada,
            $cajaId,
            $anulacionesJoinVentas,
            $importeVigenteExpr,
            $anulacionesItemsJoin,
            $cantidadVigenteExpr,
            $whereMovEfectivo
          );
          $saldoSistema = (float)$criticos['saldo_sistema'];
          $totalVentas = (float)$criticos['total_ventas'];
          $itemsVendidos = (float)$criticos['total_productos'];
          $totalAnulaciones = (int)$criticos['total_anulaciones'];
          $diferencia = $saldoDeclarado - $saldoSistema;

          $requiereNotaControl = abs($diferencia) > 0.009 || $cierrePorSupervisor || $motivoCierre === 'CIERRE_FORZADO';
          if ($requiereNotaControl && mb_strlen($notas) < 8) {
            $sentidoDiferencia = $diferencia < -0.009 ? 'faltan' : 'sobran';
            throw new FlusCajaCierreValidationException(
              'Hay una diferencia: ' . $sentidoDiferencia . ' ' . money_ar(abs($diferencia))
              . '. Podes cerrar igual, pero escribi una observacion breve para dejar registro.'
            );
          }

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
            
            $sets = [
              'fecha_cierre      = NOW()',
              'saldo_sistema     = :saldo_sistema',
              'saldo_declarado   = :saldo_declarado',
              'diferencia        = :diferencia',
              'notas             = :notas',
              'total_ventas      = :total_ventas',
              'total_productos   = :total_productos',
              'total_anulaciones = :total_anulaciones',
            ];
            $paramsUpdate = [
              ':saldo_sistema' => $saldoSistema,
              ':saldo_declarado' => $saldoDeclarado,
              ':diferencia' => $diferencia,
              ':notas' => $notas,
              ':total_ventas' => $totalVentas,
              ':total_productos' => $itemsVendidos,
              ':total_anulaciones' => $totalAnulaciones,
              ':id' => $cajaId,
            ];
            if ($hasCierreMotivoCol) {
              $sets[] = 'cierre_motivo = :cierre_motivo';
              $paramsUpdate[':cierre_motivo'] = $motivoCierre;
            }
            if ($hasCerradoPorCol) {
              $sets[] = 'cerrado_por_user_id = :cerrado_por_user_id';
              $paramsUpdate[':cerrado_por_user_id'] = $currentUserId > 0 ? $currentUserId : null;
            }
            $stUpd = $pdo->prepare("
              UPDATE caja_sesiones
              SET " . implode(",\n                  ", $sets) . "
              WHERE id = :id
                AND (fecha_cierre IS NULL OR fecha_cierre = '0000-00-00 00:00:00')
            ");

            $stUpd->execute($paramsUpdate);

            if ($stUpd->rowCount() === 0) {
              $pdo->rollBack();
              $errores[] = 'No se pudo cerrar la caja (ya estaba cerrada o ID inválido).';
            } else {
              $pdo->commit();
              $terminalCloud = terminal_get($pdo, (int)($cajaBloqueada['terminal_id'] ?? $terminalId));
              flus_cloud_sync_enqueue_cash_session($pdo, 'closed', [
                'caja_id' => $cajaId,
                'terminal_id' => (int)($cajaBloqueada['terminal_id'] ?? $terminalId),
                'terminal_name' => (string)($terminalCloud['nombre'] ?? ''),
                'user_id' => (int)($cajaBloqueada['user_id'] ?? 0),
                'cashier_name' => (string)($caja['username'] ?? ''),
                'fecha_apertura' => (string)($cajaBloqueada['fecha_apertura'] ?? ''),
                'fecha_cierre' => date('Y-m-d H:i:s'),
                'total_ventas' => $totalVentas,
                'saldo_sistema' => $saldoSistema,
                'saldo_declarado' => $saldoDeclarado,
                'diferencia' => $diferencia,
                'motivo' => $motivoCierre,
              ]);
              header('Location: caja.php?ok=' . urlencode('Caja cerrada correctamente.'));
              exit;
            }
        } catch (FlusCajaCierreValidationException $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          $errores[] = $e->getMessage();
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          error_log('caja_cerrar.php error: ' . $e->getMessage());
          $errores[] = 'No se pudo cerrar la caja. Recarga la pagina e intenta de nuevo.';
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
        · Desde <?= h(format_datetime_ar($fechaApertura)) ?>
        <?php if (!$abierta && !empty($caja['fecha_cierre'])): ?>
          · Hasta <?= h(format_datetime_ar((string)$caja['fecha_cierre'])) ?>
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
        <li class="cierre-row cierre-row--cc">
          <span>Ventas a Cuenta Corriente</span>
          <span class="mono"><?= money_ar($totalVentasCC) ?></span>
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
      <form method="post" class="cierre-form" id="cierreForm" data-saldo-sistema="<?= h(number_format($saldoSistema, 2, '.', '')) ?>">
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
        <div class="cierre-diff-preview" id="cierreDiffPreview" aria-live="polite">
          El sistema espera <?= money_ar($saldoSistema) ?> en efectivo. Carga el conteo para ver si falta o sobra plata.
        </div>

        <?php if ($hasCierreMotivoCol): ?>
          <div class="cierre-input-row">
            <select name="cierre_motivo" class="cierre-input" aria-label="Motivo de cierre">
              <?php
                $motivoPost = strtoupper(trim((string)($_POST['cierre_motivo'] ?? 'CAMBIO_TURNO')));
                $motivosUi = [
                  'CAMBIO_TURNO' => 'Cambio de turno',
                  'FIN_DIA' => 'Fin del dia',
                  'CORTE_PARCIAL' => 'Corte parcial',
                  'CIERRE_FORZADO' => 'Cierre forzado',
                ];
              ?>
              <?php foreach ($motivosUi as $value => $label): ?>
                <option value="<?= h($value) ?>" <?= $motivoPost === $value ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <p class="cierre-control-help">
            Cierre simple: conta el efectivo real. Si falta o sobra, deja una observacion. El saldo inicial del proximo cajero se declara al abrir su turno.
          </p>
        <?php endif; ?>

        <label for="notas" class="cierre-label cierre-label-notas">
          Observacion del cierre
        </label>
        <textarea
          id="notas"
          name="notas"
          class="cierre-textarea"
          rows="2"
          placeholder="Ej: diferencia menor por redondeo, falta cambio, cierre revisado por encargado"><?= h((string)($_POST['notas'] ?? ($caja['notas'] ?? ''))) ?></textarea>
        <p class="cierre-note-help">Solo hace falta si falta/sobra efectivo, si cierra un supervisor o si es cierre forzado.</p>
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

        <?php if ($hasCierreMotivoCol && !empty($caja['cierre_motivo'])): ?>
          <div class="cierre-total-line">
            <span>Motivo</span>
            <span class="mono"><?= h((string)$caja['cierre_motivo']) ?></span>
          </div>
        <?php endif; ?>

        <?php if ($hasFondoSiguienteCol && (float)($caja['cierre_fondo_siguiente'] ?? 0) > 0): ?>
          <div class="cierre-total-line">
            <span>Fondo proximo turno</span>
            <span class="mono"><?= money_ar((float)$caja['cierre_fondo_siguiente']) ?></span>
          </div>
        <?php endif; ?>

        <?php if ($hasRetiroEfectivoCol && (float)($caja['cierre_retiro_efectivo'] ?? 0) > 0): ?>
          <div class="cierre-total-line">
            <span>Retiro efectivo</span>
            <span class="mono"><?= money_ar((float)$caja['cierre_retiro_efectivo']) ?></span>
          </div>
        <?php endif; ?>

        <?php if (!empty($caja['notas'])): ?>
          <div class="cierre-notas">
            <?= nl2br(h((string)$caja['notas'])) ?>
          </div>
        <?php endif; ?>
      </div>

    <?php endif; ?>
</section>

</div>

<?php if ($abierta): ?>
<script>
(function () {
  const form = document.getElementById('cierreForm');
  const saldoInput = document.getElementById('saldo_declarado');
  const diffBox = document.getElementById('cierreDiffPreview');
  if (!form || !saldoInput || !diffBox) return;

  const saldoSistema = Number.parseFloat(form.dataset.saldoSistema || '0') || 0;
  const money = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' });

  function parseMoneyAr(value) {
    const raw = String(value || '').trim();
    if (!raw) return null;
    const normalized = raw.replace(/\./g, '').replace(',', '.');
    const parsed = Number.parseFloat(normalized);
    return Number.isFinite(parsed) ? parsed : null;
  }

  function setDiffBox(kind, text) {
    diffBox.classList.remove('is-ok', 'is-short', 'is-over');
    if (kind) {
      diffBox.classList.add(kind);
    }
    diffBox.textContent = text;
  }

  function syncDiffPreview() {
    const contado = parseMoneyAr(saldoInput.value);
    if (contado === null) {
      setDiffBox('', 'El sistema espera ' + money.format(saldoSistema) + ' en efectivo. Carga el conteo para ver si falta o sobra plata.');
      return;
    }

    const diff = contado - saldoSistema;
    if (Math.abs(diff) < 0.01) {
      setDiffBox('is-ok', 'Sin diferencia: el efectivo contado coincide con el sistema.');
      return;
    }

    if (diff < 0) {
      setDiffBox('is-short', 'Faltan ' + money.format(Math.abs(diff)) + '. Podes cerrar igual, pero deja una observacion para que quede auditado.');
      return;
    }

    setDiffBox('is-over', 'Sobran ' + money.format(diff) + '. Podes cerrar igual, pero deja una observacion para que quede auditado.');
  }

  saldoInput.addEventListener('input', syncDiffPreview);
  syncDiffPreview();
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>

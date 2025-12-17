<?php
// public/caja_cerrar.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();

// ✅ Permiso recomendado para cierre (cambiá el slug si querés)
require_permission('cerrar_caja');

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php';   // ✅ NECESARIO: h(), money_ar(), parse_money_ar()
require_once __DIR__ . '/caja_lib.php';

$pdo = getPDO();

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ----------------------------------------------------
   1) OBTENER ID DE CAJA (GET o última abierta)
---------------------------------------------------- */
$cajaId = (int)($_GET['id'] ?? 0);

if ($cajaId <= 0) {
  $stmt = $pdo->query("
    SELECT id
    FROM caja_sesiones
    WHERE fecha_cierre IS NULL
    ORDER BY id DESC
    LIMIT 1
  ");
  $cajaId = (int)($stmt->fetchColumn() ?: 0);
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

$abierta       = empty($caja['fecha_cierre']);
$saldoInicial  = (float)$caja['saldo_inicial'];
$usernameCaja  = (string)$caja['username'];
$fechaApertura = (string)$caja['fecha_apertura'];

/* ----------------------------------------------------
   3) RESUMEN DE VENTAS DEL TURNO
---------------------------------------------------- */
$stmt = $pdo->prepare("
  SELECT medio_pago, SUM(total) AS total
  FROM ventas
  WHERE caja_id = ? AND (estado IS NULL OR estado = 'EMITIDA')
  GROUP BY medio_pago
");
$stmt->execute([$cajaId]);

$porMedio = [
  'EFECTIVO' => 0.0,
  'MP'       => 0.0,
  'DEBITO'   => 0.0,
  'CREDITO'  => 0.0,
];

$totalVentas = 0.0;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $medio = strtoupper((string)$row['medio_pago']);
  $total = (float)$row['total'];

  $totalVentas += $total;
  if (isset($porMedio[$medio])) {
    $porMedio[$medio] = $total;
  }
}

// Ítems vendidos del turno
$stmt = $pdo->prepare("
  SELECT SUM(vi.cantidad) AS cant
  FROM ventas v
  JOIN venta_items vi ON vi.venta_id = v.id
  WHERE v.caja_id = ?
");
$stmt->execute([$cajaId]);
$itemsVendidos = (int)($stmt->fetchColumn() ?: 0);

// Saldo que debería haber en caja física (sólo efectivo)
$saldoSistema = $saldoInicial + $porMedio['EFECTIVO'];

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
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
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

        // ✅ Cierre atómico (evita doble cierre por doble click)
        $pdo->beginTransaction();
        try {
          // Lock de la caja
          $stLock = $pdo->prepare("SELECT fecha_cierre FROM caja_sesiones WHERE id = ? FOR UPDATE");
          $stLock->execute([$cajaId]);
          $fechaCierreActual = $stLock->fetchColumn();

          if (!empty($fechaCierreActual)) {
            $pdo->rollBack();
            $errores[] = 'No se pudo cerrar la caja: ya estaba cerrada.';
          } else {
            $stUpd = $pdo->prepare("
              UPDATE caja_sesiones
              SET
                fecha_cierre    = NOW(),
                saldo_sistema   = ?,
                saldo_declarado = ?,
                diferencia      = ?,
                notas           = ?,
                total_ventas    = ?,
                total_efectivo  = ?,
                total_mp        = ?,
                total_debito    = ?,
                total_credito   = ?,
                total_productos = ?
              WHERE id = ? AND fecha_cierre IS NULL
            ");

            $stUpd->execute([
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
              $cajaId,
            ]);

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
$extraCss       = ['assets/css/caja_cerrar.css?v=1'];

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
      <h2 class="cierre-section-title">Resumen del día</h2>
      <ul class="cierre-list">
        <li class="cierre-row">
          <span>Saldo inicial</span>
          <span class="mono"><?= money_ar($saldoInicial) ?></span>
        </li>
        <li class="cierre-row">
          <span>Total Efectivo</span>
          <span class="mono"><?= money_ar($porMedio['EFECTIVO']) ?></span>
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
        <li class="cierre-row cierre-row-simple">
          <span>Ítems vendidos</span>
          <span class="mono"><?= (int)$itemsVendidos ?></span>
        </li>
      </ul>
    </section>

    <section class="cierre-card cierre-total">
      <div class="cierre-total-label">Total sistema</div>
      <div class="cierre-total-amount"><?= money_ar($saldoSistema) ?></div>
      <div class="cierre-total-sub">
        Saldo inicial + ventas en efectivo
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
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

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

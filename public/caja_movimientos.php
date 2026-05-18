<?php
// public/caja_movimiento.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/caja_lib.php';

require_permission('realizar_ventas'); // si querés más fino: 'cerrar_caja' o crear 'movimientos_caja'

require_pos(); // login + terminal + lock

if (!function_exists('format_datetime_ar')) {
  function format_datetime_ar(?string $dt): string {
    if (!$dt || $dt === '0000-00-00 00:00:00') return '—';
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $dt);
    return $d ? $d->format('d/m/Y H:i') : (string)$dt;
  }
}

// Terminal + caja abierta
$terminalId = (int)($_SESSION['terminal_id'] ?? current_terminal_id());
$caja = caja_get_abierta($pdo, $terminalId);
$cajaId = (int)($caja['id'] ?? 0);

if ($cajaId <= 0) {
  header('Location: caja.php?err=' . urlencode('No hay caja abierta.'));
  exit;
}

$u = current_user();
$username = (string)($u['username'] ?? ('user#' . (int)($u['id'] ?? 0)));

$error = null;

// ------------------------------
// POST: guardar movimiento
// ------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    $error = 'CSRF inválido. Recargá e intentá de nuevo.';
  } else {

    $tipo = strtolower(trim((string)($_POST['tipo'] ?? 'ingreso')));
    if (!in_array($tipo, ['ingreso', 'egreso'], true)) $tipo = 'ingreso';

    $concepto = trim((string)($_POST['concepto'] ?? ''));
    $concepto = mb_substr($concepto, 0, 255);

    $monto = parse_money_ar($_POST['monto'] ?? '');

    if ($concepto === '') {
      $error = 'Ingresá un concepto.';
    } elseif ($monto <= 0) {
      $error = 'Monto inválido (debe ser mayor a 0).';
    } else {

      try {
        // Seguridad extra: validar que sigue abierta (por si cerraron en otra pestaña)
        $lock = $pdo->prepare("SELECT fecha_cierre FROM caja_sesiones WHERE id = ? LIMIT 1");
        $lock->execute([$cajaId]);
        $fc = (string)($lock->fetchColumn() ?? '');

        if ($fc !== '' && $fc !== '0000-00-00 00:00:00') {
          $error = 'La caja ya está cerrada. No podés cargar movimientos.';
        } else {
          $st = $pdo->prepare("
            INSERT INTO caja_movimientos (caja_id, tipo, concepto, monto, usuario_registro)
            VALUES (:caja_id, :tipo, :concepto, :monto, :usr)
          ");
          $st->execute([
            ':caja_id'  => $cajaId,
            ':tipo'     => $tipo, // enum('ingreso','egreso')
            ':concepto' => $concepto,
            ':monto'    => $monto,
            ':usr'      => mb_substr($username, 0, 100),
          ]);

          header('Location: caja.php?ok=' . urlencode('Movimiento registrado.'));
          exit;
        }

      } catch (Throwable $e) {
        error_log('caja_movimiento.php error: ' . $e->getMessage());
        $error = 'No se pudo registrar el movimiento.';
      }
    }
  }
}

// ------------------------------
// GET: listar últimos movimientos + totales
// ------------------------------

// Verificar si existe columna medio_pago (compatibilidad)
$hasMedioPagoCol = false;
try {
  if (function_exists('flus_column_exists')) {
    $hasMedioPagoCol = (bool)flus_column_exists($pdo, 'caja_movimientos', 'medio_pago');
  } elseif (function_exists('has_column')) {
    $hasMedioPagoCol = (bool)has_column($pdo, 'caja_movimientos', 'medio_pago');
  }
} catch (Throwable $e) {}

$selectMedio = $hasMedioPagoCol ? ', medio_pago' : '';

$stList = $pdo->prepare("
  SELECT id, tipo, concepto, monto, fecha, usuario_registro{$selectMedio}
  FROM caja_movimientos
  WHERE caja_id = ?
  ORDER BY fecha DESC, id DESC
  LIMIT 50
");
$stList->execute([$cajaId]);
$movs = $stList->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stSum = $pdo->prepare("
  SELECT
    COALESCE(SUM(CASE WHEN UPPER(tipo)='INGRESO' THEN monto ELSE 0 END),0) AS ingresos,
    COALESCE(SUM(CASE WHEN UPPER(tipo)='EGRESO'  THEN monto ELSE 0 END),0) AS egresos
  FROM caja_movimientos
  WHERE caja_id = ?
");
$stSum->execute([$cajaId]);
$rowS = $stSum->fetch(PDO::FETCH_ASSOC) ?: [];
$ing = (float)($rowS['ingresos'] ?? 0);
$egr = (float)($rowS['egresos'] ?? 0);

// Header
$pageTitle      = 'Movimiento de caja - Apertura #' . $cajaId;
$currentSection = 'caja';
$extraCss       = ['assets/css/caja_movimientos.css?v=' . filemtime(__DIR__ . '/assets/css/caja_movimientos.css')];

require __DIR__ . '/partials/header.php';
?>

<div class="panel">

  <div class="mov-header">
    <div class="mov-header__info">
      <h1 class="mov-header__title">Movimiento de caja · Apertura #<?= (int)$cajaId ?></h1>
      <div class="mov-header__pills">
        <span class="pill pill-success">+ Ingresos: <?= money_ar($ing) ?></span>
        <span class="pill pill-danger">− Egresos: <?= money_ar($egr) ?></span>
      </div>
    </div>
    <a class="btn btn-secondary btn-sm" href="caja.php">← Volver a Caja</a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error" style="margin-bottom:12px;"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="mov-form-card">
    <div class="mov-form-title">Registrar movimiento</div>
    <form method="post">
      <?= csrf_field() ?>
      <div class="mov-form-grid">
        <div class="mov-form-field">
          <label for="mov-tipo">Tipo</label>
          <select id="mov-tipo" name="tipo">
            <option value="ingreso">Ingreso</option>
            <option value="egreso">Egreso</option>
          </select>
        </div>

        <div class="mov-form-field">
          <label for="mov-concepto">Concepto</label>
          <input id="mov-concepto" name="concepto" maxlength="255"
                 placeholder="Ej: Cambio, retiro, pago proveedor" required>
        </div>

        <div class="mov-form-field">
          <label for="mov-monto">Monto</label>
          <input id="mov-monto" name="monto" placeholder="Ej: 1.200,00" required>
        </div>

        <button class="btn btn-primary mov-form-submit" type="submit">Guardar</button>
      </div>
    </form>
  </div>

  <h2 class="mov-history-title">Últimos movimientos</h2>

  <?php if (!$movs): ?>
    <div class="mov-empty">Todavía no hay movimientos en esta apertura.</div>
  <?php else: ?>
    <div class="table-wrapper">
      <table class="table">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Tipo</th>
            <?php if ($hasMedioPagoCol): ?>
            <th>Medio</th>
            <?php endif; ?>
            <th>Concepto</th>
            <th class="t-right">Monto</th>
            <th>Usuario</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($movs as $m): ?>
            <?php
              $t = strtoupper((string)($m['tipo'] ?? 'INGRESO'));
              $pill = ($t === 'EGRESO') ? 'pill-danger' : 'pill-success';
              $lbl  = ($t === 'EGRESO') ? '− Egreso' : '+ Ingreso';
              $medio = strtoupper(trim((string)($m['medio_pago'] ?? '')));
            ?>
            <tr>
              <td class="mono"><?= h(format_datetime_ar($m['fecha'] ?? null)) ?></td>
              <td><span class="pill <?= $pill ?>"><?= h($lbl) ?></span></td>
              <?php if ($hasMedioPagoCol): ?>
              <td><?= $medio ? h($medio) : '<span class="muted">—</span>' ?></td>
              <?php endif; ?>
              <td><?= h((string)($m['concepto'] ?? '—')) ?></td>
              <td class="t-right"><?= money_ar($m['monto'] ?? 0) ?></td>
              <td><?= h((string)($m['usuario_registro'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

<?php
// public/caja_movimientos.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/caja_lib.php';

require_permission('realizar_ventas');
require_pos();

function caja_movimiento_wants_json(): bool {
  $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
  $xhr = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
  return $xhr === 'xmlhttprequest'
    || str_contains($accept, 'application/json')
    || (string)($_REQUEST['response'] ?? '') === 'json';
}

function caja_movimiento_json_response(bool $ok, string $message, int $status = 200, array $extra = []): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
  exit;
}

final class FlusCajaMovimientoException extends RuntimeException {
  public function __construct(string $message, public readonly int $status = 409) {
    parent::__construct($message);
  }
}

function caja_movimiento_idempotency_ready(PDO $pdo): bool {
  if (!function_exists('flus_column_exists') || !flus_column_exists($pdo, 'caja_movimientos', 'request_uid')) {
    return false;
  }

  $st = $pdo->query("\n    SELECT INDEX_NAME\n    FROM information_schema.STATISTICS\n    WHERE TABLE_SCHEMA = DATABASE()\n      AND TABLE_NAME = 'caja_movimientos'\n      AND NON_UNIQUE = 0\n    GROUP BY INDEX_NAME\n    HAVING COUNT(*) = 1\n       AND SUM(CASE WHEN COLUMN_NAME = 'request_uid' THEN 1 ELSE 0 END) = 1\n    LIMIT 1\n  ");
  return $st ? (bool)$st->fetchColumn() : false;
}

function caja_movimiento_find_by_request_uid(PDO $pdo, string $requestUid): ?array {
  if ($requestUid === '') return null;
  $st = $pdo->prepare('SELECT id, caja_id FROM caja_movimientos WHERE request_uid = ? LIMIT 1');
  $st->execute([$requestUid]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return $row ?: null;
}

if (!function_exists('format_datetime_ar')) {
  function format_datetime_ar(?string $dt): string {
    if (!$dt || $dt === '0000-00-00 00:00:00') return '-';
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $dt);
    return $d ? $d->format('d/m/Y H:i') : (string)$dt;
  }
}

$terminalId = (int)($_SESSION['terminal_id'] ?? current_terminal_id());
$caja = caja_get_abierta($pdo, $terminalId);
$cajaId = (int)($caja['id'] ?? 0);

if ($cajaId <= 0) {
  if (caja_movimiento_wants_json()) {
    caja_movimiento_json_response(false, 'No hay caja abierta.', 409);
  }
  header('Location: caja.php?err=' . urlencode('No hay caja abierta.'));
  exit;
}

$u = current_user();
$userId = (int)($u['id'] ?? 0);
$username = (string)($u['username'] ?? ('user#' . (int)($u['id'] ?? 0)));

if (!caja_user_can_operar_turno($caja, $userId)) {
  $msg = 'Esta caja fue abierta por ' . caja_turno_owner_label($caja) . '. No podes cargar movimientos en un turno ajeno.';
  if (caja_movimiento_wants_json()) {
    caja_movimiento_json_response(false, $msg, 403);
  }
  header('Location: caja.php?err=' . urlencode($msg));
  exit;
}

$error = null;
$canVerControlMovimientos = caja_user_can_supervisar_turnos();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $wantsJson = caja_movimiento_wants_json();

  if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    $error = 'CSRF invalido. Recarga e intenta de nuevo.';
    if ($wantsJson) caja_movimiento_json_response(false, $error, 403);
  } else {
    $tipo = strtolower(trim((string)($_POST['tipo'] ?? 'ingreso')));
    if (!in_array($tipo, ['ingreso', 'egreso'], true)) $tipo = 'ingreso';

    $concepto = trim((string)($_POST['concepto'] ?? ''));
    $concepto = mb_substr($concepto, 0, 255);

    $monto = parse_money_ar($_POST['monto'] ?? '');
    $requestUid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['request_uid'] ?? ''));
    $requestUid = mb_substr($requestUid, 0, 80);

    if ($concepto === '') {
      $error = 'Ingresa un concepto.';
      if ($wantsJson) caja_movimiento_json_response(false, $error, 422);
    } elseif ($monto <= 0) {
      $error = 'Monto invalido. Debe ser mayor a 0.';
      if ($wantsJson) caja_movimiento_json_response(false, $error, 422);
    } elseif (strlen($requestUid) < 8) {
      $error = 'Identificador de solicitud invalido. Recarga e intenta de nuevo.';
      if ($wantsJson) caja_movimiento_json_response(false, $error, 422);
    } elseif (!caja_movimiento_idempotency_ready($pdo)) {
      $error = 'Falta aplicar la migracion de idempotencia de movimientos.';
      if ($wantsJson) caja_movimiento_json_response(false, $error, 409, ['error_code' => 'CAJA_MOVIMIENTO_IDEMPOTENCIA_NO_DISPONIBLE']);
    } else {
      try {
        $existing = caja_movimiento_find_by_request_uid($pdo, $requestUid);
        if ($existing !== null) {
          if ($wantsJson) {
            caja_movimiento_json_response(true, 'Movimiento registrado.', 200, [
              'duplicate' => true,
              'id' => (int)$existing['id'],
            ]);
          }
          header('Location: caja.php?ok=' . urlencode('Movimiento registrado.'));
          exit;
        }

        $pdo->beginTransaction();
        $cajaBloqueada = caja_lock_session_for_update($pdo, $cajaId);
        if (!$cajaBloqueada || !caja_session_is_open($cajaBloqueada['fecha_cierre'] ?? null)) {
          throw new FlusCajaMovimientoException('La caja ya esta cerrada. No podes cargar movimientos.', 409);
        }
        if (!caja_user_can_operar_turno($cajaBloqueada, $userId)) {
          throw new FlusCajaMovimientoException('El turno de caja ya no pertenece a este usuario.', 403);
        }

        $st = $pdo->prepare("
            INSERT INTO caja_movimientos (caja_id, tipo, concepto, monto, usuario_registro, request_uid)
            VALUES (:caja_id, :tipo, :concepto, :monto, :usr, :request_uid)
        ");
        $st->execute([
          ':caja_id' => $cajaId,
          ':tipo' => $tipo,
          ':concepto' => $concepto,
          ':monto' => $monto,
          ':usr' => mb_substr($username, 0, 100),
          ':request_uid' => $requestUid,
        ]);
        $movimientoId = (int)$pdo->lastInsertId();
        $pdo->commit();

        if ($wantsJson) {
          caja_movimiento_json_response(true, 'Movimiento registrado.', 200, ['id' => $movimientoId]);
        }
        header('Location: caja.php?ok=' . urlencode('Movimiento registrado.'));
        exit;
      } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((int)($e->errorInfo[1] ?? 0) === 1062) {
          $existing = caja_movimiento_find_by_request_uid($pdo, $requestUid);
          if ($existing !== null) {
            if ($wantsJson) {
              caja_movimiento_json_response(true, 'Movimiento registrado.', 200, [
                'duplicate' => true,
                'id' => (int)$existing['id'],
              ]);
            }
            header('Location: caja.php?ok=' . urlencode('Movimiento registrado.'));
            exit;
          }
        }
        error_log('caja_movimiento.php error: ' . $e->getMessage());
        $error = 'No se pudo registrar el movimiento.';
        if ($wantsJson) caja_movimiento_json_response(false, $error, 500);
      } catch (FlusCajaMovimientoException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
        if ($wantsJson) caja_movimiento_json_response(false, $error, $e->status);
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('caja_movimiento.php error: ' . $e->getMessage());
        $error = 'No se pudo registrar el movimiento.';
        if ($wantsJson) caja_movimiento_json_response(false, $error, 500);
      }
    }
  }
}

$hasMedioPagoCol = false;
try {
  if (function_exists('flus_column_exists')) {
    $hasMedioPagoCol = (bool)flus_column_exists($pdo, 'caja_movimientos', 'medio_pago');
  } elseif (function_exists('has_column')) {
    $hasMedioPagoCol = (bool)has_column($pdo, 'caja_movimientos', 'medio_pago');
  }
} catch (Throwable $e) {}

$selectMedio = $hasMedioPagoCol ? ', medio_pago' : '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && caja_movimiento_wants_json()) {
  if (!$canVerControlMovimientos) {
    caja_movimiento_json_response(false, 'No tenes permiso para ver los movimientos.', 403);
  }

  $limit = max(1, min(12, (int)($_GET['limit'] ?? 6)));
  $stJson = $pdo->prepare("
    SELECT id, tipo, concepto, monto, fecha, usuario_registro{$selectMedio}
    FROM caja_movimientos
    WHERE caja_id = ?
    ORDER BY fecha DESC, id DESC
    LIMIT {$limit}
  ");
  $stJson->execute([$cajaId]);
  $rows = $stJson->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $items = array_map(static function (array $m) use ($hasMedioPagoCol): array {
    $tipo = strtolower((string)($m['tipo'] ?? 'ingreso'));
    return [
      'id' => (int)($m['id'] ?? 0),
      'tipo' => $tipo === 'egreso' ? 'egreso' : 'ingreso',
      'concepto' => (string)($m['concepto'] ?? ''),
      'monto' => (float)($m['monto'] ?? 0),
      'monto_fmt' => money_ar($m['monto'] ?? 0),
      'fecha' => format_datetime_ar((string)($m['fecha'] ?? '')),
      'usuario' => (string)($m['usuario_registro'] ?? '-'),
      'medio' => $hasMedioPagoCol ? (string)($m['medio_pago'] ?? '') : '',
    ];
  }, $rows);

  caja_movimiento_json_response(true, 'Movimientos cargados.', 200, ['items' => $items]);
}

$movs = [];
$ing = 0.0;
$egr = 0.0;

if ($canVerControlMovimientos) {
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
}

$pageTitle      = 'Movimiento de caja - Apertura #' . $cajaId;
$currentSection = 'caja';
$extraCss       = ['assets/css/caja_movimientos.css?v=' . filemtime(__DIR__ . '/assets/css/caja_movimientos.css')];

require __DIR__ . '/partials/header.php';
?>

<div class="panel">

  <div class="mov-header">
    <div class="mov-header__info">
      <h1 class="mov-header__title">Movimiento de caja - Apertura #<?= (int)$cajaId ?></h1>
      <div class="mov-header__meta">Carga ingresos o egresos de efectivo autorizados para este turno.</div>
      <?php if ($canVerControlMovimientos): ?>
      <div class="mov-header__pills">
        <span class="pill pill-success">+ Ingresos: <?= money_ar($ing) ?></span>
        <span class="pill pill-danger">- Egresos: <?= money_ar($egr) ?></span>
      </div>
      <?php endif; ?>
    </div>
    <a class="btn btn-secondary btn-sm" href="caja.php">Volver a Caja</a>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error mov-error"><?= h($error) ?></div>
  <?php endif; ?>

  <div class="mov-form-card">
    <div class="mov-form-title">Registrar movimiento</div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="request_uid" value="<?= h(bin2hex(random_bytes(16))) ?>">
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
                 placeholder="Ej: cambio, retiro, pago proveedor" required>
        </div>

        <div class="mov-form-field">
          <label for="mov-monto">Monto</label>
          <input id="mov-monto" name="monto" placeholder="Ej: 1.200,00" required>
        </div>

        <button class="btn btn-primary mov-form-submit" type="submit">Guardar</button>
      </div>
    </form>
  </div>

  <?php if ($canVerControlMovimientos): ?>
    <h2 class="mov-history-title">Ultimos movimientos</h2>

    <?php if (!$movs): ?>
      <div class="mov-empty">Todavia no hay movimientos en esta apertura.</div>
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
              $lbl  = ($t === 'EGRESO') ? '- Egreso' : '+ Ingreso';
              $medio = strtoupper(trim((string)($m['medio_pago'] ?? '')));
            ?>
            <tr>
              <td class="mono"><?= h(format_datetime_ar($m['fecha'] ?? null)) ?></td>
              <td><span class="pill <?= $pill ?>"><?= h($lbl) ?></span></td>
              <?php if ($hasMedioPagoCol): ?>
              <td><?= $medio ? h($medio) : '<span class="muted">-</span>' ?></td>
              <?php endif; ?>
              <td><?= h((string)($m['concepto'] ?? '-')) ?></td>
              <td class="t-right"><?= money_ar($m['monto'] ?? 0) ?></td>
              <td><?= h((string)($m['usuario_registro'] ?? '-')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  <?php else: ?>
    <div class="mov-control-note">Los acumulados y el historial quedan disponibles para usuarios con permiso de control.</div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

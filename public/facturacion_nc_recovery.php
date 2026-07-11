<?php
// public/facturacion_nc_recovery.php
// Herramienta de soporte para casos ERROR_POST_ARCA:
// ARCA aprobó la NC pero la aplicación comercial local falló.
// Solo accesible para usuarios con administrar_config.
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/db_schema.php';
require_once __DIR__ . '/../src/facturacion_lib.php';
require_once __DIR__ . '/../src/venta_anulaciones_lib.php';
require_once __DIR__ . '/../src/Fiscal/bootstrap.php';

require_login();
require_permission('administrar_config');

$msg    = trim((string)($_GET['msg']    ?? ''));
$msgErr = trim((string)($_GET['msgerr'] ?? ''));

// ── Acción POST: recovery de un caso puntual ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        header('Location: facturacion_nc_recovery.php?msgerr=' . urlencode('Sesión vencida (CSRF). Recargá e intentá de nuevo.'));
        exit;
    }

    $requestUid = trim((string)($_POST['request_uid'] ?? ''));
    $user       = current_user();
    $usuarioId  = (int)($user['id'] ?? 0);

    if ($requestUid === '') {
        header('Location: facturacion_nc_recovery.php?msgerr=' . urlencode('request_uid vacío.'));
        exit;
    }

    try {
        flus_fiscal_nc_assert_schema_ready($pdo);
        $repo    = new PdoFacturaFiscalRepository($pdo);
        $svc     = new DbFiscalRecoveryService($pdo, $repo);
        $result  = $svc->recoverByRequestUid($requestUid, $usuarioId);

        if ($result->ok) {
            header('Location: facturacion_nc_recovery.php?msg=' . urlencode($result->message ?? 'Recovery aplicado.'));
        } else {
            header('Location: facturacion_nc_recovery.php?msgerr=' . urlencode($result->errorMessage ?? 'Error desconocido.'));
        }
    } catch (Throwable $e) {
        error_log('facturacion_nc_recovery: ' . $e->getMessage());
        $errorUsuario = flus_facturacion_mensaje_operativo_seguro($e->getMessage(), 'No se pudo aplicar el recovery de NC. Revisa el caso e intenta nuevamente.');
        header('Location: facturacion_nc_recovery.php?msgerr=' . urlencode($errorUsuario));
    }
    exit;
}

// ── Cargar casos ERROR_POST_ARCA ─────────────────────────────────────────
$casos = [];
if (
    flus_table_exists($pdo, 'venta_anulaciones')
    && flus_column_exists($pdo, 'venta_anulaciones', 'estado_fiscal')
) {
    $joinUsers   = flus_table_exists($pdo, 'users');
    $joinFactura = flus_table_exists($pdo, 'facturas')
                && flus_column_exists($pdo, 'venta_anulaciones', 'nc_factura_id');

    $sql = "
        SELECT
            va.*,
            " . ($joinUsers   ? 'u.username AS anulado_por_username' : 'NULL AS anulado_por_username') . ",
            " . ($joinFactura ? 'f.cae AS nc_cae, f.tipo AS nc_tipo, f.numero AS nc_numero' : 'NULL AS nc_cae, NULL AS nc_tipo, NULL AS nc_numero') . "
        FROM venta_anulaciones va
        " . ($joinUsers   ? 'LEFT JOIN users    u ON u.id    = va.anulado_por'   : '') . "
        " . ($joinFactura ? 'LEFT JOIN facturas f ON f.id    = va.nc_factura_id' : '') . "
        WHERE COALESCE(va.estado_fiscal, 'NO_APLICA') = 'ERROR_POST_ARCA'
        ORDER BY va.anulado_en DESC
        LIMIT 100
    ";
    $st   = $pdo->prepare($sql);
    $st->execute();
    $casos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function rc_h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function rc_money(float $v): string
{
    return function_exists('money_ar') ? money_ar($v) : ('$' . number_format($v, 2, ',', '.'));
}

$pageTitle       = 'Recovery NC — ERROR_POST_ARCA';
$currentSection  = 'facturacion';
$breadcrumb      = [
    ['label' => 'Facturación',     'url' => 'facturacion.php'],
    ['label' => 'NC Recovery',     'url' => ''],
];
$extraCss = ['assets/css/facturacion.css?v=21'];
$extraJs = ['assets/js/facturacion_recovery.js?v=2'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap">
  <div class="panel fact-panel">
    <header class="page-header module-header">
      <div class="module-header-main">
        <div class="module-header-hero">
          <span class="module-header-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
              <path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
          </span>
          <div class="module-header-copy">
            <span class="module-eyebrow">Soporte fiscal</span>
            <h1 class="page-title module-title">Recovery NC — ERROR_POST_ARCA</h1>
            <p class="page-sub module-subtitle">
              Casos donde ARCA aprobó la NC pero la aplicación comercial local falló.
              El recovery reaplica stock, CC y estados sin re-emitir ante ARCA.
            </p>
          </div>
        </div>
      </div>
      <div class="promo-actions-top module-header-actions">
        <a href="facturacion_nc.php" class="v-btn v-btn--outline">Volver a NC</a>
      </div>
    </header>

    <?php if ($msg !== ''): ?>
      <div class="alert alert-success fact-alert"><?= rc_h($msg) ?></div>
    <?php endif; ?>
    <?php if ($msgErr !== ''): ?>
      <div class="alert alert-error fact-alert"><?= rc_h($msgErr) ?></div>
    <?php endif; ?>

    <?php if ($casos === []): ?>
      <div class="fact-empty-state fact-empty-state--compact">
        <p class="fact-empty-state__success">OK - No hay casos ERROR_POST_ARCA pendientes.</p>
      </div>
    <?php else: ?>
      <p class="fact-recovery-warning fact-recovery-warning--danger">
        <strong><?= count($casos) ?> caso<?= count($casos) === 1 ? '' : 's' ?></strong> requiere<?= count($casos) === 1 ? '' : 'n' ?> atención.
        Cada fila tiene la NC ya emitida en ARCA — solo falta cerrar el lado local.
      </p>

      <div class="table-wrapper">
        <table class="mov-table">
          <thead>
            <tr>
              <th>Anulación</th>
              <th>Venta</th>
              <th>Tipo</th>
              <th>NC emitida</th>
              <th class="t-right">Monto</th>
              <th>Error local</th>
              <th>Fecha</th>
              <th>Operador</th>
              <th>Acción</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($casos as $caso): ?>
              <?php
                $ncLabel = '';
                if (!empty($caso['nc_tipo']) && !empty($caso['nc_numero'])) {
                    $ncLabel = rc_h($caso['nc_tipo']) . ' #' . (int)$caso['nc_numero'];
                } elseif (!empty($caso['nc_factura_id'])) {
                    $ncLabel = 'ID #' . (int)$caso['nc_factura_id'];
                } else {
                    $ncLabel = '<em>Sin NC local</em>';
                }
                $requestUid = trim((string)($caso['fiscal_request_uid'] ?? ''));
              ?>
              <tr>
                <td>#<?= (int)$caso['id'] ?></td>
                <td>
                  <?php if (!empty($caso['venta_id'])): ?>
                    <a href="venta_detalle.php?id=<?= (int)$caso['venta_id'] ?>">#<?= (int)$caso['venta_id'] ?></a>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td><?= rc_h((string)($caso['tipo'] ?? '—')) ?></td>
                <td>
                  <?= $ncLabel ?>
                  <?php if (!empty($caso['nc_cae'])): ?>
                    <br><small class="fact-cell-sub">CAE <?= rc_h((string)$caso['nc_cae']) ?></small>
                  <?php endif; ?>
                </td>
                <td class="t-right"><?= rc_money((float)($caso['monto_total'] ?? 0)) ?></td>
                <td class="fact-error-cell">
                  <small><?= rc_h((string)($caso['fiscal_error_message'] ?? '—')) ?></small>
                </td>
                <td><small><?= rc_h((string)($caso['anulado_en'] ?? '')) ?></small></td>
                <td><small><?= rc_h((string)($caso['anulado_por_username'] ?? '—')) ?></small></td>
                <td>
                  <?php if ($requestUid !== ''): ?>
                    <form method="post"
                          action="facturacion_nc_recovery.php"
                          class="js-fiscal-confirm"
                          data-confirm-title="Reaplicar anulación #<?= (int)$caso['id'] ?>"
                          data-confirm-body="Esto repone stock, revierte cuenta corriente y cierra el estado local. No vuelve a emitir ante ARCA."
                          data-confirm-text="Reaplicar"
                          data-confirm-danger="true">
                      <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? rc_h((string)csrf_token()) : '' ?>">
                      <input type="hidden" name="request_uid" value="<?= rc_h($requestUid) ?>">
                      <button type="submit" class="btn-mini btn-mini--danger">Reaplicar</button>
                    </form>
                  <?php else: ?>
                    <span class="fact-inline-badge fact-inline-badge--warn" title="Sin request_uid — intervención manual necesaria">Sin UID</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

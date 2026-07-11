<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/db_schema.php';
require_once __DIR__ . '/../src/facturacion_lib.php';

require_login();
require_any_permission(['emitir_factura', 'administrar_config', 'ver_facturacion']);
$puedeOperarFiscal = user_has_permission('emitir_factura') || user_has_permission('administrar_config');

$facturacionHabilitada = config_get($pdo, 'facturacion_habilitada', '0') === '1';
if (!$facturacionHabilitada) {
    header('Location: index.php');
    exit;
}

$msg = trim((string)($_GET['msg'] ?? ''));
$msgErr = trim((string)($_GET['msgerr'] ?? ''));
$focusFacturaId = max(0, (int)($_GET['factura_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_any_permission(['emitir_factura', 'administrar_config']);

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        header('Location: facturacion_recovery.php?msgerr=' . urlencode('Sesion vencida (CSRF). Recarga e intenta de nuevo.'));
        exit;
    }

    $facturaId = max(0, (int)($_POST['factura_id'] ?? 0));
    if ($facturaId <= 0) {
        header('Location: facturacion_recovery.php?msgerr=' . urlencode('Factura invalida para operar.'));
        exit;
    }

    $action = trim((string)($_POST['action'] ?? 'regularizar'));
    try {
        if ($action === 'cerrar_incidencia') {
            $motivo = trim((string)($_POST['motivo'] ?? ''));
            $usuarioId = function_exists('session_user_id') ? session_user_id() : (int)($_SESSION['user_id'] ?? 0);
            flus_facturacion_cerrar_incidencia_fiscal($pdo, $facturaId, $motivo, $usuarioId > 0 ? $usuarioId : null);
            header('Location: facturacion_recovery.php?msg=' . urlencode('Incidencia fiscal de factura #' . $facturaId . ' cerrada localmente sin borrar la traza.') . '&factura_id=' . $facturaId);
            exit;
        }

        $facturaRegularizadaId = flus_facturacion_regularizar_factura($pdo, $facturaId);
        header('Location: facturacion_recovery.php?msg=' . urlencode('Factura #' . $facturaRegularizadaId . ' regularizada o confirmada sin duplicar emision.') . '&factura_id=' . $facturaRegularizadaId);
    } catch (Throwable $e) {
        error_log('facturacion_recovery: ' . $e->getMessage());
        $errorUsuario = flus_facturacion_mensaje_operativo_seguro($e->getMessage(), 'No se pudo regularizar la incidencia fiscal. Revisa el caso e intenta nuevamente.');
        header('Location: facturacion_recovery.php?msgerr=' . urlencode($errorUsuario) . '&factura_id=' . $facturaId);
    }
    exit;
}

$casos = [];
$hasFiscalCierre = false;
if (flus_table_exists($pdo, 'facturas') && flus_column_exists($pdo, 'facturas', 'estado_fiscal')) {
    $hasFiscalCierre = flus_column_exists($pdo, 'facturas', 'fiscal_cerrada_at');
    $joinClientes = flus_table_exists($pdo, 'clientes') && flus_column_exists($pdo, 'facturas', 'cliente_id');
    $joinEventosArca = flus_table_exists($pdo, 'factura_eventos_arca')
        && flus_column_exists($pdo, 'facturas', 'fiscal_request_uid')
        && flus_column_exists($pdo, 'factura_eventos_arca', 'request_uid');
    $clienteNombreExpr = $joinClientes
        ? (flus_column_exists($pdo, 'clientes', 'nombre') ? 'c.`nombre`' : 'CONCAT("Cliente #", c.id)')
        : 'NULL';
    $eventoSelect = $joinEventosArca ? ",
            fe.resultado AS arca_resultado,
            fe.operacion AS arca_operacion,
            fe.modo AS arca_modo,
            fe.error_code AS arca_error_code,
            fe.error_message AS arca_error_message,
            fe.created_at AS arca_created_at,
            fe.finished_at AS arca_finished_at" : '';

    $sql = "
        SELECT
            f.*,
            {$clienteNombreExpr} AS cliente_nombre{$eventoSelect}
        FROM facturas f
        " . ($joinClientes ? 'LEFT JOIN clientes c ON c.id = f.cliente_id' : '') . "
        " . ($joinEventosArca ? 'LEFT JOIN factura_eventos_arca fe ON CONVERT(fe.request_uid USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(f.fiscal_request_uid USING utf8mb4) COLLATE utf8mb4_unicode_ci' : '') . "
        WHERE COALESCE(f.estado_fiscal, 'NO_APLICA') IN ('PENDIENTE_ENVIO', 'ERROR_TRANSITORIO', 'ERROR_POST_ARCA', 'RECHAZADA')
          " . ($hasFiscalCierre ? 'AND f.fiscal_cerrada_at IS NULL' : '') . "
        ORDER BY COALESCE(f.fiscal_requested_at, f.fiscal_approved_at) DESC, f.id DESC
        LIMIT 200
    ";
    $st = $pdo->prepare($sql);
    $st->execute();
    $casos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function frc_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$pageTitle = 'Incidencias fiscales';
$currentSection = 'facturacion';
$breadcrumb = [
    ['label' => 'Facturacion', 'url' => 'facturacion.php'],
    ['label' => 'Incidencias fiscales', 'url' => ''],
];
$extraCss = ['assets/css/facturacion.css?v=21'];
$extraJs = ['assets/js/facturacion_recovery.js?v=1'];

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
            <span class="module-eyebrow">Contingencia fiscal</span>
            <h1 class="page-title module-title">Incidencias fiscales</h1>
            <p class="page-sub module-subtitle">
              Vista minima de comprobantes pendientes, transitorios, en ERROR_POST_ARCA o rechazados.
              La regularizacion reutiliza request_uid, eventos ARCA y recovery simple antes de cualquier reintento; los rechazados se corrigen y reemiten manualmente.
              Los rechazos de prueba pueden cerrarse localmente sin borrar auditoria.
            </p>
          </div>
        </div>
      </div>
      <div class="promo-actions-top module-header-actions">
        <a href="facturacion.php" class="v-btn v-btn--outline">Volver a facturacion</a>
      </div>
    </header>

    <?php if ($msg !== ''): ?>
      <div class="alert alert-success fact-alert"><?= frc_h($msg) ?></div>
    <?php endif; ?>
    <?php if ($msgErr !== ''): ?>
      <div class="alert alert-error fact-alert"><?= frc_h($msgErr) ?></div>
    <?php endif; ?>
    <?php if (!$hasFiscalCierre): ?>
      <div class="alert alert-warning fact-alert">
        Para cerrar rechazos de prueba falta aplicar la migracion 029 de cierre de incidencias fiscales.
      </div>
    <?php endif; ?>

    <?php if ($casos === []): ?>
      <div class="fact-empty-state fact-empty-state--compact">
        <p class="fact-empty-state__success">OK - No hay incidencias fiscales abiertas.</p>
      </div>
    <?php else: ?>
      <p class="fact-recovery-warning">
        <strong><?= count($casos) ?> caso<?= count($casos) === 1 ? '' : 's' ?></strong> requiere<?= count($casos) === 1 ? '' : 'n' ?> atencion.
        ERROR_POST_ARCA se intenta regularizar sin reenvio automatico; pendientes y transitorios si pueden reintentarse en forma segura; los rechazados se corrigen antes de volver a pedir CAE.
      </p>

      <div class="table-wrapper">
        <table class="mov-table">
          <thead>
            <tr>
              <th>Factura</th>
              <th>Cliente</th>
              <th>Estado fiscal</th>
              <th>Request UID</th>
              <th>Intentos</th>
              <th>Ultima interaccion ARCA</th>
              <th>Error</th>
              <th>Accion</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($casos as $caso): ?>
              <?php
                $estadoFiscal = flus_facturacion_estado_fiscal_resolver_desde_factura($caso);
                $clienteNombre = trim((string)($caso['cliente_nombre'] ?? '')) ?: 'Consumidor Final';
                $requestUid = trim((string)($caso['fiscal_request_uid'] ?? ''));
                $highlight = $focusFacturaId > 0 && $focusFacturaId === (int)$caso['id'];
                $accionFiscal = flus_facturacion_factura_accion_operativa($caso);
              ?>
              <tr class="<?= $highlight ? 'fact-row-highlight' : '' ?>">
                <td>
                  <strong><a href="factura_ver.php?id=<?= (int)$caso['id'] ?>">#<?= (int)$caso['id'] ?></a></strong>
                  <div class="fact-cell-sub"><?= frc_h(trim((string)($caso['tipo'] ?? 'Factura'))) ?><?= !empty($caso['numero']) ? ' #' . (int)$caso['numero'] : '' ?></div>
                </td>
                <td><?= frc_h($clienteNombre) ?></td>
                <td>
                  <strong><?= frc_h(flus_facturacion_estado_fiscal_label($estadoFiscal)) ?></strong>
                  <div class="fact-cell-sub"><?= frc_h(flus_facturacion_estado_fiscal_detalle_operativo($estadoFiscal)) ?></div>
                </td>
                <td><?= $requestUid !== '' ? '<span class="mono">' . frc_h($requestUid) . '</span>' : '<span class="fact-inline-badge fact-inline-badge--warn">Sin UID</span>' ?></td>
                <td><?= (int)($caso['fiscal_intentos'] ?? 0) ?></td>
                <td>
                  <?php if (!empty($caso['arca_resultado'])): ?>
                    <div><?= frc_h(flus_facturacion_evento_arca_resultado_label((string)$caso['arca_resultado'])) ?></div>
                    <div class="fact-cell-sub"><?= frc_h(flus_facturacion_evento_arca_operacion_label((string)($caso['arca_operacion'] ?? ''))) ?><?= !empty($caso['arca_modo']) ? '  | ' . frc_h(flus_facturacion_modo_label((string)$caso['arca_modo'])) : '' ?><?= !empty($caso['arca_finished_at']) ? '  | ' . frc_h((string)$caso['arca_finished_at']) : (!empty($caso['arca_created_at']) ? '  | ' . frc_h((string)$caso['arca_created_at']) : '') ?></div>
                  <?php elseif (!empty($caso['fiscal_approved_at']) || !empty($caso['fiscal_requested_at'])): ?>
                    <div>Sin evento ARCA visible</div>
                    <div class="fact-cell-sub"><?php if (!empty($caso['fiscal_approved_at'])): ?>Aprobado local  | <?= frc_h((string)$caso['fiscal_approved_at']) ?><?php else: ?>Solicitado  | <?= frc_h((string)$caso['fiscal_requested_at']) ?><?php endif; ?></div>
                  <?php else: ?>
                    <span class="fact-cell-sub">Sin traza fiscal visible</span>
                  <?php endif; ?>
                </td>
                <td class="fact-error-cell"><small><?= frc_h(trim((string)($caso['fiscal_error_message'] ?? $caso['arca_error_message'] ?? '-'))) ?></small></td>
                <td>
                  <?php if ($puedeOperarFiscal && ($accionFiscal['kind'] ?? '') === 'regularizar'): ?>
                    <form method="post" action="facturacion_recovery.php" class="js-fiscal-confirm" data-confirm-title="Regularizar factura #<?= (int)$caso['id'] ?>" data-confirm-body="FLUS intentará recuperar desde trazas y eventos. Sólo reenviará cuando el caso sea seguro." data-confirm-text="Regularizar" data-confirm-danger="true">
                      <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? frc_h((string)csrf_token()) : '' ?>">
                      <input type="hidden" name="factura_id" value="<?= (int)$caso['id'] ?>">
                      <input type="hidden" name="action" value="regularizar">
                      <button type="submit" class="btn-mini btn-mini--danger">Regularizar</button>
                    </form>
                  <?php elseif ($puedeOperarFiscal && ($accionFiscal['url'] ?? '') !== '' && ($accionFiscal['label'] ?? '') !== ''): ?>
                    <a href="<?= frc_h((string)$accionFiscal['url']) ?>" class="btn-mini btn-mini--ghost"><?= frc_h((string)$accionFiscal['label']) ?></a>
                  <?php else: ?>
                    <span class="fact-inline-badge">Solo lectura</span>
                  <?php endif; ?>
                  <?php if ($puedeOperarFiscal && $hasFiscalCierre && $estadoFiscal === 'RECHAZADA'): ?>
                    <form method="post" action="facturacion_recovery.php" class="fact-close-incident-form js-fiscal-confirm" data-confirm-title="Cerrar incidencia fiscal #<?= (int)$caso['id'] ?>" data-confirm-body="La factura rechazada quedará en auditoría, pero saldrá de la bandeja activa sin reemitir." data-confirm-text="Cerrar incidencia">
                      <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? frc_h((string)csrf_token()) : '' ?>">
                      <input type="hidden" name="factura_id" value="<?= (int)$caso['id'] ?>">
                      <input type="hidden" name="action" value="cerrar_incidencia">
                      <input type="text" name="motivo" value="Prueba de homologacion / no reemitir" maxlength="255" class="fact-close-incident-input">
                      <button type="submit" class="btn-mini btn-mini--ghost">Cerrar incidencia</button>
                    </form>
                  <?php endif; ?>
                  <?php if (trim((string)($accionFiscal['help'] ?? '')) !== ''): ?>
                    <div class="fact-cell-sub fact-row-help"><?= frc_h((string)$accionFiscal['help']) ?></div>
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



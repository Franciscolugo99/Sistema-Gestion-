<?php
// public/caja_sesion_detalle.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
require_permission('ver_historial_caja');

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php';

$pdo  = getPDO();
$user = current_user();

function format_datetime_ar(?string $dt): string {
  if (!$dt || $dt === '0000-00-00 00:00:00' || $dt === '') return '—';
  $d = DateTime::createFromFormat('Y-m-d H:i:s', $dt);
  return $d ? $d->format('d/m/Y H:i') : (string)$dt;
}

$sesion_id = sanitize_int($_GET['id'] ?? 0);
if ($sesion_id <= 0) redirect('caja_historial.php');

$error_msg = null;
$debug_msg = null;

$sesion = null;
$ventas = [];
$movimientos = [];

try {
  // Sesión
  $sqlSesion = "
    SELECT cs.*, u.username, u.nombre
    FROM caja_sesiones cs
    LEFT JOIN users u ON u.id = cs.user_id
    WHERE cs.id = :id
    LIMIT 1
  ";
  $stSesion = $pdo->prepare($sqlSesion);
  $stSesion->execute([':id' => $sesion_id]);
  $sesion = $stSesion->fetch(PDO::FETCH_ASSOC);

  if (!$sesion) {
    $error_msg = "Sesión no encontrada";
  } else {
    // Ventas (AJUSTADO A TU DB)
    // - medio_pago existe (no metodo_pago)
    // - productos_count probablemente NO existe -> lo calculamos desde venta_items
    // - si tu tabla venta_items usa otra columna, cambiá el subquery
      $sqlVentas = "
        SELECT
          v.id,
          v.fecha,
          v.total,
          v.medio_pago,
          v.estado,
          COALESCE(SUM(vi.cantidad), 0) AS productos_count
        FROM ventas v
        LEFT JOIN venta_items vi ON vi.venta_id = v.id
        WHERE v.caja_id = :sesion_id
        GROUP BY v.id, v.fecha, v.total, v.medio_pago, v.estado
        ORDER BY v.fecha DESC
      ";


    $stVentas = $pdo->prepare($sqlVentas);
    $stVentas->execute([':sesion_id' => $sesion_id]);
    $ventas = $stVentas->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Movimientos (tu tabla ahora tiene caja_id)
    $sqlMovimientos = "
      SELECT id, tipo, concepto, monto, fecha, usuario_registro
      FROM caja_movimientos
      WHERE caja_id = :sesion_id
      ORDER BY fecha DESC
    ";
    $stMovimientos = $pdo->prepare($sqlMovimientos);
    $stMovimientos->execute([':sesion_id' => $sesion_id]);
    $movimientos = $stMovimientos->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
} catch (PDOException $e) {
  error_log("Error en caja_sesion_detalle: " . $e->getMessage());
  $error_msg = "Error al cargar los datos de la sesión";
  $debug_msg = $e->getMessage(); // útil en local
}

/* Totales */
$total_ventas_activas = 0.0;
$total_ventas_anuladas = 0.0;
$count_activas = 0;
$count_anuladas = 0;

foreach ($ventas as $v) {
  $estado = strtoupper((string)($v['estado'] ?? ''));
  $total  = (float)($v['total'] ?? 0);

  // AJUSTE: tu estado real parece "EMITIDA". Tomo anulada solo si contiene "ANUL"
  if ($estado !== '' && str_contains($estado, 'ANUL')) {
    $total_ventas_anuladas += $total;
    $count_anuladas++;
  } else {
    $total_ventas_activas += $total;
    $count_activas++;
  }
}

$total_mov_ingresos = 0.0;
$total_mov_egresos  = 0.0;

foreach ($movimientos as $m) {
  $tipo  = (string)($m['tipo'] ?? 'ingreso');
  $monto = (float)($m['monto'] ?? 0);
  if ($tipo === 'ingreso') $total_mov_ingresos += $monto;
  else $total_mov_egresos += $monto;
}

/* Header */
$pageTitle      = 'Detalle Sesión #' . $sesion_id . ' - FLUS';
$currentSection = 'caja_historial';
$extraCss       = ['assets/css/caja_sesion_detalle.css?v=2'];

require __DIR__ . '/partials/header.php';
?>

<div class="panel detalle-panel">
  <div class="detalle-header">
    <h1 class="detalle-title">Sesión de Caja #<?= (int)$sesion_id ?></h1>
    <div class="detalle-actions">
      <a href="caja_sesion_print.php?id=<?= (int)$sesion_id ?>" class="btn btn-secondary" target="_blank">🖨️ Imprimir</a>
      <a href="caja_historial.php" class="btn btn-primary">← Volver al historial</a>
    </div>
  </div>

  <?php if ($error_msg): ?>
    <div class="alert alert-error">
      <?= h($error_msg) ?>
      <?php if ($debug_msg): ?>
        <div style="margin-top:8px; font-family: monospace; font-size:12px; opacity:.85;">
          <?= h($debug_msg) ?>
        </div>
      <?php endif; ?>
    </div>
    <a href="caja_historial.php" class="btn btn-primary">← Volver</a>

  <?php elseif ($sesion): ?>

    <?php
      $cierre = (string)($sesion['fecha_cierre'] ?? '');
      $isOpen = ($cierre === '' || $cierre === '0000-00-00 00:00:00' || $cierre === null);
      $dif    = (float)($sesion['diferencia'] ?? 0);
      $difClass = $dif > 0.00001 ? 'pill-pos' : ($dif < -0.00001 ? 'pill-neg' : 'pill-zero');
    ?>

    <div class="info-grid">
      <div class="info-card">
        <h3>👤 Usuario</h3>
        <p class="info-value"><?= h($sesion['username'] ?? '—') ?></p>
        <?php if (!empty($sesion['nombre'])): ?>
          <p class="info-sub"><?= h($sesion['nombre']) ?></p>
        <?php endif; ?>
      </div>

      <div class="info-card">
        <h3>📅 Apertura</h3>
        <p class="info-value"><?= h(format_datetime_ar($sesion['fecha_apertura'] ?? null)) ?></p>
      </div>

      <div class="info-card">
        <h3>🔒 Cierre</h3>
        <?php if ($isOpen): ?>
          <p class="info-value"><span class="pill pill-open">Abierta</span></p>
        <?php else: ?>
          <p class="info-value"><?= h(format_datetime_ar($cierre)) ?></p>
        <?php endif; ?>
      </div>

      <div class="info-card">
        <h3>💵 Saldo Inicial</h3>
        <p class="info-value"><?= money_ar($sesion['saldo_inicial'] ?? 0) ?></p>
      </div>
    </div>

    <div class="resumen-financiero">
      <h2>💰 Resumen Financiero</h2>

      <div class="resumen-grid">
        <div class="resumen-item">
          <span class="resumen-label">Total Sistema:</span>
          <span class="resumen-value"><?= money_ar($sesion['saldo_sistema'] ?? 0) ?></span>
        </div>

        <div class="resumen-item">
          <span class="resumen-label">Total Declarado:</span>
          <span class="resumen-value"><?= money_ar($sesion['saldo_declarado'] ?? 0) ?></span>
        </div>

        <div class="resumen-item">
          <span class="resumen-label">Diferencia:</span>
          <span class="resumen-value <?= h($difClass) ?>"><?= money_ar($dif) ?></span>
        </div>
      </div>

      <div class="resumen-metodos">
        <div class="metodo-item"><span>💵 Efectivo:</span> <strong><?= money_ar($sesion['total_efectivo'] ?? 0) ?></strong></div>
        <div class="metodo-item"><span>📱 Mercado Pago:</span> <strong><?= money_ar($sesion['total_mp'] ?? 0) ?></strong></div>
        <div class="metodo-item"><span>💳 Débito:</span> <strong><?= money_ar($sesion['total_debito'] ?? 0) ?></strong></div>
        <div class="metodo-item"><span>💳 Crédito:</span> <strong><?= money_ar($sesion['total_credito'] ?? 0) ?></strong></div>
      </div>

      <div class="resumen-stats">
        <div class="stat-item"><span>🛒 Total Ventas:</span> <strong><?= money_ar($sesion['total_ventas'] ?? 0) ?></strong></div>
        <div class="stat-item"><span>📦 Productos Vendidos:</span> <strong><?= (int)($sesion['total_productos'] ?? 0) ?></strong></div>
        <div class="stat-item"><span>❌ Anulaciones:</span> <strong><?= (int)($sesion['total_anulaciones'] ?? 0) ?></strong></div>
      </div>
    </div>

    <div class="movimientos-section">
      <h2>💸 Movimientos de Caja</h2>

      <?php if (empty($movimientos)): ?>
        <p class="empty-message">No hay movimientos registrados en esta sesión.</p>
      <?php else: ?>
        <div class="movimientos-resumen">
          <span>Ingresos: <strong><?= money_ar($total_mov_ingresos) ?></strong></span>
          <span>Egresos: <strong><?= money_ar($total_mov_egresos) ?></strong></span>
        </div>

        <table class="tabla-movimientos">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Tipo</th>
              <th>Concepto</th>
              <th class="t-right">Monto</th>
              <th>Registrado por</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($movimientos as $mov): ?>
              <?php
                $tipo = (string)($mov['tipo'] ?? 'ingreso');
                $tipoClass = $tipo === 'ingreso' ? 'pill-success' : 'pill-danger';
                $tipoLabel = $tipo === 'ingreso' ? '+ Ingreso' : '- Egreso';
              ?>
              <tr>
                <td><?= h(format_datetime_ar($mov['fecha'] ?? null)) ?></td>
                <td><span class="pill <?= h($tipoClass) ?>"><?= h($tipoLabel) ?></span></td>
                <td><?= h($mov['concepto'] ?? '—') ?></td>
                <td class="t-right"><?= money_ar($mov['monto'] ?? 0) ?></td>
                <td><?= h($mov['usuario_registro'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="ventas-section">
      <h2>🛒 Ventas Realizadas (<?= count($ventas) ?>)</h2>

      <?php if ($count_anuladas > 0): ?>
        <div class="ventas-resumen">
          <span>Activas: <strong><?= (int)$count_activas ?></strong> (<?= money_ar($total_ventas_activas) ?>)</span>
          <span class="text-danger">Anuladas: <strong><?= (int)$count_anuladas ?></strong> (<?= money_ar($total_ventas_anuladas) ?>)</span>
        </div>
      <?php endif; ?>

      <?php if (empty($ventas)): ?>
        <p class="empty-message">No hay ventas registradas en esta sesión.</p>
      <?php else: ?>
        <table class="tabla-ventas">
          <thead>
            <tr>
              <th>#</th>
              <th>Fecha</th>
              <th>Cliente</th>
              <th class="t-center">Productos</th>
              <th>Método de Pago</th>
              <th class="t-right">Total</th>
              <th class="t-center">Estado</th>
              <th class="t-center">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($ventas as $venta): ?>
              <?php
                $vid = (int)($venta['id'] ?? 0);

                $estadoRaw = (string)($venta['estado'] ?? '');
                $estadoUp  = strtoupper($estadoRaw);
                $isAnulada = ($estadoUp !== '' && str_contains($estadoUp, 'ANUL')); // ANULADA

                $estadoClass = $isAnulada ? 'pill-danger' : 'pill-success';

                // en tu DB es medio_pago
                $metodo = (string)($venta['medio_pago'] ?? '—');

                // NO hay cliente en tu tabla ventas
                $cliente = 'Cliente genérico';

                // NO hay productos_count (por ahora)
                $productosCount = (int)($venta['productos_count'] ?? 0);
              ?>

              <tr class="<?= $isAnulada ? 'venta-anulada' : '' ?>">
                <td class="mono"><?= $vid ?></td>
                <td class="mono"><?= h(format_datetime_ar($venta['fecha'] ?? null)) ?></td>
                <td><?= h($cliente) ?></td>
                <td class="t-center"><?= h($productosCount) ?></td>
                <td><?= h($metodo) ?></td>
                <td class="t-right"><?= money_ar($venta['total'] ?? 0) ?></td>
                <td class="t-center">
                  <span class="pill <?= h($estadoClass) ?>"><?= h($estadoRaw ?: '—') ?></span>
                </td>
                <td class="t-center">
                  <a href="venta_detalle.php?id=<?= $vid ?>" class="btn-icon" title="Ver detalle">👁️</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>

        </table>
      <?php endif; ?>
    </div>

  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

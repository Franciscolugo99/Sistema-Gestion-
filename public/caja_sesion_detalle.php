<?php
// public/caja_sesion_detalle.php
// FLUS v3.2.2 - Refactorizado con mejoras de UX, light mode y funcionalidad
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('ver_historial_caja');

$sesion_id = sanitize_int($_GET['id'] ?? 0);
if ($sesion_id <= 0) redirect('caja_historial.php');

$error_msg = null;
$debug_msg = null;

$sesion = null;
$ventas = [];
$movimientos = [];
$terminal_nombre = null;

try {
  // ═══════════════════════════════════════════════════════════════════
  // 1. SESIÓN DE CAJA + USUARIO + TERMINAL
  // ═══════════════════════════════════════════════════════════════════
  $sqlSesion = "
    SELECT 
      cs.*,
      u.username,
      u.nombre AS usuario_nombre,
      t.nombre AS terminal_nombre
    FROM caja_sesiones cs
    LEFT JOIN users u ON u.id = cs.user_id
    LEFT JOIN terminales t ON t.id = cs.terminal_id
    WHERE cs.id = :id
    LIMIT 1
  ";
  $stSesion = $pdo->prepare($sqlSesion);
  $stSesion->execute([':id' => $sesion_id]);
  $sesion = $stSesion->fetch(PDO::FETCH_ASSOC);

  if (!$sesion) {
    $error_msg = "Sesión no encontrada";
  } else {
    // ═══════════════════════════════════════════════════════════════════
    // 2. VENTAS DE LA SESIÓN + CLIENTE
    // ═══════════════════════════════════════════════════════════════════
    $sqlVentas = "
      SELECT
        v.id,
        v.fecha,
        v.total,
        v.medio_pago,
        v.estado,
        COALESCE(c.nombre, 'Consumidor Final') AS cliente_nombre,
        COALESCE(SUM(vi.cantidad), 0) AS productos_count
      FROM ventas v
      LEFT JOIN clientes c ON c.id = v.cliente_id
      LEFT JOIN venta_items vi ON vi.venta_id = v.id
      WHERE v.caja_id = :sesion_id
      GROUP BY v.id, v.fecha, v.total, v.medio_pago, v.estado, c.nombre
      ORDER BY v.fecha DESC
    ";
    $stVentas = $pdo->prepare($sqlVentas);
    $stVentas->execute([':sesion_id' => $sesion_id]);
    $ventas = $stVentas->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ═══════════════════════════════════════════════════════════════════
    // 3. MOVIMIENTOS DE CAJA
    // ═══════════════════════════════════════════════════════════════════
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
  if (defined('FLUS_DEBUG') && FLUS_DEBUG) {
    $debug_msg = $e->getMessage();
  }
}

// ═══════════════════════════════════════════════════════════════════
// 4. CÁLCULO DE TOTALES
// ═══════════════════════════════════════════════════════════════════
$total_ventas_activas = 0.0;
$total_ventas_anuladas = 0.0;
$count_activas = 0;
$count_anuladas = 0;

foreach ($ventas as $v) {
  $estado = strtoupper((string)($v['estado'] ?? ''));
  $total  = (float)($v['total'] ?? 0);

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
  $tipo  = strtolower((string)($m['tipo'] ?? 'ingreso'));
  $monto = (float)($m['monto'] ?? 0);
  if ($tipo === 'ingreso') $total_mov_ingresos += $monto;
  else $total_mov_egresos += $monto;
}

// ═══════════════════════════════════════════════════════════════════
// 5. ESTADO DE LA SESIÓN
// ═══════════════════════════════════════════════════════════════════
$cierre = (string)($sesion['fecha_cierre'] ?? '');
$isOpen = ($cierre === '' || $cierre === '0000-00-00 00:00:00' || $cierre === null);
$dif    = (float)($sesion['diferencia'] ?? 0);

// Clase para el panel según estado
$panelStateClass = 'sesion-cerrada';
if ($isOpen) {
  $panelStateClass = 'sesion-abierta';
} elseif (abs($dif) > 0.009) {
  $panelStateClass = 'sesion-con-diferencia';
}

// ═══════════════════════════════════════════════════════════════════
// 6. HEADER
// ═══════════════════════════════════════════════════════════════════
$pageTitle      = 'Detalle Sesión #' . $sesion_id . ' - FLUS';
$currentSection = 'caja_historial';
$extraCss       = ['assets/css/caja_sesion_detalle.css?v=3'];
$extraJs        = ['assets/js/caja_sesion_detalle.js?v=1'];
$bodyClass      = 'caja-sesion-detalle-page';

require __DIR__ . '/partials/header.php';
?>

<div class="panel detalle-panel <?= h($panelStateClass) ?>">
  
  <!-- ═══════════════════════════════════════════════════════════════ -->
  <!-- HEADER -->
  <!-- ═══════════════════════════════════════════════════════════════ -->
  <div class="detalle-header">
    <div class="detalle-header-left">
      <h1 class="detalle-title">Sesión de Caja #<?= (int)$sesion_id ?></h1>
      <?php if (!empty($sesion['terminal_nombre'])): ?>
        <span class="detalle-terminal">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
          <?= h($sesion['terminal_nombre']) ?>
        </span>
      <?php endif; ?>
    </div>
    <div class="detalle-actions">
      <a href="caja_sesion_export.php?id=<?= (int)$sesion_id ?>" class="btn btn-secondary btn-icon-text" title="Descargar como CSV (compatible con Excel)">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        CSV
      </a>
      <a href="caja_sesion_print.php?id=<?= (int)$sesion_id ?>" class="btn btn-secondary btn-icon-text" target="_blank" title="Imprimir">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Imprimir
      </a>
      <a href="caja_historial.php" class="btn btn-primary btn-icon-text">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        Volver al historial
      </a>
    </div>
  </div>

  <?php if ($error_msg): ?>
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- ERROR -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="alert alert-error">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <span><?= h($error_msg) ?></span>
      <?php if ($debug_msg): ?>
        <code class="debug-msg"><?= h($debug_msg) ?></code>
      <?php endif; ?>
    </div>
    <a href="caja_historial.php" class="btn btn-primary">← Volver</a>

  <?php elseif ($sesion): ?>

    <?php if ($isOpen): ?>
      <!-- BANNER SESIÓN ABIERTA -->
      <div class="alert alert-warning">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <span>Esta sesión de caja sigue <strong>abierta</strong>. Los totales se actualizan con cada venta.</span>
        <a href="caja_cerrar.php?id=<?= (int)$sesion_id ?>" class="btn btn-sm btn-warning">Cerrar caja</a>
      </div>
    <?php endif; ?>

    <?php
      $difClass = 'pill-zero';
      if ($dif > 0.009) $difClass = 'pill-pos';
      elseif ($dif < -0.009) $difClass = 'pill-neg';
    ?>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- INFO CARDS -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="info-grid">
      <div class="info-card">
        <h3>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Usuario
        </h3>
        <p class="info-value"><?= h($sesion['username'] ?? '—') ?></p>
        <?php if (!empty($sesion['usuario_nombre'])): ?>
          <p class="info-sub"><?= h($sesion['usuario_nombre']) ?></p>
        <?php endif; ?>
      </div>

      <div class="info-card">
        <h3>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Apertura
        </h3>
        <p class="info-value"><?= h(format_datetime_ar($sesion['fecha_apertura'] ?? null)) ?></p>
      </div>

      <div class="info-card">
        <h3>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          Cierre
        </h3>
        <?php if ($isOpen): ?>
          <p class="info-value"><span class="pill pill-open">Abierta</span></p>
        <?php else: ?>
          <p class="info-value"><?= h(format_datetime_ar($cierre)) ?></p>
        <?php endif; ?>
      </div>

      <div class="info-card">
        <h3>
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
          Saldo Inicial
        </h3>
        <p class="info-value"><?= money_ar($sesion['saldo_inicial'] ?? 0) ?></p>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- RESUMEN FINANCIERO -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="resumen-financiero">
      <h2>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 18V6"/></svg>
        Resumen Financiero
      </h2>

      <div class="resumen-grid">
        <div class="resumen-item">
          <span class="resumen-label">
            Total Sistema
            <span class="hint" title="Calculado automáticamente: saldo inicial + ventas efectivo + ingresos - egresos">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </span>
          </span>
          <span class="resumen-value"><?= money_ar($sesion['saldo_sistema'] ?? 0) ?></span>
        </div>

        <div class="resumen-item">
          <span class="resumen-label">
            Total Declarado
            <span class="hint" title="Monto contado físicamente por el cajero al cerrar">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </span>
          </span>
          <span class="resumen-value"><?= money_ar($sesion['saldo_declarado'] ?? 0) ?></span>
        </div>

        <div class="resumen-item">
          <span class="resumen-label">
            Diferencia
            <span class="hint" title="Declarado - Sistema. Positivo = sobrante, Negativo = faltante">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </span>
          </span>
          <span class="resumen-value <?= h($difClass) ?>"><?= money_ar($dif) ?></span>
        </div>
      </div>

      <div class="resumen-metodos">
        <div class="metodo-item">
          <span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>
            Efectivo
          </span>
          <strong><?= money_ar($sesion['total_efectivo'] ?? 0) ?></strong>
        </div>
        <div class="metodo-item">
          <span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            Mercado Pago
          </span>
          <strong><?= money_ar($sesion['total_mp'] ?? 0) ?></strong>
        </div>
        <div class="metodo-item">
          <span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            Débito
          </span>
          <strong><?= money_ar($sesion['total_debito'] ?? 0) ?></strong>
        </div>
        <div class="metodo-item">
          <span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            Crédito
          </span>
          <strong><?= money_ar($sesion['total_credito'] ?? 0) ?></strong>
        </div>
        <?php if (isset($sesion['total_transferencia'])): ?>
        <div class="metodo-item">
          <span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
            Transferencia
          </span>
          <strong><?= money_ar($sesion['total_transferencia'] ?? 0) ?></strong>
        </div>
        <?php endif; ?>
      </div>

      <div class="resumen-stats">
        <div class="stat-item">
          <span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
            Total Ventas
          </span>
          <strong><?= money_ar($sesion['total_ventas'] ?? 0) ?></strong>
        </div>
        <div class="stat-item">
          <span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
            Productos Vendidos
          </span>
          <strong><?= (int)($sesion['total_productos'] ?? 0) ?></strong>
        </div>
        <div class="stat-item">
          <span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            Anulaciones
          </span>
          <strong><?= (int)($sesion['total_anulaciones'] ?? 0) ?></strong>
        </div>
      </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- OBSERVACIONES DE CIERRE -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <?php if (!$isOpen && !empty($sesion['notas'])): ?>
    <div class="observaciones-section">
      <h2>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        Observaciones de Cierre
      </h2>
      <div class="observaciones-content">
        <?= nl2br(h($sesion['notas'])) ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- MOVIMIENTOS DE CAJA -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="movimientos-section">
      <h2>
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
        Movimientos de Caja
      </h2>

      <?php if (empty($movimientos)): ?>
        <p class="empty-message">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          No hay movimientos registrados en esta sesión.
        </p>
      <?php else: ?>
        <div class="movimientos-resumen">
          <span class="mov-ingreso">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="19" x2="12" y2="5"/><polyline points="5 12 12 5 19 12"/></svg>
            Ingresos: <strong><?= money_ar($total_mov_ingresos) ?></strong>
          </span>
          <span class="mov-egreso">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><polyline points="19 12 12 19 5 12"/></svg>
            Egresos: <strong><?= money_ar($total_mov_egresos) ?></strong>
          </span>
        </div>

        <div class="table-wrap">
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
                  $tipo = strtolower((string)($mov['tipo'] ?? 'ingreso'));
                  $tipoClass = $tipo === 'ingreso' ? 'pill-success' : 'pill-danger';
                  $tipoLabel = $tipo === 'ingreso' ? '+ Ingreso' : '- Egreso';
                ?>
                <tr>
                  <td class="mono"><?= h(format_datetime_ar($mov['fecha'] ?? null)) ?></td>
                  <td><span class="pill <?= h($tipoClass) ?>"><?= h($tipoLabel) ?></span></td>
                  <td><?= h($mov['concepto'] ?? '—') ?></td>
                  <td class="t-right mono"><?= money_ar($mov['monto'] ?? 0) ?></td>
                  <td><?= h($mov['usuario_registro'] ?? '—') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- VENTAS REALIZADAS -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <div class="ventas-section">
      <div class="ventas-header">
        <h2>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
          Ventas Realizadas
          <span class="ventas-count">(<?= count($ventas) ?>)</span>
        </h2>
        
        <?php if (!empty($ventas)): ?>
        <!-- FILTROS -->
        <div class="ventas-filters">
          <select id="filtroMetodo" class="filter-select">
            <option value="">Todos los métodos</option>
            <option value="efectivo">Efectivo</option>
            <option value="mp">Mercado Pago</option>
            <option value="debito">Débito</option>
            <option value="credito">Crédito</option>
            <option value="otro">Otro</option>
          </select>
          
          <label class="chk">
            <input type="checkbox" id="ocultarAnuladas" checked>
            <span>Ocultar anuladas</span>
          </label>
          
          <button type="button" id="btnLimpiarFiltros" class="btn btn-sm btn-ghost">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M7 12h10"/><path d="M10 18h4"/></svg>
            Limpiar
          </button>
        </div>
        <?php endif; ?>
      </div>

      <?php if ($count_anuladas > 0): ?>
        <div class="ventas-resumen">
          <span class="ventas-activas">
            Activas: <strong><?= (int)$count_activas ?></strong> (<?= money_ar($total_ventas_activas) ?>)
          </span>
          <span class="ventas-anuladas">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            Anuladas: <strong><?= (int)$count_anuladas ?></strong> (<?= money_ar($total_ventas_anuladas) ?>)
          </span>
        </div>
      <?php endif; ?>

      <?php if (empty($ventas)): ?>
        <p class="empty-message">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          No hay ventas registradas en esta sesión.
        </p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="tabla-ventas" id="tablaVentas">
            <thead>
              <tr>
                <th>#</th>
                <th>Fecha</th>
                <th>Cliente</th>
                <th class="t-center">Productos</th>
                <th>Método de Pago</th>
                <th class="t-right">Total</th>
                <th class="t-center">Estado</th>
                <th class="t-center col-actions">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($ventas as $venta): ?>
                <?php
                  $vid = (int)($venta['id'] ?? 0);
                  $estadoRaw = (string)($venta['estado'] ?? 'EMITIDA');
                  $estadoUp  = strtoupper($estadoRaw);
                  $isAnulada = ($estadoUp === 'ANULADA');
                  $estadoClass = $isAnulada ? 'pill-danger' : 'pill-success';
                  
                  // Normalizar método de pago para filtros
                  $metodoRaw = strtoupper(trim((string)($venta['medio_pago'] ?? 'EFECTIVO')));
                  $metodoNorm = match(true) {
                    str_contains($metodoRaw, 'EFEC') => 'efectivo',
                    str_contains($metodoRaw, 'MP') || str_contains($metodoRaw, 'MERCADO') => 'mp',
                    str_contains($metodoRaw, 'DEB') => 'debito',
                    str_contains($metodoRaw, 'CRED') => 'credito',
                    default => 'otro'
                  };
                  $metodoDisplay = match($metodoNorm) {
                    'efectivo' => 'Efectivo',
                    'mp' => 'Mercado Pago',
                    'debito' => 'Débito',
                    'credito' => 'Crédito',
                    default => ucfirst($metodoRaw)
                  };
                  
                  $cliente = (string)($venta['cliente_nombre'] ?? 'Consumidor Final');
                  $productosCount = (int)($venta['productos_count'] ?? 0);
                ?>

                <tr class="venta-row <?= $isAnulada ? 'venta-anulada' : '' ?>" 
                    data-metodo="<?= h($metodoNorm) ?>" 
                    data-anulada="<?= $isAnulada ? '1' : '0' ?>">
                  <td class="mono"><?= $vid ?></td>
                  <td class="mono nowrap"><?= h(format_datetime_ar($venta['fecha'] ?? null)) ?></td>
                  <td><?= h($cliente) ?></td>
                  <td class="t-center"><?= $productosCount ?></td>
                  <td>
                    <span class="badge-medio badge-medio-<?= h($metodoNorm) ?>">
                      <?= h($metodoDisplay) ?>
                    </span>
                  </td>
                  <td class="t-right mono"><?= money_ar($venta['total'] ?? 0) ?></td>
                  <td class="t-center">
                    <span class="pill <?= h($estadoClass) ?>"><?= h($estadoRaw ?: 'EMITIDA') ?></span>
                  </td>
                  <td class="t-center">
                    <div class="row-actions">
                      <a href="venta_detalle.php?id=<?= $vid ?>" class="btn-icon" title="Ver detalle">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                      </a>
                      <a href="ticket.php?id=<?= $vid ?>" class="btn-icon" title="Ver ticket" target="_blank">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        
        <!-- Mensaje cuando no hay resultados del filtro -->
        <p class="empty-message filter-empty" id="noResultsMsg" style="display: none;">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          No se encontraron ventas con los filtros seleccionados.
        </p>
      <?php endif; ?>
    </div>

  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

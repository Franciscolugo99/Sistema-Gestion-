<?php
// public/caja_sesion_print.php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/caja_session_summary.php';

require_login();
require_permission('ver_historial_caja');



/* --------------------------------------------------------
   FUNCIÓN AUXILIAR
-------------------------------------------------------- */

/* --------------------------------------------------------
   OBTENER ID DE SESIÓN
-------------------------------------------------------- */
$sesion_id = sanitize_int($_GET['id'] ?? 0);
if ($sesion_id <= 0) {
  flus_abort(400, 'ID de sesión inválido');
}

$sesion = null;
$ventas = [];
$movimientos = [];
$mediosResumen = [
  'ventas_cc' => 0.0,
  'cobros_cc' => 0.0,
  'base_medios' => 0.0,
  'suma_medios' => 0.0,
  'diff_medios' => 0.0,
];

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
    flus_abort(404, 'Sesión no encontrada');
  }

  $hasVentaMontoCc = flus_column_exists($pdo, 'ventas', 'monto_cc');
  $hasVentaPagos = flus_table_exists($pdo, 'venta_pagos');
  $ventaMontoCcSelect = $hasVentaMontoCc ? 'v.monto_cc' : '0 AS monto_cc';
  $ventaPagosSelect = $hasVentaPagos ? 'vp.pagos_label' : "NULL AS pagos_label";
  $ventaPagosJoin = $hasVentaPagos ? "
    LEFT JOIN (
      SELECT venta_id, GROUP_CONCAT(UPPER(medio_pago) ORDER BY id SEPARATOR ' + ') AS pagos_label
      FROM venta_pagos
      GROUP BY venta_id
    ) vp ON vp.venta_id = v.id
  " : '';
  $ventaMontoCcGroup = $hasVentaMontoCc ? ', v.monto_cc' : '';
  $ventaPagosGroup = $hasVentaPagos ? ', vp.pagos_label' : '';

  // Ventas + productos_count desde venta_items (TU DB)
  $sqlVentas = "
    SELECT
      v.id,
      v.fecha,
      v.total,
      {$ventaMontoCcSelect},
      v.medio_pago,
      {$ventaPagosSelect},
      v.estado,
      COALESCE(SUM(vi.cantidad), 0) AS productos_count
    FROM ventas v
    LEFT JOIN venta_items vi ON vi.venta_id = v.id
    {$ventaPagosJoin}
    WHERE v.caja_id = :sesion_id
    GROUP BY v.id, v.fecha, v.total{$ventaMontoCcGroup}, v.medio_pago, v.estado{$ventaPagosGroup}
    ORDER BY v.fecha ASC
  ";
  $stVentas = $pdo->prepare($sqlVentas);
  $stVentas->execute([':sesion_id' => $sesion_id]);
  $ventas = $stVentas->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // Movimientos
  $movMedioPagoSelect = flus_column_exists($pdo, 'caja_movimientos', 'medio_pago') ? 'medio_pago' : 'NULL AS medio_pago';
  $movCcSelect = flus_column_exists($pdo, 'caja_movimientos', 'cc_movimiento_id') ? 'cc_movimiento_id' : 'NULL AS cc_movimiento_id';
  $sqlMovimientos = "
    SELECT id, tipo, concepto, monto, fecha, usuario_registro, {$movMedioPagoSelect}, {$movCcSelect}
    FROM caja_movimientos
    WHERE caja_id = :sesion_id
    ORDER BY fecha ASC
  ";
  $stMovimientos = $pdo->prepare($sqlMovimientos);
  $stMovimientos->execute([':sesion_id' => $sesion_id]);
  $movimientos = $stMovimientos->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $mediosResumen = flus_caja_sesion_medios_resumen($pdo, $sesion_id, $sesion);

} catch (PDOException $e) {
  error_log("Error en caja_sesion_print: " . $e->getMessage());
  flus_abort(500, 'Error al cargar los datos');
}

/* --------------------------------------------------------
   CALCULAR TOTALES
-------------------------------------------------------- */
$total_ventas_activas = 0.0;
$total_ventas_anuladas = 0.0;
$count_activas = 0;
$count_anuladas = 0;

foreach ($ventas as $v) {
  $estado = strtoupper((string)($v['estado'] ?? ''));
  $total  = (float)($v['total'] ?? 0);

  $isAnul = ($estado !== '' && str_contains($estado, 'ANUL'));
  if ($isAnul) {
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

// Estado caja
$fc = (string)($sesion['fecha_cierre'] ?? '');
$isOpen = ($fc === '' || $fc === '0000-00-00 00:00:00');

// Diferencia
$dif = (float)($sesion['diferencia'] ?? 0);
$difClass = $dif > 0.00001 ? 'positivo' : ($dif < -0.00001 ? 'negativo' : 'neutro');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sesión de Caja #<?= (int)$sesion_id ?> - FLUS</title>
  <link rel="stylesheet" href="assets/css/caja_sesion_print.css?v=1">
</head>
<body>

<button class="btn-print no-print" onclick="window.print()">🖨️ Imprimir</button>

<div class="header">
  <h1>REPORTE DE SESIÓN DE CAJA</h1>
  <div class="subtitle">Sistema FLUS - Gestión Comercial</div>
  <div class="subtitle">Sesión #<?= (int)$sesion_id ?></div>
</div>

<div class="block">
  <h2>📋 Información General</h2>
  <div class="info-grid">
    <div class="info-item">
      <span class="info-label">Usuario:</span>
      <span><?= h($sesion['username'] ?? '—') ?></span>
    </div>
    <div class="info-item">
      <span class="info-label">Estado:</span>
      <span>
        <?php if ($isOpen): ?>
          <span class="status-badge status-open">ABIERTA</span>
        <?php else: ?>
          <span class="status-badge status-closed">CERRADA</span>
        <?php endif; ?>
      </span>
    </div>
    <div class="info-item">
      <span class="info-label">Apertura:</span>
      <span><?= h(format_datetime_ar($sesion['fecha_apertura'] ?? null)) ?></span>
    </div>
    <div class="info-item">
      <span class="info-label">Cierre:</span>
      <span><?= $isOpen ? '—' : h(format_datetime_ar($sesion['fecha_cierre'] ?? null)) ?></span>
    </div>
  </div>
</div>

<div class="resumen">
  <h2>💰 RESUMEN FINANCIERO</h2>

  <div class="resumen-grid">
    <div class="resumen-item">
      <span class="resumen-label">Saldo Inicial</span>
      <span class="resumen-value"><?= money_ar($sesion['saldo_inicial'] ?? 0) ?></span>
    </div>

    <div class="resumen-item">
      <span class="resumen-label">Total Sistema</span>
      <span class="resumen-value"><?= money_ar($sesion['saldo_sistema'] ?? 0) ?></span>
    </div>

    <div class="resumen-item">
      <span class="resumen-label">Total Declarado</span>
      <span class="resumen-value"><?= money_ar($sesion['saldo_declarado'] ?? 0) ?></span>
    </div>
  </div>

  <div class="resumen-grid" style="border-bottom:0; padding-bottom:0; margin-bottom:0;">
    <div class="resumen-item">
      <span class="resumen-label">Diferencia</span>
      <span class="resumen-value <?= h($difClass) ?>"><?= money_ar($dif) ?></span>
    </div>

    <div class="resumen-item">
      <span class="resumen-label">Total Ventas</span>
      <span class="resumen-value"><?= money_ar($sesion['total_ventas'] ?? 0) ?></span>
    </div>

    <div class="resumen-item">
      <span class="resumen-label">Ventas a CC</span>
      <span class="resumen-value"><?= money_ar((float)$mediosResumen['ventas_cc']) ?></span>
    </div>

    <div class="resumen-item">
      <span class="resumen-label">Cobros CC</span>
      <span class="resumen-value"><?= money_ar((float)$mediosResumen['cobros_cc']) ?></span>
    </div>

    <div class="resumen-item">
      <span class="resumen-label">Base Medios</span>
      <span class="resumen-value"><?= money_ar((float)$mediosResumen['base_medios']) ?></span>
    </div>

    <div class="resumen-item">
      <span class="resumen-label">Suma Medios</span>
      <span class="resumen-value"><?= money_ar((float)$mediosResumen['suma_medios']) ?></span>
    </div>

    <div class="resumen-item">
      <span class="resumen-label">Diff Medios</span>
      <span class="resumen-value"><?= money_ar((float)$mediosResumen['diff_medios']) ?></span>
    </div>

    <div class="resumen-item">
      <span class="resumen-label">Productos Vendidos</span>
      <span class="resumen-value"><?= (int)($sesion['total_productos'] ?? 0) ?></span>
    </div>
  </div>

  <div class="metodos">
    <div class="metodo-item">
      <span class="metodo-label">💵 Efectivo</span>
      <span class="metodo-value"><?= money_ar($sesion['total_efectivo'] ?? 0) ?></span>
    </div>
    <div class="metodo-item">
      <span class="metodo-label">📱 Mercado Pago</span>
      <span class="metodo-value"><?= money_ar($sesion['total_mp'] ?? 0) ?></span>
    </div>
    <div class="metodo-item">
      <span class="metodo-label">💳 Débito</span>
      <span class="metodo-value"><?= money_ar($sesion['total_debito'] ?? 0) ?></span>
    </div>
    <div class="metodo-item">
      <span class="metodo-label">💳 Crédito</span>
      <span class="metodo-value"><?= money_ar($sesion['total_credito'] ?? 0) ?></span>
    </div>
    <?php if (isset($sesion['total_transferencia'])): ?>
    <div class="metodo-item">
      <span class="metodo-label">🏦 Transferencia</span>
      <span class="metodo-value"><?= money_ar($sesion['total_transferencia'] ?? 0) ?></span>
    </div>
    <?php endif; ?>
  </div>
</div>

<h3 class="section-title">💸 Movimientos de Caja</h3>
<?php if (empty($movimientos)): ?>
  <div class="empty">No hay movimientos registrados en esta sesión.</div>
<?php else: ?>
  <div class="table-meta">
    <strong>Ingresos:</strong> <?= money_ar($total_mov_ingresos) ?> |
    <strong>Egresos:</strong> <?= money_ar($total_mov_egresos) ?>
  </div>

  <table>
    <thead>
      <tr>
        <th>Fecha</th>
        <th>Tipo</th>
        <th>Concepto</th>
        <th class="t-right">Monto</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($movimientos as $mov): ?>
        <tr>
          <td class="mono"><?= h(format_datetime_ar($mov['fecha'] ?? null)) ?></td>
          <td><?= h(ucfirst((string)($mov['tipo'] ?? '—'))) ?></td>
          <td><?= h($mov['concepto'] ?? '—') ?></td>
          <td class="t-right"><?= money_ar($mov['monto'] ?? 0) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<h3 class="section-title">🛒 Detalle de Ventas (<?= count($ventas) ?>)</h3>

<?php if ($count_anuladas > 0): ?>
  <div class="table-meta" style="background:#fff3cd; border-color:#ffd56a;">
    <strong>Ventas Activas:</strong> <?= (int)$count_activas ?> (<?= money_ar($total_ventas_activas) ?>) |
    <strong style="color:#c62828;">Anuladas:</strong> <?= (int)$count_anuladas ?> (<?= money_ar($total_ventas_anuladas) ?>)
  </div>
<?php endif; ?>

<?php if (empty($ventas)): ?>
  <div class="empty">No hay ventas registradas</div>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Fecha</th>
        <th>Cliente</th>
        <th class="t-center">Prods.</th>
        <th>Método</th>
        <th class="t-right">Total</th>
        <th class="t-center">Estado</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($ventas as $venta): ?>
        <?php
          $estadoRaw = (string)($venta['estado'] ?? '');
          $estadoUp  = strtoupper($estadoRaw);
          $isAnul    = ($estadoUp !== '' && str_contains($estadoUp, 'ANUL'));
          $estadoClass = $isAnul ? 'estado-anulada' : 'estado-activa';
          $metodo = flus_caja_sesion_pago_label($venta);
        ?>
        <tr>
          <td class="mono"><?= (int)($venta['id'] ?? 0) ?></td>
          <td class="mono"><?= h(format_datetime_ar($venta['fecha'] ?? null)) ?></td>
          <td><?= h('Cliente genérico') ?></td>
          <td class="t-center"><?= (int)($venta['productos_count'] ?? 0) ?></td>
          <td><?= h($metodo ?: '—') ?></td>
          <td class="t-right"><?= money_ar($venta['total'] ?? 0) ?></td>
          <td class="t-center <?= h($estadoClass) ?>"><?= h($estadoRaw ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<div class="footer">
  <p>Generado el: <?= date('d/m/Y H:i:s') ?></p>
  <p>Sistema FLUS - Gestión Comercial | Usuario: <?= h($user['username'] ?? '—') ?></p>
</div>

</body>
</html>

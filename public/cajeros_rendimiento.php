<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once FLUS_ROOT . '/src/venta_anulaciones_lib.php';

require_login();
require_any_permission(['administrar_usuarios', 'administrar_config']);

if (function_exists('date_default_timezone_set')) {
  @date_default_timezone_set('America/Argentina/Mendoza');
}

function cajeros_valid_shift(string $value): string {
  return in_array($value, ['manana', 'tarde', 'noche'], true) ? $value : '';
}

function cajeros_shift_label(string $value): string {
  return [
    'manana' => "Ma\u{00F1}ana",
    'tarde' => 'Tarde',
    'noche' => 'Noche',
  ][$value] ?? 'Todos';
}

function cajeros_shift_hint(string $value): string {
  return [
    'manana' => '06:00 a 13:59',
    'tarde' => '14:00 a 21:59',
    'noche' => '22:00 a 05:59',
  ][$value] ?? 'Segun apertura de caja';
}

function cajeros_bind_params(PDOStatement $st, array $params): void {
  foreach ($params as $key => $value) {
    if (is_int($value)) {
      $st->bindValue($key, $value, PDO::PARAM_INT);
    } else {
      $st->bindValue($key, (string)$value, PDO::PARAM_STR);
    }
  }
}

function cajeros_num(float $value, int $decimals = 1): string {
  return number_format($value, $decimals, ',', '.');
}

$today = date('Y-m-d');
$monthStart = date('Y-m-01');

$desde = validDateYmd($_GET['desde'] ?? null) ?? $monthStart;
$hasta = validDateYmd($_GET['hasta'] ?? null) ?? $today;
if ($desde > $hasta) {
  [$desde, $hasta] = [$hasta, $desde];
}

$usuarioId = isset($_GET['usuario']) && ctype_digit((string)$_GET['usuario']) ? (int)$_GET['usuario'] : 0;
$terminalId = isset($_GET['terminal']) && ctype_digit((string)$_GET['terminal']) ? (int)$_GET['terminal'] : 0;
$shift = cajeros_valid_shift(trim((string)($_GET['turno'] ?? '')));

$shiftExpr = "CASE
  WHEN HOUR(cs.fecha_apertura) >= 6 AND HOUR(cs.fecha_apertura) < 14 THEN 'manana'
  WHEN HOUR(cs.fecha_apertura) >= 14 AND HOUR(cs.fecha_apertura) < 22 THEN 'tarde'
  ELSE 'noche'
END";
$endExpr = "IF(cs.fecha_cierre IS NULL OR cs.fecha_cierre = '' OR cs.fecha_cierre = '0000-00-00 00:00:00', NOW(), cs.fecha_cierre)";

$where = [
  'cs.fecha_apertura >= :desde_dt',
  'cs.fecha_apertura <= :hasta_dt',
];
$params = [
  ':desde_dt' => $desde . ' 00:00:00',
  ':hasta_dt' => $hasta . ' 23:59:59',
];

if ($usuarioId > 0) {
  $where[] = 'cs.user_id = :usuario_id';
  $params[':usuario_id'] = $usuarioId;
}
if ($terminalId > 0) {
  $where[] = 'cs.terminal_id = :terminal_id';
  $params[':terminal_id'] = $terminalId;
}
if ($shift !== '') {
  $where[] = "{$shiftExpr} = :turno";
  $params[':turno'] = $shift;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$ventasAnulJoin = flus_venta_anulaciones_totales_join_sql($pdo, 'v', 'vaa');
$ventasImporteExpr = $ventasAnulJoin !== ''
  ? flus_venta_importe_vigente_expr_sql('v.total', 'COALESCE(vaa.monto_anulado_total,0)')
  : 'v.total';

$itemsAnulJoin = flus_venta_items_anulados_join_sql($pdo, 'vi', 'vaix');
$itemsQtyExpr = $itemsAnulJoin !== ''
  ? flus_venta_cantidad_vigente_expr_sql('vi.cantidad', 'COALESCE(vaix.cantidad_anulada_total,0)')
  : 'vi.cantidad';

$sql = "
  SELECT
    cs.user_id,
    u.username,
    u.nombre,
    {$shiftExpr} AS turno_key,
    COUNT(*) AS turnos,
    COUNT(DISTINCT DATE(cs.fecha_apertura)) AS dias,
    COALESCE(SUM(TIMESTAMPDIFF(MINUTE, cs.fecha_apertura, {$endExpr})),0) AS minutos,
    COALESCE(SUM(COALESCE(vs.ventas_total, cs.total_ventas, 0)),0) AS ventas_total,
    COALESCE(SUM(COALESCE(vs.tickets, 0)),0) AS tickets,
    COALESCE(SUM(COALESCE(vs.productos, cs.total_productos, 0)),0) AS productos,
    COALESCE(SUM(COALESCE(va.anulaciones, cs.total_anulaciones, 0)),0) AS anulaciones,
    COALESCE(SUM(COALESCE(cs.diferencia, 0)),0) AS diferencia_total,
    COALESCE(SUM(CASE WHEN ABS(COALESCE(cs.diferencia,0)) > 0.00001 THEN 1 ELSE 0 END),0) AS cierres_con_dif,
    COALESCE(SUM(CASE WHEN (cs.fecha_cierre IS NULL OR cs.fecha_cierre = '' OR cs.fecha_cierre = '0000-00-00 00:00:00') THEN 1 ELSE 0 END),0) AS turnos_abiertos
  FROM caja_sesiones cs
  LEFT JOIN users u ON u.id = cs.user_id
  LEFT JOIN (
    SELECT
      v.caja_id,
      COUNT(*) AS tickets,
      COALESCE(SUM({$ventasImporteExpr}),0) AS ventas_total,
      COALESCE(SUM(COALESCE(vit.productos,0)),0) AS productos
    FROM ventas v
    {$ventasAnulJoin}
    LEFT JOIN (
      SELECT
        vi.venta_id,
        COALESCE(SUM({$itemsQtyExpr}),0) AS productos
      FROM venta_items vi
      {$itemsAnulJoin}
      GROUP BY vi.venta_id
    ) vit ON vit.venta_id = v.id
    WHERE v.caja_id IS NOT NULL
      AND (v.estado IS NULL OR UPPER(v.estado) <> 'ANULADA')
    GROUP BY v.caja_id
  ) vs ON vs.caja_id = cs.id
  LEFT JOIN (
    SELECT caja_id, COUNT(*) AS anulaciones
    FROM ventas
    WHERE caja_id IS NOT NULL AND UPPER(estado) = 'ANULADA'
    GROUP BY caja_id
  ) va ON va.caja_id = cs.id
  {$whereSql}
  GROUP BY cs.user_id, turno_key, u.username, u.nombre
  ORDER BY ventas_total DESC, tickets DESC, u.username ASC, turno_key ASC
";

$rows = [];
$errorMsg = null;
try {
  $st = $pdo->prepare($sql);
  cajeros_bind_params($st, $params);
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $errorMsg = $e->getMessage();
}

$usuarios = [];
try {
  $usuarios = $pdo->query("SELECT id, username, nombre FROM users WHERE activo = 1 ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $usuarios = [];
}

$terminales = [];
try {
  if (function_exists('terminal_list')) {
    require_once __DIR__ . '/lib/terminal.php';
    $terminales = terminal_list($pdo);
  }
} catch (Throwable $e) {
  $terminales = [];
}

$summary = [
  'usuarios' => [],
  'turnos' => 0,
  'dias' => 0,
  'minutos' => 0,
  'ventas' => 0.0,
  'tickets' => 0,
  'diferencia' => 0.0,
];
$bestRow = null;

foreach ($rows as &$row) {
  $hours = ((float)($row['minutos'] ?? 0)) / 60;
  $ventas = (float)($row['ventas_total'] ?? 0);
  $tickets = (int)($row['tickets'] ?? 0);
  $productos = (float)($row['productos'] ?? 0);

  $row['horas_calc'] = $hours;
  $row['ticket_promedio_calc'] = $tickets > 0 ? ($ventas / $tickets) : 0.0;
  $row['ventas_hora_calc'] = $hours > 0 ? ($ventas / $hours) : 0.0;
  $row['tickets_hora_calc'] = $hours > 0 ? ($tickets / $hours) : 0.0;
  $row['productos_hora_calc'] = $hours > 0 ? ($productos / $hours) : 0.0;

  $uid = (int)($row['user_id'] ?? 0);
  $summary['usuarios'][$uid] = true;
  $summary['turnos'] += (int)($row['turnos'] ?? 0);
  $summary['dias'] += (int)($row['dias'] ?? 0);
  $summary['minutos'] += (int)($row['minutos'] ?? 0);
  $summary['ventas'] += $ventas;
  $summary['tickets'] += $tickets;
  $summary['diferencia'] += (float)($row['diferencia_total'] ?? 0);

  if ($hours >= 1 && ($bestRow === null || $row['ventas_hora_calc'] > (float)$bestRow['ventas_hora_calc'])) {
    $bestRow = $row;
  }
}
unset($row);

if ((string)($_GET['export'] ?? '') === 'csv' && $errorMsg === null) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="rendimiento_cajeros_' . $desde . '_' . $hasta . '.csv"');
  $out = fopen('php://output', 'w');
  if ($out !== false) {
    fputcsv($out, ['Cajero', 'Turno', 'Dias', 'Horas', 'Ventas', 'Tickets', 'Ticket promedio', 'Ventas hora', 'Tickets hora', 'Productos hora', 'Diferencia', 'Cierres con diferencia', 'Anulaciones']);
    foreach ($rows as $row) {
      $label = trim((string)($row['username'] ?? ''));
      $nombre = trim((string)($row['nombre'] ?? ''));
      if ($nombre !== '') $label .= ' - ' . $nombre;
      fputcsv($out, [
        $label,
        cajeros_shift_label((string)($row['turno_key'] ?? '')),
        (int)($row['dias'] ?? 0),
        number_format((float)$row['horas_calc'], 2, '.', ''),
        number_format((float)($row['ventas_total'] ?? 0), 2, '.', ''),
        (int)($row['tickets'] ?? 0),
        number_format((float)$row['ticket_promedio_calc'], 2, '.', ''),
        number_format((float)$row['ventas_hora_calc'], 2, '.', ''),
        number_format((float)$row['tickets_hora_calc'], 2, '.', ''),
        number_format((float)$row['productos_hora_calc'], 2, '.', ''),
        number_format((float)($row['diferencia_total'] ?? 0), 2, '.', ''),
        (int)($row['cierres_con_dif'] ?? 0),
        (int)($row['anulaciones'] ?? 0),
      ]);
    }
  }
  exit;
}

$activeBadges = [];
if ($usuarioId > 0) $activeBadges[] = 'Usuario filtrado';
if ($terminalId > 0) $activeBadges[] = 'Terminal filtrada';
if ($shift !== '') $activeBadges[] = 'Turno ' . cajeros_shift_label($shift);

$pageTitle = 'Rendimiento de cajeros - FLUS';
$currentSection = 'cajeros_rendimiento';
$bodyClass = trim(($bodyClass ?? '') . ' cajeros-rendimiento-page');
$extraCss = ['assets/css/cajeros_rendimiento.css'];
$breadcrumbs = [
  ['label' => 'Rendimiento de cajeros', 'url' => null],
];

require __DIR__ . '/partials/header.php';
?>

<div class="rend-page">
  <div class="panel rend-shell">
    <header class="module-header rend-header">
      <div class="module-header-main">
        <div class="module-header-hero">
          <span class="module-header-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
              <path d="M4 19V5"/>
              <path d="M8 17V9"/>
              <path d="M12 17V7"/>
              <path d="M16 17v-5"/>
              <path d="M20 17V4"/>
              <path d="M3 19h18"/>
            </svg>
          </span>
          <div class="module-header-copy">
            <span class="module-eyebrow">Analisis administrativo</span>
            <h1 class="page-title module-title">Rendimiento de cajeros</h1>
            <p class="page-sub module-subtitle">Compara dias trabajados, horas, ventas y diferencias por la hora de apertura de cada caja.</p>
          </div>
        </div>
      </div>
      <div class="module-header-actions">
        <a class="btn btn-secondary" href="caja_historial.php">Control de turnos</a>
        <a class="btn btn-primary" href="?<?= h(http_build_query(array_filter($_GET + ['export' => 'csv'], static fn($v) => $v !== null && $v !== ''))) ?>">Exportar CSV</a>
      </div>
    </header>

    <?php if ($errorMsg): ?>
      <div class="rend-alert">No se pudo calcular el informe: <?= h($errorMsg) ?></div>
    <?php endif; ?>

    <section class="rend-kpis" aria-label="Resumen">
      <div class="rend-kpi">
        <span>Cajeros</span>
        <strong><?= number_format(count($summary['usuarios']), 0, ',', '.') ?></strong>
        <small><?= number_format($summary['turnos'], 0, ',', '.') ?> turnos</small>
      </div>
      <div class="rend-kpi">
        <span>Horas</span>
        <strong><?= cajeros_num(((int)$summary['minutos']) / 60, 1) ?></strong>
        <small><?= number_format((int)$summary['dias'], 0, ',', '.') ?> dias trabajados</small>
      </div>
      <div class="rend-kpi">
        <span>Ventas</span>
        <strong><?= money_ar((float)$summary['ventas']) ?></strong>
        <small><?= number_format((int)$summary['tickets'], 0, ',', '.') ?> tickets</small>
      </div>
      <div class="rend-kpi">
        <span>Promedio</span>
        <strong><?= ((int)$summary['tickets'] > 0) ? money_ar(((float)$summary['ventas']) / (int)$summary['tickets']) : money_ar(0) ?></strong>
        <small>Ticket promedio</small>
      </div>
      <div class="rend-kpi">
        <span>Diferencia</span>
        <strong class="<?= ((float)$summary['diferencia'] < 0) ? 'is-bad' : (((float)$summary['diferencia'] > 0) ? 'is-warn' : '') ?>"><?= money_ar((float)$summary['diferencia']) ?></strong>
        <small>Total caja</small>
      </div>
    </section>

    <form class="rend-filters" method="get">
      <select name="usuario" aria-label="Usuario">
        <option value="0">Todos los usuarios</option>
        <?php foreach ($usuarios as $u):
          $uid = (int)($u['id'] ?? 0);
          $label = (string)($u['username'] ?? ('#' . $uid));
          $name = trim((string)($u['nombre'] ?? ''));
          if ($name !== '') $label .= ' - ' . $name;
        ?>
          <option value="<?= $uid ?>" <?= $uid === $usuarioId ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="turno" aria-label="Turno">
        <option value="" <?= $shift === '' ? 'selected' : '' ?>>Todos los turnos</option>
        <option value="manana" <?= $shift === 'manana' ? 'selected' : '' ?>>Ma&ntilde;ana</option>
        <option value="tarde" <?= $shift === 'tarde' ? 'selected' : '' ?>>Tarde</option>
        <option value="noche" <?= $shift === 'noche' ? 'selected' : '' ?>>Noche</option>
      </select>

      <select name="terminal" aria-label="Terminal">
        <option value="0">Todas las terminales</option>
        <?php foreach ($terminales as $terminal):
          $tid = (int)($terminal['id'] ?? 0);
          $name = (string)($terminal['nombre'] ?? ('Caja #' . $tid));
        ?>
          <option value="<?= $tid ?>" <?= $tid === $terminalId ? 'selected' : '' ?>><?= h($name) ?></option>
        <?php endforeach; ?>
      </select>

      <input type="date" name="desde" value="<?= h($desde) ?>" aria-label="Desde">
      <input type="date" name="hasta" value="<?= h($hasta) ?>" aria-label="Hasta">
      <button class="btn btn-primary" type="submit">Aplicar</button>
      <a class="btn btn-secondary" href="cajeros_rendimiento.php">Limpiar</a>
    </form>

    <div class="rend-meta">
      <span><?= h(date('d/m/Y', strtotime($desde))) ?> al <?= h(date('d/m/Y', strtotime($hasta))) ?></span>
      <span>Franjas por apertura: ma&ntilde;ana 06-14, tarde 14-22, noche 22-06</span>
      <?php foreach ($activeBadges as $badge): ?>
        <span class="rend-badge"><?= h($badge) ?></span>
      <?php endforeach; ?>
    </div>

    <?php if ($bestRow): ?>
      <section class="rend-insight" aria-label="Lectura rapida">
        <strong><?= h(trim((string)($bestRow['username'] ?? 'Cajero')) ?: 'Cajero') ?></strong>
        <span>lidera en <?= h(cajeros_shift_label((string)$bestRow['turno_key'])) ?> con <?= money_ar((float)$bestRow['ventas_hora_calc']) ?> por hora.</span>
      </section>
    <?php endif; ?>

    <div class="rend-table-wrap">
      <table class="rend-table">
        <thead>
          <tr>
            <th class="t-left">Cajero</th>
            <th class="t-left">Turno</th>
            <th class="t-right">Dias</th>
            <th class="t-right">Horas</th>
            <th class="t-right">Ventas</th>
            <th class="t-right">Tickets</th>
            <th class="t-right">Ticket prom.</th>
            <th class="t-right">Ventas/h</th>
            <th class="t-right">Prod/h</th>
            <th class="t-right">Dif.</th>
            <th class="t-left">Alertas</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="11" class="rend-empty">No hay turnos para los filtros seleccionados.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($rows as $row):
              $name = trim((string)($row['username'] ?? ''));
              $fullName = trim((string)($row['nombre'] ?? ''));
              $diff = (float)($row['diferencia_total'] ?? 0);
              $hasDiff = (int)($row['cierres_con_dif'] ?? 0) > 0;
              $hasAnnul = (int)($row['anulaciones'] ?? 0) > 0;
              $hasOpen = (int)($row['turnos_abiertos'] ?? 0) > 0;
            ?>
              <tr>
                <td class="rend-person">
                  <strong><?= h($name !== '' ? $name : ('#' . (int)($row['user_id'] ?? 0))) ?></strong>
                  <?php if ($fullName !== ''): ?><span><?= h($fullName) ?></span><?php endif; ?>
                </td>
                <td>
                  <span class="rend-shift"><?= h(cajeros_shift_label((string)($row['turno_key'] ?? ''))) ?></span>
                  <small><?= h(cajeros_shift_hint((string)($row['turno_key'] ?? ''))) ?></small>
                </td>
                <td class="t-right"><?= number_format((int)($row['dias'] ?? 0), 0, ',', '.') ?></td>
                <td class="t-right"><?= cajeros_num((float)$row['horas_calc'], 1) ?></td>
                <td class="t-right mono"><?= money_ar((float)($row['ventas_total'] ?? 0)) ?></td>
                <td class="t-right"><?= number_format((int)($row['tickets'] ?? 0), 0, ',', '.') ?></td>
                <td class="t-right mono"><?= money_ar((float)$row['ticket_promedio_calc']) ?></td>
                <td class="t-right mono"><strong><?= money_ar((float)$row['ventas_hora_calc']) ?></strong></td>
                <td class="t-right"><?= cajeros_num((float)$row['productos_hora_calc'], 1) ?></td>
                <td class="t-right mono <?= $diff < 0 ? 'is-bad' : ($diff > 0 ? 'is-warn' : '') ?>"><?= money_ar($diff) ?></td>
                <td>
                  <div class="rend-tags">
                    <?php if (!$hasDiff && !$hasAnnul && !$hasOpen): ?>
                      <span class="rend-tag is-ok">OK</span>
                    <?php endif; ?>
                    <?php if ($hasDiff): ?><span class="rend-tag is-warn"><?= (int)($row['cierres_con_dif'] ?? 0) ?> dif.</span><?php endif; ?>
                    <?php if ($hasAnnul): ?><span class="rend-tag"><?= (int)($row['anulaciones'] ?? 0) ?> anul.</span><?php endif; ?>
                    <?php if ($hasOpen): ?><span class="rend-tag is-open"><?= (int)($row['turnos_abiertos'] ?? 0) ?> abierto</span><?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

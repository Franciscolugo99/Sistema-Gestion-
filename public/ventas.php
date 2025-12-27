<?php
// public/ventas.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
require_permission('ver_reportes');
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php';

$pdo = getPDO();

/* =========================================================
   LIMPIAR (borra query en server; el localStorage lo borra JS)
========================================================= */
if (isset($_GET['clear'])) {
  header('Location: ventas.php');
  exit;
}

/* =========================================================
   FILTROS
========================================================= */
$allowedMedios   = ['EFECTIVO', 'MP', 'DEBITO', 'CREDITO', 'SIN_ESPECIFICAR'];
$allowedEstados  = ['', 'EMITIDA', 'ANULADA']; // '' = todas

$medio     = strtoupper(trim((string)($_GET['medio'] ?? '')));
$estado    = strtoupper(trim((string)($_GET['estado'] ?? '')));
if (!in_array($estado, $allowedEstados, true)) $estado = '';

$desde     = validDateYmd($_GET['desde'] ?? null);
$hasta     = validDateYmd($_GET['hasta'] ?? null);

$venta_id  = trim((string)($_GET['venta_id'] ?? ''));

// min/max total: aceptar formato AR ($ 1.234,56) o 1234.56
$min_total_raw = (string)($_GET['min_total'] ?? '');
$max_total_raw = (string)($_GET['max_total'] ?? '');
$min_total = ($min_total_raw !== '') ? parse_money_ar($min_total_raw) : null;
$max_total = ($max_total_raw !== '') ? parse_money_ar($max_total_raw) : null;

// si vienen invertidos, los acomodamos (sin romper filtros)
if ($min_total !== null && $max_total !== null && $min_total > $max_total) {
  [$min_total, $max_total] = [$max_total, $min_total];
}

$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, [20, 50, 100], true)) $perPage = 20;

$page = max(1, (int)($_GET['page'] ?? 1));

$export = ((string)($_GET['export'] ?? '') === 'csv');

/* =========================================================
   WHERE dinámico
========================================================= */
$whereParts = ['1=1'];
$params = [];

if ($medio && in_array($medio, $allowedMedios, true)) {
  $whereParts[] = 'v.medio_pago = :medio';
  $params[':medio'] = $medio;
}

if ($estado !== '') {
  $whereParts[] = 'v.estado = :estado';
  $params[':estado'] = $estado;
}

if ($desde) {
  $whereParts[] = 'v.fecha >= :desde';
  $params[':desde'] = $desde . ' 00:00:00';
}

if ($hasta) {
  $whereParts[] = 'v.fecha <= :hasta';
  $params[':hasta'] = $hasta . ' 23:59:59';
}

if ($venta_id !== '' && ctype_digit($venta_id)) {
  $whereParts[] = 'v.id = :venta_id';
  $params[':venta_id'] = (int)$venta_id;
}

if ($min_total !== null) {
  $whereParts[] = 'v.total >= :min_total';
  $params[':min_total'] = (float)$min_total;
}

if ($max_total !== null) {
  $whereParts[] = 'v.total <= :max_total';
  $params[':max_total'] = (float)$max_total;
}

$whereSql = 'WHERE ' . implode(' AND ', $whereParts);

/* =========================================================
   EXPORTAR CSV
========================================================= */
if ($export) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="ventas_' . date('Ymd_His') . '.csv"');

  $out = fopen('php://output', 'w');
  fputcsv($out, ['id','fecha','medio_pago','estado','total','monto_pagado','vuelto','items'], ';');

  $sqlCsv = "
    SELECT v.id, v.fecha, v.medio_pago, v.estado, v.total, v.monto_pagado, v.vuelto,
           (SELECT COUNT(*) FROM venta_items vi WHERE vi.venta_id = v.id) AS items_count
    FROM ventas v
    {$whereSql}
    ORDER BY v.fecha DESC, v.id DESC
  ";
  $st = $pdo->prepare($sqlCsv);
  foreach ($params as $k => $v) $st->bindValue($k, $v);
  $st->execute();

  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, [
      $r['id'],
      $r['fecha'],
      $r['medio_pago'],
      $r['estado'],
      number_format((float)$r['total'],        2, '.', ''),
      number_format((float)$r['monto_pagado'], 2, '.', ''),
      number_format((float)$r['vuelto'],       2, '.', ''),
      $r['items_count'],
    ], ';');
  }

  fclose($out);
  exit;
}

/* =========================================================
   STATS
========================================================= */
$sqlStats = "
  SELECT COUNT(*) AS cnt,
         COALESCE(SUM(v.total),0)        AS sum_total,
         COALESCE(SUM(v.monto_pagado),0) AS sum_pagado,
         COALESCE(AVG(v.total),0)        AS avg_total
  FROM ventas v
  {$whereSql}
";
$stS = $pdo->prepare($sqlStats);
foreach ($params as $k => $v) $stS->bindValue($k, $v);
$stS->execute();
$stats = $stS->fetch(PDO::FETCH_ASSOC) ?: [
  'cnt'        => 0,
  'sum_total'  => 0,
  'sum_pagado' => 0,
  'avg_total'  => 0,
];

$totalRows  = (int)$stats['cnt'];
$totalPages = max(1, (int)ceil($totalRows / $perPage));

if ($page > $totalPages) $page = $totalPages;

$offset  = ($page - 1) * $perPage;
$fromRow = $totalRows ? $offset + 1 : 0;
$toRow   = min($offset + $perPage, $totalRows);

/* =========================================================
   LISTADO
========================================================= */
$sqlList = "
  SELECT v.id, v.fecha, v.medio_pago, v.estado, v.total, v.monto_pagado, v.vuelto,
         (SELECT COUNT(*) FROM venta_items vi WHERE vi.venta_id = v.id) AS items_count,
         (SELECT f.estado FROM facturas f WHERE f.venta_id = v.id ORDER BY f.id DESC LIMIT 1) AS factura_estado
  FROM ventas v
  {$whereSql}
  ORDER BY v.fecha DESC, v.id DESC
  LIMIT :limit OFFSET :offset
";
$st = $pdo->prepare($sqlList);
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$st->bindValue(':offset', $offset,  PDO::PARAM_INT);
$st->execute();
$ventas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* =========================================================
   Promos activas (banner)
========================================================= */
$promosActivas = (int)$pdo->query("
  SELECT COUNT(*) FROM promos
  WHERE activo = 1
    AND (fecha_inicio IS NULL OR fecha_inicio <= CURDATE())
    AND (fecha_fin    IS NULL OR fecha_fin    >= CURDATE())
")->fetchColumn();

/* =========================================================
   HEADER GLOBAL
========================================================= */
$pageTitle      = 'Ventas';
$currentSection = 'ventas';
$extraCss       = ['assets/css/ventas.css?v=1'];

require __DIR__ . '/partials/header.php';
?>

<div class="panel ventas-panel">

  <header class="ventas-top">
    <div class="ventas-top-left">
      <h1 class="ventas-title">Ventas</h1>
      <p class="ventas-sub">
        Mostrando <b><?= (int)$fromRow ?>–<?= (int)$toRow ?></b> de <b><?= (int)$totalRows ?></b>
        • Página <b><?= (int)$page ?></b> / <b><?= (int)$totalPages ?></b>
      </p>
    </div>

    <div class="ventas-top-right">
      <div class="paper-box">
        <span class="paper-label">Papel</span>
        <select id="paperSel">
          <option value="80">80 mm</option>
          <option value="58">58 mm</option>
        </select>
      </div>

      <a class="v-btn v-btn--outline"
         href="<?= h(urlWith(['export' => 'csv', 'page' => 1])) ?>">
        Exportar CSV
      </a>

      <button id="btnScrollTop" class="v-btn v-btn--icon" type="button">↑</button>
    </div>
  </header>

  <?php if ($promosActivas > 0): ?>
    <div class="alert alert-promo">
      💡 <?= (int)$promosActivas ?>
      promoción<?= $promosActivas > 1 ? 'es' : '' ?>
      activa<?= $promosActivas > 1 ? 's' : '' ?> hoy
    </div>
  <?php endif; ?>

  <div class="stats-row ventas-kpis">
    <div class="kpi">
      <div class="kpi-label">Ventas</div>
      <div class="kpi-value"><?= (int)$stats['cnt'] ?></div>
    </div>
    <div class="kpi">
      <div class="kpi-label">Facturación</div>
      <div class="kpi-value"><?= money_ar((float)$stats['sum_total']) ?></div>
    </div>
    <div class="kpi">
      <div class="kpi-label">Ticket promedio</div>
      <div class="kpi-value"><?= money_ar((float)$stats['avg_total']) ?></div>
    </div>
  </div>

  <?php include __DIR__ . '/partials/ventas_filtros.php'; ?>

  <div class="table-wrapper">
    <table class="ventas-table">
      <thead>
        <tr>
          <th>Fecha</th>
          <th class="t-center">ID</th>
          <th class="t-center">Medio</th>
          <th class="t-center">Estado</th>
          <th class="t-right">Total</th>
          <th class="t-right">Pagado</th>
          <th class="t-right">Vuelto</th>
          <th class="t-center">Ítems</th>
          <th class="t-center">Factura</th>
          <th class="t-right">Acciones</th>
        </tr>
      </thead>

      <tbody>
      <?php if (!$ventas): ?>
        <tr>
          <td colspan="10" class="empty-cell">No se encontraron ventas.</td>
        </tr>
      <?php else: ?>
        <?php foreach ($ventas as $v): ?>
          <?php
            $mp = strtoupper((string)($v['medio_pago'] ?? 'SIN_ESPECIFICAR'));
            $medioClass = strtolower(preg_replace('/[^a-z0-9\-_]/i', '', $mp) ?: 'sin_especificar');

            $stt = strtoupper((string)($v['estado'] ?? 'EMITIDA'));
            $estadoClass = ($stt === 'ANULADA') ? 'anulada' : 'emitida';

            $fe = strtoupper((string)($v['factura_estado'] ?? ''));
            if ($fe === '') {
              $factLabel = 'PENDIENTE';
              $factClass = 'pendiente';
            } elseif ($fe === 'ANULADA') {
              $factLabel = 'FACT. ANULADA';
              $factClass = 'anulada';
            } else {
              $factLabel = 'FACTURADA';
              $factClass = 'facturada';
            }
          ?>
          <tr>
            <td class="mono"><?= h((string)$v['fecha']) ?></td>
            <td class="t-center mono">#<?= (int)$v['id'] ?></td>

            <td class="t-center">
              <span class="badge badge-<?= h($medioClass) ?>">
                <?= h($mp) ?>
              </span>
            </td>

            <td class="t-center">
              <span class="badge badge-estado badge-<?= h($estadoClass) ?>">
                <?= h($stt) ?>
              </span>
            </td>

            <td class="t-right"><?= money_ar((float)$v['total']) ?></td>
            <td class="t-right"><?= money_ar((float)$v['monto_pagado']) ?></td>
            <td class="t-right"><?= money_ar((float)$v['vuelto']) ?></td>
            <td class="t-center"><?= (int)$v['items_count'] ?></td>

            <td class="t-center">
              <span class="badge badge-fact badge-<?= h($factClass) ?>">
                <?= h($factLabel) ?>
              </span>
            </td>

            <td class="t-right">
              <div class="row-actions">
                <a class="btn-mini act-view"
                   href="ticket.php?venta_id=<?= (int)$v['id'] ?>&paper=80&preview=1">
                  Ticket
                </a>

                <a class="btn-mini btn-mini-ok act-print"
                   href="ticket.php?venta_id=<?= (int)$v['id'] ?>&paper=80&autoprint=1"
                   target="_blank" rel="noopener">
                  Imprimir
                </a>

                <a class="btn-mini btn-mini-ghost"
                   href="venta_detalle.php?id=<?= (int)$v['id'] ?>">
                  Detalle
                </a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>

    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <div class="pager">
      <a class="pager-btn <?= $page <= 1 ? 'disabled' : '' ?>"
         href="<?= $page <= 1 ? '#' : h(urlWith(['page' => $page - 1])) ?>">
        ← Anterior
      </a>

      <div class="pager-mid">
        Página <?= (int)$page ?>/<?= (int)$totalPages ?>
      </div>

      <a class="pager-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"
         href="<?= $page >= $totalPages ? '#' : h(urlWith(['page' => $page + 1])) ?>">
        Siguiente →
      </a>
    </div>
  <?php endif; ?>

</div>

<script src="assets/js/ventas.js?v=2"></script>

<?php require __DIR__ . '/partials/footer.php'; ?>

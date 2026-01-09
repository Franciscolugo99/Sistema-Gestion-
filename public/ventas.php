<?php
// public/ventas.php - Compatible + split payments (venta_pagos)
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('ver_reportes');

/* =========================
   Helpers
========================= */
function has_table(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
  $st->execute([$table]);
  return (bool)$st->fetchColumn();
}

/* =========================
   Inicialización segura
========================= */
$stats = [
  'cnt' => 0,
  'sum_total' => 0,
  'sum_pagado' => 0,
  'avg_total' => 0,
];

$ventas = [];
$promosActivas = 0;

$totalRows = 0;
$totalPages = 1;
$page = 1;
$perPage = 20;
$offset = 0;
$fromRow = 0;
$toRow = 0;

// Filtros
$allowedMedios = ['EFECTIVO', 'MP', 'DEBITO', 'CREDITO', 'SIN_ESPECIFICAR'];
$allowedEstados = ['', 'EMITIDA', 'ANULADA'];

$medio = '';
$estado = '';
$desde = '';
$hasta = '';
$venta_id = '';
$min_total_raw = '';
$max_total_raw = '';
$min_total = null;
$max_total = null;

/* =========================
   Limpiar filtros
========================= */
if (isset($_GET['clear'])) {
  header('Location: ventas.php');
  exit;
}

/* =========================
   Split payments: detectar tabla
========================= */
$hasVentaPagos = has_table($pdo, 'venta_pagos');

/* =========================
   Procesar filtros
========================= */
$medio  = strtoupper(trim((string)($_GET['medio'] ?? '')));
$estado = strtoupper(trim((string)($_GET['estado'] ?? '')));

if (!in_array($estado, $allowedEstados, true)) $estado = '';

$desde    = validDateYmd($_GET['desde'] ?? null);
$hasta    = validDateYmd($_GET['hasta'] ?? null);
$venta_id = trim((string)($_GET['venta_id'] ?? ''));

$min_total_raw = (string)($_GET['min_total'] ?? '');
$max_total_raw = (string)($_GET['max_total'] ?? '');

if ($min_total_raw !== '') $min_total = parse_money_ar($min_total_raw);
if ($max_total_raw !== '') $max_total = parse_money_ar($max_total_raw);

if ($min_total !== null && $max_total !== null && $min_total > $max_total) {
  [$min_total, $max_total] = [$max_total, $min_total];
}

$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, [20, 50, 100], true)) $perPage = 20;

$page = max(1, (int)($_GET['page'] ?? 1));

$export = ((string)($_GET['export'] ?? '') === 'csv');

/* =========================
   WHERE dinámico (con pagos mixtos)
========================= */
$whereParts = ['1=1'];
$params = [];

// Medio (si existe venta_pagos, filtra por pagos reales y fallback legacy)
if ($medio && in_array($medio, $allowedMedios, true)) {
  if ($hasVentaPagos) {
    if ($medio === 'SIN_ESPECIFICAR') {
      $whereParts[] = "(
        NOT EXISTS (SELECT 1 FROM venta_pagos vp2 WHERE vp2.venta_id = v.id)
        AND (v.medio_pago IS NULL OR v.medio_pago = '' OR v.medio_pago = 'SIN_ESPECIFICAR')
      )";
    } else {
      $whereParts[] = "(
        EXISTS (SELECT 1 FROM venta_pagos vp2 WHERE vp2.venta_id = v.id AND vp2.medio_pago = :medio_vp)
        OR (
          NOT EXISTS (SELECT 1 FROM venta_pagos vp2 WHERE vp2.venta_id = v.id)
          AND v.medio_pago = :medio_legacy
        )
      )";
      $params[':medio_vp'] = $medio;
      $params[':medio_legacy'] = $medio;
    }
    }
}

// Estado (en tu sistema EMITIDA suele ser NULL o 'EMITIDA')
if ($estado === 'EMITIDA') {
  $whereParts[] = "(v.estado IS NULL OR v.estado = 'EMITIDA')";
} elseif ($estado === 'ANULADA') {
  $whereParts[] = "(v.estado = 'ANULADA' OR UPPER(v.estado) LIKE '%ANUL%')";
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

/* =========================
   Join/Select pagos (resumen)
========================= */
$joinPagos = "";
$selectPagos = ", v.monto_pagado AS pagado_calc, NULL AS pagos_cnt, NULL AS pagos_medios";

if ($hasVentaPagos) {
  $joinPagos = "
    LEFT JOIN (
      SELECT
        venta_id,
        SUM(monto)  AS pagado_total,
        COUNT(*)    AS pagos_cnt,
        GROUP_CONCAT(DISTINCT medio_pago ORDER BY medio_pago SEPARATOR '+') AS medios
      FROM venta_pagos
      GROUP BY venta_id
    ) vp ON vp.venta_id = v.id
  ";
  $selectPagos = ", COALESCE(vp.pagado_total, v.monto_pagado) AS pagado_calc,
                  COALESCE(vp.pagos_cnt, 0) AS pagos_cnt,
                  vp.medios AS pagos_medios";
}

/* =========================
   EXPORT CSV (con pagos mixtos)
========================= */
if ($export) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="ventas_' . date('Ymd_His') . '.csv"');

  $out = fopen('php://output', 'w');
  fputcsv($out, ['id','fecha','medio_resumen','estado','total','pagado','vuelto','items','medios_detalle'], ';');

  $sqlCsv = "
    SELECT
      v.id, v.fecha, v.medio_pago, v.estado, v.total, v.vuelto,
      (SELECT COUNT(*) FROM venta_items vi WHERE vi.venta_id = v.id) AS items_count
      {$selectPagos}
    FROM ventas v
    {$joinPagos}
    {$whereSql}
    ORDER BY v.fecha DESC, v.id DESC
  ";

  $st = $pdo->prepare($sqlCsv);
  foreach ($params as $k => $v) $st->bindValue($k, $v);
  $st->execute();

  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $cntPagos = (int)($r['pagos_cnt'] ?? 0);
    $medios   = (string)($r['pagos_medios'] ?? '');
    $medioLegacy = (string)($r['medio_pago'] ?? 'SIN_ESPECIFICAR');

    $medioResumen = $medioLegacy;
    $mediosDetalle = '';

    if ($hasVentaPagos && $cntPagos > 0) {
      $mediosDetalle = $medios;
      $medioResumen = ($cntPagos > 1) ? 'MIXTO' : ($medios !== '' ? $medios : $medioLegacy);
    }

    fputcsv($out, [
      $r['id'],
      $r['fecha'],
      $medioResumen,
      $r['estado'],
      number_format((float)$r['total'], 2, '.', ''),
      number_format((float)($r['pagado_calc'] ?? 0), 2, '.', ''),
      number_format((float)$r['vuelto'], 2, '.', ''),
      (int)($r['items_count'] ?? 0),
      $mediosDetalle,
    ], ';');
  }

  fclose($out);
  exit;
}

/* =========================
   Stats (sum_pagado compatible)
========================= */
$sqlStats = "
  SELECT
    COUNT(*) AS cnt,
    COALESCE(SUM(v.total), 0) AS sum_total,
    " . ($hasVentaPagos
      ? "COALESCE(SUM(COALESCE(vp.pagado_total, v.monto_pagado)), 0) AS sum_pagado,"
      : "COALESCE(SUM(v.monto_pagado), 0) AS sum_pagado,") . "
    COALESCE(AVG(v.total), 0) AS avg_total
  FROM ventas v
  " . ($hasVentaPagos ? $joinPagos : "") . "
  {$whereSql}
";

$st = $pdo->prepare($sqlStats);
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->execute();
$statsResult = $st->fetch(PDO::FETCH_ASSOC);
if ($statsResult) $stats = $statsResult;

/* =========================
   Paginación
========================= */
$totalRows  = (int)($stats['cnt'] ?? 0);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

if ($page > $totalPages) $page = $totalPages;

$offset  = ($page - 1) * $perPage;
$fromRow = $totalRows ? $offset + 1 : 0;
$toRow   = min($offset + $perPage, $totalRows);

/* =========================
   Listado ventas (con resumen de pagos)
========================= */
$sqlList = "
  SELECT
    v.id,
    v.fecha,
    v.medio_pago,
    v.estado,
    v.total,
    v.vuelto,
    (SELECT COUNT(*) FROM venta_items vi WHERE vi.venta_id = v.id) AS items_count,
    (SELECT f.estado FROM facturas f WHERE f.venta_id = v.id ORDER BY f.id DESC LIMIT 1) AS factura_estado
    {$selectPagos}
  FROM ventas v
  {$joinPagos}
  {$whereSql}
  ORDER BY v.fecha DESC, v.id DESC
  LIMIT :limit OFFSET :offset
";

$st = $pdo->prepare($sqlList);
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->bindValue(':limit', $perPage, PDO::PARAM_INT);
$st->bindValue(':offset', $offset, PDO::PARAM_INT);
$st->execute();

$ventas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* =========================
   Promos activas
========================= */
$promosActivas = (int)$pdo->query("
  SELECT COUNT(*) FROM promos
  WHERE activo = 1
    AND (fecha_inicio IS NULL OR fecha_inicio <= CURDATE())
    AND (fecha_fin IS NULL OR fecha_fin >= CURDATE())
")->fetchColumn();

/* =========================
   Header
========================= */
$pageTitle = 'Ventas';
$currentSection = 'ventas';
$extraCss = ['assets/css/ventas.css'];
$extraJs  = ['assets/js/ventas.js'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap ventas-page">

  <div class="panel ventas-panel">

    <div class="ventas-top">
      <div class="ventas-top-left">
        <h1 class="ventas-title">VENTAS</h1>
        <p class="ventas-sub">Gestión y reportes de ventas</p>
      </div>

      <div class="ventas-top-right">
        <div class="paper-box">
          <label for="paperSel">Papel</label>
          <select id="paperSel">
            <option value="80">80mm</option>
            <option value="58">58mm</option>
          </select>
        </div>

        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-primary">
          💾 Exportar CSV
        </a>

        <button id="btnScrollTop" class="btn-icon" title="Volver arriba">↑</button>
      </div>
    </div>

    <div class="ventas-kpis">
      <div class="kpi">
        <div class="kpi-label">Ventas</div>
        <div class="kpi-value"><?= number_format((int)$stats['cnt']) ?></div>
      </div>
      <div class="kpi">
        <div class="kpi-label">Total</div>
        <div class="kpi-value"><?= money($stats['sum_total']) ?></div>
      </div>
      <div class="kpi">
        <div class="kpi-label">Promedio</div>
        <div class="kpi-value"><?= money($stats['avg_total']) ?></div>
      </div>
    </div>

    <div class="ventas-filters">
      <form id="ventasForm" method="get" action="ventas.php">

        <div class="filters-grid">

          <div class="field">
            <label for="venta_id">🔍 Buscar venta</label>
            <input type="text" id="venta_id" name="venta_id" placeholder="ID de venta" value="<?= h($venta_id) ?>">
          </div>

          <div class="field">
            <label for="medio">💳 Medio de pago</label>
            <select id="medio" name="medio">
              <option value="">Todos</option>
              <?php foreach ($allowedMedios as $m): ?>
                <option value="<?= h($m) ?>" <?= ($medio === $m) ? 'selected' : '' ?>><?= h($m) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label for="estado">📋 Estado</label>
            <select id="estado" name="estado">
              <option value="">Todas</option>
              <?php foreach ($allowedEstados as $e): ?>
                <?php if ($e !== ''): ?>
                  <option value="<?= h($e) ?>" <?= ($estado === $e) ? 'selected' : '' ?>><?= h($e) ?></option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field">
            <label for="desde">📅 Desde</label>
            <input type="date" id="desde" name="desde" value="<?= h($desde ?? '') ?>">
          </div>

          <div class="field">
            <label for="hasta">📅 Hasta</label>
            <input type="date" id="hasta" name="hasta" value="<?= h($hasta ?? '') ?>">
          </div>

          <div class="field">
            <label for="min_total">💰 Monto mín.</label>
            <input type="text" id="min_total" name="min_total" placeholder="0,00" value="<?= h($min_total_raw) ?>">
          </div>

          <div class="field">
            <label for="max_total">💰 Monto máx.</label>
            <input type="text" id="max_total" name="max_total" placeholder="999999,00" value="<?= h($max_total_raw) ?>">
          </div>

          <div class="field">
            <label for="per_page">📄 Por página</label>
            <select id="per_page" name="per_page">
              <option value="20" <?= ($perPage === 20) ? 'selected' : '' ?>>20</option>
              <option value="50" <?= ($perPage === 50) ? 'selected' : '' ?>>50</option>
              <option value="100" <?= ($perPage === 100) ? 'selected' : '' ?>>100</option>
            </select>
          </div>

          <div class="field" style="display:flex; gap:8px; align-items:end;">
            <button type="submit" class="btn btn-primary">Filtrar</button>
            <a href="ventas.php?clear=1" id="ventasClear" class="btn btn-ghost">Limpiar</a>
          </div>

        </div>

        <div class="quick">
          <span class="chip" data-range="today">Hoy</span>
          <span class="chip" data-range="7d">Últimos 7 días</span>
          <span class="chip" data-range="30d">Últimos 30 días</span>
        </div>

        <input type="hidden" id="page" name="page" value="<?= (int)$page ?>">
      </form>
    </div>

    <div class="table-wrapper">
      <table class="ventas-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Fecha</th>
            <th>Medio</th>
            <th>Estado</th>
            <th class="t-right">Total</th>
            <th>Items</th>
            <th class="t-center">Acciones</th>
          </tr>
        </thead>
        <tbody>

        <?php if ($ventas): ?>
          <?php foreach ($ventas as $v): ?>
            <?php
              $cntPagos = (int)($v['pagos_cnt'] ?? 0);
              $medios   = (string)($v['pagos_medios'] ?? '');
              $medioLegacy = (string)($v['medio_pago'] ?? 'SIN_ESPECIFICAR');

              if ($hasVentaPagos && $cntPagos > 0) {
                $medioShow = ($cntPagos > 1) ? 'MIXTO' : ($medios !== '' ? $medios : $medioLegacy);
                $medioTitle = ($cntPagos > 1 && $medios) ? $medios : '';
              } else {
                $medioShow  = $medioLegacy ?: 'SIN_ESPECIFICAR';
                $medioTitle = '';
              }

              $mpClass = strtolower(preg_replace('/[^a-z0-9_]+/i', '', $medioShow));
            ?>
            <tr>
              <td class="mono"><?= h((string)$v['id']) ?></td>

              <td>
                <?php
                  $f = $v['fecha'] ?? '';
                  if ($f) {
                    try {
                      $dt = new DateTime((string)$f);
                      echo h($dt->format('d/m/Y H:i'));
                    } catch (Exception $e) {
                      echo h((string)$f);
                    }
                  }
                ?>
              </td>

              <td>
                <span class="badge badge-<?= h($mpClass) ?>" <?= $medioTitle ? 'title="'.h($medioTitle).'"' : '' ?>>
                  <?= h($medioShow) ?>
                </span>
              </td>

              <td>
                <?php if (strtoupper((string)($v['estado'] ?? '')) === 'ANULADA'): ?>
                  <span class="badge-estado badge-anulada">Anulada</span>
                <?php else: ?>
                  <span class="badge-estado badge-emitida">Emitida</span>
                <?php endif; ?>
              </td>

              <td class="t-right mono"><?= money($v['total'] ?? 0) ?></td>

              <td class="t-center"><?= (int)($v['items_count'] ?? 0) ?></td>

              <td class="t-center">
                <div class="row-actions">
                  <a href="ticket.php?id=<?= (int)$v['id'] ?>&paper=80" target="_blank" class="btn-mini btn-mini-ok" title="Ver ticket">📄</a>
                  <a href="venta_detalle.php?id=<?= (int)$v['id'] ?>" class="btn-mini" title="Ver detalle">→</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="7" class="empty-cell">
              No hay ventas que coincidan con los filtros seleccionados.
            </td>
          </tr>
        <?php endif; ?>

        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="pager">
        <div class="pager-info">
          Mostrando <?= $fromRow ?> - <?= $toRow ?> de <?= number_format($totalRows) ?> ventas
        </div>

        <div class="pager-controls">
          <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="pager-btn">← Anterior</a>
          <?php else: ?>
            <span class="pager-btn disabled">← Anterior</span>
          <?php endif; ?>

          <span class="pager-current">Página <?= $page ?> de <?= $totalPages ?></span>

          <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="pager-btn">Siguiente →</a>
          <?php else: ?>
            <span class="pager-btn disabled">Siguiente →</span>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

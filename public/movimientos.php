<?php
// public/movimientos.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();
require_permission('ver_movimientos');
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php';

$pdo = getPDO();

/* =========================================================
   HELPERS
========================================================= */
function urlWith(array $overrides = []): string {
  $q = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null) unset($q[$k]);
    else $q[$k] = $v;
  }
  return 'movimientos.php' . (empty($q) ? '' : '?' . http_build_query($q));
}

function tipoNorm(string $s): string {
  $s = strtoupper(trim($s));
  if ($s === 'ANULACIÓN') return 'ANULACION';
  if ($s === 'DEVOLUCIÓN') return 'DEVOLUCION';
  return $s;
}

/** Signo “esperado” por tipo (para normalizar visualmente) */
function tipoSign(string $tipo): int {
  $t = tipoNorm($tipo);

  // Restan stock
  if (in_array($t, ['VENTA', 'AJUSTE_NEGATIVO'], true)) return -1;

  // Suman stock
  if (in_array($t, ['COMPRA', 'AJUSTE_POSITIVO', 'ANULACION', 'DEVOLUCION'], true)) return 1;

  return 1;
}

/**
 * Devuelve:
 *  [$qtyNorm, $signChar, $pretty, $unit, $dirLabel]
 */
function prettyQtyByTipo(float $cantidad, string $tipo, int $esPesable, ?string $unidadVenta): array {
  $unidadVenta = strtoupper(trim((string)$unidadVenta));
  $signTipo = tipoSign($tipo);

  // normalizamos visualmente (aunque en DB haya quedado positivo en una VENTA)
  $qtyNorm = abs($cantidad) * $signTipo;

  $unitMap = [
    'UNIDAD' => 'u',
    'KG' => 'kg',
    'G'  => 'g',
    'LT' => 'lt',
    'ML' => 'ml',
  ];

  $isPes = ($esPesable === 1) || in_array($unidadVenta, ['KG','G','LT','ML'], true);
  $abs = abs($qtyNorm);

  $pretty = $isPes
    ? number_format($abs, 3, ',', '.')
    : number_format($abs, 0, ',', '.');

  $signChar = ($qtyNorm < 0) ? '−' : '+';
  $dirLabel = ($qtyNorm < 0) ? 'Salida' : 'Entrada';
  $unit = $unitMap[$unidadVenta] ?? ($isPes ? 'kg' : 'u');

  return [$qtyNorm, $signChar, $pretty, $unit, $dirLabel];
}

/* =========================================================
   PARÁMETROS
========================================================= */
$productoId = (int)($_GET['producto_id'] ?? 0);

$tipoRaw = (string)($_GET['tipo'] ?? '');
$tipo    = ($tipoRaw !== '') ? tipoNorm($tipoRaw) : '';

$desdeRaw = (string)($_GET['desde'] ?? '');
$hastaRaw = (string)($_GET['hasta'] ?? '');

$desde = validDateYmd($desdeRaw);
$hasta = validDateYmd($hastaRaw);

$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, [20,50,100], true)) $perPage = 50;

$page   = max(1, (int)($_GET['page'] ?? 1));
$export = ((string)($_GET['export'] ?? '')) === 'csv';

/* =========================================================
   WHERE
========================================================= */
$allowedTipos = ['VENTA','COMPRA','AJUSTE_POSITIVO','AJUSTE_NEGATIVO','ANULACION','DEVOLUCION'];

$whereParts = ['1=1'];
$params = [];

if ($productoId > 0) {
  $whereParts[] = 'm.producto_id = :producto_id';
  $params[':producto_id'] = $productoId;
}

if ($tipo !== '' && in_array($tipo, $allowedTipos, true)) {
  if ($tipo === 'ANULACION') {
    $whereParts[] = "(UPPER(TRIM(m.tipo)) = 'ANULACION' OR UPPER(TRIM(m.tipo)) = 'ANULACIÓN')";
  } elseif ($tipo === 'DEVOLUCION') {
    $whereParts[] = "(UPPER(TRIM(m.tipo)) = 'DEVOLUCION' OR UPPER(TRIM(m.tipo)) = 'DEVOLUCIÓN')";
  } else {
    $whereParts[] = "UPPER(TRIM(m.tipo)) = :tipo";
    $params[':tipo'] = $tipo;
  }
}

if ($desde) {
  $whereParts[] = 'm.fecha >= :desde';
  $params[':desde'] = $desde . ' 00:00:00';
}
if ($hasta) {
  $whereParts[] = 'm.fecha <= :hasta';
  $params[':hasta'] = $hasta . ' 23:59:59';
}

$whereSql = 'WHERE ' . implode(' AND ', $whereParts);

/* =========================================================
   EXPORT CSV
========================================================= */
if ($export) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="movimientos_' . date('Ymd_His') . '.csv"');

  $out = fopen('php://output','w');
  fputcsv($out, ['id','fecha','producto','codigo','tipo','cantidad_raw','cantidad_norm','comentario'], ';');

  $sqlCsv = "
    SELECT
      m.id, m.fecha,
      p.nombre AS producto,
      p.codigo AS codigo,
      p.es_pesable,
      p.unidad_venta,
      UPPER(TRIM(m.tipo)) AS tipo,
      m.cantidad,
      m.comentario
    FROM movimientos_stock m
    JOIN productos p ON p.id = m.producto_id
    {$whereSql}
    ORDER BY m.fecha DESC, m.id DESC
  ";
  $st = $pdo->prepare($sqlCsv);
  $st->execute($params);

  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    [$qtyNorm] = prettyQtyByTipo((float)$r['cantidad'], (string)$r['tipo'], (int)$r['es_pesable'], (string)$r['unidad_venta']);
    fputcsv($out, [
      $r['id'], $r['fecha'], $r['producto'], $r['codigo'],
      $r['tipo'], $r['cantidad'], $qtyNorm, $r['comentario']
    ], ';');
  }
  exit;
}

/* =========================================================
   PAGINACIÓN
========================================================= */
$stCount = $pdo->prepare("SELECT COUNT(*) FROM movimientos_stock m {$whereSql}");
$stCount->execute($params);
$totalRows = (int)$stCount->fetchColumn();

$totalPages = max(1,(int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;

$offset = ($page - 1) * $perPage;

/* =========================================================
   LISTADO
========================================================= */
$sqlList = "
  SELECT
    m.id,
    m.fecha,
    p.nombre AS nombre,
    p.codigo AS codigo,
    p.es_pesable,
    p.unidad_venta,
    UPPER(TRIM(m.tipo)) AS tipo,
    m.cantidad,
    m.referencia_venta_id,
    m.comentario
  FROM movimientos_stock m
  JOIN productos p ON p.id = m.producto_id
  {$whereSql}
  ORDER BY m.fecha DESC, m.id DESC
  LIMIT :limit OFFSET :offset
";
$stList = $pdo->prepare($sqlList);
foreach ($params as $k => $v) $stList->bindValue($k, $v);
$stList->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stList->bindValue(':offset', $offset, PDO::PARAM_INT);
$stList->execute();
$movs = $stList->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* =========================================================
   PRODUCTOS PARA FILTRO
========================================================= */
$productos = $pdo->query("
  SELECT id, codigo, nombre
  FROM productos
  ORDER BY nombre
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* =========================================================
   STATS (global sin filtros)
========================================================= */
$stats = $pdo->query("
  SELECT
    COUNT(*) AS total,
    SUM(UPPER(TRIM(tipo))='VENTA') AS ventas,
    SUM(UPPER(TRIM(tipo))='COMPRA') AS compras,
    SUM(UPPER(TRIM(tipo)) IN ('AJUSTE_POSITIVO','AJUSTE_NEGATIVO')) AS ajustes,
    SUM(UPPER(TRIM(tipo)) IN ('DEVOLUCION','DEVOLUCIÓN')) AS devoluciones
  FROM movimientos_stock
")->fetch() ?: ['total'=>0,'ventas'=>0,'compras'=>0,'ajustes'=>0,'devoluciones'=>0];

/* =========================================================
   HEADER
========================================================= */
$pageTitle = "Movimientos";
$currentSection = "movimientos";
$extraCss = ["assets/css/movimientos.css"];
$extraJs  = ["assets/js/movimientos.js"];

require __DIR__ . "/partials/header.php";
?>

<div class="panel mov-panel">

  <header class="page-header">
    <div>
      <h1 class="page-title">Movimientos</h1>
      <p class="page-sub">Registro de ventas, compras, ajustes y devoluciones.</p>
    </div>

    <div>
      <a href="<?= h(urlWith(['export'=>'csv','page'=>1])) ?>" class="v-btn v-btn--outline">
        Exportar CSV
      </a>
    </div>
  </header>

  <div class="stats-row">
    <div class="stat-card"><div class="stat-label">Total</div><div class="stat-value"><?= (int)$stats['total'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Ventas</div><div class="stat-value"><?= (int)$stats['ventas'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Compras</div><div class="stat-value"><?= (int)$stats['compras'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Ajustes</div><div class="stat-value"><?= (int)$stats['ajustes'] ?></div></div>
    <div class="stat-card"><div class="stat-label">Devoluciones</div><div class="stat-value"><?= (int)$stats['devoluciones'] ?></div></div>
  </div>

  <form method="get" class="filters" id="movFilters">

    <select name="producto_id">
      <option value="">Todos los productos</option>
      <?php foreach ($productos as $p): $pid = (int)$p['id']; ?>
        <option value="<?= $pid ?>" <?= ($productoId === $pid) ? 'selected' : '' ?>>
          <?= h((string)$p['nombre']) ?> (<?= h((string)$p['codigo']) ?>)
        </option>
      <?php endforeach; ?>
    </select>

    <select name="tipo">
      <option value="">Todos los tipos</option>
      <?php foreach ($allowedTipos as $t): ?>
        <option value="<?= h($t) ?>" <?= ($tipo === $t) ? 'selected' : '' ?>><?= h($t) ?></option>
      <?php endforeach; ?>
    </select>

    <input type="date" name="desde" value="<?= h($desde ?? '') ?>">
    <input type="date" name="hasta" value="<?= h($hasta ?? '') ?>">

    <select name="per_page">
      <?php foreach ([20,50,100] as $n): ?>
        <option value="<?= $n ?>" <?= ($perPage === $n) ? 'selected' : '' ?>><?= $n ?></option>
      <?php endforeach; ?>
    </select>

    <button class="v-btn v-btn--primary">Filtrar</button>
    <a href="movimientos.php" class="v-btn v-btn--ghost">Limpiar</a>
  </form>

  <div class="filters-quick">
    <span>Rápido:</span>
    <button type="button" class="chip" data-range="today">Hoy</button>
    <button type="button" class="chip" data-range="7d">7 días</button>
    <button type="button" class="chip" data-range="30d">30 días</button>
  </div>

  <div class="table-wrapper">
    <table class="mov-table">
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Producto</th>
          <th class="t-right">Cantidad</th>
          <th>Tipo</th>
          <th>Ref. venta</th>
          <th>Comentario</th>
        </tr>
      </thead>
      <tbody>

        <?php if (!$movs): ?>
          <tr><td colspan="6" class="empty-cell">No se encontraron movimientos.</td></tr>
        <?php else: foreach ($movs as $m): ?>

          <?php
            $rawQty = (float)($m['cantidad'] ?? 0);
            [$qtyNorm, $signChar, $pretty, $unit, $dirLabel] = prettyQtyByTipo(
              $rawQty,
              (string)($m['tipo'] ?? ''),
              (int)($m['es_pesable'] ?? 0),
              (string)($m['unidad_venta'] ?? 'UNIDAD')
            );
          ?>

          <tr>
            <td class="mono"><?= h((string)$m['fecha']) ?></td>

            <td>
              <?= h((string)$m['nombre']) ?>
              <span class="muted">(<?= h((string)$m['codigo']) ?>)</span>
            </td>

            <td class="t-right">
              <span class="qty <?= ($qtyNorm < 0) ? 'qty-neg' : 'qty-pos' ?>">
                <?= h($signChar . $pretty . ' ' . $unit) ?>
              </span>
              <span class="muted">(<?= h($dirLabel) ?>)</span>
            </td>

            <td><?= h((string)$m['tipo']) ?></td>

            <td>
              <?php if (!empty($m['referencia_venta_id'])): ?>
                <a href="venta_detalle.php?id=<?= (int)$m['referencia_venta_id'] ?>">#<?= (int)$m['referencia_venta_id'] ?></a>
              <?php endif; ?>
            </td>

            <td><?= h((string)$m['comentario']) ?></td>
          </tr>

        <?php endforeach; endif; ?>

      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <div class="pager">
      <a class="pager-btn <?= $page <= 1 ? 'disabled' : '' ?>"
         href="<?= $page <= 1 ? '#' : h(urlWith(['page'=>$page-1])) ?>">←</a>

      <div class="pager-mid">Página <?= $page ?> / <?= $totalPages ?></div>

      <a class="pager-btn <?= $page >= $totalPages ? 'disabled' : '' ?>"
         href="<?= $page >= $totalPages ? '#' : h(urlWith(['page'=>$page+1])) ?>">→</a>
    </div>
  <?php endif; ?>

</div>

<?php require __DIR__ . "/partials/footer.php"; ?>

<?php
// public/dashboard.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php';

$pdo = getPDO();

/* =========================
   Helpers locales (por si no existen)
========================= */
if (!function_exists('h')) {
  function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}
if (!function_exists('money_ar')) {
  function money_ar($n): string {
    return '$' . number_format((float)$n, 2, ',', '.');
  }
}
function validDateYmd(?string $s): ?string {
  if (!$s) return null;
  $d = DateTime::createFromFormat('Y-m-d', $s);
  return ($d && $d->format('Y-m-d') === $s) ? $s : null;
}
function format_qty(float $n): string {
  // hasta 3 decimales, sin ceros molestos
  $s = number_format($n, 3, ',', '.');
  $s = rtrim($s, '0');
  $s = rtrim($s, ',');
  return $s === '' ? '0' : $s;
}

/* =========================
   RANGO
========================= */
$today = (new DateTime('today'))->format('Y-m-d');

// Default: últimos 30 días (incluye hoy)
$defaultFrom = (new DateTime('today'))->modify('-29 days')->format('Y-m-d');
$defaultTo   = $today;

$from = validDateYmd($_GET['from'] ?? null) ?? $defaultFrom;
$to   = validDateYmd($_GET['to'] ?? null)   ?? $defaultTo;

// si vienen invertidas, las damos vuelta
if ($from > $to) {
  $tmp  = $from;
  $from = $to;
  $to   = $tmp;
}

/* =========================
   LÍMITE DE RANGO (365 días)
========================= */
$maxDays      = 365;
$toastMessage = '';
$toastFrom    = '';
$toastTo      = '';

$fromDT   = new DateTime($from);
$toDT     = new DateTime($to);
$diffDays = (int)$fromDT->diff($toDT)->format('%a'); // sin incluir ambos extremos

if ($diffDays > ($maxDays - 1)) {
  // Ajusta "from" para que el rango sea de 365 días inclusive
  $fromDT = (clone $toDT)->modify('-' . ($maxDays - 1) . ' days');
  $from   = $fromDT->format('Y-m-d');

  $toastMessage = "Rango máximo permitido: {$maxDays} días. Se ajustó automáticamente.";
  $toastFrom    = $from;
  $toastTo      = $to;
}

// Para SQL: [from 00:00:00, to+1day 00:00:00)
$fromStart = $from . " 00:00:00";
$toEnd     = (new DateTime($to))->modify('+1 day')->format('Y-m-d') . " 00:00:00";

/* =========================
   KPIs RANGO
========================= */

// Movimientos en rango
$stmt = $pdo->prepare("
  SELECT COUNT(*)
  FROM movimientos_stock
  WHERE fecha >= :fromStart AND fecha < :toEnd
");
$stmt->execute([':fromStart' => $fromStart, ':toEnd' => $toEnd]);
$movimientosRango = (int)$stmt->fetchColumn();

// Ventas (tickets) en rango (index-friendly)
$stmt = $pdo->prepare("
  SELECT COUNT(*)
  FROM ventas
  WHERE fecha >= :fromStart AND fecha < :toEnd
");
$stmt->execute([':fromStart' => $fromStart, ':toEnd' => $toEnd]);
$ventasRango = (int)$stmt->fetchColumn();

// Facturación en rango
$stmt = $pdo->prepare("
  SELECT COALESCE(SUM(total),0)
  FROM ventas
  WHERE fecha >= :fromStart AND fecha < :toEnd
");
$stmt->execute([':fromStart' => $fromStart, ':toEnd' => $toEnd]);
$facturacionRango = (float)$stmt->fetchColumn();

// Unidades vendidas (pueden ser decimales por pesables)
$stmt = $pdo->prepare("
  SELECT COALESCE(SUM(vi.cantidad),0)
  FROM venta_items vi
  JOIN ventas v ON v.id = vi.venta_id
  WHERE v.fecha >= :fromStart AND v.fecha < :toEnd
");
$stmt->execute([':fromStart' => $fromStart, ':toEnd' => $toEnd]);
$unidadesVendidasRango = (float)$stmt->fetchColumn();

// Ticket promedio
$ticketPromedio = ($ventasRango > 0) ? ($facturacionRango / $ventasRango) : 0.0;

/* =========================
   COMPARACIÓN vs PERÍODO ANTERIOR
========================= */
$rangeDays = $diffDays + 1;

// período anterior: termina el día anterior a $from
$prevToDT   = (new DateTime($from))->modify('-1 day');
$prevFromDT = (clone $prevToDT)->modify('-' . ($rangeDays - 1) . ' days');

$prevFrom = $prevFromDT->format('Y-m-d');
$prevTo   = $prevToDT->format('Y-m-d');

$prevFromStart = $prevFrom . " 00:00:00";
$prevToEnd     = (new DateTime($prevTo))->modify('+1 day')->format('Y-m-d') . " 00:00:00";

// Ventas previas
$stmt = $pdo->prepare("
  SELECT COUNT(*)
  FROM ventas
  WHERE fecha >= :fromStart AND fecha < :toEnd
");
$stmt->execute([':fromStart' => $prevFromStart, ':toEnd' => $prevToEnd]);
$ventasPrev = (int)$stmt->fetchColumn();

// Facturación previa
$stmt = $pdo->prepare("
  SELECT COALESCE(SUM(total),0)
  FROM ventas
  WHERE fecha >= :fromStart AND fecha < :toEnd
");
$stmt->execute([':fromStart' => $prevFromStart, ':toEnd' => $prevToEnd]);
$facturacionPrev = (float)$stmt->fetchColumn();

$ticketPrev = ($ventasPrev > 0) ? ($facturacionPrev / $ventasPrev) : 0.0;

function kpiDeltaBadge(float $curr, float $prev): array {
  if ($prev == 0.0) {
    if ($curr == 0.0) return ['class' => 'kpi-flat', 'text' => '0%', 'title' => 'Sin cambios vs período anterior'];
    return ['class' => 'kpi-new', 'text' => 'Nuevo', 'title' => 'No hubo datos en el período anterior'];
  }
  $pct = (($curr - $prev) / $prev) * 100.0;
  if (abs($pct) < 0.05) return ['class' => 'kpi-flat', 'text' => '0%', 'title' => 'Sin cambios vs período anterior'];

  $arrow = ($pct > 0) ? '▲' : '▼';
  $cls   = ($pct > 0) ? 'kpi-up' : 'kpi-down';
  $txt   = $arrow . ' ' . number_format(abs($pct), 1, ',', '.') . '%';
  return ['class' => $cls, 'text' => $txt, 'title' => 'Vs período anterior'];
}

$ventasDelta = kpiDeltaBadge((float)$ventasRango, (float)$ventasPrev);
$factDelta   = kpiDeltaBadge($facturacionRango, $facturacionPrev);
$ticketDelta = kpiDeltaBadge($ticketPromedio, $ticketPrev);

$prevLabel = (new DateTime($prevFrom))->format('d/m/Y') . ' → ' . (new DateTime($prevTo))->format('d/m/Y');

/* =========================
   KPIs STOCK (sin filtro)
========================= */
$totalProductos = (int)$pdo->query("SELECT COUNT(*) FROM productos")->fetchColumn();

$stockOk = (int)$pdo->query("
  SELECT COUNT(*)
  FROM productos
  WHERE stock > stock_minimo AND stock > 0 AND activo = 1
")->fetchColumn();

$stockBajo = (int)$pdo->query("
  SELECT COUNT(*)
  FROM productos
  WHERE stock > 0 AND stock <= stock_minimo AND activo = 1
")->fetchColumn();

$sinStock = (int)$pdo->query("
  SELECT COUNT(*)
  FROM productos
  WHERE stock <= 0 AND activo = 1
")->fetchColumn();

$inactivos = (int)$pdo->query("
  SELECT COUNT(*)
  FROM productos
  WHERE activo = 0
")->fetchColumn();

/* =========================
   KPIs MOVIMIENTOS (hoy) - index-friendly
========================= */
$hoyStart = (new DateTime('today'))->format('Y-m-d') . " 00:00:00";
$maniana  = (new DateTime('today'))->modify('+1 day')->format('Y-m-d') . " 00:00:00";

$stmt = $pdo->prepare("SELECT COUNT(*) FROM movimientos_stock WHERE fecha >= :a AND fecha < :b");
$stmt->execute([':a' => $hoyStart, ':b' => $maniana]);
$totalMovimientosHoy = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM movimientos_stock WHERE tipo='VENTA' AND fecha >= :a AND fecha < :b");
$stmt->execute([':a' => $hoyStart, ':b' => $maniana]);
$ventasHoy = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM movimientos_stock WHERE tipo LIKE 'AJUSTE%' AND fecha >= :a AND fecha < :b");
$stmt->execute([':a' => $hoyStart, ':b' => $maniana]);
$ajustesHoy = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM movimientos_stock WHERE tipo='DEVOLUCION' AND fecha >= :a AND fecha < :b");
$stmt->execute([':a' => $hoyStart, ':b' => $maniana]);
$devolucionesHoy = (int)$stmt->fetchColumn();

/* =========================
   CHART 1: Ventas por día (rango)
========================= */
$ventasLabels = [];
$ventasData   = [];

$stmt = $pdo->prepare("
  SELECT DATE(fecha) AS dia, COUNT(*) AS total
  FROM ventas
  WHERE fecha >= :fromStart AND fecha < :toEnd
  GROUP BY DATE(fecha)
  ORDER BY dia
");
$stmt->execute([':fromStart' => $fromStart, ':toEnd' => $toEnd]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ventasMap = [];
foreach ($rows as $r) {
  $ventasMap[(string)$r['dia']] = (int)$r['total'];
}

$periodo = new DatePeriod(
  new DateTime($from),
  new DateInterval('P1D'),
  (new DateTime($to))->modify('+1 day')
);

foreach ($periodo as $d) {
  $dia            = $d->format('Y-m-d');
  $ventasLabels[] = $dia;
  $ventasData[]   = $ventasMap[$dia] ?? 0;
}

/* =========================
   CHART 2: Top productos (rango)
========================= */
$topProductosLabels = [];
$topProductosData   = [];

$stmt = $pdo->prepare("
  SELECT p.nombre AS producto, SUM(vi.cantidad) AS total
  FROM venta_items vi
  JOIN ventas v    ON v.id = vi.venta_id
  JOIN productos p ON p.id = vi.producto_id
  WHERE v.fecha >= :fromStart AND v.fecha < :toEnd
  GROUP BY p.id, p.nombre
  ORDER BY total DESC
  LIMIT 5
");
$stmt->execute([':fromStart' => $fromStart, ':toEnd' => $toEnd]);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $topProductosLabels[] = (string)$row['producto'];
  $topProductosData[]   = (float)$row['total'];
}

/* =========================
   CHART 3: Movimientos por tipo (rango)
========================= */
$tiposLabels = [];
$tiposData   = [];

$stmt = $pdo->prepare("
  SELECT tipo, COUNT(*) AS total
  FROM movimientos_stock
  WHERE fecha >= :fromStart AND fecha < :toEnd
  GROUP BY tipo
  ORDER BY total DESC
");
$stmt->execute([':fromStart' => $fromStart, ':toEnd' => $toEnd]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $tiposLabels[] = (string)$row['tipo'];
  $tiposData[]   = (int)$row['total'];
}

/* =========================
   HEADER GLOBAL
========================= */
$pageTitle      = 'Dashboard';
$currentSection = 'dashboard';
$extraCss       = ['assets/css/dashboard.css?v=1'];

require __DIR__ . '/partials/header.php';
?>

<!-- TOAST -->
<div
  id="dashToast"
  class="flus-toast"
  style="display:none;"
  data-message="<?= h($toastMessage) ?>"
  data-from="<?= h($toastFrom) ?>"
  data-to="<?= h($toastTo) ?>"
></div>

<div class="page-wrap">
  <div class="panel dashboard-panel">

    <div class="dash-header">
      <div>
        <h1 class="dash-title">Dashboard</h1>
        <p class="dash-sub">Resumen general de ventas, stock y movimientos.</p>
      </div>

      <div class="dash-header-meta">
        <span>Hoy: <?= date('d/m/Y'); ?></span>
      </div>
    </div>

    <form id="dashFilters" class="dash-filters" method="get" action="dashboard.php">
      <div class="dash-presets">
        <button type="button" class="dash-chip" data-preset="today">Hoy</button>
        <button type="button" class="dash-chip" data-preset="7d">7d</button>
        <button type="button" class="dash-chip" data-preset="30d">30d</button>
        <button type="button" class="dash-chip" data-preset="month">Este mes</button>
      </div>

      <div class="dash-range">
        <div class="dash-range-controls">
          <label>
            <span>Desde</span>
            <input type="date" id="dashFrom" name="from" value="<?= h($from) ?>" />
          </label>

          <label>
            <span>Hasta</span>
            <input type="date" id="dashTo" name="to" value="<?= h($to) ?>" />
          </label>

          <button type="submit" class="dash-apply">Aplicar</button>

          <div class="dash-export-group">
            <a class="dash-export" href="dashboard_export.php?type=movimientos&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">
              Exportar Movimientos
            </a>
            <a class="dash-export" href="dashboard_export.php?type=kpis&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">
              Exportar KPIs
            </a>
            <a class="dash-export" href="dashboard_export.php?type=top_productos&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>">
              Exportar Top productos
            </a>
          </div>
        </div>

        <div class="dash-range-hint">
          Rango actual:
          <strong><?= (new DateTime($from))->format('d/m/Y'); ?></strong>
          →
          <strong><?= (new DateTime($to))->format('d/m/Y'); ?></strong>
        </div>
      </div>
    </form>

    <!-- KPIs RANGO -->
    <div class="dash-kpi-row">
      <div class="stat-card">
        <div class="stat-label kpi-label">
          Movimientos (rango)
          <button type="button" class="kpi-help" aria-label="Ayuda"
            data-help="Total de registros de movimientos_stock en el rango (ventas + ajustes + devoluciones, etc.).">?</button>
        </div>
        <div class="stat-value"><?= (int)$movimientosRango ?></div>
      </div>

      <div class="stat-card stat-ok">
        <div class="stat-label kpi-label">
          Ventas (rango)
          <button type="button" class="kpi-help" aria-label="Ayuda"
            data-help="Cantidad de tickets/ventas en el rango. Comparado contra el período anterior (<?= h($prevLabel) ?>).">?</button>
        </div>
        <div class="stat-value"><?= (int)$ventasRango ?></div>
        <div class="kpi-delta <?= h($ventasDelta['class']) ?>" title="<?= h($ventasDelta['title']) ?>">
          <?= h($ventasDelta['text']) ?>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-label kpi-label">
          Unidades vendidas
          <button type="button" class="kpi-help" aria-label="Ayuda"
            data-help="Suma de cantidades vendidas (venta_items) dentro del rango. Puede incluir decimales (pesables).">?</button>
        </div>
        <div class="stat-value"><?= h(format_qty($unidadesVendidasRango)) ?></div>
      </div>

      <div class="stat-card">
        <div class="stat-label kpi-label">
          Facturación (rango)
          <button type="button" class="kpi-help" aria-label="Ayuda"
            data-help="Suma del total de cada venta (ventas.total) en el rango. Comparado con: <?= h($prevLabel) ?>.">?</button>
        </div>
        <div class="stat-value">$ <?= number_format($facturacionRango, 0, ',', '.') ?></div>
        <div class="kpi-delta <?= h($factDelta['class']) ?>" title="<?= h($factDelta['title']) ?>">
          <?= h($factDelta['text']) ?>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-label kpi-label">
          Ticket promedio
          <button type="button" class="kpi-help" aria-label="Ayuda"
            data-help="Facturación del rango ÷ Ventas del rango. Comparado con: <?= h($prevLabel) ?>.">?</button>
        </div>
        <div class="stat-value">$ <?= number_format($ticketPromedio, 0, ',', '.') ?></div>
        <div class="kpi-delta <?= h($ticketDelta['class']) ?>" title="<?= h($ticketDelta['title']) ?>">
          <?= h($ticketDelta['text']) ?>
        </div>
      </div>
    </div>

    <!-- KPIs STOCK -->
    <div class="dash-kpi-row">
      <div class="stat-card">
        <div class="stat-label kpi-label">
          Total productos
          <button type="button" class="kpi-help" aria-label="Ayuda"
            data-help="Cantidad total de productos en el sistema.">?</button>
        </div>
        <div class="stat-value"><?= (int)$totalProductos; ?></div>
      </div>

      <div class="stat-card stat-ok">
        <div class="stat-label kpi-label">En stock ok</div>
        <div class="stat-value"><?= (int)$stockOk; ?></div>
      </div>

      <div class="stat-card stat-bajo">
        <div class="stat-label kpi-label">Stock bajo</div>
        <div class="stat-value"><?= (int)$stockBajo; ?></div>
      </div>

      <div class="stat-card stat-sin">
        <div class="stat-label kpi-label">Sin stock</div>
        <div class="stat-value"><?= (int)$sinStock; ?></div>
      </div>

      <div class="stat-card stat-inactivo hide-on-small">
        <div class="stat-label kpi-label">Inactivos</div>
        <div class="stat-value"><?= (int)$inactivos; ?></div>
      </div>
    </div>

    <!-- KPIs HOY -->
    <div class="dash-kpi-row">
      <div class="stat-card">
        <div class="stat-label kpi-label">
          Movimientos hoy
          <button type="button" class="kpi-help" aria-label="Ayuda"
            data-help="Total de movimientos_stock registrados hoy.">?</button>
        </div>
        <div class="stat-value"><?= (int)$totalMovimientosHoy; ?></div>
      </div>

      <div class="stat-card stat-ok">
        <div class="stat-label kpi-label">
          Ventas hoy
          <button type="button" class="kpi-help" aria-label="Ayuda"
            data-help="Cantidad de movimientos tipo VENTA registrados hoy.">?</button>
        </div>
        <div class="stat-value"><?= (int)$ventasHoy; ?></div>
      </div>

      <div class="stat-card stat-bajo">
        <div class="stat-label kpi-label">
          Ajustes hoy
          <button type="button" class="kpi-help" aria-label="Ayuda"
            data-help="Cantidad de movimientos tipo AJUSTE realizados hoy.">?</button>
        </div>
        <div class="stat-value"><?= (int)$ajustesHoy; ?></div>
      </div>

      <div class="stat-card stat-sin">
        <div class="stat-label kpi-label">
          Devoluciones hoy
          <button type="button" class="kpi-help" aria-label="Ayuda"
            data-help="Cantidad de devoluciones registradas hoy.">?</button>
        </div>
        <div class="stat-value"><?= (int)$devolucionesHoy; ?></div>
      </div>
    </div>

    <!-- CHARTS -->
    <div class="dash-grid">
      <div class="dash-card">
        <div class="dash-card-header">
          <h2>Ventas por día</h2>
          <span class="dash-card-sub">Según rango seleccionado</span>
        </div>

        <div class="chart-wrap">
          <canvas id="chartVentas7d"></canvas>
          <div id="noVentasMsg" class="chart-empty" style="display:none;">
            No hubo ventas registradas en el rango seleccionado.
          </div>
        </div>
      </div>

      <div class="dash-card">
        <div class="dash-card-header">
          <h2>Top productos</h2>
          <span class="dash-card-sub">Según rango seleccionado</span>
        </div>

        <div class="chart-wrap">
          <canvas id="chartTopProductos"></canvas>
          <div id="noTopMsg" class="chart-empty" style="display:none;">
            No hay ventas para armar el top en este rango.
          </div>
        </div>
      </div>

      <div class="dash-card dash-card-wide">
        <div class="dash-card-header">
          <h2>Movimientos por tipo</h2>
          <span class="dash-card-sub">Según rango seleccionado</span>
        </div>

        <div class="chart-wrap chart-wrap-wide">
          <canvas id="chartTipos"></canvas>
          <div id="noTiposMsg" class="chart-empty" style="display:none;">
            No hay movimientos registrados en este rango.
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<script>
  window.dashboardData = {
    from: "<?= h($from) ?>",
    to:   "<?= h($to) ?>",
    ventasLabels:  <?= json_encode($ventasLabels, JSON_UNESCAPED_UNICODE) ?>,
    ventasData:    <?= json_encode($ventasData, JSON_UNESCAPED_UNICODE) ?>,
    topProdLabels: <?= json_encode($topProductosLabels, JSON_UNESCAPED_UNICODE) ?>,
    topProdData:   <?= json_encode($topProductosData, JSON_UNESCAPED_UNICODE) ?>,
    tiposLabels:   <?= json_encode($tiposLabels, JSON_UNESCAPED_UNICODE) ?>,
    tiposData:     <?= json_encode($tiposData, JSON_UNESCAPED_UNICODE) ?>
  };
</script>

<!-- Chart.js + tu JS -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="assets/js/dashboard.js?v=1" defer></script>

<?php require __DIR__ . '/partials/footer.php'; ?>

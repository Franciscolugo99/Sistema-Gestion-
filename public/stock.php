<?php
// public/stock.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/helpers.php';

$pdo = getPDO();

/* ============================
   CONFIG PÁGINA
============================ */
$pageTitle      = "Stock";
$currentSection = "stock";
$extraCss       = ["assets/css/stock.css"];
$extraJs        = ["assets/js/stock.js"];

/* ============================
   FILTROS
============================ */
$buscar = trim((string)($_GET['q'] ?? ''));
$estado = (string)($_GET['estado'] ?? '');

/* ============================
   PAGINACIÓN PRINCIPAL
============================ */
$perPageOptions = [20, 50, 100];
$perPage = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
if (!in_array($perPage, $perPageOptions, true)) $perPage = 20;

$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

/* ============================
   KPIs (sobre TODOS los productos)
============================ */
$kpiSql = "
  SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN activo = 0 THEN 1 ELSE 0 END) AS inactivos,
    SUM(CASE WHEN activo = 1 AND stock <= 0 THEN 1 ELSE 0 END) AS sin_stock,
    SUM(CASE WHEN activo = 1 AND stock > 0 AND stock <= stock_minimo THEN 1 ELSE 0 END) AS bajo_stock,
    SUM(CASE WHEN activo = 1 AND stock > stock_minimo AND stock > 0 THEN 1 ELSE 0 END) AS ok
  FROM productos
";
$kpi = $pdo->query($kpiSql)->fetch(PDO::FETCH_ASSOC) ?: [];

$totalProductos = (int)($kpi['total'] ?? 0);
$inactivos      = (int)($kpi['inactivos'] ?? 0);
$sinStock       = (int)($kpi['sin_stock'] ?? 0);
$bajoStock      = (int)($kpi['bajo_stock'] ?? 0);
$ok             = (int)($kpi['ok'] ?? 0);

/* ============================
   WHERE DINÁMICO (tabla principal)
============================ */
$where  = [];
$params = [];

if ($buscar !== '') {
  $where[] = "(codigo LIKE :q OR nombre LIKE :q OR categoria LIKE :q OR marca LIKE :q OR proveedor LIKE :q)";
  $params[':q'] = '%' . $buscar . '%';
}

if ($estado !== '') {
  switch ($estado) {
    case 'inactivo':
      $where[] = "activo = 0";
      break;
    case 'sin':
      $where[] = "activo = 1 AND stock <= 0";
      break;
    case 'bajo':
      $where[] = "activo = 1 AND stock > 0 AND stock <= stock_minimo";
      break;
    case 'ok':
      $where[] = "activo = 1 AND stock > stock_minimo AND stock > 0";
      break;
    default:
      break;
  }
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

/* ============================
   TOTAL FILTRADOS + PÁGINAS
============================ */
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM productos $whereSql");
$stmtCount->execute($params);
$totalFiltrados = (int)$stmtCount->fetchColumn();

$totalPages = max(1, (int)ceil($totalFiltrados / $perPage));
if ($page > $totalPages) {
  $page = $totalPages;
  $offset = ($page - 1) * $perPage;
}

/* ============================
   LISTADO PRINCIPAL (PAGINADO)
============================ */
$sqlList = "
  SELECT
    id, codigo, nombre, categoria, marca, proveedor,
    stock, stock_minimo, es_pesable, activo,
    CASE
      WHEN activo = 0 THEN 'inactivo'
      WHEN stock <= 0 THEN 'sin'
      WHEN stock > 0 AND stock <= stock_minimo THEN 'bajo'
      ELSE 'ok'
    END AS estado_stock
  FROM productos
  $whereSql
  ORDER BY nombre ASC
  LIMIT :lim OFFSET :off
";
$stmtList = $pdo->prepare($sqlList);
foreach ($params as $k => $v) $stmtList->bindValue($k, $v, PDO::PARAM_STR);
$stmtList->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmtList->bindValue(':off', $offset, PDO::PARAM_INT);
$stmtList->execute();
$productosPage = $stmtList->fetchAll(PDO::FETCH_ASSOC);

/* ============================
   REPONER (siempre: bajo o sin, activo=1)
============================ */
$reponerPerPage = $perPage;
$reponerPage    = max(1, (int)($_GET['reponer_page'] ?? 1));
$reponerOffset  = ($reponerPage - 1) * $reponerPerPage;

$stmtRepCount = $pdo->query("
  SELECT COUNT(*)
  FROM productos
  WHERE activo = 1 AND (stock <= 0 OR (stock > 0 AND stock <= stock_minimo))
");
$reponerTotal = (int)$stmtRepCount->fetchColumn();

$reponerPages = max(1, (int)ceil($reponerTotal / $reponerPerPage));
if ($reponerPage > $reponerPages) {
  $reponerPage = $reponerPages;
  $reponerOffset = ($reponerPage - 1) * $reponerPerPage;
}

/* ============================
   EXPORT CSV (reponer)
============================ */
if (($_GET['export'] ?? '') === 'reponer') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="reponer.csv"');

  $out = fopen('php://output', 'w');
  fputcsv($out, ['codigo','nombre','categoria','marca','proveedor','stock','stock_minimo','sugerido','unidad'], ';');

  $stmtRepAll = $pdo->query("
    SELECT codigo, nombre, categoria, marca, proveedor, stock, stock_minimo, es_pesable, unidad_venta
    FROM productos
    WHERE activo = 1 AND (stock <= 0 OR (stock > 0 AND stock <= stock_minimo))
    ORDER BY nombre ASC
  ");

  while ($p = $stmtRepAll->fetch(PDO::FETCH_ASSOC)) {
    $stockActual = (float)($p['stock'] ?? 0);
    $minimo      = (float)($p['stock_minimo'] ?? 0);
    $esPes       = is_pesable_row($p);

    if ($stockActual <= 0) {
      $paraPedir = max(($esPes ? 0.001 : 1), $minimo * 2);
    } else {
      $paraPedir = ($minimo * 2) - $stockActual;
      $minPedido = ($esPes ? 0.001 : 1);
      if ($paraPedir < $minPedido) $paraPedir = $minPedido;
    }
    $p['para_pedir'] = $paraPedir;

    $unidad = (string)($p['unidad_venta'] ?? '');
    if ($unidad === '') $unidad = $esPes ? 'KG' : 'UNID';

    fputcsv($out, [
      (string)($p['codigo'] ?? ''),
      (string)($p['nombre'] ?? ''),
      (string)($p['categoria'] ?? ''),
      (string)($p['marca'] ?? ''),
      (string)($p['proveedor'] ?? ''),
      format_qty_field($p, 'stock'),
      format_qty_field($p, 'stock_minimo'),
      format_qty_field($p, 'para_pedir'),
      $unidad,
    ], ';');
  }

  fclose($out);
  exit;
}

/* ============================
   LISTADO REPONER (PAGINADO)
============================ */
$sqlRep = "
  SELECT
    id, codigo, nombre, categoria, marca, proveedor,
    stock, stock_minimo, es_pesable, unidad_venta, activo,
    CASE WHEN stock <= 0 THEN 'sin' ELSE 'bajo' END AS estado_stock
  FROM productos
  WHERE activo = 1 AND (stock <= 0 OR (stock > 0 AND stock <= stock_minimo))
  ORDER BY nombre ASC
  LIMIT :lim OFFSET :off
";
$stmtRep = $pdo->prepare($sqlRep);
$stmtRep->bindValue(':lim', $reponerPerPage, PDO::PARAM_INT);
$stmtRep->bindValue(':off', $reponerOffset, PDO::PARAM_INT);
$stmtRep->execute();
$reponerPageData = $stmtRep->fetchAll(PDO::FETCH_ASSOC);

// calcular sugerido en PHP
foreach ($reponerPageData as &$p) {
  $stockActual = (float)($p['stock'] ?? 0);
  $minimo      = (float)($p['stock_minimo'] ?? 0);
  $esPes       = is_pesable_row($p);

  if ($stockActual <= 0) {
    $paraPedir = max(($esPes ? 0.001 : 1), $minimo * 2);
  } else {
    $paraPedir = ($minimo * 2) - $stockActual;
    $minPedido = ($esPes ? 0.001 : 1);
    if ($paraPedir < $minPedido) $paraPedir = $minPedido;
  }
  $p['para_pedir'] = $paraPedir;
}
unset($p);

/* ============================
   HEADER
============================ */
require __DIR__ . "/partials/header.php";
?>

<div class="panel">

  <header class="page-header">
    <div>
      <h1 class="page-title">Stock</h1>
      <p class="page-sub">Control del stock actual y productos a reponer.</p>
    </div>
  </header>

  <div class="stats-row">
    <div class="stat-card"><div class="stat-label">Total</div><div class="stat-value"><?= (int)$totalProductos ?></div></div>
    <div class="stat-card stat-ok"><div class="stat-label">OK</div><div class="stat-value"><?= (int)$ok ?></div></div>
    <div class="stat-card stat-bajo"><div class="stat-label">Bajo</div><div class="stat-value"><?= (int)$bajoStock ?></div></div>
    <div class="stat-card stat-sin"><div class="stat-label">Sin</div><div class="stat-value"><?= (int)$sinStock ?></div></div>
    <div class="stat-card stat-inactivo"><div class="stat-label">Inactivos</div><div class="stat-value"><?= (int)$inactivos ?></div></div>
  </div>

  <form method="get" class="filters" id="stockFilters">
    <div class="filters-left">
      <input type="text" name="q"
             placeholder="Buscar por código, nombre, marca o proveedor..."
             value="<?= h($buscar) ?>">
    </div>

    <div class="filters-right">
      <select name="estado">
        <option value="">Todos</option>
        <option value="ok"       <?= $estado==='ok'?'selected':'' ?>>OK</option>
        <option value="bajo"     <?= $estado==='bajo'?'selected':'' ?>>Bajo</option>
        <option value="sin"      <?= $estado==='sin'?'selected':'' ?>>Sin stock</option>
        <option value="inactivo" <?= $estado==='inactivo'?'selected':'' ?>>Inactivo</option>
      </select>

      <select name="limit" id="limitSel">
        <?php foreach ($perPageOptions as $opt): ?>
          <option value="<?= (int)$opt ?>" <?= $opt===$perPage?'selected':'' ?>><?= (int)$opt ?></option>
        <?php endforeach; ?>
      </select>

      <input type="hidden" name="page" value="<?= (int)$page ?>">

      <button class="v-btn v-btn--primary" type="submit">Aplicar</button>

      <?php if ($buscar || $estado): ?>
        <a href="stock.php" class="v-btn v-btn--ghost">Limpiar</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="table-wrapper" id="tablaStock">
    <table class="stock-table">
      <thead>
        <tr>
          <th>Cód.</th>
          <th>Producto</th>
          <th>Categoría</th>
          <th>Marca</th>
          <th>Proveedor</th>
          <th class="t-right">Stock</th>
          <th class="t-right">Mín.</th>
          <th class="t-center">Estado</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$productosPage): ?>
        <tr><td colspan="8" class="empty-cell">No se encontraron productos.</td></tr>
      <?php else: foreach ($productosPage as $p): ?>
        <tr>
          <td><?= h($p['codigo'] ?? '') ?></td>
          <td><?= h($p['nombre'] ?? '') ?></td>
          <td><?= h($p['categoria'] ?? '') ?></td>
          <td><?= h($p['marca'] ?? '') ?></td>
          <td><?= h($p['proveedor'] ?? '') ?></td>
          <td class="t-right"><?= format_qty_field($p,'stock') ?></td>
          <td class="t-right"><?= format_qty_field($p,'stock_minimo') ?></td>
          <td class="t-center"><span class="tag tag-<?= h($p['estado_stock'] ?? '') ?>"><?= h($p['estado_stock'] ?? '') ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
    <div class="pager">
      <a class="pager-btn <?= $page<=1?'disabled':'' ?>"
         href="<?= $page<=1 ? '#' : h(urlWith(['page'=>$page-1], 'stock.php')) . '#tablaStock' ?>">←</a>

      <div class="pager-mid">Página <?= (int)$page ?> / <?= (int)$totalPages ?></div>

      <a class="pager-btn <?= $page>=$totalPages?'disabled':'' ?>"
         href="<?= $page>=$totalPages ? '#' : h(urlWith(['page'=>$page+1], 'stock.php')) . '#tablaStock' ?>">→</a>
    </div>
  <?php endif; ?>

  <?php if ($reponerTotal > 0): ?>
    <div class="reponer-toggle">
      <button type="button" class="v-btn v-btn--outline" id="btnToggleReponer">
        Productos a reponer (<?= (int)$reponerTotal ?>)
      </button>
    </div>
  <?php endif; ?>

  <?php if ($reponerTotal > 0): ?>
    <div id="reponerSection" class="reponer-section">

      <div class="page-sub" style="margin-bottom:10px">
        Productos con bajo stock o sin stock
      </div>

      <div class="reponer-actions" style="margin-bottom:12px">
        <a class="v-btn v-btn--ghost"
           href="<?= h(urlWith(['export'=>'reponer'], 'stock.php')) ?>">
          Exportar CSV
        </a>
      </div>

      <div class="table-wrapper" id="tablaReponer">
        <table class="stock-table">
          <thead>
            <tr>
              <th>Cód.</th>
              <th>Producto</th>
              <th>Categoría</th>
              <th>Marca</th>
              <th>Proveedor</th>
              <th class="t-right">Stock</th>
              <th class="t-right">Mín.</th>
              <th class="t-right">Sugerido</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($reponerPageData as $p): ?>
            <tr>
              <td><?= h($p['codigo'] ?? '') ?></td>
              <td><?= h($p['nombre'] ?? '') ?></td>
              <td><?= h($p['categoria'] ?? '') ?></td>
              <td><?= h($p['marca'] ?? '') ?></td>
              <td><?= h($p['proveedor'] ?? '') ?></td>
              <td class="t-right"><?= format_qty_field($p,'stock') ?></td>
              <td class="t-right"><?= format_qty_field($p,'stock_minimo') ?></td>
              <td class="t-right"><?= format_qty_field($p,'para_pedir') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($reponerPages > 1): ?>
        <div class="pager">
          <a class="pager-btn <?= $reponerPage<=1?'disabled':'' ?>"
             href="<?= $reponerPage<=1 ? '#' : h(urlWith(['reponer_page'=>$reponerPage-1], 'stock.php')) . '#tablaReponer' ?>">←</a>

          <div class="pager-mid">Página <?= (int)$reponerPage ?> / <?= (int)$reponerPages ?></div>

          <a class="pager-btn <?= $reponerPage>=$reponerPages?'disabled':'' ?>"
             href="<?= $reponerPage>=$reponerPages ? '#' : h(urlWith(['reponer_page'=>$reponerPage+1], 'stock.php')) . '#tablaReponer' ?>">→</a>
        </div>
      <?php endif; ?>

    </div>
  <?php endif; ?>

</div>

<?php require __DIR__ . "/partials/footer.php"; ?>

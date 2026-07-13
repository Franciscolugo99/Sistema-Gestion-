<?php
// public/stock.php - FLUS v5.0 (Refactorizado - Consistente con Productos/Ventas)
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$productosHelpers = FLUS_ROOT . '/src/productos_helpers.php';
if (is_file($productosHelpers)) {
    require_once $productosHelpers;
}
require_login();
require_permission('editar_stock');

$pdo = getPDO();

$tiposAjuste = flus_stock_tipos_ajuste();

/* ============================
   FUNCIÓN CENTRALIZADA: Estado de Stock
============================ */
function calcular_estado_stock(float $stock, float $stock_minimo, bool $activo): string {
    if (!$activo) return 'inactivo';
    if ($stock <= 0) return 'sin';
    if ($stock <= $stock_minimo) return 'bajo';
    return 'ok';
}

/* ============================
   FUNCIÓN: Calcular cantidad sugerida a pedir
============================ */
function calcular_sugerido(float $stock, float $stock_minimo, bool $es_pesable): float {
    // Objetivo: llegar a 2x el mínimo
    $objetivo = $stock_minimo * 2;
    $minPedido = $es_pesable ? 0.001 : 1;
    
    if ($stock <= 0) {
        return max($minPedido, $objetivo);
    }
    
    $faltante = $objetivo - $stock;
    
    if ($faltante <= 0) {
        return $minPedido;
    }
    
    return max($minPedido, $faltante);
}

/* ============================
   FUNCIÓN: Paginación numérica (consistente con ventas)
============================ */

/* ============================
   CONFIG PÁGINA
============================ */
$pageTitle      = "Stock";
$currentSection = "stock";
$breadcrumbs    = [
    ['label' => 'Stock', 'url' => null],
];
$extraCss       = ["assets/css/stock.css", "assets/css/stock-enhanced.css?v=1"];
$extraJs        = ["assets/js/stock.js?v=5.0"];

/* ============================
   TAB ACTIVO
============================ */
$tab = (string)($_GET['tab'] ?? 'general');
if (!in_array($tab, ['general', 'alertas'], true)) $tab = 'general';

/* ============================
   FILTROS
============================ */
$buscar    = trim((string)($_GET['q'] ?? ''));
$estado    = (string)($_GET['estado'] ?? '');
$categoria = (string)($_GET['categoria'] ?? '');
$proveedor = (string)($_GET['proveedor'] ?? '');
$pesable   = (string)($_GET['pesable'] ?? '');

/* ============================
   PAGINACIÓN
============================ */
$perPageOptions = [20, 50, 100];
$perPage = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
if (!in_array($perPage, $perPageOptions, true)) $perPage = 20;

$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

/* ============================
   KPIs (todos los productos)
============================ */
$kpiSql = "
  SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN activo = 0 THEN 1 ELSE 0 END) AS inactivos,
    SUM(CASE WHEN activo = 1 AND stock <= 0 THEN 1 ELSE 0 END) AS sin_stock,
    SUM(CASE WHEN activo = 1 AND stock > 0 AND stock <= stock_minimo THEN 1 ELSE 0 END) AS bajo_stock,
    SUM(CASE WHEN activo = 1 AND stock > stock_minimo THEN 1 ELSE 0 END) AS ok
  FROM productos
";
$kpi = $pdo->query($kpiSql)->fetch(PDO::FETCH_ASSOC) ?: [];

$totalProductos = (int)($kpi['total'] ?? 0);
$inactivos      = (int)($kpi['inactivos'] ?? 0);
$sinStock       = (int)($kpi['sin_stock'] ?? 0);
$bajoStock      = (int)($kpi['bajo_stock'] ?? 0);
$ok             = (int)($kpi['ok'] ?? 0);

/* ============================
   LISTAS PARA FILTROS (con caché simple)
============================ */
$categorias  = $pdo->query("SELECT DISTINCT categoria FROM productos WHERE categoria IS NOT NULL AND categoria != '' ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN);
$proveedores = $pdo->query("SELECT DISTINCT proveedor FROM productos WHERE proveedor IS NOT NULL AND proveedor != '' ORDER BY proveedor")->fetchAll(PDO::FETCH_COLUMN);

/* ============================
   WHERE DINÁMICO
============================ */
$where  = [];
$params = [];

if ($buscar !== '') {
    $where[] = "(codigo LIKE :q1 OR nombre LIKE :q2 OR categoria LIKE :q3 OR marca LIKE :q4 OR proveedor LIKE :q5)";
    $like = '%' . $buscar . '%';
    $params[':q1'] = $like;
    $params[':q2'] = $like;
    $params[':q3'] = $like;
    $params[':q4'] = $like;
    $params[':q5'] = $like;
}

if ($pesable !== '') {
    if ($pesable === 'si') {
        $where[] = "es_pesable = 1";
    } elseif ($pesable === 'no') {
        $where[] = "(es_pesable = 0 OR es_pesable IS NULL)";
    }
}

if ($tab !== 'alertas' && $estado !== '') {
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
            $where[] = "activo = 1 AND stock > stock_minimo";
            break;
    }
}

if ($categoria !== '') {
    $where[] = "categoria = :cat";
    $params[':cat'] = $categoria;
}

if ($proveedor !== '') {
    $where[] = "proveedor = :prov";
    $params[':prov'] = $proveedor;
}

if ($tab === 'alertas') {
    $where[] = "activo = 1 AND (stock <= 0 OR (stock > 0 AND stock <= stock_minimo))";
}

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";
$orderSpec = flus_stock_search_order_sql($buscar);
$orderSql = $orderSpec['sql'];
$orderParams = $orderSpec['params'];

/* ============================
   EXPORT CSV (con límite de seguridad)
============================ */
if (isset($_GET['export'])) {
    $exportType = (string)$_GET['export'];
    if (!in_array($exportType, ['general', 'alertas'], true)) $exportType = 'general';
    
    $exportLimit = 10000;

    $filename = ($exportType === 'alertas') ? 'stock_alertas' : 'stock_general';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));

    if ($exportType === 'alertas') {
        fputcsv($out, ['codigo','nombre','categoria','marca','proveedor','stock','stock_minimo','sugerido','unidad'], ';');
        $sqlExp = "
          SELECT codigo, nombre, categoria, marca, proveedor, stock, stock_minimo, es_pesable, unidad_venta
          FROM productos
          $whereSql
          $orderSql
          LIMIT $exportLimit
        ";
    } else {
        fputcsv($out, ['codigo','nombre','categoria','marca','proveedor','stock','stock_minimo','estado'], ';');
        $sqlExp = "
          SELECT codigo, nombre, categoria, marca, proveedor, stock, stock_minimo, es_pesable, activo
          FROM productos
          $whereSql
          $orderSql
          LIMIT $exportLimit
        ";
    }

    $stmtExp = $pdo->prepare($sqlExp);
    foreach ($orderParams as $k => $v) $stmtExp->bindValue($k, $v, PDO::PARAM_STR);
    foreach ($params as $k => $v) $stmtExp->bindValue($k, $v, PDO::PARAM_STR);
    $stmtExp->execute();

    while ($p = $stmtExp->fetch(PDO::FETCH_ASSOC)) {
        $esPes = is_pesable_row($p);

        if ($exportType === 'alertas') {
            $stockActual = (float)($p['stock'] ?? 0);
            $minimo      = (float)($p['stock_minimo'] ?? 0);
            $paraPedir   = calcular_sugerido($stockActual, $minimo, $esPes);

            $unidad = (string)($p['unidad_venta'] ?? '');
            if ($unidad === '') $unidad = $esPes ? 'KG' : 'UNID';

            fputcsv($out, [
                (string)($p['codigo'] ?? ''),
                (string)($p['nombre'] ?? ''),
                (string)($p['categoria'] ?? ''),
                (string)($p['marca'] ?? ''),
                (string)($p['proveedor'] ?? ''),
                format_stock_con_unidad($p, 'stock'),
                format_stock_con_unidad($p, 'stock_minimo'),
                format_stock_con_unidad(array_merge($p, ['para_pedir' => $paraPedir]), 'para_pedir'),
                $unidad,
            ], ';');

        } else {
            $estadoRow = calcular_estado_stock(
                (float)($p['stock'] ?? 0),
                (float)($p['stock_minimo'] ?? 0),
                (bool)($p['activo'] ?? true)
            );

            fputcsv($out, [
                (string)($p['codigo'] ?? ''),
                (string)($p['nombre'] ?? ''),
                (string)($p['categoria'] ?? ''),
                (string)($p['marca'] ?? ''),
                (string)($p['proveedor'] ?? ''),
                format_stock_con_unidad($p, 'stock'),
                format_stock_con_unidad($p, 'stock_minimo'),
                $estadoRow,
            ], ';');
        }
    }

    fclose($out);
    exit;
}

/* ============================
   TOTAL FILTRADOS + PÁGINAS
============================ */
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM productos $whereSql");
foreach ($params as $k => $v) $stmtCount->bindValue($k, $v, PDO::PARAM_STR);
$stmtCount->execute();
$totalFiltrados = (int)$stmtCount->fetchColumn();

$totalPages = max(1, (int)ceil($totalFiltrados / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

// Calcular rango para paginación
$fromRow = $totalFiltrados > 0 ? $offset + 1 : 0;
$toRow = min($offset + $perPage, $totalFiltrados);

/* ============================
   LISTADO PRINCIPAL
============================ */
$sqlList = "
  SELECT
    id, codigo, nombre, categoria, marca, proveedor,
    stock, stock_minimo, es_pesable, activo, unidad_venta,
    CASE
      WHEN activo = 0 THEN 'inactivo'
      WHEN stock <= 0 THEN 'sin'
      WHEN stock > 0 AND stock <= stock_minimo THEN 'bajo'
      ELSE 'ok'
    END AS estado_stock
  FROM productos
  $whereSql
  $orderSql
  LIMIT :lim OFFSET :off
";
$stmtList = $pdo->prepare($sqlList);
foreach ($orderParams as $k => $v) $stmtList->bindValue($k, $v, PDO::PARAM_STR);
foreach ($params as $k => $v) $stmtList->bindValue($k, $v, PDO::PARAM_STR);
$stmtList->bindValue(':lim', $perPage, PDO::PARAM_INT);
$stmtList->bindValue(':off', $offset, PDO::PARAM_INT);
$stmtList->execute();
$productos = $stmtList->fetchAll(PDO::FETCH_ASSOC);

/* Sugerido solo en alertas */
if ($tab === 'alertas') {
    foreach ($productos as &$p) {
        $stockActual = (float)($p['stock'] ?? 0);
        $minimo      = (float)($p['stock_minimo'] ?? 0);
        $esPes       = is_pesable_row($p);
        $p['para_pedir'] = calcular_sugerido($stockActual, $minimo, $esPes);
    }
    unset($p);
}

/* ============================
   Params para paginación
============================ */
$queryParams = $_GET;
unset($queryParams['page']);

require __DIR__ . "/partials/header.php";
?>

<div class="panel">

  <header class="page-header module-header">
    <div class="page-header-main module-header-main">
      <div class="module-header-hero">
        <span class="module-header-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
            <path d="m12 3 8 4.5v9L12 21 4 16.5v-9L12 3Z"/>
            <path d="m4 7.5 8 4.5 8-4.5"/>
            <path d="M12 12v9"/>
          </svg>
        </span>
        <div class="module-header-copy">
          <span class="page-eyebrow module-eyebrow">Inventario operativo</span>
          <h1 class="page-title">Control de stock</h1>
          <p class="page-sub">Controlá existencias, alertas y ajustes con historial.</p>
        </div>
      </div>
    </div>

    <div class="header-actions module-header-actions">
      <a class="v-btn v-btn--outline" href="movimientos.php">Ver movimientos</a>
    </div>
  </header>

  <div class="stats-row">
    <div class="stat-card stat-clickable" data-filter-estado="">
      <div class="stat-label">Total productos</div>
      <div class="stat-value"><?= (int)$totalProductos ?></div>
    </div>
    <div class="stat-card stat-ok stat-clickable" data-filter-estado="ok">
      <div class="stat-label">Stock OK</div>
      <div class="stat-value"><?= (int)$ok ?></div>
    </div>
    <div class="stat-card stat-bajo stat-clickable" data-filter-estado="bajo">
      <div class="stat-label">Bajo stock</div>
      <div class="stat-value badge-warning"><?= (int)$bajoStock ?></div>
    </div>
    <div class="stat-card stat-sin stat-clickable" data-filter-estado="sin">
      <div class="stat-label">Sin stock</div>
      <div class="stat-value badge-danger"><?= (int)$sinStock ?></div>
    </div>
    <div class="stat-card stat-inactivo stat-clickable" data-filter-estado="inactivo">
      <div class="stat-label">Inactivos</div>
      <div class="stat-value"><?= (int)$inactivos ?></div>
    </div>
  </div>

  <div class="tabs-container">
    <div class="tabs">
      <a href="<?= h(urlWith(['tab'=>'general','page'=>1], 'stock.php')) ?>" class="tab <?= $tab==='general'?'active':'' ?>">
        Stock general
      </a>
      <a href="<?= h(urlWith(['tab'=>'alertas','page'=>1], 'stock.php')) ?>" class="tab <?= $tab==='alertas'?'active':'' ?>">
        Alertas
        <?php if ($sinStock + $bajoStock > 0): ?>
          <span class="tab-badge"><?= $sinStock + $bajoStock ?></span>
        <?php endif; ?>
      </a>
    </div>
  </div>

  <form method="get" class="filters" id="stockFilters">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <input type="hidden" name="page" id="pageInput" value="1">

    <div class="filters-grid">
      <div class="filters-left">
        <div class="search-wrapper">
          <input type="text" name="q"
                 id="searchInput"
                 placeholder="Buscar (Ctrl+K)"
                 value="<?= h($buscar) ?>"
                 class="filter-search"
                 autocomplete="off">
        </div>
      </div>

      <div class="filters-right">

      <?php if ($tab !== 'alertas'): ?>
      <select name="estado" id="estadoSelect" class="filter-select">
        <option value="">Todos los estados</option>
        <option value="ok" <?= $estado==='ok'?'selected':'' ?>>OK</option>
        <option value="bajo" <?= $estado==='bajo'?'selected':'' ?>>Bajo</option>
        <option value="sin" <?= $estado==='sin'?'selected':'' ?>>Sin stock</option>
        <option value="inactivo" <?= $estado==='inactivo'?'selected':'' ?>>Inactivo</option>
      </select>
      <?php endif; ?>

      <select name="pesable" id="pesableSelect" class="filter-select">
        <option value="">Todos</option>
        <option value="si" <?= $pesable==='si'?'selected':'' ?>>Solo pesables</option>
        <option value="no" <?= $pesable==='no'?'selected':'' ?>>Solo no pesables</option>
      </select>

      <select name="categoria" id="categoriaSelect" class="filter-select">
        <option value="">Todas las categorías</option>
        <?php foreach ($categorias as $cat): ?>
          <option value="<?= h($cat) ?>" <?= $categoria===$cat?'selected':'' ?>><?= h($cat) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="proveedor" id="proveedorSelect" class="filter-select">
        <option value="">Todos los proveedores</option>
        <?php foreach ($proveedores as $prov): ?>
          <option value="<?= h($prov) ?>" <?= $proveedor===$prov?'selected':'' ?>><?= h($prov) ?></option>
        <?php endforeach; ?>
      </select>

      <select name="limit" id="limitSelect" class="filter-select">
        <?php foreach ($perPageOptions as $opt): ?>
          <option value="<?= (int)$opt ?>" <?= $opt===$perPage?'selected':'' ?>><?= (int)$opt ?> por página</option>
        <?php endforeach; ?>
      </select>

      <div class="filter-actions">
        <button class="v-btn v-btn--primary" type="submit">Filtrar</button>
        <?php if ($buscar || $estado || $categoria || $proveedor || $pesable): ?>
          <a href="stock.php?tab=<?= h($tab) ?>" class="v-btn v-btn--ghost">Limpiar</a>
        <?php endif; ?>
      </div>
      </div>
    </div>
  </form>

  <!-- Filtros activos (NUEVO - consistente con ventas) -->
  <?php 
  $filtrosActivos = [];
  if ($buscar) $filtrosActivos[] = ['key' => 'q', 'label' => "Búsqueda: $buscar"];
  if ($estado) $filtrosActivos[] = ['key' => 'estado', 'label' => "Estado: $estado"];
  if ($pesable === 'si') $filtrosActivos[] = ['key' => 'pesable', 'label' => 'Tipo: Solo pesables'];
  if ($pesable === 'no') $filtrosActivos[] = ['key' => 'pesable', 'label' => 'Tipo: Solo no pesables'];
  if ($categoria) $filtrosActivos[] = ['key' => 'categoria', 'label' => "Categoría: $categoria"];
  if ($proveedor) $filtrosActivos[] = ['key' => 'proveedor', 'label' => "Proveedor: $proveedor"];
  ?>
  <?php if ($filtrosActivos): ?>
  <div class="filtros-activos">
    <?php foreach ($filtrosActivos as $f): ?>
      <span class="filtro-tag">
        <?= h($f['label']) ?>
        <button type="button" class="filtro-remove" data-filter="<?= $f['key'] ?>">x</button>
      </span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="table-actions">
    <div class="results-info">
      Mostrando <?= number_format($fromRow) ?>-<?= number_format($toRow) ?> de <?= number_format($totalFiltrados) ?> productos
    </div>
    <div class="action-buttons">
      <button type="button" class="v-btn v-btn--ghost btn-icon" data-stock-refresh title="Actualizar datos">
        Actualizar
      </button>
      <a href="<?= h(urlWith(['export' => $tab], 'stock.php')) ?>" class="v-btn v-btn--ghost btn-icon">
        Exportar CSV
      </a>
    </div>
  </div>

  <!-- Paginación SUPERIOR (NUEVO) -->
  <?= render_pagination($page, $totalPages, $queryParams, true, $totalFiltrados, $fromRow, $toRow) ?>

  <div class="table-wrapper" id="tablaStock">
    <table class="stock-table">
      <thead>
        <tr>
          <th class="stock-col-code">Código</th>
          <th>Producto</th>
          <th class="stock-col-category">Categoría</th>
          <th class="stock-col-brand">Marca</th>
          <th class="stock-col-provider">Proveedor</th>
          <th class="stock-col-stock t-right">Stock</th>
          <th class="stock-col-min t-right">Mín.</th>
          <?php if ($tab === 'alertas'): ?>
          <th class="stock-col-suggested t-right">Sugerido</th>
          <?php endif; ?>
          <th class="stock-col-status t-center">Estado</th>
          <th class="stock-col-actions t-center">Acciones</th>
        </tr>
      </thead>
      <tbody id="stockTableBody">
      <?php if (!$productos): ?>
        <tr><td colspan="<?= $tab==='alertas' ? 10 : 9 ?>" class="empty-cell">No se encontraron productos.</td></tr>
      <?php else: foreach ($productos as $p):
        $esPesable = is_pesable_row($p);
        $stockNum = (float)($p['stock'] ?? 0);
        $stockMinNum = (float)($p['stock_minimo'] ?? 0);
        $stockPct = $stockMinNum > 0 ? min(100, ($stockNum / $stockMinNum) * 100) : ($stockNum > 0 ? 100 : 0);
      ?>
        <tr data-id="<?= (int)$p['id'] ?>" 
            data-stock="<?= $stockNum ?>" 
            data-stock-minimo="<?= $stockMinNum ?>"
            data-es-pesable="<?= $esPesable ? '1' : '0' ?>"
            data-unidad-venta="<?= h((string)($p['unidad_venta'] ?? 'UNIDAD')) ?>"
            class="row-<?= h($p['estado_stock']) ?>">
          <td><code><?= h($p['codigo'] ?? '') ?></code></td>
          <td class="td-producto">
            <div class="producto-main">
              <strong><?= h($p['nombre'] ?? '') ?></strong>
              <?php if ($esPesable): ?><span class="badge-pesable">Pesable</span><?php endif; ?>
            </div>
          </td>
          <td><?= h($p['categoria'] ?? '-') ?></td>
          <td><?= h($p['marca'] ?? '-') ?></td>
          <td><?= h($p['proveedor'] ?? '-') ?></td>
          <td class="t-right td-stock">
            <strong class="stock-value"><?= format_stock_con_unidad($p, 'stock') ?></strong>
            <div class="stock-bar">
              <div class="stock-bar-fill stock-bar-<?= h($p['estado_stock']) ?>" data-stock-pct="<?= h((string)(float)$stockPct) ?>"></div>
            </div>
          </td>
          <td class="t-right muted td-stock-min"><?= format_stock_con_unidad($p, 'stock_minimo') ?></td>

          <?php if ($tab === 'alertas'): ?>
          <td class="t-right td-sugerido">
            <strong class="text-primary"><?= format_stock_con_unidad(array_merge($p, ['para_pedir' => (float)$p['para_pedir']]), 'para_pedir') ?></strong>
          </td>
          <?php endif; ?>
          
          <td class="t-center td-estado">
            <span class="tag tag-<?= h($p['estado_stock']) ?>">
              <?= ucfirst(h($p['estado_stock'])) ?>
            </span>
          </td>

          <td class="t-center">
            <div class="action-btns">
              <button
                type="button"
                class="btn-icon btn-sm"
                title="Ajustar stock"
                data-stock-adjust
                data-producto-id="<?= (int)$p['id'] ?>"
                data-producto-nombre="<?= h((string)($p['nombre'] ?? '')) ?>"
                data-es-pesable="<?= $esPesable ? '1' : '0' ?>"
                data-stock-actual-raw="<?= h((string)$stockNum) ?>"
                data-stock-minimo-raw="<?= h((string)$stockMinNum) ?>"
                data-stock-actual-display="<?= h(format_stock_con_unidad($p, 'stock')) ?>"
                data-stock-minimo-display="<?= h(format_stock_con_unidad($p, 'stock_minimo')) ?>"
                data-unidad-venta="<?= h((string)($p['unidad_venta'] ?? 'UNIDAD')) ?>"
                data-unidad-label="<?= h(function_exists('flus_producto_unidad_descripcion') ? flus_producto_unidad_descripcion((string)($p['unidad_venta'] ?? 'UNIDAD'), $esPesable) : ($esPesable ? 'Pesable' : 'Unidad')) ?>"
              >Ajustar</button>

              <a
                class="btn-icon btn-sm"
                title="Ver en Productos"
                href="<?= h(urlWith(['q' => (string)($p['codigo'] ?? '')], 'productos.php')) ?>"
              >Ver producto</a>
            </div>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginación INFERIOR -->
  <?= render_pagination($page, $totalPages, $queryParams, false) ?>

</div>

<!-- MODAL: AJUSTE RAPIDO -->
<div id="modalAjusteStock" class="modal">
  <div class="modal-content modal-sm modal-content--stock-adjust">
    <div class="modal-header">
      <h3 class="modal-title">Ajustar Stock</h3>
      <button type="button" class="modal-close" data-stock-close-modal aria-label="Cerrar ajuste">&times;</button>
    </div>

    <form id="formAjusteStock">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="ajuste_request_id" id="ajuste_request_id" value="">
      <div class="modal-body">
        <input type="hidden" name="producto_id" id="ajuste_producto_id">

        <div class="form-group">
          <label>Producto</label>
          <div id="ajuste_producto_nombre" class="producto-info"></div>
        </div>
        
        <div class="form-group">
          <div class="stock-info-row">
            <div class="stock-info-item">
              <span class="stock-info-label">Stock actual</span>
              <span class="stock-info-value" id="ajuste_stock_actual">-</span>
            </div>
            <div class="stock-info-item">
              <span class="stock-info-label">Stock mínimo</span>
              <span class="stock-info-value" id="ajuste_stock_minimo">-</span>
            </div>
          </div>
        </div>

        <div class="form-group">
          <label>Tipo de ajuste</label>
          <select name="tipo" id="ajuste_tipo" class="form-control" required>
<?php foreach ($tiposAjuste as $key => $tipo): ?>
              <option value="<?= h($key) ?>"><?= h($tipo['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <small class="form-hint" id="ajuste_tipo_hint">Entrada suma mercaderia al stock.</small>
        </div>

        <div class="form-group">
          <label>Cantidad</label>
          <input type="number" name="cantidad" id="ajuste_cantidad" class="form-control" step="0.001" min="0.001" required>
          <small class="form-hint" id="ajuste_cantidad_hint"></small>
        </div>

        <div class="stock-adjust-preview" id="ajuste_preview" aria-live="polite">
          <span class="stock-adjust-preview-label">Vista previa</span>
          <strong id="ajuste_preview_line">Elegí tipo y cantidad para ver el resultado.</strong>
          <small id="ajuste_preview_help">El movimiento queda guardado en el historial del producto.</small>
        </div>

        <div class="form-group">
          <label>Historial reciente</label>
          <div id="ajuste_historial" class="stock-history">
            <div class="stock-history-empty">Cargando historial...</div>
          </div>
        </div>

        <div class="form-group">
          <label>Motivo <span class="text-muted">(opcional)</span></label>
          <textarea name="motivo" id="ajuste_motivo" class="form-control" rows="2" maxlength="255"
            placeholder="Ej: recepción proveedor, corrección, rotura, etc."></textarea>
          <small class="form-hint"><span id="motivo_chars">0</span>/255 caracteres</small>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="v-btn v-btn--ghost" data-stock-close-modal>Cancelar</button>
        <button type="submit" class="v-btn v-btn--primary">Confirmar</button>
      </div>
    </form>
  </div>
</div>

<!-- MODAL: CONFIRMACIÓN AJUSTE GRANDE -->
<div id="modalConfirmacion" class="modal">
  <div class="modal-content modal-sm">
    <div class="modal-header">
      <h3 class="modal-title">Confirmar ajuste</h3>
      <button type="button" class="modal-close" data-stock-close-confirm aria-label="Cerrar confirmación">&times;</button>
    </div>
    <div class="modal-body">
      <p id="confirmacion_mensaje"></p>
    </div>
    <div class="modal-footer">
      <button type="button" class="v-btn v-btn--ghost" data-stock-close-confirm>Cancelar</button>
      <button type="button" class="v-btn v-btn--danger" data-stock-confirm-adjust data-default-text="Sí, confirmar">Sí, confirmar</button>
    </div>
  </div>
</div>

<!-- Keyboard hints (NUEVO) -->
<div class="keyboard-hints" id="keyboardHints">
    <div class="keyboard-hints-item">
        <kbd>Ctrl</kbd> + <kbd>K</kbd> = Buscar
    </div>
    <div class="keyboard-hints-item">
        <kbd>Esc</kbd> = Cerrar
    </div>
</div>

<?php require __DIR__ . "/partials/footer.php"; ?>

<?php
// public/movimientos.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('ver_movimientos');

$pdo = getPDO();

// Detectar columnas opcionales para compatibilidad con instalaciones viejas.
$hasRefVenta = false;
$hasRefCompra = false;
try {
    $st = $pdo->query("SHOW COLUMNS FROM movimientos_stock LIKE 'referencia_venta_id'");
    $hasRefVenta = (bool)$st->fetch();
} catch (Throwable $e) {
    $hasRefVenta = false;
}
try {
    $st = $pdo->query("SHOW COLUMNS FROM movimientos_stock LIKE 'referencia_compra_id'");
    $hasRefCompra = (bool)$st->fetch();
} catch (Throwable $e) {
    $hasRefCompra = false;
}

$selRefVenta = $hasRefVenta ? 'm.referencia_venta_id' : 'NULL';
$selRefCompra = $hasRefCompra ? 'm.referencia_compra_id' : 'NULL';

function tipoNorm(string $value): string
{
    $value = strtoupper(trim($value));
    if ($value === 'ANULACIÃƒâ€œN') {
        return 'ANULACION';
    }
    if ($value === 'DEVOLUCIÃƒâ€œN') {
        return 'DEVOLUCION';
    }

    return $value;
}

function tipoDisplayFromRow(string $tipoRaw, $refVenta, $refCompra, ?string $comentario): string
{
    $tipo = tipoNorm($tipoRaw);
    $comentario = (string)$comentario;

    if ($tipo === '' && $comentario !== '') {
        if (stripos($comentario, 'anulaciÃƒÂ³n compra') === 0) {
            $tipo = 'ANULACION';
        }
        if (stripos($comentario, 'anulaciÃƒÂ³n venta') === 0) {
            $tipo = 'ANULACION';
        }
    }

    if ($tipo === 'ANULACION') {
        if (!empty($refCompra)) {
            return 'ANULACION_COMPRA';
        }
        if (!empty($refVenta)) {
            return 'ANULACION_VENTA';
        }
        if (stripos($comentario, 'anulaciÃƒÂ³n compra') === 0) {
            return 'ANULACION_COMPRA';
        }
        if (stripos($comentario, 'anulaciÃƒÂ³n venta') === 0) {
            return 'ANULACION_VENTA';
        }

        return 'ANULACION';
    }

    if ($tipo === 'DEVOLUCION' || $tipo === 'DEVOLUCIÃƒâ€œN') {
        return 'DEVOLUCION';
    }

    return $tipo;
}

function tipoLabel(string $tipo): string
{
    return match (tipoNorm($tipo)) {
        'VENTA' => 'Venta',
        'COMPRA' => 'Compra',
        'AJUSTE_POSITIVO' => 'Ajuste +',
        'AJUSTE_NEGATIVO' => 'Ajuste -',
        'ANULACION_VENTA' => 'Anulacion venta',
        'ANULACION_COMPRA' => 'Anulacion compra',
        'ANULACION' => 'Anulacion',
        'DEVOLUCION' => 'Devolucion',
        default => tipoNorm($tipo),
    };
}

function flujoLabel(string $flujo): string
{
    return match ($flujo) {
        'ENTRADA' => 'Entradas',
        'SALIDA' => 'Salidas',
        default => 'Todos',
    };
}

function sortLabel(string $sort): string
{
    return match ($sort) {
        'producto' => 'Producto',
        'tipo' => 'Tipo',
        'cantidad' => 'Cantidad',
        default => 'Fecha',
    };
}

function render_pagination(int $page, int $totalPages, array $params, bool $showInfo = true, int $total = 0, int $from = 0, int $to = 0): string
{
    if ($totalPages <= 1) {
        return '';
    }

    unset($params['page']);
    $html = '<div class="pagination">';

    if ($showInfo && $total > 0) {
        $html .= '<span class="pagination-info">' . number_format($from) . '-' . number_format($to) . ' de ' . number_format($total) . '</span>';
    }

    $html .= '<div class="pagination-btns">';

    if ($page > 1) {
        $params['page'] = $page - 1;
        $html .= '<a href="?' . http_build_query($params) . '" class="pg-btn">&lsaquo;</a>';
    } else {
        $html .= '<span class="pg-btn disabled">&lsaquo;</span>';
    }

    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);

    if ($start > 1) {
        $params['page'] = 1;
        $html .= '<a href="?' . http_build_query($params) . '" class="pg-btn">1</a>';
        if ($start > 2) {
            $html .= '<span class="pg-ellipsis">...</span>';
        }
    }

    for ($i = $start; $i <= $end; $i++) {
        $params['page'] = $i;
        $html .= ($i === $page)
            ? '<span class="pg-btn active">' . $i . '</span>'
            : '<a href="?' . http_build_query($params) . '" class="pg-btn">' . $i . '</a>';
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $html .= '<span class="pg-ellipsis">...</span>';
        }
        $params['page'] = $totalPages;
        $html .= '<a href="?' . http_build_query($params) . '" class="pg-btn">' . $totalPages . '</a>';
    }

    if ($page < $totalPages) {
        $params['page'] = $page + 1;
        $html .= '<a href="?' . http_build_query($params) . '" class="pg-btn">&rsaquo;</a>';
    } else {
        $html .= '<span class="pg-btn disabled">&rsaquo;</span>';
    }

    $html .= '</div></div>';

    return $html;
}

function render_sort_link(string $label, string $sortKey, string $currentSort, string $currentDir, array $params, bool $rightAligned = false): string
{
    $isActive = $currentSort === $sortKey;
    $nextDir = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';
    $icon = !$isActive ? '&harr;' : ($currentDir === 'asc' ? '&uarr;' : '&darr;');

    unset($params['page']);
    $params['sort'] = $sortKey;
    $params['dir'] = $nextDir;
    $params['page'] = 1;

    $classes = 'mov-sort';
    if ($isActive) {
        $classes .= ' is-active';
    }
    if ($rightAligned) {
        $classes .= ' mov-sort--right';
    }

    return '<a href="?' . h(http_build_query($params)) . '" class="' . h($classes) . '"><span>' . h($label) . '</span><span class="mov-sort__icon">' . $icon . '</span></a>';
}

function tipoSign(string $tipo): int
{
    $tipo = tipoNorm($tipo);

    if (in_array($tipo, ['VENTA', 'AJUSTE_NEGATIVO', 'ANULACION_COMPRA'], true)) {
        return -1;
    }

    if (in_array($tipo, ['COMPRA', 'AJUSTE_POSITIVO', 'ANULACION', 'ANULACION_VENTA', 'DEVOLUCION'], true)) {
        return 1;
    }

    return 1;
}

function prettyQtyByTipo(float $cantidad, string $tipo, int $esPesable, ?string $unidadVenta): array
{
    $unidadVenta = strtoupper(trim((string)$unidadVenta));
    $signoTipo = tipoSign($tipo);
    $tipoNormalizado = tipoNorm($tipo);

    if (in_array($tipoNormalizado, ['ANULACION', 'ANULACION_COMPRA', 'ANULACION_VENTA'], true)) {
        $cantidadNormalizada = (float)$cantidad;
    } else {
        $cantidadNormalizada = abs($cantidad) * $signoTipo;
    }

    $unitMap = [
        'UNIDAD' => 'u',
        'KG' => 'kg',
        'G' => 'g',
        'LT' => 'lt',
        'ML' => 'ml',
    ];

    $esPesableReal = ($esPesable === 1) || in_array($unidadVenta, ['KG', 'G', 'LT', 'ML'], true);
    $abs = abs($cantidadNormalizada);
    $pretty = $esPesableReal
        ? number_format($abs, 3, ',', '.')
        : number_format($abs, 0, ',', '.');

    $signChar = $cantidadNormalizada < 0 ? '-' : '+';
    $dirLabel = $cantidadNormalizada < 0 ? 'Salida' : 'Entrada';
    $unit = $unitMap[$unidadVenta] ?? ($esPesableReal ? 'kg' : 'u');

    return [$cantidadNormalizada, $signChar, $pretty, $unit, $dirLabel];
}

$productoId = (int)($_GET['producto_id'] ?? 0);
$searchRaw = trim((string)($_GET['q'] ?? ''));
$search = function_exists('mb_substr') ? mb_substr($searchRaw, 0, 80) : substr($searchRaw, 0, 80);

$tipoRaw = (string)($_GET['tipo'] ?? '');
$tipo = $tipoRaw !== '' ? tipoNorm($tipoRaw) : '';
$flujo = strtoupper(trim((string)($_GET['flujo'] ?? '')));
if (!in_array($flujo, ['', 'ENTRADA', 'SALIDA'], true)) {
    $flujo = '';
}

$desdeRaw = (string)($_GET['desde'] ?? '');
$hastaRaw = (string)($_GET['hasta'] ?? '');
$desde = validDateYmd($desdeRaw);
$hasta = validDateYmd($hastaRaw);
$dateRangeAdjusted = false;
if ($desde !== null && $hasta !== null && strcmp($desde, $hasta) > 0) {
    [$desde, $hasta] = [$hasta, $desde];
    $dateRangeAdjusted = true;
}

$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, [20, 50, 100], true)) {
    $perPage = 50;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$export = ((string)($_GET['export'] ?? '')) === 'csv';
$sort = (string)($_GET['sort'] ?? 'fecha');
$allowedSorts = ['fecha', 'producto', 'tipo', 'cantidad'];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = 'fecha';
}
$dir = strtolower((string)($_GET['dir'] ?? 'desc'));
$dir = $dir === 'asc' ? 'asc' : 'desc';
$dirSql = strtoupper($dir);

$allowedTipos = ['VENTA', 'COMPRA', 'AJUSTE_POSITIVO', 'AJUSTE_NEGATIVO', 'ANULACION', 'ANULACION_VENTA', 'ANULACION_COMPRA', 'DEVOLUCION'];
$baseFromSql = 'FROM movimientos_stock m JOIN productos p ON p.id = m.producto_id';
$exprTipo = 'UPPER(TRIM(m.tipo))';
$condAnulacion = "{$exprTipo} IN ('ANULACION','ANULACIÃƒâ€œN')";
$condDevolucion = "{$exprTipo} IN ('DEVOLUCION','DEVOLUCIÃƒâ€œN')";
$condAnulCompra = $hasRefCompra ? 'm.referencia_compra_id IS NOT NULL' : "m.comentario LIKE 'AnulaciÃƒÂ³n compra%'";
$condAnulVenta = $hasRefVenta ? 'm.referencia_venta_id IS NOT NULL' : "m.comentario LIKE 'AnulaciÃƒÂ³n venta%'";
$condSalida = "{$exprTipo} = 'VENTA' OR {$exprTipo} = 'AJUSTE_NEGATIVO' OR ({$condAnulacion} AND ({$condAnulCompra}))";
$condEntrada = "{$exprTipo} = 'COMPRA' OR {$exprTipo} = 'AJUSTE_POSITIVO' OR {$condDevolucion} OR ({$condAnulacion} AND (({$condAnulVenta}) OR (NOT ({$condAnulCompra}) AND NOT ({$condAnulVenta}))))";
$whereParts = ['1=1'];
$params = [];

if ($productoId > 0) {
    $whereParts[] = 'm.producto_id = :producto_id';
    $params[':producto_id'] = $productoId;
}

if ($search !== '') {
    $whereParts[] = "(
        p.nombre LIKE :q_like
        OR p.codigo LIKE :q_like
        OR COALESCE(m.comentario, '') LIKE :q_like
        OR CAST(m.id AS CHAR) = :q_exact
        OR CAST({$selRefVenta} AS CHAR) = :q_exact
        OR CAST({$selRefCompra} AS CHAR) = :q_exact
    )";
    $params[':q_like'] = '%' . $search . '%';
    $params[':q_exact'] = $search;
}

if ($tipo !== '' && in_array($tipo, $allowedTipos, true)) {
    if ($tipo === 'ANULACION_COMPRA') {
        $whereParts[] = "(UPPER(TRIM(m.tipo)) IN ('ANULACION','ANULACIÃƒâ€œN'))";
        $whereParts[] = $condAnulCompra;
    } elseif ($tipo === 'ANULACION_VENTA') {
        $whereParts[] = "(UPPER(TRIM(m.tipo)) IN ('ANULACION','ANULACIÃƒâ€œN'))";
        $whereParts[] = $condAnulVenta;
    } elseif ($tipo === 'ANULACION') {
        $whereParts[] = "(UPPER(TRIM(m.tipo)) IN ('ANULACION','ANULACIÃƒâ€œN'))";
    } elseif ($tipo === 'DEVOLUCION') {
        $whereParts[] = "(UPPER(TRIM(m.tipo)) IN ('DEVOLUCION','DEVOLUCIÃƒâ€œN'))";
    } else {
        $whereParts[] = 'UPPER(TRIM(m.tipo)) = :tipo';
        $params[':tipo'] = $tipo;
    }
}

if ($flujo === 'ENTRADA') {
    $whereParts[] = "({$condEntrada})";
} elseif ($flujo === 'SALIDA') {
    $whereParts[] = "({$condSalida})";
}

if ($desde !== null) {
    $whereParts[] = 'm.fecha >= :desde';
    $params[':desde'] = $desde . ' 00:00:00';
}
if ($hasta !== null) {
    $whereParts[] = 'm.fecha <= :hasta';
    $params[':hasta'] = $hasta . ' 23:59:59';
}

$whereSql = 'WHERE ' . implode(' AND ', $whereParts);

$orderSql = match ($sort) {
    'producto' => "ORDER BY p.nombre {$dirSql}, p.codigo {$dirSql}, m.fecha DESC, m.id DESC",
    'tipo' => "ORDER BY UPPER(TRIM(m.tipo)) {$dirSql}, m.fecha DESC, m.id DESC",
    'cantidad' => "ORDER BY ABS(m.cantidad) {$dirSql}, m.fecha DESC, m.id DESC",
    default => "ORDER BY m.fecha {$dirSql}, m.id {$dirSql}",
};

if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="movimientos_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'fecha', 'producto', 'codigo', 'tipo', 'cantidad_raw', 'cantidad_norm', 'ref_venta', 'ref_compra', 'comentario'], ';');

    $sqlCsv = "
        SELECT
            m.id,
            m.fecha,
            p.nombre AS producto,
            p.codigo AS codigo,
            p.es_pesable,
            p.unidad_venta,
            UPPER(TRIM(m.tipo)) AS tipo,
            m.cantidad,
            {$selRefVenta} AS referencia_venta_id,
            {$selRefCompra} AS referencia_compra_id,
            m.comentario
        {$baseFromSql}
        {$whereSql}
        {$orderSql}
    ";
    $st = $pdo->prepare($sqlCsv);
    $st->execute($params);

    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $tipoDisplay = tipoDisplayFromRow(
            (string)($row['tipo'] ?? ''),
            $row['referencia_venta_id'] ?? null,
            $row['referencia_compra_id'] ?? null,
            (string)($row['comentario'] ?? '')
        );
        [$qtyNorm] = prettyQtyByTipo(
            (float)($row['cantidad'] ?? 0),
            $tipoDisplay,
            (int)($row['es_pesable'] ?? 0),
            (string)($row['unidad_venta'] ?? 'UNIDAD')
        );

        fputcsv($out, [
            $row['id'],
            $row['fecha'],
            $row['producto'],
            $row['codigo'],
            $tipoDisplay,
            $row['cantidad'],
            $qtyNorm,
            $row['referencia_venta_id'] ?? null,
            $row['referencia_compra_id'] ?? null,
            $row['comentario'],
        ], ';');
    }
    exit;
}

$stCount = $pdo->prepare("SELECT COUNT(*) {$baseFromSql} {$whereSql}");
$stCount->execute($params);
$totalRows = (int)$stCount->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;
$fromRow = $totalRows > 0 ? ($offset + 1) : 0;
$toRow = min($offset + $perPage, $totalRows);
$queryParams = [
    'q' => $search,
    'producto_id' => $productoId > 0 ? $productoId : '',
    'tipo' => $tipo,
    'flujo' => $flujo,
    'desde' => $desde ?? '',
    'hasta' => $hasta ?? '',
    'per_page' => $perPage,
    'sort' => $sort,
    'dir' => $dir,
];

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
        {$selRefVenta} AS referencia_venta_id,
        {$selRefCompra} AS referencia_compra_id,
        m.comentario
    {$baseFromSql}
    {$whereSql}
    {$orderSql}
    LIMIT :limit OFFSET :offset
";
$stList = $pdo->prepare($sqlList);
foreach ($params as $key => $value) {
    $stList->bindValue($key, $value);
}
$stList->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stList->bindValue(':offset', $offset, PDO::PARAM_INT);
$stList->execute();
$movs = $stList->fetchAll(PDO::FETCH_ASSOC) ?: [];

$productos = $pdo->query("
    SELECT id, codigo, nombre
    FROM productos
    ORDER BY nombre
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$productoFiltroLabel = '';
foreach ($productos as $producto) {
    if ((int)($producto['id'] ?? 0) === $productoId) {
        $productoFiltroLabel = trim((string)($producto['nombre'] ?? '') . ' (' . (string)($producto['codigo'] ?? '') . ')');
        break;
    }
}

$hasActiveFilters = $productoId > 0
    || $search !== ''
    || $tipo !== ''
    || $flujo !== ''
    || $desde !== null
    || $hasta !== null
    || $perPage !== 50
    || $sort !== 'fecha'
    || $dir !== 'desc';

$filterTags = [];
if ($flujo !== '') {
    $filterTags[] = 'Flujo: ' . flujoLabel($flujo);
}
if ($search !== '') {
    $filterTags[] = 'Busqueda: ' . $search;
}
if ($productoFiltroLabel !== '') {
    $filterTags[] = 'Producto: ' . $productoFiltroLabel;
}
if ($tipo !== '') {
    $filterTags[] = 'Tipo: ' . tipoLabel($tipo);
}
if ($desde !== null || $hasta !== null) {
    $filterTags[] = 'Periodo: ' . ($desde ?? '...') . ' a ' . ($hasta ?? '...');
}
if ($perPage !== 50) {
    $filterTags[] = 'Filas por pagina: ' . $perPage;
}
if ($sort !== 'fecha' || $dir !== 'desc') {
    $filterTags[] = 'Orden: ' . sortLabel($sort) . ' ' . ($dir === 'asc' ? 'asc' : 'desc');
}

$stKpis = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        COUNT(DISTINCT m.producto_id) AS productos,
        MAX(m.fecha) AS ultimo_movimiento,
        SUM(CASE WHEN {$condEntrada} THEN 1 ELSE 0 END) AS entradas,
        SUM(CASE WHEN {$condSalida} THEN 1 ELSE 0 END) AS salidas
    {$baseFromSql}
    {$whereSql}
");
$stKpis->execute($params);
$kpis = $stKpis->fetch(PDO::FETCH_ASSOC) ?: [
    'total' => 0,
    'productos' => 0,
    'ultimo_movimiento' => null,
    'entradas' => 0,
    'salidas' => 0,
];

$stStats = $pdo->prepare("
    SELECT
        SUM(UPPER(TRIM(m.tipo)) = 'VENTA') AS ventas,
        SUM(UPPER(TRIM(m.tipo)) = 'COMPRA') AS compras,
        SUM(UPPER(TRIM(m.tipo)) IN ('AJUSTE_POSITIVO','AJUSTE_NEGATIVO')) AS ajustes,
        SUM(UPPER(TRIM(m.tipo)) IN ('DEVOLUCION','DEVOLUCIÃƒâ€œN')) AS devoluciones,
        SUM(
            UPPER(TRIM(m.tipo)) IN ('ANULACION','ANULACIÃƒâ€œN')
            OR ((m.tipo = '' OR m.tipo IS NULL) AND m.comentario LIKE 'AnulaciÃƒÂ³n%')
        ) AS anulaciones
    {$baseFromSql}
    {$whereSql}
");
$stStats->execute($params);
$stats = $stStats->fetch(PDO::FETCH_ASSOC) ?: [
    'ventas' => 0,
    'compras' => 0,
    'ajustes' => 0,
    'devoluciones' => 0,
    'anulaciones' => 0,
];

$ultimoMovimientoLabel = 'Sin movimientos';
if (!empty($kpis['ultimo_movimiento'])) {
    $ts = strtotime((string)$kpis['ultimo_movimiento']);
    $ultimoMovimientoLabel = $ts !== false ? date('d/m/Y H:i', $ts) : (string)$kpis['ultimo_movimiento'];
}

$pageTitle = 'Movimientos';
$currentSection = 'movimientos';
$extraCss = ['assets/css/movimientos.css'];
$extraJs = ['assets/js/movimientos.js'];

require __DIR__ . '/partials/header.php';
?>

<div class="panel mov-panel">
    <header class="page-header">
        <div>
            <h1 class="page-title">Movimientos</h1>
            <p class="page-sub">Registro de ventas, compras, ajustes, anulaciones y devoluciones.</p>
        </div>

        <div>
            <a href="<?= h(urlWith(['export' => 'csv', 'page' => 1])) ?>" class="v-btn v-btn--outline">
                Exportar CSV
            </a>
        </div>
    </header>

    <div class="stats-row mov-stats-row mov-stats-row--primary">
        <div class="stat-card"><div class="stat-label">Registros</div><div class="stat-value"><?= (int)$kpis['total'] ?></div></div>
        <div class="stat-card"><div class="stat-label">Entradas</div><div class="stat-value stat-value--ok"><?= (int)$kpis['entradas'] ?></div></div>
        <div class="stat-card"><div class="stat-label">Salidas</div><div class="stat-value stat-value--warn"><?= (int)$kpis['salidas'] ?></div></div>
        <div class="stat-card"><div class="stat-label">Productos</div><div class="stat-value"><?= (int)$kpis['productos'] ?></div></div>
        <div class="stat-card"><div class="stat-label">Ultimo mov.</div><div class="stat-value stat-value--compact"><?= h($ultimoMovimientoLabel) ?></div></div>
    </div>

    <div class="stats-row mov-stats-row mov-stats-row--secondary">
        <div class="stat-card"><div class="stat-label">Ventas</div><div class="stat-value"><?= (int)$stats['ventas'] ?></div></div>
        <div class="stat-card"><div class="stat-label">Compras</div><div class="stat-value"><?= (int)$stats['compras'] ?></div></div>
        <div class="stat-card"><div class="stat-label">Ajustes</div><div class="stat-value"><?= (int)$stats['ajustes'] ?></div></div>
        <div class="stat-card"><div class="stat-label">Devoluciones</div><div class="stat-value"><?= (int)$stats['devoluciones'] ?></div></div>
        <div class="stat-card"><div class="stat-label">Anulaciones</div><div class="stat-value"><?= (int)$stats['anulaciones'] ?></div></div>
    </div>

    <p class="mov-stats-caption">
        <?= $hasActiveFilters ? 'Resumen aplicado a la busqueda actual.' : 'Resumen completo del historial de movimientos.' ?>
    </p>

    <div class="mov-flow-switch" aria-label="Filtro principal por flujo">
        <?php foreach ([
            '' => 'Todos',
            'ENTRADA' => 'Entradas',
            'SALIDA' => 'Salidas',
        ] as $flowValue => $flowText): ?>
            <a
                href="<?= h(urlWith(['flujo' => $flowValue, 'page' => 1])) ?>"
                class="mov-flow-pill <?= $flujo === $flowValue ? 'is-active' : '' ?>"
            >
                <?= h($flowText) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <form method="get" class="filters" id="movFilters">
        <input type="hidden" name="flujo" value="<?= h($flujo) ?>">
        <input
            type="search"
            name="q"
            value="<?= h($search) ?>"
            placeholder="Buscar por producto, codigo, comentario o referencia"
            autocomplete="off"
        >

        <select name="producto_id">
            <option value="">Todos los productos</option>
            <?php foreach ($productos as $producto): ?>
                <?php $pid = (int)($producto['id'] ?? 0); ?>
                <option value="<?= $pid ?>" <?= $productoId === $pid ? 'selected' : '' ?>>
                    <?= h((string)($producto['nombre'] ?? '')) ?> (<?= h((string)($producto['codigo'] ?? '')) ?>)
                </option>
            <?php endforeach; ?>
        </select>

        <select name="tipo">
            <option value="">Todos los tipos</option>
            <?php foreach ($allowedTipos as $tipoOption): ?>
                <option value="<?= h($tipoOption) ?>" <?= $tipo === $tipoOption ? 'selected' : '' ?>>
                    <?= h(tipoLabel($tipoOption)) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <input type="date" name="desde" value="<?= h($desde ?? '') ?>">
        <input type="date" name="hasta" value="<?= h($hasta ?? '') ?>">

        <select name="per_page">
            <?php foreach ([20, 50, 100] as $pageSize): ?>
                <option value="<?= $pageSize ?>" <?= $perPage === $pageSize ? 'selected' : '' ?>><?= $pageSize ?></option>
            <?php endforeach; ?>
        </select>

        <button class="v-btn v-btn--primary">Filtrar</button>
        <a href="movimientos.php" class="v-btn v-btn--ghost" id="movClearBtn">Limpiar</a>
    </form>

    <div class="filters-quick">
        <span>Rapido:</span>
        <button type="button" class="chip" data-range="today">Hoy</button>
        <button type="button" class="chip" data-range="7d">7 dias</button>
        <button type="button" class="chip" data-range="30d">30 dias</button>
    </div>

    <section class="mov-summary" aria-label="Resumen de filtros">
        <div class="mov-summary__headline">
            <strong><?= $totalRows ?></strong> resultado<?= $totalRows === 1 ? '' : 's' ?>
            <?php if ($totalRows > 0): ?>
                <span class="muted">| <?= $fromRow ?>-<?= $toRow ?> de <?= $totalRows ?> | pagina <?= $page ?> de <?= $totalPages ?> | <?= $perPage ?> por pagina</span>
            <?php endif; ?>
        </div>

        <?php if ($dateRangeAdjusted): ?>
            <p class="mov-summary__notice">Se invirtio el rango de fechas para usar primero la fecha mas antigua.</p>
        <?php endif; ?>

        <?php if ($filterTags): ?>
            <div class="mov-tags">
                <?php foreach ($filterTags as $tag): ?>
                    <span class="mov-tag"><?= h($tag) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <?= render_pagination($page, $totalPages, $queryParams, true, $totalRows, $fromRow, $toRow) ?>

    <div class="table-wrapper">
        <table class="mov-table">
            <thead>
                <tr>
                    <th><?= render_sort_link('Fecha', 'fecha', $sort, $dir, $queryParams) ?></th>
                    <th><?= render_sort_link('Producto', 'producto', $sort, $dir, $queryParams) ?></th>
                    <th class="t-right"><?= render_sort_link('Cantidad', 'cantidad', $sort, $dir, $queryParams, true) ?></th>
                    <th><?= render_sort_link('Tipo', 'tipo', $sort, $dir, $queryParams) ?></th>
                    <th>Referencia</th>
                    <th>Comentario</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$movs): ?>
                    <tr><td colspan="6" class="empty-cell">No se encontraron movimientos para los filtros actuales.</td></tr>
                <?php else: ?>
                    <?php foreach ($movs as $movimiento): ?>
                        <?php
                        $tipoDisplay = tipoDisplayFromRow(
                            (string)($movimiento['tipo'] ?? ''),
                            $movimiento['referencia_venta_id'] ?? null,
                            $movimiento['referencia_compra_id'] ?? null,
                            (string)($movimiento['comentario'] ?? '')
                        );
                        [$qtyNorm, $signChar, $pretty, $unit, $dirLabel] = prettyQtyByTipo(
                            (float)($movimiento['cantidad'] ?? 0),
                            $tipoDisplay,
                            (int)($movimiento['es_pesable'] ?? 0),
                            (string)($movimiento['unidad_venta'] ?? 'UNIDAD')
                        );

                        $rowHref = '';
                        $rowLabel = '';
                        if (!empty($movimiento['referencia_venta_id'])) {
                            $rowHref = 'venta_detalle.php?id=' . (int)$movimiento['referencia_venta_id'];
                            $rowLabel = 'Abrir venta #' . (int)$movimiento['referencia_venta_id'];
                        } elseif (!empty($movimiento['referencia_compra_id'])) {
                            $rowHref = 'compras.php?q=' . urlencode((string)(int)$movimiento['referencia_compra_id']);
                            $rowLabel = 'Buscar compra #' . (int)$movimiento['referencia_compra_id'];
                        }
                        ?>
                        <tr
                            class="<?= h('row-' . strtolower(str_replace('_', '-', $tipoDisplay))) ?> <?= $rowHref !== '' ? 'mov-row-link' : '' ?>"
                            <?= $rowHref !== '' ? 'data-href="' . h($rowHref) . '" data-row-label="' . h($rowLabel) . '" tabindex="0" role="link"' : '' ?>
                        >
                            <td class="mono">
                                <?= h((string)($movimiento['fecha'] ?? '')) ?>
                                <?php if ($rowHref !== ''): ?>
                                    <div class="mov-row-hint"><?= h($rowLabel) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= h((string)($movimiento['nombre'] ?? '')) ?>
                                <span class="muted">(<?= h((string)($movimiento['codigo'] ?? '')) ?>)</span>
                            </td>
                            <td class="t-right">
                                <span class="qty <?= $qtyNorm < 0 ? 'qty-neg' : 'qty-pos' ?>">
                                    <?= h($signChar . $pretty . ' ' . $unit) ?>
                                </span>
                                <span class="muted">(<?= h($dirLabel) ?>)</span>
                            </td>
                            <td><span class="mov-badge"><?= h(tipoLabel($tipoDisplay)) ?></span></td>
                            <td>
                                <div class="mov-ref-stack">
                                    <?php if (!empty($movimiento['referencia_venta_id'])): ?>
                                        <a class="mov-ref mov-ref--venta" href="venta_detalle.php?id=<?= (int)$movimiento['referencia_venta_id'] ?>">Venta #<?= (int)$movimiento['referencia_venta_id'] ?></a>
                                    <?php endif; ?>
                                    <?php if (!empty($movimiento['referencia_compra_id'])): ?>
                                        <a class="mov-ref mov-ref--compra" href="compras.php?q=<?= (int)$movimiento['referencia_compra_id'] ?>">Compra #<?= (int)$movimiento['referencia_compra_id'] ?></a>
                                    <?php endif; ?>
                                    <?php if (empty($movimiento['referencia_venta_id']) && empty($movimiento['referencia_compra_id'])): ?>
                                        <span class="muted">Manual</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <?php if (trim((string)($movimiento['comentario'] ?? '')) !== ''): ?>
                                    <?= h((string)$movimiento['comentario']) ?>
                                <?php else: ?>
                                    <span class="muted">Sin comentario</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?= render_pagination($page, $totalPages, $queryParams, true, $totalRows, $fromRow, $toRow) ?>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
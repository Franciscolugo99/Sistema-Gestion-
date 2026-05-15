<?php
// public/movimientos.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('ver_movimientos');

$pdo = getPDO();

// Detectar columnas opcionales para compatibilidad con instalaciones viejas.
$hasRefVenta = flus_column_exists($pdo, 'movimientos_stock', 'referencia_venta_id');
$hasRefCompra = flus_column_exists($pdo, 'movimientos_stock', 'referencia_compra_id');

$selRefVenta = $hasRefVenta ? 'm.referencia_venta_id' : 'NULL';
$selRefCompra = $hasRefCompra ? 'm.referencia_compra_id' : 'NULL';

function tipoNorm(string $value): string
{
    $value = strtoupper(trim($value));
    if ($value === 'ANULACION') {
        return 'ANULACION';
    }
    if ($value === 'DEVOLUCION') {
        return 'DEVOLUCION';
    }

    return $value;
}

function tipoDisplayFromRow(string $tipoRaw, $refVenta, $refCompra, ?string $comentario): string
{
    $tipo = mov_tipo_norm($tipoRaw);
    $comentario = (string)$comentario;

    if ($tipo === '' && $comentario !== '') {
        if (stripos($comentario, 'anulacion compra') === 0) {
            $tipo = 'ANULACION';
        }
        if (stripos($comentario, 'anulacion venta') === 0) {
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
        if (stripos($comentario, 'anulacion compra') === 0) {
            return 'ANULACION_COMPRA';
        }
        if (stripos($comentario, 'anulacion venta') === 0) {
            return 'ANULACION_VENTA';
        }

        return 'ANULACION';
    }

    if ($tipo === 'DEVOLUCION') {
        return 'DEVOLUCION';
    }

    return $tipo;
}

function mov_tipo_norm(string $value): string
{
    $value = strtoupper(trim($value));
    if (in_array($value, ['ANULACION_VENTA', 'ANULACION_COMPRA'], true)) {
        return $value;
    }
    if ($value !== '' && str_starts_with($value, 'ANULACI')) {
        return 'ANULACION';
    }
    if ($value !== '' && str_starts_with($value, 'DEVOLUCI')) {
        return 'DEVOLUCION';
    }

    return $value;
}

function mov_comentario_anulacion_tipo(?string $comentario): string
{
    $comentario = strtolower(trim((string)$comentario));
    if ($comentario === '' || stripos($comentario, 'anulaci') !== 0) {
        return '';
    }

    if (stripos($comentario, 'compra') !== false) {
        return 'ANULACION_COMPRA';
    }
    if (stripos($comentario, 'venta') !== false) {
        return 'ANULACION_VENTA';
    }

    return 'ANULACION';
}

function mov_tipo_display_from_row(string $tipoRaw, $refVenta, $refCompra, ?string $comentario): string
{
    $tipo = mov_tipo_norm($tipoRaw);
    $tipoDesdeComentario = mov_comentario_anulacion_tipo($comentario);

    if ($tipo === '' && $tipoDesdeComentario !== '') {
        $tipo = 'ANULACION';
    }

    if ($tipo === 'ANULACION') {
        if (!empty($refCompra)) {
            return 'ANULACION_COMPRA';
        }
        if (!empty($refVenta)) {
            return 'ANULACION_VENTA';
        }
        if ($tipoDesdeComentario !== '') {
            return $tipoDesdeComentario;
        }

        return 'ANULACION';
    }

    if ($tipo === 'DEVOLUCION') {
        return 'DEVOLUCION';
    }

    return $tipo;
}

function tipoLabel(string $tipo): string
{
    return match (mov_tipo_norm($tipo)) {
        'VENTA' => 'Venta',
        'COMPRA' => 'Compra',
        'AJUSTE_POSITIVO' => 'Ajuste +',
        'AJUSTE_NEGATIVO' => 'Ajuste -',
        'ANULACION_VENTA' => 'Anulacion venta',
        'ANULACION_COMPRA' => 'Anulacion compra',
        'ANULACION' => 'Anulacion',
        'DEVOLUCION' => 'Devolucion',
        default => mov_tipo_norm($tipo),
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
    $tipo = mov_tipo_norm($tipo);

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
    $tipoNormalizado = mov_tipo_norm($tipo);

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
$tipo = $tipoRaw !== '' ? mov_tipo_norm($tipoRaw) : '';
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
$condAnulacion = "{$exprTipo} IN ('ANULACION')";
$condDevolucion = "{$exprTipo} IN ('DEVOLUCION')";
$condAnulCompra = $hasRefCompra ? 'm.referencia_compra_id IS NOT NULL' : "m.comentario LIKE 'Anulacion compra%'";
$condAnulVenta = $hasRefVenta ? 'm.referencia_venta_id IS NOT NULL' : "m.comentario LIKE 'Anulacion venta%'";
$condAnulacion = "{$exprTipo} = 'ANULACION' OR {$exprTipo} LIKE 'ANULACI%'";
$condDevolucion = "{$exprTipo} = 'DEVOLUCION' OR {$exprTipo} LIKE 'DEVOLUCI%'";
$condAnulCompra = $hasRefCompra ? 'm.referencia_compra_id IS NOT NULL' : "m.comentario LIKE 'Anulaci% compra%'";
$condAnulVenta = $hasRefVenta ? 'm.referencia_venta_id IS NOT NULL' : "m.comentario LIKE 'Anulaci% venta%'";
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
        p.nombre LIKE :q_nombre
        OR p.codigo LIKE :q_codigo
        OR COALESCE(m.comentario, '') LIKE :q_comentario
        OR CAST(m.id AS CHAR) = :q_mov_id
        OR CAST({$selRefVenta} AS CHAR) = :q_ref_venta
        OR CAST({$selRefCompra} AS CHAR) = :q_ref_compra
    )";
    $params[':q_nombre'] = '%' . $search . '%';
    $params[':q_codigo'] = '%' . $search . '%';
    $params[':q_comentario'] = '%' . $search . '%';
    $params[':q_mov_id'] = $search;
    $params[':q_ref_venta'] = $search;
    $params[':q_ref_compra'] = $search;
}

if ($tipo !== '' && in_array($tipo, $allowedTipos, true)) {
    if ($tipo === 'ANULACION_COMPRA') {
        $whereParts[] = "({$condAnulacion})";
        $whereParts[] = $condAnulCompra;
    } elseif ($tipo === 'ANULACION_VENTA') {
        $whereParts[] = "({$condAnulacion})";
        $whereParts[] = $condAnulVenta;
    } elseif ($tipo === 'ANULACION') {
        $whereParts[] = "({$condAnulacion})";
    } elseif ($tipo === 'DEVOLUCION') {
        $whereParts[] = "({$condDevolucion})";
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
        $tipoDisplay = mov_tipo_display_from_row(
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
    $filterTags[] = [
        'label' => 'Flujo: ' . flujoLabel($flujo),
        'url' => urlWith(['flujo' => '', 'page' => 1]),
    ];
}
if ($search !== '') {
    $filterTags[] = [
        'label' => 'Busqueda: ' . $search,
        'url' => urlWith(['q' => '', 'page' => 1]),
    ];
}
if ($productoFiltroLabel !== '') {
    $filterTags[] = [
        'label' => 'Producto: ' . $productoFiltroLabel,
        'url' => urlWith(['producto_id' => '', 'page' => 1]),
    ];
}
if ($tipo !== '') {
    $filterTags[] = [
        'label' => 'Tipo: ' . tipoLabel($tipo),
        'url' => urlWith(['tipo' => '', 'page' => 1]),
    ];
}
if ($desde !== null || $hasta !== null) {
    $filterTags[] = [
        'label' => 'Periodo: ' . ($desde ?? '...') . ' a ' . ($hasta ?? '...'),
        'url' => urlWith(['desde' => '', 'hasta' => '', 'page' => 1]),
    ];
}
if ($perPage !== 50) {
    $filterTags[] = [
        'label' => 'Filas por pagina: ' . $perPage,
        'url' => urlWith(['per_page' => 50, 'page' => 1]),
    ];
}
if ($sort !== 'fecha' || $dir !== 'desc') {
    $filterTags[] = [
        'label' => 'Orden: ' . sortLabel($sort) . ' ' . ($dir === 'asc' ? 'asc' : 'desc'),
        'url' => urlWith(['sort' => 'fecha', 'dir' => 'desc', 'page' => 1]),
    ];
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
        SUM(CASE WHEN {$condDevolucion} THEN 1 ELSE 0 END) AS devoluciones,
        SUM(
            CASE
                WHEN ({$condAnulacion}) OR ((m.tipo = '' OR m.tipo IS NULL) AND m.comentario LIKE 'Anulaci%') THEN 1
                ELSE 0
            END
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
    <header class="page-header module-header">
        <div class="page-header-main module-header-main">
            <div class="module-header-hero">
                <span class="module-header-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
                        <path d="M7 7h10"/>
                        <path d="M7 12h10"/>
                        <path d="M7 17h6"/>
                        <path d="M5 4h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Z"/>
                    </svg>
                </span>
                <div class="module-header-copy">
                    <span class="page-eyebrow module-eyebrow">Trazabilidad operativa</span>
                    <h1 class="page-title">Movimientos</h1>
                    <p class="page-sub">Registro de ventas, compras, ajustes, anulaciones y devoluciones.</p>
                </div>
            </div>
        </div>

        <div class="page-actions module-header-actions">
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
        <button type="button" class="chip" data-range="month">Este mes</button>
    </div>

    <section class="mov-summary" aria-label="Resumen de filtros">
        <div class="mov-summary__top">
            <div class="mov-summary__headline">
                <strong><?= $totalRows ?></strong> resultado<?= $totalRows === 1 ? '' : 's' ?>
                <?php if ($totalRows > 0): ?>
                    <span class="muted">| <?= $fromRow ?>-<?= $toRow ?> de <?= $totalRows ?> | pagina <?= $page ?> de <?= $totalPages ?> | <?= $perPage ?> por pagina</span>
                <?php endif; ?>
            </div>

            <div class="mov-summary__actions">
                <?php if ($totalRows > 0): ?>
                    <a href="<?= h(urlWith(['export' => 'csv', 'page' => 1])) ?>" class="mov-summary__export">Exportar vista</a>
                <?php endif; ?>
                <?php if ($hasActiveFilters): ?>
                    <a href="movimientos.php" class="mov-summary__clear">Limpiar filtros</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($dateRangeAdjusted): ?>
            <p class="mov-summary__notice">Se invirtio el rango de fechas para usar primero la fecha mas antigua.</p>
        <?php endif; ?>

        <?php if ($filterTags): ?>
            <div class="mov-tags">
                <?php foreach ($filterTags as $tag): ?>
                    <a class="mov-tag mov-tag--clear" href="<?= h((string)$tag['url']) ?>">
                        <span><?= h((string)$tag['label']) ?></span>
                        <span aria-hidden="true">x</span>
                    </a>
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
                    <tr>
                        <td colspan="6" class="empty-cell">
                            No se encontraron movimientos para los filtros actuales.
                            <?php if ($hasActiveFilters): ?>
                                <a href="movimientos.php">Limpiar filtros</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($movs as $movimiento): ?>
                        <?php
                        $tipoDisplay = mov_tipo_display_from_row(
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
                            $rowHref = 'compra_detalle.php?id=' . (int)$movimiento['referencia_compra_id'] . '&origen=movimientos';
                            $rowLabel = 'Abrir compra #' . (int)$movimiento['referencia_compra_id'];
                        }
                        ?>
                        <tr
                            class="<?= h('row-' . strtolower(str_replace('_', '-', $tipoDisplay))) ?> <?= $rowHref !== '' ? 'mov-row-link' : '' ?>"
                            <?= $rowHref !== '' ? 'data-href="' . h($rowHref) . '" data-row-label="' . h($rowLabel) . '" tabindex="0" role="link"' : '' ?>
                        >
                            <td class="mono">
                                <?php $fechaRaw = (string)($movimiento['fecha'] ?? ''); ?>
                                <time datetime="<?= h($fechaRaw) ?>" title="<?= h($fechaRaw) ?>">
                                    <?= h(format_datetime($fechaRaw)) ?>
                                </time>
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
                                        <a class="mov-ref mov-ref--compra" href="compra_detalle.php?id=<?= (int)$movimiento['referencia_compra_id'] ?>&amp;origen=movimientos">Compra #<?= (int)$movimiento['referencia_compra_id'] ?></a>
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

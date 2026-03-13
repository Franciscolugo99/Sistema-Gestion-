<?php
// public/facturacion.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/db_schema.php';
require_once __DIR__ . '/../src/facturacion_lib.php';

require_login();
require_any_permission(['ver_facturacion', 'emitir_factura']);

$facturacionHabilitada = config_get($pdo, 'facturacion_habilitada', '0') === '1';
if (!$facturacionHabilitada) {
    header('Location: index.php');
    exit;
}

function validDateYmdStr(string $value): string
{
    if ($value === '') {
        return '';
    }

    $dt = DateTime::createFromFormat('Y-m-d', $value);
    return ($dt && $dt->format('Y-m-d') === $value) ? $value : '';
}

function urlWithFact(array $overrides = []): string
{
    $query = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    return 'facturacion.php' . ($query === [] ? '' : '?' . http_build_query($query));
}

function factLikeParam(string $value): string
{
    return '%' . addcslashes($value, "\\%_") . '%';
}

function factRangeUrl(string $desde, string $hasta): string
{
    return urlWithFact([
        'desde' => $desde,
        'hasta' => $hasta,
        'page' => 1,
    ]);
}


$desdeRaw = (string)($_GET['desde'] ?? '');
$hastaRaw = (string)($_GET['hasta'] ?? '');
$estado = trim((string)($_GET['estado'] ?? ''));
$tipoFiltro = strtoupper(trim((string)($_GET['tipo'] ?? '')));
$search = trim((string)($_GET['q'] ?? ''));
$clienteId = (int)($_GET['cliente_id'] ?? 0);
$ventaIdFiltro = (int)($_GET['venta_id'] ?? 0);
$desde = validDateYmdStr($desdeRaw);
$hasta = validDateYmdStr($hastaRaw);

if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
    [$desde, $hasta] = [$hasta, $desde];
}

$perPage = (int)($_GET['per_page'] ?? 50);
if (!in_array($perPage, [20, 50, 100], true)) {
    $perPage = 50;
}
$page = max(1, (int)($_GET['page'] ?? 1));
$allowedEstados = ['EMITIDA', 'ANULADA'];
$today = new DateTimeImmutable('today');
$quickRanges = [
    [
        'key' => 'today',
        'label' => 'Hoy',
        'desde' => $today->format('Y-m-d'),
        'hasta' => $today->format('Y-m-d'),
    ],
    [
        'key' => 'week',
        'label' => 'Esta semana',
        'desde' => $today->modify('monday this week')->format('Y-m-d'),
        'hasta' => $today->modify('sunday this week')->format('Y-m-d'),
    ],
    [
        'key' => 'month',
        'label' => 'Este mes',
        'desde' => $today->modify('first day of this month')->format('Y-m-d'),
        'hasta' => $today->modify('last day of this month')->format('Y-m-d'),
    ],
    [
        'key' => 'prev_month',
        'label' => 'Mes anterior',
        'desde' => $today->modify('first day of last month')->format('Y-m-d'),
        'hasta' => $today->modify('last day of last month')->format('Y-m-d'),
    ],
];

$facturas = [];
$clientes = flus_facturacion_clientes_disponibles($pdo);
$tiposDisponibles = [];
$avisos = [];
$totalRows = 0;
$totalPages = 1;
$fromRow = 0;
$toRow = 0;
$hasActiveFilters = false;
$filterTags = [];
$stats = [
    'total_docs' => 0,
    'emitidas' => 0,
    'anuladas' => 0,
    'total_emitido' => 0.0,
    'ticket_promedio' => 0.0,
    'cae_real' => 0,
    'tipo_a' => 0,
    'tipo_b' => 0,
    'sin_cae_real' => 0,
    'cae_por_vencer' => 0,
];

$modoFacturacion = 'demo';
$configFact = flus_facturacion_config_activa($pdo);
if ($configFact) {
    $modoFacturacion = flus_facturacion_modo_actual($configFact);
}
$modoFacturacionLabel = flus_facturacion_modo_label($modoFacturacion);

if (!flus_table_exists($pdo, 'facturas')) {
    $avisos[] = 'La tabla de facturas no existe todavia. Aplica la migracion de facturacion para ver el historial.';
} else {
    $fechaCol = flus_first_existing_column($pdo, 'facturas', ['creado_en', 'fecha']);
    $estadoCol = flus_column_exists($pdo, 'facturas', 'estado');
    $clienteIdCol = flus_column_exists($pdo, 'facturas', 'cliente_id');
    $ventaIdCol = flus_column_exists($pdo, 'facturas', 'venta_id');
    $tipoCol = flus_column_exists($pdo, 'facturas', 'tipo');
    $numeroCol = flus_column_exists($pdo, 'facturas', 'numero');
    $puntoVentaCol = flus_column_exists($pdo, 'facturas', 'punto_venta');
    $caeCol = flus_column_exists($pdo, 'facturas', 'cae');
    $caeVtoCol = flus_column_exists($pdo, 'facturas', 'cae_vto');
    $modoCol = flus_column_exists($pdo, 'facturas', 'modo');
    $joinClientes = $clienteIdCol && flus_table_exists($pdo, 'clientes');

    $fechaExpr = $fechaCol ? 'f.`' . $fechaCol . '`' : 'NULL';
    $tipoExpr = $tipoCol ? 'f.`tipo`' : "''";
    $puntoVentaExpr = $puntoVentaCol ? 'f.`punto_venta`' : 'NULL';
    $numeroExpr = $numeroCol ? 'f.`numero`' : 'NULL';
    $totalExpr = flus_column_exists($pdo, 'facturas', 'total') ? 'f.`total`' : '0';
    $estadoExpr = $estadoCol ? 'f.`estado`' : "'EMITIDA'";
    $ventaIdExpr = $ventaIdCol ? 'f.`venta_id`' : 'NULL';
    $caeExpr = $caeCol ? 'f.`cae`' : 'NULL';
    $caeVtoExpr = $caeVtoCol ? 'f.`cae_vto`' : 'NULL';
    $modoExpr = $modoCol ? 'f.`modo`' : 'NULL';
    $clienteNombreExpr = $joinClientes
        ? (flus_column_exists($pdo, 'clientes', 'nombre') ? 'c.`nombre`' : 'CONCAT("Cliente #", c.id)')
        : 'NULL';
    $clienteCuitExpr = $joinClientes && flus_column_exists($pdo, 'clientes', 'cuit') ? 'c.`cuit`' : 'NULL';
    $caeVtoSql = $caeVtoCol
        ? "CASE
              WHEN CHAR_LENGTH(TRIM(f.`cae_vto`)) = 8 THEN STR_TO_DATE(f.`cae_vto`, '%Y%m%d')
              ELSE STR_TO_DATE(f.`cae_vto`, '%Y-%m-%d')
           END"
        : 'NULL';

    if ($tipoCol) {
        $stTipos = $pdo->query("SELECT DISTINCT UPPER(TRIM(tipo)) AS tipo FROM facturas WHERE tipo IS NOT NULL AND TRIM(tipo) <> '' ORDER BY tipo");
        $tiposDisponibles = $stTipos ? array_values(array_filter(array_map(static fn(array $row): string => trim((string)($row['tipo'] ?? '')), $stTipos->fetchAll(PDO::FETCH_ASSOC) ?: []))) : [];
    }

    $where = ['1=1'];
    $params = [];

    if ($desde !== '' && $fechaCol) {
        $where[] = $fechaExpr . ' >= :desde';
        $params[':desde'] = $desde . ' 00:00:00';
    }
    if ($hasta !== '' && $fechaCol) {
        $where[] = $fechaExpr . ' <= :hasta';
        $params[':hasta'] = $hasta . ' 23:59:59';
    }
    if (($desde !== '' || $hasta !== '') && !$fechaCol) {
        $avisos[] = 'Esta instalacion no tiene una fecha de factura estandar, por eso el filtro por fecha no se pudo aplicar.';
    }

    if ($estado !== '' && in_array($estado, $allowedEstados, true) && $estadoCol) {
        $where[] = 'f.`estado` = :estado';
        $params[':estado'] = $estado;
    }

    if ($tipoFiltro !== '' && $tipoCol) {
        if (in_array($tipoFiltro, $tiposDisponibles, true)) {
            $where[] = 'UPPER(TRIM(f.`tipo`)) = :tipo';
            $params[':tipo'] = $tipoFiltro;
        } else {
            $tipoFiltro = '';
        }
    }

    if ($clienteId > 0 && $clienteIdCol) {
        $where[] = 'f.`cliente_id` = :cliente_id';
        $params[':cliente_id'] = $clienteId;
    }

    if ($ventaIdFiltro > 0 && $ventaIdCol) {
        $where[] = 'f.`venta_id` = :venta_id';
        $params[':venta_id'] = $ventaIdFiltro;
    } elseif ($ventaIdFiltro > 0) {
        $avisos[] = 'Esta instalacion no permite filtrar por venta porque la tabla facturas no tiene venta_id.';
    }

    if ($search !== '') {
        $searchWhere = [];
        $params[':search_like'] = factLikeParam($search);

        if ($joinClientes) {
            $searchWhere[] = "{$clienteNombreExpr} LIKE :search_like ESCAPE '\\\\'";
            if ($clienteCuitExpr !== 'NULL') {
                $searchWhere[] = "{$clienteCuitExpr} LIKE :search_like ESCAPE '\\\\'";
            }
        }
        if ($caeCol) {
            $searchWhere[] = "f.`cae` LIKE :search_like ESCAPE '\\\\'";
        }
        if ($tipoCol) {
            $searchWhere[] = "f.`tipo` LIKE :search_like ESCAPE '\\\\'";
        }

        if ($numeroCol && ctype_digit($search)) {
            $searchWhere[] = 'f.`numero` = :search_numero';
            $params[':search_numero'] = (int)$search;
        }
        if ($ventaIdCol && ctype_digit($search)) {
            $searchWhere[] = 'f.`venta_id` = :search_venta_id';
            $params[':search_venta_id'] = (int)$search;
        }
        if ($puntoVentaCol && $numeroCol && preg_match('/^\s*(\d{1,4})\D+(\d{1,8})\s*$/', $search, $m) === 1) {
            $searchWhere[] = '(f.`punto_venta` = :search_pv AND f.`numero` = :search_comp_num)';
            $params[':search_pv'] = (int)$m[1];
            $params[':search_comp_num'] = (int)$m[2];
        }

        if ($searchWhere !== []) {
            $where[] = '(' . implode(' OR ', $searchWhere) . ')';
        }
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $joinSql = $joinClientes ? 'LEFT JOIN clientes c ON c.id = f.cliente_id' : '';
    $orderSql = $fechaCol ? $fechaExpr . ' DESC, f.id DESC' : 'f.id DESC';

    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        $sqlExport = "
            SELECT
                {$fechaExpr} AS fecha,
                {$tipoExpr} AS tipo,
                {$puntoVentaExpr} AS punto_venta,
                {$numeroExpr} AS numero,
                {$clienteNombreExpr} AS cliente_nombre,
                {$clienteCuitExpr} AS cliente_cuit,
                {$totalExpr} AS total,
                {$estadoExpr} AS estado,
                {$ventaIdExpr} AS venta_id,
                {$caeExpr} AS cae,
                {$caeVtoExpr} AS cae_vto,
                {$modoExpr} AS modo
            FROM facturas f
            {$joinSql}
            {$whereSql}
            ORDER BY {$orderSql}
            LIMIT " . flus_export_limit();

        $stExport = $pdo->prepare($sqlExport);
        $stExport->execute($params);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="facturacion_' . date('Y-m-d_His') . '.csv"');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Fecha', 'Tipo', 'Punto de venta', 'Numero', 'Cliente', 'CUIT', 'Total', 'Estado', 'Venta', 'CAE', 'CAE vto', 'Modo'], ';');

        foreach ($stExport->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            fputcsv($out, [
                (string)($row['fecha'] ?? ''),
                (string)($row['tipo'] ?? ''),
                $row['punto_venta'] !== null ? str_pad((string)(int)$row['punto_venta'], 4, '0', STR_PAD_LEFT) : '',
                $row['numero'] !== null ? str_pad((string)(int)$row['numero'], 8, '0', STR_PAD_LEFT) : '',
                (string)($row['cliente_nombre'] ?? 'Consumidor Final'),
                (string)($row['cliente_cuit'] ?? ''),
                number_format((float)($row['total'] ?? 0), 2, ',', ''),
                (string)($row['estado'] ?? 'EMITIDA'),
                (string)($row['venta_id'] ?? ''),
                (string)($row['cae'] ?? ''),
                (string)($row['cae_vto'] ?? ''),
                $modoCol ? flus_facturacion_modo_label((string)($row['modo'] ?? 'demo')) : '',
            ], ';');
        }

        fclose($out);
        exit;
    }

    $sqlCount = "
        SELECT COUNT(*)
        FROM facturas f
        {$joinSql}
        {$whereSql}
    ";
    $stCount = $pdo->prepare($sqlCount);
    $stCount->execute($params);
    $totalRows = (int)$stCount->fetchColumn();

    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $fromRow = $totalRows > 0 ? $offset + 1 : 0;
    $toRow = min($offset + $perPage, $totalRows);

    $sqlStats = "
        SELECT
            COUNT(*) AS total_docs,
            SUM(CASE WHEN {$estadoExpr} = 'ANULADA' THEN 1 ELSE 0 END) AS anuladas,
            SUM(CASE WHEN {$estadoExpr} = 'ANULADA' THEN 0 ELSE 1 END) AS emitidas,
            COALESCE(SUM(CASE WHEN {$estadoExpr} = 'ANULADA' THEN 0 ELSE {$totalExpr} END), 0) AS total_emitido,
            AVG(CASE WHEN {$estadoExpr} = 'ANULADA' THEN NULL ELSE {$totalExpr} END) AS ticket_promedio,
            " . ($caeCol
                ? "SUM(CASE WHEN f.`cae` IS NOT NULL AND TRIM(f.`cae`) <> '' AND f.`cae` NOT LIKE 'DEMO%' THEN 1 ELSE 0 END)"
                : '0') . " AS cae_real,
            " . ($tipoCol
                ? "SUM(CASE WHEN UPPER(TRIM(f.`tipo`)) LIKE '%A' THEN 1 ELSE 0 END)"
                : '0') . " AS tipo_a,
            " . ($tipoCol
                ? "SUM(CASE WHEN UPPER(TRIM(f.`tipo`)) LIKE '%B' THEN 1 ELSE 0 END)"
                : '0') . " AS tipo_b,
            " . (($modoCol && $caeCol)
                ? "SUM(CASE WHEN COALESCE(f.`modo`, 'demo') <> 'demo' AND (f.`cae` IS NULL OR TRIM(f.`cae`) = '' OR f.`cae` LIKE 'DEMO%') THEN 1 ELSE 0 END)"
                : '0') . " AS sin_cae_real,
            " . ($caeCol && $caeVtoCol
                ? "SUM(CASE
                        WHEN {$estadoExpr} <> 'ANULADA'
                         AND f.`cae` IS NOT NULL
                         AND TRIM(f.`cae`) <> ''
                         AND f.`cae` NOT LIKE 'DEMO%'
                         AND {$caeVtoSql} BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 5 DAY)
                        THEN 1 ELSE 0 END)"
                : '0') . " AS cae_por_vencer
        FROM facturas f
        {$joinSql}
        {$whereSql}
    ";
    $stStats = $pdo->prepare($sqlStats);
    $stStats->execute($params);
    $statsRow = $stStats->fetch(PDO::FETCH_ASSOC) ?: [];
    $stats = [
        'total_docs' => (int)($statsRow['total_docs'] ?? 0),
        'emitidas' => (int)($statsRow['emitidas'] ?? 0),
        'anuladas' => (int)($statsRow['anuladas'] ?? 0),
        'total_emitido' => (float)($statsRow['total_emitido'] ?? 0),
        'ticket_promedio' => (float)($statsRow['ticket_promedio'] ?? 0),
        'cae_real' => (int)($statsRow['cae_real'] ?? 0),
        'tipo_a' => (int)($statsRow['tipo_a'] ?? 0),
        'tipo_b' => (int)($statsRow['tipo_b'] ?? 0),
        'sin_cae_real' => (int)($statsRow['sin_cae_real'] ?? 0),
        'cae_por_vencer' => (int)($statsRow['cae_por_vencer'] ?? 0),
    ];

    $sqlList = "
        SELECT
            f.id,
            {$fechaExpr} AS fecha,
            {$tipoExpr} AS tipo,
            {$puntoVentaExpr} AS punto_venta,
            {$numeroExpr} AS numero,
            {$totalExpr} AS total,
            {$estadoExpr} AS estado,
            {$clienteNombreExpr} AS cliente_nombre,
            {$clienteCuitExpr} AS cliente_cuit,
            {$ventaIdExpr} AS venta_id,
            {$caeExpr} AS cae,
            {$caeVtoExpr} AS cae_vto,
            {$modoExpr} AS modo
        FROM facturas f
        {$joinSql}
        {$whereSql}
        ORDER BY {$orderSql}
        LIMIT :limit OFFSET :offset
    ";

    $st = $pdo->prepare($sqlList);
    foreach ($params as $key => $value) {
        $st->bindValue($key, $value);
    }
    $st->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $st->bindValue(':offset', $offset, PDO::PARAM_INT);
    $st->execute();
    $facturas = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$hasActiveFilters = $search !== '' || $estado !== '' || $tipoFiltro !== '' || $clienteId > 0 || $ventaIdFiltro > 0 || $desde !== '' || $hasta !== '';
foreach ($quickRanges as &$range) {
    $range['active'] = $desde === $range['desde'] && $hasta === $range['hasta'];
    $range['url'] = factRangeUrl($range['desde'], $range['hasta']);
}
unset($range);
if ($search !== '') {
    $filterTags[] = ['label' => 'Busqueda: ' . $search, 'url' => urlWithFact(['q' => null, 'page' => 1])];
}
if ($tipoFiltro !== '') {
    $filterTags[] = ['label' => 'Tipo: ' . $tipoFiltro, 'url' => urlWithFact(['tipo' => null, 'page' => 1])];
}
if ($estado !== '') {
    $filterTags[] = ['label' => 'Estado: ' . $estado, 'url' => urlWithFact(['estado' => null, 'page' => 1])];
}
if ($clienteId > 0) {
    $clienteLabel = 'Cliente #' . $clienteId;
    foreach ($clientes as $cli) {
        if ((int)($cli['id'] ?? 0) === $clienteId) {
            $clienteLabel = (string)($cli['nombre'] ?? $clienteLabel);
            break;
        }
    }
    $filterTags[] = ['label' => 'Cliente: ' . $clienteLabel, 'url' => urlWithFact(['cliente_id' => null, 'page' => 1])];
}
if ($ventaIdFiltro > 0) {
    $filterTags[] = ['label' => 'Venta #' . $ventaIdFiltro, 'url' => urlWithFact(['venta_id' => null, 'page' => 1])];
}
if ($desde !== '' || $hasta !== '') {
    $periodoLabel = $desde !== '' && $hasta !== ''
        ? 'Periodo: ' . $desde . ' a ' . $hasta
        : ($desde !== '' ? 'Desde: ' . $desde : 'Hasta: ' . $hasta);
    $filterTags[] = ['label' => $periodoLabel, 'url' => urlWithFact(['desde' => null, 'hasta' => null, 'page' => 1])];
}

$pageTitle = 'Facturacion';
$currentSection = 'facturacion';
$extraCss = ['assets/css/facturacion.css?v=10'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap facturacion-page">
  <div class="panel fact-panel">
    <header class="page-header module-header">
      <div class="module-header-main">
        <span class="module-eyebrow">Operacion fiscal</span>
        <h1 class="page-title module-title">Facturacion</h1>
        <p class="page-sub module-subtitle">
          Historial fiscal de FLUS para comprobar emisiones, CAE y documentos vinculados a ventas o carga manual.
          <?php if ($modoFacturacion === 'demo'): ?>
            <span class="modo-badge modo-demo" title="Las facturas generadas no se envian a ARCA">Modo demo</span>
          <?php elseif ($modoFacturacion === 'homologacion'): ?>
            <span class="modo-badge modo-homo" title="Conectado a ARCA testing">Homologacion</span>
          <?php else: ?>
            <span class="modo-badge modo-prod" title="Conectado a AFIP/ARCA produccion">Produccion</span>
          <?php endif; ?>
        </p>
      </div>

      <div class="promo-actions-top module-header-actions">
        <?php if (function_exists('user_has_permission') && user_has_permission('administrar_config')): ?>
          <a href="facturacion_config.php" class="v-btn v-btn--outline" title="Configuracion de facturacion">
            Configuracion
          </a>
        <?php endif; ?>
        <a href="factura_manual.php" class="v-btn v-btn--primary">
          + Factura manual
        </a>
      </div>
    </header>

    <section class="fact-overview">
      <div class="fact-overview__main">
        <span class="fact-overview__eyebrow">Operacion fiscal</span>
        <h2 class="fact-overview__title">Historial fiscal</h2>
        <p class="fact-overview__text">
          Busca por cliente, venta, CAE o comprobante y exporta la vista filtrada cuando necesites control o soporte.
        </p>
      </div>
      <div class="fact-overview__meta">
        <div class="fact-overview__item">
          <span>Modo activo</span>
          <strong><?= h($modoFacturacionLabel) ?></strong>
        </div>
        <div class="fact-overview__item">
          <span>Resultados</span>
          <strong><?= number_format($totalRows) ?></strong>
        </div>
        <div class="fact-overview__item">
          <span>Por pagina</span>
          <strong><?= (int)$perPage ?></strong>
        </div>
      </div>
    </section>

    <section class="fact-kpi-grid" aria-label="Resumen de facturacion">
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Total facturado</span>
        <strong class="fact-kpi-card__value"><?= money_ar($stats['total_emitido']) ?></strong>
        <span class="fact-kpi-card__help"><?= number_format($stats['emitidas']) ?> comprobante<?= $stats['emitidas'] === 1 ? '' : 's' ?> emitido<?= $stats['emitidas'] === 1 ? '' : 's' ?></span>
      </article>
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Ticket promedio</span>
        <strong class="fact-kpi-card__value"><?= money_ar($stats['ticket_promedio']) ?></strong>
        <span class="fact-kpi-card__help">Promedio sobre facturas no anuladas</span>
      </article>
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Facturas A / B</span>
        <strong class="fact-kpi-card__value"><?= number_format($stats['tipo_a']) ?> <span class="fact-kpi-card__value-split">/ <?= number_format($stats['tipo_b']) ?></span></strong>
        <span class="fact-kpi-card__help"><?= number_format($stats['sin_cae_real']) ?> sin CAE real</span>
      </article>
      <article class="fact-kpi-card fact-kpi-card--accent">
        <span class="fact-kpi-card__label">CAE por vencer</span>
        <strong class="fact-kpi-card__value"><?= number_format($stats['cae_por_vencer']) ?></strong>
        <span class="fact-kpi-card__help">En los proximos 5 dias</span>
      </article>
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Emitidas / anuladas</span>
        <strong class="fact-kpi-card__value"><?= number_format($stats['emitidas']) ?> <span class="fact-kpi-card__value-split">/ <?= number_format($stats['anuladas']) ?></span></strong>
        <span class="fact-kpi-card__help">Balance operativo de la vista</span>
      </article>
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">CAE real</span>
        <strong class="fact-kpi-card__value"><?= number_format($stats['cae_real']) ?></strong>
        <span class="fact-kpi-card__help">Documentos con autorizacion fiscal real</span>
      </article>
    </section>

    <?php foreach ($avisos as $aviso): ?>
      <div class="alert alert-error" style="margin-bottom:12px;"><?= h($aviso) ?></div>
    <?php endforeach; ?>

    <form method="get" class="filters fact-filters">
      <div class="fact-filters__top">
        <div class="fact-filters__quick">
          <span class="fact-filters__quick-label">Periodo rapido:</span>
          <?php foreach ($quickRanges as $range): ?>
            <a href="<?= h((string)$range['url']) ?>" class="fact-chip <?= !empty($range['active']) ? 'is-active' : '' ?>">
              <?= h((string)$range['label']) ?>
            </a>
          <?php endforeach; ?>
        </div>
        <?php if ($totalRows > 0): ?>
          <a href="<?= h(urlWithFact(['export' => 'csv', 'page' => 1])) ?>" class="btn btn-secondary fact-filters__export">Exportar CSV</a>
        <?php endif; ?>
      </div>

      <div class="filters-left">
        <input type="text" name="q" value="<?= h($search) ?>" placeholder="Buscar cliente, CAE o comprobante">
        <input type="number" name="venta_id" min="1" step="1" value="<?= $ventaIdFiltro > 0 ? (int)$ventaIdFiltro : '' ?>" placeholder="Venta #">

        <select name="cliente_id">
          <option value="">Todos los clientes</option>
          <?php foreach ($clientes as $cli): ?>
            <option value="<?= (int)$cli['id'] ?>" <?= $clienteId === (int)$cli['id'] ? 'selected' : '' ?>>
              <?= h((string)($cli['nombre'] ?? 'Cliente')) ?><?= !empty($cli['cuit']) ? ' (' . h((string)$cli['cuit']) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>

        <select name="estado">
          <option value="">Todos los estados</option>
          <option value="EMITIDA" <?= $estado === 'EMITIDA' ? 'selected' : '' ?>>Emitidas</option>
          <option value="ANULADA" <?= $estado === 'ANULADA' ? 'selected' : '' ?>>Anuladas</option>
        </select>

        <?php if ($tiposDisponibles !== []): ?>
          <select name="tipo">
            <option value="">Todos los tipos</option>
            <?php foreach ($tiposDisponibles as $tipo): ?>
              <option value="<?= h($tipo) ?>" <?= $tipoFiltro === $tipo ? 'selected' : '' ?>><?= h($tipo) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
      </div>

      <div class="filters-right">
        <input type="date" name="desde" value="<?= h($desde) ?>">
        <input type="date" name="hasta" value="<?= h($hasta) ?>">

        <select name="per_page">
          <?php foreach ([20, 50, 100] as $n): ?>
            <option value="<?= $n ?>" <?= $perPage === $n ? 'selected' : '' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>

        <button class="btn btn-filter" type="submit">Aplicar</button>
        <a href="facturacion.php" class="btn btn-secondary">Limpiar</a>
      </div>
    </form>

    <section class="fact-summary-bar" aria-label="Resumen de resultados">
      <div class="fact-summary-bar__top">
        <div class="fact-summary-bar__headline">
          <strong><?= number_format($totalRows) ?></strong> resultado<?= $totalRows === 1 ? '' : 's' ?>
          <?php if ($totalRows > 0): ?>
            <span class="muted">| <?= $fromRow ?>-<?= $toRow ?> de <?= $totalRows ?> | pagina <?= $page ?> de <?= $totalPages ?> | <?= $perPage ?> por pagina</span>
          <?php endif; ?>
        </div>

        <div class="fact-summary-bar__actions">
          <?php if ($totalRows > 0): ?>
            <a href="<?= h(urlWithFact(['export' => 'csv', 'page' => 1])) ?>" class="fact-summary-bar__export">Exportar vista</a>
          <?php endif; ?>
          <?php if ($hasActiveFilters): ?>
            <a href="facturacion.php" class="fact-summary-bar__clear">Limpiar filtros</a>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($filterTags !== []): ?>
        <div class="fact-filter-tags">
          <?php foreach ($filterTags as $tag): ?>
            <a class="fact-filter-tag" href="<?= h((string)$tag['url']) ?>">
              <span><?= h((string)$tag['label']) ?></span>
              <span aria-hidden="true">x</span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <?= render_pagination($page, $totalPages, $_GET, true, $totalRows, $fromRow, $toRow, ['export']) ?>

    <?php if ($facturas === []): ?>
      <section class="fact-empty-state">
        <div class="fact-empty-state__icon">F</div>
        <h3>No hay facturas para esta vista</h3>
        <p>
          <?= $hasActiveFilters
              ? 'Prueba aflojando filtros o buscando por cliente, CAE, numero de comprobante o venta.'
              : 'Todavia no hay comprobantes para mostrar en este historial.' ?>
        </p>
        <div class="fact-empty-state__actions">
          <?php if ($hasActiveFilters): ?>
            <a href="facturacion.php" class="btn btn-secondary">Quitar filtros</a>
          <?php endif; ?>
          <a href="factura_manual.php" class="btn btn-primary">Crear factura manual</a>
        </div>
      </section>
    <?php else: ?>
      <div class="table-wrapper">
        <table class="mov-table fact-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Comprobante</th>
              <th>Cliente</th>
              <th>CAE</th>
              <th class="t-right">Total</th>
              <th>Estado</th>
              <th>Venta</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($facturas as $factura): ?>
            <?php
              $clienteNombre = trim((string)($factura['cliente_nombre'] ?? '')) ?: 'Consumidor Final';
              $clienteCuit = trim((string)($factura['cliente_cuit'] ?? ''));
              $numero = $factura['numero'] !== null ? (int)$factura['numero'] : null;
              $puntoVenta = $factura['punto_venta'] !== null ? (int)$factura['punto_venta'] : null;
              $fechaLista = trim((string)($factura['fecha'] ?? ''));
              $fechaTs = $fechaLista !== '' ? strtotime($fechaLista) : false;
              $fechaMostrar = $fechaTs !== false ? date('d/m/Y H:i', $fechaTs) : ($fechaLista !== '' ? $fechaLista : '-');
              $estadoFila = strtoupper(trim((string)($factura['estado'] ?? 'EMITIDA')));
              $modoFilaRaw = trim((string)($factura['modo'] ?? ''));
              $modoFila = $modoFilaRaw !== '' ? flus_facturacion_normalizar_modo($modoFilaRaw) : '';
              $cae = trim((string)($factura['cae'] ?? ''));
              $caeVtoRaw = trim((string)($factura['cae_vto'] ?? ''));
              $tieneCaeReal = $cae !== '' && !str_starts_with(strtoupper($cae), 'DEMO');
              if ($caeVtoRaw !== '' && preg_match('/^\d{8}$/', $caeVtoRaw) === 1) {
                  $dtCaeVto = DateTime::createFromFormat('Ymd', $caeVtoRaw);
                  $caeVtoTs = $dtCaeVto ? $dtCaeVto->getTimestamp() : false;
              } else {
                  $caeVtoTs = $caeVtoRaw !== '' ? strtotime($caeVtoRaw) : false;
              }
              $caeVtoLabel = $caeVtoTs !== false ? date('d/m/Y', $caeVtoTs) : ($caeVtoRaw !== '' ? $caeVtoRaw : '');
              $comprobanteLabel = trim((string)($factura['tipo'] ?? 'Factura'));
              if ($numero !== null && $puntoVenta !== null) {
                  $comprobanteLabel .= ' ' . sprintf('%04d-%08d', $puntoVenta, $numero);
              } elseif ($numero !== null) {
                  $comprobanteLabel .= ' #' . $numero;
              } else {
                  $comprobanteLabel .= ' (sin numero)';
              }
            ?>
            <tr>
              <td class="mono">
                <div><?= h($fechaMostrar) ?></div>
                <div class="fact-cell-sub">ID #<?= (int)$factura['id'] ?></div>
              </td>
              <td>
                <div class="fact-doc-title"><?= h($comprobanteLabel) ?></div>
                <div class="fact-doc-meta">
                  <?php if ($modoFila !== ''): ?>
                    <span class="fact-inline-badge <?= $modoFila === 'demo' ? 'fact-inline-badge--demo' : 'fact-inline-badge--real' ?>">
                      <?= h($modoFila === 'demo' ? 'Demo' : 'ARCA') ?>
                    </span>
                  <?php endif; ?>
                  <?php if ($cae !== ''): ?>
                    <span class="fact-cell-sub">CAE <?= h($cae) ?><?= !$tieneCaeReal ? ' (demo)' : '' ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="fact-doc-title"><?= h($clienteNombre) ?></div>
                <div class="fact-cell-sub"><?= $clienteCuit !== '' ? h($clienteCuit) : 'Sin documento fiscal cargado' ?></div>
              </td>
              <td>
                <?php if ($cae !== ''): ?>
                  <div class="fact-doc-title fact-doc-title--small"><?= h($cae) ?></div>
                  <div class="fact-cell-sub"><?= $caeVtoLabel !== '' ? 'Vto. ' . h($caeVtoLabel) : ($tieneCaeReal ? 'Sin vencimiento visible' : 'Modo demo') ?></div>
                <?php else: ?>
                  <span class="fact-inline-badge fact-inline-badge--warn">Sin CAE</span>
                <?php endif; ?>
              </td>
              <td class="t-right">
                <div class="fact-doc-title"><?= money_ar((float)($factura['total'] ?? 0)) ?></div>
              </td>
              <td>
                <span class="fact-status-badge <?= $estadoFila === 'ANULADA' ? 'fact-status-badge--danger' : 'fact-status-badge--ok' ?>">
                  <?= h($estadoFila) ?>
                </span>
              </td>
              <td>
                <?php if (!empty($factura['venta_id'])): ?>
                  <a href="venta_detalle.php?id=<?= (int)$factura['venta_id'] ?>" class="fact-link-inline">Venta #<?= (int)$factura['venta_id'] ?></a>
                <?php else: ?>
                  <span class="fact-cell-sub">Sin venta vinculada</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="fact-row-actions">
                  <a href="factura_ver.php?id=<?= (int)$factura['id'] ?>" class="btn-mini">Ver</a>
                  <a href="factura_pdf.php?id=<?= (int)$factura['id'] ?>" class="btn-mini btn-mini--ghost">PDF</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="fact-table-footer">
        <div class="fact-table-footer__item">Mostrando <?= $fromRow ?>-<?= $toRow ?> de <?= number_format($totalRows) ?> comprobantes</div>
        <div class="fact-table-footer__item">Total filtrado: <strong><?= money_ar($stats['total_emitido']) ?></strong></div>
        <div class="fact-table-footer__item">CAE por vencer: <strong><?= number_format($stats['cae_por_vencer']) ?></strong></div>
      </div>

      <?= render_pagination($page, $totalPages, $_GET, false, $totalRows, $fromRow, $toRow, ['export']) ?>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

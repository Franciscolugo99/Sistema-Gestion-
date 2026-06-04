<?php
// public/facturacion.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/db_schema.php';
require_once __DIR__ . '/../src/facturacion_lib.php';
require_once __DIR__ . '/../src/facturacion_panel_lib.php';

require_login();
require_any_permission(['ver_facturacion', 'emitir_factura']);
$canViewClientes = function_exists('user_has_permission') && user_has_permission('ver_clientes');

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
$estadoFiscalFiltro = strtoupper(trim((string)($_GET['estado_fiscal'] ?? '')));
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
$allowedEstadosFiscales = ['NO_APLICA', 'PENDIENTE_ENVIO', 'ERROR_TRANSITORIO', 'ERROR_POST_ARCA', 'AUTORIZADA', 'RECUPERADA', 'RECHAZADA'];
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

$clientes = flus_facturacion_clientes_disponibles($pdo);
$hasActiveFilters = false;
$filterTags = [];
$panel = flus_facturacion_panel_read($pdo, [
    'desde' => $desde,
    'hasta' => $hasta,
    'estado' => $estado,
    'estado_fiscal' => $estadoFiscalFiltro,
    'tipo' => $tipoFiltro,
    'search' => $search,
    'cliente_id' => $clienteId,
    'venta_id' => $ventaIdFiltro,
    'per_page' => $perPage,
    'page' => $page,
]);

$panelFilters = $panel['filters'];
$search = (string)($panelFilters['search'] ?? $search);
$estado = (string)($panelFilters['estado'] ?? $estado);
$estadoFiscalFiltro = (string)($panelFilters['estado_fiscal'] ?? $estadoFiscalFiltro);
$tipoFiltro = (string)($panelFilters['tipo'] ?? $tipoFiltro);
$clienteId = (int)($panelFilters['cliente_id'] ?? $clienteId);
$ventaIdFiltro = (int)($panelFilters['venta_id'] ?? $ventaIdFiltro);
$perPage = (int)($panelFilters['per_page'] ?? $perPage);
$page = (int)($panelFilters['page'] ?? $page);

$facturas = $panel['facturas'];
$tiposDisponibles = $panel['tipos_disponibles'];
$avisos = $panel['avisos'];
$totalRows = (int)$panel['total_rows'];
$totalPages = (int)$panel['total_pages'];
$fromRow = (int)$panel['from_row'];
$toRow = (int)$panel['to_row'];
$stats = $panel['stats'];
$incidencias = $panel['incidencias'];
$modoFacturacion = (string)$panel['modo_facturacion'];
$modoFacturacionLabel = (string)$panel['modo_facturacion_label'];
$incidenciasVisibles = (int)($incidencias['pendientes'] ?? 0)
    + (int)($incidencias['transitorios'] ?? 0)
    + (int)($incidencias['post_arca'] ?? 0)
    + (int)($incidencias['rechazadas'] ?? 0);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportRows = flus_facturacion_panel_export_rows($pdo, $panel['plan']);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="facturacion_' . date('Y-m-d_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Fecha', 'Tipo', 'Punto de venta', 'Numero', 'Cliente', 'CUIT', 'Total', 'Estado', 'Estado fiscal', 'Venta', 'CAE', 'CAE vto', 'Modo'], ';');

    foreach ($exportRows as $row) {
        fputcsv($out, [
            (string)($row['fecha'] ?? ''),
            (string)($row['tipo'] ?? ''),
            $row['punto_venta'] !== null ? str_pad((string)(int)$row['punto_venta'], 4, '0', STR_PAD_LEFT) : '',
            $row['numero'] !== null ? str_pad((string)(int)$row['numero'], 8, '0', STR_PAD_LEFT) : '',
            (string)($row['cliente_nombre'] ?? 'Consumidor Final'),
            (string)($row['cliente_cuit'] ?? ''),
            number_format((float)($row['total'] ?? 0), 2, ',', ''),
            (string)($row['estado'] ?? 'EMITIDA'),
            (string)($row['estado_fiscal'] ?? 'NO_APLICA'),
            (string)($row['venta_id'] ?? ''),
            (string)($row['cae'] ?? ''),
            (string)($row['cae_vto'] ?? ''),
            flus_facturacion_modo_label((string)($row['modo'] ?? 'demo')),
        ], ';');
    }

    fclose($out);
    exit;
}

$hasActiveFilters = $search !== '' || $estado !== '' || $estadoFiscalFiltro !== '' || $tipoFiltro !== '' || $clienteId > 0 || $ventaIdFiltro > 0 || $desde !== '' || $hasta !== '';
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
if ($estadoFiscalFiltro !== '') {
    $filterTags[] = ['label' => 'Fiscal: ' . flus_facturacion_estado_fiscal_label($estadoFiscalFiltro), 'url' => urlWithFact(['estado_fiscal' => null, 'page' => 1])];
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
$breadcrumbs = [
    ['label' => 'Facturacion', 'url' => null],
];
$extraCss = ['assets/css/facturacion.css?v=10'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap facturacion-page">
  <div class="panel fact-panel">
    <header class="page-header module-header">
      <div class="module-header-main">
        <div class="module-header-hero">
          <span class="module-header-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
              <path d="M8 3h7l5 5v13H8z"/>
              <path d="M15 3v5h5"/>
              <path d="M11 13h6"/>
              <path d="M11 17h6"/>
            </svg>
          </span>
          <div class="module-header-copy">
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
        </div>
      </div>

      <div class="promo-actions-top module-header-actions">
        <a href="documentos_comerciales.php" class="v-btn v-btn--outline" title="Presupuestos y remitos">
          Documentos comerciales
        </a>
        <a href="facturacion_nc.php" class="v-btn v-btn--outline" title="Gestionar notas de credito">
          Notas de credito
        </a>
        <a href="facturacion_recovery.php" class="v-btn v-btn--outline" title="Incidencias fiscales y regularizacion">
          Incidencias fiscales
        </a>
        <?php if (function_exists('user_has_permission') && user_has_permission('administrar_config')): ?>
          <a href="facturacion_config.php" class="v-btn v-btn--outline" title="Configuracion de facturacion">
            Configuracion
          </a>
        <?php endif; ?>
        <a href="documento_comercial.php?tipo=PRESUPUESTO" class="v-btn v-btn--outline">
          + Presupuesto
        </a>
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
        <div class="fact-overview__item">
          <span>Incidencias</span>
          <strong><?= number_format($incidenciasVisibles) ?></strong>
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

    <?php if ($incidenciasVisibles > 0): ?>
      <section class="fact-summary-bar fact-summary-bar--incidents" aria-label="Incidencias fiscales">
        <div class="fact-summary-bar__top">
          <div class="fact-summary-bar__headline">
            <strong><?= number_format($incidenciasVisibles) ?></strong> incidencia<?= $incidenciasVisibles === 1 ? '' : 's' ?> fiscal<?= $incidenciasVisibles === 1 ? '' : 'es' ?> visible<?= $incidenciasVisibles === 1 ? '' : 's' ?>
            <span class="muted">| Pendientes <?= number_format($incidencias['pendientes']) ?>  | Transitorios <?= number_format($incidencias['transitorios']) ?>  | Post-ARCA <?= number_format($incidencias['post_arca']) ?>  | Rechazadas <?= number_format($incidencias['rechazadas']) ?><?php if ($incidencias['recuperadas'] > 0): ?>  | Recuperadas <?= number_format($incidencias['recuperadas']) ?><?php endif; ?></span>
          </div>
          <div class="fact-summary-bar__actions">
            <a href="facturacion_recovery.php" class="fact-summary-bar__export">Abrir incidencias</a>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <?php foreach ($avisos as $aviso): ?>
      <div class="alert alert-error fact-alert"><?= h($aviso) ?></div>
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

        <select name="estado_fiscal">
          <option value="">Todos los estados fiscales</option>
          <?php foreach ($allowedEstadosFiscales as $_estadoFiscalOpt): ?>
            <option value="<?= h($_estadoFiscalOpt) ?>" <?= $estadoFiscalFiltro === $_estadoFiscalOpt ? 'selected' : '' ?>><?= h(flus_facturacion_estado_fiscal_label($_estadoFiscalOpt)) ?></option>
          <?php endforeach; ?>
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
          <a href="documento_comercial.php?tipo=PRESUPUESTO" class="btn btn-secondary">Nuevo presupuesto</a>
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
              $clienteIdFila = (int)($factura['cliente_id'] ?? 0);
              $clienteNombre = trim((string)($factura['cliente_nombre'] ?? '')) ?: 'Consumidor Final';
              $clienteCuit = trim((string)($factura['cliente_cuit'] ?? ''));
              $numero = $factura['numero'] !== null ? (int)$factura['numero'] : null;
              $puntoVenta = $factura['punto_venta'] !== null ? (int)$factura['punto_venta'] : null;
              $fechaLista = trim((string)($factura['fecha'] ?? ''));
              $fechaTs = $fechaLista !== '' ? strtotime($fechaLista) : false;
              $fechaMostrar = $fechaTs !== false ? date('d/m/Y H:i', $fechaTs) : ($fechaLista !== '' ? $fechaLista : '-');
              $estadoFila = strtoupper(trim((string)($factura['estado'] ?? 'EMITIDA')));
              $estadoFiscalFila = flus_facturacion_estado_fiscal_resolver_desde_factura($factura);
              $estadoFiscalLabel = flus_facturacion_estado_fiscal_label($estadoFiscalFila);
              $modoFilaRaw = trim((string)($factura['modo'] ?? ''));
              $modoFila = $modoFilaRaw !== '' ? flus_facturacion_normalizar_modo($modoFilaRaw) : '';
              $tipoFila = strtoupper(trim((string)($factura['tipo'] ?? '')));
              $isNcFila = str_starts_with($tipoFila, 'NC');
              $isNdFila = str_starts_with($tipoFila, 'ND');
              $naturalezaLabel = $isNcFila ? 'NC' : ($isNdFila ? 'ND' : 'FACTURA');
              $naturalezaClass = $isNcFila
                  ? 'fact-inline-badge--nc'
                  : ($isNdFila ? 'fact-inline-badge--nd' : 'fact-inline-badge--doc');
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
              $accionFiscal = flus_facturacion_factura_accion_operativa($factura);
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
                  <span class="fact-inline-badge <?= h($naturalezaClass) ?>">
                    <?= h($naturalezaLabel) ?>
                  </span>
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
                <div class="fact-doc-title">
                  <?php if ($canViewClientes && $clienteIdFila > 0): ?>
                    <a href="cliente_detalle.php?id=<?= $clienteIdFila ?>" class="fact-link-inline"><?= h($clienteNombre) ?></a>
                  <?php else: ?>
                    <?= h($clienteNombre) ?>
                  <?php endif; ?>
                </div>
                <div class="fact-cell-sub"><?= $clienteCuit !== '' ? h($clienteCuit) : 'Sin documento fiscal cargado' ?></div>
              </td>
              <td>
                <?php if ($cae !== ''): ?>
                  <div class="fact-doc-title fact-doc-title--small"><?= h($cae) ?></div>
                  <div class="fact-cell-sub"><?= $caeVtoLabel !== '' ? 'Vto. ' . h($caeVtoLabel) : ($tieneCaeReal ? 'Sin vencimiento visible' : 'Modo demo') ?></div>
                <?php else: ?>
                  <span class="fact-inline-badge fact-inline-badge--warn">Sin CAE</span>
                  <?php if ($estadoFiscalFila === 'RECHAZADA'): ?>
                    <div class="fact-cell-sub">Rechazada por ARCA</div>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td class="t-right">
                <div class="fact-doc-title"><?= money_ar((float)($factura['total'] ?? 0)) ?></div>
              </td>
              <td>
                <span class="fact-status-badge <?= $estadoFila === 'ANULADA' ? 'fact-status-badge--danger' : 'fact-status-badge--ok' ?>">
                  <?= h($estadoFila) ?>
                </span>
                <?php if ($estadoFiscalFila !== 'NO_APLICA'): ?>
                  <div class="fact-cell-sub">Fiscal: <?= h($estadoFiscalLabel) ?></div>
                <?php endif; ?>
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
                  <?php if (($accionFiscal['url'] ?? '') !== '' && ($accionFiscal['label'] ?? '') !== ''): ?>
                    <a href="<?= h((string)$accionFiscal['url']) ?>" class="btn-mini <?= ($accionFiscal['kind'] ?? '') === 'regularizar' ? 'btn-mini--danger' : 'btn-mini--ghost' ?>"><?= h((string)$accionFiscal['label']) ?></a>
                  <?php endif; ?>
                </div>
                <?php if (trim((string)($accionFiscal['help'] ?? '')) !== ''): ?>
                  <div class="fact-cell-sub fact-row-help"><?= h((string)$accionFiscal['help']) ?></div>
                <?php endif; ?>
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


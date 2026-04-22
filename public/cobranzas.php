<?php
// public/cobranzas.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/caja_lib.php';
require_once __DIR__ . '/../src/db_schema.php';
require_once __DIR__ . '/../src/facturacion_lib.php';
require_once __DIR__ . '/../src/facturacion_panel_lib.php';
require_once __DIR__ . '/../src/cobranzas_lib.php';

require_login();
require_any_permission(['ver_facturacion', 'registrar_pago_cc', 'ver_cuenta_corriente']);

function cobranzas_url(array $overrides = []): string
{
    $query = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    return 'cobranzas.php' . ($query === [] ? '' : '?' . http_build_query($query));
}

function cobranzas_range_url(string $desde, string $hasta): string
{
    return cobranzas_url([
        'desde' => $desde,
        'hasta' => $hasta,
        'page' => 1,
    ]);
}

function cobranzas_caja_abierta_actual(PDO $pdo): int
{
    $cajaId = (int)($_SESSION['caja_id'] ?? 0);
    if ($cajaId > 0) {
        return $cajaId;
    }

    if (!function_exists('current_terminal_id') || !function_exists('caja_get_abierta')) {
        return 0;
    }

    try {
        $terminalId = current_terminal_id();
        if ($terminalId <= 0) {
            return 0;
        }
        $caja = caja_get_abierta($pdo, $terminalId);
        return is_array($caja) ? (int)($caja['id'] ?? 0) : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function cobranzas_estado_badge_class(string $estado): string
{
    return match (strtoupper(trim($estado))) {
        'COBRADA' => 'cobranzas-status--ok',
        'COMPENSADA' => 'cobranzas-status--info',
        'PARCIAL' => 'cobranzas-status--warn',
        default => 'cobranzas-status--pending',
    };
}

$today = new DateTimeImmutable('today');
$quickRanges = [
    [
        'label' => 'Hoy',
        'desde' => $today->format('Y-m-d'),
        'hasta' => $today->format('Y-m-d'),
    ],
    [
        'label' => 'Esta semana',
        'desde' => $today->modify('monday this week')->format('Y-m-d'),
        'hasta' => $today->modify('sunday this week')->format('Y-m-d'),
    ],
    [
        'label' => 'Este mes',
        'desde' => $today->modify('first day of this month')->format('Y-m-d'),
        'hasta' => $today->modify('last day of this month')->format('Y-m-d'),
    ],
    [
        'label' => 'Mes anterior',
        'desde' => $today->modify('first day of last month')->format('Y-m-d'),
        'hasta' => $today->modify('last day of last month')->format('Y-m-d'),
    ],
];

$panel = flus_cobranzas_panel_read($pdo, [
    'desde' => $_GET['desde'] ?? '',
    'hasta' => $_GET['hasta'] ?? '',
    'estado_cobro' => $_GET['estado_cobro'] ?? 'PENDIENTE',
    'search' => $_GET['q'] ?? '',
    'cliente_id' => $_GET['cliente_id'] ?? 0,
    'per_page' => $_GET['per_page'] ?? 50,
    'page' => $_GET['page'] ?? 1,
]);

$filters = $panel['filters'];
$desde = (string)($filters['desde'] ?? '');
$hasta = (string)($filters['hasta'] ?? '');
$estadoCobro = (string)($filters['estado_cobro'] ?? 'PENDIENTE');
$search = (string)($filters['search'] ?? '');
$clienteId = (int)($filters['cliente_id'] ?? 0);
$perPage = (int)($filters['per_page'] ?? 50);
$page = (int)($filters['page'] ?? 1);
$rows = $panel['rows'];
$stats = $panel['stats'];
$avisos = $panel['avisos'];
$totalRows = (int)$panel['total_rows'];
$totalPages = (int)$panel['total_pages'];
$fromRow = (int)$panel['from_row'];
$toRow = (int)$panel['to_row'];
$receiptsReady = (bool)$panel['receipts_ready'];
$clientes = function_exists('flus_facturacion_clientes_disponibles') ? flus_facturacion_clientes_disponibles($pdo) : [];
$canViewFacturacion = function_exists('user_has_permission') && user_has_permission('ver_facturacion');
$canEmitirFactura = function_exists('user_has_permission') && user_has_permission('emitir_factura');
$canViewCuentaCorriente = function_exists('user_has_permission') && user_has_permission('ver_cuenta_corriente');
$canRegistrarCobro = function_exists('user_has_permission') && user_has_permission('registrar_pago_cc') && $receiptsReady;
$cajaAbiertaId = cobranzas_caja_abierta_actual($pdo);
$hasActiveFilters = $search !== '' || $clienteId > 0 || $estadoCobro !== 'PENDIENTE' || $desde !== '' || $hasta !== '';
$filterTags = [];

foreach ($quickRanges as &$range) {
    $range['active'] = $desde === $range['desde'] && $hasta === $range['hasta'];
    $range['url'] = cobranzas_range_url($range['desde'], $range['hasta']);
}
unset($range);

if ($search !== '') {
    $filterTags[] = ['label' => 'Busqueda: ' . $search, 'url' => cobranzas_url(['q' => null, 'page' => 1])];
}
if ($clienteId > 0) {
    $clienteLabel = 'Cliente #' . $clienteId;
    foreach ($clientes as $cli) {
        if ((int)($cli['id'] ?? 0) === $clienteId) {
            $clienteLabel = (string)($cli['nombre'] ?? $clienteLabel);
            break;
        }
    }
    $filterTags[] = ['label' => 'Cliente: ' . $clienteLabel, 'url' => cobranzas_url(['cliente_id' => null, 'page' => 1])];
}
if ($estadoCobro !== 'PENDIENTE') {
    $labels = [
        'SIN_COBRAR' => 'Sin cobrar',
        'PARCIAL' => 'Parciales',
        'COBRADA' => 'Cobradas',
        'COMPENSADA' => 'Compensadas por NC',
        'TODAS' => 'Todas',
    ];
    $filterTags[] = ['label' => 'Estado: ' . ($labels[$estadoCobro] ?? $estadoCobro), 'url' => cobranzas_url(['estado_cobro' => null, 'page' => 1])];
}
if ($desde !== '' || $hasta !== '') {
    $periodoLabel = $desde !== '' && $hasta !== ''
        ? 'Periodo: ' . $desde . ' a ' . $hasta
        : ($desde !== '' ? 'Desde: ' . $desde : 'Hasta: ' . $hasta);
    $filterTags[] = ['label' => $periodoLabel, 'url' => cobranzas_url(['desde' => null, 'hasta' => null, 'page' => 1])];
}

$pageTitle = 'Cobranzas - FLUS';
$currentSection = 'cobranzas';
$breadcrumbs = [
    ['label' => 'Tesoreria', 'url' => 'tesoreria.php'],
    ['label' => 'Cobranzas', 'url' => null],
];
$extraCss = ['assets/css/facturacion.css?v=10', 'assets/css/cobranzas.css?v=1'];
$inlineJs = <<<'JS'
(function () {
  function ready(fn) {
    if (document.readyState !== 'loading') { fn(); return; }
    document.addEventListener('DOMContentLoaded', fn);
  }
  function csrf() {
    if (window.getCsrfToken) return window.getCsrfToken() || '';
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? (meta.getAttribute('content') || '') : '';
  }
  function uid() {
    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
    return 'cobranzas-' + Date.now() + '-' + Math.random().toString(16).slice(2);
  }
  function notify(message, isError) {
    if (window.Notif && typeof window.Notif[isError ? 'error' : 'success'] === 'function') {
      window.Notif[isError ? 'error' : 'success'](message);
      return;
    }
    if (window.showToast) {
      window.showToast(message, isError ? 'error' : 'success');
      return;
    }
    if (isError) alert(message);
  }
  ready(function () {
    var modal = document.getElementById('cobranzaModal');
    var form = document.getElementById('cobranzaForm');
    var closeButtons = modal ? modal.querySelectorAll('[data-close-cobranza]') : [];
    var openButtons = document.querySelectorAll('[data-cobrar-factura]');
    if (!modal || !form || openButtons.length === 0) return;

    function openModal(btn) {
      var facturaId = btn.getAttribute('data-factura-id') || '';
      var saldo = btn.getAttribute('data-saldo') || '';
      var label = btn.getAttribute('data-label') || 'Factura';
      form.reset();
      form.querySelector('[name="factura_id"]').value = facturaId;
      form.querySelector('[name="request_uid"]').value = '';
      var amount = form.querySelector('[name="monto"]');
      if (amount) {
        amount.value = saldo;
        amount.max = saldo;
      }
      var title = modal.querySelector('[data-cobranza-title]');
      var summary = modal.querySelector('[data-cobranza-summary]');
      if (title) title.textContent = 'Registrar cobro';
      if (summary) summary.textContent = label + ' - saldo pendiente $ ' + saldo;
      modal.hidden = false;
      if (amount) amount.focus();
    }
    function closeModal() {
      modal.hidden = true;
    }

    openButtons.forEach(function (btn) {
      btn.addEventListener('click', function () { openModal(btn); });
    });
    closeButtons.forEach(function (btn) { btn.addEventListener('click', closeModal); });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && !modal.hidden) closeModal();
    });

    form.addEventListener('submit', async function (ev) {
      ev.preventDefault();
      var submit = form.querySelector('[type="submit"]');
      var fd = new FormData(form);
      fd.set('action', 'registrar_cobro_factura');
      fd.set('csrf_token', csrf());
      if (!fd.get('request_uid')) fd.set('request_uid', uid());
      if (submit) submit.disabled = true;

      try {
        var response = await fetch('api/factura_cobranza_api.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf() },
          body: fd
        });
        var data = await response.json().catch(function () { return null; });
        if (!response.ok || !data || data.success !== true) {
          throw new Error((data && (data.error || data.message)) || 'No se pudo registrar el cobro.');
        }
        notify('Cobro registrado. Actualizando cobranzas...', false);
        window.setTimeout(function () { window.location.reload(); }, 550);
      } catch (err) {
        notify(err && err.message ? err.message : 'No se pudo registrar el cobro.', true);
        if (submit) submit.disabled = false;
      }
    });
  });
}());
JS;

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap facturacion-page cobranzas-page">
  <div class="panel fact-panel">
    <header class="page-header module-header">
      <div class="module-header-main">
        <div class="module-header-hero">
          <span class="module-header-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
              <path d="M4 7h16v10H4z"/>
              <path d="M7 11h4"/>
              <path d="M15 14h2"/>
            </svg>
          </span>
          <div class="module-header-copy">
            <span class="module-eyebrow">Gestion de cobro</span>
            <h1 class="page-title module-title">Cobranzas</h1>
            <p class="page-sub module-subtitle">
              Facturas pendientes, cobros parciales y recibos internos de FLUS.
            </p>
          </div>
        </div>
      </div>

      <div class="promo-actions-top module-header-actions">
        <?php if ($canViewFacturacion): ?>
          <a href="facturacion.php" class="v-btn v-btn--outline">Facturacion</a>
        <?php endif; ?>
        <?php if ($canViewCuentaCorriente): ?>
          <a href="cuenta_corriente.php" class="v-btn v-btn--outline">Cuenta corriente</a>
        <?php endif; ?>
        <?php if ($canEmitirFactura): ?>
          <a href="factura_manual.php" class="v-btn v-btn--primary">+ Factura manual</a>
        <?php endif; ?>
      </div>
    </header>

    <section class="fact-overview cobranzas-overview">
      <div class="fact-overview__main">
        <span class="fact-overview__eyebrow">Operacion comercial</span>
        <h2 class="fact-overview__title">Facturas por cobrar</h2>
        <p class="fact-overview__text">
          Registra pagos de facturas emitidas y deja un recibo interno. Este flujo no envia nada a ARCA.
        </p>
      </div>
      <div class="fact-overview__meta">
        <div class="fact-overview__item">
          <span>Resultados</span>
          <strong><?= number_format($totalRows) ?></strong>
        </div>
        <div class="fact-overview__item">
          <span>Caja</span>
          <strong><?= $cajaAbiertaId > 0 ? '#' . (int)$cajaAbiertaId : 'Sin abrir' ?></strong>
        </div>
        <div class="fact-overview__item">
          <span>Recibos</span>
          <strong><?= $receiptsReady ? 'Listos' : 'Pendientes' ?></strong>
        </div>
      </div>
    </section>

    <section class="fact-kpi-grid" aria-label="Resumen de cobranzas">
      <article class="fact-kpi-card fact-kpi-card--accent">
        <span class="fact-kpi-card__label">Por cobrar</span>
        <strong class="fact-kpi-card__value"><?= money_ar($stats['total_saldo']) ?></strong>
        <span class="fact-kpi-card__help"><?= number_format($stats['pendientes']) ?> factura<?= (int)$stats['pendientes'] === 1 ? '' : 's' ?> pendiente<?= (int)$stats['pendientes'] === 1 ? '' : 's' ?></span>
      </article>
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Cobrado</span>
        <strong class="fact-kpi-card__value"><?= money_ar($stats['total_cobrado']) ?></strong>
        <span class="fact-kpi-card__help">Aplicado a facturas visibles</span>
      </article>
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Notas de credito</span>
        <strong class="fact-kpi-card__value"><?= money_ar($stats['total_nc'] ?? 0) ?></strong>
        <span class="fact-kpi-card__help">Compensan saldo de facturas</span>
      </article>
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Neto a cobrar</span>
        <strong class="fact-kpi-card__value"><?= money_ar($stats['total_neto'] ?? 0) ?></strong>
        <span class="fact-kpi-card__help">Facturado menos NC</span>
      </article>
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Sin cobrar</span>
        <strong class="fact-kpi-card__value"><?= number_format($stats['sin_cobrar']) ?></strong>
        <span class="fact-kpi-card__help">Sin recibos aplicados</span>
      </article>
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Parciales</span>
        <strong class="fact-kpi-card__value"><?= number_format($stats['parciales']) ?></strong>
        <span class="fact-kpi-card__help">Con saldo todavia abierto</span>
      </article>
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Cobradas</span>
        <strong class="fact-kpi-card__value"><?= number_format($stats['cobradas']) ?></strong>
        <span class="fact-kpi-card__help">Saldo cancelado</span>
      </article>
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Compensadas</span>
        <strong class="fact-kpi-card__value"><?= number_format($stats['compensadas'] ?? 0) ?></strong>
        <span class="fact-kpi-card__help">Canceladas por NC</span>
      </article>
      <article class="fact-kpi-card">
        <span class="fact-kpi-card__label">Facturado</span>
        <strong class="fact-kpi-card__value"><?= money_ar($stats['total_facturado']) ?></strong>
        <span class="fact-kpi-card__help">Total de la vista</span>
      </article>
    </section>

    <?php foreach ($avisos as $aviso): ?>
      <div class="alert alert-error" style="margin-bottom:12px;"><?= h((string)$aviso) ?></div>
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
      </div>

      <div class="filters-left">
        <input type="text" name="q" value="<?= h($search) ?>" placeholder="Buscar cliente, CUIT, CAE o comprobante">

        <select name="cliente_id">
          <option value="">Todos los clientes</option>
          <?php foreach ($clientes as $cli): ?>
            <option value="<?= (int)$cli['id'] ?>" <?= $clienteId === (int)$cli['id'] ? 'selected' : '' ?>>
              <?= h((string)($cli['nombre'] ?? 'Cliente')) ?><?= !empty($cli['cuit']) ? ' (' . h((string)$cli['cuit']) . ')' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>

        <select name="estado_cobro">
          <option value="PENDIENTE" <?= $estadoCobro === 'PENDIENTE' ? 'selected' : '' ?>>Pendientes</option>
          <option value="SIN_COBRAR" <?= $estadoCobro === 'SIN_COBRAR' ? 'selected' : '' ?>>Sin cobrar</option>
          <option value="PARCIAL" <?= $estadoCobro === 'PARCIAL' ? 'selected' : '' ?>>Parciales</option>
          <option value="COBRADA" <?= $estadoCobro === 'COBRADA' ? 'selected' : '' ?>>Cobradas</option>
          <option value="COMPENSADA" <?= $estadoCobro === 'COMPENSADA' ? 'selected' : '' ?>>Compensadas por NC</option>
          <option value="TODAS" <?= $estadoCobro === 'TODAS' ? 'selected' : '' ?>>Todas</option>
        </select>
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
        <a href="cobranzas.php" class="btn btn-secondary">Limpiar</a>
      </div>
    </form>

    <section class="fact-summary-bar" aria-label="Resumen de resultados">
      <div class="fact-summary-bar__top">
        <div class="fact-summary-bar__headline">
          <strong><?= number_format($totalRows) ?></strong> factura<?= $totalRows === 1 ? '' : 's' ?>
          <?php if ($totalRows > 0): ?>
            <span class="muted">| <?= $fromRow ?>-<?= $toRow ?> de <?= $totalRows ?> | pagina <?= $page ?> de <?= $totalPages ?></span>
          <?php endif; ?>
        </div>

        <div class="fact-summary-bar__actions">
          <?php if ($hasActiveFilters): ?>
            <a href="cobranzas.php" class="fact-summary-bar__clear">Limpiar filtros</a>
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

    <?= render_pagination($page, $totalPages, $_GET, true, $totalRows, $fromRow, $toRow) ?>

    <?php if ($rows === []): ?>
      <section class="fact-empty-state">
        <div class="fact-empty-state__icon">C</div>
        <h3>No hay facturas para cobrar en esta vista</h3>
        <p>
          <?= $hasActiveFilters
              ? 'Prueba cambiando cliente, estado o periodo para encontrar otras facturas.'
              : 'Cuando existan facturas emitidas con saldo, van a aparecer aca.' ?>
        </p>
        <div class="fact-empty-state__actions">
          <?php if ($hasActiveFilters): ?>
            <a href="cobranzas.php" class="btn btn-secondary">Quitar filtros</a>
          <?php endif; ?>
          <?php if ($canViewFacturacion): ?>
            <a href="facturacion.php" class="btn btn-secondary">Ver facturacion</a>
          <?php endif; ?>
          <?php if ($canEmitirFactura): ?>
            <a href="factura_manual.php" class="btn btn-primary">Crear factura manual</a>
          <?php endif; ?>
        </div>
      </section>
    <?php else: ?>
      <div class="table-wrapper">
        <table class="mov-table fact-table cobranzas-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Factura</th>
              <th>Cliente</th>
              <th class="t-right">Total</th>
              <th class="t-right">NC</th>
              <th class="t-right">Neto</th>
              <th class="t-right">Cobrado</th>
              <th class="t-right">Saldo</th>
              <th>Estado</th>
              <th>Recibos</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <?php
              $facturaId = (int)($row['id'] ?? 0);
              $clienteNombre = trim((string)($row['cliente_nombre'] ?? '')) ?: 'Consumidor Final';
              $clienteCuit = trim((string)($row['cliente_cuit'] ?? ''));
              $fechaRaw = trim((string)($row['fecha'] ?? ''));
              $fechaTs = $fechaRaw !== '' ? strtotime($fechaRaw) : false;
              $fechaLabel = $fechaTs !== false ? date('d/m/Y H:i', $fechaTs) : ($fechaRaw !== '' ? $fechaRaw : '-');
              $tipo = trim((string)($row['tipo'] ?? 'Factura')) ?: 'Factura';
              $puntoVenta = $row['punto_venta'] !== null ? (int)$row['punto_venta'] : null;
              $numero = $row['numero'] !== null ? (int)$row['numero'] : null;
              $comprobante = $tipo;
              if ($puntoVenta !== null && $numero !== null) {
                  $comprobante .= ' ' . sprintf('%04d-%08d', $puntoVenta, $numero);
              } elseif ($numero !== null) {
                  $comprobante .= ' #' . $numero;
              }
              $saldo = round((float)($row['saldo'] ?? 0), 2);
              $estadoFila = (string)($row['estado_cobro'] ?? 'SIN_COBRAR');
              $estadoLabel = (string)($row['estado_cobro_label'] ?? $estadoFila);
              $saldoAttr = number_format($saldo, 2, '.', '');
            ?>
            <tr>
              <td class="mono">
                <div><?= h($fechaLabel) ?></div>
                <div class="fact-cell-sub">ID #<?= $facturaId ?></div>
              </td>
              <td>
                <div class="fact-doc-title"><?= h($comprobante) ?></div>
                <div class="fact-doc-meta">
                  <?php if (!empty($row['venta_id'])): ?>
                    <a href="venta_detalle.php?id=<?= (int)$row['venta_id'] ?>" class="fact-link-inline">Venta #<?= (int)$row['venta_id'] ?></a>
                  <?php else: ?>
                    <span class="fact-cell-sub">Carga manual o sin venta vinculada</span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="fact-doc-title"><?= h($clienteNombre) ?></div>
                <div class="fact-cell-sub"><?= $clienteCuit !== '' ? h($clienteCuit) : 'Sin documento fiscal cargado' ?></div>
              </td>
              <td class="t-right"><strong><?= money_ar((float)($row['total'] ?? 0)) ?></strong></td>
              <td class="t-right">
                <?= money_ar((float)($row['total_nc'] ?? 0)) ?>
                <?php if ((int)($row['nc_count'] ?? 0) > 0): ?>
                  <div class="fact-cell-sub"><?= number_format((int)$row['nc_count']) ?> NC</div>
                <?php endif; ?>
              </td>
              <td class="t-right"><strong><?= money_ar((float)($row['total_neto'] ?? $row['total'] ?? 0)) ?></strong></td>
              <td class="t-right"><?= money_ar((float)($row['cobrado'] ?? 0)) ?></td>
              <td class="t-right"><strong><?= money_ar($saldo) ?></strong></td>
              <td>
                <span class="cobranzas-status <?= h(cobranzas_estado_badge_class($estadoFila)) ?>">
                  <?= h($estadoLabel) ?>
                </span>
              </td>
              <td>
                <span class="fact-doc-title"><?= number_format((int)($row['recibos_count'] ?? 0)) ?></span>
                <div class="fact-cell-sub">recibo<?= (int)($row['recibos_count'] ?? 0) === 1 ? '' : 's' ?> interno<?= (int)($row['recibos_count'] ?? 0) === 1 ? '' : 's' ?></div>
              </td>
              <td>
                <div class="fact-row-actions">
                  <?php if ($canViewFacturacion || $canEmitirFactura): ?>
                    <a href="factura_ver.php?id=<?= $facturaId ?>" class="btn-mini">Ver</a>
                  <?php endif; ?>
                  <?php if ($canRegistrarCobro && $saldo > 0.009): ?>
                    <button
                      type="button"
                      class="btn-mini btn-mini--primary"
                      data-cobrar-factura
                      data-factura-id="<?= $facturaId ?>"
                      data-saldo="<?= h($saldoAttr) ?>"
                      data-label="<?= h($comprobante) ?>">
                      Cobrar
                    </button>
                  <?php elseif (!$receiptsReady): ?>
                    <span class="fact-cell-sub">Falta esquema de recibos</span>
                  <?php elseif ($saldo <= 0.009): ?>
                    <span class="fact-cell-sub">Sin saldo</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="fact-table-footer">
        <div class="fact-table-footer__item">Mostrando <?= $fromRow ?>-<?= $toRow ?> de <?= number_format($totalRows) ?> facturas</div>
        <div class="fact-table-footer__item">NC: <strong><?= money_ar($stats['total_nc'] ?? 0) ?></strong></div>
        <div class="fact-table-footer__item">Neto: <strong><?= money_ar($stats['total_neto'] ?? 0) ?></strong></div>
        <div class="fact-table-footer__item">Por cobrar: <strong><?= money_ar($stats['total_saldo']) ?></strong></div>
        <div class="fact-table-footer__item">Cobrado: <strong><?= money_ar($stats['total_cobrado']) ?></strong></div>
      </div>

      <?= render_pagination($page, $totalPages, $_GET, false, $totalRows, $fromRow, $toRow) ?>
    <?php endif; ?>
  </div>
</div>

<div id="cobranzaModal" class="cobranza-modal" hidden>
  <div class="cobranza-modal__backdrop" data-close-cobranza></div>
  <div class="cobranza-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="cobranzaModalTitle">
    <div class="cobranza-modal__head">
      <div>
        <div class="cobranza-modal__eyebrow">Recibo interno</div>
        <h3 id="cobranzaModalTitle" data-cobranza-title>Registrar cobro</h3>
        <p data-cobranza-summary>Factura seleccionada</p>
      </div>
      <button type="button" class="cobranza-modal__close" data-close-cobranza aria-label="Cerrar">x</button>
    </div>
    <form id="cobranzaForm" class="cobranza-form">
      <input type="hidden" name="factura_id" value="">
      <input type="hidden" name="request_uid" value="">

      <label>
        <span>Monto</span>
        <input type="number" name="monto" min="0.01" step="0.01" value="" required>
      </label>

      <label>
        <span>Medio de pago</span>
        <select name="medio_pago" required>
          <option value="EFECTIVO">Efectivo</option>
          <option value="DEBITO">Debito</option>
          <option value="CREDITO">Credito</option>
          <option value="TRANSFERENCIA">Transferencia</option>
          <option value="QR">QR</option>
          <option value="OTRO">Otro</option>
        </select>
      </label>

      <label>
        <span>Referencia</span>
        <input type="text" name="referencia" maxlength="120" placeholder="Numero de operacion o nota breve">
      </label>

      <label>
        <span>Observaciones</span>
        <textarea name="observaciones" rows="3" maxlength="255" placeholder="Detalle interno del cobro"></textarea>
      </label>

      <label class="cobranza-form__check">
        <input type="checkbox" name="registrar_caja" value="1" <?= $cajaAbiertaId > 0 ? 'checked' : 'disabled' ?>>
        <span><?= $cajaAbiertaId > 0 ? 'Registrar movimiento en caja abierta #' . (int)$cajaAbiertaId : 'Sin caja abierta en esta terminal' ?></span>
      </label>

      <div class="cobranza-form__hint">El recibo es interno de FLUS; no se envia a ARCA.</div>

      <div class="cobranza-form__actions">
        <button type="button" class="btn btn-secondary" data-close-cobranza>Cancelar</button>
        <button type="submit" class="btn btn-primary">Registrar cobro</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

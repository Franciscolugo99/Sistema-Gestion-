<?php
// public/facturacion_nc.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/db_schema.php';
require_once __DIR__ . '/../src/facturacion_lib.php';
require_once __DIR__ . '/../src/venta_anulaciones_lib.php';
require_once __DIR__ . '/../src/Fiscal/bootstrap.php';

require_login();
require_any_permission(['ver_facturacion', 'emitir_factura']);

$facturacionHabilitada = config_get($pdo, 'facturacion_habilitada', '0') === '1';
if (!$facturacionHabilitada) {
    header('Location: index.php');
    exit;
}

function nc_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function nc_is_factura_origen(array $row): bool
{
    $naturaleza = strtoupper(trim((string)($row['naturaleza'] ?? '')));
    if ($naturaleza !== '') {
        return $naturaleza === 'FACTURA';
    }

    $tipo = strtoupper(trim((string)($row['tipo'] ?? '')));
    return !in_array($tipo, ['NCA', 'NCB', 'NCC', 'NDA', 'NDB', 'NDC'], true);
}

function nc_money(float $value): string
{
    return function_exists('money_ar') ? money_ar($value) : ('$' . number_format($value, 2, ',', '.'));
}

function nc_qty(float $value): string
{
    return (abs($value - round($value)) < 0.0009)
        ? (string)(int)round($value)
        : rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
}

function nc_build_url(array $overrides = []): string
{
    $query = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    return 'facturacion_nc.php' . ($query === [] ? '' : '?' . http_build_query($query));
}

function nc_search_like(string $value): string
{
    return '%' . addcslashes($value, "\\%_") . '%';
}

/**
 * @return array<int,array<string,mixed>>
 */
function nc_fetch_candidate_facturas(PDO $pdo, string $search, int $ventaIdFiltro, int $limit = 40): array
{
    if (!flus_table_exists($pdo, 'facturas')) {
        return [];
    }

    $joinClientes = flus_table_exists($pdo, 'clientes') && flus_column_exists($pdo, 'facturas', 'cliente_id');
    $joinVentas = flus_table_exists($pdo, 'ventas') && flus_column_exists($pdo, 'facturas', 'venta_id');
    $tipoExpr = flus_column_exists($pdo, 'facturas', 'tipo') ? 'f.tipo' : "''";
    $fechaExpr = flus_column_exists($pdo, 'facturas', 'fecha') ? 'f.fecha' : (flus_column_exists($pdo, 'facturas', 'creado_en') ? 'f.creado_en' : 'NULL');
    $clienteNombreExpr = $joinClientes
        ? (flus_column_exists($pdo, 'clientes', 'nombre') ? 'c.nombre' : 'CONCAT("Cliente #", c.id)')
        : 'NULL';
    $clienteCuitExpr = $joinClientes && flus_column_exists($pdo, 'clientes', 'cuit') ? 'c.cuit' : 'NULL';
    $ventaEstadoExpr = $joinVentas && flus_column_exists($pdo, 'ventas', 'estado') ? 'v.estado' : 'NULL';

    $sql = "
        SELECT
            f.*,
            {$clienteNombreExpr} AS cliente_nombre,
            {$clienteCuitExpr} AS cliente_cuit,
            {$fechaExpr} AS fecha_ref,
            {$ventaEstadoExpr} AS venta_estado
        FROM facturas f
        " . ($joinClientes ? 'LEFT JOIN clientes c ON c.id = f.cliente_id' : '') . "
        " . ($joinVentas ? 'LEFT JOIN ventas v ON v.id = f.venta_id' : '') . "
        WHERE 1=1
    ";

    $params = [];

    if (flus_column_exists($pdo, 'facturas', 'naturaleza')) {
        $sql .= " AND f.naturaleza = 'FACTURA'";
    } elseif (flus_column_exists($pdo, 'facturas', 'tipo')) {
        $sql .= " AND UPPER(TRIM({$tipoExpr})) NOT IN ('NCA', 'NCB', 'NCC', 'NDA', 'NDB', 'NDC')";
    }

    if ($ventaIdFiltro > 0 && flus_column_exists($pdo, 'facturas', 'venta_id')) {
        $sql .= ' AND f.venta_id = :venta_id';
        $params[':venta_id'] = $ventaIdFiltro;
    }

    if ($search !== '') {
        $sql .= ' AND (';
        $clauses = [];
        $params[':search_like'] = nc_search_like($search);

        if ($joinClientes) {
            $clauses[] = "{$clienteNombreExpr} LIKE :search_like ESCAPE '\\\\'";
            if ($clienteCuitExpr !== 'NULL') {
                $clauses[] = "{$clienteCuitExpr} LIKE :search_like ESCAPE '\\\\'";
            }
        }
        if (flus_column_exists($pdo, 'facturas', 'cae')) {
            $clauses[] = "f.cae LIKE :search_like ESCAPE '\\\\'";
        }
        if (flus_column_exists($pdo, 'facturas', 'tipo')) {
            $clauses[] = "f.tipo LIKE :search_like ESCAPE '\\\\'";
        }
        if (ctype_digit($search) && flus_column_exists($pdo, 'facturas', 'numero')) {
            $clauses[] = 'f.numero = :search_numero';
            $params[':search_numero'] = (int)$search;
        }
        if (ctype_digit($search) && flus_column_exists($pdo, 'facturas', 'venta_id')) {
            $clauses[] = 'f.venta_id = :search_venta_id';
            $params[':search_venta_id'] = (int)$search;
        }
        if (preg_match('/^\s*(\d{1,4})\D+(\d{1,8})\s*$/', $search, $m) === 1 && flus_column_exists($pdo, 'facturas', 'punto_venta') && flus_column_exists($pdo, 'facturas', 'numero')) {
            $clauses[] = '(f.punto_venta = :pv AND f.numero = :nro)';
            $params[':pv'] = (int)$m[1];
            $params[':nro'] = (int)$m[2];
        }

        $sql .= implode(' OR ', $clauses !== [] ? $clauses : ['1=0']);
        $sql .= ')';
    }

    $sql .= ' ORDER BY ' . ($fechaExpr !== 'NULL' ? $fechaExpr : 'f.id') . ' DESC, f.id DESC LIMIT :limit';

    $st = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $st->bindValue($key, $value);
    }
    $st->bindValue(':limit', $limit, PDO::PARAM_INT);
    $st->execute();

    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_values(array_filter($rows, static fn(array $row): bool => nc_is_factura_origen($row)));
}

function nc_fetch_factura_origen(PDO $pdo, int $facturaId): ?array
{
    if ($facturaId <= 0 || !flus_table_exists($pdo, 'facturas')) {
        return null;
    }

    $joinClientes = flus_table_exists($pdo, 'clientes') && flus_column_exists($pdo, 'facturas', 'cliente_id');
    $joinVentas = flus_table_exists($pdo, 'ventas') && flus_column_exists($pdo, 'facturas', 'venta_id');
    $clienteNombreExpr = $joinClientes
        ? (flus_column_exists($pdo, 'clientes', 'nombre') ? 'c.nombre' : 'CONCAT("Cliente #", c.id)')
        : 'NULL';
    $clienteCuitExpr = $joinClientes && flus_column_exists($pdo, 'clientes', 'cuit') ? 'c.cuit' : 'NULL';
    $ventaEstadoExpr = $joinVentas && flus_column_exists($pdo, 'ventas', 'estado') ? 'v.estado' : 'NULL';
    $ventaTotalExpr = $joinVentas && flus_column_exists($pdo, 'ventas', 'total') ? 'v.total' : 'NULL';

    $sql = "
        SELECT
            f.*,
            {$clienteNombreExpr} AS cliente_nombre,
            {$clienteCuitExpr} AS cliente_cuit,
            {$ventaEstadoExpr} AS venta_estado,
            {$ventaTotalExpr} AS venta_total
        FROM facturas f
        " . ($joinClientes ? 'LEFT JOIN clientes c ON c.id = f.cliente_id' : '') . "
        " . ($joinVentas ? 'LEFT JOIN ventas v ON v.id = f.venta_id' : '') . "
        WHERE f.id = ?
        LIMIT 1
    ";

    $st = $pdo->prepare($sql);
    $st->execute([$facturaId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!is_array($row) || !nc_is_factura_origen($row)) {
        return null;
    }

    return $row;
}

/**
 * @return array<int,array<string,mixed>>
 */
function nc_fetch_factura_origen_items(PDO $pdo, array $factura): array
{
    $facturaId = (int)($factura['id'] ?? 0);
    $ventaId = (int)($factura['venta_id'] ?? 0);
    $items = [];

    if ($facturaId > 0 && flus_table_exists($pdo, 'factura_items')) {
        $st = $pdo->prepare('SELECT * FROM factura_items WHERE factura_id = ? ORDER BY linea_orden ASC, id ASC');
        $st->execute([$facturaId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $items[] = [
                'linea_orden' => (int)($row['linea_orden'] ?? 0),
                'venta_item_id' => (int)($row['venta_item_id'] ?? 0),
                'producto_id' => (int)($row['producto_id'] ?? 0),
                'descripcion' => trim((string)($row['descripcion_snapshot'] ?? '')),
                'codigo' => trim((string)($row['codigo_snapshot'] ?? '')),
                'cantidad_original' => round((float)($row['cantidad'] ?? 0), 3),
                'subtotal_original' => round((float)($row['subtotal_total'] ?? 0), 2),
                'neto_original' => round((float)($row['neto_gravado'] ?? 0), 2),
                'iva_original' => round((float)($row['iva_importe'] ?? 0), 2),
                'iva_porcentaje' => round((float)($row['iva_porcentaje'] ?? 0), 2),
                'source' => 'factura_items',
            ];
        }
        if ($items !== []) {
            return $items;
        }
    }

    if ($ventaId > 0 && flus_table_exists($pdo, 'venta_items')) {
        $usaProductos = flus_table_exists($pdo, 'productos');
        $productoCodigoExpr = ($usaProductos && flus_column_exists($pdo, 'productos', 'codigo')) ? 'p.codigo' : 'NULL';
        $productoNombreExpr = ($usaProductos && flus_column_exists($pdo, 'productos', 'nombre')) ? 'p.nombre' : 'NULL';
        $productoIvaExpr = ($usaProductos && flus_column_exists($pdo, 'productos', 'iva_porcentaje')) ? 'p.iva_porcentaje' : 'NULL';
        $sql = '
            SELECT vi.*, ' . $productoCodigoExpr . ' AS codigo, ' . $productoNombreExpr . ' AS nombre, ' . $productoIvaExpr . ' AS iva_porcentaje
            FROM venta_items vi
            ' . ($usaProductos ? 'LEFT JOIN productos p ON p.id = vi.producto_id' : '') . '
            WHERE vi.venta_id = ?
            ORDER BY vi.id ASC
        ';
        $st = $pdo->prepare($sql);
        $st->execute([$ventaId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $idx => $row) {
            $cantidad = round((float)($row['cantidad'] ?? 0), 3);
            $subtotal = round((float)($row['subtotal'] ?? 0), 2);
            $ivaPct = round((float)($row['iva_porcentaje'] ?? 0), 2);
            $neto = $ivaPct > 0 ? round($subtotal / (1 + $ivaPct / 100), 2) : $subtotal;
            $iva = round($subtotal - $neto, 2);

            $items[] = [
                'linea_orden' => $idx + 1,
                'venta_item_id' => (int)($row['id'] ?? 0),
                'producto_id' => (int)($row['producto_id'] ?? 0),
                'descripcion' => trim((string)($row['nombre'] ?? $row['descripcion'] ?? ('Item #' . ($row['id'] ?? '')))),
                'codigo' => trim((string)($row['codigo'] ?? '')),
                'cantidad_original' => $cantidad,
                'subtotal_original' => $subtotal,
                'neto_original' => $neto,
                'iva_original' => $iva,
                'iva_porcentaje' => $ivaPct,
                'source' => 'venta_items',
            ];
        }
    }

    return $items;
}

/**
 * @return array{count:int,total:float,rows:array<int,array<string,mixed>>,credited_qty:array<int,float>}
 */
function nc_fetch_resumen_notas_credito(PDO $pdo, int $facturaId): array
{
    if ($facturaId <= 0 || !flus_table_exists($pdo, 'facturas')) {
        return ['count' => 0, 'total' => 0.0, 'rows' => [], 'credited_qty' => []];
    }

    $where = 'f.factura_asociada_id = ?';
    if (flus_column_exists($pdo, 'facturas', 'naturaleza')) {
        $where .= " AND f.naturaleza = 'NC'";
    }

    $st = $pdo->prepare('SELECT f.* FROM facturas f WHERE ' . $where . ' ORDER BY f.fecha DESC, f.id DESC');
    $st->execute([$facturaId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $creditedQty = [];
    $total = 0.0;
    foreach ($rows as $row) {
        $total += (float)($row['total'] ?? 0);
    }

    if ($rows !== [] && flus_table_exists($pdo, 'factura_items')) {
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stItems = $pdo->prepare('SELECT venta_item_id, SUM(cantidad) AS qty FROM factura_items WHERE factura_id IN (' . $placeholders . ') AND venta_item_id IS NOT NULL GROUP BY venta_item_id');
        $stItems->execute($ids);
        foreach ($stItems->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $creditedQty[(int)$row['venta_item_id']] = round((float)$row['qty'], 3);
        }
    }

    return [
        'count' => count($rows),
        'total' => round($total, 2),
        'rows' => $rows,
        'credited_qty' => $creditedQty,
    ];
}

$q = trim((string)($_GET['q'] ?? ''));
$ventaIdFiltro = max(0, (int)($_GET['venta_id'] ?? 0));
$facturaId = max(0, (int)($_GET['factura_id'] ?? 0));
$ncOk = trim((string)($_GET['nc_ok'] ?? ''));
$ncError = trim((string)($_GET['nc_error'] ?? ''));

$candidates = nc_fetch_candidate_facturas($pdo, $q, $ventaIdFiltro, 40);
if ($facturaId <= 0 && $ventaIdFiltro > 0) {
    foreach ($candidates as $candidate) {
        if ((int)($candidate['venta_id'] ?? 0) === $ventaIdFiltro) {
            $facturaId = (int)($candidate['id'] ?? 0);
            break;
        }
    }
}

$factura = $facturaId > 0 ? nc_fetch_factura_origen($pdo, $facturaId) : null;
$facturaItems = $factura ? nc_fetch_factura_origen_items($pdo, $factura) : [];
$ncResumen = $factura ? nc_fetch_resumen_notas_credito($pdo, (int)$factura['id']) : ['count' => 0, 'total' => 0.0, 'rows' => [], 'credited_qty' => []];
$creditedQty = $ncResumen['credited_qty'] ?? [];

$lineas = [];
$saldoFiscalTotal = 0.0;
$saldoItems = 0;
foreach ($facturaItems as $row) {
    $ventaItemId = (int)($row['venta_item_id'] ?? 0);
    $cantidadOriginal = round((float)($row['cantidad_original'] ?? 0), 3);
    $cantidadAcreditada = round((float)($creditedQty[$ventaItemId] ?? 0), 3);
    $cantidadDisponible = max(0.0, round($cantidadOriginal - $cantidadAcreditada, 3));
    $subtotalOriginal = round((float)($row['subtotal_original'] ?? 0), 2);
    $factor = $cantidadOriginal > 0 ? ($cantidadDisponible / $cantidadOriginal) : 0.0;
    $subtotalDisponible = round($subtotalOriginal * $factor, 2);
    $saldoFiscalTotal += $subtotalDisponible;
    if ($cantidadDisponible > 0.0009) {
        $saldoItems++;
    }

    $lineas[] = $row + [
        'cantidad_acreditada' => $cantidadAcreditada,
        'cantidad_disponible' => $cantidadDisponible,
        'subtotal_disponible' => $subtotalDisponible,
    ];
}

$modoActual = flus_facturacion_modo_label(flus_facturacion_modo_actual(flus_facturacion_config_activa($pdo) ?? []));
$canEmitir = $factura && (int)($factura['venta_id'] ?? 0) > 0 && $saldoFiscalTotal > 0.009;
$ventaEstado = trim((string)($factura['venta_estado'] ?? ''));
$facturaLabel = '';
if ($factura) {
    $facturaLabel = trim((string)($factura['tipo'] ?? 'Factura'));
    if (isset($factura['punto_venta'], $factura['numero'])) {
        $facturaLabel .= ' ' . sprintf('%04d-%08d', (int)$factura['punto_venta'], (int)$factura['numero']);
    }
}

$pageTitle = 'Notas de crédito';
$currentSection = 'facturacion';
$breadcrumb = [
    ['label' => 'Facturación', 'url' => 'facturacion.php'],
    ['label' => 'Notas de crédito', 'url' => ''],
];
$extraCss = ['assets/css/facturacion.css?v=10', 'assets/css/facturacion_nc.css?v=1'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap nc-page">
  <div class="panel fact-panel nc-panel">
    <header class="page-header module-header">
      <div class="module-header-main">
        <div class="module-header-hero">
          <span class="module-header-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
              <path d="M5 5h14v14H5z"></path>
              <path d="M8 9h8"></path>
              <path d="M8 13h5"></path>
              <path d="M9 17l-3-3 3-3"></path>
            </svg>
          </span>
          <div class="module-header-copy">
            <span class="module-eyebrow">Operación fiscal</span>
            <h1 class="page-title module-title">Notas de crédito</h1>
            <p class="page-sub module-subtitle">
              Gestiona NC totales o parciales sobre facturas emitidas desde FLUS, separando el flujo fiscal del comercial.
              <span class="modo-badge <?= stripos($modoActual, 'Homo') !== false ? 'modo-homo' : 'modo-demo' ?>"><?= nc_h($modoActual) ?></span>
            </p>
          </div>
        </div>
      </div>

      <div class="promo-actions-top module-header-actions">
        <?php if (function_exists('user_has_permission') && user_has_permission('administrar_config')): ?>
          <a href="facturacion_nc_recovery.php" class="v-btn v-btn--outline" style="color:var(--color-danger,#dc2626);">Recovery ERROR_POST_ARCA</a>
        <?php endif; ?>
        <a href="facturacion.php" class="v-btn v-btn--outline">Volver a facturación</a>
      </div>
    </header>

    <?php if ($ncOk !== ''): ?>
      <div class="alert alert-success" style="margin-bottom:12px;"><?= nc_h($ncOk) ?></div>
    <?php endif; ?>
    <?php if ($ncError !== ''): ?>
      <div class="alert alert-error" style="margin-bottom:12px;"><?= nc_h($ncError) ?></div>
    <?php endif; ?>

    <section class="nc-search-card">
      <div>
        <h2>Buscar factura origen</h2>
        <p>Busca por cliente, comprobante o venta para abrir una factura y gestionar su nota de crédito.</p>
      </div>

      <form method="get" class="nc-search-form">
        <input type="text" name="q" value="<?= nc_h($q) ?>" placeholder="Cliente, CAE, FA 0001-00000017 o número">
        <input type="number" name="venta_id" min="1" step="1" value="<?= $ventaIdFiltro > 0 ? (int)$ventaIdFiltro : '' ?>" placeholder="Venta #">
        <button type="submit" class="btn btn-filter">Buscar</button>
        <a href="facturacion_nc.php" class="btn btn-secondary">Limpiar</a>
      </form>
    </section>

    <section class="nc-layout">
      <aside class="nc-sidebar panel-muted">
        <div class="nc-sidebar-head">
          <strong>Facturas cargadas</strong>
          <span><?= count($candidates) ?> resultado<?= count($candidates) === 1 ? '' : 's' ?></span>
        </div>

        <?php if ($candidates === []): ?>
          <div class="nc-empty-box">No hay facturas para esta búsqueda.</div>
        <?php else: ?>
          <div class="nc-invoice-list">
            <?php foreach ($candidates as $candidate): ?>
              <?php
                $cid = (int)($candidate['id'] ?? 0);
                $isSelected = $factura && $cid === (int)$factura['id'];
                $label = trim((string)($candidate['tipo'] ?? 'Factura'));
                if (isset($candidate['punto_venta'], $candidate['numero'])) {
                    $label .= ' ' . sprintf('%04d-%08d', (int)$candidate['punto_venta'], (int)$candidate['numero']);
                }
                $clienteNombre = trim((string)($candidate['cliente_nombre'] ?? '')) ?: 'Consumidor Final';
              ?>
              <a href="<?= nc_h(nc_build_url(['factura_id' => $cid])) ?>" class="nc-invoice-card <?= $isSelected ? 'is-active' : '' ?>">
                <strong><?= nc_h($label) ?></strong>
                <span><?= nc_h($clienteNombre) ?></span>
                <small><?= nc_money((float)($candidate['total'] ?? 0)) ?><?= !empty($candidate['venta_id']) ? ' · Venta #' . (int)$candidate['venta_id'] : '' ?></small>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </aside>

      <section class="nc-main">
        <?php if (!$factura): ?>
          <div class="nc-empty-state">
            <h3>Selecciona una factura</h3>
            <p>Desde acá vas a poder emitir una NC total o parcial sobre una factura emitida por FLUS.</p>
          </div>
        <?php else: ?>
          <section class="nc-summary-grid">
            <article class="nc-summary-card nc-summary-card--wide">
              <span class="nc-summary-label">Factura origen</span>
              <strong class="nc-summary-value"><?= nc_h($facturaLabel) ?></strong>
              <span class="nc-summary-help">
                <?= nc_h(trim((string)($factura['cliente_nombre'] ?? '')) ?: 'Consumidor Final') ?>
                <?php if (!empty($factura['venta_id'])): ?> · Venta #<?= (int)$factura['venta_id'] ?><?php endif; ?>
              </span>
            </article>
            <article class="nc-summary-card">
              <span class="nc-summary-label">Total original</span>
              <strong class="nc-summary-value"><?= nc_money((float)($factura['total'] ?? 0)) ?></strong>
              <span class="nc-summary-help">CAE <?= nc_h((string)($factura['cae'] ?? '-')) ?></span>
            </article>
            <article class="nc-summary-card">
              <span class="nc-summary-label">NC emitidas</span>
              <strong class="nc-summary-value"><?= (int)($ncResumen['count'] ?? 0) ?></strong>
              <span class="nc-summary-help">Acreditado <?= nc_money((float)($ncResumen['total'] ?? 0)) ?></span>
            </article>
            <article class="nc-summary-card">
              <span class="nc-summary-label">Saldo fiscal</span>
              <strong class="nc-summary-value"><?= nc_money($saldoFiscalTotal) ?></strong>
              <span class="nc-summary-help"><?= $saldoItems ?> línea<?= $saldoItems === 1 ? '' : 's' ?> con saldo</span>
            </article>
            <article class="nc-summary-card">
              <span class="nc-summary-label">Estado venta</span>
              <strong class="nc-summary-value"><?= nc_h($ventaEstado !== '' ? $ventaEstado : 'Sin venta') ?></strong>
              <span class="nc-summary-help"><?= !empty($factura['venta_id']) ? 'Operable desde FLUS' : 'Sin venta vinculada' ?></span>
            </article>
          </section>

          <?php if ((int)($factura['venta_id'] ?? 0) <= 0): ?>
            <div class="alert alert-error" style="margin-top:12px;">Esta factura no tiene una venta vinculada en FLUS. El módulo muestra la referencia, pero la emisión automática de NC desde UI requiere <code>venta_id</code>.</div>
          <?php endif; ?>

          <section class="nc-block">
            <div class="nc-block-head">
              <div>
                <h3>NC parcial por línea</h3>
                <p>Define cuánto saldo fiscal queda disponible por ítem y cuánto querés acreditar ahora.</p>
              </div>
            </div>

            <?php if ($lineas === []): ?>
              <div class="nc-empty-box">No hay líneas de factura reconstruibles para esta operación.</div>
            <?php else: ?>
              <form method="post" action="facturacion_nc_emitir.php" class="nc-form-block">
                <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? nc_h((string)csrf_token()) : '' ?>">
                <input type="hidden" name="modo_operacion" value="PARTIAL">
                <input type="hidden" name="factura_id" value="<?= (int)$factura['id'] ?>">
                <input type="hidden" name="venta_id" value="<?= (int)($factura['venta_id'] ?? 0) ?>">

                <div class="table-wrapper">
                  <table class="mov-table nc-table">
                    <thead>
                      <tr>
                        <th>Línea</th>
                        <th>Producto</th>
                        <th class="t-right">Original</th>
                        <th class="t-right">Acreditada</th>
                        <th class="t-right">Disponible</th>
                        <th class="t-right">Saldo fiscal</th>
                        <th class="t-right">Acreditar ahora</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($lineas as $idx => $linea): ?>
                        <?php $available = (float)$linea['cantidad_disponible']; ?>
                        <tr>
                          <td>#<?= (int)($linea['linea_orden'] ?: ($idx + 1)) ?></td>
                          <td>
                            <div class="nc-product-title"><?= nc_h((string)$linea['descripcion']) ?></div>
                            <div class="nc-product-meta">
                              <?= !empty($linea['codigo']) ? 'Cod. ' . nc_h((string)$linea['codigo']) . ' · ' : '' ?>
                              IVA <?= nc_h(number_format((float)$linea['iva_porcentaje'], 2, ',', '.')) ?>%
                              <?php if (($linea['source'] ?? '') !== 'factura_items'): ?> · legacy<?php endif; ?>
                            </div>
                          </td>
                          <td class="t-right"><?= nc_qty((float)$linea['cantidad_original']) ?></td>
                          <td class="t-right"><?= nc_qty((float)$linea['cantidad_acreditada']) ?></td>
                          <td class="t-right"><?= nc_qty($available) ?></td>
                          <td class="t-right"><?= nc_money((float)$linea['subtotal_disponible']) ?></td>
                          <td class="t-right">
                            <?php if ($available > 0.0009): ?>
                              <input class="nc-qty-input" type="number" name="items[<?= $idx ?>][cantidad]" min="0" max="<?= nc_h((string)$available) ?>" step="0.001" value="0">
                              <input type="hidden" name="items[<?= $idx ?>][item_id]" value="<?= (int)$linea['venta_item_id'] ?>">
                            <?php else: ?>
                              <span class="fact-inline-badge fact-inline-badge--warn">Sin saldo</span>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div class="nc-form-footer">
                  <div class="nc-form-fields">
                    <label>
                      <span>Motivo</span>
                      <input type="text" name="motivo" maxlength="255" placeholder="Ej: Devolución parcial por mercadería dañada">
                    </label>
                  </div>
                  <button type="submit" class="v-btn v-btn--primary" <?= $canEmitir ? '' : 'disabled' ?>>Emitir NC parcial</button>
                </div>
              </form>
            <?php endif; ?>
          </section>

          <section class="nc-block">
            <div class="nc-block-head">
              <div>
                <h3>NC total</h3>
                <p>Usa este flujo cuando el comercio necesite cancelar fiscalmente toda la venta facturada.</p>
              </div>
            </div>

            <form method="post" action="facturacion_nc_emitir.php" class="nc-total-form">
              <input type="hidden" name="csrf_token" value="<?= function_exists('csrf_token') ? nc_h((string)csrf_token()) : '' ?>">
              <input type="hidden" name="modo_operacion" value="TOTAL">
              <input type="hidden" name="factura_id" value="<?= (int)$factura['id'] ?>">
              <input type="hidden" name="venta_id" value="<?= (int)($factura['venta_id'] ?? 0) ?>">

              <div class="nc-total-kpis">
                <div><span>Total a acreditar</span><strong><?= nc_money($saldoFiscalTotal) ?></strong></div>
                <div><span>NC previas</span><strong><?= (int)$ncResumen['count'] ?></strong></div>
                <div><span>Estado de venta</span><strong><?= nc_h($ventaEstado !== '' ? $ventaEstado : 'Sin venta') ?></strong></div>
              </div>

              <label>
                <span>Motivo</span>
                <input type="text" name="motivo" maxlength="255" placeholder="Ej: Anulación total de la operación">
              </label>

              <button type="submit" class="v-btn v-btn--outline" <?= $canEmitir ? '' : 'disabled' ?>>Emitir NC total</button>
            </form>
          </section>

          <?php if (($ncResumen['rows'] ?? []) !== []): ?>
            <section class="nc-block">
              <div class="nc-block-head">
                <div>
                  <h3>Notas de crédito ya emitidas</h3>
                  <p>Historial fiscal ya aplicado sobre esta factura origen.</p>
                </div>
              </div>

              <div class="table-wrapper">
                <table class="mov-table nc-table">
                  <thead>
                    <tr>
                      <th>Fecha</th>
                      <th>Comprobante</th>
                      <th class="t-right">Total</th>
                      <th>Estado</th>
                      <th>Acciones</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach (($ncResumen['rows'] ?? []) as $row): ?>
                      <?php
                        $label = trim((string)($row['tipo'] ?? 'NC'));
                        if (isset($row['punto_venta'], $row['numero'])) {
                            $label .= ' ' . sprintf('%04d-%08d', (int)$row['punto_venta'], (int)$row['numero']);
                        }
                      ?>
                      <tr>
                        <td><?= nc_h((string)($row['fecha'] ?? $row['creado_en'] ?? '')) ?></td>
                        <td><?= nc_h($label) ?></td>
                        <td class="t-right"><?= nc_money((float)($row['total'] ?? 0)) ?></td>
                        <td><?= nc_h((string)($row['estado'] ?? 'EMITIDA')) ?></td>
                        <td>
                          <div class="fact-row-actions">
                            <a href="factura_ver.php?id=<?= (int)$row['id'] ?>" class="btn-mini">Ver</a>
                            <a href="factura_pdf.php?id=<?= (int)$row['id'] ?>" class="btn-mini btn-mini--ghost">PDF</a>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </section>
          <?php endif; ?>
        <?php endif; ?>
      </section>
    </section>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

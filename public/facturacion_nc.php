<?php
// public/facturacion_nc.php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../src/db_schema.php';
require_once __DIR__ . '/../src/facturacion_lib.php';
require_once __DIR__ . '/../src/venta_anulaciones_lib.php';
require_once __DIR__ . '/../src/Fiscal/bootstrap.php';

require_login();
require_any_permission(['ver_facturacion', 'emitir_factura', 'emitir_nota_credito']);

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
        $searchLike = nc_search_like($search);
        $likeIndex = 0;
        $nextLikeParam = static function () use (&$likeIndex): string {
            $likeIndex++;
            return ':search_like_' . $likeIndex;
        };

        if ($joinClientes) {
            $param = $nextLikeParam();
            $clauses[] = "{$clienteNombreExpr} LIKE {$param} ESCAPE '\\\\'";
            $params[$param] = $searchLike;
            if ($clienteCuitExpr !== 'NULL') {
                $param = $nextLikeParam();
                $clauses[] = "{$clienteCuitExpr} LIKE {$param} ESCAPE '\\\\'";
                $params[$param] = $searchLike;
            }
        }
        if (flus_column_exists($pdo, 'facturas', 'cae')) {
            $param = $nextLikeParam();
            $clauses[] = "f.cae LIKE {$param} ESCAPE '\\\\'";
            $params[$param] = $searchLike;
        }
        if (flus_column_exists($pdo, 'facturas', 'tipo')) {
            $param = $nextLikeParam();
            $clauses[] = "f.tipo LIKE {$param} ESCAPE '\\\\'";
            $params[$param] = $searchLike;
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

/**
 * @return array<int,array<string,mixed>>
 */
function nc_fetch_venta_items_base(PDO $pdo, int $ventaId): array
{
    if ($ventaId <= 0 || !flus_table_exists($pdo, 'venta_items')) {
        return [];
    }

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

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Vincula conservadoramente factura_items sin venta_item_id usando venta_items de la venta.
 * Solo asigna cuando encuentra un candidato único y razonable.
 *
 * @param array<int,array<string,mixed>> $facturaItems
 * @return array<int,array<string,mixed>>
 */
function nc_link_missing_factura_items(PDO $pdo, int $ventaId, array $facturaItems): array
{
    if ($ventaId <= 0 || $facturaItems === []) {
        return $facturaItems;
    }

    $ventaRows = nc_fetch_venta_items_base($pdo, $ventaId);
    if ($ventaRows === []) {
        return $facturaItems;
    }

    $available = [];
    foreach ($ventaRows as $row) {
        $available[] = [
            'id' => (int)($row['id'] ?? 0),
            'producto_id' => (int)($row['producto_id'] ?? 0),
            'descripcion' => trim((string)($row['nombre'] ?? $row['descripcion'] ?? '')),
            'cantidad' => round((float)($row['cantidad'] ?? 0), 3),
            'subtotal' => round((float)($row['subtotal'] ?? 0), 2),
        ];
    }

    $used = [];
    foreach ($facturaItems as $item) {
        if ((int)($item['venta_item_id'] ?? 0) > 0) {
            $used[(int)$item['venta_item_id']] = true;
        }
    }

    foreach ($facturaItems as $idx => $item) {
        if ((int)($item['venta_item_id'] ?? 0) > 0) {
            continue;
        }

        $productoId = (int)($item['producto_id'] ?? 0);
        $cantidad = round((float)($item['cantidad_original'] ?? 0), 3);
        $subtotal = round((float)($item['subtotal_original'] ?? 0), 2);
        $descripcion = trim((string)($item['descripcion'] ?? ''));
        $lineaOrden = (int)($item['linea_orden'] ?? 0);

        $candidates = array_values(array_filter($available, static function (array $ventaItem) use ($used, $productoId, $cantidad, $subtotal, $descripcion): bool {
            $id = (int)($ventaItem['id'] ?? 0);
            if ($id <= 0 || isset($used[$id])) {
                return false;
            }

            $sameProducto = $productoId > 0 && (int)($ventaItem['producto_id'] ?? 0) === $productoId;
            $sameCantidad = abs(((float)($ventaItem['cantidad'] ?? 0)) - $cantidad) <= 0.0009;
            $sameSubtotal = abs(((float)($ventaItem['subtotal'] ?? 0)) - $subtotal) <= 0.01;
            $sameDescripcion = $descripcion !== ''
                && mb_strtolower(trim((string)($ventaItem['descripcion'] ?? '')), 'UTF-8') === mb_strtolower($descripcion, 'UTF-8');

            return ($sameProducto && $sameCantidad)
                || ($sameProducto && $sameSubtotal)
                || ($sameCantidad && $sameSubtotal)
                || ($sameDescripcion && $sameCantidad);
        }));

        if (count($candidates) !== 1 && $lineaOrden > 0 && isset($available[$lineaOrden - 1])) {
            $fallback = $available[$lineaOrden - 1];
            $fallbackId = (int)($fallback['id'] ?? 0);
            if ($fallbackId > 0 && !isset($used[$fallbackId])) {
                $fallbackMatches = ($productoId > 0 && (int)($fallback['producto_id'] ?? 0) === $productoId)
                    || ($descripcion !== '' && mb_strtolower((string)($fallback['descripcion'] ?? ''), 'UTF-8') === mb_strtolower($descripcion, 'UTF-8'));
                if ($fallbackMatches) {
                    $candidates = [$fallback];
                }
            }
        }

        if (count($candidates) === 1) {
            $ventaItemId = (int)($candidates[0]['id'] ?? 0);
            if ($ventaItemId > 0) {
                $facturaItems[$idx]['venta_item_id'] = $ventaItemId;
                $facturaItems[$idx]['source'] = 'factura_items_linked';
                $used[$ventaItemId] = true;
            }
        }
    }

    return $facturaItems;
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
            return nc_link_missing_factura_items($pdo, $ventaId, $items);
        }
    }

    if ($ventaId > 0 && flus_table_exists($pdo, 'venta_items')) {
        $rows = nc_fetch_venta_items_base($pdo, $ventaId);
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

/* ── parámetros GET ────────────────────────────────────────── */
$q             = trim((string)($_GET['q'] ?? ''));
$ventaIdFiltro = max(0, (int)($_GET['venta_id'] ?? 0));
$facturaId     = max(0, (int)($_GET['factura_id'] ?? 0));
$ncOk          = trim((string)($_GET['nc_ok'] ?? ''));
$ncError       = trim((string)($_GET['nc_error'] ?? ''));

$candidates = nc_fetch_candidate_facturas($pdo, $q, $ventaIdFiltro, 40);
if ($facturaId <= 0 && $ventaIdFiltro > 0) {
    foreach ($candidates as $candidate) {
        if ((int)($candidate['venta_id'] ?? 0) === $ventaIdFiltro) {
            $facturaId = (int)($candidate['id'] ?? 0);
            break;
        }
    }
}

$factura      = $facturaId > 0 ? nc_fetch_factura_origen($pdo, $facturaId) : null;
$facturaItems = $factura ? nc_fetch_factura_origen_items($pdo, $factura) : [];
$ncResumen    = $factura
    ? nc_fetch_resumen_notas_credito($pdo, (int)$factura['id'])
    : ['count' => 0, 'total' => 0.0, 'rows' => [], 'credited_qty' => []];
$creditedQty  = $ncResumen['credited_qty'] ?? [];

$lineas                = [];
$saldoFiscalTotal      = 0.0;
$saldoItems            = 0;
$partialEligibleCount  = 0;
$partialUnlinkedCount  = 0;
foreach ($facturaItems as $row) {
    $ventaItemId       = (int)($row['venta_item_id'] ?? 0);
    $cantidadOriginal  = round((float)($row['cantidad_original'] ?? 0), 3);
    $cantidadAcreditada = round((float)($creditedQty[$ventaItemId] ?? 0), 3);
    $cantidadDisponible = max(0.0, round($cantidadOriginal - $cantidadAcreditada, 3));
    $subtotalOriginal  = round((float)($row['subtotal_original'] ?? 0), 2);
    $factor            = $cantidadOriginal > 0 ? ($cantidadDisponible / $cantidadOriginal) : 0.0;
    $subtotalDisponible = round($subtotalOriginal * $factor, 2);
    $saldoFiscalTotal  += $subtotalDisponible;
    $hasVentaItemLink = $ventaItemId > 0;
    if ($cantidadDisponible > 0.0009) {
        $saldoItems++;
        if ($hasVentaItemLink) {
            $partialEligibleCount++;
        } else {
            $partialUnlinkedCount++;
        }
    }

    $lineas[] = $row + [
        'has_venta_item_link'   => $hasVentaItemLink,
        'cantidad_acreditada'  => $cantidadAcreditada,
        'cantidad_disponible'  => $cantidadDisponible,
        'subtotal_disponible'  => $subtotalDisponible,
    ];
}

$modoActual  = flus_facturacion_modo_label(flus_facturacion_modo_actual(flus_facturacion_config_activa($pdo) ?? []));
$canEmitir   = $factura && (int)($factura['venta_id'] ?? 0) > 0 && $saldoFiscalTotal > 0.009;
$ventaEstado = trim((string)($factura['venta_estado'] ?? ''));
$facturaLabel = '';
if ($factura) {
    $facturaLabel = trim((string)($factura['tipo'] ?? 'Factura'));
    if (isset($factura['punto_venta'], $factura['numero'])) {
        $facturaLabel .= ' ' . sprintf('%04d-%08d', (int)$factura['punto_venta'], (int)$factura['numero']);
    }
}

$clienteLabel = trim((string)($factura['cliente_nombre'] ?? '')) ?: 'Consumidor Final';

// Precompute unit prices for JS (subtotal_disponible / cantidad_disponible)
$unitPrices = [];
foreach ($lineas as $idx => $linea) {
    $avail = (float)$linea['cantidad_disponible'];
    $unitPrices[$idx] = $avail > 0.0009
        ? round((float)$linea['subtotal_disponible'] / $avail, 4)
        : 0.0;
}

$csrfToken  = function_exists('csrf_token') ? (string)csrf_token() : '';

$pageTitle      = 'Notas de crédito';
$currentSection = 'facturacion';
$breadcrumbs    = [
    ['label' => 'Facturación', 'url' => 'facturacion.php'],
    ['label' => 'Notas de crédito', 'url' => null],
];
$extraCss = [
    'assets/css/facturacion.css?v=10',
    'assets/css/facturacion_nc.css?v=2',
];
$extraJs = ['assets/js/facturacion_nc.js?v=3'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap nc-page" data-nc-has-factura="<?= $factura ? '1' : '0' ?>">
  <div class="panel fact-panel nc-wrap">

    <!-- ── Header del módulo ─────────────────────────────── -->
    <header class="page-header module-header">
      <div class="module-header-main">
        <div class="module-header-hero">
          <span class="module-header-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M9 14l-4-4 4-4"/>
              <path d="M5 10h11a4 4 0 0 1 0 8h-1"/>
            </svg>
          </span>
          <div class="module-header-copy">
            <span class="module-eyebrow">Operación fiscal</span>
            <h1 class="page-title module-title">Notas de crédito</h1>
            <p class="page-sub module-subtitle">
              Emitís NC totales o parciales sobre facturas FLUS. El flujo fiscal y comercial quedan separados.
              <span class="modo-badge <?= stripos($modoActual, 'Homo') !== false ? 'modo-homo' : 'modo-demo' ?>"><?= nc_h($modoActual) ?></span>
            </p>
          </div>
        </div>
      </div>

      <div class="promo-actions-top module-header-actions">
        <?php if (function_exists('user_has_permission') && user_has_permission('administrar_config')): ?>
          <a href="facturacion_nc_recovery.php" class="v-btn v-btn--outline" style="color:var(--nc-danger,#ef4444);">
            Recovery ERROR_POST_ARCA
          </a>
        <?php endif; ?>
        <a href="facturacion.php" class="v-btn v-btn--outline">Volver a facturación</a>
      </div>
    </header>

    <nav class="nc-stepper" aria-label="Pasos">
      <div class="nc-step-item" data-step="1">
        <div class="nc-step-badge">
          <?php if ($factura): ?>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" width="14" height="14"><path d="M20 6L9 17l-5-5"/></svg>
          <?php else: ?>1<?php endif; ?>
        </div>
        <span class="nc-step-label">Seleccionar factura</span>
      </div>
      <div class="nc-step-divider"></div>
      <div class="nc-step-item" data-step="2">
        <div class="nc-step-badge">2</div>
        <span class="nc-step-label">Elegir tipo de NC</span>
      </div>
      <div class="nc-step-divider"></div>
      <div class="nc-step-item" data-step="3">
        <div class="nc-step-badge">3</div>
        <span class="nc-step-label">Configurar y emitir</span>
      </div>
    </nav>

    <!-- ── Alertas ───────────────────────────────────────── -->
    <?php if ($ncOk !== ''): ?>
      <div class="nc-alert nc-alert--success">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <span><?= nc_h($ncOk) ?></span>
      </div>
    <?php endif; ?>
    <?php if ($ncError !== ''): ?>
      <div class="nc-alert nc-alert--error">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span><?= nc_h($ncError) ?></span>
      </div>
    <?php endif; ?>

    <!-- ── Body: sidebar + main ──────────────────────────── -->
    <div class="nc-body">

      <!-- ── Sidebar: buscar y listar facturas ───────────── -->
      <aside class="nc-sidebar" aria-label="Facturas disponibles">

        <div class="nc-sidebar-head">
          <h2>Factura origen</h2>
          <p>Buscá por cliente, comprobante o número de venta</p>
        </div>

        <form method="get" class="nc-search-form">
          <input
            type="text"
            name="q"
            value="<?= nc_h($q) ?>"
            placeholder="Ej: García, FA 0001-17, CAE…"
            aria-label="Buscar factura"
            autocomplete="off"
          >
          <div class="nc-search-row">
            <input
              type="number"
              name="venta_id"
              min="1"
              step="1"
              value="<?= $ventaIdFiltro > 0 ? (int)$ventaIdFiltro : '' ?>"
              placeholder="Venta #"
              aria-label="Número de venta"
            >
            <button type="submit" class="btn btn-filter">Buscar</button>
          </div>
          <?php if ($q !== '' || $ventaIdFiltro > 0): ?>
            <a href="facturacion_nc.php" class="btn btn-secondary" style="text-align:center;font-size:.8rem;">Limpiar filtros</a>
          <?php endif; ?>
        </form>

        <?php if ($candidates === []): ?>
          <div class="nc-empty-sidebar">
            <?php if ($q !== '' || $ventaIdFiltro > 0): ?>
              Sin resultados para esta búsqueda.
            <?php else: ?>
              Ingresá un término para buscar facturas.
            <?php endif; ?>
          </div>
        <?php else: ?>
          <p class="nc-invoice-count"><?= count($candidates) ?> factura<?= count($candidates) === 1 ? '' : 's' ?> encontrada<?= count($candidates) === 1 ? '' : 's' ?></p>
          <div class="nc-invoice-list">
            <?php foreach ($candidates as $candidate):
              $cid      = (int)($candidate['id'] ?? 0);
              $isSelected = $factura && $cid === (int)$factura['id'];
              $lbl      = trim((string)($candidate['tipo'] ?? 'Factura'));
              if (isset($candidate['punto_venta'], $candidate['numero'])) {
                  $lbl .= ' ' . sprintf('%04d-%08d', (int)$candidate['punto_venta'], (int)$candidate['numero']);
              }
              $cNombre = trim((string)($candidate['cliente_nombre'] ?? '')) ?: 'Consumidor Final';
              $cDate   = !empty($candidate['fecha_ref']) ? date('d/m/Y', strtotime((string)$candidate['fecha_ref'])) : '';
            ?>
              <a
                href="<?= nc_h(nc_build_url(['factura_id' => $cid])) ?>"
                class="nc-invoice-card <?= $isSelected ? 'is-active' : '' ?>"
                title="Ver factura <?= nc_h($lbl) ?>"
              >
                <strong><?= nc_h($lbl) ?></strong>
                <span><?= nc_h($cNombre) ?></span>
                <small>
                  <?= nc_money((float)($candidate['total'] ?? 0)) ?>
                  <?= $cDate !== '' ? ' · ' . nc_h($cDate) : '' ?>
                  <?= !empty($candidate['venta_id']) ? ' · Venta #' . (int)$candidate['venta_id'] : '' ?>
                </small>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

      </aside>

      <!-- ── Main ──────────────────────────────────────────── -->
      <main class="nc-main">

        <?php if (!$factura): ?>
          <!-- ── Empty state ────────────────────────────── -->
          <div class="nc-empty-state">
            <div class="nc-empty-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <rect x="3" y="3" width="18" height="18" rx="3"/>
                <path d="M8 9h8M8 13h5M9 17l-3-3 3-3"/>
              </svg>
            </div>
            <h3>Seleccioná una factura para comenzar</h3>
            <p>Las notas de crédito permiten acreditar total o parcialmente una factura emitida ante ARCA. El proceso es guiado y requiere confirmación antes de enviarse.</p>
            <div class="nc-empty-hints">
              <div class="nc-empty-hint">
                <span class="hint-num">1</span>
                Buscá la factura por cliente, CAE o número (ej: <em>FA 0001-17</em>)
              </div>
              <div class="nc-empty-hint">
                <span class="hint-num">2</span>
                Elegí si querés acreditar toda la factura o solo algunos ítems
              </div>
              <div class="nc-empty-hint">
                <span class="hint-num">3</span>
                Revisá el resumen y confirmá — ARCA recibirá la NC al instante
              </div>
            </div>
          </div>

        <?php else: ?>

          <!-- ── Contexto de la factura seleccionada ──────── -->
          <div class="nc-context-strip">
            <div class="nc-context-factura">
              <div class="nc-context-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                  <polyline points="14 2 14 8 20 8"/>
                  <line x1="16" y1="13" x2="8" y2="13"/>
                  <line x1="16" y1="17" x2="8" y2="17"/>
                  <polyline points="10 9 9 9 8 9"/>
                </svg>
              </div>
              <div class="nc-context-info">
                <span class="nc-context-label">Factura seleccionada</span>
                <span class="nc-context-title"><?= nc_h($facturaLabel) ?></span>
                <span class="nc-context-sub">
                  <?= nc_h($clienteLabel) ?>
                  <?php if (!empty($factura['venta_id'])): ?> · Venta #<?= (int)$factura['venta_id'] ?><?php endif; ?>
                  <?php if (!empty($factura['cae'])): ?> · CAE <?= nc_h((string)$factura['cae']) ?><?php endif; ?>
                </span>
              </div>
            </div>

            <div class="nc-context-kpis">
              <div class="nc-kpi">
                <span class="nc-kpi-label">Total original</span>
                <span class="nc-kpi-value"><?= nc_money((float)($factura['total'] ?? 0)) ?></span>
              </div>
              <div class="nc-kpi">
                <span class="nc-kpi-label">Ya acreditado</span>
                <span class="nc-kpi-value <?= $ncResumen['count'] > 0 ? 'nc-kpi-value--warn' : '' ?>">
                  <?= nc_money((float)$ncResumen['total']) ?>
                </span>
              </div>
              <div class="nc-kpi">
                <span class="nc-kpi-label">Saldo disponible</span>
                <span class="nc-kpi-value <?= $saldoFiscalTotal > 0 ? 'nc-kpi-value--ok' : 'nc-kpi-value--danger' ?>">
                  <?= nc_money($saldoFiscalTotal) ?>
                </span>
              </div>
            </div>
          </div>

          <?php if ((int)($factura['venta_id'] ?? 0) <= 0): ?>
            <div class="nc-alert nc-alert--warn">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
              <span>Esta factura no tiene una venta vinculada en FLUS. La emisión automática de NC desde la UI requiere <code>venta_id</code>.</span>
            </div>
          <?php endif; ?>

          <?php if (!$canEmitir && $saldoFiscalTotal <= 0.009): ?>
            <div class="nc-alert nc-alert--warn">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
              <span>Esta factura no tiene saldo fiscal disponible para emitir NC. Es posible que ya haya sido acreditada por completo.</span>
            </div>
          <?php endif; ?>

          <?php if ($partialUnlinkedCount > 0): ?>
            <div class="nc-alert nc-alert--warn">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
              <span><?= (int)$partialUnlinkedCount ?> línea<?= $partialUnlinkedCount === 1 ? '' : 's' ?> con saldo no tienen vínculo confiable con <code>venta_items</code>. Se muestran, pero no podrán incluirse en una NC parcial hasta corregir el vínculo.</span>
            </div>
          <?php endif; ?>

          <!-- ── Paso 2: Elegir tipo de NC ─────────────────── -->
          <div class="nc-block" id="nc-step2">
            <div class="nc-block-head">
              <div>
                <h3>¿Qué tipo de nota de crédito necesitás emitir?</h3>
                <p>Elegí la opción según el caso comercial. Podrás revisar los detalles antes de confirmar.</p>
              </div>
            </div>

            <div class="nc-type-selector">

              <!-- Card: NC Parcial -->
              <label
                class="nc-type-card nc-type-card--primary"
                data-value="PARTIAL"
                title="Acreditar solo algunos ítems o cantidades parciales"
              >
                <input type="radio" name="nc_type" value="PARTIAL" <?= !$canEmitir ? 'disabled' : '' ?>>
                <div class="nc-type-check">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg>
                </div>
                <div class="nc-type-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <rect x="3" y="3" width="18" height="18" rx="3"/>
                    <path d="M8 12h8M8 8h5"/>
                    <path d="M12 16v2M12 18l-2-2M12 18l2-2"/>
                  </svg>
                </div>
                <div>
                  <div class="nc-type-title">NC Parcial</div>
                  <div class="nc-type-desc">Acreditás solo los ítems o cantidades que elegís. El resto de la factura queda vigente.</div>
                </div>
                <span class="nc-type-badge">Más flexible</span>
              </label>

              <!-- Card: NC Total -->
              <label
                class="nc-type-card nc-type-card--danger"
                data-value="TOTAL"
                title="Cancelar fiscalmente la factura completa"
              >
                <input type="radio" name="nc_type" value="TOTAL" <?= !$canEmitir ? 'disabled' : '' ?>>
                <div class="nc-type-check">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg>
                </div>
                <div class="nc-type-icon">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M3 6h18M19 6l-1 14H6L5 6"/>
                    <path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/>
                    <line x1="10" y1="11" x2="10" y2="17"/>
                    <line x1="14" y1="11" x2="14" y2="17"/>
                  </svg>
                </div>
                <div>
                  <div class="nc-type-title">NC Total</div>
                  <div class="nc-type-desc">Cancela fiscalmente el saldo completo disponible de la factura. Usalo para anulaciones totales.</div>
                </div>
                <span class="nc-type-badge">Anulación completa</span>
              </label>

            </div>
          </div>

          <!-- ── Paso 3: Formularios (ocultos hasta elegir tipo) ── -->
          <div id="nc-step3" hidden>

            <!-- ── NC PARCIAL ──────────────────────────────── -->
            <div id="nc-form-parcial" hidden>
              <div class="nc-block">
                <div class="nc-block-head">
                  <div>
                    <h3>NC Parcial — Seleccioná las cantidades a acreditar</h3>
                    <p>Ingresá cuántas unidades de cada ítem querés incluir en la NC. Solo las filas con cantidad mayor a 0 se envían a ARCA.</p>
                  </div>
                  <div class="nc-items-toolbar">
                    <div class="nc-toolbar-actions">
                      <button type="button" id="nc-select-all" class="btn btn-filter" title="Completar todas las cantidades disponibles">
                        Seleccionar todo
                      </button>
                      <button type="button" id="nc-clear-all" class="btn btn-secondary" title="Borrar todas las cantidades">
                        Limpiar
                      </button>
                    </div>
                  </div>
                </div>

                <?php if ($lineas === []): ?>
                  <div class="nc-block-body" style="text-align:center;color:var(--nc-muted);">
                    No se encontraron líneas de factura para esta operación.
                  </div>
                <?php else: ?>

                  <form method="post" action="facturacion_nc_emitir.php" id="form-nc-parcial">
                    <input type="hidden" name="csrf_token"      value="<?= nc_h($csrfToken) ?>">
                    <input type="hidden" name="modo_operacion"  value="PARTIAL">
                    <input type="hidden" name="factura_id"      value="<?= (int)$factura['id'] ?>">
                    <input type="hidden" name="venta_id"        value="<?= (int)($factura['venta_id'] ?? 0) ?>">

                    <div class="table-wrapper">
                      <table class="mov-table nc-table">
                        <thead>
                          <tr>
                            <th style="width:40px">#</th>
                            <th>Producto / Servicio</th>
                            <th class="t-right" title="Cantidad total en la factura original">Cant. original</th>
                            <th class="t-right" title="Cantidad ya acreditada en NC anteriores">Ya acreditado</th>
                            <th class="t-right" title="Cantidad que aún puede acreditarse">Disponible</th>
                            <th class="t-right">Cantidad a devolver</th>
                            <th class="t-right">Subtotal estimado</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($lineas as $idx => $linea):
                            $available    = (float)$linea['cantidad_disponible'];
                            $original     = (float)$linea['cantidad_original'];
                            $acreditada   = (float)$linea['cantidad_acreditada'];
                            $unitPrice    = $unitPrices[$idx] ?? 0.0;
                            $creditPct    = $original > 0 ? min(100, round($acreditada / $original * 100)) : 0;
                            $isFullCredit = $creditPct >= 100;
                            $numLinea     = (int)($linea['linea_orden'] ?: ($idx + 1));
                          ?>
                            <tr
                              class="nc-line-row"
                              data-unit-price="<?= nc_h(number_format($unitPrice, 4, '.', '')) ?>"
                              data-full-amount="<?= nc_h(number_format((float)$linea['subtotal_disponible'], 2, '.', '')) ?>"
                              data-max-qty="<?= nc_h(number_format($available, 3, '.', '')) ?>"
                              data-desc="<?= nc_h(strip_tags((string)$linea['descripcion'])) ?>"
                            >
                              <td style="color:var(--nc-muted);font-size:.82rem;">#<?= $numLinea ?></td>
                              <td>
                                <div class="nc-product-title"><?= nc_h((string)$linea['descripcion']) ?></div>
                                <div class="nc-product-meta">
                                  <?= !empty($linea['codigo']) ? 'Cód. ' . nc_h((string)$linea['codigo']) . ' · ' : '' ?>
                                  IVA <?= nc_h(number_format((float)$linea['iva_porcentaje'], 2, ',', '.')) ?>%
                                  <?php if (($linea['source'] ?? '') === 'venta_items'): ?>
                                    · <em>datos de venta</em>
                                  <?php elseif (($linea['source'] ?? '') === 'factura_items_linked'): ?>
                                    · <em>vínculo recuperado</em>
                                  <?php endif; ?>
                                </div>
                                <?php if ($acreditada > 0.0009): ?>
                                  <div class="nc-credit-bar" title="<?= $creditPct ?>% ya acreditado">
                                    <div
                                      class="nc-credit-bar-fill <?= $isFullCredit ? 'is-full' : '' ?>"
                                      style="width:<?= $creditPct ?>%"
                                    ></div>
                                  </div>
                                <?php endif; ?>
                              </td>
                              <td class="t-right"><?= nc_qty($original) ?></td>
                              <td class="t-right" style="<?= $acreditada > 0 ? 'color:var(--nc-warn);font-weight:600;' : 'color:var(--nc-muted);' ?>">
                                <?= nc_qty($acreditada) ?>
                              </td>
                              <td class="t-right" style="font-weight:600;">
                                <?= nc_qty($available) ?>
                                <div style="font-size:.76rem;font-weight:400;color:var(--nc-muted);"><?= nc_money((float)$linea['subtotal_disponible']) ?></div>
                              </td>
                              <td class="t-right">
                                <?php if ($available > 0.0009 && !empty($linea['has_venta_item_link'])): ?>
                                  <input
                                    class="nc-qty-input"
                                    type="number"
                                    name="items[<?= $idx ?>][cantidad]"
                                    min="0"
                                    max="<?= nc_h((string)$available) ?>"
                                    step="0.001"
                                    value="0"
                                    aria-label="Cantidad a acreditar para <?= nc_h((string)$linea['descripcion']) ?>"
                                  >
                                  <input type="hidden" name="items[<?= $idx ?>][item_id]" value="<?= (int)$linea['venta_item_id'] ?>">
                                <?php elseif ($available > 0.0009): ?>
                                  <span class="nc-no-saldo" title="La línea no tiene vínculo usable con venta_items">Sin vínculo</span>
                                <?php else: ?>
                                  <span class="nc-no-saldo">Sin saldo</span>
                                <?php endif; ?>
                              </td>
                              <td class="t-right">
                                <span class="nc-line-amount-preview">—</span>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>

                    <div class="nc-motivo-row">
                      <label for="nc-motivo-parcial">Motivo de la NC <span style="color:var(--nc-muted);font-weight:400;">(opcional)</span></label>
                      <input
                        type="text"
                        id="nc-motivo-parcial"
                        name="motivo"
                        maxlength="255"
                        placeholder="Ej: Devolución parcial por mercadería dañada"
                      >
                    </div>

                    <div class="nc-submit-footer">
                      <span class="nc-submit-hint">
                        Solo se envían a ARCA los ítems con cantidad mayor a 0. La operación es irreversible.
                      </span>
                      <button
                        type="button"
                        id="nc-submit-parcial"
                        class="v-btn v-btn--primary"
                        <?= $canEmitir ? '' : 'disabled' ?>
                      >
                        Revisar y emitir NC parcial →
                      </button>
                    </div>
                  </form>

                <?php endif; ?>
              </div>
            </div><!-- /nc-form-parcial -->

            <!-- ── NC TOTAL ────────────────────────────────── -->
            <div id="nc-form-total" hidden>
              <div class="nc-block">
                <div class="nc-block-head">
                  <div>
                    <h3>NC Total — Anulación completa del saldo disponible</h3>
                    <p>Se acreditará todo el saldo fiscal restante de la factura ante ARCA. Esta acción no puede revertirse.</p>
                  </div>
                </div>

                <div class="nc-total-summary">
                  <div class="nc-total-kpi nc-total-kpi--highlight">
                    <span>Total a acreditar</span>
                    <strong class="nc-total-amount-value"><?= nc_money($saldoFiscalTotal) ?></strong>
                  </div>
                  <div class="nc-total-kpi">
                    <span>NC previas emitidas</span>
                    <strong><?= (int)$ncResumen['count'] ?></strong>
                  </div>
                  <div class="nc-total-kpi">
                    <span>Estado de venta</span>
                    <strong><?= nc_h($ventaEstado !== '' ? $ventaEstado : 'Sin venta') ?></strong>
                  </div>
                </div>

                <div class="nc-total-warn">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                  <span>Vas a acreditar fiscalmente <strong><?= nc_money($saldoFiscalTotal) ?></strong> sobre la factura <strong><?= nc_h($facturaLabel) ?></strong>. La operación se enviará a ARCA y no podrá revertirse.</span>
                </div>

                <form method="post" action="facturacion_nc_emitir.php">
                  <input type="hidden" name="csrf_token"     value="<?= nc_h($csrfToken) ?>">
                  <input type="hidden" name="modo_operacion" value="TOTAL">
                  <input type="hidden" name="factura_id"     value="<?= (int)$factura['id'] ?>">
                  <input type="hidden" name="venta_id"       value="<?= (int)($factura['venta_id'] ?? 0) ?>">

                  <div class="nc-motivo-row">
                    <label for="nc-motivo-total">Motivo de la NC <span style="color:var(--nc-muted);font-weight:400;">(opcional)</span></label>
                    <input
                      type="text"
                      id="nc-motivo-total"
                      name="motivo"
                      maxlength="255"
                      placeholder="Ej: Anulación total de la operación"
                    >
                  </div>

                  <div class="nc-submit-footer">
                    <span class="nc-submit-hint">
                      Se enviará la NC total a ARCA. Revisá el resumen antes de confirmar.
                    </span>
                    <button
                      type="button"
                      id="nc-submit-total"
                      class="v-btn"
                      style="background:var(--nc-danger,#ef4444);color:#fff;border:none;"
                      <?= $canEmitir ? '' : 'disabled' ?>
                    >
                      Revisar y emitir NC total →
                    </button>
                  </div>
                </form>

              </div>
            </div><!-- /nc-form-total -->

          </div><!-- /nc-step3 -->

          <!-- ── Historial de NC ya emitidas ──────────────── -->
          <?php if (($ncResumen['rows'] ?? []) !== []): ?>
            <div class="nc-block">
              <div class="nc-block-head">
                <div>
                  <h3>Historial de NC emitidas sobre esta factura</h3>
                  <p><?= (int)$ncResumen['count'] ?> NC · Total acreditado: <?= nc_money((float)$ncResumen['total']) ?></p>
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
                    <?php foreach (($ncResumen['rows'] ?? []) as $row):
                      $lbl = trim((string)($row['tipo'] ?? 'NC'));
                      if (isset($row['punto_venta'], $row['numero'])) {
                          $lbl .= ' ' . sprintf('%04d-%08d', (int)$row['punto_venta'], (int)$row['numero']);
                      }
                    ?>
                      <tr>
                        <td><?= nc_h((string)($row['fecha'] ?? $row['creado_en'] ?? '')) ?></td>
                        <td><?= nc_h($lbl) ?></td>
                        <td class="t-right"><?= nc_money((float)($row['total'] ?? 0)) ?></td>
                        <td><span class="fact-inline-badge"><?= nc_h((string)($row['estado'] ?? 'EMITIDA')) ?></span></td>
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
            </div>
          <?php endif; ?>

        <?php endif; // end if $factura ?>

      </main>

    </div><!-- /nc-body -->

  </div><!-- /panel -->
</div><!-- /page-wrap -->

<!-- ── Sticky live bar ──────────────────────────────────── -->
<div id="nc-live-bar" aria-live="polite" aria-label="Total a acreditar">
  <div class="nc-live-bar-info">
    <span class="nc-live-bar-label">Total NC parcial</span>
    <span id="nc-live-total">$0,00</span>
    <span id="nc-live-count">0 líneas</span>
  </div>
  <button
    type="button"
    id="nc-submit-parcial-bar"
    class="v-btn v-btn--primary"
    onclick="document.getElementById('nc-submit-parcial')?.click()"
  >
    Emitir NC parcial →
  </button>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>

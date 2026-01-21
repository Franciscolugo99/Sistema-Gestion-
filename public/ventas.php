<?php
// public/ventas.php - Versión optimizada usando v_ventas_completo
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_permission('ver_reportes');

/* =========================
   Helpers
========================= */
function has_table(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
  $st->execute([$table]);
  return (bool)$st->fetchColumn();
}

function has_view(PDO $pdo, string $view): bool {
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
  $st->execute([$view]);
  return (bool)$st->fetchColumn();
}
function has_column(PDO $pdo, string $table, string $column): bool {
  $st = $pdo->prepare("
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
    LIMIT 1
  ");
  $st->execute([$table, $column]);
  return (bool)$st->fetchColumn();
}

function first_column(PDO $pdo, string $table, array $candidates): ?string {
  foreach ($candidates as $c) {
    if (has_column($pdo, $table, $c)) return $c;
  }
  return null;
}


/* =========================
   Inicialización segura
========================= */
$stats = [
  'cnt' => 0,
  'sum_total' => 0,
  'sum_pagado' => 0,
  'avg_total' => 0,
  // Nuevas estadísticas
  'cnt_hoy' => 0,
  'sum_hoy' => 0,
  'avg_hoy' => 0,
  'cnt_ayer' => 0,
  'sum_ayer' => 0,
  'diff_ventas' => 0,
  'diff_total' => 0,
  'top_medio' => 'N/A',
  'top_medio_pct' => 0,
];

$ventas = [];
$promosActivas = 0;

$totalRows = 0;
$totalPages = 1;
$page = 1;
$perPage = 20;
$offset = 0;
$fromRow = 0;
$toRow = 0;

// Filtros existentes
$allowedMedios = ['EFECTIVO', 'MP', 'DEBITO', 'CREDITO', 'SIN_ESPECIFICAR'];
$allowedEstados = ['', 'EMITIDA', 'ANULADA'];

$medio = '';
$estado = '';
$desde = '';
$hasta = '';
$venta_id = '';
$min_total_raw = '';
$max_total_raw = '';
$min_total = null;
$max_total = null;

// NUEVOS FILTROS
$cliente_id = '';
$producto_id = '';
$terminal_id = '';
$vendedor_id = '';
$facturado = '';
$con_descuento = '';
$tag = '';

/* =========================
   Detectar tablas/vistas
========================= */
$hasVentaPagos = has_table($pdo, 'venta_pagos');
$hasVentaTags = has_table($pdo, 'venta_tags');
$hasVentasCompleto = has_view($pdo, 'v_ventas_completo');

/* =========================
   Limpiar filtros
========================= */
if (isset($_GET['clear'])) {
  header('Location: ventas.php');
  exit;
}

/* =========================
   Procesar filtros
========================= */
$medio  = strtoupper(trim((string)($_GET['medio'] ?? '')));
$estado = strtoupper(trim((string)($_GET['estado'] ?? '')));

if (!in_array($estado, $allowedEstados, true)) $estado = '';

$desde    = validDateYmd($_GET['desde'] ?? null);
$hasta    = validDateYmd($_GET['hasta'] ?? null);
$venta_id = trim((string)($_GET['venta_id'] ?? ''));

$min_total_raw = (string)($_GET['min_total'] ?? '');
$max_total_raw = (string)($_GET['max_total'] ?? '');

if ($min_total_raw !== '') $min_total = parse_money_ar($min_total_raw);
if ($max_total_raw !== '') $max_total = parse_money_ar($max_total_raw);

if ($min_total !== null && $max_total !== null && $min_total > $max_total) {
  [$min_total, $max_total] = [$max_total, $min_total];
}

// NUEVOS FILTROS
$cliente_id = isset($_GET['cliente_id']) && ctype_digit($_GET['cliente_id']) 
  ? (int)$_GET['cliente_id'] 
  : 0;

$producto_id = isset($_GET['producto_id']) && ctype_digit($_GET['producto_id']) 
  ? (int)$_GET['producto_id'] 
  : 0;

$terminal_id = isset($_GET['terminal_id']) && ctype_digit($_GET['terminal_id']) 
  ? (int)$_GET['terminal_id'] 
  : 0;

$vendedor_id = isset($_GET['vendedor_id']) && ctype_digit($_GET['vendedor_id']) 
  ? (int)$_GET['vendedor_id'] 
  : 0;

$facturado = trim((string)($_GET['facturado'] ?? ''));
$con_descuento = trim((string)($_GET['con_descuento'] ?? ''));
$tag = trim((string)($_GET['tag'] ?? ''));

$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, [20, 50, 100], true)) $perPage = 20;

$page = max(1, (int)($_GET['page'] ?? 1));

$export = ((string)($_GET['export'] ?? '') === 'csv');

/* =========================
   WHERE dinámico
========================= */
$whereParts = ['1=1'];
$params = [];

// Medio (si existe venta_pagos, filtra por pagos reales y fallback legacy)
if ($medio && in_array($medio, $allowedMedios, true)) {
  if ($hasVentaPagos) {
    if ($medio === 'SIN_ESPECIFICAR') {
      $whereParts[] = "(
        NOT EXISTS (SELECT 1 FROM venta_pagos vp2 WHERE vp2.venta_id = v.id)
        AND (v.medio_pago IS NULL OR v.medio_pago = '' OR v.medio_pago = 'SIN_ESPECIFICAR')
      )";
    } else {
      $whereParts[] = "(
        EXISTS (SELECT 1 FROM venta_pagos vp2 WHERE vp2.venta_id = v.id AND vp2.medio_pago = :medio_vp)
        OR (
          NOT EXISTS (SELECT 1 FROM venta_pagos vp2 WHERE vp2.venta_id = v.id)
          AND v.medio_pago = :medio_legacy
        )
      )";
      $params[':medio_vp'] = $medio;
      $params[':medio_legacy'] = $medio;
    }
  }
}

// Estado
if ($estado === 'EMITIDA') {
  $whereParts[] = "(v.estado IS NULL OR v.estado = 'EMITIDA')";
} elseif ($estado === 'ANULADA') {
  $whereParts[] = "(v.estado = 'ANULADA' OR UPPER(v.estado) LIKE '%ANUL%')";
}

if ($desde) {
  $whereParts[] = 'v.fecha >= :desde';
  $params[':desde'] = $desde . ' 00:00:00';
}
if ($hasta) {
  $whereParts[] = 'v.fecha <= :hasta';
  $params[':hasta'] = $hasta . ' 23:59:59';
}

if ($venta_id !== '' && ctype_digit($venta_id)) {
  $whereParts[] = 'v.id = :venta_id';
  $params[':venta_id'] = (int)$venta_id;
}

if ($min_total !== null) {
  $whereParts[] = 'v.total >= :min_total';
  $params[':min_total'] = (float)$min_total;
}
if ($max_total !== null) {
  $whereParts[] = 'v.total <= :max_total';
  $params[':max_total'] = (float)$max_total;
}

// NUEVOS FILTROS

// Filtro por cliente
if ($cliente_id > 0) {
  $whereParts[] = 'v.cliente_id = :cliente_id';
  $params[':cliente_id'] = $cliente_id;
}

// Filtro por producto (requiere JOIN con venta_items)
$joinProducto = '';
if ($producto_id > 0) {
  $joinProducto = "INNER JOIN venta_items vi_filter ON vi_filter.venta_id = v.id";
  $whereParts[] = 'vi_filter.producto_id = :producto_id';
  $params[':producto_id'] = $producto_id;
}

// Filtro por terminal
if ($terminal_id > 0) {
  $whereParts[] = 'v.terminal_id = :terminal_id';
  $params[':terminal_id'] = $terminal_id;
}

// Filtro por vendedor (ajustar a usuario_id si tu tabla usa ese nombre)
if ($vendedor_id > 0) {
  // Si tu tabla usa 'usuario_id' en lugar de 'vendedor_id', cambiar aquí
  $whereParts[] = '(v.usuario_id = :vendedor_id OR v.vendedor_id = :vendedor_id)';
  $params[':vendedor_id'] = $vendedor_id;
}

// Filtro por facturación
if ($facturado === '1') {
  $whereParts[] = 'EXISTS (SELECT 1 FROM facturas f WHERE f.venta_id = v.id)';
} elseif ($facturado === '0') {
  $whereParts[] = 'NOT EXISTS (SELECT 1 FROM facturas f WHERE f.venta_id = v.id)';
}

// Filtro por descuento (usando vista optimizada si existe)
if ($con_descuento === '1') {
  if ($hasVentasCompleto) {
    $whereParts[] = 'v.descuento_total > 0';
  } else {
    $whereParts[] = '(SELECT COALESCE(SUM(vi2.descuento_monto), 0) FROM venta_items vi2 WHERE vi2.venta_id = v.id) > 0';
  }
}

// Filtro por tag
if ($tag !== '' && $hasVentaTags) {
  $whereParts[] = 'EXISTS (SELECT 1 FROM venta_tags vt WHERE vt.venta_id = v.id AND vt.tag = :tag)';
  $params[':tag'] = $tag;
}

$whereSql = 'WHERE ' . implode(' AND ', $whereParts);

/* =========================
   Join/Select pagos (resumen)
========================= */
$joinPagos = "";
$selectPagos = ", v.monto_pagado AS pagado_calc, NULL AS pagos_cnt, NULL AS pagos_medios";

if ($hasVentaPagos) {
  $joinPagos = "
    LEFT JOIN (
      SELECT
        venta_id,
        SUM(monto)  AS pagado_total,
        COUNT(*)    AS pagos_cnt,
        GROUP_CONCAT(DISTINCT medio_pago ORDER BY medio_pago SEPARATOR '+') AS medios
      FROM venta_pagos
      GROUP BY venta_id
    ) vp ON vp.venta_id = v.id
  ";
  $selectPagos = ", COALESCE(vp.pagado_total, v.monto_pagado) AS pagado_calc,
                  COALESCE(vp.pagos_cnt, 0) AS pagos_cnt,
                  vp.medios AS pagos_medios";
}

/* =========================
   EXPORT CSV (OPTIMIZADO)
========================= */
if ($export) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="ventas_' . date('Ymd_His') . '.csv"');

  $out = fopen('php://output', 'w');
  fputcsv($out, ['id','fecha','cliente','medio_resumen','estado','total','pagado','vuelto','items','descuento','productos','tags','terminal','vendedor','medios_detalle'], ';');

  // Usar vista si existe para mejor performance
  $selectFields = $hasVentasCompleto 
    ? "v.id, v.fecha, v.medio_pago, v.estado, v.total, v.vuelto,
       vc.items_count, vc.descuento_total, vc.productos_preview, vc.tags"
    : "v.id, v.fecha, v.medio_pago, v.estado, v.total, v.vuelto,
       (SELECT COUNT(*) FROM venta_items vi WHERE vi.venta_id = v.id) AS items_count,
       (SELECT COALESCE(SUM(vi.descuento_monto), 0) FROM venta_items vi WHERE vi.venta_id = v.id) AS descuento_total,
       NULL AS productos_preview, NULL AS tags";

  $joinVista = $hasVentasCompleto ? "LEFT JOIN v_ventas_completo vc ON vc.id = v.id" : "";

  $sqlCsv = "
    SELECT
      {$selectFields},
      COALESCE(c.nombre, 'Consumidor Final') AS cliente_nombre,
      COALESCE(u.username, u2.username) AS vendedor,
      t.nombre AS terminal
      {$selectPagos}
    FROM v_ventas_completo v
    {$joinVista}
    LEFT JOIN clientes c ON c.id = v.cliente_id
    LEFT JOIN users u ON u.id = v.usuario_id
    LEFT JOIN users u2 ON u2.id = v.vendedor_id
    LEFT JOIN terminales t ON t.id = v.terminal_id
    {$joinProducto}
    {$joinPagos}
    {$whereSql}
    GROUP BY v.id
    ORDER BY v.fecha DESC, v.id DESC
  ";

  $st = $pdo->prepare($sqlCsv);
  foreach ($params as $k => $val) $st->bindValue($k, $val);
  $st->execute();

  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $cntPagos = (int)($r['pagos_cnt'] ?? 0);
    $medios   = (string)($r['pagos_medios'] ?? '');
    $medioLegacy = (string)($r['medio_pago'] ?? 'SIN_ESPECIFICAR');

    $medioResumen = $medioLegacy;
    $mediosDetalle = '';

    if ($hasVentaPagos && $cntPagos > 0) {
      $mediosDetalle = $medios;
      $medioResumen = ($cntPagos > 1) ? 'MIXTO' : ($medios !== '' ? $medios : $medioLegacy);
    }

    fputcsv($out, [
      $r['id'],
      $r['fecha'],
      $r['cliente_nombre'],
      $medioResumen,
      $r['estado'],
      number_format((float)$r['total'], 2, '.', ''),
      number_format((float)($r['pagado_calc'] ?? 0), 2, '.', ''),
      number_format((float)$r['vuelto'], 2, '.', ''),
      (int)($r['items_count'] ?? 0),
      number_format((float)($r['descuento_total'] ?? 0), 2, '.', ''),
      $r['productos_preview'] ?? '',
      $r['tags'] ?? '',
      $r['terminal'] ?? '',
      $r['vendedor'] ?? '',
      $mediosDetalle,
    ], ';');
  }

  fclose($out);
  exit;
}

/* =========================
   Stats MEJORADAS con comparativa
========================= */
// Stats generales del período filtrado
$joinVistaStats = $hasVentasCompleto ? "LEFT JOIN v_ventas_completo vc ON vc.id = v.id" : "";

$sqlStats = "
  SELECT
    COUNT(*) AS cnt,
    COALESCE(SUM(v.total), 0) AS sum_total,
    " . ($hasVentaPagos
      ? "COALESCE(SUM(COALESCE(vp.pagado_total, v.monto_pagado)), 0) AS sum_pagado,"
      : "COALESCE(SUM(v.monto_pagado), 0) AS sum_pagado,") . "
    COALESCE(AVG(v.total), 0) AS avg_total
  FROM v_ventas_completo v
  {$joinVistaStats}
  " . ($hasVentaPagos ? $joinPagos : "") . "
  {$joinProducto}
  {$whereSql}
";

$st = $pdo->prepare($sqlStats);
foreach ($params as $k => $val) $st->bindValue($k, $val);
$st->execute();
$statsResult = $st->fetch(PDO::FETCH_ASSOC);

if ($statsResult) {
  $stats['cnt'] = (int)$statsResult['cnt'];
  $stats['sum_total'] = (float)$statsResult['sum_total'];
  $stats['sum_pagado'] = (float)$statsResult['sum_pagado'];
  $stats['avg_total'] = (float)$statsResult['avg_total'];
}

// Stats de HOY
$stHoy = $pdo->query("
  SELECT 
    COUNT(*) AS cnt,
    COALESCE(SUM(total), 0) AS sum,
    COALESCE(AVG(total), 0) AS avg
  FROM v_ventas_completo
  WHERE DATE(fecha) = CURDATE() 
    AND (estado IS NULL OR estado = 'EMITIDA')
");
$statsHoy = $stHoy->fetch(PDO::FETCH_ASSOC);
$stats['cnt_hoy'] = (int)($statsHoy['cnt'] ?? 0);
$stats['sum_hoy'] = (float)($statsHoy['sum'] ?? 0);
$stats['avg_hoy'] = (float)($statsHoy['avg'] ?? 0);

// Stats de AYER
$stAyer = $pdo->query("
  SELECT 
    COUNT(*) AS cnt,
    COALESCE(SUM(total), 0) AS sum
  FROM v_ventas_completo
  WHERE DATE(fecha) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) 
    AND (estado IS NULL OR estado = 'EMITIDA')
");
$statsAyer = $stAyer->fetch(PDO::FETCH_ASSOC);
$stats['cnt_ayer'] = (int)($statsAyer['cnt'] ?? 0);
$stats['sum_ayer'] = (float)($statsAyer['sum'] ?? 0);

// Calcular diferencias porcentuales
if ($stats['cnt_ayer'] > 0) {
  $stats['diff_ventas'] = round((($stats['cnt_hoy'] - $stats['cnt_ayer']) / $stats['cnt_ayer']) * 100, 1);
}
if ($stats['sum_ayer'] > 0) {
  $stats['diff_total'] = round((($stats['sum_hoy'] - $stats['sum_ayer']) / $stats['sum_ayer']) * 100, 1);
}

// Top medio de pago HOY
$stMedio = $pdo->query("
  SELECT 
    medio_pago,
    COUNT(*) AS cnt
  FROM v_ventas_completo
  WHERE DATE(fecha) = CURDATE() 
    AND (estado IS NULL OR estado = 'EMITIDA')
  GROUP BY medio_pago
  ORDER BY cnt DESC
  LIMIT 1
");
$topMedio = $stMedio->fetch(PDO::FETCH_ASSOC);
if ($topMedio) {
  $stats['top_medio'] = $topMedio['medio_pago'] ?? 'N/A';
  if ($stats['cnt_hoy'] > 0) {
    $stats['top_medio_pct'] = round(((int)$topMedio['cnt'] / $stats['cnt_hoy']) * 100, 1);
  }
}

/* =========================
   Paginación
========================= */
$sqlCount = "
  SELECT COUNT(DISTINCT v.id) 
  FROM v_ventas_completo v
  " . ($hasVentasCompleto && $con_descuento === '1' ? "LEFT JOIN v_ventas_completo vc ON vc.id = v.id" : "") . "
  {$joinProducto}
  " . ($hasVentaPagos ? $joinPagos : "") . "
  {$whereSql}
";

$stCount = $pdo->prepare($sqlCount);
foreach ($params as $k => $val) $stCount->bindValue($k, $val);
$stCount->execute();
$totalRows = (int)$stCount->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$fromRow = ($totalRows > 0) ? ($offset + 1) : 0;
$toRow = min($offset + $perPage, $totalRows);

/* =========================
   Ventas (OPTIMIZADO con vista)
========================= */
if ($hasVentasCompleto) {
  // OPTIMIZADO: Usar vista v_ventas_completo (1 JOIN en lugar de N subqueries)
  $sqlVentas = "
    SELECT
      v.id, 
      v.fecha, 
      v.medio_pago, 
      v.estado, 
      v.total, 
      v.vuelto,
      v.cliente_id,
      v.terminal_id,
      COALESCE(v.usuario_id, v.vendedor_id) AS vendedor_id,
      vc.items_count,
      vc.descuento_total,
      vc.productos_preview,
      vc.tags,
      COALESCE(c.nombre, 'Consumidor Final') AS cliente_nombre,
      c.cuit AS cliente_documento,
      COALESCE(u.username, u2.username) AS vendedor_username,
      t.nombre AS terminal_nombre,
      (SELECT 1 FROM facturas f WHERE f.venta_id = v.id LIMIT 1) AS esta_facturada
      {$selectPagos}
    FROM v_ventas_completo v
    LEFT JOIN v_ventas_completo vc ON vc.id = v.id
    LEFT JOIN clientes c ON c.id = v.cliente_id
    LEFT JOIN users u ON u.id = v.usuario_id
    LEFT JOIN users u2 ON u2.id = v.vendedor_id
    LEFT JOIN terminales t ON t.id = v.terminal_id
    {$joinProducto}
    {$joinPagos}
    {$whereSql}
    GROUP BY v.id
    ORDER BY v.fecha DESC, v.id DESC
    LIMIT :limit OFFSET :offset
  ";
} else {
  // FALLBACK: Sin vista optimizada (más lento pero funciona)
  $sqlVentas = "
    SELECT
      v.id, v.fecha, v.medio_pago, v.estado, v.total, v.vuelto,
      v.cliente_id,
      v.terminal_id,
      COALESCE(v.usuario_id, v.vendedor_id) AS vendedor_id,
      COALESCE(c.nombre, 'Consumidor Final') AS cliente_nombre,
      c.cuit AS cliente_documento,
      COALESCE(u.username, u2.username) AS vendedor_username,
      t.nombre AS terminal_nombre,
      (SELECT COUNT(*) FROM venta_items vi WHERE vi.venta_id = v.id) AS items_count,
      (SELECT COALESCE(SUM(vi.descuento_monto), 0) FROM venta_items vi WHERE vi.venta_id = v.id) AS descuento_total,
      (SELECT GROUP_CONCAT(p.nombre SEPARATOR ', ') 
       FROM venta_items vi 
       JOIN productos p ON p.id = vi.producto_id 
       WHERE vi.venta_id = v.id 
       LIMIT 2) AS productos_preview,
      " . ($hasVentaTags ? "
      (SELECT GROUP_CONCAT(vt.tag SEPARATOR ', ')
       FROM venta_tags vt
       WHERE vt.venta_id = v.id) AS tags,
      " : "NULL AS tags,") . "
      (SELECT 1 FROM facturas f WHERE f.venta_id = v.id LIMIT 1) AS esta_facturada
      {$selectPagos}
    FROM v_ventas_completo v
    LEFT JOIN clientes c ON c.id = v.cliente_id
    LEFT JOIN users u ON u.id = v.usuario_id
    LEFT JOIN users u2 ON u2.id = v.vendedor_id
    LEFT JOIN terminales t ON t.id = v.terminal_id
    {$joinProducto}
    {$joinPagos}
    {$whereSql}
    GROUP BY v.id
    ORDER BY v.fecha DESC, v.id DESC
    LIMIT :limit OFFSET :offset
  ";
}

$stVentas = $pdo->prepare($sqlVentas);
foreach ($params as $k => $val) $stVentas->bindValue($k, $val);
$stVentas->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stVentas->bindValue(':offset', $offset, PDO::PARAM_INT);
$stVentas->execute();
$ventas = $stVentas->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* =========================
   Listados para filtros
========================= */
// Terminales (activa/activo/habilitada/...)
$terminales = [];
$colActTerm = first_column($pdo, 'terminales', ['activa', 'activo', 'habilitada', 'habilitado', 'enabled']);

$sqlTerm = "SELECT id, nombre FROM terminales";
if ($colActTerm) {
  $sqlTerm .= " WHERE {$colActTerm} = 1";
}
$sqlTerm .= " ORDER BY nombre ASC";

$stTerminales = $pdo->query($sqlTerm);
$terminales = $stTerminales->fetchAll(PDO::FETCH_ASSOC) ?: [];


// Vendedores (usuarios activos si existe columna)
$vendedores = [];
$colActUser = first_column($pdo, 'users', ['activo', 'activa', 'enabled']);

$sqlVend = "
  SELECT DISTINCT u.id, u.username
  FROM users u
  WHERE " . ($colActUser ? "u.{$colActUser} = 1 AND " : "") . "
    u.id IN (
      SELECT DISTINCT COALESCE(usuario_id, vendedor_id)
      FROM v_ventas_completo
      WHERE COALESCE(usuario_id, vendedor_id) IS NOT NULL
    )
  ORDER BY u.username ASC
";
$stVendedores = $pdo->query($sqlVend);
$vendedores = $stVendedores->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Tags disponibles (si existe la tabla)
$tags = [];
if ($hasVentaTags) {
  $stTags = $pdo->query("
    SELECT DISTINCT tag 
    FROM venta_tags 
    ORDER BY tag ASC 
    LIMIT 50
  ");
  $tags = $stTags->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

// Obtener nombre de cliente seleccionado
$clienteNombre = '';
if ($cliente_id > 0) {
  $stCliente = $pdo->prepare("SELECT nombre FROM clientes WHERE id = ? LIMIT 1");
  $stCliente->execute([$cliente_id]);
  $clienteNombre = $stCliente->fetchColumn() ?: '';
}

// Obtener nombre de producto seleccionado
$productoNombre = '';
if ($producto_id > 0) {
  $stProducto = $pdo->prepare("SELECT nombre FROM productos WHERE id = ? LIMIT 1");
  $stProducto->execute([$producto_id]);
  $productoNombre = $stProducto->fetchColumn() ?: '';
}

/* =========================
   Promos activas
========================= */
$promosActivas = (int)$pdo->query("
  SELECT COUNT(*) 
  FROM promos 
  WHERE activo = 1
    AND (fecha_inicio IS NULL OR fecha_inicio <= CURDATE())
    AND (fecha_fin IS NULL OR fecha_fin >= CURDATE())
")->fetchColumn();

/* =========================
   Header
========================= */
$pageTitle = 'Ventas';
$currentSection = 'ventas';
$extraCss = ['assets/css/ventas.css'];
$extraJs  = [
  'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
  'assets/js/ventas.js'
];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap ventas-page">

  <div class="panel ventas-panel">

    <!-- Header superior -->
    <div class="ventas-top">
      <div class="ventas-top-left">
        <h1 class="ventas-title">VENTAS</h1>
        <p class="ventas-sub">
          Gestión y reportes de ventas 
          <?php if ($hasVentasCompleto): ?>
            · <span class="badge-success" title="Usando vista optimizada">⚡ Optimizado</span>
          <?php endif; ?>
        </p>
      </div>

      <div class="ventas-top-right">
        <div class="paper-box">
          <label for="paperSel">Papel</label>
          <select id="paperSel">
            <option value="80">80mm</option>
            <option value="58">58mm</option>
          </select>
        </div>

        <button id="btnToggleCharts" class="btn btn-secondary">
          📊 Gráficos
        </button>

        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-primary">
          💾 Exportar CSV
        </a>

        <button id="btnScrollTop" class="btn-icon" title="Volver arriba">↑</button>
      </div>
    </div>

    <!-- KPIs MEJORADOS con tendencias -->
    <div class="ventas-kpis">
      <!-- Ventas Hoy -->
      <div class="kpi">
        <div class="kpi-label">Ventas Hoy</div>
        <div class="kpi-value"><?= number_format($stats['cnt_hoy']) ?></div>
        <?php if ($stats['diff_ventas'] != 0): ?>
          <div class="kpi-trend <?= $stats['diff_ventas'] > 0 ? 'positive' : 'negative' ?>">
            <?= $stats['diff_ventas'] > 0 ? '↗' : '↘' ?>
            <?= abs($stats['diff_ventas']) ?>% vs ayer
          </div>
        <?php endif; ?>
      </div>

      <!-- Total Hoy -->
      <div class="kpi">
        <div class="kpi-label">Total Hoy</div>
        <div class="kpi-value"><?= money($stats['sum_hoy']) ?></div>
        <?php if ($stats['diff_total'] != 0): ?>
          <div class="kpi-trend <?= $stats['diff_total'] > 0 ? 'positive' : 'negative' ?>">
            <?= $stats['diff_total'] > 0 ? '↗' : '↘' ?>
            <?= abs($stats['diff_total']) ?>% vs ayer
          </div>
        <?php endif; ?>
      </div>

      <!-- Ticket Promedio Hoy -->
      <div class="kpi">
        <div class="kpi-label">Ticket Promedio Hoy</div>
        <div class="kpi-value"><?= money($stats['avg_hoy']) ?></div>
      </div>

      <!-- Top Medio de Pago -->
      <div class="kpi">
        <div class="kpi-label">Medio Más Usado Hoy</div>
        <div class="kpi-value">
          <span class="badge-medio-mini badge-<?= strtolower($stats['top_medio']) ?>">
            <?= h($stats['top_medio']) ?>
          </span>
          <span class="kpi-percentage"><?= $stats['top_medio_pct'] ?>%</span>
        </div>
      </div>
    </div>

    <!-- Panel de Gráficos (inicialmente oculto) -->
    <div id="chartsPanel" class="charts-panel hidden">
      <h3>📊 Análisis de Ventas</h3>
      
      <div class="charts-grid">
        <div class="chart-box">
          <h4>Ventas por Día (últimos 30 días)</h4>
          <canvas id="chartVentasPorDia"></canvas>
        </div>
        
        <div class="chart-box">
          <h4>Distribución por Medio de Pago</h4>
          <canvas id="chartMediosPago"></canvas>
        </div>
      </div>
    </div>

    <!-- Filtros activos -->
    <?php
      $hayFiltros = $medio || $estado || $desde || $hasta || $cliente_id || $producto_id || $terminal_id || $vendedor_id || $facturado || $con_descuento || $tag;
    ?>
    
    <?php if ($hayFiltros): ?>
      <div class="active-filters">
        <span class="filters-label">Filtros activos:</span>
        
        <?php if ($medio): ?>
          <span class="filter-badge">
            Medio: <?= h($medio) ?>
            <button class="filter-remove" data-filter="medio">×</button>
          </span>
        <?php endif; ?>
        
        <?php if ($estado): ?>
          <span class="filter-badge">
            Estado: <?= h($estado) ?>
            <button class="filter-remove" data-filter="estado">×</button>
          </span>
        <?php endif; ?>
        
        <?php if ($desde && $hasta): ?>
          <span class="filter-badge">
            Período: <?= h($desde) ?> - <?= h($hasta) ?>
            <button class="filter-remove" data-filter="fecha">×</button>
          </span>
        <?php endif; ?>
        
        <?php if ($cliente_id): ?>
          <span class="filter-badge">
            Cliente: <?= h($clienteNombre) ?>
            <button class="filter-remove" data-filter="cliente">×</button>
          </span>
        <?php endif; ?>
        
        <?php if ($producto_id): ?>
          <span class="filter-badge">
            Producto: <?= h($productoNombre) ?>
            <button class="filter-remove" data-filter="producto">×</button>
          </span>
        <?php endif; ?>
        
        <?php if ($terminal_id): ?>
          <span class="filter-badge">
            Terminal: <?= h(array_column(array_filter($terminales, fn($t) => $t['id'] == $terminal_id), 'nombre')[0] ?? '') ?>
            <button class="filter-remove" data-filter="terminal">×</button>
          </span>
        <?php endif; ?>
        
        <?php if ($tag): ?>
          <span class="filter-badge">
            Tag: <?= h($tag) ?>
            <button class="filter-remove" data-filter="tag">×</button>
          </span>
        <?php endif; ?>
        
        <?php if ($facturado === '1'): ?>
          <span class="filter-badge">
            Solo facturadas
            <button class="filter-remove" data-filter="facturado">×</button>
          </span>
        <?php elseif ($facturado === '0'): ?>
          <span class="filter-badge">
            Sin facturar
            <button class="filter-remove" data-filter="facturado">×</button>
          </span>
        <?php endif; ?>
        
        <?php if ($con_descuento === '1'): ?>
          <span class="filter-badge">
            Con descuento
            <button class="filter-remove" data-filter="descuento">×</button>
          </span>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Filtros -->
    <div class="ventas-filters">
      <form id="ventasForm" method="get" action="ventas.php">

        <div class="filters-grid">

          <!-- ID Venta -->
          <div class="field">
            <label for="venta_id">🔍 ID Venta</label>
            <input type="text" id="venta_id" name="venta_id" placeholder="Buscar por ID" value="<?= h($venta_id) ?>">
          </div>

          <!-- NUEVO: Cliente -->
          <div class="field field-autocomplete">
            <label for="cliente_buscar">👤 Cliente</label>
            <input type="text" 
                   id="cliente_buscar" 
                   placeholder="Buscar cliente..."
                   value="<?= h($clienteNombre) ?>"
                   autocomplete="off">
            <input type="hidden" id="cliente_id" name="cliente_id" value="<?= $cliente_id ?>">
            <div id="cliente_dropdown" class="autocomplete-dropdown hidden"></div>
          </div>

          <!-- NUEVO: Producto -->
          <div class="field field-autocomplete">
            <label for="producto_buscar">📦 Producto</label>
            <input type="text" 
                   id="producto_buscar" 
                   placeholder="Buscar producto..."
                   value="<?= h($productoNombre) ?>"
                   autocomplete="off">
            <input type="hidden" id="producto_id" name="producto_id" value="<?= $producto_id ?>">
            <div id="producto_dropdown" class="autocomplete-dropdown hidden"></div>
          </div>

          <!-- Medio de pago -->
          <div class="field">
            <label for="medio">💳 Medio de pago</label>
            <select id="medio" name="medio">
              <option value="">Todos</option>
              <?php foreach ($allowedMedios as $m): ?>
                <option value="<?= h($m) ?>" <?= ($medio === $m) ? 'selected' : '' ?>><?= h($m) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Estado -->
          <div class="field">
            <label for="estado">📋 Estado</label>
            <select id="estado" name="estado">
              <option value="">Todas</option>
              <?php foreach ($allowedEstados as $e): ?>
                <?php if ($e !== ''): ?>
                  <option value="<?= h($e) ?>" <?= ($estado === $e) ? 'selected' : '' ?>><?= h($e) ?></option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- NUEVO: Terminal -->
          <?php if (!empty($terminales)): ?>
            <div class="field">
              <label for="terminal_id">🖥️ Terminal</label>
              <select id="terminal_id" name="terminal_id">
                <option value="">Todas</option>
                <?php foreach ($terminales as $t): ?>
                  <option value="<?= (int)$t['id'] ?>" <?= ($terminal_id === (int)$t['id']) ? 'selected' : '' ?>>
                    <?= h($t['nombre']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <!-- NUEVO: Vendedor -->
          <?php if (!empty($vendedores)): ?>
            <div class="field">
              <label for="vendedor_id">👨‍💼 Vendedor</label>
              <select id="vendedor_id" name="vendedor_id">
                <option value="">Todos</option>
                <?php foreach ($vendedores as $v): ?>
                  <option value="<?= (int)$v['id'] ?>" <?= ($vendedor_id === (int)$v['id']) ? 'selected' : '' ?>>
                    <?= h($v['username']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <!-- Desde -->
          <div class="field">
            <label for="desde">📅 Desde</label>
            <input type="date" id="desde" name="desde" value="<?= h($desde ?? '') ?>">
          </div>

          <!-- Hasta -->
          <div class="field">
            <label for="hasta">📅 Hasta</label>
            <input type="date" id="hasta" name="hasta" value="<?= h($hasta ?? '') ?>">
          </div>

          <!-- Monto mín -->
          <div class="field">
            <label for="min_total">💰 Monto mín.</label>
            <input type="text" id="min_total" name="min_total" placeholder="0,00" value="<?= h($min_total_raw) ?>">
          </div>

          <!-- Monto máx -->
          <div class="field">
            <label for="max_total">💰 Monto máx.</label>
            <input type="text" id="max_total" name="max_total" placeholder="999999,00" value="<?= h($max_total_raw) ?>">
          </div>

          <!-- NUEVO: Facturación -->
          <div class="field">
            <label for="facturado">🧾 Facturación</label>
            <select id="facturado" name="facturado">
              <option value="">Todas</option>
              <option value="1" <?= ($facturado === '1') ? 'selected' : '' ?>>Facturadas</option>
              <option value="0" <?= ($facturado === '0') ? 'selected' : '' ?>>Sin facturar</option>
            </select>
          </div>

          <!-- NUEVO: Con descuento -->
          <div class="field">
            <label for="con_descuento">🏷️ Descuentos</label>
            <select id="con_descuento" name="con_descuento">
              <option value="">Todas</option>
              <option value="1" <?= ($con_descuento === '1') ? 'selected' : '' ?>>Con descuento</option>
            </select>
          </div>

          <!-- NUEVO: Tag -->
          <?php if (!empty($tags)): ?>
            <div class="field">
              <label for="tag">🏷️ Tag</label>
              <select id="tag" name="tag">
                <option value="">Todos</option>
                <?php foreach ($tags as $t): ?>
                  <option value="<?= h($t) ?>" <?= ($tag === $t) ? 'selected' : '' ?>><?= h($t) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endif; ?>

          <!-- Por página -->
          <div class="field">
            <label for="per_page">📄 Por página</label>
            <select id="per_page" name="per_page">
              <option value="20" <?= ($perPage === 20) ? 'selected' : '' ?>>20</option>
              <option value="50" <?= ($perPage === 50) ? 'selected' : '' ?>>50</option>
              <option value="100" <?= ($perPage === 100) ? 'selected' : '' ?>>100</option>
            </select>
          </div>

          <!-- Botones -->
          <div class="field" style="display:flex; gap:8px; align-items:end;">
            <button type="submit" class="btn btn-primary">Filtrar</button>
            <a href="ventas.php?clear=1" id="ventasClear" class="btn btn-ghost">Limpiar</a>
          </div>

        </div>

        <!-- Chips de rango rápido EXTENDIDOS -->
        <div class="quick">
          <span class="chip" data-range="today">🔥 Hoy</span>
          <span class="chip" data-range="yesterday">Ayer</span>
          <span class="chip" data-range="7d">Últimos 7 días</span>
          <span class="chip" data-range="30d">Últimos 30 días</span>
          <span class="chip" data-range="this_week">Esta semana</span>
          <span class="chip" data-range="last_week">Semana pasada</span>
          <span class="chip" data-range="this_month">Este mes</span>
          <span class="chip" data-range="last_month">Mes pasado</span>
        </div>

        <input type="hidden" id="page" name="page" value="<?= (int)$page ?>">
      </form>
    </div>

    <!-- Barra de acciones en masa (aparece cuando hay selección) -->
    <div id="bulkActionsBar" class="bulk-actions-bar hidden">
      <div class="bulk-actions-info">
        <span id="bulkCount">0</span> ventas seleccionadas
      </div>
      
      <div class="bulk-actions">
        <button class="btn btn-secondary" id="btnBulkPrint">
          🖨️ Imprimir todas
        </button>
        
        <button class="btn btn-secondary" id="btnBulkExport">
          💾 Exportar selección
        </button>
        
        <button class="btn btn-ghost" id="btnDeselectAll">
          Deseleccionar todas
        </button>
      </div>
    </div>

    <!-- Tabla MEJORADA -->
    <div class="table-wrapper">
      <table class="ventas-table">
        <thead>
          <tr>
            <th style="width:40px;">
              <input type="checkbox" id="selectAll" title="Seleccionar todas">
            </th>
            <th>ID</th>
            <th>Fecha</th>
            <th>Cliente</th>
            <th>Productos</th>
            <th>Medio</th>
            <th>Estado</th>
            <th class="t-right">Desc.</th>
            <th class="t-right">Total</th>
            <th>Vendedor</th>
            <th>Terminal</th>
            <?php if ($hasVentaTags): ?>
            <th>Tags</th>
            <?php endif; ?>
            <th class="t-center">Acciones</th>
          </tr>
        </thead>
        <tbody>

        <?php if ($ventas): ?>
          <?php foreach ($ventas as $v): ?>
            <?php
              $cntPagos = (int)($v['pagos_cnt'] ?? 0);
              $medios   = (string)($v['pagos_medios'] ?? '');
              $medioLegacy = (string)($v['medio_pago'] ?? 'SIN_ESPECIFICAR');

              if ($hasVentaPagos && $cntPagos > 0) {
                $medioShow = ($cntPagos > 1) ? 'MIXTO' : ($medios !== '' ? $medios : $medioLegacy);
                $medioTitle = ($cntPagos > 1 && $medios) ? $medios : '';
              } else {
                $medioShow  = $medioLegacy ?: 'SIN_ESPECIFICAR';
                $medioTitle = '';
              }

              $mpClass = strtolower(preg_replace('/[^a-z0-9_]+/i', '', $medioShow));
              
              // Clases especiales
              $rowClasses = [];
              if ((float)($v['total'] ?? 0) >= 50000) $rowClasses[] = 'venta-grande';
              if ((float)($v['descuento_total'] ?? 0) > 0) $rowClasses[] = 'con-descuento';
              if (!($v['esta_facturada'] ?? false) && !empty($v['cliente_id'])) $rowClasses[] = 'sin-facturar';
            ?>
            <tr class="<?= implode(' ', $rowClasses) ?>" data-venta-id="<?= (int)$v['id'] ?>">
              <td>
                <input type="checkbox" class="venta-check" value="<?= (int)$v['id'] ?>">
              </td>
              
              <td class="mono"><?= h((string)$v['id']) ?></td>

              <td>
                <?php
                  $f = $v['fecha'] ?? '';
                  if ($f) {
                    try {
                      $dt = new DateTime((string)$f);
                      echo h($dt->format('d/m/Y H:i'));
                    } catch (Exception $e) {
                      echo h((string)$f);
                    }
                  }
                ?>
              </td>

              <!-- Cliente -->
              <td>
                <?php if (!empty($v['cliente_id'])): ?>
                  <a href="clientes.php?id=<?= (int)$v['cliente_id'] ?>" class="link-cliente">
                    <?= h($v['cliente_nombre']) ?>
                  </a>
                  <?php if ($v['cliente_documento']): ?>
                    <br><span class="muted small"><?= h($v['cliente_documento']) ?></span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="muted">Consumidor Final</span>
                <?php endif; ?>
              </td>

              <!-- Productos -->
              <td class="productos-cell">
                <span class="badge-qty"><?= (int)($v['items_count'] ?? 0) ?></span>
                <?php if ($v['productos_preview']): ?>
                  <div class="productos-preview" title="<?= h($v['productos_preview']) ?>">
                    <?= h(mb_strimwidth($v['productos_preview'], 0, 30, '...')) ?>
                  </div>
                <?php endif; ?>
              </td>

              <!-- Medio -->
              <td>
                <span class="badge badge-<?= h($mpClass) ?>" <?= $medioTitle ? 'title="'.h($medioTitle).'"' : '' ?>>
                  <?= h($medioShow) ?>
                </span>
              </td>

              <!-- Estado -->
              <td>
                <?php if (strtoupper((string)($v['estado'] ?? '')) === 'ANULADA'): ?>
                  <span class="badge-estado badge-anulada">Anulada</span>
                <?php else: ?>
                  <span class="badge-estado badge-emitida">Emitida</span>
                <?php endif; ?>
              </td>

              <!-- Descuento -->
              <td class="t-right">
                <?php if ((float)($v['descuento_total'] ?? 0) > 0): ?>
                  <span class="badge-descuento">-<?= money($v['descuento_total']) ?></span>
                <?php else: ?>
                  <span class="muted">-</span>
                <?php endif; ?>
              </td>

              <!-- Total -->
              <td class="t-right mono"><?= money($v['total'] ?? 0) ?></td>

              <!-- Vendedor -->
              <td>
                <?php if ($v['vendedor_username']): ?>
                  <span class="muted"><?= h($v['vendedor_username']) ?></span>
                <?php else: ?>
                  <span class="muted">-</span>
                <?php endif; ?>
              </td>

              <!-- Terminal -->
              <td>
                <?php if ($v['terminal_nombre']): ?>
                  <span class="badge badge-terminal"><?= h($v['terminal_nombre']) ?></span>
                <?php else: ?>
                  <span class="muted">-</span>
                <?php endif; ?>
              </td>

              <!-- Tags -->
              <?php if ($hasVentaTags): ?>
              <td>
                <?php if ($v['tags']): ?>
                  <?php foreach (explode(', ', $v['tags']) as $tag): ?>
                    <span class="badge badge-tag"><?= h($tag) ?></span>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span class="muted">-</span>
                <?php endif; ?>
              </td>
              <?php endif; ?>

              <!-- Acciones -->
              <td class="t-center">
                <div class="row-actions">
                  <button class="btn-mini btn-mini-preview" 
                          data-venta-id="<?= (int)$v['id'] ?>" 
                          title="Vista previa">
                    👁️
                  </button>
                  <a href="ticket.php?id=<?= (int)$v['id'] ?>&paper=80" 
                     target="_blank" 
                     class="btn-mini btn-mini-ok" 
                     title="Ver ticket">
                    📄
                  </a>
                  <a href="venta_detalle.php?id=<?= (int)$v['id'] ?>" 
                     class="btn-mini" 
                     title="Ver detalle">
                    →
                  </a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="<?= $hasVentaTags ? 13 : 12 ?>" class="empty-cell">
              No hay ventas que coincidan con los filtros seleccionados.
            </td>
          </tr>
        <?php endif; ?>

        </tbody>
      </table>
    </div>

    <!-- Paginación -->
    <?php if ($totalPages > 1): ?>
      <div class="pager">
        <div class="pager-info">
          Mostrando <?= $fromRow ?> - <?= $toRow ?> de <?= number_format($totalRows) ?> ventas
        </div>

        <div class="pager-controls">
          <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="pager-btn">← Anterior</a>
          <?php else: ?>
            <span class="pager-btn disabled">← Anterior</span>
          <?php endif; ?>

          <span class="pager-current">Página <?= $page ?> de <?= $totalPages ?></span>

          <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="pager-btn">Siguiente →</a>
          <?php else: ?>
            <span class="pager-btn disabled">Siguiente →</span>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

  </div>
</div>

<!-- Modal de Vista Previa Rápida -->
<div id="quickPreviewModal" class="quick-preview-modal hidden">
  <div class="preview-dialog">
    <div class="preview-header">
      <h3 class="preview-title">Venta #<span id="previewVentaId"></span></h3>
      <button class="btn-close" id="closePreview">×</button>
    </div>
    
    <div class="preview-body">
      <div id="previewContent">
        <div class="preview-loading">
          <div class="spinner"></div>
          <p>Cargando...</p>
        </div>
      </div>
    </div>
    
    <div class="preview-footer">
      <a href="#" id="previewVerDetalle" class="btn btn-primary">
        Ver detalle completo
      </a>
      <a href="#" id="previewImprimir" class="btn btn-secondary" target="_blank">
        🖨️ Imprimir ticket
      </a>
      <button class="btn btn-ghost" id="previewCerrar">Cerrar</button>
    </div>
  </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>